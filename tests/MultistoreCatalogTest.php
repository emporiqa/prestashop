<?php
/**
 * Locks in what a product sync sends for each shop of a multistore install:
 *
 * - a product link is built the way the shop's storefront builds it: from the
 *   product loaded in that language and shop (Link reads the rewrite of a
 *   product loaded in all languages in the context language) and with its
 *   default category's rewrite in that shop and language, which PrestaShop
 *   8's default product route puts in the path ({category:/}); no rewrite
 *   there means no category segment, as on the storefront, even though
 *   Product fills one from the context shop;
 * - a combination goes only to the channels of the shops that sell it (its
 *   product_attribute_shop rows), and a combination sold in no synced shop is
 *   not sent at all; the stock-only event follows the same rule;
 * - the parent's stock in a shop is the sum of the combinations sold there,
 *   it quotes a combination sold there, and in a shop that sells none of its
 *   combinations it is the simple product that shop sells.
 *
 * Self-contained (no PHPUnit, no PrestaShop): PrestaShop classes are stubbed
 * and the real EmporiqaProductFormatter is loaded.
 *
 * Run: php tests/MultistoreCatalogTest.php
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
define('_PS_VERSION_', '8.1.0');
define('_DB_PREFIX_', 'ps_');
define('PS_TAX_EXC', 1);

class Context
{
    public $customer;
    public $cart;
    public $country;
    public $currency;
    public $shop;

    public function cloneContext()
    {
        return clone $this;
    }
}

class Customer
{
    public $id;
}

class Cart
{
    public $id;
}

class Country
{
    public $id;

    public function __construct($id = null)
    {
        $this->id = $id;
    }
}

class Shop
{
    public $id;

    public function __construct($id = 1)
    {
        $this->id = $id;
    }

    public static function isFeatureActive()
    {
        return true;
    }
}

class Currency
{
    public $id;
    public $iso_code = 'EUR';

    public function __construct($id = null)
    {
        $this->id = $id;
    }
}

class Configuration
{
    public static function get($key)
    {
        return $key === 'PS_LANG_DEFAULT' ? 1 : 0;
    }
}

class SpecificPrice
{
    public static function isFeatureActive()
    {
        return false;
    }
}

class Group
{
    public $id = 1;

    public static function getCurrent()
    {
        return new self();
    }

    public static function getPriceDisplayMethod($id)
    {
        return 0;
    }
}

class Validate
{
    public static function isLoadedObject($object)
    {
        return true;
    }
}

class Link
{
    /** @var array<int, array> the arguments of every call */
    public static $calls = [];

    // As PrestaShop's: an empty $category means the product's own, and the
    // rewrite of a product loaded in all languages is the context language's (1).
    public function getProductLink($product, $alias = null, $category = null, $ean13 = null, $langId = null, $shopId = null, $paId = null)
    {
        self::$calls[] = ['alias' => $alias, 'category' => $category];
        $category = $category ?: $product->category;
        $rewrite = is_array($product->link_rewrite) ? $product->link_rewrite[1] : $product->link_rewrite;

        return 'https://shop' . $shopId . '.test/' . $langId . '/' . ($category ? $category . '/' : '') . $product->id . ($paId ? '-' . $paId : '') . '-' . $rewrite . '.html';
    }
}

class Image
{
    public static function getImages($langId, $productId, $paId = null)
    {
        return [];
    }
}

class ImageType
{
    public static function getFormattedName($type)
    {
        return $type . '_default';
    }
}

class StockAvailable
{
    /** @var array<string, int> "paId-shopId" => quantity */
    public static $quantities = [
        '7-1' => 3, '8-1' => 5, '9-1' => 40,
        '7-2' => 2, '8-2' => 100, '9-2' => 40,
        '0-3' => 9, '7-3' => 70, '8-3' => 70,
    ];

    public static function getQuantityAvailableByProduct($productId, $paId = null, $shopId = null)
    {
        return self::$quantities[(int) $paId . '-' . (int) $shopId] ?? 1;
    }
}

class Manufacturer
{
    public static function getNameById($id)
    {
        return 'Emporiqa test brand';
    }
}

class Db
{
    /** @var array<string, string> "category-lang-shop" => link_rewrite in category_lang */
    public static $categoryRewrites = ['4-1-1' => 'tees', '4-2-1' => 't-shirts', '4-1-2' => 'tees-fr', '4-1-3' => 'tees'];

    /** @var array<int, int[]> product_attribute_shop: combination => shops */
    public static $combinationShops = [7 => [1, 2], 8 => [1], 9 => [4]];

    public static function getInstance()
    {
        return new self();
    }

    public function getValue($sql)
    {
        if (is_string($sql) && preg_match('/`id_category` = (\d+) AND `id_lang` = (\d+) AND `id_shop` = (\d+)/', $sql, $m)) {
            return self::$categoryRewrites[$m[1] . '-' . $m[2] . '-' . $m[3]] ?? false;
        }

        return false;
    }

    public function executeS($sql)
    {
        $rows = [];
        if (strpos($sql, 'product_attribute_shop') !== false) {
            foreach (self::$combinationShops as $paId => $shops) {
                foreach ($shops as $shopId) {
                    $rows[] = ['id_product_attribute' => (string) $paId, 'id_shop' => (string) $shopId];
                }
            }
        }

        return $rows;
    }
}

class Product
{
    /** @var array<int, array{int|false, int}> the combination and shop of each price computed */
    public static $priced = [];
    public $id;
    public $reference = 'TEE';
    public $name = [1 => 'Emporiqa test tee', 2 => 'Emporiqa Test-Shirt'];
    public $description = [1 => 'Soft cotton.', 2 => 'Weiche Baumwolle.'];
    public $link_rewrite = [1 => 'emporiqa-test-tee', 2 => 'emporiqa-test-shirt'];
    public $minimal_quantity = 1;
    public $id_manufacturer = 3;
    public $available_for_order = true;
    public $condition = 'new';
    public $is_virtual = false;
    public $cache_default_attribute = 8;
    public $id_category_default = 4;
    public $category;

    // Loaded in one language, PrestaShop gives the language's values and sets
    // category from the CONTEXT shop's category_lang.
    public function __construct($id = null, $full = false, $langId = null, $shopId = null)
    {
        $this->id = $id;
        if ($langId) {
            $this->name = $this->name[$langId];
            $this->description = $this->description[$langId];
            $this->link_rewrite = $this->link_rewrite[$langId];
            $this->category = 'context-shop-category';
        }
    }

    // As PrestaShop answers in the "all shops" context: every combination with a row in any shop.
    public function getAttributeCombinations($langId)
    {
        $rows = [];
        foreach ([7 => 'M', 8 => 'L', 9 => 'XL'] as $paId => $size) {
            $rows[] = [
                'id_product_attribute' => $paId,
                'group_name' => $langId === 2 ? 'Größe' : 'Size',
                'attribute_name' => $size,
                'reference' => 'TEE-' . $size,
                'minimal_quantity' => 1,
            ];
        }

        return $rows;
    }

    public function getFrontFeatures($langId)
    {
        return [];
    }

    public static function getProductCategories($productId)
    {
        return [];
    }

    public static function getPriceStatic($id, $usetax, $paId = null, $decimals = 6, ...$rest)
    {
        $context = $rest[11] ?? null;
        self::$priced[] = [$paId, $context ? (int) $context->shop->id : 0];

        return 10.0;
    }
}

class EmporiqaChannelResolver
{
    public function getShopContexts()
    {
        $contexts = [];
        foreach ([1 => 'shop-1', 2 => 'shop-2', 3 => 'shop-3'] as $shopId => $key) {
            $contexts[$key] = [
                'shop_id' => $shopId,
                'domain' => 'https://shop' . $shopId . '.test',
                'enabled_languages' => ['en', 'de'],
                'languages' => ['en' => 1, 'de' => 2],
                'currencies' => [['id_currency' => 1, 'iso_code' => 'EUR']],
            ];
        }

        return $contexts;
    }

    public function getProductChannels($productId)
    {
        return ['shop-1', 'shop-2', 'shop-3'];
    }
}

require __DIR__ . '/../classes/EmporiqaProductFormatter.php';

$failures = 0;
function check($label, $ok)
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        ++$failures;
    }
}

function formatter()
{
    EmporiqaProductFormatter::clearBatchCaches();
    $context = new Context();
    $context->shop = new Shop(1);

    return new EmporiqaProductFormatter(new EmporiqaChannelResolver(), $context);
}

$items = [];
foreach (formatter()->format(new Product(12)) as $item) {
    $items[$item['identification_number']] = $item;
}
$parent = $items['product-12'];

echo "Scenario 1: links are the storefront's own, per shop and language\n";
check("with the default category of that shop and language, and the language's rewrite", $parent['links']['shop-1'] === [
    'en' => 'https://shop1.test/1/tees/12-emporiqa-test-tee.html',
    'de' => 'https://shop1.test/2/t-shirts/12-emporiqa-test-shirt.html',
]);
check("another shop's own category rewrite", $parent['links']['shop-2']['en'] === 'https://shop2.test/1/tees-fr/12-emporiqa-test-tee.html');
check('no rewrite in that shop and language: no category segment, not the context shop\'s',
    $parent['links']['shop-2']['de'] === 'https://shop2.test/2/12-emporiqa-test-shirt.html');
check("a combination's link the same way", $items['variation-7']['links']['shop-1']['de'] === 'https://shop1.test/2/t-shirts/12-7-emporiqa-test-shirt.html');
check('no alias or category argument that could disagree with the product', array_unique(array_map('json_encode', Link::$calls)) === ['{"alias":null,"category":null}']);

echo "Scenario 2: a combination goes only to the shops that sell it\n";
check('sold in shops 1 and 2', $items['variation-7']['channels'] === ['shop-1', 'shop-2']);
check('sold in shop 1 only', $items['variation-8']['channels'] === ['shop-1']);
$v8 = $items['variation-8'];
$keys = [];
foreach (['names', 'links', 'attributes', 'prices', 'availability_statuses', 'stock_quantities', 'images', 'min_order_quantities', 'max_order_quantities'] as $field) {
    $keys[$field] = array_keys((array) $v8[$field]);
}
check('and carries no data for any other shop', array_values(array_unique(array_map('json_encode', $keys))) === [json_encode(['shop-1'])]);
check('sold only in a shop that is not synced: not sent', !isset($items['variation-9']));
check('a parent and two combinations', count($items) === 3);

echo "Scenario 3: the parent in each shop\n";
check('stock is what each shop sells', $parent['stock_quantities'] === ['shop-1' => 8, 'shop-2' => 2, 'shop-3' => 9]);
$parentPrices = [];
foreach (Product::$priced as [$paId, $shopId]) {
    $parentPrices[$shopId][] = $paId;
}
check('quotes the default combination where it is sold (shop 1)', ($parentPrices[1][0] ?? null) === 8);
check('else a combination sold there (shop 2)', in_array(7, $parentPrices[2] ?? [], true) && !in_array(8, $parentPrices[2] ?? [], true));
check('option names only where combinations are sold', $parent['variation_attributes']['shop-1']['de'] === ['Größe']
    && $parent['variation_attributes']['shop-3']['en'] === []);

echo "Scenario 4: the stock-only event follows the same rule\n";
$availability = [];
foreach (formatter()->formatAvailability(new Product(12)) as $item) {
    $availability[$item['identification_number']] = array_keys($item['stock_quantities']);
}
check('variation-7 in shops 1 and 2, variation-8 in shop 1, no variation-9', $availability === [
    'variation-7' => ['shop-1', 'shop-2'],
    'variation-8' => ['shop-1'],
]);

if ($failures > 0) {
    echo "\n{$failures} assertion(s) FAILED\n";
    exit(1);
}
echo "\nAll assertions passed\n";
exit(0);
