<?php
/**
 * Locks in that updating or deleting a catalog price rule queues every
 * product the rule covered: PrestaShop drops the rule's SpecificPrice rows
 * with a raw DELETE that fires no hook, so without this those products keep
 * the rule's discount in the chat.
 *
 * - an update, and a delete from the legacy Catalog price rules page (one or
 *   in bulk), queue the products of the rule's rows, read before they go;
 * - any other delete runs after SpecificPriceRule::delete() dropped the rows,
 *   so it queues the active products of the rule's shop, and a rule already
 *   handled in this request is not queued twice;
 * - one request queues at most PRICE_CHANGE_MAX_PRODUCTS for price rules
 *   (rows written by a rule included): beyond that it logs the full-sync
 *   advice once and queues nothing more.
 *
 * Self-contained (no PHPUnit, no PrestaShop): PrestaShop classes are stubbed
 * and the real Emporiqa module class is loaded.
 *
 * Run: php tests/PriceRuleHooksTest.php
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
    public static $values = [];

    public static function isPHPCLI()
    {
        return true;
    }

    public static function getValue($key)
    {
        return self::$values[$key] ?? false;
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

    public static function getInstance()
    {
        return new self();
    }

    public function executeS($sql)
    {
        self::$queries[] = $sql;

        return self::$rows;
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

Configuration::$values = [
    'EMPORIQA_SYNC_PRODUCTS' => 1,
    'EMPORIQA_WEBHOOK_URL' => 'https://emporiqa.test/webhooks/sync/',
    'EMPORIQA_WEBHOOK_SECRET' => 'secret',
    'EMPORIQA_STORE_ID' => 'store',
];

echo "Scenario 1: an update or a legacy delete re-sends what the rule covered\n";
$module = freshModule();
Db::$rows = [['id_product' => 3], ['id_product' => 4]];
$module->hookActionObjectSpecificPriceRuleUpdateBefore(['object' => (object) ['id' => 12]]);
check('the update queues the covered products', queued($module) === [3, 4]);
check('of that rule only', strpos(Db::$queries[0], '`id_specific_price_rule` IN (12)') !== false);

$module = freshModule();
Tools::$values = ['id_specific_price_rule' => '12'];
$module->hookActionAdminSpecificPriceRuleControllerDeleteBefore([]);
check('a legacy delete queues them before the rows go', queued($module) === [3, 4]);
$module->hookActionObjectSpecificPriceRuleDeleteBefore(['object' => (object) ['id' => 12, 'id_shop' => 1]]);
check('the object hook of the same delete adds nothing', count(Db::$queries) === 1);

$module = freshModule();
Tools::$values = ['specific_price_ruleBox' => ['7', '9', 'x']];
$module->hookActionAdminSpecificPriceRuleControllerBulkdeleteBefore([]);
check('a bulk delete reads every ticked rule', strpos(Db::$queries[0], 'IN (7,9)') !== false);
Tools::$values = [];

echo "Scenario 2: any other delete queues the products of the rule's shop\n";
$module = freshModule();
Db::$rows = [['id_product' => 5], ['id_product' => 6]];
$module->hookActionObjectSpecificPriceRuleDeleteBefore(['object' => (object) ['id' => 13, 'id_shop' => 2]]);
check('queued', queued($module) === [5, 6]);
check('active products of that shop', strpos(Db::$queries[0], 'product_shop') !== false
    && strpos(Db::$queries[0], '`id_shop` = 2') !== false && strpos(Db::$queries[0], '`active` = 1') !== false);

echo "Scenario 3: a rule on more products than one request sends asks for a full sync\n";
$module = freshModule();
Db::$rows = array_map(function ($i) {
    return ['id_product' => $i];
}, range(1, 101));
$module->hookActionObjectSpecificPriceRuleUpdateBefore(['object' => (object) ['id' => 14]]);
check('nothing queued', queued($module) === []);
check('the full-sync advice is logged', count(PrestaShopLogger::$messages) === 1);
check('the read is capped', strpos(Db::$queries[0], 'LIMIT 101') !== false);
call($module, 'queuePriceRuleProducts', [200]);
check('and the rule rows that follow queue nothing', queued($module) === [] && count(PrestaShopLogger::$messages) === 1);

if ($failures > 0) {
    echo "\n{$failures} assertion(s) FAILED\n";
    exit(1);
}
echo "\nAll assertions passed\n";
exit(0);
