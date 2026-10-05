<?php
/**
 * Locks in EmporiqaOrderFormatter::shipmentTracking(): PrestaShop 9.2's
 * improved shipments keep tracking numbers in {prefix}shipment, not on
 * OrderCarrier, and order lookups must find them there. On 8.1 the table
 * does not exist, so it is never queried, and the existence check runs once
 * per request, not once per order.
 *
 * Self-contained (no PHPUnit, no PrestaShop): Db is a fake that answers the
 * table check and returns canned shipment rows.
 *
 * Run: php tests/ShipmentTrackingTest.php
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
define('_PS_VERSION_', '8.1.0');
define('_DB_PREFIX_', 'ps_');

function pSQL($string)
{
    return addslashes($string);
}

function bqSQL($string)
{
    return str_replace('`', '\`', pSQL($string));
}

class FakeDb
{
    /** @var string[] */
    public $log = [];

    /** @var bool */
    public $hasTable;

    /** @var array<int, array<string, mixed>> */
    public $rows;

    /** @var bool */
    public $throws = false;

    public function __construct($hasTable, array $rows = [])
    {
        $this->hasTable = $hasTable;
        $this->rows = $rows;
    }

    public function executeS($sql, $array = true, $useCache = true)
    {
        $this->log[] = $sql;
        if ($this->throws) {
            throw new RuntimeException('Unknown column');
        }
        if (strpos($sql, 'SHOW TABLES') === 0) {
            return $this->hasTable && $sql === "SHOW TABLES LIKE 'ps\\\\_shipment'" ? [['Tables_in_db' => 'ps_shipment']] : [];
        }

        return $this->rows;
    }
}

require __DIR__ . '/../classes/EmporiqaOrderFormatter.php';

$failures = 0;
function check($label, $ok)
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        ++$failures;
    }
}

function newRequest()
{
    $cache = new ReflectionProperty('EmporiqaOrderFormatter', 'shipmentTableExists');
    if (PHP_VERSION_ID < 80100) {
        $cache->setAccessible(true);
    }
    $cache->setValue(null, null);
}

function queries(FakeDb $db, $needle)
{
    return count(array_filter($db->log, function ($sql) use ($needle) {
        return strpos($sql, $needle) !== false;
    }));
}

echo "Scenario 1: PrestaShop 8.1, no shipment table\n";
newRequest();
$db = new FakeDb(false);
check('first order finds nothing', EmporiqaOrderFormatter::shipmentTracking(10, $db) === []);
check('second order finds nothing', EmporiqaOrderFormatter::shipmentTracking(11, $db) === []);
check('the table is checked once per request', queries($db, 'SHOW TABLES') === 1);
check('the missing table is never queried', queries($db, '`ps_shipment`') === 0);

echo "Scenario 2: PrestaShop 9.2 with shipments\n";
newRequest();
$db = new FakeDb(true, [
    ['tracking_number' => 'TRK-1', 'id_carrier' => '2'],
    ['tracking_number' => ' TRK-1 ', 'id_carrier' => '2'],
    ['tracking_number' => 'TRK-2', 'id_carrier' => '3'],
    ['tracking_number' => '', 'id_carrier' => '2'],
]);
$found = EmporiqaOrderFormatter::shipmentTracking(10, $db);
check('distinct numbers, oldest shipment first, with their carriers', $found === [
    ['number' => 'TRK-1', 'id_carrier' => 2],
    ['number' => 'TRK-2', 'id_carrier' => 3],
]);
EmporiqaOrderFormatter::shipmentTracking(11, $db);
check('the table is still checked once', queries($db, 'SHOW TABLES') === 1);
$select = end($db->log);
check('reads by id_order', strpos($select, '`id_order` = 11') !== false);
check('skips deleted and cancelled shipments',
    strpos($select, '`deleted` = 0') !== false && strpos($select, '`cancelled_at` IS NULL') !== false);

echo "Scenario 3: the feature off (table present, no rows)\n";
newRequest();
check('nothing found', EmporiqaOrderFormatter::shipmentTracking(10, new FakeDb(true)) === []);

echo "Scenario 4: a schema that moved\n";
newRequest();
$db = new FakeDb(true);
$db->throws = true;
check('a failing query finds nothing instead of failing the lookup',
    EmporiqaOrderFormatter::shipmentTracking(10, $db) === []);

if ($failures > 0) {
    echo "\n{$failures} assertion(s) FAILED\n";
    exit(1);
}
echo "\nAll assertions passed\n";
exit(0);
