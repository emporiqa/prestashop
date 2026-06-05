<?php
/**
 * Emporiqa Product Formatter
 *
 * Formats PrestaShop product and combination data into the consolidated
 * payload structure expected by the Emporiqa webhook API.
 * One event per product contains ALL channels and ALL languages
 * in nested channel→language maps.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaProductFormatter
{
    private static $categoryLangCache = [];
    private static $manufacturerCache = [];
    private static $imageTypeCache = [];
    private static $currencyObjCache = [];

    /** @var EmporiqaChannelResolver */
    private $channelResolver;

    /** @var Context */
    private $context;

    public function __construct(EmporiqaChannelResolver $channelResolver, Context $context)
    {
        $this->context = $context;
        $this->channelResolver = $channelResolver;
    }

    public static function clearBatchCaches()
    {
        self::$categoryLangCache = [];
        self::$manufacturerCache = [];
        self::$currencyObjCache = [];
    }

    /**
     * Format a product (and its combinations) for the webhook payload.
     * Includes all assigned channels with per-channel data.
     *
     * @param Product $product The product
     * @param string|null $syncSessionId Optional sync session ID
     *
     * @return array Array of formatted payload items (parent + variations)
     */
    public function format(Product $product, $syncSessionId = null)
    {
        $productId = (int) $product->id;
        // Use the merchant's Reference field as SKU when set so that customers
        // who type the reference shown on the storefront page (e.g. "2332")
        // resolve to the right product. Fall back to the namespaced ID when
        // the merchant hasn't filled it in.
        $reference = trim((string) $product->reference);
        $parentSku = $reference !== '' ? $reference : 'product-' . $productId;

        $allContexts = $this->channelResolver->getShopContexts();
        $productChannels = $this->channelResolver->getProductChannels($productId);

        if (empty($productChannels)) {
            return [];
        }

        // Filter to only channels this product is assigned to
        $contexts = [];
        foreach ($allContexts as $channelKey => $ctx) {
            if (in_array($channelKey, $productChannels, true)) {
                $contexts[$channelKey] = $ctx;
            }
        }

        if (empty($contexts)) {
            return [];
        }

        // Shared fields (not channel/language-dependent)
        $brand = $this->getProductBrand($product);
        $defaultLangId = (int) Configuration::get('PS_LANG_DEFAULT');
        $parentMinQty = (int) $product->minimal_quantity;
        if ($parentMinQty < 1) {
            $parentMinQty = 1;
        }

        // Collect all unique languages across all channels, then fetch combinations once per language
        $allLangIds = [];
        foreach ($contexts as $ctx) {
            foreach ($ctx['languages'] as $iso => $langId) {
                $allLangIds[$iso] = $langId;
            }
        }

        // Ensure default language is included for grouping
        $defaultIso = null;
        foreach ($allLangIds as $iso => $langId) {
            if ($langId === $defaultLangId) {
                $defaultIso = $iso;
                break;
            }
        }
        if ($defaultIso === null) {
            $defaultIso = '__default';
            $allLangIds[$defaultIso] = $defaultLangId;
        }

        $combinationsByLang = [];
        $hasCombinations = false;
        foreach ($allLangIds as $iso => $langId) {
            $combos = $product->getAttributeCombinations($langId);
            if (!empty($combos)) {
                $hasCombinations = true;
            }
            $combinationsByLang[$iso] = $combos;
        }

        // Group combinations by product_attribute ID (prefer default language, fallback to any)
        $groupedCombinations = [];
        if ($hasCombinations) {
            $groupingSource = !empty($combinationsByLang[$defaultIso])
                ? $combinationsByLang[$defaultIso]
                : null;
            if ($groupingSource === null) {
                foreach ($combinationsByLang as $combos) {
                    if (!empty($combos)) {
                        $groupingSource = $combos;
                        break;
                    }
                }
            }
            if ($groupingSource) {
                foreach ($groupingSource as $combo) {
                    $paId = (int) $combo['id_product_attribute'];
                    if (!isset($groupedCombinations[$paId])) {
                        $groupedCombinations[$paId] = [];
                    }
                    $groupedCombinations[$paId][] = $combo;
                }
            }
        }

        $hasVariations = count($groupedCombinations) > 0;

        // Build per-channel data
        $channelKeys = [];
        $allNames = [];
        $allDescriptions = [];
        $allLinks = [];
        $allAttributes = [];
        $allCategories = [];
        $allBrands = [];
        $allPrices = [];
        $allAvailabilities = [];
        $allStocks = [];
        $allImages = [];
        $allVariationAttributes = [];

        foreach ($contexts as $channelKey => $ctx) {
            $channelKeys[] = $channelKey;
            $shopId = $ctx['shop_id'];

            // Reload Product scoped to this shop so translation arrays
            // (name/description/link_rewrite/features) populate correctly even
            // when the admin AJAX is running under a shop where this product
            // has no rows in ps_product_lang.
            $shopProduct = new Product($productId, false, null, $shopId);
            if (!Validate::isLoadedObject($shopProduct)) {
                $shopProduct = $product;
            }

            // Names, descriptions, links, attributes per language
            $names = [];
            $descriptions = [];
            $links = [];
            $attributes = [];
            $categories = [];

            $shopLink = new Link(null, null);

            foreach ($ctx['enabled_languages'] as $iso) {
                $langId = isset($ctx['languages'][$iso]) ? $ctx['languages'][$iso] : null;
                if (!$langId) {
                    continue;
                }

                $name = is_array($shopProduct->name) ? ($shopProduct->name[$langId] ?? reset($shopProduct->name)) : $shopProduct->name;
                $names[$iso] = $name ?: '';

                $desc = is_array($shopProduct->description) ? ($shopProduct->description[$langId] ?? reset($shopProduct->description)) : $shopProduct->description;
                $descriptions[$iso] = $desc ?: '';

                $links[$iso] = $shopLink->getProductLink($shopProduct, null, null, null, $langId, $shopId);

                $features = $shopProduct->getFrontFeatures($langId);
                $featureMap = [];
                if (!empty($features)) {
                    foreach ($features as $feature) {
                        $fName = $feature['name'] ?? '';
                        $fValue = $feature['value'] ?? '';
                        if ($fName && $fValue) {
                            $featureMap[$fName] = $fValue;
                        }
                    }
                }
                $attributes[$iso] = !empty($featureMap) ? $featureMap : new stdClass();

                $categories[$iso] = $this->getCategoryPaths($shopProduct, $langId, $shopId);
            }

            // Empty PHP arrays serialize as JSON []; the API expects {} (dict).
            $allNames[$channelKey] = !empty($names) ? $names : new stdClass();
            $allDescriptions[$channelKey] = !empty($descriptions) ? $descriptions : new stdClass();
            $allLinks[$channelKey] = !empty($links) ? $links : new stdClass();
            $allAttributes[$channelKey] = !empty($attributes) ? $attributes : new stdClass();
            $allCategories[$channelKey] = !empty($categories) ? $categories : new stdClass();
            $allBrands[$channelKey] = $brand;

            // Images — use shop domain for URLs (list, [] is valid JSON)
            $allImages[$channelKey] = $this->getProductImages($shopProduct, $ctx['domain']);

            // Prices per channel's currencies (shop-aware for multi-shop)
            $firstPaId = ($hasCombinations && !empty($groupedCombinations)) ? key($groupedCombinations) : null;
            $allPrices[$channelKey] = $this->buildPriceEntries($productId, $firstPaId, $ctx['currencies'], $shopId);

            // Stock & availability per shop
            if ($hasVariations) {
                $parentAvailability = 'out_of_stock';
                $parentStock = 0;
                foreach (array_keys($groupedCombinations) as $paId) {
                    $qty = $this->getStockQuantity($productId, $shopId, $paId);
                    $parentStock += $qty;
                    $comboStatus = $this->getAvailabilityStatus($shopProduct, $qty, $paId, $shopId);
                    if ($comboStatus === 'available') {
                        $parentAvailability = 'available';
                    } elseif ($comboStatus === 'backorder' && $parentAvailability !== 'available') {
                        $parentAvailability = 'backorder';
                    }
                }
            } else {
                $parentStock = $this->getStockQuantity($productId, $shopId);
                $parentAvailability = $this->getAvailabilityStatus($shopProduct, $parentStock, null, $shopId);
            }

            $allAvailabilities[$channelKey] = $parentAvailability;
            $allStocks[$channelKey] = $parentStock;

            // Variation attribute names per language for this channel
            if ($hasVariations) {
                $varAttrs = [];
                foreach ($ctx['enabled_languages'] as $iso) {
                    if (!isset($combinationsByLang[$iso]) || empty($combinationsByLang[$iso])) {
                        // Language has no combination translations — fall back to default language
                        if (isset($combinationsByLang[$defaultIso]) && !empty($combinationsByLang[$defaultIso])) {
                            $fallbackGrouped = [];
                            foreach ($combinationsByLang[$defaultIso] as $combo) {
                                $cPaId = (int) $combo['id_product_attribute'];
                                if (!isset($fallbackGrouped[$cPaId])) {
                                    $fallbackGrouped[$cPaId] = [];
                                }
                                $fallbackGrouped[$cPaId][] = $combo;
                            }
                            $varAttrs[$iso] = $this->getVariationAttributeNames($fallbackGrouped);
                        } else {
                            $varAttrs[$iso] = [];
                        }
                        continue;
                    }
                    $langGrouped = [];
                    foreach ($combinationsByLang[$iso] as $combo) {
                        $cPaId = (int) $combo['id_product_attribute'];
                        if (!isset($langGrouped[$cPaId])) {
                            $langGrouped[$cPaId] = [];
                        }
                        $langGrouped[$cPaId][] = $combo;
                    }
                    $varAttrs[$iso] = $this->getVariationAttributeNames($langGrouped);
                }
                $allVariationAttributes[$channelKey] = $varAttrs;
            }
        }

        // Per-channel min order quantity. Mirrors `prices` / `stock_quantities`
        // shape so PS multi-shop overrides have a place to live. Today we
        // send the product-level value for every channel; per-shop reads
        // from `ps_product_shop.minimal_quantity` can fill these in later.
        $allMinQty = [];
        foreach ($channelKeys as $ck) {
            $allMinQty[$ck] = $parentMinQty;
        }

        // Per-channel maximum order quantity. Mirrors the `min_order_quantities`
        // shape. PrestaShop core has NO native max-per-order field, so this is a
        // placeholder that always emits null (= no limit) for every channel. A
        // future custom field (e.g. a per-shop column) can fill these in later.
        $allMaxQty = [];
        foreach ($channelKeys as $ck) {
            $allMaxQty[$ck] = null;
        }

        $availableForOrder = (bool) $product->available_for_order;
        $productCondition = !empty($product->condition) ? (string) $product->condition : null;
        $isVirtual = (bool) $product->is_virtual;

        $parentData = [
            'identification_number' => 'product-' . $productId,
            'sku' => $parentSku,
            'channels' => $channelKeys,
            'names' => $allNames,
            'descriptions' => $allDescriptions,
            'links' => $allLinks,
            'attributes' => $allAttributes,
            'categories' => $allCategories,
            'brands' => $allBrands,
            'prices' => $allPrices,
            'availability_statuses' => $allAvailabilities,
            'stock_quantities' => $allStocks,
            'images' => $allImages,
            'min_order_quantities' => $allMinQty,
            'max_order_quantities' => $allMaxQty,
            'available_for_order' => $availableForOrder,
            'condition' => $productCondition,
            'is_virtual' => $isVirtual,
            'parent_sku' => null,
            'is_parent' => $hasVariations,
            'variation_attributes' => !empty($allVariationAttributes) ? $allVariationAttributes : new stdClass(),
        ];

        if ($syncSessionId) {
            $parentData['sync_session_id'] = $syncSessionId;
        }

        $result = [$parentData];

        if ($hasCombinations) {
            foreach ($groupedCombinations as $paId => $comboGroup) {
                $variationData = $this->formatCombination(
                    $product,
                    $paId,
                    $comboGroup,
                    $parentSku,
                    $contexts,
                    $channelKeys,
                    $allCategories,
                    $allDescriptions,
                    $combinationsByLang,
                    $parentMinQty,
                    $syncSessionId
                );
                if ($variationData) {
                    $result[] = $variationData;
                }
            }
        }

        return $result;
    }

    /**
     * Build the lightweight "product.availability" payloads for a product.
     *
     * Mirrors the channel resolution, SKU scheme, per-channel availability
     * status and stock-quantity logic of `format()` EXACTLY, but skips the
     * heavy fields (names, descriptions, prices, images, categories). Used
     * by the stock-only hook path so a bare quantity tick doesn't rebuild
     * and re-ship the whole product.
     *
     * For a SIMPLE product, returns the single `product-{id}` entry (its own
     * availability). For a product WITH combinations, returns one entry per
     * combination (`variation-{paId}`) and intentionally OMITS the
     * `product-{id}` parent aggregate: Emporiqa derives a parent's aggregate
     * availability/stock on-demand from its combinations at read time, so
     * shipping the aggregate on a stock tick would only refresh a stored field
     * nothing reads. (The full event still emits the parent — it carries
     * fields that ARE read, e.g. the price range.) Each entry is the SHARED
     * availability contract:
     *   identification_number, sku, availability_statuses{}, stock_quantities{}
     *
     * @param Product $product
     *
     * @return array<int, array> List of availability payload entries (may be empty)
     */
    public function formatAvailability(Product $product)
    {
        $productId = (int) $product->id;

        $reference = trim((string) $product->reference);
        $parentSku = $reference !== '' ? $reference : 'product-' . $productId;

        $allContexts = $this->channelResolver->getShopContexts();
        $productChannels = $this->channelResolver->getProductChannels($productId);
        if (empty($productChannels)) {
            return [];
        }

        $contexts = [];
        foreach ($allContexts as $channelKey => $ctx) {
            if (in_array($channelKey, $productChannels, true)) {
                $contexts[$channelKey] = $ctx;
            }
        }
        if (empty($contexts)) {
            return [];
        }

        // Group combinations by product_attribute id. Only paIds + references
        // are needed here, so a single language pass is enough. Prefer the
        // default language; if it yields nothing (a product whose attributes
        // lack default-language names — getAttributeCombinations inner-joins
        // attribute_lang) fall back to any active language so a product with
        // combinations is never misread as simple, mirroring format().
        $defaultLangId = (int) Configuration::get('PS_LANG_DEFAULT');
        $combos = $product->getAttributeCombinations($defaultLangId);
        if (empty($combos)) {
            foreach ($contexts as $ctx) {
                foreach ($ctx['languages'] as $langId) {
                    $combos = $product->getAttributeCombinations((int) $langId);
                    if (!empty($combos)) {
                        break 2;
                    }
                }
            }
        }
        $groupedCombinations = [];
        if (!empty($combos)) {
            foreach ($combos as $combo) {
                $paId = (int) $combo['id_product_attribute'];
                if (!isset($groupedCombinations[$paId])) {
                    $groupedCombinations[$paId] = $combo;
                }
            }
        }
        $hasVariations = count($groupedCombinations) > 0;

        $result = [];

        // Simple product (no combinations): the `product-{id}` entry IS the
        // item, not an aggregate of children, so it must be emitted. For a
        // product WITH combinations the parent aggregate is omitted (derived
        // on-demand by Emporiqa); only the combination entries below ship.
        if (!$hasVariations) {
            $parentAvailabilities = [];
            $parentStocks = [];
            foreach ($contexts as $channelKey => $ctx) {
                $shopId = $ctx['shop_id'];

                // No shop-scoped reload needed: getAvailabilityStatus reads
                // only the product id, and the shop dimension is applied via
                // the $shopId argument (stock query + out_of_stock lookup).
                $parentStock = $this->getStockQuantity($productId, $shopId);
                $parentAvailabilities[$channelKey] = $this->getAvailabilityStatus($product, $parentStock, null, $shopId);
                $parentStocks[$channelKey] = $parentStock;
            }

            $result[] = [
                'identification_number' => 'product-' . $productId,
                'sku' => $parentSku,
                'availability_statuses' => $parentAvailabilities,
                'stock_quantities' => $parentStocks,
            ];
        }

        foreach ($groupedCombinations as $paId => $combo) {
            $comboAvailabilities = [];
            $comboStocks = [];
            foreach ($contexts as $channelKey => $ctx) {
                $shopId = $ctx['shop_id'];
                $qty = $this->getStockQuantity($productId, $shopId, $paId);
                $comboStocks[$channelKey] = $qty;
                $comboAvailabilities[$channelKey] = $this->getAvailabilityStatus($product, $qty, $paId, $shopId);
            }

            $comboReference = isset($combo['reference']) ? trim((string) $combo['reference']) : '';
            $result[] = [
                'identification_number' => 'variation-' . (int) $paId,
                'sku' => $comboReference !== '' ? $comboReference : 'variation-' . (int) $paId,
                'availability_statuses' => $comboAvailabilities,
                'stock_quantities' => $comboStocks,
            ];
        }

        return $result;
    }

    private function formatCombination(
        Product $product,
        $paId,
        array $comboGroup,
        $parentSku,
        array $contexts,
        array $channelKeys,
        array $parentCategories,
        array $parentDescriptions,
        array $combinationsByLang,
        $parentMinQty = 1,
        $syncSessionId = null
    ) {
        $productId = (int) $product->id;
        $brand = $this->getProductBrand($product);
        $defaultLangId = (int) Configuration::get('PS_LANG_DEFAULT');

        $defaultAttributes = [];
        foreach ($comboGroup as $combo) {
            $groupName = $combo['group_name'] ?? '';
            $attrName = $combo['attribute_name'] ?? '';
            if ($groupName && $attrName) {
                $defaultAttributes[$groupName] = $attrName;
            }
        }

        $allNames = [];
        $allDescriptions = [];
        $allLinks = [];
        $allAttributes = [];
        $allBrands = [];
        $allPrices = [];
        $allAvailabilities = [];
        $allStocks = [];
        $allImages = [];

        foreach ($contexts as $channelKey => $ctx) {
            $shopId = $ctx['shop_id'];
            $shopLink = new Link(null, null);

            // Reload Product scoped to this shop — see note in format().
            $shopProduct = new Product($productId, false, null, $shopId);
            if (!Validate::isLoadedObject($shopProduct)) {
                $shopProduct = $product;
            }

            $names = [];
            $descriptions = [];
            $links = [];
            $attributes = [];

            foreach ($ctx['enabled_languages'] as $iso) {
                $langId = isset($ctx['languages'][$iso]) ? $ctx['languages'][$iso] : null;
                if (!$langId) {
                    continue;
                }

                $name = is_array($shopProduct->name) ? ($shopProduct->name[$langId] ?? reset($shopProduct->name)) : $shopProduct->name;

                $langAttributes = [];
                if (isset($combinationsByLang[$iso])) {
                    foreach ($combinationsByLang[$iso] as $lc) {
                        if ((int) $lc['id_product_attribute'] === (int) $paId) {
                            $gn = $lc['group_name'] ?? '';
                            $an = $lc['attribute_name'] ?? '';
                            if ($gn && $an) {
                                $langAttributes[$gn] = $an;
                            }
                        }
                    }
                }

                $attrForSuffix = !empty($langAttributes) ? $langAttributes : $defaultAttributes;
                if (!empty($attrForSuffix)) {
                    $name .= ' - ' . implode(' / ', array_values($attrForSuffix));
                }
                $names[$iso] = $name ?: '';

                $descriptions[$iso] = isset($parentDescriptions[$channelKey][$iso]) ? $parentDescriptions[$channelKey][$iso] : '';

                $links[$iso] = $shopLink->getProductLink($shopProduct, null, null, null, $langId, $shopId, $paId);

                $attributes[$iso] = !empty($langAttributes) ? $langAttributes : (!empty($defaultAttributes) ? $defaultAttributes : new stdClass());
            }

            // Empty PHP arrays serialize as JSON []; the API expects {} (dict).
            $allNames[$channelKey] = !empty($names) ? $names : new stdClass();
            $allDescriptions[$channelKey] = !empty($descriptions) ? $descriptions : new stdClass();
            $allLinks[$channelKey] = !empty($links) ? $links : new stdClass();
            $allAttributes[$channelKey] = !empty($attributes) ? $attributes : new stdClass();
            $allBrands[$channelKey] = $brand;

            // Variation images
            $varImages = $this->getProductImages($shopProduct, $ctx['domain']);
            $combinationImages = Image::getImages($defaultLangId, $productId, $paId);
            if (!empty($combinationImages)) {
                $varImages = [];
                $linkRewrite = is_array($shopProduct->link_rewrite)
                    ? ($shopProduct->link_rewrite[$defaultLangId] ?? reset($shopProduct->link_rewrite))
                    : $shopProduct->link_rewrite;
                $imageTypeName = $this->getImageTypeName('large');
                foreach ($combinationImages as $img) {
                    $imageUrl = $ctx['domain'] . '/img/p/' . $this->getImagePath($img['id_image']) . '-' . $imageTypeName . '.jpg';
                    $varImages[] = $imageUrl;
                }
            }
            $allImages[$channelKey] = array_values(array_unique($varImages));

            $allPrices[$channelKey] = $this->buildPriceEntries($productId, $paId, $ctx['currencies'], $shopId);

            $stock = $this->getStockQuantity($productId, $shopId, $paId);
            $allStocks[$channelKey] = $stock;
            $allAvailabilities[$channelKey] = $this->getAvailabilityStatus($shopProduct, $stock, $paId, $shopId);
        }

        $reference = '';
        if (!empty($comboGroup[0]['reference'])) {
            $reference = $comboGroup[0]['reference'];
        }

        // Per-variation minimum order quantity lives on
        // `ps_product_attribute.minimal_quantity` (note: PS uses
        // `minimal_` everywhere -- the `minimum_` spelling was a typo).
        // Inherit the parent's minimal_quantity when the combination has
        // none (column default 1).
        $variationMinQty = isset($comboGroup[0]['minimal_quantity']) ? (int) $comboGroup[0]['minimal_quantity'] : 0;
        if ($variationMinQty < 1) {
            $variationMinQty = $parentMinQty > 0 ? (int) $parentMinQty : 1;
        }

        // No per-shop dimension for combination min_qty in PS, so the same
        // value goes to every channel for shape consistency with the parent.
        $allMinQty = [];
        foreach ($channelKeys as $ck) {
            $allMinQty[$ck] = $variationMinQty;
        }

        // Per-channel maximum order quantity. Mirrors `min_order_quantities`.
        // PrestaShop core has NO native max-per-order field, so this is a
        // placeholder that always emits null (= no limit) for every channel
        // unless a future custom field is added.
        $allMaxQty = [];
        foreach ($channelKeys as $ck) {
            $allMaxQty[$ck] = null;
        }

        // Product-level flags (combinations inherit them from the parent).
        $availableForOrder = (bool) $product->available_for_order;
        $productCondition = !empty($product->condition) ? (string) $product->condition : null;
        $isVirtual = (bool) $product->is_virtual;

        $data = [
            'identification_number' => 'variation-' . $paId,
            'sku' => $reference ?: 'variation-' . $paId,
            'channels' => $channelKeys,
            'names' => $allNames,
            'descriptions' => $allDescriptions,
            'links' => $allLinks,
            'attributes' => $allAttributes,
            'categories' => $parentCategories,
            'brands' => $allBrands,
            'prices' => $allPrices,
            'availability_statuses' => $allAvailabilities,
            'stock_quantities' => $allStocks,
            'images' => $allImages,
            'min_order_quantities' => $allMinQty,
            'max_order_quantities' => $allMaxQty,
            'available_for_order' => $availableForOrder,
            'condition' => $productCondition,
            'is_virtual' => $isVirtual,
            'parent_sku' => $parentSku,
            'is_parent' => false,
            'variation_attributes' => new stdClass(),
        ];

        if ($syncSessionId) {
            $data['sync_session_id'] = $syncSessionId;
        }

        return $data;
    }

    private function getAvailabilityStatus(Product $product, $stock, $paId = null, $shopId = null)
    {
        if ($stock > 0) {
            return 'available';
        }

        $outOfStockBehavior = $this->getOutOfStockBehavior((int) $product->id, $paId, $shopId);
        if ($outOfStockBehavior === 1) {
            return 'backorder';
        }
        if ($outOfStockBehavior === 2) {
            $globalAllow = (bool) Configuration::get('PS_ORDER_OUT_OF_STOCK');

            return $globalAllow ? 'backorder' : 'out_of_stock';
        }

        return 'out_of_stock';
    }

    private function getOutOfStockBehavior($productId, $paId = null, $shopId = null)
    {
        if ($shopId === null) {
            $shopId = (int) $this->context->shop->id;
        }
        $sql = new DbQuery();
        $sql->select('out_of_stock');
        $sql->from('stock_available');
        $sql->where('id_product = ' . (int) $productId);
        $sql->where('id_product_attribute = ' . ($paId ? (int) $paId : 0));
        $sql->where('id_shop = ' . (int) $shopId);

        $result = Db::getInstance()->getValue($sql);

        // Fallback for shared stock (shop group level, where id_shop = 0)
        if ($result === false) {
            $sql2 = new DbQuery();
            $sql2->select('out_of_stock');
            $sql2->from('stock_available');
            $sql2->where('id_product = ' . (int) $productId);
            $sql2->where('id_product_attribute = ' . ($paId ? (int) $paId : 0));
            $sql2->where('id_shop = 0');
            $result = Db::getInstance()->getValue($sql2);
        }

        return $result !== false ? (int) $result : 2;
    }

    private function getStockQuantity($productId, $shopId, $paId = null)
    {
        return (int) StockAvailable::getQuantityAvailableByProduct(
            (int) $productId,
            $paId ? (int) $paId : 0,
            (int) $shopId
        );
    }

    private function getCategoryPaths(Product $product, $langId = null, $shopId = null)
    {
        $productId = (int) $product->id;
        if ($langId === null) {
            $langId = (int) Configuration::get('PS_LANG_DEFAULT');
        }
        if (!$shopId) {
            $shopId = (int) Configuration::get('PS_SHOP_DEFAULT');
        }

        $cacheKey = $productId . '-' . $shopId . '-' . $langId;
        if (isset(self::$categoryLangCache[$cacheKey])) {
            return self::$categoryLangCache[$cacheKey];
        }

        $categoryIds = Product::getProductCategories($productId);
        if (empty($categoryIds)) {
            self::$categoryLangCache[$cacheKey] = [];

            return [];
        }

        $paths = [];

        foreach ($categoryIds as $categoryId) {
            $catCacheKey = 'cat-' . (int) $categoryId . '-' . $shopId . '-' . $langId;
            if (isset(self::$categoryLangCache[$catCacheKey])) {
                $path = self::$categoryLangCache[$catCacheKey];
                if ($path !== '') {
                    $paths[] = $path;
                }
                continue;
            }

            // Look up the leaf node's tree position to walk the ancestor chain
            // via nleft/nright. Avoids relying on PrestaShop's Category object
            // which inherits the current request's shop context.
            $leaf = Db::getInstance()->getRow(
                'SELECT nleft, nright FROM ' . _DB_PREFIX_ . 'category WHERE id_category = ' . (int) $categoryId
            );
            if (!$leaf) {
                self::$categoryLangCache[$catCacheKey] = '';
                continue;
            }

            $rows = Db::getInstance()->executeS(
                'SELECT cl.name FROM ' . _DB_PREFIX_ . 'category c'
                . ' INNER JOIN ' . _DB_PREFIX_ . 'category_lang cl'
                . '   ON c.id_category = cl.id_category'
                . '   AND cl.id_shop = ' . (int) $shopId
                . '   AND cl.id_lang = ' . (int) $langId
                . ' WHERE c.nleft <= ' . (int) $leaf['nleft']
                . '   AND c.nright >= ' . (int) $leaf['nright']
                . '   AND c.level_depth >= 2'
                . ' ORDER BY c.level_depth ASC'
            );

            $segments = [];
            if ($rows) {
                foreach ($rows as $row) {
                    if (!empty($row['name'])) {
                        $segments[] = $row['name'];
                    }
                }
            }

            $path = !empty($segments) ? implode(' > ', $segments) : '';
            self::$categoryLangCache[$catCacheKey] = $path;
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        $result = array_values(array_unique($paths));
        self::$categoryLangCache[$cacheKey] = $result;

        return $result;
    }

    private function buildPriceEntries($productId, $paId = null, $currencies = null, $shopId = null)
    {
        if ($currencies === null) {
            $currencies = Currency::getCurrencies(true);
        }
        if (empty($currencies)) {
            $default = Currency::getDefaultCurrency();
            $currencies = $default ? [['id_currency' => $default->id, 'iso_code' => $default->iso_code]] : [];
        }

        // Base context for price computation, shop-scoped for multi-shop so
        // per-shop pricing (ps_product_shop) resolves correctly.
        $baseContext = $this->context;
        if ($shopId && Shop::isFeatureActive() && (int) $shopId !== (int) $this->context->shop->id) {
            $baseContext = $this->context->cloneContext();
            $baseContext->shop = new Shop((int) $shopId);
        }

        $entries = [];

        foreach ($currencies as $currency) {
            $iso = is_array($currency) ? $currency['iso_code'] : $currency->iso_code;
            $currId = (int) (is_array($currency) ? $currency['id_currency'] : $currency->id);

            if (!isset(self::$currencyObjCache[$currId])) {
                self::$currencyObjCache[$currId] = is_array($currency) ? new Currency($currId) : $currency;
            }
            $currencyObj = self::$currencyObjCache[$currId];

            // Compute the price in THIS currency's own context. getPriceStatic
            // reads the currency from the context to (a) match currency-scoped
            // specific prices — a promo or price override set for one currency
            // only — and (b) convert the base price. Computing once in the
            // default currency and mathematically converting (the previous
            // approach) silently dropped currency-targeted promotions: the chat
            // would quote the undiscounted converted price.
            $priceContext = $baseContext->cloneContext();
            $priceContext->currency = $currencyObj;

            $specificPrice = null;
            $currentInc = (float) Product::getPriceStatic(
                (int) $productId, true, $paId, 2, null, false, true, 1, false,
                null, null, null, $specificPrice, true, true, $priceContext
            );
            $currentExc = (float) Product::getPriceStatic(
                (int) $productId, false, $paId, 2, null, false, true, 1, false,
                null, null, null, $specificPrice, true, true, $priceContext
            );
            $regularInc = (float) Product::getPriceStatic(
                (int) $productId, true, $paId, 2, null, false, false, 1, false,
                null, null, null, $specificPrice, true, true, $priceContext
            );

            $entry = [
                'currency' => $iso,
                'current_price' => $currentInc,
                'regular_price' => $regularInc,
            ];

            if (abs($currentInc - $currentExc) > 0.001) {
                $entry['price_incl_tax'] = $currentInc;
                $entry['price_excl_tax'] = $currentExc;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    private function getProductBrand(Product $product)
    {
        $manufacturerId = (int) $product->id_manufacturer;
        if ($manufacturerId <= 0) {
            return '';
        }

        if (isset(self::$manufacturerCache[$manufacturerId])) {
            return self::$manufacturerCache[$manufacturerId];
        }

        $name = Manufacturer::getNameById($manufacturerId);
        $result = $name ?: '';
        self::$manufacturerCache[$manufacturerId] = $result;

        return $result;
    }

    private function getProductImages(Product $product, $domain = null)
    {
        $defaultLangId = (int) Configuration::get('PS_LANG_DEFAULT');
        $images = Image::getImages($defaultLangId, (int) $product->id);
        $urls = [];

        $imageTypeName = $this->getImageTypeName('large');
        foreach ($images as $img) {
            $imageId = $img['id_image'];
            if ($domain) {
                $url = $domain . '/img/p/' . $this->getImagePath($imageId) . '-' . $imageTypeName . '.jpg';
            } else {
                $context = $this->context;
                $linkRewrite = is_array($product->link_rewrite)
                    ? ($product->link_rewrite[$defaultLangId] ?? reset($product->link_rewrite))
                    : $product->link_rewrite;
                $url = $context->link->getImageLink(
                    $linkRewrite,
                    (int) $product->id . '-' . $imageId,
                    $imageTypeName
                );
                $url = (strpos($url, 'http') === 0) ? $url : 'https://' . $url;
            }
            $urls[] = $url;
        }

        return $urls;
    }

    /**
     * Convert image ID to file path segments (e.g. 123 → 1/2/3/123).
     */
    private function getImagePath($imageId)
    {
        $id = (string) $imageId;
        $parts = [];
        for ($i = 0; $i < strlen($id); ++$i) {
            $parts[] = $id[$i];
        }
        $parts[] = $id;

        return implode('/', $parts);
    }

    private function getImageTypeName($type)
    {
        if (isset(self::$imageTypeCache[$type])) {
            return self::$imageTypeCache[$type];
        }

        $name = ImageType::getFormattedName($type);
        self::$imageTypeCache[$type] = $name;

        return $name;
    }

    private function getVariationAttributeNames(array $groupedCombinations)
    {
        $names = [];
        $firstGroup = reset($groupedCombinations);
        if ($firstGroup) {
            foreach ($firstGroup as $combo) {
                $groupName = $combo['group_name'] ?? '';
                if ($groupName && !in_array($groupName, $names, true)) {
                    $names[] = $groupName;
                }
            }
        }

        return $names;
    }
}
