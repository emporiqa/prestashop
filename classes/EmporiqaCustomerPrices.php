<?php
/**
 * The `customer_prices` action: what one signed-in customer pays.
 *
 * Read-only. Prices come from PrestaShop's own engine, computed as that
 * customer (their groups, their own specific prices, group reductions,
 * catalog price rules, volume discounts), through the same code as the
 * synced public prices, so the two can be compared field by field. The
 * customer's cart and session are never touched. The answer carries
 * prices only: no name, email or group. A product the customer cannot see
 * or buy in that shop is left out, never reported as hidden.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaCustomerPrices
{
    public const MAX_PRODUCTS = 20;

    /** Combinations priced under the products asked for, per call; more are left out. */
    public const MAX_VARIATIONS = 100;

    public const RATE_PER_CUSTOMER = 30;

    public const RATE_PER_SHOP = 600;

    /** @var Context */
    private $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    /**
     * Count this call against its customer and the shop (10-minute window,
     * fails closed, as for order_status).
     *
     * @param array $payload decoded, signature-verified request body
     * @param int $shopId
     *
     * @return array{scope: string, retry_after: int}|null
     */
    public static function rateLimitHit(array $payload, $shopId)
    {
        $shopBucket = 'prices_shop:' . (int) $shopId;
        $buckets = [$shopBucket => self::RATE_PER_SHOP];
        $customerId = self::customerId($payload);
        if ($customerId > 0) {
            $buckets['prices_customer:' . (int) $shopId . ':' . $customerId] = self::RATE_PER_CUSTOMER;
        }

        return EmporiqaOrderStatus::countHits($buckets, $shopBucket);
    }

    /**
     * @param array $payload decoded, signature-verified request body
     *
     * @return array response envelope
     */
    public function handle(array $payload)
    {
        $customerId = self::customerId($payload);
        if ($customerId <= 0) {
            return ['status' => 'rejected', 'message_code' => 'missing_field'];
        }
        $ids = isset($payload['products']) ? $payload['products'] : null;
        if (!is_array($ids) || empty($ids) || count($ids) > self::MAX_PRODUCTS) {
            return ['status' => 'rejected', 'message_code' => 'invalid_field'];
        }

        $resolver = new EmporiqaChannelResolver($this->context);
        $shop = $this->shopContext($resolver, self::text($payload, 'channel'));
        if ($shop === null) {
            return ['status' => 'rejected', 'message_code' => 'invalid_field'];
        }
        $shopId = (int) $shop['shop_id'];

        $customer = new Customer($customerId);
        if (!Validate::isLoadedObject($customer) || !$customer->active || $customer->deleted || $customer->is_guest
            || !in_array($shopId, array_map('intval', Shop::getSharedShops((int) $customer->id_shop, Shop::SHARE_CUSTOMER)), true)
        ) {
            return ['status' => 'not_found'];
        }

        $currency = $this->currency($shop, self::text($payload, 'currency'), $shopId);
        if ($currency === null) {
            return ['status' => 'not_found'];
        }
        $country = $this->country(self::text($payload, 'country'), $customerId, $shopId);
        $idAddress = (int) Db::getInstance()->getValue(
            'SELECT `id_address` FROM `' . _DB_PREFIX_ . 'address` WHERE `id_customer` = ' . (int) $customerId
            . ' AND `id_country` = ' . (int) $country->id . ' AND `deleted` = 0 AND `active` = 1 ORDER BY `id_address`',
            false,
        );

        $formatter = new EmporiqaProductFormatter($resolver, $this->context);
        $data = $formatter->withPriceContext($customer, $country, $idAddress, function () use ($formatter, $ids, $currency, $shopId) {
            // PrestaShop shows a customer every price in their group's mode.
            $includeTax = !Tax::excludeTaxeOption()
                && Group::getPriceDisplayMethod((int) Group::getCurrent()->id) != PS_TAX_EXC;

            return [
                'currency' => (string) $currency['iso_code'],
                'prices_include_tax' => $includeTax,
                'products' => $this->prices($formatter, $ids, $currency, $shopId, $includeTax),
            ];
        });
        // True only when every entry includes tax.
        foreach ($data['products'] as $entry) {
            $data['prices_include_tax'] = $data['prices_include_tax'] && $entry['prices_include_tax'];
        }
        if (empty($data['products'])) {
            $data['products'] = new stdClass();
        }

        return ['status' => 'found', 'data' => $data];
    }

    /**
     * Requested id => prices, for the ids this customer can see and buy.
     * Product-level prices only: cart rules and vouchers are not in them.
     * Runs inside withPriceContext.
     */
    private function prices(EmporiqaProductFormatter $formatter, array $ids, array $currency, $shopId, $includeTax)
    {
        if (!Configuration::showPrices()
            || (Configuration::get('PS_CATALOG_MODE') && !Configuration::get('PS_CATALOG_MODE_WITH_PRICES'))
        ) {
            return [];
        }

        $variationBudget = self::MAX_VARIATIONS;
        $result = [];
        foreach ($ids as $id) {
            if (!is_scalar($id) || isset($result[(string) $id])) {
                continue;
            }
            $id = (string) $id;
            [$productId, $paId] = $this->parseId($id);
            $product = $productId > 0 ? $this->visibleProduct($productId, $shopId) : null;
            if ($product === null) {
                continue;
            }

            if ($paId > 0) {
                $entry = $this->entry($formatter, $productId, $paId, $currency, $shopId, false, $includeTax);
            } else {
                $paIds = $this->combinationIds($productId, $shopId);
                $default = in_array((int) $product->cache_default_attribute, $paIds, true)
                    ? (int) $product->cache_default_attribute
                    : (int) reset($paIds);
                $entry = $this->entry($formatter, $productId, $paIds ? $default : null, $currency, $shopId, true, $includeTax);
                if ($entry !== null && $paIds) {
                    $variations = [];
                    foreach (array_slice($paIds, 0, max(0, $variationBudget)) as $variationId) {
                        $variation = $this->entry($formatter, $productId, $variationId, $currency, $shopId, false, $includeTax);
                        if ($variation !== null) {
                            $variations['variation-' . $variationId] = $variation;
                        }
                    }
                    $variationBudget -= count($paIds);
                    if ($variations) {
                        $entry['variations'] = $variations;
                    }
                }
            }
            if ($entry !== null) {
                $result[$id] = $entry;
            }
        }

        return $result;
    }

    /**
     * One product's or combination's prices in the response's fields, null
     * when it has no price in this shop.
     */
    private function entry(EmporiqaProductFormatter $formatter, $productId, $paId, array $currency, $shopId, $isParent, $includeTax)
    {
        $entries = $formatter->priceEntriesInContext($productId, $paId, [$currency], $shopId, $isParent);
        if (empty($entries)) {
            return null;
        }
        $entry = $entries[0];
        $current = (float) $entry['current_price'];

        return [
            'current_price' => $current,
            'regular_price' => (float) $entry['regular_price'],
            'price_incl_tax' => (float) ($entry['price_incl_tax'] ?? $current),
            'price_excl_tax' => (float) ($entry['price_excl_tax'] ?? $current),
            'tier_prices' => $entry['tier_prices'] ?? [],
            'prices_include_tax' => (bool) $includeTax,
        ];
    }

    /**
     * The product, loaded for this shop, when this customer can see and buy
     * it there: active in the shop, not hidden from the catalog and search,
     * in a category their groups may see, with its price shown.
     *
     * @return Product|null
     */
    private function visibleProduct($productId, $shopId)
    {
        $product = new Product((int) $productId, false, null, (int) $shopId);
        if (!Validate::isLoadedObject($product) || !$product->isAssociatedToShop((int) $shopId)
            || !$product->active || $product->visibility === 'none'
            || (!$product->show_price && !$product->available_for_order)
            || !Product::checkAccessStatic((int) $productId, (int) $this->context->customer->id)
        ) {
            return null;
        }

        return $product;
    }

    /**
     * The product's combinations available in this shop, in id order.
     *
     * @return int[]
     */
    private function combinationIds($productId, $shopId)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT pa.`id_product_attribute` FROM `' . _DB_PREFIX_ . 'product_attribute` pa'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_shop` pas'
            . ' ON pas.`id_product_attribute` = pa.`id_product_attribute` AND pas.`id_shop` = ' . (int) $shopId
            . ' WHERE pa.`id_product` = ' . (int) $productId . ' ORDER BY pa.`id_product_attribute`',
        );

        return array_map('intval', array_column($rows ?: [], 'id_product_attribute'));
    }

    /**
     * The synced identification_number (product-123, variation-456) or a bare
     * product id, as [product id, combination id]; [0, 0] when it is none.
     *
     * @return int[]
     */
    private function parseId($id)
    {
        if (preg_match('/^(?:product-)?([1-9][0-9]{0,9})$/D', $id, $m)) {
            return [(int) $m[1], 0];
        }
        if (preg_match('/^variation-([1-9][0-9]{0,9})$/D', $id, $m)) {
            $productId = (int) Db::getInstance()->getValue(
                'SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product_attribute` = ' . (int) $m[1],
                false,
            );

            return [$productId, $productId > 0 ? (int) $m[1] : 0];
        }

        return [0, 0];
    }

    /**
     * The synced shop for this channel key; the shop the request came in on
     * when none is given. null for a channel the module does not sync.
     */
    private function shopContext(EmporiqaChannelResolver $resolver, $channel)
    {
        $contexts = $resolver->getShopContexts();
        if ($channel === '') {
            $channel = $resolver->getCurrentChannelKey();
        }

        return isset($contexts[$channel]) ? $contexts[$channel] : null;
    }

    /**
     * The requested currency when the shop sells in it, else the shop's
     * default, else its first currency.
     *
     * @return array|null a currency row (id_currency, iso_code)
     */
    private function currency(array $shop, $iso, $shopId)
    {
        $defaultId = (int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, (int) $shopId);
        $fallback = null;
        foreach ($shop['currencies'] as $row) {
            if ($iso !== '' && strtoupper((string) $row['iso_code']) === strtoupper($iso)) {
                return $row;
            }
            if ($fallback === null || (int) $row['id_currency'] === $defaultId) {
                $fallback = $row;
            }
        }

        return $fallback;
    }

    /**
     * The requested country when it is active, else the country of the
     * customer's first address (the one their cart is taxed with), else the
     * shop's default country.
     *
     * @return Country
     */
    private function country($iso, $customerId, $shopId)
    {
        $id = 0;
        if (preg_match('/^[A-Za-z]{2}$/D', $iso)) {
            $id = (int) Country::getByIso(strtoupper($iso), true);
        }
        if ($id <= 0) {
            $idAddress = (int) Address::getFirstCustomerAddressId((int) $customerId);
            $id = $idAddress > 0 ? (int) (new Address($idAddress))->id_country : 0;
        }
        if ($id <= 0) {
            $id = (int) Configuration::get('PS_COUNTRY_DEFAULT', null, null, (int) $shopId);
        }

        return new Country($id);
    }

    private static function customerId(array $payload)
    {
        $id = isset($payload['customer']['id']) ? $payload['customer']['id'] : null;

        return is_scalar($id) && ctype_digit((string) $id) ? (int) $id : 0;
    }

    private static function text(array $payload, $name)
    {
        return isset($payload[$name]) && is_string($payload[$name]) ? trim($payload[$name]) : '';
    }
}
