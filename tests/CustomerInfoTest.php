<?php
/**
 * Locks in the `customer_info` action:
 *
 * - no customer id (or not a number) is rejected missing_field; an unknown,
 *   disabled, deleted or guest customer, or one of a shop that is not
 *   synced, is not_found;
 * - the account's name, first and last name and email come from the
 *   customer, not an order, and nothing else about them is sent;
 * - the orders are this customer's only (never another customer's, never a
 *   guest order placed with the same email), only in the synced shops,
 *   newest first, at most 10; a split checkout (orders sharing a reference)
 *   is one entry with its newest order's status and the sum of the totals;
 *   each entry is order_number (the reference), placed_at, status_code (the
 *   catalog codes, as Order status maps them), status_label in the order's
 *   language, total and currency;
 * - actionEmporiqaCustomerInfo runs last and can change or remove anything;
 * - limits: 30 calls per customer and 600 per shop in 10 minutes.
 *
 * Self-contained (no PHPUnit, no PrestaShop): PrestaShop classes are stubbed
 * and the real EmporiqaCustomerInfo and EmporiqaOrderStatus (status codes and
 * the shared rate limiter) are loaded.
 *
 * Run: php tests/CustomerInfoTest.php
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
define('_PS_VERSION_', '8.1.0');
define('_DB_PREFIX_', 'ps_');

class Context
{
}

class Configuration
{
    public static $values = ['PS_OS_SHIPPING' => 4, 'PS_OS_DELIVERED' => 5, 'PS_OS_BANKWIRE' => 10];

    public static function get($key)
    {
        return self::$values[$key] ?? false;
    }
}

class Validate
{
    public static function isLoadedObject($object)
    {
        return is_object($object) && !empty($object->id);
    }
}

class Tools
{
    public static function strtoupper($value)
    {
        return strtoupper($value);
    }
}

class Shop
{
    public const SHARE_CUSTOMER = 'share_customer';

    public static function getSharedShops($shopId, $type)
    {
        // Shops 1 and 2 share customers; shop 3 is on its own.
        return $shopId === 3 ? [3] : [1, 2];
    }
}

class Customer
{
    /** @var array<int, array<string, mixed>> */
    public static $rows = [
        5 => ['firstname' => 'Ana', 'lastname' => 'Emporiqa test', 'email' => 'ana@example.test', 'id_shop' => 1],
        6 => ['firstname' => 'Bo', 'lastname' => 'Emporiqa test', 'email' => 'bo@example.test', 'id_shop' => 1],
        7 => ['firstname' => 'Guest', 'lastname' => 'Emporiqa test', 'email' => 'ana@example.test', 'id_shop' => 1, 'is_guest' => 1],
        8 => ['firstname' => 'Off', 'lastname' => 'Emporiqa test', 'email' => 'off@example.test', 'id_shop' => 1, 'active' => 0],
        9 => ['firstname' => 'Gone', 'lastname' => 'Emporiqa test', 'email' => 'gone@example.test', 'id_shop' => 1, 'deleted' => 1],
        11 => ['firstname' => 'Cy', 'lastname' => 'Emporiqa test', 'email' => 'cy@example.test', 'id_shop' => 3],
    ];
    public $id;
    public $firstname;
    public $lastname;
    public $email;
    public $id_shop;
    public $active = 1;
    public $deleted = 0;
    public $is_guest = 0;
    public $passwd = 'hash';
    public $id_default_group = 3;

    public function __construct($id)
    {
        if (isset(self::$rows[$id])) {
            $this->id = $id;
            foreach (self::$rows[$id] as $k => $v) {
                $this->$k = $v;
            }
        }
    }
}

class Order
{
    /** @var array<int, array<string, mixed>> id => row, as ps_orders */
    public static $rows = [];
    public $id;
    public $reference;
    public $id_customer;
    public $id_shop;
    public $id_lang;
    public $id_currency;
    public $date_add;
    public $total_paid_tax_incl;
    public $state;

    public function __construct($id)
    {
        if (isset(self::$rows[$id])) {
            $this->id = $id;
            foreach (self::$rows[$id] as $k => $v) {
                $this->$k = $v;
            }
        }
    }

    public function getCurrentState()
    {
        return $this->state;
    }
}

class OrderState
{
    public $id;
    public $name;
    public $shipped = 0;

    public function __construct($id, $langId)
    {
        $names = [4 => [1 => 'Shipped', 2 => 'Versandt'], 5 => [1 => 'Delivered', 2 => 'Zugestellt'], 10 => [1 => 'Awaiting bank wire payment', 2 => 'Warten auf Zahlungseingang Überweisung']];
        if (isset($names[$id])) {
            $this->id = $id;
            $this->name = $names[$id][$langId];
        }
    }
}

class Currency
{
    public $id;
    public $iso_code;
    public $precision = 2;

    public function __construct($id)
    {
        $this->id = $id;
        $this->iso_code = $id === 2 ? 'usd' : 'EUR';
    }
}

class Hook
{
    /** @var callable|null */
    public static $subscriber;

    public static function exec($name, array $params = [])
    {
        if ($name === 'actionEmporiqaCustomerInfo' && self::$subscriber) {
            (self::$subscriber)($params);
        }
    }
}

class EmporiqaChannelResolver
{
    public function __construct($context)
    {
    }

    // Shops 1 and 2 are synced, shop 3 is not.
    public function getMapping()
    {
        return [1 => 'prestashop', 2 => 'french-shop'];
    }
}

/** ps_orders and the action rate table, read from the SQL the code sends. */
class Db
{
    /** @var string[] */
    public static $queries = [];
    /** @var array<string, int> */
    public static $hits = [];

    public static function getInstance()
    {
        return new self();
    }

    public function executeS($sql)
    {
        self::$queries[] = $sql;
        preg_match('/`id_customer` = (\d+)/', $sql, $c);
        preg_match('/`id_shop` IN \(([\d,]+)\)/', $sql, $s);
        preg_match('/LIMIT (\d+)/', $sql, $l);
        $shops = array_map('intval', explode(',', $s[1]));
        $rows = [];
        foreach (Order::$rows as $id => $row) {
            if ($row['id_customer'] === (int) $c[1] && in_array($row['id_shop'], $shops, true)) {
                $rows[] = ['id_order' => (string) $id, 'reference' => $row['reference'], 'date_add' => $row['date_add']];
            }
        }
        usort($rows, function ($a, $b) {
            return strcmp($b['date_add'], $a['date_add']) ?: (int) $b['id_order'] - (int) $a['id_order'];
        });

        return array_slice($rows, 0, (int) $l[1]);
    }

    public function execute($sql)
    {
        if (preg_match('/VALUES \("([0-9a-f]+)"/', $sql, $m)) {
            self::$hits[$m[1]] = (self::$hits[$m[1]] ?? 0) + 1;
        }

        return true;
    }

    public function getValue($sql, $useCache = true)
    {
        preg_match('/`bucket_hash` = "([0-9a-f]+)"/', $sql, $m);

        return self::$hits[$m[1]] ?? 0;
    }
}

function pSQL($value)
{
    return addslashes($value);
}

require __DIR__ . '/../classes/EmporiqaOrderStatus.php';
require __DIR__ . '/../classes/EmporiqaCustomerInfo.php';

$failures = 0;
function check($label, $ok)
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        ++$failures;
    }
}

function ask($customerId)
{
    $payload = ['rule' => 'customer_info', 'request_id' => 'r', 'customer' => $customerId === null ? null : ['id' => $customerId]];

    return (new EmporiqaCustomerInfo(new Context()))->handle($payload);
}

function order($customer, $shop, $reference, $date, $total, $state = 4, $lang = 1, $currency = 1)
{
    return ['id_customer' => $customer, 'id_shop' => $shop, 'reference' => $reference, 'date_add' => $date,
        'total_paid_tax_incl' => $total, 'state' => $state, 'id_lang' => $lang, 'id_currency' => $currency];
}

Order::$rows = [
    1 => order(5, 1, 'AAAOLDEST', '2026-01-01 10:00:00', 10.0, 5),
    2 => order(5, 1, 'BBBSPLIT', '2026-03-01 10:00:00', 20.0, 4),
    3 => order(5, 1, 'BBBSPLIT', '2026-03-01 10:00:00', 5.555, 10),
    4 => order(5, 2, 'CCCFRENCH', '2026-04-01 10:00:00', 30.0, 10, 2, 2),
    5 => order(5, 3, 'DDDUNSYNCED', '2026-05-01 10:00:00', 40.0),
    6 => order(6, 1, 'EEEOTHER', '2026-06-01 10:00:00', 50.0),
    7 => order(7, 1, 'FFFGUEST', '2026-06-02 10:00:00', 60.0),
];

echo "Scenario 1: who is asked about\n";
check('no customer: rejected missing_field', ask(null) === ['status' => 'rejected', 'message_code' => 'missing_field']);
check('not a number: rejected missing_field', ask('5 OR 1=1')['status'] === 'rejected');
foreach (['unknown' => '404', 'a guest record' => '7', 'disabled' => '8', 'deleted' => '9', 'of a shop that is not synced' => '11'] as $label => $id) {
    check($label . ': not_found', ask($id) === ['status' => 'not_found']);
}

echo "Scenario 2: the account\n";
$answer = ask('5');
$data = $answer['data'];
check('found', $answer['status'] === 'found');
check('name, first and last name and email from the account, nothing else', $data['customer'] === [
    'name' => 'Ana Emporiqa test',
    'first_name' => 'Ana',
    'last_name' => 'Emporiqa test',
    'email' => 'ana@example.test',
]);
check('only customer and orders', array_keys($data) === ['customer', 'orders']);

echo "Scenario 3: the orders\n";
$numbers = array_column($data['orders'], 'order_number');
check('own orders in synced shops, newest first, a split checkout once', $numbers === ['CCCFRENCH', 'BBBSPLIT', 'AAAOLDEST']);
check("another customer's order and the guest order with the same email are not there",
    !in_array('EEEOTHER', $numbers, true) && !in_array('FFFGUEST', $numbers, true));
check('nor one in a shop that is not synced', !in_array('DDDUNSYNCED', $numbers, true));
$sql = end(Db::$queries);
check('asked of the database by customer id and synced shops', strpos($sql, '`id_customer` = 5') !== false && strpos($sql, '`id_shop` IN (1,2)') !== false);
$split = $data['orders'][1];
check('split checkout: the newest order\'s status (same second: the higher id), summed total', $split['status_code'] === 'pending_payment' && $split['status_label'] === 'Awaiting bank wire payment' && $split['total'] === 25.56);
$french = $data['orders'][0];
check('status code and label in the order language', $french === [
    'order_number' => 'CCCFRENCH',
    'placed_at' => date('c', strtotime('2026-04-01 10:00:00')),
    'status_code' => 'pending_payment',
    'status_label' => 'Warten auf Zahlungseingang Überweisung',
    'total' => 30.0,
    'currency' => 'USD',
]);
check('delivered maps to delivered', $data['orders'][2]['status_code'] === 'delivered');
for ($i = 100; $i < 115; ++$i) {
    Order::$rows[$i] = order(6, 1, 'REF' . $i, '2026-07-01 10:' . ($i - 100 + 10) . ':00', 1.0);
}
$many = ask('6')['data']['orders'];
check('at most 10, the newest', count($many) === 10 && $many[0]['order_number'] === 'REF114' && $many[9]['order_number'] === 'REF105');
Order::$rows = array_slice(Order::$rows, 0, 7, true);
Customer::$rows[12] = ['firstname' => 'New', 'lastname' => 'Emporiqa test', 'email' => 'new@example.test', 'id_shop' => 2];
check('no orders: an empty list', ask('12')['data']['orders'] === []);

echo "Scenario 4: the merchant hook runs last\n";
Hook::$subscriber = function (array $params) {
    unset($params['data']['customer']['email']);
    $params['data']['extra'] = ['loyalty_points' => 120];
};
$data = ask('5')['data'];
Hook::$subscriber = null;
check('a field removed and extra added', !isset($data['customer']['email']) && $data['extra'] === ['loyalty_points' => 120]);

echo "Scenario 5: limits\n";
Db::$hits = [];
$hit = null;
for ($i = 1; $i <= 31; ++$i) {
    $hit = EmporiqaCustomerInfo::rateLimitHit(['customer' => ['id' => '5']], 1);
    if ($i === 30) {
        check('30 calls for one customer pass', $hit === null);
    }
}
check('the 31st is limited for that customer', $hit !== null && $hit['scope'] === 'value');
check('another customer is not', EmporiqaCustomerInfo::rateLimitHit(['customer' => ['id' => '6']], 1) === null);
Db::$hits = [];
for ($i = 1; $i <= 600; ++$i) {
    EmporiqaCustomerInfo::rateLimitHit(['customer' => ['id' => (string) (1000 + $i)]], 1);
}
$hit = EmporiqaCustomerInfo::rateLimitHit(['customer' => ['id' => '5']], 1);
check('the 601st call in the shop is limited for everyone', $hit !== null && $hit['scope'] === 'store');

if ($failures > 0) {
    echo "\n{$failures} assertion(s) FAILED\n";
    exit(1);
}
echo "\nAll assertions passed\n";
exit(0);
