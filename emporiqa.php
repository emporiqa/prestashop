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
require_once dirname(__FILE__) . '/classes/EmporiqaWebhookClient.php';
require_once dirname(__FILE__) . '/classes/EmporiqaProductFormatter.php';
require_once dirname(__FILE__) . '/classes/EmporiqaPageFormatter.php';
require_once dirname(__FILE__) . '/classes/EmporiqaOrderFormatter.php';
require_once dirname(__FILE__) . '/classes/EmporiqaCartHandler.php';
require_once dirname(__FILE__) . '/classes/EmporiqaSyncService.php';

class Emporiqa extends Module
{
    const DEFAULT_WEBHOOK_URL = 'https://emporiqa.com/webhooks/sync/';

    /** @var EmporiqaWebhookClient|null */
    private $webhookClient;

    /** @var EmporiqaProductFormatter|null */
    private $productFormatter;

    /** @var EmporiqaPageFormatter|null */
    private $pageFormatter;

    /** @var EmporiqaSyncService|null */
    private $syncService;

    /** @var array Product IDs already queued this request (dedup) */
    private $queuedProductIds = [];

    /** @var array Page IDs already queued this request (dedup) */
    private $queuedPageIds = [];

    /** @var bool Whether the shutdown flush has been registered */
    private $shutdownRegistered = false;

    public function __construct()
    {
        $this->name = 'emporiqa';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'Emporiqa';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '8.99.99'];
        $this->bootstrap = true;

        parent::__construct();

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
            $this->webhookClient = new EmporiqaWebhookClient();
        }

        return $this->webhookClient;
    }

    /**
     * @return EmporiqaProductFormatter
     */
    public function getProductFormatter()
    {
        if (!$this->productFormatter) {
            $this->productFormatter = new EmporiqaProductFormatter();
        }

        return $this->productFormatter;
    }

    /**
     * @return EmporiqaPageFormatter
     */
    public function getPageFormatter()
    {
        if (!$this->pageFormatter) {
            $this->pageFormatter = new EmporiqaPageFormatter();
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
        ];
    }

    public function install()
    {
        return parent::install()
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
            && $this->installConfig()
            && $this->installDb();
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
        Configuration::updateGlobalValue('EMPORIQA_WIDGET_CHANNEL', '');
        Configuration::updateGlobalValue('EMPORIQA_BATCH_SIZE', 25);

        return true;
    }

    private function uninstallConfig()
    {
        $keys = [
            'EMPORIQA_STORE_ID', 'EMPORIQA_WEBHOOK_URL', 'EMPORIQA_WEBHOOK_SECRET',
            'EMPORIQA_SYNC_PRODUCTS', 'EMPORIQA_SYNC_PAGES', 'EMPORIQA_ENABLED_LANGUAGES',
            'EMPORIQA_ORDER_TRACKING', 'EMPORIQA_ORDER_TRACKING_EMAIL', 'EMPORIQA_CART_ENABLED',
            'EMPORIQA_WIDGET_CHANNEL', 'EMPORIQA_BATCH_SIZE',
        ];
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
        $orderTracking = (int) Tools::getValue('EMPORIQA_ORDER_TRACKING');
        $orderTrackingEmail = (int) Tools::getValue('EMPORIQA_ORDER_TRACKING_EMAIL');
        $cartEnabled = (int) Tools::getValue('EMPORIQA_CART_ENABLED');
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
        Configuration::updateGlobalValue('EMPORIQA_ORDER_TRACKING', $orderTracking);
        Configuration::updateGlobalValue('EMPORIQA_ORDER_TRACKING_EMAIL', $orderTrackingEmail);
        Configuration::updateGlobalValue('EMPORIQA_CART_ENABLED', $cartEnabled);
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

        $this->context->smarty->assign([
            'emporiqa_module_dir' => $this->_path,
            'emporiqa_store_id' => Configuration::get('EMPORIQA_STORE_ID'),
            'emporiqa_webhook_url' => Configuration::get('EMPORIQA_WEBHOOK_URL'),
            'emporiqa_webhook_secret_set' => !empty(Configuration::get('EMPORIQA_WEBHOOK_SECRET')),
            'emporiqa_sync_products' => Configuration::get('EMPORIQA_SYNC_PRODUCTS'),
            'emporiqa_sync_pages' => Configuration::get('EMPORIQA_SYNC_PAGES'),
            'emporiqa_enabled_languages' => $enabledLanguages,
            'emporiqa_languages' => $languages,
            'emporiqa_token' => Tools::getAdminTokenLite('AdminModules'),
            'emporiqa_sync_ajax_url' => $this->context->link->getAdminLink('AdminModules', true) . '&configure=emporiqa',
            'emporiqa_product_count' => $this->getSyncService()->countProducts(),
            'emporiqa_page_count' => $this->getSyncService()->countPages(),
            'emporiqa_platform_base_url' => $this->getPlatformBaseUrl(),
            'emporiqa_order_tracking' => Configuration::get('EMPORIQA_ORDER_TRACKING'),
            'emporiqa_order_tracking_email' => Configuration::get('EMPORIQA_ORDER_TRACKING_EMAIL'),
            'emporiqa_order_tracking_url' => $this->context->link->getModuleLink('emporiqa', 'ordertracking'),
            'emporiqa_cart_enabled' => Configuration::get('EMPORIQA_CART_ENABLED'),
            'emporiqa_batch_size' => (int) Configuration::get('EMPORIQA_BATCH_SIZE') ?: 25,
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
        if (empty($token) || $token !== Tools::getAdminTokenLite('AdminModules')) {
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

        $queryParams['channel'] = (string) Configuration::get('EMPORIQA_WIDGET_CHANNEL');

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

        if (isset($this->queuedProductIds[$productId])) {
            return;
        }
        $this->queuedProductIds[$productId] = true;

        $product = (isset($params['product']) && $params['product'] instanceof Product && (int) $params['product']->id === $productId)
            ? $params['product']
            : new Product($productId);
        if (!Validate::isLoadedObject($product)) {
            return;
        }

        if (!$product->active) {
            $this->queueProductDelete($productId);
            return;
        }

        if (!$product->isAssociatedToShop()) {
            return;
        }

        $eventType = 'product.updated';

        $shouldSync = true;
        $this->dispatchSyncHook('actionEmporiqaShouldSyncProduct', [
            'product' => $product,
            'event_type' => &$eventType,
        ], $shouldSync);
        if (!$shouldSync) {
            return;
        }

        $this->queueProductEvent($product, $eventType);
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
        if (!$productId || isset($this->queuedProductIds[$productId])) {
            return;
        }
        $this->queuedProductIds[$productId] = true;

        $product = new Product($productId);
        if (!Validate::isLoadedObject($product) || !$product->active) {
            return;
        }

        if (!$product->isAssociatedToShop()) {
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
        if (Validate::isLoadedObject($product) && $product->active && $product->isAssociatedToShop()) {
            if (!isset($this->queuedProductIds[$productId])) {
                $this->queuedProductIds[$productId] = true;
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
            $client->queueEvent('product.deleted', [
                'identification_number' => 'variation-' . (int) $object->id,
            ]);
            $this->ensureShutdownFlush();
        }

        // Re-sync the parent product
        $productId = (int) $object->id_product;
        $product = new Product($productId);
        if (Validate::isLoadedObject($product) && $product->active && $product->isAssociatedToShop()) {
            if (!isset($this->queuedProductIds[$productId])) {
                $this->queuedProductIds[$productId] = true;
                $this->queueProductEvent($product, 'product.updated');
            }
        }
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

        $this->queuePageDelete((int) $object->id);
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

        $cmsId = (int) $object->id;
        if (isset($this->queuedPageIds[$cmsId])) {
            return;
        }
        $this->queuedPageIds[$cmsId] = true;

        $cms = new CMS($cmsId);
        if (!Validate::isLoadedObject($cms)) {
            return;
        }

        if (!$cms->active) {
            $this->queuePageDelete($cmsId);
            return;
        }

        if (!$cms->isAssociatedToShop()) {
            return;
        }

        $shouldSync = true;
        $this->dispatchSyncHook('actionEmporiqaShouldSyncPage', [
            'page' => $cms,
            'event_type' => &$eventType,
        ], $shouldSync);
        if (!$shouldSync) {
            return;
        }

        $this->queuePageEvent($cms, $eventType);
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
            $client->queueEvent('order.completed', $eventData);
            $this->ensureShutdownFlush();

            Db::getInstance()->insert('emporiqa_order_tracked', [
                'id_order' => (int) $order->id,
                'date_add' => date('Y-m-d H:i:s'),
            ], false, true, Db::ON_DUPLICATE_KEY);
        } catch (\Exception $e) {
            PrestaShopLogger::addLog(
                '[Emporiqa] Order webhook failed for #' . $order->id . ': ' . $e->getMessage(),
                2,
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
                'id_order' => $orderId,
                'date_add' => date('Y-m-d H:i:s'),
            ], false, true, Db::ON_DUPLICATE_KEY);
        } catch (\Exception $e) {
            PrestaShopLogger::addLog(
                '[Emporiqa] Order status webhook failed for #' . $orderId . ': ' . $e->getMessage(),
                2,
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
        $client->queueEvent('order.completed', $eventData);
        $this->ensureShutdownFlush();
    }

    // -------------------------------------------------------------------------
    // Event Queuing (deferred flush)
    // -------------------------------------------------------------------------

    private function queueProductEvent(Product $product, $eventType)
    {
        if (!$this->isWebhookConfigured()) {
            return;
        }

        try {
            $client = $this->getWebhookClient();
            $formatted = $this->getProductFormatter()->format($product);

            // Allow other modules to modify each product/variation payload
            foreach ($formatted as &$item) {
                Hook::exec('actionEmporiqaFormatProduct', [
                    'data' => &$item,
                    'product' => $product,
                    'event_type' => $eventType,
                ]);
            }
            unset($item);

            foreach ($formatted as $item) {
                $client->queueEvent($eventType, $item);
            }
            $this->ensureShutdownFlush();
        } catch (\Exception $e) {
            PrestaShopLogger::addLog('Emporiqa: ' . $e->getMessage(), 3);
        }
    }

    private function queueProductDelete($productId)
    {
        if (!$this->isWebhookConfigured()) {
            return;
        }
        $client = $this->getWebhookClient();

        $client->queueEvent('product.deleted', [
            'identification_number' => 'product-' . $productId,
        ]);

        $combinations = Product::getProductAttributesIds($productId);
        if ($combinations) {
            foreach ($combinations as $combo) {
                $client->queueEvent('product.deleted', [
                    'identification_number' => 'variation-' . $combo['id_product_attribute'],
                ]);
            }
        }

        $this->ensureShutdownFlush();
    }

    private function queuePageEvent(CMS $cms, $eventType)
    {
        if (!$this->isWebhookConfigured()) {
            return;
        }
        $client = $this->getWebhookClient();
        $formatted = $this->getPageFormatter()->format($cms);
        if (!empty($formatted)) {
            Hook::exec('actionEmporiqaFormatPage', [
                'data' => &$formatted,
                'page' => $cms,
                'event_type' => $eventType,
            ]);
            $client->queueEvent($eventType, $formatted);
        }
        $this->ensureShutdownFlush();
    }

    private function queuePageDelete($cmsId)
    {
        if (!$this->isWebhookConfigured()) {
            return;
        }
        $client = $this->getWebhookClient();
        $client->queueEvent('page.deleted', [
            'identification_number' => 'page-' . $cmsId,
        ]);
        $this->ensureShutdownFlush();
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
            $sql->innerJoin('product_shop', 'ps', 'p.id_product = ps.id_product AND ps.id_shop = ' . (int) Context::getContext()->shop->id);
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
            $sql->innerJoin('cms_shop', 'cs', 'c.id_cms = cs.id_cms AND cs.id_shop = ' . (int) Context::getContext()->shop->id);
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
     * Read and sanitize the emporiqa_sid cookie value.
     */
    private function getEmporiqaSessionId()
    {
        $raw = isset($_COOKIE['emporiqa_sid']) ? (string) $_COOKIE['emporiqa_sid'] : '';
        if (empty($raw) || !preg_match('/^[a-zA-Z0-9_\-]{1,128}$/', $raw)) {
            return '';
        }

        return $raw;
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

    private function ensureShutdownFlush()
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $client = $this->getWebhookClient();
        register_shutdown_function(function () use ($client) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            $client->flushPendingEvents();
        });
        $this->shutdownRegistered = true;
    }
}
