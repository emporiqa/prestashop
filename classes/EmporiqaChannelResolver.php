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
    /** JSON list of shop ids to sync and show the widget on; empty means every active shop. Ignored outside multistore. */
    public const ENABLED_SHOPS_KEY = 'EMPORIQA_ENABLED_SHOPS';

    private static $mapping;
    private static $allMapping;
    private static $shopNames;
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
        // The enabled shops first: on a single-shop install that answers
        // without reading the shop list.
        $mapping = $this->getMapping();
        if (isset($mapping[$shopId])) {
            return $mapping[$shopId];
        }

        $mapping = $this->getAllMapping();
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
     * Get shop ID → channel key mapping for the shops the merchant enabled.
     * Every shop gets a slugified version of its name as the channel key.
     *
     * @return array<int, string>
     */
    public function getMapping()
    {
        if (self::$mapping !== null) {
            return self::$mapping;
        }

        // Single shop: no shop list to read (this runs on every storefront
        // page view, for the widget).
        $shop = $this->context->shop;
        if (!Shop::isFeatureActive() && $shop && $shop->id) {
            $shopId = (int) $shop->id;
            self::$mapping = [$shopId => self::slugify($shop->name ?: (string) $shopId)];

            return self::$mapping;
        }

        $all = $this->getAllMapping();
        $stored = self::getEnabledShopIds();
        if (empty($stored) || !Shop::isFeatureActive()) {
            if (empty($all) && $shop && $shop->id) {
                $all = [(int) $shop->id => self::slugify($shop->name ?: (string) $shop->id)];
            }
            self::$mapping = $all;

            return self::$mapping;
        }

        // A selection whose shops were all deactivated or deleted since
        // syncs nothing, never every shop: widening it would push the
        // catalog of shops the merchant excluded. The settings page warns.
        self::$mapping = array_intersect_key($all, array_flip($stored));

        return self::$mapping;
    }

    /**
     * Whether a stored multistore selection matches no active shop, so
     * nothing is synced and the chat shows nowhere.
     *
     * @return bool
     */
    public function selectionMatchesNoShop()
    {
        return Shop::isFeatureActive() && !empty(self::getEnabledShopIds()) && empty($this->getMapping());
    }

    /**
     * Shop ID → channel key for every active shop with a storefront URL,
     * enabled or not.
     *
     * @return array<int, string>
     */
    public function getAllMapping()
    {
        if (self::$allMapping !== null) {
            return self::$allMapping;
        }

        // Shop::getShops is cached by PrestaShop for the request and carries
        // the names and main URLs, so no Shop object is loaded per shop. It
        // already leaves out deleted shops (and inactive ones with true).
        $shops = Shop::getShops(true);
        ksort($shops);

        $mapping = [];
        $names = [];
        foreach ($shops as $id => $row) {
            // A shop with no main URL has no storefront: nobody can chat there,
            // and every link PrestaShop builds for it lacks a host.
            if (empty($row['domain']) && empty($row['domain_ssl'])) {
                continue;
            }
            $name = isset($row['name']) ? (string) $row['name'] : '';
            $key = self::slugify($name !== '' ? $name : (string) $id);
            // Two shops whose names slugify alike would share one channel and
            // overwrite each other's data; the later one (higher id) gets its id.
            if (in_array($key, $mapping, true)) {
                $key .= '-' . (int) $id;
            }
            $mapping[(int) $id] = $key;
            $names[(int) $id] = $name;
        }

        self::$allMapping = $mapping;
        self::$shopNames = $names;

        return self::$allMapping;
    }

    /**
     * Shop ID => shop name for every active shop, from the same list as
     * getAllMapping().
     *
     * @return array<int, string>
     */
    public function getShopNames()
    {
        $this->getAllMapping();

        return self::$shopNames;
    }

    /**
     * @return array<int> shop ids stored in EMPORIQA_ENABLED_SHOPS (empty = all)
     */
    public static function getEnabledShopIds()
    {
        $ids = json_decode((string) Configuration::getGlobalValue(self::ENABLED_SHOPS_KEY), true);

        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    /**
     * @param int $shopId
     *
     * @return bool
     */
    public function isShopEnabled($shopId)
    {
        return isset($this->getMapping()[(int) $shopId]);
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

        foreach ($this->getMapping() as $shopId => $channelKey) {
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

        return $this->channelsOfShops($shopIds);
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

        return $this->channelsOfShops($shopIds);
    }

    /**
     * The channel keys of the given shops, limited to the synced ones: a
     * product or page row in an inactive, URL-less or excluded shop never
     * names a channel.
     *
     * @param array<int> $shopIds
     *
     * @return array<string>
     */
    private function channelsOfShops(array $shopIds)
    {
        $mapping = $this->getMapping();
        $channels = [];
        foreach ($shopIds as $shopId) {
            if (isset($mapping[(int) $shopId])) {
                $channels[] = $mapping[(int) $shopId];
            }
        }

        return array_values(array_unique($channels));
    }

    public static function reset()
    {
        self::$mapping = null;
        self::$allMapping = null;
        self::$shopNames = null;
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

        // getCurrenciesByIdShop also returns disabled and deleted currencies
        // (Currency::delete is a soft delete that keeps the currency_shop row),
        // which no shopper can pay in, and currencies this shop has no
        // exchange rate for, whose prices PrestaShop converts to 0.
        $defaultCurrency = (int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, $shopId);
        $currencies = array_values(array_filter(
            Currency::getCurrenciesByIdShop($shopId),
            function ($currency) use ($defaultCurrency) {
                return !empty($currency['active']) && empty($currency['deleted'])
                    && ((int) $currency['id_currency'] === $defaultCurrency || (float) $currency['conversion_rate'] > 0);
            },
        ));

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
