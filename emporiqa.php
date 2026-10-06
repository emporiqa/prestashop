<?php
/**
 * Emporiqa
 *
 * Integrates PrestaShop with Emporiqa chat assistant.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

// The class files use PHP 8.0 syntax, so the PHP 7 parser must never read
// them. This file stays 7.x-parseable so the module can refuse to install
// with a message instead of a fatal error. EmporiqaJsonResponse and
// EmporiqaSchema are the exceptions: they parse on 7.2, and uninstall needs
// the schema there.
require_once dirname(__FILE__) . '/classes/EmporiqaJsonResponse.php';
require_once dirname(__FILE__) . '/classes/EmporiqaSchema.php';
if (PHP_VERSION_ID >= 80000) {
    require_once dirname(__FILE__) . '/classes/EmporiqaSignatureHelper.php';
    require_once dirname(__FILE__) . '/classes/EmporiqaLanguageHelper.php';
    require_once dirname(__FILE__) . '/classes/EmporiqaChannelResolver.php';
    require_once dirname(__FILE__) . '/classes/EmporiqaWebhookClient.php';
    require_once dirname(__FILE__) . '/classes/EmporiqaProductFormatter.php';
    require_once dirname(__FILE__) . '/classes/EmporiqaPageFormatter.php';
    require_once dirname(__FILE__) . '/classes/EmporiqaOrderFormatter.php';
    require_once dirname(__FILE__) . '/classes/EmporiqaCartHandler.php';
    require_once dirname(__FILE__) . '/classes/EmporiqaSyncService.php';
    require_once dirname(__FILE__) . '/classes/EmporiqaConnectNonce.php';
    require_once dirname(__FILE__) . '/classes/EmporiqaOrderStatus.php';
    require_once dirname(__FILE__) . '/classes/EmporiqaCustomerPrices.php';
}

class Emporiqa extends Module
{
    /** Sent as X-Emporiqa-Plugin-Version; keep equal to the $this->version literal (the Addons validator wants a literal there) and config.xml. */
    public const VERSION = '1.3.2';

    public const DEFAULT_WEBHOOK_URL = 'https://emporiqa.com/webhooks/sync/';

    // How often storefront traffic checks for dated prices that started or
    // ended (queueScheduledPriceChanges).
    private const PRICE_WINDOW_CHECK_SECONDS = 900;

    // Products one request re-sends for a price change that no product save
    // reports (a catalog price rule, a dated price starting or ending), after
    // the response. More than that and the merchant is asked to run a full
    // sync instead, as for any catalog-wide change.
    private const PRICE_CHANGE_MAX_PRODUCTS = 100;

    // The dated-price check: every start or end up to SENT_UNTIL has been
    // sent; RUN_AT is when a request last claimed the check.
    private const PRICE_WINDOW_SENT_UNTIL = 'EMPORIQA_PRICE_WINDOW_SENT_UNTIL';
    private const PRICE_WINDOW_RUN_AT = 'EMPORIQA_PRICE_WINDOW_RUN_AT';

    /** @var EmporiqaWebhookClient|null */
    private $webhookClient;

    /** @var EmporiqaChannelResolver|null */
    private $channelResolver;

    /** @var EmporiqaProductFormatter|null */
    private $productFormatter;

    /** @var EmporiqaPageFormatter|null */
    private $pageFormatter;

    /** @var EmporiqaSyncService|null */
    private $syncService;

    /**
     * Nothing queued: the shape of $pending.
     *
     * - product_syncs: productId => event type (e.g. "product.updated").
     *   Product changes are queued during the request and flushed once at
     *   request shutdown so the webhook payload reflects the FINAL DB state,
     *   not the half-committed state visible to whichever hook fired first.
     *   Doubles as per-request dedup: a parent product touched by five
     *   different hooks in the same request emits one webhook.
     * - product_deletes: productId => true. Delete wins on conflict.
     * - stock: productId => true. Stock/availability-ONLY changes (quantity
     *   ticks), flushed as a lightweight `product.availability` event instead
     *   of rebuilding and re-shipping the full product. Mutually exclusive
     *   with product_syncs: a product already queued for a full
     *   `product.updated` is never also queued here, and a full sync queued
     *   afterwards drops the stock entry (the full event already carries the
     *   final availability).
     * - page_syncs: cmsId => event type. CMS pages on PS9 also save across
     *   multiple CQRS commands; we wait for shutdown to read the final state.
     * - page_deletes: cmsId => true. Delete wins on conflict.
     * - orders: orderId => chat session id ('' when none). order.completed is
     *   sent at shutdown, not inside checkout: the shopper's confirmation
     *   page must never wait on Emporiqa.
     * - price_window: the dated-price check this request claimed
     *   (sent_until, more), saved once its products are sent.
     */
    private const NOTHING_PENDING = [
        'orders' => [],
        'product_syncs' => [],
        'product_deletes' => [],
        'stock' => [],
        'page_syncs' => [],
        'page_deletes' => [],
        'price_window' => [],
    ];

    /** @var array<string, array<int, mixed>> see NOTHING_PENDING */
    private $pending = self::NOTHING_PENDING;

    /** @var bool true once we've registered the shutdown callback this request. */
    private $shutdownFlushRegistered = false;

    /** @var array<int, bool> catalog price rules whose products this request queued */
    private $queuedPriceRules = [];

    /** @var int products queued this request for a catalog price rule */
    private $priceRuleProducts = 0;

    /** @var bool a price rule covered more than PRICE_CHANGE_MAX_PRODUCTS this request */
    private $priceRuleOverflow = false;

    public function __construct()
    {
        $this->name = 'emporiqa';
        $this->module_key = '19a6bf09ba552447feda82c897be7296';
        $this->tab = 'front_office_features';
        $this->version = '1.3.2';
        $this->author = 'Emporiqa';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.1.0', 'max' => '9.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        // PS 9 occasionally leaves $this->id at 0 because its modules_cache
        // is pre-populated by Module::loadUpgradeVersionList with only an
        // 'upgrade' key (no id_module), which causes the cache-lookup
        // branch of Module::__construct to skip the DB read. Without an id,
        // Hook::registerHook silently inserts orphan rows (or fails) and
        // every upgrade-time hook write breaks. Resolve from DB by name.
        if (empty($this->id)) {
            $this->id = (int) Module::getModuleIdByName($this->name);
        }

        $this->displayName = $this->l('Emporiqa');
        $this->description = $this->l('Adds the Emporiqa AI salesperson to your shop: it answers shoppers, recommends products from your catalog and guides them to checkout.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall Emporiqa? All configuration will be removed.');

        if (!self::isPhpSupported()) {
            $this->warning = $this->l('Emporiqa needs PHP 8.0 or newer.');
        }
    }

    /**
     * Whether this PHP can run the module. On older PHP the class files are
     * never loaded: install, getContent and hookDisplayHeader check this,
     * and the sync and order hooks reach a PHP 8 class only through
     * isWebhookConfigured() or registerShutdownFlush(), which check it too.
     *
     * @return bool
     */
    public static function isPhpSupported()
    {
        return PHP_VERSION_ID >= 80000;
    }

    /**
     * Lets an upgrade script, which runs outside the class, report why it
     * stopped; PrestaShop shows the module's errors after a failed upgrade.
     *
     * @param string $message
     */
    public function addUpgradeError($message)
    {
        $this->_errors[] = (string) $message;
    }

    /**
     * @return EmporiqaWebhookClient
     */
    public function getWebhookClient()
    {
        if (!$this->webhookClient) {
            $this->webhookClient = new EmporiqaWebhookClient($this->getChannelResolver(), $this->context);
        }

        return $this->webhookClient;
    }

    /**
     * @return EmporiqaChannelResolver
     */
    public function getChannelResolver()
    {
        if (!$this->channelResolver) {
            $this->channelResolver = new EmporiqaChannelResolver($this->context);
        }

        return $this->channelResolver;
    }

    /**
     * @return EmporiqaProductFormatter
     */
    public function getProductFormatter()
    {
        if (!$this->productFormatter) {
            $this->productFormatter = new EmporiqaProductFormatter($this->getChannelResolver(), $this->context);
        }

        return $this->productFormatter;
    }

    /**
     * @return EmporiqaPageFormatter
     */
    public function getPageFormatter()
    {
        if (!$this->pageFormatter) {
            $this->pageFormatter = new EmporiqaPageFormatter($this->getChannelResolver());
        }

        return $this->pageFormatter;
    }

    /**
     * @return EmporiqaSyncService
     */
    public function getSyncService()
    {
        if (!$this->syncService) {
            $this->syncService = new EmporiqaSyncService(
                $this->getWebhookClient(),
                $this->getProductFormatter(),
                $this->getPageFormatter(),
                $this->getChannelResolver(),
                $this->context
            );
        }

        return $this->syncService;
    }

    public function getTabs()
    {
        return [
            [
                'name' => 'Emporiqa',
                'class_name' => 'AdminEmporiqa',
                'route_name' => '',
                'parent_class_name' => 'AdminParentModulesSf',
                'visible' => true,
                'icon' => 'chat',
            ],
            [
                // Provides a stable admin URL for the one-click connect
                // handshake (?action=initiate / ?action=callback). Reached
                // only via the Connect button on the module settings page.
                // active=1 is REQUIRED — PS refuses to route to inactive
                // tabs (causes "controller missing" or "invalid token").
                // visible=false hides it from the back-office menu.
                'name' => 'Emporiqa Connect',
                'class_name' => 'AdminEmporiqaConnect',
                'route_name' => '',
                'parent_class_name' => 'AdminEmporiqa',
                'visible' => false,
                'active' => true,
                'icon' => '',
            ],
        ];
    }

    /**
     * Register any tabs declared in getTabs() that aren't already in
     * ps_tab. Idempotent — safe to call from install() and from upgrade
     * scripts that add new tabs in later versions.
     *
     * PS 9 calls this automatically on fresh install via the
     * ModuleTabRegister Symfony service, but in-place upgrades have no
     * such hook, so upgrade scripts that ship a new tab must call this
     * explicitly.
     */
    public function installTabs()
    {
        foreach ($this->getTabs() as $tabData) {
            if (Tab::getInstanceFromClassName($tabData['class_name'])->id) {
                continue;
            }

            $tab = new Tab();
            $tab->class_name = $tabData['class_name'];
            $tab->module = $this->name;
            $tab->id_parent = empty($tabData['parent_class_name'])
                ? 0
                : (int) Tab::getInstanceFromClassName($tabData['parent_class_name'])->id;
            $tab->active = isset($tabData['active']) ? (bool) $tabData['active'] : true;
            $tab->icon = $tabData['icon'] ?? '';
            $tab->route_name = $tabData['route_name'] ?? '';

            $tab->name = [];
            foreach (Language::getLanguages(false) as $lang) {
                $tab->name[(int) $lang['id_lang']] = (string) $tabData['name'];
            }

            if (!$tab->add()) {
                return false;
            }
        }

        return true;
    }

    public function install()
    {
        if (!self::isPhpSupported()) {
            $this->_errors[] = $this->l('Emporiqa needs PHP 8.0 or newer.');

            return false;
        }

        // Multi-shop: the chat assistant is a site-wide feature, not a
        // per-shop one. Force the install context to "all shops" before
        // parent::install() so the merchant gets the widget on every
        // shop instead of only the one they happened to be viewing
        // when they hit Install. Without this, hooks register for all
        // shops but ps_module_shop only carries a row for the active
        // shop, so the widget silently doesn't render on the others.
        // The merchant can still disable per-shop afterwards via
        // Module Manager -- opt-out is the right default here.
        $originalContext = null;
        $originalShopId = null;
        if (Shop::isFeatureActive()) {
            $originalContext = Shop::getContext();
            $originalShopId = Shop::getContextShopID();
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        $result = parent::install()
            && $this->registerHook('displayHeader')
            && $this->registerHook('actionProductSave')
            && $this->registerHook('actionProductDelete')
            && $this->registerHook('actionObjectCombinationAddAfter')
            && $this->registerHook('actionObjectCombinationUpdateAfter')
            && $this->registerHook('actionObjectCombinationDeleteAfter')
            && $this->registerHook('actionObjectCmsAddAfter')
            && $this->registerHook('actionObjectCmsUpdateAfter')
            && $this->registerHook('actionObjectCmsDeleteAfter')
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('actionOrderStatusPostUpdate')
            && $this->registerHook('actionUpdateQuantity')
            && $this->registerHook('actionObjectSpecificPriceAddAfter')
            && $this->registerHook('actionObjectSpecificPriceUpdateAfter')
            && $this->registerHook('actionObjectSpecificPriceDeleteAfter')
            && $this->registerHook('actionObjectSpecificPriceRuleUpdateBefore')
            && $this->registerHook('actionObjectSpecificPriceRuleDeleteBefore')
            && $this->registerHook('actionAdminSpecificPriceRuleControllerDeleteBefore')
            && $this->registerHook('actionAdminSpecificPriceRuleControllerBulkdeleteBefore')
            && $this->registerHook('actionObjectCurrencyUpdateAfter')
            && $this->registerHook('actionObjectTaxUpdateAfter')
            && $this->registerHook('actionObjectTaxRulesGroupUpdateAfter')
            && $this->registerHook('actionObjectCartRuleAddAfter')
            && $this->registerHook('actionObjectCartRuleUpdateAfter')
            && $this->registerHook('actionObjectCartRuleDeleteAfter')
            && $this->registerHook('actionProductOutOfStock')
            && $this->registerHook('actionObjectCategoryUpdateAfter')
            && $this->registerHook('actionObjectCategoryDeleteAfter')
            && $this->registerHook('actionObjectManufacturerUpdateAfter')
            && $this->registerHook('actionObjectManufacturerDeleteAfter')
            && $this->registerHook('actionObjectImageAddAfter')
            && $this->registerHook('actionObjectImageUpdateAfter')
            && $this->registerHook('actionObjectImageDeleteAfter')
            && $this->registerHook('actionObjectLanguageAddAfter')
            && $this->installConfig()
            && $this->installDb();

        if ($originalContext !== null) {
            Shop::setContext($originalContext, $originalShopId);
        }

        return $result;
    }

    /**
     * Also runs on PHP 7 (a module installed under 8 and left behind by a PHP
     * downgrade), so this path and everything it calls use only core classes
     * and 7.x syntax.
     */
    public function uninstall()
    {
        return parent::uninstall()
            && $this->uninstallConfig()
            && $this->uninstallDb();
    }

    private function installConfig()
    {
        $allCodes = array_map(function ($lang) {
            return EmporiqaLanguageHelper::getLangCode($lang);
        }, Language::getLanguages(true));
        if (empty($allCodes)) {
            $allCodes = ['en'];
        }

        Configuration::updateGlobalValue('EMPORIQA_STORE_ID', '');
        Configuration::updateGlobalValue('EMPORIQA_WEBHOOK_URL', self::DEFAULT_WEBHOOK_URL);
        Configuration::updateGlobalValue('EMPORIQA_WEBHOOK_SECRET', '');
        Configuration::updateGlobalValue('EMPORIQA_SYNC_PRODUCTS', 1);
        Configuration::updateGlobalValue('EMPORIQA_SYNC_PAGES', 1);
        Configuration::updateGlobalValue('EMPORIQA_ENABLED_LANGUAGES', json_encode($allCodes));
        // Order tracking is on and offered, as in 1.2.8: ready-made rules,
        // which replace it, are not offered to every store yet. The module
        // learns whether they are at connect and on Test connection
        // (EMPORIQA_RULES_AVAILABLE, see storeRulesStatus).
        Configuration::updateGlobalValue('EMPORIQA_ORDER_TRACKING', 1);
        Configuration::updateGlobalValue('EMPORIQA_RULES_AVAILABLE', 0);
        Configuration::updateGlobalValue('EMPORIQA_LIVE_RULES', '[]');
        Configuration::updateGlobalValue('EMPORIQA_ORDER_TRACKING_EMAIL', 1);
        Configuration::updateGlobalValue('EMPORIQA_CART_ENABLED', 1);
        Configuration::updateGlobalValue('EMPORIQA_BATCH_SIZE', 25);
        Configuration::updateGlobalValue(EmporiqaChannelResolver::ENABLED_SHOPS_KEY, '[]');

        return true;
    }

    private function uninstallConfig()
    {
        $keys = [
            'EMPORIQA_STORE_ID', 'EMPORIQA_WEBHOOK_URL', 'EMPORIQA_WEBHOOK_SECRET',
            'EMPORIQA_SYNC_PRODUCTS', 'EMPORIQA_SYNC_PAGES', 'EMPORIQA_ENABLED_LANGUAGES',
            'EMPORIQA_ORDER_TRACKING', 'EMPORIQA_ORDER_TRACKING_EMAIL', 'EMPORIQA_CART_ENABLED',
            // EmporiqaChannelResolver::ENABLED_SHOPS_KEY, spelled out for PHP 7.
            'EMPORIQA_BATCH_SIZE', 'EMPORIQA_ENABLED_SHOPS',
            // One-click connect transient (1.2.0+) — cleared on uninstall.
            'EMPORIQA_CONNECT_LAST_ERROR',
            'EMPORIQA_CLOCK_SKEW_LOGGED',
            // 1.3.0: ready-made rules status, sync health.
            'EMPORIQA_RULES_AVAILABLE', 'EMPORIQA_LIVE_RULES',
            'EMPORIQA_LAST_SYNC_PRODUCTS', 'EMPORIQA_LAST_SYNC_PAGES', 'EMPORIQA_LAST_AUTO_FAIL',
            'EMPORIQA_PRICE_WINDOW_SENT_UNTIL', 'EMPORIQA_PRICE_WINDOW_RUN_AT',
        ];
        // EMPORIQA_BASE_URL is deliberately NOT cleared on uninstall:
        // it's a staging/regional override set by the sysadmin and should
        // survive uninstall + reinstall cycles. Never set in production
        // (EmporiqaConnectHandshake falls back to its DEFAULT_BASE_URL).

        // deleteByName removes the key's global, shop-group and shop rows
        // (and their _lang rows). deleteFromContext, used here before 1.3.0
        // when uninstalling from a single-shop context, removed only that
        // shop's row and left the rest behind.
        foreach ($keys as $key) {
            Configuration::deleteByName($key);
        }

        // Per-session sync guard rows (1.2.7+, EMPORIQA_SSN_<hash>, the
        // EmporiqaSyncService::SESSION_STATS_PREFIX) are transient global
        // state — always safe to drop.
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'configuration` '
            . "WHERE `name` LIKE 'EMPORIQA\\_SSN\\_%'"
        );

        return true;
    }

    private function installDb()
    {
        // Not just CREATE IF NOT EXISTS: a table kept from an older version
        // would otherwise miss columns this version needs.
        return EmporiqaSchema::ensure();
    }

    private function uninstallDb()
    {
        // In multi-shop, only drop tables if no other shop still has this module installed
        if (Shop::isFeatureActive()) {
            $sql = new DbQuery();
            $sql->select('COUNT(*)');
            $sql->from('module_shop', 'ms');
            $sql->innerJoin('module', 'm', 'm.id_module = ms.id_module');
            $sql->where('m.name = "emporiqa"');
            $otherShops = (int) Db::getInstance()->getValue($sql);
            if ($otherShops > 0) {
                return true;
            }
        }

        EmporiqaSchema::dropAll();

        return true;
    }

    /**
     * Called during module upgrade (disable + enable cycle).
     *
     * @return bool
     */
    public function reset()
    {
        return true;
    }

    // -------------------------------------------------------------------------
    // Admin Configuration Page
    // -------------------------------------------------------------------------

    public function getContent()
    {
        if (!self::isPhpSupported()) {
            return $this->displayError($this->l('Emporiqa needs PHP 8.0 or newer.'));
        }

        $output = '';

        if (Tools::isSubmit('submitEmporiqaSettings')) {
            $output .= $this->postProcess();
        }

        if (Tools::isSubmit('ajax') && Tools::getValue('action') === 'emporiqaSyncAjax') {
            $this->handleSyncAjax();
        }

        return $output . $this->renderConfigPage();
    }

    private function postProcess()
    {
        $storeId = trim(Tools::getValue('EMPORIQA_STORE_ID'));
        $webhookUrl = trim(Tools::getValue('EMPORIQA_WEBHOOK_URL'));
        $webhookSecret = trim(Tools::getValue('EMPORIQA_WEBHOOK_SECRET'));
        $syncProducts = (int) Tools::getValue('EMPORIQA_SYNC_PRODUCTS');
        $syncPages = (int) Tools::getValue('EMPORIQA_SYNC_PAGES');
        $enabledLanguages = Tools::getValue('EMPORIQA_ENABLED_LANGUAGES');
        $batchSize = (int) Tools::getValue('EMPORIQA_BATCH_SIZE');

        if (!empty($storeId) && !Validate::isCleanHtml($storeId)) {
            return $this->displayError($this->l('Invalid Store ID.'));
        }

        require_once dirname(__FILE__) . '/classes/EmporiqaConnectHandshake.php';
        if (!empty($webhookUrl) && !EmporiqaConnectHandshake::isEmporiqaWebhookUrl($webhookUrl)) {
            return $this->displayError($this->l('Invalid Webhook URL.'));
        }

        if ($batchSize < 1 || $batchSize > 500) {
            $batchSize = 25;
        }

        // Keep only codes of languages that exist and are active, so a
        // stale or forged code can never be stored and later offered.
        $enabledLanguages = is_array($enabledLanguages)
            ? array_values(array_intersect(array_keys(EmporiqaLanguageHelper::getActiveLanguageMap()), $enabledLanguages))
            : [];
        if (empty($enabledLanguages)) {
            $defaultLang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
            $defaultCode = Validate::isLoadedObject($defaultLang) ? EmporiqaLanguageHelper::getLangCode($defaultLang) : 'en';
            $enabledLanguages = [$defaultCode];
        }

        // The shop list is only rendered for multistore installs; without the
        // marker field the stored selection is left untouched.
        $enabledShops = null;
        if (Tools::getValue('EMPORIQA_SHOPS_FIELD')) {
            $submittedShops = Tools::getValue('EMPORIQA_ENABLED_SHOPS');
            $submittedShops = is_array($submittedShops) ? array_map('intval', $submittedShops) : [];
            $activeShopIds = array_keys($this->getChannelResolver()->getAllMapping());
            $enabledShops = array_values(array_intersect($activeShopIds, $submittedShops));
            if (empty($enabledShops)) {
                return $this->displayError($this->l('Tick at least one shop under Shops and languages.'));
            }
            if (count($enabledShops) === count($activeShopIds)) {
                $enabledShops = [];
            }
        }

        if (empty($webhookSecret)) {
            $webhookSecret = Configuration::get('EMPORIQA_WEBHOOK_SECRET');
        }

        Configuration::updateGlobalValue('EMPORIQA_STORE_ID', $storeId);
        Configuration::updateGlobalValue('EMPORIQA_WEBHOOK_URL', $webhookUrl);
        Configuration::updateGlobalValue('EMPORIQA_WEBHOOK_SECRET', $webhookSecret);
        Configuration::updateGlobalValue('EMPORIQA_SYNC_PRODUCTS', $syncProducts);
        Configuration::updateGlobalValue('EMPORIQA_SYNC_PAGES', $syncPages);
        Configuration::updateGlobalValue('EMPORIQA_ENABLED_LANGUAGES', json_encode($enabledLanguages));
        Configuration::updateGlobalValue('EMPORIQA_ORDER_TRACKING', (int) Tools::getValue('EMPORIQA_ORDER_TRACKING'));
        Configuration::updateGlobalValue('EMPORIQA_ORDER_TRACKING_EMAIL', 1);
        Configuration::updateGlobalValue('EMPORIQA_CART_ENABLED', 1);
        Configuration::updateGlobalValue('EMPORIQA_BATCH_SIZE', $batchSize);
        if ($enabledShops !== null) {
            Configuration::updateGlobalValue(EmporiqaChannelResolver::ENABLED_SHOPS_KEY, json_encode($enabledShops));
        }
        EmporiqaChannelResolver::reset();

        // Cached storefront pages were rendered with the old configuration
        // (possibly without the widget at all) — drop them so the widget
        // state changes take effect immediately.
        Tools::clearSmartyCache();

        return $this->displayConfirmation($this->l('Settings saved. To sync your products and pages, go to the Sync tab.'));
    }

    private function renderConfigPage()
    {
        $languages = Language::getLanguages(true);
        foreach ($languages as &$lang) {
            $lang['emporiqa_code'] = EmporiqaLanguageHelper::getLangCode($lang);
            // PrestaShop names a language "English (English)"; the code is
            // what Emporiqa shows, so "English (en-US)".
            $plainName = trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', (string) $lang['name']));
            $lang['emporiqa_label'] = ($plainName !== '' ? $plainName : (string) $lang['name'])
                . ' (' . $lang['emporiqa_code'] . ')';
        }
        unset($lang);
        $enabledLanguages = EmporiqaLanguageHelper::getEnabledLanguages();

        $resolver = $this->getChannelResolver();
        $syncedShops = $resolver->getMapping();
        $shopNames = $resolver->getShopNames();
        $shops = [];
        foreach ($resolver->getAllMapping() as $shopId => $channelKey) {
            $shops[] = [
                'id' => (int) $shopId,
                'name' => isset($shopNames[$shopId]) ? $shopNames[$shopId] : (string) $shopId,
                'channel_key' => $channelKey,
                'enabled' => isset($syncedShops[$shopId]),
            ];
        }
        $isMultistore = Shop::isFeatureActive() && count($shops) > 1;

        $secretSet = !empty(Configuration::get('EMPORIQA_WEBHOOK_SECRET'));
        $storeIdSet = !empty(Configuration::get('EMPORIQA_STORE_ID'));
        $lastError = Configuration::get('EMPORIQA_CONNECT_LAST_ERROR');

        if ($secretSet && $storeIdSet) {
            $connectState = 'connected';
        } elseif (!empty($lastError)) {
            $connectState = 'error';
        } else {
            $connectState = 'not_connected';
        }
        // Clear the one-shot error so it doesn't follow the merchant around.
        if ($connectState === 'error') {
            Configuration::deleteByName('EMPORIQA_CONNECT_LAST_ERROR');
        }

        $justConnected = (int) Tools::getValue('emporiqa_connected') === 1;

        // Emporiqa says at connect and on Test connection whether this store
        // has ready-made rules (every connected store does). Until it has
        // said so, nothing about them is shown and order tracking stays the
        // 1.2.8 one.
        $rulesAvailable = (bool) Configuration::get('EMPORIQA_RULES_AVAILABLE');
        $liveRules = $rulesAvailable ? self::liveRules() : [];
        $orderTracking = (bool) Configuration::get('EMPORIQA_ORDER_TRACKING');
        $connectInitiateUrl = $this->context->link->getAdminLink('AdminEmporiqaConnect', true, [], [
            'action' => 'initiate',
        ]);

        require_once dirname(__FILE__) . '/classes/EmporiqaConnectHandshake.php';
        $this->context->smarty->assign([
            'emporiqa_module_dir' => $this->_path,
            'emporiqa_module_version' => $this->version,
            'emporiqa_store_id' => Configuration::get('EMPORIQA_STORE_ID'),
            'emporiqa_webhook_url' => Configuration::get('EMPORIQA_WEBHOOK_URL'),
            'emporiqa_webhook_secret_set' => $secretSet,
            'emporiqa_sync_products' => Configuration::get('EMPORIQA_SYNC_PRODUCTS'),
            'emporiqa_sync_pages' => Configuration::get('EMPORIQA_SYNC_PAGES'),
            'emporiqa_enabled_languages' => $enabledLanguages,
            'emporiqa_languages' => $languages,
            'emporiqa_shops' => $shops,
            'emporiqa_show_shops' => $isMultistore,
            'emporiqa_shop_context_notice' => $isMultistore && Shop::getContext() !== Shop::CONTEXT_ALL,
            'emporiqa_shops_none_warning' => $resolver->selectionMatchesNoShop(),
            'emporiqa_token' => Tools::hash($this->name . (int) $this->context->employee->id),
            'emporiqa_sync_ajax_url' => $this->getConfigureUrl(),
            'emporiqa_product_count' => $this->getSyncService()->countProducts(),
            'emporiqa_page_count' => $this->getSyncService()->countPages(),
            'emporiqa_platform_base_url' => $this->getPlatformBaseUrl(),
            'emporiqa_order_tracking_url' => $this->context->link->getModuleLink('emporiqa', 'ordertracking'),
            'emporiqa_action_url' => (new EmporiqaConnectHandshake($this->context, $this))->actionsBaseUrl(),
            'emporiqa_order_tracking' => $orderTracking,
            'emporiqa_rules_available' => $rulesAvailable,
            'emporiqa_order_status_live' => in_array('order_status', $liveRules, true),
            'emporiqa_suggest_legacy_off' => $rulesAvailable && $orderTracking
                && in_array('order_status', $liveRules, true),
            'emporiqa_sync_health' => $this->getSyncHealth(),
            'emporiqa_batch_size' => (int) Configuration::get('EMPORIQA_BATCH_SIZE') ?: 25,
            // One-click connect (1.2.0+)
            'emporiqa_connect_state' => $connectState,
            'emporiqa_connect_initiate_url' => $connectInitiateUrl,
            // Stored as "code: message"; the code is for logs, not for the merchant.
            'emporiqa_connect_last_error' => (string) preg_replace('/^[a-z0-9_]+: /', '', (string) $lastError),
            'emporiqa_just_connected' => $justConnected,
            'emporiqa_https_enabled' => (bool) Configuration::get('PS_SSL_ENABLED'),
        ]);

        $this->context->controller->addCSS($this->_path . 'views/css/admin.css?v=' . $this->assetVersion('views/css/admin.css'));
        $this->context->controller->addJS($this->_path . 'views/js/admin-sync.js?v=' . $this->assetVersion('views/js/admin-sync.js'));
        Media::addJsDef(['emporiqaI18n' => $this->getAdminJsStrings()]);

        return $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');
    }

    /**
     * Cache-buster for a back-office asset: the module version plus the
     * file's mtime, so a browser refetches after an upgrade and after a
     * file is replaced in place without a version bump.
     *
     * @param string $relativePath path inside the module directory
     *
     * @return string
     */
    private function assetVersion($relativePath)
    {
        $mtime = @filemtime($this->local_path . $relativePath);

        return $this->version . ($mtime ? '-' . $mtime : '');
    }

    /**
     * Strings admin-sync.js shows, translated here because the JS has no
     * translation layer of its own. %1$s / %2$d placeholders are filled in
     * by the script.
     *
     * @return array<string, string>
     */
    private function getAdminJsStrings()
    {
        $strings = [
            'products' => $this->l('products'),
            'pages' => $this->l('pages'),
            'initializing' => $this->l('Starting the sync...'),
            'initFailed' => $this->l('The sync could not start.'),
            'started' => $this->l('Started syncing %1$s.'),
            'cancelled' => $this->l('Sync cancelled.'),
            'batchRetry' => $this->l('A batch of %1$s failed. Trying it once more.'),
            'batchDone' => $this->l('Sent %1$d %3$s (%2$d events).'),
            'batchFailed' => $this->l('A batch of %1$s failed.'),
            'finishedWithErrors' => $this->l('The sync finished with errors. The sessions with failed batches were not completed, so nothing was deleted on Emporiqa. Fix the errors and run the sync again.'),
            'completed' => $this->l('Sync completed.'),
            'processing' => $this->l('Emporiqa is now processing your data. This can take a few minutes for a large catalog. To follow the progress, open %1$s and %2$s in your Emporiqa dashboard.'),
            'skippedFailed' => $this->l('The sync of %1$s was not completed because %2$d batch(es) failed. It stays open, so nothing is deleted on Emporiqa.'),
            'skippedEmpty' => $this->l('The sync of %1$s was not completed because nothing was sent.'),
            'sessionCompleted' => $this->l('The sync of %1$s is complete.'),
            'sessionFailed' => $this->l('The sync of %1$s could not be completed.'),
            'testing' => $this->l('Testing...'),
            'success' => $this->l('Connection works.'),
            'requestFailed' => $this->l('The request failed. Reload the page and try again.'),
            'copied' => $this->l('Copied!'),
            'technicalDetails' => $this->l('Technical details'),
            'technicalDetailsHelp' => $this->l('The sample data below is what your shop sends to Emporiqa. You only need it if Emporiqa support asks for it.'),
            'sampleProduct' => $this->l('Sample product data'),
            'samplePage' => $this->l('Sample page data'),
        ];

        // Module::l() HTML-escapes; the script writes these with textContent.
        foreach ($strings as $key => $text) {
            $strings[$key] = htmlspecialchars_decode($text, ENT_QUOTES);
        }

        return $strings;
    }

    // -------------------------------------------------------------------------
    // Admin Sync AJAX Handler
    // -------------------------------------------------------------------------

    private function handleSyncAjax()
    {
        if (!$this->context->employee || !$this->context->employee->id) {
            $this->sendJsonAndExit(['success' => false, 'error' => $this->l('Permission denied.')]);
        }

        $token = (string) Tools::getValue('emporiqa_token', '');
        $expectedToken = (string) Tools::hash($this->name . (int) $this->context->employee->id);
        if ($token === '' || !hash_equals($expectedToken, $token)) {
            $this->sendJsonAndExit(['success' => false, 'error' => $this->l('Invalid security token. Reload the page and try again.')]);
        }
        // The token proves the employee, not that they may configure this
        // module: a profile without the module's configure permission is refused.
        if (!$this->getPermission('configure', $this->context->employee)) {
            $this->sendJsonAndExit(['success' => false, 'error' => $this->l('Permission denied.')]);
        }

        $syncAction = Tools::getValue('sync_action');
        $syncService = $this->getSyncService();
        $dryRun = (bool) Tools::getValue('dry_run', false);

        switch ($syncAction) {
            case 'init':
                $entity = Tools::getValue('entity', 'all');
                $result = $syncService->initSync($entity, $dryRun);
                if ($dryRun) {
                    $result['dry_run'] = true;
                }
                break;

            case 'batch':
                $entity = Tools::getValue('entity');
                $sessionId = Tools::getValue('session_id');
                $afterId = (int) Tools::getValue('after_id', 0);
                $result = $syncService->processBatch($entity, $sessionId, $afterId, $dryRun);
                break;

            case 'complete':
                $entity = Tools::getValue('entity');
                $sessionId = Tools::getValue('session_id');
                $result = $syncService->completeSync($entity, $sessionId, $dryRun);
                break;

            case 'test_connection':
                $result = $this->getWebhookClient()->testConnection();
                if (!empty($result['success'])) {
                    // A connected shop learns here, without reconnecting,
                    // whether Emporiqa now offers ready-made rules.
                    if (is_array($result['dry_run'] ?? null)) {
                        self::storeRulesStatus($result['dry_run']);
                    }
                    $result['sample_product'] = $this->getSampleProductPayload();
                    $result['sample_page'] = $this->getSamplePagePayload();
                }
                // Warned from 2 minutes, well inside the 5 minutes Emporiqa
                // allows, so the merchant hears of a drifting clock before
                // every sync starts failing.
                $skew = isset($result['clock_skew']) ? $result['clock_skew'] : null;
                if ($skew !== null && abs($skew) > 120) {
                    $result['clock_warning'] = true;
                    $result['message'] .= ' ' . sprintf(
                        $this->l('Your server clock is off by %d minutes; Emporiqa refuses signatures more than 5 minutes off. Ask your host to enable NTP.'),
                        max(1, (int) round(abs($skew) / 60))
                    );
                }
                break;

            default:
                $result = ['success' => false, 'error' => $this->l('Unknown sync action.')];
        }

        $this->sendJsonAndExit($result);
    }

    private function sendJsonAndExit(array $data)
    {
        EmporiqaJsonResponse::send(200, $data);
    }

    // -------------------------------------------------------------------------
    // Frontend: Widget Embedding (displayHeader hook)
    // -------------------------------------------------------------------------

    public function hookDisplayHeader($params)
    {
        if (!self::isPhpSupported()) {
            return;
        }

        $storeId = Configuration::get('EMPORIQA_STORE_ID');
        if (empty($storeId)) {
            return '';
        }

        $this->queueScheduledPriceChanges();

        if (!$this->getChannelResolver()->isShopEnabled((int) $this->context->shop->id)) {
            return '';
        }
        // An unticked language is neither synced nor offered: no chat on its pages.
        if (!EmporiqaLanguageHelper::isLanguageEnabled($this->context->language)) {
            return '';
        }
        $language = EmporiqaLanguageHelper::getLangCode($this->context->language);

        $queryParams = [
            'store_id' => $storeId,
            'language' => $language,
            'currency' => $this->context->currency->iso_code,
        ];

        $queryParams['channel'] = $this->getChannelResolver()->getCurrentChannelKey();

        // No customer token here: full-page caches would serve it to another
        // shopper. views/js/front-customer-token.js fetches it uncached.

        // Allow other modules to modify widget parameters
        Hook::exec('actionEmporiqaWidgetParams', [
            'params' => &$queryParams,
        ]);

        $widgetUrl = $this->getPlatformBaseUrl('https') . '/chat/embed/?' . http_build_query($queryParams);

        $cartToken = $this->getCartApiToken();
        $cartApiUrl = $this->context->link->getModuleLink('emporiqa', 'cartapi');
        $checkoutUrl = $this->context->link->getPageLink('order');

        // Through PrestaShop's asset manager, so CCC can bundle them and
        // neither blocks rendering. The token script is deferred: it only
        // answers once the chat is opened.
        Media::addJsDef([
            'emporiqa_cart_config' => [
                'ajax_url' => $cartApiUrl,
                'token' => $cartToken,
                'checkout_url' => $checkoutUrl,
            ],
            'emporiqa_token_config' => [
                'url' => $this->context->link->getModuleLink('emporiqa', 'token'),
            ],
        ]);
        $controller = $this->context->controller;
        if ($controller instanceof FrontController) {
            $controller->registerJavascript(
                'module-emporiqa-cart',
                'modules/' . $this->name . '/views/js/front-cart-handler.js',
                ['position' => 'bottom', 'priority' => 200, 'version' => $this->version]
            );
            $controller->registerJavascript(
                'module-emporiqa-customer-token',
                'modules/' . $this->name . '/views/js/front-customer-token.js',
                ['position' => 'head', 'priority' => 200, 'attributes' => 'defer', 'version' => $this->version]
            );
        }

        $this->context->smarty->assign([
            'emporiqa_widget_url' => $widgetUrl,
        ]);

        return $this->display(__FILE__, 'views/templates/hook/header.tpl');
    }

    /**
     * Per-visitor CSRF token for the cart API.
     *
     * Tools::getToken(false) hashes customer id + password, which for
     * guests collapses to one constant token shared by every anonymous
     * visitor — any guest could forge cart mutations against any other
     * guest. Instead, bind the token to a random nonce stored in the
     * visitor's (encrypted and signed) PrestaShop cookie, making it
     * unique per visitor for guests and customers alike.
     *
     * @param bool $mintNonce set false when validating so a request
     *                        without an existing nonce fails closed
     *                        instead of minting one
     *
     * @return string empty string when no nonce exists and minting is off
     */
    public function getCartApiToken($mintNonce = true)
    {
        $cookie = $this->context->cookie;

        // Cookie fields are magic properties; use the explicit accessors
        // so static analysis can follow them.
        $nonce = $cookie->__isset('emporiqa_cart_nonce') ? (string) $cookie->__get('emporiqa_cart_nonce') : '';

        if ($nonce === '') {
            if (!$mintNonce) {
                return '';
            }
            try {
                $nonce = bin2hex(random_bytes(16));
            } catch (Throwable $e) {
                // random_bytes can throw on a broken /dev/urandom; fall back
                // to a weaker per-visitor value rather than breaking every
                // storefront page render.
                $nonce = md5(uniqid((string) mt_rand(), true));
            }
            $cookie->__set('emporiqa_cart_nonce', $nonce);
            $cookie->write();
        }

        $customerId = ($this->context->customer && $this->context->customer->id)
            ? (int) $this->context->customer->id
            : 0;

        return Tools::hash('emporiqa-cartapi:' . $nonce . ':' . $customerId);
    }

    // -------------------------------------------------------------------------
    // Product Sync Hooks
    // -------------------------------------------------------------------------

    public function hookActionProductSave($params)
    {
        if (!Configuration::get('EMPORIQA_SYNC_PRODUCTS')) {
            return;
        }

        $productId = isset($params['id_product']) ? (int) $params['id_product'] : 0;
        if (!$productId && isset($params['product']) && $params['product'] instanceof Product) {
            $productId = (int) $params['product']->id;
        }
        if (!$productId) {
            return;
        }

        // Defer EVERY decision -- active check, shouldSync gate, format,
        // dispatch -- to the shutdown flush. PS9's CQRS save fires this
        // hook multiple times during a single user save, often before
        // later sub-commands have set fields like `active=1`. Reading
        // `$product->active` at hook time can see a half-committed
        // snapshot where a brand-new product still has `active=0`,
        // which would (and did, May 25 2026 demo) flip the queue to
        // delete -- making the create then "delete wins" at flush and
        // never landing in Qdrant. `productSyncEvents` reloads at
        // shutdown and routes inactive products to delete correctly.
        $this->queueProductEvent($productId, 'product.updated');
    }

    public function hookActionProductDelete($params)
    {
        if (!Configuration::get('EMPORIQA_SYNC_PRODUCTS')) {
            return;
        }

        $productId = isset($params['id_product']) ? (int) $params['id_product'] : 0;
        if ($productId) {
            $this->queueProductDelete($productId);
        }
    }

    public function hookActionUpdateQuantity($params)
    {
        if (!Configuration::get('EMPORIQA_SYNC_PRODUCTS')) {
            return;
        }

        // $params carries id_product / id_product_attribute / id_shop /
        // quantity, so we don't need to format the full product. Route this
        // to the lightweight stock path: when ONLY availability changed,
        // ship a tiny `product.availability` event and spare the merchant's
        // server a full rebuild. If a full `product.updated` is already
        // queued for this product, that wins (it carries the final
        // availability anyway) — queueStockEvent no-ops in that case.
        $productId = isset($params['id_product']) ? (int) $params['id_product'] : 0;
        if (!$productId) {
            return;
        }

        $this->queueStockEvent($productId);
    }

    public function hookActionObjectCombinationAddAfter($params)
    {
        $this->handleCombinationChange($params);
    }

    public function hookActionObjectCombinationUpdateAfter($params)
    {
        $this->handleCombinationChange($params);
    }

    public function hookActionObjectCombinationDeleteAfter($params)
    {
        $this->handleCombinationDelete($params);
    }

    private function handleCombinationChange($params)
    {
        if (!Configuration::get('EMPORIQA_SYNC_PRODUCTS')) {
            return;
        }

        $object = isset($params['object']) ? $params['object'] : null;
        if (!$object || !isset($object->id_product)) {
            return;
        }

        $productId = (int) $object->id_product;
        $product = new Product($productId);
        if (Validate::isLoadedObject($product) && $product->active) {
            if (!isset($this->pending['product_syncs'][$productId])) {
                $this->queueProductEvent($product, 'product.updated');
            }
        }
    }

    private function handleCombinationDelete($params)
    {
        if (!self::isPhpSupported()) {
            return;
        }

        if (!Configuration::get('EMPORIQA_SYNC_PRODUCTS')) {
            return;
        }

        $object = isset($params['object']) ? $params['object'] : null;
        if (!$object || !isset($object->id_product)) {
            return;
        }

        // Send delete event for the removed variation
        if ($this->isWebhookConfigured() && isset($object->id)) {
            $client = $this->getWebhookClient();
            $client->dispatchEvent('product.deleted', [
                'identification_number' => 'variation-' . (int) $object->id,
            ]);
        }

        // Re-sync the parent product
        $productId = (int) $object->id_product;
        $product = new Product($productId);
        if (Validate::isLoadedObject($product) && $product->active) {
            if (!isset($this->pending['product_syncs'][$productId])) {
                $this->queueProductEvent($product, 'product.updated');
            }
        }
    }

    // -------------------------------------------------------------------------
    // SpecificPrice Hooks (catalog promos / scheduled discounts / group prices)
    // -------------------------------------------------------------------------
    //
    // SpecificPrice rows can change the effective price WITHOUT touching the
    // product itself (catalog rules, scheduled promos, per-group reductions),
    // so actionProductSave never fires and our cached price stays stale.
    // These three hooks bridge that gap by re-emitting the affected product
    // through the same path hookActionProductSave uses.

    public function hookActionObjectSpecificPriceAddAfter($params)
    {
        $this->handleSpecificPriceChange($params);
    }

    public function hookActionObjectSpecificPriceUpdateAfter($params)
    {
        $this->handleSpecificPriceChange($params);
    }

    public function hookActionObjectSpecificPriceDeleteAfter($params)
    {
        $this->handleSpecificPriceChange($params);
    }

    private function handleSpecificPriceChange($params)
    {
        if (!Configuration::get('EMPORIQA_SYNC_PRODUCTS')) {
            return;
        }

        $object = isset($params['object']) ? $params['object'] : null;
        if (!$object || !isset($object->id_product)) {
            return;
        }

        $productId = (int) $object->id_product;
        if ($productId <= 0) {
            // id_product=0 means a catalog-wide rule (every product).
            // Re-syncing the whole catalog from a hook would block the admin
            // request, so we log and skip — the merchant can trigger a full
            // sync from the admin tab when needed.
            PrestaShopLogger::addLog(
                '[Emporiqa] Catalog-wide SpecificPrice change detected (id_product=0); '
                . 'skipped automatic re-sync. Run a manual sync from the Emporiqa admin tab to refresh prices.',
                1,
                null,
                'Emporiqa'
            );

            return;
        }

        if (isset($this->pending['product_syncs'][$productId])
            || (!empty($object->id_specific_price_rule) && $this->priceRuleOverflow)
        ) {
            return;
        }

        $product = new Product($productId);
        if (!Validate::isLoadedObject($product) || !$product->active) {
            return;
        }

        // A catalog price rule writes one row per product it covers, so
        // saving a rule on a large catalog lands here once per product.
        if (!empty($object->id_specific_price_rule)) {
            $this->queuePriceRuleProducts([$productId]);

            return;
        }

        $this->queueProductEvent($product, 'product.updated');
    }

    // The rows a catalog price rule drops (rule deleted, or a product no
    // longer matching its conditions) go with a raw DELETE that fires no
    // hook, and those products would keep the rule's discount in the chat.
    // Before the rule changes, queue every product it currently covers; the
    // flush runs after the save and the rule's re-application, so each one
    // is sent as it ends up.

    public function hookActionObjectSpecificPriceRuleUpdateBefore($params)
    {
        $rule = isset($params['object']) ? $params['object'] : null;
        if ($rule && !empty($rule->id)) {
            $this->queueProductsOfPriceRules([(int) $rule->id]);
        }
    }

    // The legacy Catalog price rules page (PrestaShop's default while its
    // catalog_price_rule feature flag is off) fires these before the delete,
    // while the rule's rows still exist.

    public function hookActionAdminSpecificPriceRuleControllerDeleteBefore($params)
    {
        $this->queueProductsOfPriceRules([(int) Tools::getValue('id_specific_price_rule')]);
    }

    public function hookActionAdminSpecificPriceRuleControllerBulkdeleteBefore($params)
    {
        $ids = Tools::getValue('specific_price_ruleBox');
        $this->queueProductsOfPriceRules(is_array($ids) ? $ids : []);
    }

    /**
     * Any other delete (the Symfony page, the API, another module).
     * SpecificPriceRule::delete() has already removed the rule's conditions
     * and rows when this fires, so which products it covered is lost: every
     * product of the rule's shop may have carried it.
     */
    public function hookActionObjectSpecificPriceRuleDeleteBefore($params)
    {
        $rule = isset($params['object']) ? $params['object'] : null;
        if (!$rule || empty($rule->id) || isset($this->queuedPriceRules[(int) $rule->id])
            || !Configuration::get('EMPORIQA_SYNC_PRODUCTS')
        ) {
            return;
        }
        $this->queuedPriceRules[(int) $rule->id] = true;

        $rows = Db::getInstance()->executeS(
            'SELECT DISTINCT `id_product` FROM `' . _DB_PREFIX_ . 'product_shop` WHERE `active` = 1'
            . (!empty($rule->id_shop) ? ' AND `id_shop` = ' . (int) $rule->id_shop : '')
            . ' LIMIT ' . (self::PRICE_CHANGE_MAX_PRODUCTS + 1)
        );
        $this->queuePriceRuleProducts(array_column($rows ?: [], 'id_product'));
    }

    /**
     * @param array $ruleIds catalog price rule ids, from the request or a hook
     */
    private function queueProductsOfPriceRules(array $ruleIds)
    {
        if (!Configuration::get('EMPORIQA_SYNC_PRODUCTS')) {
            return;
        }

        $ids = [];
        foreach ($ruleIds as $ruleId) {
            $ruleId = (int) $ruleId;
            if ($ruleId > 0 && !isset($this->queuedPriceRules[$ruleId])) {
                $this->queuedPriceRules[$ruleId] = true;
                $ids[] = $ruleId;
            }
        }
        if (empty($ids)) {
            return;
        }

        $rows = Db::getInstance()->executeS(
            'SELECT DISTINCT `id_product` FROM `' . _DB_PREFIX_ . 'specific_price`'
            . ' WHERE `id_specific_price_rule` IN (' . implode(',', $ids) . ') AND `id_product` > 0'
            . ' LIMIT ' . (self::PRICE_CHANGE_MAX_PRODUCTS + 1)
        );
        $this->queuePriceRuleProducts(array_column($rows ?: [], 'id_product'));
    }

    /**
     * Queue products a catalog price rule changed, up to
     * PRICE_CHANGE_MAX_PRODUCTS per request. A rule on more than that (one
     * on the whole catalog) asks for a full sync once, and queues no more.
     *
     * @param array $productIds
     */
    private function queuePriceRuleProducts(array $productIds)
    {
        if ($this->priceRuleOverflow) {
            return;
        }

        $new = [];
        foreach ($productIds as $productId) {
            $productId = (int) $productId;
            if ($productId > 0 && !isset($this->pending['product_syncs'][$productId])) {
                $new[$productId] = true;
            }
        }
        if ($this->priceRuleProducts + count($new) > self::PRICE_CHANGE_MAX_PRODUCTS) {
            $this->priceRuleOverflow = true;
            $this->handleFullCatalogResync('catalog_price_rule');

            return;
        }

        $this->priceRuleProducts += count($new);
        foreach (array_keys($new) as $productId) {
            $this->queueProductEvent($productId, 'product.updated');
        }
    }

    /**
     * Re-send the products whose specific price (a promo, a catalog price rule,
     * a volume discount) started or ended since the last check.
     *
     * PrestaShop has no cron, and a dated price changes nothing in the
     * database when it starts or ends, so no hook fires: without this, the
     * chat keeps quoting the sale price after the sale ends and the full price
     * while it runs. Storefront page views drive the check instead, at most
     * once per PRICE_WINDOW_CHECK_SECONDS; the chat only runs on the
     * storefront, so a shop with no visitors has no one to quote a stale price
     * to.
     *
     * One request runs it: the claim on RUN_AT is a compare-and-set, so
     * concurrent page views at the same moment do not each re-send. The
     * products go out with the end-of-request flush, at most
     * PRICE_CHANGE_MAX_PRODUCTS of them, oldest change first, and SENT_UNTIL
     * moves only past what was sent: a send that fails or a request that
     * dies is retried on the next check, and a longer backlog continues on
     * the next page view.
     */
    private function queueScheduledPriceChanges()
    {
        if (!Configuration::get('EMPORIQA_SYNC_PRODUCTS') || !SpecificPrice::isFeatureActive()) {
            return;
        }

        $now = time();
        $sentUntil = (int) Configuration::getGlobalValue(self::PRICE_WINDOW_SENT_UNTIL);
        $runAt = (string) Configuration::getGlobalValue(self::PRICE_WINDOW_RUN_AT);
        if ($sentUntil <= 0 || $runAt === '') {
            // The first check only starts the clock.
            Configuration::updateGlobalValue(self::PRICE_WINDOW_SENT_UNTIL, $now);
            Configuration::updateGlobalValue(self::PRICE_WINDOW_RUN_AT, $now);

            return;
        }
        if ($now - (int) $runAt < self::PRICE_WINDOW_CHECK_SECONDS || !$this->claimGlobalValue(self::PRICE_WINDOW_RUN_AT, $runAt, $now)) {
            return;
        }

        // `from`/`to` hold the shop's local time, the same clock date() uses
        // under PrestaShop's configured timezone.
        $since = pSQL(date('Y-m-d H:i:s', $sentUntil));
        $until = pSQL(date('Y-m-d H:i:s', $now));
        $started = '(`from` > \'' . $since . '\' AND `from` <= \'' . $until . '\')';
        $rows = Db::getInstance()->executeS(
            'SELECT `id_product`, MIN(IF(' . $started . ', `from`, `to`)) AS `changed_at`'
            . ' FROM `' . _DB_PREFIX_ . 'specific_price`'
            . ' WHERE ' . $started . ' OR (`to` >= \'' . $since . '\' AND `to` < \'' . $until . '\')'
            . ' GROUP BY `id_product` ORDER BY `changed_at`, `id_product`'
            . ' LIMIT ' . (self::PRICE_CHANGE_MAX_PRODUCTS + 1)
        );
        $rows = $rows ?: [];

        $more = false;
        $sendUntil = $now;
        if (count($rows) > self::PRICE_CHANGE_MAX_PRODUCTS) {
            $last = (string) $rows[self::PRICE_CHANGE_MAX_PRODUCTS - 1]['changed_at'];
            if ($last === (string) $rows[self::PRICE_CHANGE_MAX_PRODUCTS]['changed_at']) {
                // More products than one request sends changed at the very same
                // second (a sale on a large part of the catalog): no cursor can
                // split them.
                $this->handleFullCatalogResync('scheduled_price_window');
                $rows = [];
            } else {
                $rows = array_slice($rows, 0, self::PRICE_CHANGE_MAX_PRODUCTS);
                $sendUntil = (int) strtotime($last);
                $more = true;
            }
        }

        $queued = false;
        foreach ($rows as $row) {
            $productId = (int) $row['id_product'];
            if ($productId > 0) {
                $this->queueProductEvent($productId, 'product.updated');
                $queued = $queued || isset($this->pending['product_syncs'][$productId]);
            } else {
                $this->handleFullCatalogResync('scheduled_price_window');
            }
        }

        if ($queued) {
            $this->pending['price_window'] = ['sent_until' => $sendUntil, 'more' => $more];
        } else {
            Configuration::updateGlobalValue(self::PRICE_WINDOW_SENT_UNTIL, $sendUntil);
        }
    }

    /**
     * Once the dated-price products were sent, move SENT_UNTIL past them;
     * with more waiting, free the claim so the next page view continues.
     *
     * @param array $window pending['price_window']
     * @param bool $sent whether every product batch was accepted
     */
    private function savePriceWindow(array $window, $sent)
    {
        if (empty($window) || !$sent) {
            return;
        }
        Configuration::updateGlobalValue(self::PRICE_WINDOW_SENT_UNTIL, (int) $window['sent_until']);
        if (!empty($window['more'])) {
            Configuration::updateGlobalValue(self::PRICE_WINDOW_RUN_AT, 0);
        }
    }

    /**
     * Compare-and-set on a global configuration value, bypassing the
     * per-request cache: true only for the one request whose UPDATE changed
     * the stored value.
     *
     * @param string $key
     * @param string $expected the value this request read
     * @param int $value
     *
     * @return bool
     */
    private function claimGlobalValue($key, $expected, $value)
    {
        $db = Db::getInstance();
        $updated = $db->execute(
            'UPDATE `' . _DB_PREFIX_ . 'configuration` SET `value` = \'' . (int) $value . '\', `date_upd` = \'' . pSQL(date('Y-m-d H:i:s')) . '\''
            . ' WHERE `name` = \'' . pSQL($key) . '\' AND `id_shop` IS NULL AND `id_shop_group` IS NULL'
            . ' AND `value` = \'' . pSQL($expected) . '\''
        );

        return $updated && (int) $db->Affected_Rows() === 1;
    }

    // -------------------------------------------------------------------------
    // Catalog-wide Price Refresh Hooks (currency / tax updates)
    // -------------------------------------------------------------------------
    //
    // Currency exchange-rate refreshes and tax-rate / tax-rules-group edits
    // change the effective price of MANY products at once without touching
    // any individual product row. Re-syncing per-product here would be
    // wasteful (and impossible — there is no single product to target),
    // so these handlers fall through to the same "catalog-wide" path the
    // SpecificPrice handler uses for id_product=0: log an actionable
    // warning and let the merchant trigger a full sync from the admin tab.

    public function hookActionObjectCurrencyUpdateAfter($params)
    {
        $this->handleFullCatalogResync('currency_update');
    }

    public function hookActionObjectTaxUpdateAfter($params)
    {
        $this->handleFullCatalogResync('tax_rate_update');
    }

    public function hookActionObjectTaxRulesGroupUpdateAfter($params)
    {
        $this->handleFullCatalogResync('tax_group_update');
    }

    private function handleFullCatalogResync($reason)
    {
        if (!Configuration::get('EMPORIQA_SYNC_PRODUCTS')) {
            return;
        }

        // No async queue exists in-module; running a full catalog re-sync
        // synchronously from a hook would block the admin request. Mirror
        // the SpecificPrice id_product=0 path: log and let the merchant
        // trigger a manual sync from the Emporiqa admin tab.
        PrestaShopLogger::addLog(
            '[Emporiqa] Catalog-wide price-affecting change detected (' . (string) $reason . '); '
            . 'skipped automatic re-sync. Run a manual sync from the Emporiqa admin tab to refresh prices.',
            1,
            null,
            'Emporiqa'
        );
    }

    // -------------------------------------------------------------------------
    // Cart Rule Hooks (1.2.0)
    // -------------------------------------------------------------------------
    //
    // Cart rules / vouchers can apply category- or catalog-wide reductions
    // that change effective prices for many products without touching any
    // single product row. Fall through to the same catalog-wide path the
    // currency / tax handlers use: log and let the merchant trigger a manual
    // sync when ready.

    public function hookActionObjectCartRuleAddAfter($params)
    {
        $this->handleFullCatalogResync('cart_rule_add');
    }

    public function hookActionObjectCartRuleUpdateAfter($params)
    {
        $this->handleFullCatalogResync('cart_rule_update');
    }

    public function hookActionObjectCartRuleDeleteAfter($params)
    {
        $this->handleFullCatalogResync('cart_rule_delete');
    }

    // -------------------------------------------------------------------------
    // Category / Manufacturer Hooks (1.2.0)
    // -------------------------------------------------------------------------
    //
    // Category rename/delete affects every product in that category (and
    // breadcrumbs). Manufacturer rename/delete affects the brand text on
    // every product carrying that manufacturer. Both fall through to the
    // catalog-wide path.

    public function hookActionObjectCategoryUpdateAfter($params)
    {
        $this->handleFullCatalogResync('category_update');
    }

    public function hookActionObjectCategoryDeleteAfter($params)
    {
        $this->handleFullCatalogResync('category_delete');
    }

    public function hookActionObjectManufacturerUpdateAfter($params)
    {
        $this->handleFullCatalogResync('manufacturer_update');
    }

    public function hookActionObjectManufacturerDeleteAfter($params)
    {
        $this->handleFullCatalogResync('manufacturer_delete');
    }

    // -------------------------------------------------------------------------
    // Language Hook (1.2.0)
    // -------------------------------------------------------------------------
    //
    // A new language enabled after the initial sync means existing products
    // gain a new locale's title/description that Emporiqa hasn't indexed yet.
    // Catalog-wide re-sync needed.

    public function hookActionObjectLanguageAddAfter($params)
    {
        $this->handleFullCatalogResync('language_add');
    }

    // -------------------------------------------------------------------------
    // Product Out-of-Stock Hook (1.2.0)
    // -------------------------------------------------------------------------
    //
    // Stock transitions (in→out, out→in) change availability shown in the
    // widget. actionUpdateQuantity covers most cases (and is the richer
    // signal — it fires on incremental changes too, not just the boundary),
    // so both hooks route to the SAME lightweight stock path. Per-request
    // dedup coalesces the two fires into one `product.availability` event.

    public function hookActionProductOutOfStock($params)
    {
        if (!Configuration::get('EMPORIQA_SYNC_PRODUCTS')) {
            return;
        }

        $product = isset($params['product']) ? $params['product'] : null;
        $productId = ($product instanceof Product) ? (int) $product->id : 0;
        if (!$productId && isset($params['id_product'])) {
            $productId = (int) $params['id_product'];
        }
        if (!$productId) {
            return;
        }

        $this->queueStockEvent($productId);
    }

    // -------------------------------------------------------------------------
    // Image Hooks (1.2.0)
    // -------------------------------------------------------------------------
    //
    // Product images add/update/delete change widget thumbnails. Image objects
    // expose id_product, so we can scope the re-sync to a single product.

    public function hookActionObjectImageAddAfter($params)
    {
        $this->handleImageChange($params);
    }

    public function hookActionObjectImageUpdateAfter($params)
    {
        $this->handleImageChange($params);
    }

    public function hookActionObjectImageDeleteAfter($params)
    {
        $this->handleImageChange($params);
    }

    private function handleImageChange($params)
    {
        if (!Configuration::get('EMPORIQA_SYNC_PRODUCTS')) {
            return;
        }

        $object = isset($params['object']) ? $params['object'] : null;
        if (!$object || !isset($object->id_product)) {
            return;
        }

        $productId = (int) $object->id_product;
        if ($productId <= 0 || isset($this->pending['product_syncs'][$productId])) {
            return;
        }

        $product = new Product($productId);
        if (!Validate::isLoadedObject($product) || !$product->active) {
            return;
        }

        $this->queueProductEvent($product, 'product.updated');
    }

    // -------------------------------------------------------------------------
    // CMS Page Sync Hooks
    // -------------------------------------------------------------------------

    public function hookActionObjectCmsAddAfter($params)
    {
        $this->handleCmsChange($params, 'page.created');
    }

    public function hookActionObjectCmsUpdateAfter($params)
    {
        $this->handleCmsChange($params, 'page.updated');
    }

    public function hookActionObjectCmsDeleteAfter($params)
    {
        if (!Configuration::get('EMPORIQA_SYNC_PAGES')) {
            return;
        }

        $object = isset($params['object']) ? $params['object'] : null;
        if (!$object || !isset($object->id)) {
            return;
        }

        $this->pending['page_deletes'][(int) $object->id] = true;
        $this->registerShutdownFlush();
    }

    private function handleCmsChange($params, $eventType)
    {
        if (!Configuration::get('EMPORIQA_SYNC_PAGES')) {
            return;
        }

        $object = isset($params['object']) ? $params['object'] : null;
        if (!$object || !isset($object->id)) {
            return;
        }

        // Just queue the id + event type. The fresh DB load, active /
        // shouldSync checks, and dispatch all happen at shutdown so we
        // see the final settled state instead of a half-committed one.
        $this->pending['page_syncs'][(int) $object->id] = $eventType;
        $this->registerShutdownFlush();
    }

    // -------------------------------------------------------------------------
    // Order Hooks (Conversion Tracking)
    // -------------------------------------------------------------------------

    public function hookActionValidateOrder($params)
    {
        $order = isset($params['order']) ? $params['order'] : null;
        if (!$order || !$order->id) {
            return;
        }

        // Read now: the cookie belongs to this request.
        $sessionId = $this->getEmporiqaSessionId();

        if (!empty($sessionId)) {
            Db::getInstance()->insert('emporiqa_order_session', [
                'id_order' => (int) $order->id,
                'emporiqa_sid' => pSQL($sessionId),
                'date_add' => date('Y-m-d H:i:s'),
            ], false, true, Db::ON_DUPLICATE_KEY);
        }

        if (!$this->isWebhookConfigured()) {
            return;
        }

        $this->queueOrderCompleted((int) $order->id, (string) $sessionId);
    }

    /**
     * Mark the order tracked and send order.completed at shutdown, so neither
     * checkout nor a status change waits on Emporiqa. Marked now, so a paid
     * status set later in the same request (hookActionOrderStatusPostUpdate)
     * does not queue a second one; a failed send unmarks it.
     *
     * @param int $orderId
     * @param string $sessionId chat session id, '' when none
     */
    private function queueOrderCompleted($orderId, $sessionId)
    {
        Db::getInstance()->insert('emporiqa_order_tracked', [
            'id_order' => (int) $orderId,
            'date_add' => date('Y-m-d H:i:s'),
        ], false, true, Db::ON_DUPLICATE_KEY);
        $this->pending['orders'][(int) $orderId] = $sessionId;
        $this->registerShutdownFlush();
    }

    /**
     * The deferred half of hookActionValidateOrder: one order.completed
     * (the order, its lines and totals, and the chat session id for
     * conversion attribution), built from the order as committed.
     */
    private function dispatchOrderCompleted($orderId, $sessionId)
    {
        $ok = true;
        try {
            $order = new Order($orderId);
            if (!Validate::isLoadedObject($order)) {
                return;
            }
            $eventData = (new EmporiqaOrderFormatter())->formatOrderCompleted($order);

            Hook::exec('actionEmporiqaFormatOrder', [
                'data' => &$eventData,
                'order' => $order,
            ]);

            // Attach the chat session id AFTER the format hook so the
            // cookie-derived value never flows into the hook dispatch path.
            if ($sessionId !== '') {
                $eventData['emporiqa_session_id'] = $sessionId;
            }

            $ok = $this->getWebhookClient()->dispatchEvent('order.completed', $eventData);
            if (!$ok) {
                PrestaShopLogger::addLog(
                    '[Emporiqa] Order webhook not accepted for #' . (int) $orderId . '; it is retried on the next paid status.',
                    2,
                    null,
                    'Emporiqa'
                );
            }
        } catch (Throwable $e) {
            $ok = false;
            PrestaShopLogger::addLog(
                '[Emporiqa] Order webhook failed for #' . (int) $orderId . ': ' . $e->getMessage(),
                3,
                null,
                'Emporiqa'
            );
        }
        if (!$ok) {
            // Unmarked, so a later paid status reports the order instead.
            Db::getInstance()->delete('emporiqa_order_tracked', 'id_order = ' . (int) $orderId);
        }
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        if (!$this->isWebhookConfigured()) {
            return;
        }

        $orderId = isset($params['id_order']) ? (int) $params['id_order'] : 0;
        $newStatus = isset($params['newOrderStatus']) ? $params['newOrderStatus'] : null;

        if (!$orderId || !$newStatus instanceof OrderState) {
            return;
        }
        $paidStatuses = [
            (int) Configuration::get('PS_OS_PAYMENT'),
            (int) Configuration::get('PS_OS_WS_PAYMENT'),
            (int) Configuration::get('PS_OS_SHIPPING'),
            (int) Configuration::get('PS_OS_DELIVERED'),
        ];

        if (!in_array((int) $newStatus->id, $paidStatuses, true)) {
            return;
        }

        // Duplicate prevention
        $sql = new DbQuery();
        $sql->select('id_order');
        $sql->from('emporiqa_order_tracked');
        $sql->where('id_order = ' . (int) $orderId);
        $tracked = Db::getInstance()->getValue($sql);
        if ($tracked) {
            return;
        }

        $sql = new DbQuery();
        $sql->select('emporiqa_sid');
        $sql->from('emporiqa_order_session');
        $sql->where('id_order = ' . (int) $orderId);
        $this->queueOrderCompleted($orderId, (string) Db::getInstance()->getValue($sql));
    }

    // -------------------------------------------------------------------------
    // Event Queuing (deferred flush)
    // -------------------------------------------------------------------------

    /**
     * Queue a product sync for end-of-request flush.
     *
     * Why we defer: PrestaShop 9's admin product editor dispatches a single
     * "Save" click as MULTIPLE CQRS commands (basic info, translations,
     * categories, SEO, ...). `Product::update()` -- and therefore
     * `actionProductSave` -- is fired from EACH of those handlers, often
     * before the next handler has committed. Dispatching the webhook on
     * the first fire ships a half-committed snapshot (e.g. updated price
     * but stale description).
     *
     * Deferring to `register_shutdown_function`:
     *   1. Coalesces all hook fires for the same product into a single
     *      webhook (natural dedup via the keyed product_syncs queue)
     *   2. Loads the product FRESH from the DB at flush time, after every
     *      CQRS handler in this request has committed
     *   3. Under PHP-FPM the response is already on the wire by the time
     *      shutdown runs, so the merchant's "Save" is never blocked on
     *      our webhook -- effectively async without needing a real queue
     *   4. Same path works under mod_php (just blocks the response for an
     *      extra ~50-100 ms in that legacy hosting scenario)
     *
     * The hook handler only needs the productId + intended event type;
     * the Product object passed in `$params['product']` is intentionally
     * ignored at flush time -- it would carry the pre-commit snapshot we
     * are trying to avoid.
     */
    /**
     * Queue a product for sync at request shutdown. Accepts either a
     * Product object (for hooks that already loaded one to make local
     * decisions) or a bare productId int (for hooks that defer every
     * decision to shutdown). The object is intentionally not stored --
     * we re-load at flush time to see the final post-CQRS state.
     */
    private function queueProductEvent($product, $eventType)
    {
        if (!$this->isWebhookConfigured()) {
            return;
        }
        $productId = $product instanceof Product ? (int) $product->id : (int) $product;
        if (!$productId) {
            return;
        }
        $this->pending['product_syncs'][$productId] = $eventType;
        // A full product.updated supersedes any stock-only event queued
        // earlier this request for the same product — the full payload
        // already carries the final availability_statuses + stock_quantities.
        unset($this->pending['stock'][$productId]);
        $this->registerShutdownFlush();
    }

    /**
     * Queue a stock/availability-only change for end-of-request flush as a
     * lightweight `product.availability` event.
     *
     * Mutually exclusive with full syncs: no-ops if the product is already
     * queued for a full `product.updated` this request (that event carries
     * the final availability). Per-product keyed array doubles as dedup, so
     * many quantity ticks (and combination ticks) for one product in a
     * single request coalesce into ONE event reflecting the final DB state
     * (loaded fresh at flush, after PS9's CQRS commands settle).
     */
    private function queueStockEvent($productId)
    {
        if (!$this->isWebhookConfigured()) {
            return;
        }
        $productId = (int) $productId;
        if (!$productId || isset($this->pending['product_syncs'][$productId])) {
            return;
        }
        $this->pending['stock'][$productId] = true;
        $this->registerShutdownFlush();
    }

    private function queueProductDelete($productId)
    {
        if (!$this->isWebhookConfigured()) {
            return;
        }
        $productId = (int) $productId;
        $this->pending['product_deletes'][$productId] = true;
        // Delete supersedes a stock-only event for the same product.
        unset($this->pending['stock'][$productId]);
        $this->registerShutdownFlush();
    }

    private function registerShutdownFlush()
    {
        // The flush formats with the PHP 8 classes.
        if ($this->shutdownFlushRegistered || !self::isPhpSupported()) {
            return;
        }
        $this->shutdownFlushRegistered = true;
        register_shutdown_function([$this, 'flushPendingProductSyncs']);
    }

    /**
     * Flush queued product + page syncs/deletes. Public so PHP's
     * register_shutdown_function can call it; also safe to call directly
     * from CLI scripts that want to force-flush before exiting.
     *
     * Runs AFTER the HTTP response has been sent (PHP-FPM) and AFTER
     * every CQRS handler in this request has committed -- so the fresh
     * `new Product($id)` / `new CMS($id)` loads see the final settled
     * state. Each entity is processed once even if many hook fires queued
     * it.
     */
    public function flushPendingProductSyncs()
    {
        // Snapshot + clear so re-entrant hook fires triggered during
        // dispatch (e.g. by actionEmporiqaFormatProduct subscribers)
        // queue into a fresh batch instead of mutating the one we're
        // iterating.
        $pending = $this->pending;
        $this->pending = self::NOTHING_PENDING;
        if ($pending === self::NOTHING_PENDING) {
            return;
        }

        $this->finishResponseEarly();
        // Sends run after the response; the request's time limit was meant
        // for the page, not for them.
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $productSyncs = $pending['product_syncs'];
        $productDeletes = $pending['product_deletes'];
        $pageDeletes = $pending['page_deletes'];

        // Orders first: a product send that times out can open the circuit
        // breaker, and an order is never resent by a later save.
        foreach ($pending['orders'] as $orderId => $sessionId) {
            $this->dispatchOrderCompleted((int) $orderId, (string) $sessionId);
        }

        $productGroups = [];
        foreach ($productSyncs as $productId => $eventType) {
            // Delete takes precedence over update when both were queued
            // (e.g. soft-delete sequence: update then deactivate).
            if (isset($productDeletes[$productId])) {
                continue;
            }
            $productGroups[] = $this->productSyncEvents((int) $productId, (string) $eventType);
        }
        foreach (array_keys($productDeletes) as $productId) {
            $productGroups[] = $this->productDeleteEvents((int) $productId);
        }

        // Stock-only events last. Skip any product that also got a full
        // sync or a delete this request (mutual exclusivity / delete wins).
        foreach (array_keys($pending['stock']) as $productId) {
            if (isset($productSyncs[$productId]) || isset($productDeletes[$productId])) {
                continue;
            }
            $productGroups[] = $this->productStockEvents((int) $productId);
        }
        $this->savePriceWindow($pending['price_window'], $this->dispatchProductGroups($productGroups));

        foreach ($pending['page_syncs'] as $cmsId => $eventType) {
            if (isset($pageDeletes[$cmsId])) {
                continue;
            }
            $this->dispatchPageSync((int) $cmsId, (string) $eventType);
        }
        foreach (array_keys($pageDeletes) as $cmsId) {
            $this->dispatchPageDelete((int) $cmsId);
        }
    }

    /**
     * Hand the response to the shopper or merchant before the sends, so they
     * cost them nothing (PHP-FPM and LiteSpeed; elsewhere the request just
     * waits for them).
     *
     * This runs as a shutdown function, and PrestaShop writes its cookie
     * from Cookie::__destruct, which runs after shutdown functions. Once the
     * response is finished that Set-Cookie can no longer reach the browser,
     * so a login, a cart id or a back-office session change made in this
     * request would be lost. The cookie is therefore written first.
     *
     * Finishing early is kept for every request instead of being skipped for
     * payment or module controllers: no reliable signal tells those apart
     * (payment returns and webhooks are ordinary module front controllers),
     * and anything else they send has already been sent by the time a
     * shutdown function runs. Cookie::write() itself does nothing when
     * nothing changed or headers are already out.
     */
    private function finishResponseEarly()
    {
        if (Tools::isPHPCLI()) {
            return;
        }
        if (isset($this->context->cookie) && $this->context->cookie instanceof Cookie) {
            try {
                $this->context->cookie->write();
            } catch (Throwable $e) {
                return;
            }
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }

    /**
     * The product.created / product.updated events for one product, [] when
     * it is gone, vetoed or failed to format. An inactive product becomes
     * its delete events.
     *
     * @return array<int, array{type: string, data: array}>
     */
    private function productSyncEvents($productId, $eventType)
    {
        try {
            // Fresh DB load -- the hook params' Product object may carry
            // a pre-commit snapshot from PS9's multi-step CQRS save.
            $product = new Product($productId);
            if (!Validate::isLoadedObject($product)) {
                return [];
            }
            if (!$product->active) {
                return $this->productDeleteEvents($productId);
            }

            $shouldSync = true;
            $this->dispatchSyncHook('actionEmporiqaShouldSyncProduct', [
                'product' => $product,
                'event_type' => $eventType,
            ], $shouldSync);
            if (!$shouldSync) {
                return [];
            }

            $formatted = $this->getProductFormatter()->format($product);

            // Let other modules tweak each parent/variation payload.
            foreach ($formatted as &$item) {
                Hook::exec('actionEmporiqaFormatProduct', [
                    'data' => &$item,
                    'product' => $product,
                    'event_type' => $eventType,
                ]);
            }
            unset($item);

            $events = [];
            foreach ($formatted as $item) {
                $events[] = ['type' => $eventType, 'data' => $item];
            }

            return $events;
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('[Emporiqa] Deferred sync failed: ' . $e->getMessage(), 2, null, 'Emporiqa');

            return [];
        }
    }

    /**
     * @return array<int, array{type: string, data: array}>
     */
    private function productDeleteEvents($productId)
    {
        try {
            $events = [[
                'type' => 'product.deleted',
                'data' => ['identification_number' => 'product-' . $productId],
            ]];

            $combinations = Product::getProductAttributesIds($productId);
            if ($combinations) {
                foreach ($combinations as $combo) {
                    $events[] = [
                        'type' => 'product.deleted',
                        'data' => ['identification_number' => 'variation-' . $combo['id_product_attribute']],
                    ];
                }
            }

            return $events;
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('[Emporiqa] Deferred sync failed: ' . $e->getMessage(), 2, null, 'Emporiqa');

            return [];
        }
    }

    /**
     * Lightweight `product.availability` events for a stock-only change.
     * Loads the product fresh (post-CQRS) and respects the same gating as
     * the full sync: inactive products fall through to a delete, and the
     * `actionEmporiqaShouldSyncProduct` veto is honored so excluded /
     * unsyncable products never emit. One event per parent + variation,
     * each carrying only the shared availability contract.
     *
     * @return array<int, array{type: string, data: array}>
     */
    private function productStockEvents($productId)
    {
        try {
            $product = new Product($productId);
            if (!Validate::isLoadedObject($product)) {
                return [];
            }
            if (!$product->active) {
                return $this->productDeleteEvents($productId);
            }

            $shouldSync = true;
            $this->dispatchSyncHook('actionEmporiqaShouldSyncProduct', [
                'product' => $product,
                'event_type' => 'product.availability',
            ], $shouldSync);
            if (!$shouldSync) {
                return [];
            }

            $events = [];
            foreach ($this->getProductFormatter()->formatAvailability($product) as $item) {
                $events[] = ['type' => 'product.availability', 'data' => $item];
            }

            return $events;
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('[Emporiqa] Deferred sync failed: ' . $e->getMessage(), 2, null, 'Emporiqa');

            return [];
        }
    }

    /**
     * Send the products' events in requests of about FLUSH_BATCH_SIZE
     * events, so a bulk edit of N products costs N/50 requests (each still
     * capped at SYNC_HOOK_TIMEOUT) instead of N. A product's parent and
     * variations always travel in one request; a product with more
     * variations than the batch size goes alone.
     *
     * @param array<int, array<int, array{type: string, data: array}>> $groups one list of events per product
     *
     * @return bool whether every request was accepted
     */
    private function dispatchProductGroups(array $groups)
    {
        $sent = true;
        $batch = [];
        foreach ($groups as $events) {
            if (!empty($batch) && count($batch) + count($events) > EmporiqaWebhookClient::FLUSH_BATCH_SIZE) {
                $sent = $this->dispatchProductBatch($batch) && $sent;
                $batch = [];
            }
            foreach ($events as $event) {
                $batch[] = $event;
            }
        }
        if (!empty($batch)) {
            $sent = $this->dispatchProductBatch($batch) && $sent;
        }

        return $sent;
    }

    private function dispatchProductBatch(array $events)
    {
        try {
            return $this->getWebhookClient()->dispatchEvents($events);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('[Emporiqa] Deferred sync failed: ' . $e->getMessage(), 2, null, 'Emporiqa');

            return false;
        }
    }

    private function dispatchPageSync($cmsId, $eventType)
    {
        if (!$this->isWebhookConfigured()) {
            return;
        }
        try {
            // Fresh DB load so we see the final post-CQRS state.
            $cms = new CMS($cmsId);
            if (!Validate::isLoadedObject($cms)) {
                return;
            }
            if (!$cms->active) {
                $this->dispatchPageDelete($cmsId);

                return;
            }

            $shouldSync = true;
            $this->dispatchSyncHook('actionEmporiqaShouldSyncPage', [
                'page' => $cms,
                'event_type' => $eventType,
            ], $shouldSync);
            if (!$shouldSync) {
                return;
            }

            $formatted = $this->getPageFormatter()->format($cms);
            if (empty($formatted)) {
                return;
            }
            Hook::exec('actionEmporiqaFormatPage', [
                'data' => &$formatted,
                'page' => $cms,
                'event_type' => $eventType,
            ]);
            $this->getWebhookClient()->dispatchEvent($eventType, $formatted);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('[Emporiqa] Deferred sync failed: ' . $e->getMessage(), 2, null, 'Emporiqa');
        }
    }

    private function dispatchPageDelete($cmsId)
    {
        if (!$this->isWebhookConfigured()) {
            return;
        }
        try {
            $this->getWebhookClient()->dispatchEvent('page.deleted', [
                'identification_number' => 'page-' . $cmsId,
            ]);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('[Emporiqa] Deferred sync failed: ' . $e->getMessage(), 2, null, 'Emporiqa');
        }
    }

    /**
     * Keep what Emporiqa said about ready-made rules: whether this store is
     * offered them, and which are live. Both /connect/exchange and the Test
     * connection dry run carry it; an answer without the key (an older
     * platform) changes nothing.
     *
     * @param array $answer
     */
    public static function storeRulesStatus(array $answer)
    {
        if (!array_key_exists('rules_available', $answer)) {
            return;
        }
        $live = [];
        if (isset($answer['live_rules']) && is_array($answer['live_rules'])) {
            foreach ($answer['live_rules'] as $key) {
                if (is_string($key) && preg_match('/^[a-z_]{1,40}$/', $key)) {
                    $live[] = $key;
                }
            }
        }
        Configuration::updateGlobalValue('EMPORIQA_RULES_AVAILABLE', $answer['rules_available'] ? 1 : 0);
        Configuration::updateGlobalValue('EMPORIQA_LIVE_RULES', json_encode($answer['rules_available'] ? $live : []));
    }

    /**
     * @return string[] ready-made rule keys Emporiqa last reported live
     */
    private static function liveRules()
    {
        $live = json_decode((string) Configuration::get('EMPORIQA_LIVE_RULES'), true);

        return is_array($live) ? array_values(array_filter($live, 'is_string')) : [];
    }

    /**
     * The Sync tab's health lines: the last full sync of each kind and the
     * last failed automatic send, as stored by EmporiqaSyncService and
     * EmporiqaWebhookClient.
     *
     * @return array<string, mixed>
     */
    private function getSyncHealth()
    {
        $health = [];
        foreach (['products', 'pages'] as $entity) {
            $row = json_decode((string) Configuration::get('EMPORIQA_LAST_SYNC_' . strtoupper($entity)), true);
            $health[$entity] = is_array($row) && !empty($row['at']) ? [
                'at' => Tools::displayDate(date('Y-m-d H:i:s', (int) $row['at']), true),
                'ok' => !empty($row['ok']),
            ] : null;
        }
        $autoFail = (int) Configuration::get('EMPORIQA_LAST_AUTO_FAIL');
        $health['auto_fail_at'] = $autoFail > 0 ? Tools::displayDate(date('Y-m-d H:i:s', $autoFail), true) : '';

        return $health;
    }

    private function getConfigureUrl()
    {
        if (isset($_SERVER['REQUEST_URI'])) {
            // PrestaShop's own check, which also reads X-Forwarded-Proto: behind
            // a proxy that ends TLS, HTTPS is unset and an http:// URL here is
            // blocked as mixed content, so no Sync tab button would work.
            $scheme = Tools::usingSecureMode() ? 'https' : 'http';
            $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';

            return $scheme . '://' . $host . $_SERVER['REQUEST_URI'];
        }

        return $this->context->link->getAdminLink('AdminModules', true) . '&configure=emporiqa';
    }

    /**
     * The Emporiqa platform's origin, from the webhook URL's host.
     *
     * @param string|null $scheme forced scheme; null keeps the webhook URL's
     *
     * @return string
     */
    private function getPlatformBaseUrl($scheme = null)
    {
        $parsed = parse_url(Configuration::get('EMPORIQA_WEBHOOK_URL') ?: self::DEFAULT_WEBHOOK_URL);
        $host = isset($parsed['host']) ? $parsed['host'] : 'emporiqa.com';
        if ($scheme === null) {
            $scheme = isset($parsed['scheme']) ? $parsed['scheme'] : 'https';
        }

        return $scheme . '://' . $host;
    }

    private function getSampleProductPayload()
    {
        try {
            $sql = new DbQuery();
            $sql->select('p.id_product');
            $sql->from('product', 'p');
            $sql->innerJoin('product_shop', 'ps', 'p.id_product = ps.id_product AND ps.id_shop = ' . (int) $this->context->shop->id);
            $sql->where('ps.active = 1');
            $sql->orderBy('p.id_product ASC');
            // No ->limit(1): getRow() appends LIMIT 1 itself, and a second
            // one is a SQL error that left both samples null.

            $row = Db::getInstance()->getRow($sql);
            if (!$row) {
                return null;
            }

            $product = new Product((int) $row['id_product']);
            if (!Validate::isLoadedObject($product)) {
                return null;
            }

            $formatted = $this->getProductFormatter()->format($product);

            return !empty($formatted) ? $formatted[0] : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function getSamplePagePayload()
    {
        try {
            $sql = new DbQuery();
            $sql->select('c.id_cms');
            $sql->from('cms', 'c');
            $sql->innerJoin('cms_shop', 'cs', 'c.id_cms = cs.id_cms AND cs.id_shop = ' . (int) $this->context->shop->id);
            $sql->where('c.active = 1');
            $sql->orderBy('c.id_cms ASC');
            // No ->limit(1): getRow() appends LIMIT 1 itself, and a second
            // one is a SQL error that left both samples null.

            $row = Db::getInstance()->getRow($sql);
            if (!$row) {
                return null;
            }

            $cms = new CMS((int) $row['id_cms']);
            if (!Validate::isLoadedObject($cms)) {
                return null;
            }

            return $this->getPageFormatter()->format($cms);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Read and sanitize the emporiqa_sid cookie value.
     *
     * The returned value is rebuilt by construction with preg_replace, so it
     * can only ever contain `[A-Za-z0-9_-]` (max 128 chars) and is never the
     * raw cookie string. This keeps it provably safe for every downstream use
     * (DB write, webhook payload, hook dispatch) and lets static-analysis
     * taint trackers recognise it as sanitized at this source.
     */
    private function getEmporiqaSessionId()
    {
        // emporiqa_sid is a custom cookie set by the widget JS. PS' cookie
        // manager only handles its own cookies, so $_COOKIE is read directly.
        $raw = isset($_COOKIE['emporiqa_sid']) ? (string) $_COOKIE['emporiqa_sid'] : '';
        if ($raw === '' || !preg_match('/^[a-zA-Z0-9_\-]{1,128}$/', $raw)) {
            return '';
        }

        // Already validated above; rebuild via a stripping sanitizer so the
        // result is a fresh whitelist-only string -- no raw user input flows out.
        return (string) preg_replace('/[^a-zA-Z0-9_\-]/', '', $raw);
    }

    /**
     * Also the PHP 8 gate for every sync and order hook: on older PHP (a
     * module left behind by a PHP downgrade) the class files are not loaded,
     * so nothing may be queued or sent.
     */
    private function isWebhookConfigured()
    {
        if (!self::isPhpSupported()) {
            return false;
        }
        $url = Configuration::get('EMPORIQA_WEBHOOK_URL');
        $secret = Configuration::get('EMPORIQA_WEBHOOK_SECRET');
        $storeId = Configuration::get('EMPORIQA_STORE_ID');

        return !empty($url) && !empty($secret) && !empty($storeId);
    }

    /**
     * Dispatch a sync-check hook, allowing other modules to cancel sync.
     *
     * @param string $hookName Hook name
     * @param array $hookParams Hook parameters
     * @param bool $shouldSync Modified by reference; hooks may set to false
     */
    private function dispatchSyncHook($hookName, array $hookParams, &$shouldSync)
    {
        $hookParams['should_sync'] = &$shouldSync;
        Hook::exec($hookName, $hookParams);
    }
}
