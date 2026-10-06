<?php
/**
 * Locks in that a price change no hook reports still reaches the chat:
 *
 * - a dated specific price that started or ended since the last check queues
 *   its product for a re-send; the check runs at most once per
 *   PRICE_WINDOW_CHECK_SECONDS and the first one only starts the clock;
 * - only the request whose compare-and-set UPDATE claims the check runs it,
 *   so a burst of page views does the work once;
 * - SENT_UNTIL moves only once the products were sent: a failed send or a
 *   request that dies is retried; more than PRICE_CHANGE_MAX_PRODUCTS waiting
 *   are sent oldest first and the next page view continues;
 * - more products than one request sends changing at the same second, or a
 *   catalog-wide row, log the full-sync advice instead of going quiet.
 *
 * Self-contained (no PHPUnit, no PrestaShop): PrestaShop classes are stubbed
 * and the real Emporiqa module class is loaded.
 *
 * Run: php tests/PriceWindowTest.php
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
define('_PS_VERSION_', '8.1.0');
define('_DB_PREFIX_', 'ps_');

class Module
{
}

class Context
{
}

class Tools
{
    public static function isPHPCLI()
    {
        return true;
    }
}

class Hook
{
    public static function exec($name, array $params = [])
    {
    }
}

class Validate
{
    public static function isLoadedObject($object)
    {
        return true;
    }
}

class PrestaShopLogger
{
    public static $messages = [];

    public static function addLog($message)
    {
        self::$messages[] = $message;
    }
}

class Configuration
{
    public static $values = [];

    public static function get($key)
    {
        return self::$values[$key] ?? false;
    }

    public static function getGlobalValue($key)
    {
        return self::$values[$key] ?? false;
    }

    public static function updateGlobalValue($key, $value)
    {
        self::$values[$key] = $value;

        return true;
    }
}

class SpecificPrice
{
    public static function isFeatureActive()
    {
        return true;
    }
}

class Db
{
    public static $rows = [];
    public static $queries = [];
    public static $updates = [];
    public static $affected = 1;

    public static function getInstance()
    {
        return new self();
    }

    public function executeS($sql)
    {
        self::$queries[] = $sql;

        return self::$rows;
    }

    public function execute($sql)
    {
        self::$updates[] = $sql;

        return true;
    }

    public function Affected_Rows()
    {
        return self::$affected;
    }
}

function pSQL($value)
{
    return addslashes((string) $value);
}

require __DIR__ . '/../emporiqa.php';

$failures = 0;
function check($label, $ok)
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        ++$failures;
    }
}

function freshModule()
{
    $module = (new ReflectionClass('Emporiqa'))->newInstanceWithoutConstructor();
    $flag = new ReflectionProperty($module, 'shutdownFlushRegistered');
    $flag->setAccessible(true);
    $flag->setValue($module, true);
    Db::$queries = [];
    Db::$updates = [];
    Db::$affected = 1;
    PrestaShopLogger::$messages = [];

    return $module;
}

function call($module, $method, ...$args)
{
    $m = new ReflectionMethod($module, $method);
    $m->setAccessible(true);

    return $m->invoke($module, ...$args);
}

function queued($module)
{
    $p = new ReflectionProperty($module, 'pending');
    $p->setAccessible(true);

    return array_keys($p->getValue($module)['product_syncs']);
}

function window($module)
{
    $p = new ReflectionProperty($module, 'pending');
    $p->setAccessible(true);

    return $p->getValue($module)['price_window'];
}

function lastRun($ago)
{
    Configuration::$values['EMPORIQA_PRICE_WINDOW_SENT_UNTIL'] = time() - $ago;
    Configuration::$values['EMPORIQA_PRICE_WINDOW_RUN_AT'] = (string) (time() - $ago);
}

function changes($count, $sameSecond = false)
{
    return array_map(function ($i) use ($sameSecond) {
        return ['id_product' => $i, 'changed_at' => date('Y-m-d H:i:s', time() - 900 + ($sameSecond ? 0 : $i))];
    }, range(1, $count));
}

Configuration::$values = [
    'EMPORIQA_SYNC_PRODUCTS' => 1,
    'EMPORIQA_WEBHOOK_URL' => 'https://emporiqa.test/webhooks/sync/',
    'EMPORIQA_WEBHOOK_SECRET' => 'secret',
    'EMPORIQA_STORE_ID' => 'store',
];

echo "Scenario 1: the first check only starts the clock\n";
$module = freshModule();
Db::$rows = changes(1);
call($module, 'queueScheduledPriceChanges');
check('nothing queued', queued($module) === []);
check('no query', Db::$queries === []);
check('clock started', (int) Configuration::$values['EMPORIQA_PRICE_WINDOW_SENT_UNTIL'] > 0
    && (int) Configuration::$values['EMPORIQA_PRICE_WINDOW_RUN_AT'] > 0);

echo "Scenario 2: within the interval nothing is checked\n";
$module = freshModule();
call($module, 'queueScheduledPriceChanges');
check('no query', Db::$queries === [] && Db::$updates === []);

echo "Scenario 3: a concurrent request that lost the claim does nothing\n";
$module = freshModule();
lastRun(1000);
Db::$affected = 0;
call($module, 'queueScheduledPriceChanges');
check('it tried to claim', count(Db::$updates) === 1
    && strpos(Db::$updates[0], "`value` = '" . (time() - 1000) . "'") !== false);
check('no query, nothing queued', Db::$queries === [] && queued($module) === []);

echo "Scenario 4: a sale that started or ended since the last check is re-sent\n";
$module = freshModule();
lastRun(1000);
Db::$rows = changes(2);
call($module, 'queueScheduledPriceChanges');
check('both products queued', queued($module) === [1, 2]);
$sql = Db::$queries[0] ?? '';
check('the window starts where the last sent one ended', strpos($sql, date('Y-m-d H:i:s', time() - 1000)) !== false);
check('dated rows only, oldest change first', strpos($sql, '`from` >') !== false && strpos($sql, '`to` >=') !== false
    && strpos($sql, 'ORDER BY `changed_at`') !== false);
check('nothing is marked sent before the send', (int) Configuration::$values['EMPORIQA_PRICE_WINDOW_SENT_UNTIL'] === time() - 1000);
call($module, 'savePriceWindow', window($module), false);
check('a failed send leaves it for the next check', (int) Configuration::$values['EMPORIQA_PRICE_WINDOW_SENT_UNTIL'] === time() - 1000);
call($module, 'savePriceWindow', window($module), true);
check('a good send marks the window sent', (int) Configuration::$values['EMPORIQA_PRICE_WINDOW_SENT_UNTIL'] >= time() - 1);

echo "Scenario 5: a longer backlog is sent oldest first, and continues\n";
$module = freshModule();
lastRun(1000);
Db::$rows = changes(101);
call($module, 'queueScheduledPriceChanges');
check('the first 100 are queued', count(queued($module)) === 100 && !in_array(101, queued($module), true));
call($module, 'savePriceWindow', window($module), true);
check('sent up to the 100th change', (int) Configuration::$values['EMPORIQA_PRICE_WINDOW_SENT_UNTIL'] === strtotime(Db::$rows[99]['changed_at']));
check('and the next page view continues', (int) Configuration::$values['EMPORIQA_PRICE_WINDOW_RUN_AT'] === 0);

echo "Scenario 6: a catalog-wide row, or too many changes at one second, asks for a full sync\n";
$module = freshModule();
lastRun(1000);
Db::$rows = [['id_product' => 0, 'changed_at' => date('Y-m-d H:i:s', time() - 900)]];
call($module, 'queueScheduledPriceChanges');
check('logged', count(PrestaShopLogger::$messages) === 1);
check('and nothing waits on a send', window($module) === [] && (int) Configuration::$values['EMPORIQA_PRICE_WINDOW_SENT_UNTIL'] >= time() - 1);
$module = freshModule();
lastRun(1000);
Db::$rows = changes(101, true);
call($module, 'queueScheduledPriceChanges');
check('none queued', queued($module) === []);
check('logged once', count(PrestaShopLogger::$messages) === 1);

if ($failures > 0) {
    echo "\n{$failures} assertion(s) FAILED\n";
    exit(1);
}
echo "\nAll assertions passed\n";
exit(0);
