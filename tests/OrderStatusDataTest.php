<?php
/**
 * Locks in the `data` of a found Order status answer (1.3.2):
 *
 * - every order detail is filled from the order: reference, customer name,
 *   currency, items (name as ordered, sku, quantity, unit and line price,
 *   combination), totals with the discount, payment, carrier, delivery time
 *   and both addresses, empty parts left out;
 * - amounts are tax included unless the customer's group sees prices tax
 *   excluded, and `total` is always what is paid;
 * - a split checkout reports every order's items and the summed totals;
 * - gift wrapping is `fees`, and subtotal - discount + shipping + fees is
 *   the total (plus tax when prices are shown tax excluded);
 * - a missing address is left out, not sent empty;
 * - the actionEmporiqaOrderStatus hook runs after the filling, so a module
 *   can change a key and add its own under `extra`;
 * - no email, customer id or other internal id is in the answer;
 * - the proof is unchanged: another customer's id finds nothing.
 *
 * Self-contained (no PHPUnit, no PrestaShop): the core classes it touches
 * are stubs fed from the arrays below.
 *
 * Run: php tests/OrderStatusDataTest.php
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
define('_PS_VERSION_', '8.1.0');
define('_DB_PREFIX_', 'ps_');
define('PS_TAX_EXC', 1);
define('PS_TAX_INC', 0);

class Fixtures
{
    /** @var array<int, array> order id => order fields */
    public static $orders = [];
    /** @var array<int, array> order id => order_detail rows */
    public static $lines = [];
    public static $addresses = [];
    public static $customers = [];
    public static $carriers = [];
    public static $productNames = [];
    public static $attributeRows = [];
    public static $hook;
}

class Context
{
    public $shop;
}

class Configuration
{
    public static function get($key)
    {
        $values = ['PS_OS_SHIPPING' => 4, 'PS_OS_PAYMENT' => 2, 'PS_OS_CANCELED' => 6, 'PS_SHOP_NAME' => 'Shop'];

        return $values[$key] ?? false;
    }
}

class Tools
{
    public static function strlen($value)
    {
        return strlen($value);
    }

    public static function strtolower($value)
    {
        return strtolower($value);
    }
}

class Validate
{
    public static function isLoadedObject($object)
    {
        return is_object($object) && !empty($object->id);
    }

    public static function isEmail($email)
    {
        return strpos($email, '@') !== false;
    }
}

class Db
{
    public static function getInstance()
    {
        return new self();
    }

    public function executeS($sql)
    {
        if (strpos($sql, 'SHOW TABLES') === 0) {
            return [];
        }
        if (strpos($sql, 'product_attribute_combination') !== false
            && preg_match('/id_product_attribute` = (\d+)/', $sql, $m)) {
            return Fixtures::$attributeRows[(int) $m[1]] ?? [];
        }

        return [];
    }
}

class Hook
{
    public static function exec($name, array $params)
    {
        if ($name === 'actionEmporiqaOrderStatus' && Fixtures::$hook) {
            (Fixtures::$hook)($params);
        }
    }
}

class EmporiqaChannelResolver
{
    public function __construct($context)
    {
    }

    public function getMapping()
    {
        return [1 => 'shop-1'];
    }
}

class Order
{
    public $id;
    public $id_shop = 1;
    public $id_lang = 1;
    public $id_customer;
    public $id_currency = 1;
    public $id_carrier = 3;
    public $id_address_delivery;
    public $id_address_invoice;
    public $reference;
    public $date_add;
    public $payment;
    public $state = 4;
    public $tax_method = PS_TAX_INC;
    public $paid = false;
    public $total_products;
    public $total_products_wt;
    public $total_shipping_tax_incl;
    public $total_shipping_tax_excl;
    public $total_discounts_tax_incl = 0;
    public $total_discounts_tax_excl = 0;
    public $total_wrapping_tax_incl = 0;
    public $total_wrapping_tax_excl = 0;
    public $total_paid_tax_incl;
    public $total_paid_tax_excl;

    public function __construct($id = null)
    {
        foreach (Fixtures::$orders[(int) $id] ?? [] as $name => $value) {
            $this->$name = $value;
        }
    }

    public static function getByReference($reference)
    {
        $found = [];
        foreach (Fixtures::$orders as $id => $fields) {
            if ($fields['reference'] === $reference) {
                $found[] = new self($id);
            }
        }

        return $found;
    }

    public function getCurrentState()
    {
        return $this->state;
    }

    public function getIdOrderCarrier()
    {
        return 0;
    }

    public function getProductsDetail()
    {
        return Fixtures::$lines[(int) $this->id] ?? [];
    }

    public function getTaxCalculationMethod()
    {
        return $this->tax_method;
    }

    public function hasBeenPaid()
    {
        return $this->paid ? 1 : 0;
    }
}

class OrderState
{
    public $id;
    public $name;
    public $shipped = false;
    public $paid = false;

    public function __construct($id = null, $langId = null)
    {
        $states = [4 => ['Shipped', true, true], 2 => ['Payment accepted', false, true], 10 => ['Awaiting bank wire', false, false], 6 => ['Canceled', false, false]];
        if (isset($states[(int) $id])) {
            $this->id = (int) $id;
            [$this->name, $this->shipped, $this->paid] = $states[(int) $id];
        }
    }
}

class OrderCarrier
{
    public $id;
    public $tracking_number = '';
}

class Carrier
{
    public $id;
    public $name;
    public $url = '';
    public $delay;

    public function __construct($id = null, $langId = null)
    {
        if (isset(Fixtures::$carriers[(int) $id])) {
            $this->id = (int) $id;
            $this->name = Fixtures::$carriers[(int) $id]['name'];
            $this->delay = $langId ? Fixtures::$carriers[(int) $id]['delay'] : [1 => Fixtures::$carriers[(int) $id]['delay']];
        }
    }
}

class Currency
{
    public $id;
    public $iso_code;
    public $precision = 2;

    public function __construct($id = null)
    {
        $this->id = (int) $id;
        $this->iso_code = (int) $id === 1 ? 'EUR' : 'JPY';
        $this->precision = (int) $id === 1 ? 2 : 0;
    }
}

class Customer
{
    public $id;
    public $email;
    public $firstname;
    public $lastname;

    public function __construct($id = null)
    {
        foreach (Fixtures::$customers[(int) $id] ?? [] as $name => $value) {
            $this->$name = $value;
        }
    }
}

class Address
{
    public $id;
    public $firstname = '';
    public $lastname = '';
    public $company = '';
    public $address1 = '';
    public $address2 = '';
    public $postcode = '';
    public $city = '';
    public $id_state = 0;
    public $id_country = 0;
    public $phone = '';
    public $phone_mobile = '';

    public function __construct($id = null)
    {
        foreach (Fixtures::$addresses[(int) $id] ?? [] as $name => $value) {
            $this->$name = $value;
        }
    }
}

class State
{
    public static function getNameById($id)
    {
        return (int) $id === 7 ? 'Bavaria' : false;
    }
}

class Country
{
    public static function getNameById($langId, $id)
    {
        return ['8' => 'France', '1' => 'Germany'][(string) $id] ?? '';
    }
}

class Product
{
    public static function getProductName($id, $idAttribute = null, $langId = null)
    {
        return Fixtures::$productNames[(int) $id] ?? '';
    }
}

require __DIR__ . '/../classes/EmporiqaOrderFormatter.php';
require __DIR__ . '/../classes/EmporiqaOrderStatus.php';

$failures = 0;
function check($label, $condition)
{
    global $failures;
    echo ($condition ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$condition) {
        ++$failures;
    }
}

function line($id, $name, $ref, $qty, $unitIncl, $unitExcl, $idCombination = 0)
{
    return [
        'product_id' => $id, 'product_attribute_id' => $idCombination, 'product_name' => $name,
        'product_reference' => $ref, 'product_quantity' => $qty,
        'unit_price_tax_incl' => $unitIncl, 'unit_price_tax_excl' => $unitExcl,
        'total_price_tax_incl' => $unitIncl * $qty, 'total_price_tax_excl' => $unitExcl * $qty,
    ];
}

function reset_fixtures()
{
    Fixtures::$customers = [
        5 => ['id' => 5, 'email' => 'anna@example.com', 'firstname' => 'Anna', 'lastname' => 'Muster'],
        6 => ['id' => 6, 'email' => 'bob@example.com', 'firstname' => 'Bob', 'lastname' => 'Other'],
    ];
    Fixtures::$addresses = [
        11 => ['id' => 11, 'firstname' => 'Anna', 'lastname' => 'Muster', 'address1' => 'Hauptstr. 1', 'postcode' => '80331',
            'city' => 'Munich', 'id_state' => 7, 'id_country' => 1, 'phone' => '+49 89 1', 'phone_mobile' => ''],
        12 => ['id' => 12, 'firstname' => 'Anna', 'lastname' => 'Muster', 'company' => 'Muster GmbH', 'address1' => 'Ring 2',
            'address2' => 'Floor 3', 'postcode' => '75001', 'city' => 'Paris', 'id_country' => 8, 'phone_mobile' => '+33 6 1'],
    ];
    Fixtures::$carriers = [3 => ['name' => 'DHL', 'delay' => 'Delivery in 2-3 days']];
    Fixtures::$productNames = [1 => 'T-shirt', 2 => 'Mug', 3 => 'Notebook'];
    Fixtures::$attributeRows = [30 => [['group_name' => 'Style', 'value' => 'Ruled']]];
    Fixtures::$hook = null;
    Fixtures::$orders = [
        100 => ['id' => 100, 'reference' => 'ABCDEFGHI', 'id_customer' => 5, 'date_add' => '2026-10-01 10:00:00',
            'payment' => 'Bank wire', 'paid' => true, 'id_address_delivery' => 11, 'id_address_invoice' => 12,
            'total_products' => 50.0, 'total_products_wt' => 60.0,
            'total_shipping_tax_incl' => 6.0, 'total_shipping_tax_excl' => 5.0,
            'total_discounts_tax_incl' => 12.0, 'total_discounts_tax_excl' => 10.0,
            'total_paid_tax_incl' => 54.0, 'total_paid_tax_excl' => 45.0],
    ];
    Fixtures::$lines = [
        100 => [
            line(1, 'T-shirt - Color : White, Size : S', 'demo_1', 2, 24.0, 20.0, 10),
            line(2, 'Mug', 'demo_13', 1, 12.0, 10.0),
            // The format PrestaShop 8.1 and 9 write.
            line(1, 'T-shirt (Size: M - Color: Black)', 'demo_1', 1, 24.0, 20.0, 11),
            // Renamed since the order: the combination comes from its attributes.
            line(3, 'Old notebook name Style : Ruled', '', 1, 0.0, 0.0, 30),
        ],
    ];
}

function lookup(array $fields, array $customer = [])
{
    $payload = ['fields' => $fields];
    if ($customer) {
        $payload['customer'] = $customer;
    }

    return (new EmporiqaOrderStatus(new Context()))->handle($payload);
}

// 1. A multi-item order with a discount, paid, both addresses.
reset_fixtures();
$answer = lookup(['order_number' => 'ABCDEFGHI', 'email' => 'Anna@example.com']);
check('found', $answer['status'] === 'found');
$data = $answer['data'];
check('the five original keys stay', $data['status_code'] === 'shipped' && $data['status_label'] === 'Shipped'
    && $data['placed_at'] === date('c', strtotime('2026-10-01 10:00:00')) && $data['tracking'] === []);
check('order number', $data['order_number'] === 'ABCDEFGHI');
check('customer name', $data['customer_name'] === 'Anna Muster');
check('currency', $data['currency'] === 'EUR');
check('four items', count($data['items']) === 4);
check('combination line', $data['items'][0] === ['name' => 'T-shirt - Color : White, Size : S', 'sku' => 'demo_1',
    'quantity' => 2, 'unit_price' => 24.0, 'total_price' => 48.0, 'variant' => 'Color : White, Size : S']);
check('simple line has no variant', $data['items'][1] === ['name' => 'Mug', 'sku' => 'demo_13', 'quantity' => 1,
    'unit_price' => 12.0, 'total_price' => 12.0]);
check('parenthesised combination label', $data['items'][2]['variant'] === 'Size: M - Color: Black');
check('renamed product: variant from its attributes, no empty sku', $data['items'][3]['variant'] === 'Style : Ruled'
    && !isset($data['items'][3]['sku']));
check('totals tax included, discount positive', $data['totals'] === ['subtotal' => 60.0, 'shipping' => 6.0,
    'tax' => 9.0, 'discount' => 12.0, 'total' => 54.0]);
check('payment', $data['payment_method'] === 'Bank wire' && $data['payment_status'] === 'paid');
check('carrier and delay', $data['shipping_method'] === 'DHL' && $data['delivery_time'] === 'Delivery in 2-3 days');
check('shipping address, empty parts left out', $data['shipping_address'] === ['name' => 'Anna Muster',
    'address1' => 'Hauptstr. 1', 'postcode' => '80331', 'city' => 'Munich', 'region' => 'Bavaria',
    'country' => 'Germany', 'phone' => '+49 89 1']);
check('billing address, mobile phone first', $data['billing_address'] === ['name' => 'Anna Muster',
    'company' => 'Muster GmbH', 'address1' => 'Ring 2', 'address2' => 'Floor 3', 'postcode' => '75001',
    'city' => 'Paris', 'country' => 'France', 'phone' => '+33 6 1']);
$json = json_encode($data);
check('no email in the answer', stripos($json, 'example.com') === false);
check('no id keys in the answer', !preg_match('/"id(_[a-z_]+)?"/', $json) && strpos($json, 'customer_id') === false);

// 2. A missing delivery address, no discount, unpaid bank wire.
reset_fixtures();
Fixtures::$orders[100]['id_address_delivery'] = 99;
Fixtures::$orders[100]['total_discounts_tax_incl'] = 0;
Fixtures::$orders[100]['paid'] = false;
Fixtures::$orders[100]['state'] = 10;
$data = lookup(['order_number' => 'ABCDEFGHI'], ['id' => 5])['data'];
check('signed-in customer finds own order', $data['order_number'] === 'ABCDEFGHI');
check('missing address left out', !isset($data['shipping_address']) && isset($data['billing_address']));
check('no discount key without a discount', !array_key_exists('discount', $data['totals']));
check('no fees key without gift wrapping', !array_key_exists('fees', $data['totals']));
check('unpaid is pending', $data['payment_status'] === 'pending');

// 3. The hook runs after the filling: change a key, add extra.
reset_fixtures();
Fixtures::$hook = function (array $params) {
    $params['data']['extra']['gift_message'] = 'Happy birthday';
    $params['data']['delivery_time'] = $params['data']['delivery_time'] . ' (tracked)';
    check('the hook sees the order', $params['order'] instanceof Order);
};
$data = lookup(['order_number' => 'ABCDEFGHI', 'email' => 'anna@example.com'])['data'];
check('hook adds extra', $data['extra'] === ['gift_message' => 'Happy birthday']);
check('hook changes a filled key', $data['delivery_time'] === 'Delivery in 2-3 days (tracked)');

// 4. A group shown prices tax excluded.
reset_fixtures();
Fixtures::$orders[100]['tax_method'] = PS_TAX_EXC;
$data = lookup(['order_number' => 'ABCDEFGHI', 'email' => 'anna@example.com'])['data'];
check('tax excluded unit and line price', $data['items'][0]['unit_price'] === 20.0 && $data['items'][0]['total_price'] === 40.0);
check('tax excluded totals, total still paid amount', $data['totals'] === ['subtotal' => 50.0, 'shipping' => 5.0,
    'tax' => 9.0, 'discount' => 10.0, 'total' => 54.0]);

// 5. A split checkout: two orders, one reference.
reset_fixtures();
Fixtures::$orders[101] = Fixtures::$orders[100];
Fixtures::$orders[101]['id'] = 101;
Fixtures::$orders[101]['date_add'] = '2026-10-01 10:00:01';
Fixtures::$lines[101] = [line(2, 'Mug', 'demo_13', 3, 12.0, 10.0)];
$data = lookup(['order_number' => 'ABCDEFGHI', 'email' => 'anna@example.com'])['data'];
check('split: every order\'s items, oldest first', count($data['items']) === 5 && $data['items'][4]['quantity'] === 3);
check('split: totals summed', $data['totals']['total'] === 108.0 && $data['totals']['discount'] === 24.0);
check('split: one carrier name', $data['shipping_method'] === 'DHL');

// 5b. Gift wrapping is a fee, and the totals add up both ways.
reset_fixtures();
Fixtures::$orders[100]['total_wrapping_tax_incl'] = 2.4;
Fixtures::$orders[100]['total_wrapping_tax_excl'] = 2.0;
Fixtures::$orders[100]['total_paid_tax_incl'] = 56.4;
Fixtures::$orders[100]['total_paid_tax_excl'] = 47.0;
$t = lookup(['order_number' => 'ABCDEFGHI', 'email' => 'anna@example.com'])['data']['totals'];
check('fees tax included', $t['fees'] === 2.4);
check('tax included: subtotal - discount + shipping + fees = total',
    abs($t['subtotal'] - $t['discount'] + $t['shipping'] + $t['fees'] - $t['total']) < 0.001);
Fixtures::$orders[100]['tax_method'] = PS_TAX_EXC;
$t = lookup(['order_number' => 'ABCDEFGHI', 'email' => 'anna@example.com'])['data']['totals'];
check('fees tax excluded', $t['fees'] === 2.0 && $t['tax'] === 9.4);
check('tax excluded: subtotal - discount + shipping + fees + tax = total',
    abs($t['subtotal'] - $t['discount'] + $t['shipping'] + $t['fees'] + $t['tax'] - $t['total']) < 0.001);

// 6. At most 50 items.
reset_fixtures();
Fixtures::$lines[100] = array_fill(0, 60, line(2, 'Mug', 'demo_13', 1, 12.0, 10.0));
$data = lookup(['order_number' => 'ABCDEFGHI', 'email' => 'anna@example.com'])['data'];
check('items capped at 50', count($data['items']) === 50);

// 7. The proof is unchanged.
reset_fixtures();
check('another customer\'s id finds nothing', lookup(['order_number' => 'ABCDEFGHI'], ['id' => 6]) === ['status' => 'not_found']);
check('a wrong email finds nothing', lookup(['order_number' => 'ABCDEFGHI', 'email' => 'bob@example.com']) === ['status' => 'not_found']);

// 8. A currency without decimals rounds to it.
reset_fixtures();
Fixtures::$orders[100]['id_currency'] = 2;
Fixtures::$orders[100]['total_paid_tax_incl'] = 5400.4;
$data = lookup(['order_number' => 'ABCDEFGHI', 'email' => 'anna@example.com'])['data'];
check('JPY: currency and whole amounts', $data['currency'] === 'JPY' && $data['totals']['total'] === 5400.0);

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
