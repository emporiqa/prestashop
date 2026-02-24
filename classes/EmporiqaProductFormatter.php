<?php
/**
 * Emporiqa Product Formatter
 *
 * Formats PrestaShop product and combination data into the consolidated
 * payload structure expected by the Emporiqa webhook API.
 * One event per product contains ALL languages in nested channel→language maps.
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
    private static $currencyCache;
    private static $currencyObjCache = [];

    /**
     * Clear per-product caches between sync batches to prevent memory exhaustion.
     */
    public static function clearBatchCaches()
    {
        self::$categoryLangCache = [];
        self::$manufacturerCache = [];
        self::$currencyCache = null;
        self::$currencyObjCache = [];
    }

    /**
     * Format a product (and its combinations) for the webhook payload.
     * Returns consolidated payloads with all languages included.
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
        $context = Context::getContext();
        $channel = (string) Configuration::get('EMPORIQA_WIDGET_CHANNEL');

        $enabledLanguages = EmporiqaLanguageHelper::getEnabledLanguages();
        $langMap = EmporiqaLanguageHelper::getActiveLanguageMap();

        // Build translatable fields across all languages
        $names = [];
        $descriptions = [];
        $links = [];
        $attributes = [];

        foreach ($enabledLanguages as $iso) {
            $langId = isset($langMap[$iso]) ? $langMap[$iso] : EmporiqaLanguageHelper::getLanguageIdByCode($iso);
            if (!$langId) {
                continue;
            }

            $name = is_array($product->name) ? ($product->name[$langId] ?? reset($product->name)) : $product->name;
            $names[$iso] = $name ?: '';

            $desc = is_array($product->description) ? ($product->description[$langId] ?? reset($product->description)) : $product->description;
            $descriptions[$iso] = $desc ?: '';

            $links[$iso] = $context->link->getProductLink($product, null, null, null, $langId);

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
        }

        // Translated categories per language
        $categories = [];
        foreach ($enabledLanguages as $iso) {
            $langId = isset($langMap[$iso]) ? $langMap[$iso] : EmporiqaLanguageHelper::getLanguageIdByCode($iso);
            if ($langId) {
                $categories[$iso] = $this->getCategoryPaths($product, $langId);
            }
        }

        // Shared fields (not language-dependent)
        $brand = $this->getProductBrand($product);
        $images = $this->getProductImages($product);

        // Get default lang ID for combination queries
        $defaultLangId = (int) Configuration::get('PS_LANG_DEFAULT');

        $combinations = $product->getAttributeCombinations($defaultLangId);
        $hasCombinations = !empty($combinations);

        // Group combinations by id_product_attribute
        $groupedCombinations = [];
        if ($hasCombinations) {
            foreach ($combinations as $combo) {
                $paId = (int) $combo['id_product_attribute'];
                if (!isset($groupedCombinations[$paId])) {
                    $groupedCombinations[$paId] = [];
                }
                $groupedCombinations[$paId][] = $combo;
            }
        }

        $hasVariations = count($groupedCombinations) > 0;

        // Pricing (multi-currency with tax breakdown)
        if ($hasCombinations && !empty($groupedCombinations)) {
            reset($groupedCombinations);
            $firstPaId = key($groupedCombinations);
            $prices = $this->buildPriceEntries($productId, $firstPaId);
        } else {
            $prices = $this->buildPriceEntries($productId);
        }

        // Stock & availability for parent
        if ($hasVariations) {
            $parentAvailability = 'out_of_stock';
            $parentStock = 0;
            foreach (array_keys($groupedCombinations) as $paId) {
                $qty = (int) StockAvailable::getQuantityAvailableByProduct($productId, $paId);
                $parentStock += $qty;
                $comboStatus = $this->getAvailabilityStatus($product, $qty, $paId);
                if ($comboStatus === 'available') {
                    $parentAvailability = 'available';
                } elseif ($comboStatus === 'backorder' && $parentAvailability !== 'available') {
                    $parentAvailability = 'backorder';
                }
            }
        } else {
            $stock = (int) StockAvailable::getQuantityAvailableByProduct($productId);
            $parentAvailability = $this->getAvailabilityStatus($product, $stock);
            $parentStock = $stock;
        }

        // Pre-fetch combinations per language once (reused for variation_attributes + formatCombination)
        $combinationsByLang = [];
        if ($hasCombinations) {
            foreach ($enabledLanguages as $iso) {
                $langId = isset($langMap[$iso]) ? $langMap[$iso] : EmporiqaLanguageHelper::getLanguageIdByCode($iso);
                if ($langId) {
                    $combinationsByLang[$iso] = $product->getAttributeCombinations($langId);
                }
            }
        }

        // Variation attribute names for parent (translated per language)
        $variationAttributes = [];
        if ($hasVariations) {
            foreach ($enabledLanguages as $iso) {
                if (!isset($combinationsByLang[$iso])) {
                    continue;
                }
                $langGrouped = [];
                foreach ($combinationsByLang[$iso] as $combo) {
                    $paId = (int) $combo['id_product_attribute'];
                    if (!isset($langGrouped[$paId])) {
                        $langGrouped[$paId] = [];
                    }
                    $langGrouped[$paId][] = $combo;
                }
                $variationAttributes[$iso] = $this->getVariationAttributeNames($langGrouped);
            }
        }

        $parentData = [
            'identification_number' => 'product-' . $productId,
            'sku' => $parentSku,
            'channels' => [$channel],
            'names' => [$channel => $names],
            'descriptions' => [$channel => $descriptions],
            'links' => [$channel => $links],
            'attributes' => [$channel => $attributes],
            'categories' => [$channel => $categories],
            'brands' => [$channel => $brand],
            'prices' => [$channel => $prices],
            'availability_statuses' => [$channel => $parentAvailability],
            'stock_quantities' => [$channel => $parentStock],
            'images' => [$channel => $images],
            'parent_sku' => null,
            'is_parent' => $hasVariations,
            'variation_attributes' => !empty($variationAttributes) ? [$channel => $variationAttributes] : new stdClass(),
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
                    $categories,
                    $brand,
                    $images,
                    $descriptions,
                    $enabledLanguages,
                    $langMap,
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

    /**
     * Format a single combination in consolidated format.
     */
    private function formatCombination(
        Product $product,
        $paId,
        array $comboGroup,
        $parentSku,
        array $parentCategories,
        $parentBrand,
        array $parentImages,
        array $parentDescriptions,
        array $enabledLanguages,
        array $langMap,
        array $combinationsByLang,
        $syncSessionId = null
    ) {
        $productId = (int) $product->id;
        $context = Context::getContext();
        $channel = (string) Configuration::get('EMPORIQA_WIDGET_CHANNEL');

        // Build default-language attribute map for suffix
        $defaultAttributes = [];
        foreach ($comboGroup as $combo) {
            $groupName = $combo['group_name'] ?? '';
            $attrName = $combo['attribute_name'] ?? '';
            if ($groupName && $attrName) {
                $defaultAttributes[$groupName] = $attrName;
            }
        }

        // Build translatable fields per language
        $names = [];
        $descriptions = [];
        $links = [];
        $attributes = [];

        foreach ($enabledLanguages as $iso) {
            $langId = isset($langMap[$iso]) ? $langMap[$iso] : EmporiqaLanguageHelper::getLanguageIdByCode($iso);
            if (!$langId) {
                continue;
            }

            // Name with attribute suffix
            $name = is_array($product->name) ? ($product->name[$langId] ?? reset($product->name)) : $product->name;

            // Extract this combination's attributes from pre-fetched data
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

            $descriptions[$iso] = $parentDescriptions[$iso] ?? '';

            $links[$iso] = $context->link->getProductLink($product, null, null, null, $langId, null, $paId);

            $attributes[$iso] = !empty($langAttributes) ? $langAttributes : (!empty($defaultAttributes) ? $defaultAttributes : new stdClass());
        }

        $prices = $this->buildPriceEntries($productId, $paId);

        $stock = (int) StockAvailable::getQuantityAvailableByProduct($productId, $paId);
        $availability = $this->getAvailabilityStatus($product, $stock, $paId);

        // Variation images — use combination-specific if available, else parent
        $varImages = $parentImages;
        $defaultLangId = (int) Configuration::get('PS_LANG_DEFAULT');
        $combinationImages = Image::getImages($defaultLangId, $productId, $paId);
        if (!empty($combinationImages)) {
            $varImages = [];
            $linkRewrite = is_array($product->link_rewrite)
                ? ($product->link_rewrite[$defaultLangId] ?? reset($product->link_rewrite))
                : $product->link_rewrite;
            foreach ($combinationImages as $img) {
                $imageUrl = $context->link->getImageLink(
                    $linkRewrite,
                    $productId . '-' . $img['id_image'],
                    $this->getImageTypeName('large')
                );
                $varImages[] = (strpos($imageUrl, 'http') === 0) ? $imageUrl : 'https://' . $imageUrl;
            }
        }

        // SKU: use combination reference if available
        $reference = '';
        if (!empty($comboGroup[0]['reference'])) {
            $reference = $comboGroup[0]['reference'];
        }

        $data = [
            'identification_number' => 'variation-' . $paId,
            'sku' => $reference ?: 'variation-' . $paId,
            'channels' => [$channel],
            'names' => [$channel => $names],
            'descriptions' => [$channel => $descriptions],
            'links' => [$channel => $links],
            'attributes' => [$channel => $attributes],
            'categories' => [$channel => $parentCategories],
            'brands' => [$channel => $parentBrand],
            'prices' => [$channel => $prices],
            'availability_statuses' => [$channel => $availability],
            'stock_quantities' => [$channel => $stock],
            'images' => [$channel => array_values(array_unique($varImages))],
            'parent_sku' => $parentSku,
            'is_parent' => false,
            'variation_attributes' => new stdClass(),
        ];

        if ($syncSessionId) {
            $data['sync_session_id'] = $syncSessionId;
        }

        return $data;
    }

    private function getAvailabilityStatus(Product $product, $stock, $paId = null)
    {
        if ($stock > 0) {
            return 'available';
        }

        $outOfStockBehavior = $this->getOutOfStockBehavior((int) $product->id, $paId);
        if ($outOfStockBehavior === 1) {
            return 'backorder';
        }
        if ($outOfStockBehavior === 2) {
            $globalAllow = (bool) Configuration::get('PS_ORDER_OUT_OF_STOCK');

            return $globalAllow ? 'backorder' : 'out_of_stock';
        }

        return 'out_of_stock';
    }

    /**
     * Get out_of_stock value from ps_stock_available, supporting per-combination lookup.
     * StockAvailable::outOfStock() only accepts (id_product, id_shop) — no combination support.
     */
    private function getOutOfStockBehavior($productId, $paId = null)
    {
        $shopId = (int) Context::getContext()->shop->id;
        $sql = new DbQuery();
        $sql->select('out_of_stock');
        $sql->from('stock_available');
        $sql->where('id_product = ' . (int) $productId);
        $sql->where('id_product_attribute = ' . ($paId ? (int) $paId : 0));
        $sql->where('id_shop = ' . $shopId);

        $result = Db::getInstance()->getValue($sql);

        return $result !== false ? (int) $result : 2;
    }

    /**
     * Get hierarchical category paths for all assigned categories in a specific language.
     * Returns e.g. ["Electronics > TVs", "Featured"].
     */
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

    /**
     * Build price entries for all active currencies with tax breakdown.
     *
     * @param int $productId Product ID
     * @param int|null $paId Product attribute (combination) ID
     *
     * @return array
     */
    private function buildPriceEntries($productId, $paId = null)
    {
        $currencies = $this->getActiveCurrencies();
        if (empty($currencies)) {
            $default = Currency::getDefaultCurrency();
            $currencies = $default ? [['id_currency' => $default->id, 'iso_code' => $default->iso_code, 'conversion_rate' => 1.0]] : [];
        }

        $defaultCurrencyId = (int) Configuration::get('PS_CURRENCY_DEFAULT');

        $priceInclTax = (float) Product::getPriceStatic($productId, true, $paId, 2);
        $priceExclTax = (float) Product::getPriceStatic($productId, false, $paId, 2);
        $regularInclTax = (float) Product::getPriceStatic($productId, true, $paId, 2, null, false, false);

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

    private function getActiveCurrencies()
    {
        if (self::$currencyCache === null) {
            $all = Currency::getCurrencies(true);
            $seen = [];
            $unique = [];
            foreach ($all as $c) {
                $id = (int) (is_array($c) ? $c['id_currency'] : $c->id);
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $unique[] = $c;
                }
            }
            self::$currencyCache = $unique;
        }

        return self::$currencyCache;
    }

    /**
     * Get product brand. Returns empty string if no manufacturer.
     */
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

    /**
     * Get all product image URLs (language-independent).
     */
    private function getProductImages(Product $product)
    {
        $context = Context::getContext();
        $defaultLangId = (int) Configuration::get('PS_LANG_DEFAULT');
        $images = Image::getImages($defaultLangId, (int) $product->id);
        $urls = [];

        $linkRewrite = is_array($product->link_rewrite)
            ? ($product->link_rewrite[$defaultLangId] ?? reset($product->link_rewrite))
            : $product->link_rewrite;

        $imageTypeName = $this->getImageTypeName('large');
        foreach ($images as $img) {
            $imageUrl = $context->link->getImageLink(
                $linkRewrite,
                (int) $product->id . '-' . $img['id_image'],
                $imageTypeName
            );
            $urls[] = (strpos($imageUrl, 'http') === 0) ? $imageUrl : 'https://' . $imageUrl;
        }

        return $urls;
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
