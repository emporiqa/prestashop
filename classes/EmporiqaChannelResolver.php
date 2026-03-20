<?php
/**
 * Emporiqa Channel Resolver
 *
 * Auto-discovers PrestaShop shops and maps them to Emporiqa channel keys.
 * Every shop gets a slugified name as its channel key (e.g. "My Shop" → "my-shop").
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaChannelResolver
{
    private static $mapping;
    private static $shopContexts;

    /** @var Context */
    private $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    /**
     * Get the channel key for a given shop ID.
     */
    public function resolveChannelKey($shopId)
    {
        $mapping = $this->getMapping();

        if (isset($mapping[$shopId])) {
            return $mapping[$shopId];
        }

        $shop = new Shop($shopId);

        return self::slugify($shop->name ?: (string) $shopId);
    }

    /**
     * Get the channel key for the current shop context.
     */
    public function getCurrentChannelKey()
    {
        return $this->resolveChannelKey((int) $this->context->shop->id);
    }

    /**
     * Get shop ID → channel key mapping.
     * Every shop gets a slugified version of its name as the channel key.
     *
     * @return array<int, string>
     */
    public function getMapping()
    {
        if (self::$mapping !== null) {
            return self::$mapping;
        }

        $shops = Shop::getShops(true, null, true);

        $shopIds = array_map('intval', array_keys($shops));
        sort($shopIds);

        $mapping = [];
        foreach ($shopIds as $id) {
            $shop = new Shop($id);
            $mapping[$id] = self::slugify($shop->name ?: (string) $id);
        }

        self::$mapping = $mapping;

        return self::$mapping;
    }

    /**
     * Get per-shop context data for all active shops.
     * Returns channel key → context array with domain, languages, currencies.
     *
     * @return array
     */
    public function getShopContexts()
    {
        if (self::$shopContexts !== null) {
            return self::$shopContexts;
        }

        $enabledLanguages = EmporiqaLanguageHelper::getEnabledLanguages();
        $contexts = [];

        $mapping = $this->getMapping();

        if (empty($mapping)) {
            $shopId = (int) $this->context->shop->id;
            $shop = new Shop($shopId);
            $channelKey = self::slugify($shop->name ?: (string) $shopId);
            $contexts[$channelKey] = $this->buildShopContext($shopId, $channelKey, $enabledLanguages);
            self::$shopContexts = $contexts;

            return self::$shopContexts;
        }

        foreach ($mapping as $shopId => $channelKey) {
            $contexts[$channelKey] = $this->buildShopContext($shopId, $channelKey, $enabledLanguages);
        }

        self::$shopContexts = $contexts;

        return self::$shopContexts;
    }

    /**
     * Get all shop IDs a product is assigned to.
     *
     * @param int $productId
     *
     * @return array<int>
     */
    public function getProductShopIds($productId)
    {
        $sql = new DbQuery();
        $sql->select('ps.id_shop');
        $sql->from('product_shop', 'ps');
        $sql->where('ps.id_product = ' . (int) $productId);
        $sql->where('ps.active = 1');

        $rows = Db::getInstance()->executeS($sql);
        if (!$rows) {
            return [];
        }

        return array_map(function ($row) {
            return (int) $row['id_shop'];
        }, $rows);
    }

    /**
     * Get all shop IDs a CMS page is assigned to.
     *
     * @param int $cmsId
     *
     * @return array<int>
     */
    public function getPageShopIds($cmsId)
    {
        $sql = new DbQuery();
        $sql->select('cs.id_shop');
        $sql->from('cms_shop', 'cs');
        $sql->where('cs.id_cms = ' . (int) $cmsId);

        $rows = Db::getInstance()->executeS($sql);
        if (!$rows) {
            return [];
        }

        return array_map(function ($row) {
            return (int) $row['id_shop'];
        }, $rows);
    }

    /**
     * Get the channel keys a product is assigned to.
     *
     * @param int $productId
     *
     * @return array<string> Channel keys
     */
    public function getProductChannels($productId)
    {
        $shopIds = $this->getProductShopIds($productId);
        if (empty($shopIds)) {
            return [];
        }

        $channels = [];
        foreach ($shopIds as $shopId) {
            $channels[] = $this->resolveChannelKey($shopId);
        }

        return array_unique($channels);
    }

    /**
     * Get the channel keys a CMS page is assigned to.
     *
     * @param int $cmsId
     *
     * @return array<string> Channel keys
     */
    public function getPageChannels($cmsId)
    {
        $shopIds = $this->getPageShopIds($cmsId);
        if (empty($shopIds)) {
            return [];
        }

        $channels = [];
        foreach ($shopIds as $shopId) {
            $channels[] = $this->resolveChannelKey($shopId);
        }

        return array_unique($channels);
    }

    public static function reset()
    {
        self::$mapping = null;
        self::$shopContexts = null;
    }

    private function buildShopContext($shopId, $channelKey, array $globalEnabledLanguages)
    {
        $shop = new Shop($shopId);
        $ssl = (bool) Configuration::get('PS_SSL_ENABLED');
        $domain = rtrim($shop->getBaseURL($ssl), '/');

        $shopLanguages = Language::getLanguages(true, $shopId);
        $langMap = [];
        $enabledForShop = [];
        foreach ($shopLanguages as $lang) {
            $code = EmporiqaLanguageHelper::getLangCode($lang);
            if (in_array($code, $globalEnabledLanguages, true)) {
                $langMap[$code] = (int) $lang['id_lang'];
                $enabledForShop[] = $code;
            }
        }

        $currencies = Currency::getCurrenciesByIdShop($shopId);

        return [
            'shop_id' => (int) $shopId,
            'channel_key' => $channelKey,
            'domain' => $domain,
            'languages' => $langMap,
            'enabled_languages' => $enabledForShop,
            'currencies' => $currencies,
        ];
    }

    private static function slugify($name)
    {
        $slug = strtolower(Tools::replaceAccentedChars($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }
}
