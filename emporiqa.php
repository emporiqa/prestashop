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

require_once dirname(__FILE__) . '/classes/EmporiqaSignatureHelper.php';
require_once dirname(__FILE__) . '/classes/EmporiqaLanguageHelper.php';
require_once dirname(__FILE__) . '/classes/EmporiqaChannelResolver.php';
require_once dirname(__FILE__) . '/classes/EmporiqaWebhookClient.php';
require_once dirname(__FILE__) . '/classes/EmporiqaProductFormatter.php';
require_once dirname(__FILE__) . '/classes/EmporiqaPageFormatter.php';
require_once dirname(__FILE__) . '/classes/EmporiqaOrderFormatter.php';
require_once dirname(__FILE__) . '/classes/EmporiqaCartHandler.php';
require_once dirname(__FILE__) . '/classes/EmporiqaSyncService.php';

class Emporiqa extends Module
{
    const DEFAULT_WEBHOOK_URL = 'https://emporiqa.com/webhooks/sync/';

    /** Where one-click connect points the browser. Override via Configuration::updateValue('EMPORIQA_BASE_URL', ...) for staging. */
    const DEFAULT_BASE_URL = 'https://emporiqa.com';

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
     * @var array<int, string> productId => event type (e.g. "product.updated").
     *
     * Product changes are queued here during the request and flushed once
     * at request shutdown so the webhook payload reflects the FINAL DB
     * state, not the half-committed state visible to whichever hook fired
     * first. Doubles as per-request dedup: a parent product touched by
     * five different hooks in the same request emits one webhook.
     */
    private $pendingProductSyncs = [];

    /** @var array<int, true> productId => true. Same flush as syncs; delete wins on conflict. */
    private $pendingProductDeletes = [];

    /** @var bool true once we've registered the shutdown callback this request. */
    private $shutdownFlushRegistered = false;

    /**
     * @var array<int, string> cmsId => event type.
     *
     * Same deferred-flush rationale as `$pendingProductSyncs`: CMS pages
     * on PS9 also save across multiple CQRS commands; we wait for shutdown
     * to read the final state.
     */
    private $pendingPageSyncs = [];

    /** @var array<int, true> cmsId => true. Delete wins on conflict. */
    private $pendingPageDeletes = [];

    public function __construct()
    {
        $this->name = 'emporiqa';
        $this->module_key = '19a6bf09ba552447feda82c897be7296';
        $this->tab = 'front_office_features';
        $this->version = '1.2.1';
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
        $this->description = $this->l('Integrates PrestaShop with Emporiqa chat assistant.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall Emporiqa? All configuration will be removed.');
    }

    /**
     * @return EmporiqaWebhookClient
     */
    public function getWebhookClient()
    {
        if (!$this->webhookClient) {
            $this->webhookClient = new EmporiqaWebhookClient($this->getChannelResolver());
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
                $this->getPageFormatter()
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
            if (Tab::getIdFromClassName($tabData['class_name'])) {
                continue;
            }

            $tab = new Tab();
            $tab->class_name = $tabData['class_name'];
            $tab->module = $this->name;
            $tab->id_parent = empty($tabData['parent_class_name'])
                ? 0
                : (int) Tab::getIdFromClassName($tabData['parent_class_name']);
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
        Configuration::updateGlobalValue('EMPORIQA_ORDER_TRACKING', 1);
        Configuration::updateGlobalValue('EMPORIQA_ORDER_TRACKING_EMAIL', 1);
        Configuration::updateGlobalValue('EMPORIQA_CART_ENABLED', 1);
        Configuration::updateGlobalValue('EMPORIQA_BATCH_SIZE', 25);

        return true;
    }

    private function uninstallConfig()
    {
        $keys = [
            'EMPORIQA_STORE_ID', 'EMPORIQA_WEBHOOK_URL', 'EMPORIQA_WEBHOOK_SECRET',
            'EMPORIQA_SYNC_PRODUCTS', 'EMPORIQA_SYNC_PAGES', 'EMPORIQA_ENABLED_LANGUAGES',
            'EMPORIQA_ORDER_TRACKING', 'EMPORIQA_ORDER_TRACKING_EMAIL', 'EMPORIQA_CART_ENABLED',
            'EMPORIQA_BATCH_SIZE',
            // One-click connect transient (1.2.0+) — cleared on uninstall.
            'EMPORIQA_CONNECT_LAST_ERROR',
        ];
        // EMPORIQA_BASE_URL is deliberately NOT cleared on uninstall:
        // it's a staging/regional override set by the sysadmin and should
        // survive uninstall + reinstall cycles. Never set in production
        // (controller falls back to DEFAULT_BASE_URL when unset).
        if (Shop::isFeatureActive() && Shop::getContext() !== Shop::CONTEXT_ALL) {
            foreach ($keys as $key) {
                Configuration::deleteFromContext($key);
            }
        } else {
            foreach ($keys as $key) {
                Configuration::deleteByName($key);
            }
        }

        return true;
    }

    private function installDb()
    {
        $sql = [];

        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'emporiqa_order_session` (
            `id_order` INT(10) UNSIGNED NOT NULL,
            `emporiqa_sid` VARCHAR(128) NOT NULL,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_order`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'emporiqa_order_tracked` (
            `id_order` INT(10) UNSIGNED NOT NULL,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_order`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        // One-click connect: stores the PKCE verifier keyed by sha256(state).
        // Rows are atomically consumed on callback and auto-expire after 5 min.
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'emporiqa_connect_nonce` (
            `state_hash` CHAR(64) NOT NULL,
            `verifier` VARCHAR(128) NOT NULL,
            `created_at` INT(10) UNSIGNED NOT NULL,
            PRIMARY KEY (`state_hash`),
            KEY `idx_created_at` (`created_at`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        foreach ($sql as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
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

        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'emporiqa_order_session`');
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'emporiqa_order_tracked`');
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'emporiqa_connect_nonce`');

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

        if (!empty($webhookUrl) && !Validate::isAbsoluteUrl($webhookUrl)) {
            return $this->displayError($this->l('Invalid Webhook URL.'));
        }

        if ($batchSize < 1 || $batchSize > 500) {
            $batchSize = 25;
        }

        if (!is_array($enabledLanguages)) {
            $defaultLang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
            $defaultCode = Validate::isLoadedObject($defaultLang) ? EmporiqaLanguageHelper::getLangCode($defaultLang) : 'en';
            $enabledLanguages = [$defaultCode];
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
        Configuration::updateGlobalValue('EMPORIQA_ORDER_TRACKING', 1);
        Configuration::updateGlobalValue('EMPORIQA_ORDER_TRACKING_EMAIL', 1);
        Configuration::updateGlobalValue('EMPORIQA_CART_ENABLED', 1);
        Configuration::updateGlobalValue('EMPORIQA_BATCH_SIZE', $batchSize);

        return $this->displayConfirmation($this->l('Settings saved. To sync your products and pages, go to the Sync tab.'));
    }

    private function renderConfigPage()
    {
        $languages = Language::getLanguages(true);
        foreach ($languages as &$lang) {
            $lang['emporiqa_code'] = EmporiqaLanguageHelper::getLangCode($lang);
        }
        unset($lang);
        $enabledLanguages = json_decode(Configuration::get('EMPORIQA_ENABLED_LANGUAGES'), true) ?: EmporiqaLanguageHelper::getEnabledLanguages();

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
        $connectInitiateUrl = $this->context->link->getAdminLink('AdminEmporiqaConnect', true, [], [
            'action' => 'initiate',
        ]);

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
            'emporiqa_token' => Tools::hash($this->name . (int) $this->context->employee->id),
            'emporiqa_sync_ajax_url' => $this->getConfigureUrl(),
            'emporiqa_product_count' => $this->getSyncService()->countProducts(),
            'emporiqa_page_count' => $this->getSyncService()->countPages(),
            'emporiqa_platform_base_url' => $this->getPlatformBaseUrl(),
            'emporiqa_order_tracking_url' => $this->context->link->getModuleLink('emporiqa', 'ordertracking'),
            'emporiqa_batch_size' => (int) Configuration::get('EMPORIQA_BATCH_SIZE') ?: 25,
            // One-click connect (1.2.0+)
            'emporiqa_connect_state' => $connectState,
            'emporiqa_connect_initiate_url' => $connectInitiateUrl,
            'emporiqa_connect_last_error' => $lastError ?: '',
            'emporiqa_just_connected' => $justConnected,
            'emporiqa_https_enabled' => (bool) Configuration::get('PS_SSL_ENABLED'),
        ]);

        $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
        $this->context->controller->addJS($this->_path . 'views/js/admin-sync.js');

        return $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');
    }

    // -------------------------------------------------------------------------
    // Admin Sync AJAX Handler
    // -------------------------------------------------------------------------

    private function handleSyncAjax()
    {
        if (!$this->context->employee || !$this->context->employee->id) {
            $this->sendJsonAndExit(['success' => false, 'error' => 'Permission denied.']);
        }

        $token = Tools::getValue('emporiqa_token', '');
        $expectedToken = Tools::hash($this->name . (int) $this->context->employee->id);
        if (empty($token) || $token !== $expectedToken) {
            $this->sendJsonAndExit(['success' => false, 'error' => 'Invalid security token.']);
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
                $page = (int) Tools::getValue('page', 1);
                $result = $syncService->processBatch($entity, $sessionId, $page, $dryRun);
                break;

            case 'complete':
                $entity = Tools::getValue('entity');
                $sessionId = Tools::getValue('session_id');
                $result = $syncService->completeSync($entity, $sessionId, $dryRun);
                break;

            case 'test_connection':
                $result = $this->getWebhookClient()->testConnection();
                if (!empty($result['success'])) {
                    $result['sample_product'] = $this->getSampleProductPayload();
                    $result['sample_page'] = $this->getSamplePagePayload();
                }
                break;

            default:
                $result = ['success' => false, 'error' => 'Unknown sync action.'];
        }

        $this->sendJsonAndExit($result);
    }

    private function sendJsonAndExit(array $data)
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
        exit(json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE));
    }

    // -------------------------------------------------------------------------
    // Frontend: Widget Embedding (displayHeader hook)
    // -------------------------------------------------------------------------

    public function hookDisplayHeader($params)
    {
        $storeId = Configuration::get('EMPORIQA_STORE_ID');
        if (empty($storeId)) {
            return '';
        }

        $enabledLanguages = EmporiqaLanguageHelper::getEnabledLanguages();
        $language = EmporiqaLanguageHelper::getLangCode($this->context->language);
        if (!in_array($language, $enabledLanguages, true)) {
            $language = !empty($enabledLanguages) ? $enabledLanguages[0] : 'en';
        }

        $queryParams = [
            'store_id' => $storeId,
            'language' => $language,
            'currency' => $this->context->currency->iso_code,
        ];

        $queryParams['channel'] = $this->getChannelResolver()->getCurrentChannelKey();

        if ($this->context->customer && $this->context->customer->isLogged()) {
            $webhookSecret = Configuration::get('EMPORIQA_WEBHOOK_SECRET');
            if (!empty($webhookSecret)) {
                $queryParams['user_id'] = EmporiqaSignatureHelper::generateUserToken(
                    (string) $this->context->customer->id,
                    $webhookSecret
                );
            }
        }

        // Allow other modules to modify widget parameters
        Hook::exec('actionEmporiqaWidgetParams', [
            'params' => &$queryParams,
        ]);

        $webhookUrl = Configuration::get('EMPORIQA_WEBHOOK_URL') ?: self::DEFAULT_WEBHOOK_URL;
        $parsed = parse_url($webhookUrl);
        $baseDomain = isset($parsed['host']) ? $parsed['host'] : 'emporiqa.com';
        $widgetUrl = 'https://' . $baseDomain . '/chat/embed/?' . http_build_query($queryParams);

        $cartToken = Tools::getToken(false);
        $cartApiUrl = $this->context->link->getModuleLink('emporiqa', 'cartapi');
        $checkoutUrl = $this->context->link->getPageLink('order');

        $cartHandlerJs = $this->_path . 'views/js/front-cart-handler.js?v=' . $this->version;

        $this->context->smarty->assign([
            'emporiqa_cart_ajax_url' => $cartApiUrl,
            'emporiqa_cart_token' => $cartToken,
            'emporiqa_checkout_url' => $checkoutUrl,
            'emporiqa_cart_handler_js' => $cartHandlerJs,
            'emporiqa_widget_url' => $widgetUrl,
        ]);

        return $this->display(__FILE__, 'views/templates/hook/header.tpl');
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
        // never landing in Qdrant. `dispatchProductSync` reloads at
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

        $productId = isset($params['id_product']) ? (int) $params['id_product'] : 0;
        if (!$productId || isset($this->pendingProductSyncs[$productId])) {
            return;
        }

        $product = new Product($productId);
        if (!Validate::isLoadedObject($product) || !$product->active) {
            return;
        }

        $this->queueProductEvent($product, 'product.updated');
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
            if (!isset($this->pendingProductSyncs[$productId])) {
                $this->queueProductEvent($product, 'product.updated');
            }
        }
    }

    private function handleCombinationDelete($params)
    {
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
            if (!isset($this->pendingProductSyncs[$productId])) {
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

        if (isset($this->pendingProductSyncs[$productId])) {
            return;
        }

        $product = new Product($productId);
        if (!Validate::isLoadedObject($product) || !$product->active) {
            return;
        }

        $this->queueProductEvent($product, 'product.updated');
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
    // widget. actionUpdateQuantity covers most cases, but actionProductOutOfStock
    // fires on the boundary transition specifically and is the safer signal.
    // Re-sync just the affected product id.

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
        if (!$productId || isset($this->pendingProductSyncs[$productId])) {
            return;
        }

        if (!($product instanceof Product) || (int) $product->id !== $productId) {
            $product = new Product($productId);
        }
        if (!Validate::isLoadedObject($product) || !$product->active) {
            return;
        }

        $this->queueProductEvent($product, 'product.updated');
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
        if ($productId <= 0 || isset($this->pendingProductSyncs[$productId])) {
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

        $this->pendingPageDeletes[(int) $object->id] = true;
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
        $this->pendingPageSyncs[(int) $object->id] = $eventType;
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

        try {
            $orderFormatter = new EmporiqaOrderFormatter();
            $eventData = $orderFormatter->formatOrderCompleted($order, $sessionId ?: '');

            Hook::exec('actionEmporiqaFormatOrder', [
                'data' => &$eventData,
                'order' => $order,
            ]);

            $client = $this->getWebhookClient();
            $client->dispatchEvent('order.completed', $eventData);

            Db::getInstance()->insert('emporiqa_order_tracked', [
                'id_order' => (int) $order->id,
                'date_add' => date('Y-m-d H:i:s'),
            ], false, true, Db::ON_DUPLICATE_KEY);
        } catch (\Exception $e) {
            PrestaShopLogger::addLog(
                '[Emporiqa] Order webhook failed for #' . $order->id . ': ' . $e->getMessage(),
                3,
                null,
                'Emporiqa'
            );
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

        try {
            $this->sendOrderCompletedWebhook($orderId);

            Db::getInstance()->insert('emporiqa_order_tracked', [
                'id_order' => (int) $orderId,
                'date_add' => date('Y-m-d H:i:s'),
            ], false, true, Db::ON_DUPLICATE_KEY);
        } catch (\Exception $e) {
            PrestaShopLogger::addLog(
                '[Emporiqa] Order status webhook failed for #' . $orderId . ': ' . $e->getMessage(),
                3,
                null,
                'Emporiqa'
            );
        }
    }

    private function sendOrderCompletedWebhook($orderId)
    {
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        $sql = new DbQuery();
        $sql->select('emporiqa_sid');
        $sql->from('emporiqa_order_session');
        $sql->where('id_order = ' . (int) $orderId);
        $sessionId = Db::getInstance()->getValue($sql);

        $orderFormatter = new EmporiqaOrderFormatter();
        $eventData = $orderFormatter->formatOrderCompleted($order, $sessionId ?: '');

        Hook::exec('actionEmporiqaFormatOrder', [
            'data' => &$eventData,
            'order' => $order,
        ]);

        $client = $this->getWebhookClient();
        $client->dispatchEvent('order.completed', $eventData);
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
     *      webhook (natural dedup via the keyed pendingProductSyncs array)
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
        $this->pendingProductSyncs[$productId] = $eventType;
        $this->registerShutdownFlush();
    }

    private function queueProductDelete($productId)
    {
        if (!$this->isWebhookConfigured()) {
            return;
        }
        $this->pendingProductDeletes[(int) $productId] = true;
        $this->registerShutdownFlush();
    }

    private function registerShutdownFlush()
    {
        if ($this->shutdownFlushRegistered) {
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
        if (
            empty($this->pendingProductSyncs)
            && empty($this->pendingProductDeletes)
            && empty($this->pendingPageSyncs)
            && empty($this->pendingPageDeletes)
        ) {
            return;
        }

        // Snapshot + clear so re-entrant hook fires triggered during
        // dispatch (e.g. by actionEmporiqaFormatProduct subscribers)
        // queue into a fresh batch instead of mutating the one we're
        // iterating.
        $productSyncs = $this->pendingProductSyncs;
        $productDeletes = $this->pendingProductDeletes;
        $pageSyncs = $this->pendingPageSyncs;
        $pageDeletes = $this->pendingPageDeletes;
        $this->pendingProductSyncs = [];
        $this->pendingProductDeletes = [];
        $this->pendingPageSyncs = [];
        $this->pendingPageDeletes = [];

        foreach ($productSyncs as $productId => $eventType) {
            // Delete takes precedence over update when both were queued
            // (e.g. soft-delete sequence: update then deactivate).
            if (isset($productDeletes[$productId])) {
                continue;
            }
            $this->dispatchProductSync((int) $productId, (string) $eventType);
        }
        foreach (array_keys($productDeletes) as $productId) {
            $this->dispatchProductDelete((int) $productId);
        }

        foreach ($pageSyncs as $cmsId => $eventType) {
            if (isset($pageDeletes[$cmsId])) {
                continue;
            }
            $this->dispatchPageSync((int) $cmsId, (string) $eventType);
        }
        foreach (array_keys($pageDeletes) as $cmsId) {
            $this->dispatchPageDelete((int) $cmsId);
        }
    }

    private function dispatchProductSync($productId, $eventType)
    {
        try {
            // Fresh DB load -- the hook params' Product object may carry
            // a pre-commit snapshot from PS9's multi-step CQRS save.
            $product = new Product($productId);
            if (!Validate::isLoadedObject($product)) {
                return;
            }
            if (!$product->active) {
                $this->dispatchProductDelete($productId);
                return;
            }

            $shouldSync = true;
            $this->dispatchSyncHook('actionEmporiqaShouldSyncProduct', [
                'product' => $product,
                'event_type' => $eventType,
            ], $shouldSync);
            if (!$shouldSync) {
                return;
            }

            $client = $this->getWebhookClient();
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

            // One request carries the parent + every variation.
            $events = [];
            foreach ($formatted as $item) {
                $events[] = ['type' => $eventType, 'data' => $item];
            }
            $client->dispatchEvents($events);
        } catch (\Exception $e) {
            PrestaShopLogger::addLog('Emporiqa: ' . $e->getMessage(), 2);
        }
    }

    private function dispatchProductDelete($productId)
    {
        try {
            $client = $this->getWebhookClient();

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

            $client->dispatchEvents($events);
        } catch (\Exception $e) {
            PrestaShopLogger::addLog('Emporiqa: ' . $e->getMessage(), 2);
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
        } catch (\Exception $e) {
            PrestaShopLogger::addLog('Emporiqa: ' . $e->getMessage(), 2);
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
        } catch (\Exception $e) {
            PrestaShopLogger::addLog('Emporiqa: ' . $e->getMessage(), 2);
        }
    }

    private function getConfigureUrl()
    {
        if (isset($_SERVER['REQUEST_URI'])) {
            $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
            $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';

            return $scheme . '://' . $host . $_SERVER['REQUEST_URI'];
        }

        return $this->context->link->getAdminLink('AdminModules', true) . '&configure=emporiqa';
    }

    private function getPlatformBaseUrl()
    {
        $webhookUrl = Configuration::get('EMPORIQA_WEBHOOK_URL') ?: self::DEFAULT_WEBHOOK_URL;
        $parsed = parse_url($webhookUrl);
        $host = isset($parsed['host']) ? $parsed['host'] : 'emporiqa.com';
        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] : 'https';

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
            $sql->limit(1);

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
        } catch (\Exception $e) {
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
            $sql->limit(1);

            $row = Db::getInstance()->getRow($sql);
            if (!$row) {
                return null;
            }

            $cms = new CMS((int) $row['id_cms']);
            if (!Validate::isLoadedObject($cms)) {
                return null;
            }

            return $this->getPageFormatter()->format($cms);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Read and sanitize the emporiqa_sid cookie value. The strict regex
     * already restricts the result to `[A-Za-z0-9_-]{1,128}`, but the
     * value passes through pSQL() too so static-analysis tools recognise
     * it as sanitized for downstream SQL / hook dispatch.
     */
    private function getEmporiqaSessionId()
    {
        // emporiqa_sid is a custom cookie set by the widget JS.
        // PS cookie manager only handles its own cookies, so $_COOKIE is used here.
        $raw = isset($_COOKIE['emporiqa_sid']) ? (string) $_COOKIE['emporiqa_sid'] : '';
        if (empty($raw) || !preg_match('/^[a-zA-Z0-9_\-]{1,128}$/', $raw)) {
            return '';
        }

        return pSQL($raw);
    }

    private function isWebhookConfigured()
    {
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

    // ensureShutdownFlush() removed in 1.2.0 — every hook now dispatches
    // immediately via EmporiqaWebhookClient::dispatchEvent (synchronous
    // send with a 1.5 s total / 500 ms handshake ceiling). The merchant's
    // admin save / checkout request never waits longer than that.
}
