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
        $parentSku = 'product-' . $productId;

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

                $name = is_array($product->name) ? ($product->name[$langId] ?? reset($product->name)) : $product->name;
                $names[$iso] = $name ?: '';

                $desc = is_array($product->description) ? ($product->description[$langId] ?? reset($product->description)) : $product->description;
                $descriptions[$iso] = $desc ?: '';

                $links[$iso] = $shopLink->getProductLink($product, null, null, null, $langId, $shopId);

                $features = $product->getFrontFeatures($langId);
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

                $categories[$iso] = $this->getCategoryPaths($product, $langId);
            }

            $allNames[$channelKey] = $names;
            $allDescriptions[$channelKey] = $descriptions;
            $allLinks[$channelKey] = $links;
            $allAttributes[$channelKey] = $attributes;
            $allCategories[$channelKey] = $categories;
            $allBrands[$channelKey] = $brand;

            // Images — use shop domain for URLs
            $allImages[$channelKey] = $this->getProductImages($product, $ctx['domain']);

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
                    $comboStatus = $this->getAvailabilityStatus($product, $qty, $paId, $shopId);
                    if ($comboStatus === 'available') {
                        $parentAvailability = 'available';
                    } elseif ($comboStatus === 'backorder' && $parentAvailability !== 'available') {
                        $parentAvailability = 'backorder';
                    }
                }
            } else {
                $parentStock = $this->getStockQuantity($productId, $shopId);
                $parentAvailability = $this->getAvailabilityStatus($product, $parentStock, null, $shopId);
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
                    $syncSessionId
                );
                if ($variationData) {
                    $result[] = $variationData;
                }
            }
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

            $names = [];
            $descriptions = [];
            $links = [];
            $attributes = [];

            foreach ($ctx['enabled_languages'] as $iso) {
                $langId = isset($ctx['languages'][$iso]) ? $ctx['languages'][$iso] : null;
                if (!$langId) {
                    continue;
                }

                $name = is_array($product->name) ? ($product->name[$langId] ?? reset($product->name)) : $product->name;

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

                $links[$iso] = $shopLink->getProductLink($product, null, null, null, $langId, $shopId, $paId);

                $attributes[$iso] = !empty($langAttributes) ? $langAttributes : (!empty($defaultAttributes) ? $defaultAttributes : new stdClass());
            }

            $allNames[$channelKey] = $names;
            $allDescriptions[$channelKey] = $descriptions;
            $allLinks[$channelKey] = $links;
            $allAttributes[$channelKey] = $attributes;
            $allBrands[$channelKey] = $brand;

            // Variation images
            $varImages = $this->getProductImages($product, $ctx['domain']);
            $combinationImages = Image::getImages($defaultLangId, $productId, $paId);
            if (!empty($combinationImages)) {
                $varImages = [];
                $linkRewrite = is_array($product->link_rewrite)
                    ? ($product->link_rewrite[$defaultLangId] ?? reset($product->link_rewrite))
                    : $product->link_rewrite;
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
            $allAvailabilities[$channelKey] = $this->getAvailabilityStatus($product, $stock, $paId, $shopId);
        }

        $reference = '';
        if (!empty($comboGroup[0]['reference'])) {
            $reference = $comboGroup[0]['reference'];
        }

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

    private function getCategoryPaths(Product $product, $langId = null)
    {
        $productId = (int) $product->id;
        if ($langId === null) {
            $langId = (int) Configuration::get('PS_LANG_DEFAULT');
        }

        $cacheKey = $productId . '-' . $langId;
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
            $catCacheKey = (int) $categoryId . '-' . $langId;
            if (isset(self::$categoryLangCache['cat-' . $catCacheKey])) {
                $path = self::$categoryLangCache['cat-' . $catCacheKey];
                if ($path !== '') {
                    $paths[] = $path;
                }
                continue;
            }

            $category = new Category((int) $categoryId, $langId);
            if (!Validate::isLoadedObject($category)) {
                self::$categoryLangCache['cat-' . $catCacheKey] = '';
                continue;
            }

            $parents = $category->getParentsCategories($langId);
            if (empty($parents)) {
                self::$categoryLangCache['cat-' . $catCacheKey] = '';
                continue;
            }

            usort($parents, function ($a, $b) {
                return (int) $a['level_depth'] - (int) $b['level_depth'];
            });

            $segments = [];
            foreach ($parents as $parent) {
                if ((int) $parent['level_depth'] < 2) {
                    continue;
                }
                $name = $parent['name'] ?? '';
                if ($name !== '') {
                    $segments[] = $name;
                }
            }

            $path = !empty($segments) ? implode(' > ', $segments) : '';
            self::$categoryLangCache['cat-' . $catCacheKey] = $path;
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

        $defaultCurrencyId = (int) Configuration::get('PS_CURRENCY_DEFAULT');

        // Build shop-specific context for correct multi-shop pricing
        $priceContext = null;
        if ($shopId && Shop::isFeatureActive() && (int) $shopId !== (int) $this->context->shop->id) {
            $priceContext = $this->context->cloneContext();
            $priceContext->shop = new Shop((int) $shopId);
        }

        $specificPrice = null;
        $priceInclTax = (float) Product::getPriceStatic(
            (int) $productId, true, $paId, 2, null, false, true, 1, false,
            null, null, null, $specificPrice, true, true, $priceContext
        );
        $priceExclTax = (float) Product::getPriceStatic(
            (int) $productId, false, $paId, 2, null, false, true, 1, false,
            null, null, null, $specificPrice, true, true, $priceContext
        );
        $regularInclTax = (float) Product::getPriceStatic(
            (int) $productId, true, $paId, 2, null, false, false, 1, false,
            null, null, null, $specificPrice, true, true, $priceContext
        );

        $entries = [];

        foreach ($currencies as $currency) {
            $iso = is_array($currency) ? $currency['iso_code'] : $currency->iso_code;
            $currId = (int) (is_array($currency) ? $currency['id_currency'] : $currency->id);
            $isDefault = ($currId === $defaultCurrencyId);

            if ($isDefault) {
                $currentInc = $priceInclTax;
                $currentExc = $priceExclTax;
                $regularInc = $regularInclTax;
            } else {
                if (!isset(self::$currencyObjCache[$currId])) {
                    self::$currencyObjCache[$currId] = is_array($currency) ? new Currency($currId) : $currency;
                }
                $currencyObj = self::$currencyObjCache[$currId];
                $currentInc = Tools::convertPriceFull($priceInclTax, null, $currencyObj);
                $currentExc = Tools::convertPriceFull($priceExclTax, null, $currencyObj);
                $regularInc = Tools::convertPriceFull($regularInclTax, null, $currencyObj);
            }

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
