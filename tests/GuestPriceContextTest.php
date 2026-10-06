<?php
/**
 * Locks in whose price a product sync publishes:
 *
 * - every price is computed for an anonymous visitor in the shop's default
 *   country, whatever customer, cart or country the request that triggered
 *   the sync carries (a storefront request with a signed-in customer would
 *   otherwise publish their group discount and their negotiated prices);
 * - a sync with no employee and no cart (a CLI import) still gets a cart, so
 *   getPriceStatic does not throw;
 * - the request's own customer, cart and country are back afterwards, even
 *   when pricing throws;
 * - a combination with no row in a shop gets no price there instead of 0,
 *   and the parent falls back to the shop's base price;
 * - a tier's unit price has the precision the cart multiplies by, so the
 *   quote for a quantity equals the cart total: 6 decimals when the shop
 *   rounds per line or total (50 x 17.925 = 896.25), the cent when it rounds
 *   each item (50 x 17.93 = 896.50);
 * - current_price, regular_price and the tiers are what the shop displays to
 *   the visitor group: tax excluded when that group shows prices without tax.
 *
 * Self-contained (no PHPUnit, no PrestaShop): PrestaShop classes are stubbed
 * and the real EmporiqaProductFormatter is loaded.
 *
 * Run: php tests/GuestPriceContextTest.php
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
define('_PS_VERSION_', '8.1.0');
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
    public $id;

    public function __construct($id = null)
    {
        $this->id = $id;
    }
}

class Cart
{
    public $id;

    public function __construct($id = null)
    {
        $this->id = $id;
    }
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
    public $iso_code;

    public function __construct($id = null)
    {
        $this->id = $id;
        $this->iso_code = 'EUR';
    }
}

class Configuration
{
    public static $roundType = 2;

    public static function get($key)
    {
        if ($key === 'PS_ROUND_TYPE') {
            return self::$roundType;
        }

        return $key === 'PS_COUNTRY_DEFAULT' ? 21 : 0;
    }
}

class Order
{
    public const ROUND_ITEM = 1;
}

class SpecificPrice
{
    public static $active = false;
    /** @var array<int, array{from_quantity: int}> */
    public static $discounts = [];

    public static function isFeatureActive()
    {
        return self::$active;
    }

    public static function getQuantityDiscounts(...$args)
    {
        return self::$discounts;
    }
}

class Group
{
    public static $displayMethod = 0;
    public $id = 1;

    public static function getCurrent()
    {
        return new self();
    }

    public static function getPriceDisplayMethod($id)
    {
        return self::$displayMethod;
    }
}

class Product
{
    /** @var array<int, array{customer: mixed, cart: mixed, country: mixed, paId: mixed}> */
    public static $calls = [];
    /** @var callable */
    public static $price;

    public static function getPriceStatic($id, $usetax, $paId, $decimals = 6, $idProductAttributeGroup = null, $onlyReduc = false, $useReduc = true, $quantity = 1, ...$rest)
    {
        $global = Context::getContext();
        self::$calls[] = [
            'customer' => $global->customer,
            'cart' => $global->cart,
            'country' => $global->country,
            'paId' => $paId,
        ];

        return (self::$price)($paId, $usetax, $decimals, $quantity);
    }
}

class EmporiqaChannelResolver
{
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

function prices($productId, $paId, $isParent)
{
    $formatter = new EmporiqaProductFormatter(new EmporiqaChannelResolver(), Context::getContext());
    $method = new ReflectionMethod($formatter, 'buildPriceEntries');
    $method->setAccessible(true);

    return $method->invoke($formatter, $productId, $paId, [['id_currency' => 1, 'iso_code' => 'EUR']], 1, $isParent);
}

function storefrontRequest()
{
    $ctx = new Context();
    $ctx->customer = new Customer(42);
    $ctx->cart = new Cart(7);
    $ctx->country = new Country(1);
    $ctx->currency = new Currency(1);
    $ctx->shop = new Shop();
    Context::$instance = $ctx;
    Product::$calls = [];

    return $ctx;
}

echo "Scenario 1: a signed-in customer's request publishes the visitor price\n";
$ctx = storefrontRequest();
[$customer, $cart, $country] = [$ctx->customer, $ctx->cart, $ctx->country];
Product::$price = function () {
    return 10.0;
};
$entries = prices(1, null, false);
check('one entry', count($entries) === 1 && $entries[0]['current_price'] === 10.0);
$visitorOnly = true;
foreach (Product::$calls as $call) {
    $visitorOnly = $visitorOnly
        && $call['customer'] instanceof Customer && $call['customer']->id === null
        && $call['cart'] instanceof Cart && $call['cart']->id === null
        && $call['country'] instanceof Country && $call['country']->id === 21;
}
check('every price was computed as an anonymous visitor in the default country', $visitorOnly && count(Product::$calls) === 3);
check('the request keeps its customer, cart and country',
    $ctx->customer === $customer && $ctx->cart === $cart && $ctx->country === $country);

echo "Scenario 2: a CLI sync with no cart still gets one\n";
$ctx = storefrontRequest();
$ctx->customer = null;
$ctx->cart = null;
prices(1, null, false);
check('a cart is present while pricing', Product::$calls[0]['cart'] instanceof Cart);
check('and gone again afterwards', $ctx->cart === null && $ctx->customer === null);

echo "Scenario 3: the request's context is restored when pricing throws\n";
$ctx = storefrontRequest();
$customer = $ctx->customer;
Product::$price = function () {
    throw new RuntimeException('boom');
};
try {
    prices(1, null, false);
    check('threw', false);
} catch (RuntimeException $e) {
    check('threw', true);
}
check('customer restored', $ctx->customer === $customer);

echo "Scenario 4: a combination missing from a shop is never priced 0\n";
storefrontRequest();
Product::$price = function ($paId) {
    return $paId === false ? 23.9 : null;
};
check('a combination gets no entry', prices(1, 5, false) === []);
$parent = prices(1, 5, true);
check('the parent shows the shop base price', count($parent) === 1 && $parent[0]['current_price'] === 23.9);

echo "Scenario 5: a tier unit price is not rounded to the cent\n";
storefrontRequest();
SpecificPrice::$active = true;
SpecificPrice::$discounts = [['from_quantity' => 50]];
Product::$price = function ($paId, $usetax, $decimals, $quantity) {
    return round($quantity >= 50 ? 17.925 : 19.9, $decimals);
};
$entries = prices(1, null, false);
check('the qty=1 price is the storefront price', $entries[0]['current_price'] === 19.9);
$tier = $entries[0]['tier_prices'][0] ?? null;
check('the tier keeps 17.925', $tier !== null && $tier['min_quantity'] === 50 && abs($tier['price'] - 17.925) < 1e-9);
check('50 of them cost what the cart charges', $tier !== null && round(50 * $tier['price'], 2) === 896.25);
Configuration::$roundType = Order::ROUND_ITEM;
$tier = prices(1, null, false)[0]['tier_prices'][0] ?? null;
check('a shop rounding each item gets the cent its cart multiplies', $tier !== null && round(50 * $tier['price'], 2) === 896.5);
Configuration::$roundType = 2;
SpecificPrice::$active = false;
SpecificPrice::$discounts = [];

echo "Scenario 6: a visitor group shown prices tax excluded gets them so\n";
storefrontRequest();
Group::$displayMethod = PS_TAX_EXC;
SpecificPrice::$active = true;
SpecificPrice::$discounts = [['from_quantity' => 10]];
Product::$price = function ($paId, $usetax, $decimals, $quantity) {
    return ($quantity >= 10 ? 20.0 : 24.0) * ($usetax ? 1.2 : 1.0);
};
$entries = prices(1, null, false);
check('current_price is tax excluded', $entries[0]['current_price'] === 24.0);
check('regular_price too', $entries[0]['regular_price'] === 24.0);
check('both breakdowns are kept', abs($entries[0]['price_incl_tax'] - 28.8) < 1e-9 && $entries[0]['price_excl_tax'] === 24.0);
check('the tier is tax excluded', ($entries[0]['tier_prices'][0]['price'] ?? null) === 20.0);
Group::$displayMethod = 0;
SpecificPrice::$active = false;
SpecificPrice::$discounts = [];

if ($failures > 0) {
    echo "\n{$failures} assertion(s) FAILED\n";
    exit(1);
}
echo "\nAll assertions passed\n";
exit(0);
