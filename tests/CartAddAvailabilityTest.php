<?php
/**
 * Locks in what the chat's add to cart refuses, as PrestaShop's own cart
 * controller does.
 *
 * What must never regress: a product is added only when it is active, sold
 * in the current shop, visible to the shopper's customer group, available
 * for order and not hidden everywhere, when the combination asked for
 * belongs to that product in this shop, and while the stock covers what the
 * cart already holds plus the new quantity (unless the product may be
 * ordered out of stock). A refused add never reaches Cart::updateQty. Without
 * this a shopper could put into their cart a product their group may not
 * buy, which checkout does not check again.
 *
 * Self-contained (no PHPUnit, no PrestaShop): PrestaShop classes are stubbed
 * and the real EmporiqaCartHandler is loaded.
 *
 * Run: php tests/CartAddAvailabilityTest.php
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
define('_PS_VERSION_', '8.1.0');

class Fixtures
{
    /** @var array<int, array<string, mixed>> product id => fields and stub answers */
    public static $products = [];

    /** @var array<int, int> combination id => stock */
    public static $combinationStock = [];

    /** @var bool the shop's "allow orders out of stock" answer for every product */
    public static $orderOutOfStock = false;
}

class Product
{
    public $id;
    public $active = true;
    public $available_for_order = true;
    public $visibility = 'both';
    public $shop = true;
    public $groups = [];

    public function __construct($id)
    {
        foreach (Fixtures::$products[(int) $id] ?? [] as $name => $value) {
            if (property_exists($this, $name)) {
                $this->$name = $value;
            }
        }
    }

    public function isAssociatedToShop()
    {
        return $this->shop;
    }

    public function checkAccess($idCustomer)
    {
        return !$this->groups || in_array($idCustomer, $this->groups, true);
    }

    public static function getProductAttributesIds($idProduct, $shopOnly = false)
    {
        $ids = Fixtures::$products[(int) $idProduct][$shopOnly ? 'shop_combinations' : 'combinations'] ?? [];

        return array_map(function ($id) {
            return ['id_product_attribute' => (string) $id];
        }, $ids);
    }

    public static function isAvailableWhenOutOfStock($outOfStock)
    {
        return $outOfStock;
    }

    public static function getQuantity($idProduct)
    {
        return Fixtures::$products[(int) $idProduct]['stock'] ?? 0;
    }
}

class ProductAttribute
{
    public static function checkAttributeQty($idProductAttribute, $qty)
    {
        return $qty <= (Fixtures::$combinationStock[(int) $idProductAttribute] ?? 0);
    }
}

class StockAvailable
{
    public static function outOfStock($idProduct)
    {
        return Fixtures::$orderOutOfStock;
    }
}

class Validate
{
    public static function isLoadedObject($object)
    {
        return is_object($object) && !empty($object->id);
    }
}

class Cart
{
    public $id = 9;
    public $id_customer = 0;
    public $id_currency = 1;

    /** @var array<string, int> "product-combination" => quantity */
    public $lines = [];

    /** @var array[] updateQty calls */
    public $updates = [];

    public function getProductQuantity($idProduct, $idProductAttribute = 0)
    {
        return ['quantity' => $this->lines[$idProduct . '-' . (int) $idProductAttribute] ?? 0];
    }

    public function updateQty($quantity, $idProduct, $idProductAttribute = null)
    {
        $this->updates[] = [$quantity, $idProduct, $idProductAttribute];

        return true;
    }

    public function getProducts()
    {
        return [];
    }

    public function getOrderTotal()
    {
        return 0.0;
    }
}

class Currency
{
    public $id = 1;
    public $iso_code = 'EUR';
}

class Link
{
    public function getPageLink($page)
    {
        return 'https://shop.test/' . $page;
    }
}

class Context
{
    public $cart;
    public $link;
}

require __DIR__ . '/../classes/EmporiqaCartHandler.php';

$failures = 0;
function check($label, $ok)
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        ++$failures;
    }
}

function add(array $product, $variation = '', $quantity = 1, array $cart = [])
{
    Fixtures::$products = [5 => $product + ['id' => 5, 'stock' => 10]];
    $context = new Context();
    $context->cart = new Cart();
    foreach ($cart as $name => $value) {
        $context->cart->$name = $value;
    }
    $context->link = new Link();
    $answer = (new EmporiqaCartHandler($context))->add('product-5', $variation, $quantity);

    return [$answer, $context->cart->updates];
}

function refused(array $result)
{
    return $result[0]['success'] === false && $result[1] === [];
}

function added(array $result)
{
    return $result[0]['success'] === true && count($result[1]) === 1;
}

echo "Scenario 1: a product the shop sells to everyone, in stock\n";
check('is added', added(add([])));

echo "Scenario 2: what PrestaShop's cart refuses\n";
check('not sold in this shop', refused(add(['shop' => false])));
check('not for this customer group', refused(add(['groups' => [42]], '', 1, ['id_customer' => 7])));
check('that group\'s own customer may add it', added(add(['groups' => [42]], '', 1, ['id_customer' => 42])));
check('not available for order', refused(add(['available_for_order' => false])));
check('hidden everywhere', refused(add(['visibility' => 'none'])));
check('catalog only is still added', added(add(['visibility' => 'catalog'])));
check('inactive', refused(add(['active' => false])));

echo "Scenario 3: the combination must be this product's, in this shop\n";
Fixtures::$combinationStock = [11 => 5, 12 => 5, 99 => 5];
$combinations = ['combinations' => [11, 12], 'shop_combinations' => [11]];
check('its own combination is added', added(add($combinations, 'variation-11')));
check('a combination this shop does not sell', refused(add($combinations, 'variation-12')));
check('another product\'s combination', refused(add($combinations, 'variation-99')));
check('a combination on a product without any', refused(add([], 'variation-99')));

echo "Scenario 4: stock, counting what the cart already holds\n";
Fixtures::$orderOutOfStock = false;
check('within stock', added(add(['stock' => 3], '', 3)));
check('over stock', refused(add(['stock' => 3], '', 4)));
check('over stock with the cart line', refused(add(['stock' => 3], '', 2, ['lines' => ['5-0' => 2]])));
check('a combination over its stock', refused(add($combinations, 'variation-11', 6)));
check('a combination over its stock with the cart line',
    refused(add($combinations, 'variation-11', 1, ['lines' => ['5-11' => 5]])));

echo "Scenario 5: a product that may be ordered out of stock\n";
Fixtures::$orderOutOfStock = true;
check('is added past its stock', added(add(['stock' => 0], '', 4)));
check('a combination too', added(add($combinations, 'variation-11', 50)));

if ($failures > 0) {
    echo "\n{$failures} assertion(s) FAILED\n";
    exit(1);
}
echo "\nAll assertions passed\n";
