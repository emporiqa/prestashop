<?php
/**
 * Locks in what a product sync sends:
 *
 * - a combination is a lean row: no descriptions, categories, brands,
 *   variation_attributes or is_parent (Emporiqa takes the first three from
 *   the parent and fixes the other two), and it still carries everything of
 *   its own (names, links, options, prices, stock, images, quantities, flags);
 * - the parent and a simple product keep every field;
 * - floats go out in their shortest form (17.925, not 17.925000000000001)
 *   whatever serialize_precision php.ini sets, and the setting is put back.
 *
 * Self-contained (no PHPUnit, no PrestaShop): PrestaShop classes are stubbed
 * and the real EmporiqaProductFormatter is loaded.
 *
 * Run: php tests/VariationPayloadTest.php
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
    public $id = 1;

    public static function isFeatureActive()
    {
        return false;
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
    public function getProductLink($product, $a = null, $b = null, $c = null, $langId = null, $shopId = null, $paId = 0)
    {
        return 'https://shop.test/p/' . (is_object($product) ? $product->id : $product) . ($paId ? '#' . $paId : '');
    }
}

class Db
{
    public static function getInstance()
    {
        return new self();
    }

    public function getValue($sql)
    {
        return 'tees';
    }

    public function executeS($sql)
    {
        // product_attribute_shop: combination 7 is sold in shop 1
        return [['id_product_attribute' => 7, 'id_shop' => 1]];
    }
}

class Image
{
    public static function getImages($langId, $productId, $paId = null)
    {
        return $paId ? [['id_image' => 31]] : [['id_image' => 30]];
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
    public static function getQuantityAvailableByProduct($productId, $paId = null, $shopId = null)
    {
        return 4;
    }
}

class Manufacturer
{
    public static function getNameById($id)
    {
        return 'Emporiqa test brand';
    }
}

class Product
{
    public static $withCombination = true;
    public $id;
    public $reference = 'TEE';
    public $name = [1 => 'Emporiqa test tee'];
    public $description = [1 => 'Soft cotton.'];
    public $link_rewrite = [1 => 'emporiqa-test-tee'];
    public $minimal_quantity = 1;
    public $id_manufacturer = 3;
    public $available_for_order = true;
    public $condition = 'new';
    public $is_virtual = false;
    public $cache_default_attribute = 7;
    public $id_category_default = 4;
    public $category;

    public function __construct($id = null)
    {
        $this->id = $id;
    }

    public function getAttributeCombinations($langId)
    {
        if (!self::$withCombination) {
            return [];
        }

        return [[
            'id_product_attribute' => 7,
            'group_name' => 'Size',
            'attribute_name' => 'M',
            'reference' => 'TEE-M',
            'minimal_quantity' => 2,
        ]];
    }

    public function getFrontFeatures($langId)
    {
        return [['name' => 'Material', 'value' => 'Cotton']];
    }

    public static function getProductCategories($productId)
    {
        return [];
    }

    public static function getPriceStatic($id, $usetax, $paId = null, $decimals = 6, ...$rest)
    {
        return $usetax ? 21.51 : 17.925;
    }
}

class EmporiqaChannelResolver
{
    public function getShopContexts()
    {
        return ['shop-1' => [
            'shop_id' => 1,
            'domain' => 'https://shop.test',
            'enabled_languages' => ['en'],
            'languages' => ['en' => 1],
            'currencies' => [['id_currency' => 1, 'iso_code' => 'EUR']],
        ]];
    }

    public function getProductChannels($productId)
    {
        return ['shop-1'];
    }
}

require __DIR__ . '/../classes/EmporiqaProductFormatter.php';
require __DIR__ . '/../classes/EmporiqaJsonResponse.php';

$failures = 0;
function check($label, $ok)
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        ++$failures;
    }
}

function formatProduct()
{
    $context = new Context();
    $context->shop = new Shop();

    return (new EmporiqaProductFormatter(new EmporiqaChannelResolver(), $context))->format(new Product(12), 'sess-1');
}

$dropped = ['descriptions', 'categories', 'brands', 'variation_attributes', 'is_parent'];
$parentKeys = [
    'identification_number', 'sku', 'channels', 'names', 'descriptions', 'links', 'attributes',
    'categories', 'brands', 'prices', 'availability_statuses', 'stock_quantities', 'images',
    'min_order_quantities', 'max_order_quantities', 'available_for_order', 'condition', 'is_virtual',
    'parent_sku', 'is_parent', 'variation_attributes', 'sync_session_id',
];

echo "Scenario 1: a combination is sent as a lean row\n";
$items = formatProduct();
check('a parent and one combination', count($items) === 2);
$variation = $items[1];
$leaked = array_values(array_intersect($dropped, array_keys($variation)));
check('the combination has none of the fields Emporiqa takes from the parent' . ($leaked ? ' (has ' . implode(', ', $leaked) . ')' : ''), $leaked === []);
$kept = [
    'identification_number', 'sku', 'channels', 'names', 'links', 'attributes', 'prices',
    'availability_statuses', 'stock_quantities', 'images', 'min_order_quantities',
    'max_order_quantities', 'available_for_order', 'condition', 'is_virtual', 'parent_sku', 'sync_session_id',
];
check('and every field of its own', array_diff($kept, array_keys($variation)) === [] && array_diff(array_keys($variation), $kept) === []);
check('its id, sku and parent', $variation['identification_number'] === 'variation-7' && $variation['sku'] === 'TEE-M' && $variation['parent_sku'] === 'TEE');
check('its name carries its option', $variation['names']['shop-1']['en'] === 'Emporiqa test tee - M');
check('its options are its attributes', $variation['attributes']['shop-1']['en'] === ['Size' => 'M']);
check('its link opens it', $variation['links']['shop-1']['en'] === 'https://shop.test/p/12#7');
check('its own image', $variation['images']['shop-1'] === ['https://shop.test/img/p/3/1/31-large_default.jpg']);
check('its price, stock and minimum', $variation['prices']['shop-1'][0]['current_price'] === 21.51
    && $variation['stock_quantities']['shop-1'] === 4 && $variation['min_order_quantities']['shop-1'] === 2);

echo "Scenario 2: the parent keeps every field\n";
$parent = $items[0];
check('all parent fields present', array_diff($parentKeys, array_keys($parent)) === [] && array_diff(array_keys($parent), $parentKeys) === []);
check('description, brand and variation attributes', $parent['descriptions']['shop-1']['en'] === 'Soft cotton.'
    && $parent['brands']['shop-1'] === 'Emporiqa test brand'
    && $parent['is_parent'] === true && $parent['variation_attributes']['shop-1']['en'] === ['Size']);

echo "Scenario 3: a simple product keeps every field\n";
Product::$withCombination = false;
$items = formatProduct();
Product::$withCombination = true;
check('one item', count($items) === 1);
check('all fields present', array_diff($parentKeys, array_keys($items[0])) === [] && array_diff(array_keys($items[0]), $parentKeys) === []);
check('not a parent', $items[0]['is_parent'] === false && $items[0]['parent_sku'] === null);

echo "Scenario 4: floats are encoded in their shortest form\n";
ini_set('serialize_precision', '17');
$json = EmporiqaJsonResponse::encode(['price' => 17.925, 'sum' => 0.1 + 0.2, 'qty' => 50]);
check('17.925 and 0.30000000000000004 as PHP holds them, without noise digits', $json === '{"price":17.925,"sum":0.30000000000000004,"qty":50}');
check('serialize_precision is put back', ini_get('serialize_precision') === '17');

if ($failures > 0) {
    echo "\n{$failures} assertion(s) FAILED\n";
    exit(1);
}
echo "\nAll assertions passed\n";
exit(0);
