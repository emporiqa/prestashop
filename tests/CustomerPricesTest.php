<?php
/**
 * Locks in the `customer_prices` action:
 *
 * - every price is computed by PrestaShop as the signed-in customer (their
 *   group, their own prices, the requested country), on an empty cart, and
 *   the request's own customer, cart and country are back afterwards, also
 *   when pricing throws;
 * - an unknown, disabled, deleted or guest customer, or one of another shop,
 *   is not_found; a missing customer, a channel the module does not sync,
 *   or no ids / more than 20 are rejected before anything is priced;
 * - a product the customer cannot see or buy (inactive, hidden, outside
 *   their groups' categories, price not shown) and an unknown id are left
 *   out, never reported;
 * - a product asked for by id answers its default combination and its
 *   combinations; a variation id answers that combination;
 * - the answer carries prices only (no name, email or group), in the
 *   group's display mode, the tiers what the cart charges; every product
 *   and variation entry says whether its prices include tax, and the
 *   top-level flag is true only when every entry's is;
 * - limits: 30 calls per customer and 600 per shop in 10 minutes.
 *
 * Self-contained (no PHPUnit, no PrestaShop): PrestaShop classes are stubbed
 * and the real EmporiqaCustomerPrices, EmporiqaProductFormatter and
 * EmporiqaOrderStatus (the shared rate limiter) are loaded.
 *
 * Run: php tests/CustomerPricesTest.php
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
    public static $instance;
    public $customer;
    public $cart;
    public $country;
    public $currency;
    public $shop;

    public static function getContext()
    {
        return self::$instance;
    }

    public function cloneContext()
    {
        return clone $this;
    }
}

class Customer
{
    /** @var array<int, array<string, mixed>> */
    public static $rows = [];
    public $id;
    public $active = 1;
    public $deleted = 0;
    public $is_guest = 0;
    public $id_shop = 1;
    public $id_default_group = 1;

    public function __construct($id = null)
    {
        if ($id !== null && isset(self::$rows[$id])) {
            $this->id = $id;
            foreach (self::$rows[$id] as $field => $value) {
                $this->$field = $value;
            }
        }
    }
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

    public static function getByIso($iso, $active = false)
    {
        return ['DE' => 1, 'US' => 21][$iso] ?? false;
    }
}

class Address
{
    public $id_country = 8;

    public static function getFirstCustomerAddressId($idCustomer)
    {
        return $idCustomer === 77 ? 5 : 0;
    }
}

class Shop
{
    public const SHARE_CUSTOMER = 'share_customer';
    public $id = 1;

    public static function isFeatureActive()
    {
        return false;
    }

    public static function getSharedShops($shopId, $type)
    {
        return [$shopId];
    }
}

class Currency
{
    public $id;
    public $iso_code;

    public function __construct($id = null)
    {
        $this->id = $id;
        $this->iso_code = $id === 2 ? 'USD' : 'EUR';
    }
}

class Configuration
{
    public static $values = [];

    public static function get($key, $idLang = null, $idShopGroup = null, $idShop = null)
    {
        return self::$values[$key] ?? ['PS_COUNTRY_DEFAULT' => 21, 'PS_CURRENCY_DEFAULT' => 1][$key] ?? false;
    }

    public static function showPrices()
    {
        return true;
    }
}

class Order
{
    public const ROUND_ITEM = 1;
}

class Tax
{
    public static function excludeTaxeOption()
    {
        return false;
    }
}

class Group
{
    public $id;

    public static function getCurrent()
    {
        $group = new self();
        $customer = Context::getContext()->customer;
        $group->id = ($customer && $customer->id) ? $customer->id_default_group : 1;

        return $group;
    }

    public static function getPriceDisplayMethod($id)
    {
        return $id === 4 ? PS_TAX_EXC : 0;
    }
}

class SpecificPrice
{
    public static function isFeatureActive()
    {
        return true;
    }

    public static function getQuantityDiscounts($idProduct, $idShop, $idCurrency, $idCountry, $idGroup, $paId, $all, $idCustomer)
    {
        return $idProduct === 10 ? [['from_quantity' => 10]] : [];
    }
}

class Validate
{
    public static function isLoadedObject($object)
    {
        return $object !== null && !empty($object->id);
    }
}

class Product
{
    /** @var array<int, array<string, mixed>> */
    public static $rows = [];
    /** @var array<int, array> */
    public static $calls = [];
    public static $throw = false;
    public $id;
    public $active = 1;
    public $visibility = 'both';
    public $show_price = 1;
    public $available_for_order = 1;
    public $cache_default_attribute = 0;
    public $name = 'Secret product name';

    public function __construct($id = null, $full = false, $idLang = null, $idShop = null)
    {
        if (isset(self::$rows[$id])) {
            $this->id = $id;
            foreach (self::$rows[$id] as $field => $value) {
                $this->$field = $value;
            }
        }
    }

    public function isAssociatedToShop($idShop)
    {
        return true;
    }

    public static function checkAccessStatic($idProduct, $idCustomer)
    {
        return $idProduct !== 13;
    }

    /**
     * 100 for a visitor; customer 77 has their own price of 80; the tier at
     * 10+ is 10% off; tax 20%; combination 21 adds 5. Records the global
     * context of each call.
     */
    public static function getPriceStatic($id, $usetax, $paId = null, $decimals = 6, $divisor = null, $onlyReduc = false, $useReduc = true, $quantity = 1, $forceTax = false, $idCustomer = null, $idCart = null, $idAddress = null, &$specific = null, $ecotax = true, $groupReduction = true, $context = null)
    {
        if (self::$throw) {
            throw new RuntimeException('boom');
        }
        $global = Context::getContext();
        self::$calls[] = [
            'customer' => $global->customer ? $global->customer->id : null,
            'cart' => $global->cart,
            'country' => $global->country ? $global->country->id : null,
            'currency' => $context ? $context->currency->id : null,
            'address' => $idAddress,
        ];
        $base = ($global->customer && $global->customer->id === 77 && $useReduc) ? 80.0 : 100.0;
        if ($paId === 21) {
            $base += 5;
        }
        if ($quantity >= 10 && $useReduc && $id === 10) {
            $base *= 0.9;
        }

        return round($base * ($usetax ? 1.2 : 1.0), $decimals);
    }
}

class Db
{
    /** @var array<string, int> */
    public static $hits = [];

    public static function getInstance()
    {
        return new self();
    }

    public function getValue($sql, $useCache = true)
    {
        if (preg_match('/`bucket_hash` = "([0-9a-f]{64})"/', $sql, $m)) {
            return self::$hits[$m[1]] ?? 0;
        }
        if (strpos($sql, 'product_attribute` WHERE `id_product_attribute` = 21') !== false) {
            return 20;
        }
        if (strpos($sql, 'FROM `ps_address`') !== false) {
            return strpos($sql, '`id_country` = 1 ') !== false ? 9 : 0;
        }

        return false;
    }

    public function executeS($sql)
    {
        return strpos($sql, '`id_product` = 20 ') !== false
            ? [['id_product_attribute' => 21], ['id_product_attribute' => 22]]
            : [];
    }

    public function execute($sql)
    {
        if (preg_match('/VALUES \("([0-9a-f]{64})"/', $sql, $m)) {
            self::$hits[$m[1]] = (self::$hits[$m[1]] ?? 0) + 1;
        }

        return true;
    }
}

function pSQL($value)
{
    return addslashes((string) $value);
}

class Tools
{
    public static function strtolower($value)
    {
        return strtolower($value);
    }
}

class EmporiqaChannelResolver
{
    public function getShopContexts()
    {
        return ['shop-a' => ['shop_id' => 1, 'currencies' => [
            ['id_currency' => 1, 'iso_code' => 'EUR'],
            ['id_currency' => 2, 'iso_code' => 'USD'],
        ]]];
    }

    public function getCurrentChannelKey()
    {
        return 'shop-a';
    }
}

require __DIR__ . '/../classes/EmporiqaProductFormatter.php';
require __DIR__ . '/../classes/EmporiqaOrderStatus.php';
require __DIR__ . '/../classes/EmporiqaCustomerPrices.php';

$failures = 0;
function check($label, $ok)
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        ++$failures;
    }
}

function request()
{
    $ctx = new Context();
    $ctx->customer = new Customer();
    $ctx->cart = new Cart();
    $ctx->country = new Country(21);
    $ctx->currency = new Currency(1);
    $ctx->shop = new Shop();
    Context::$instance = $ctx;
    Product::$calls = [];

    return $ctx;
}

function ask(array $payload)
{
    return (new EmporiqaCustomerPrices(Context::getContext()))->handle($payload + [
        'rule' => 'customer_prices',
        'request_id' => 'r1',
        'channel' => 'shop-a',
        'customer' => ['id' => '77'],
    ]);
}

Customer::$rows = [
    77 => ['id_default_group' => 3],
    78 => ['active' => 0],
    79 => ['deleted' => 1],
    80 => ['is_guest' => 1],
    81 => ['id_shop' => 2],
    82 => ['id_default_group' => 4],
];
Product::$rows = [
    10 => [],
    11 => ['active' => 0],
    12 => ['visibility' => 'none'],
    13 => [],
    14 => ['show_price' => 0, 'available_for_order' => 0],
    20 => ['cache_default_attribute' => 22],
];

echo "Scenario 1: prices are computed as the customer, and the request keeps its own context\n";
$ctx = request();
[$customer, $cart, $country] = [$ctx->customer, $ctx->cart, $ctx->country];
$answer = ask(['products' => ['product-10'], 'currency' => 'EUR', 'country' => 'DE']);
$p = $answer['data']['products']['product-10'] ?? null;
check('found', $answer['status'] === 'found' && $p !== null);
check('their own price, tax included as their group displays it', $p['current_price'] === 96.0
    && $p['price_incl_tax'] === 96.0 && $p['price_excl_tax'] === 80.0 && $answer['data']['prices_include_tax'] === true);
check('the regular price is the undiscounted one', $p['regular_price'] === 120.0);
check('the tier is what the cart charges at 10', $p['tier_prices'] === [['min_quantity' => 10, 'price' => 86.4]]);
$asCustomer = true;
foreach (Product::$calls as $call) {
    $asCustomer = $asCustomer && $call['customer'] === 77 && $call['cart'] instanceof Cart && $call['cart'] !== $cart
        && $call['country'] === 1 && $call['address'] === 9;
}
check('every call ran as customer 77, an empty cart, the requested country and their address there', $asCustomer);
check('the request keeps its customer, cart and country',
    $ctx->customer === $customer && $ctx->cart === $cart && $ctx->country === $country);
check('no name, email or group in the answer', strpos(json_encode($answer), 'Secret') === false
    && array_keys($p) === ['current_price', 'regular_price', 'price_incl_tax', 'price_excl_tax', 'tier_prices', 'prices_include_tax']);
check('the entry says its prices include tax', $p['prices_include_tax'] === true);

echo "Scenario 2: the context is restored when pricing throws\n";
$ctx = request();
$customer = $ctx->customer;
Product::$throw = true;
try {
    ask(['products' => ['product-10']]);
    check('threw', false);
} catch (RuntimeException $e) {
    check('threw', true);
}
Product::$throw = false;
check('customer restored', $ctx->customer === $customer);

echo "Scenario 3: who is not found, and what is rejected\n";
request();
foreach ([78 => 'disabled', 79 => 'deleted', 80 => 'a guest', 81 => 'of another shop', 999 => 'unknown'] as $id => $label) {
    check($label . ' is not_found', ask(['customer' => ['id' => (string) $id], 'products' => ['product-10']]) === ['status' => 'not_found']);
}
check('no customer is rejected', ask(['customer' => [], 'products' => ['product-10']])['status'] === 'rejected');
check('no ids are rejected', ask(['products' => []])['status'] === 'rejected');
check('21 ids are rejected', ask(['products' => array_fill(0, 21, 'product-10')])['status'] === 'rejected');
check('an unsynced channel is rejected', ask(['channel' => 'other', 'products' => ['product-10']])['status'] === 'rejected');
check('nothing was priced', Product::$calls === []);

echo "Scenario 4: what the customer cannot see or buy is left out\n";
request();
$answer = ask(['products' => ['product-11', 'product-12', 'product-13', 'product-14', 'product-999', 'nonsense', 'product-10']]);
check('only the visible product answers', array_keys((array) $answer['data']['products']) === ['product-10']);
$answer = ask(['products' => ['product-11']]);
check('none visible is an empty object, not an error', $answer['status'] === 'found' && $answer['data']['products'] == new stdClass());

echo "Scenario 5: combinations\n";
request();
$answer = ask(['products' => ['product-20', 'variation-21']]);
$parent = $answer['data']['products']['product-20'];
check('the parent quotes its default combination', $parent['current_price'] === 96.0);
check('and lists its combinations', array_keys($parent['variations']) === ['variation-21', 'variation-22']
    && $parent['variations']['variation-21']['current_price'] === 102.0);
check('a variation id answers that combination', $answer['data']['products']['variation-21']['current_price'] === 102.0);

echo "Scenario 6: currency, country and display fallbacks\n";
request();
$answer = ask(['products' => ['product-10'], 'currency' => 'USD']);
check('a currency the shop sells in', $answer['data']['currency'] === 'USD' && Product::$calls[0]['currency'] === 2);
Product::$calls = [];
$answer = ask(['products' => ['product-10'], 'currency' => 'JPY', 'country' => 'XX']);
check('else the shop default currency', $answer['data']['currency'] === 'EUR');
check('else the country of their first address', Product::$calls[0]['country'] === 8);
$answer = ask(['customer' => ['id' => '82'], 'products' => ['product-10']]);
check('a group shown prices tax excluded gets them so', $answer['data']['prices_include_tax'] === false
    && $answer['data']['products']['product-10']['current_price'] === 100.0
    && $answer['data']['products']['product-10']['prices_include_tax'] === false);
$answer = ask(['customer' => ['id' => '82'], 'products' => ['product-20']]);
check('so do its variations', $answer['data']['products']['product-20']['variations']['variation-21']['prices_include_tax'] === false);

echo "Scenario 7: limits\n";
Db::$hits = [];
$limited = null;
for ($i = 1; $i <= 31; ++$i) {
    $limited = EmporiqaCustomerPrices::rateLimitHit(['customer' => ['id' => '77']], 1);
    if ($i === 30) {
        check('30 calls for one customer pass', $limited === null);
    }
}
check('the 31st is limited for that customer', $limited !== null && $limited['scope'] === 'value' && $limited['retry_after'] > 0);
check('another customer still passes', EmporiqaCustomerPrices::rateLimitHit(['customer' => ['id' => '78']], 1) === null);
for ($i = 0; $i <= 600; ++$i) {
    $limited = EmporiqaCustomerPrices::rateLimitHit(['customer' => ['id' => (string) (1000 + $i)]], 2);
}
check('the shop ceiling holds everyone back', $limited !== null && $limited['scope'] === 'store');

if ($failures > 0) {
    echo "\n{$failures} assertion(s) FAILED\n";
    exit(1);
}
echo "\nAll assertions passed\n";
exit(0);
