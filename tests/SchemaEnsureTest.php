<?php
/**
 * Locks in EmporiqaSchema::ensure(): install and upgrade must bring a table
 * left over from an older version up to the current schema. A 1.2.8
 * emporiqa_connect_nonce without `exchanging_at` that survived into a fresh
 * 1.3.0 install made one-click connect fail with "Unknown column".
 *
 * Self-contained (no PHPUnit, no PrestaShop): Db is a fake that keeps tables,
 * columns and indexes in memory and understands only the statements
 * EmporiqaSchema emits.
 *
 * Run: php tests/SchemaEnsureTest.php
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
define('_PS_VERSION_', '8.1.0');
define('_DB_PREFIX_', 'ps_');
define('_MYSQL_ENGINE_', 'InnoDB');

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
    /** @var array<string, array{columns: string[], indexes: string[]}> */
    public $tables = [];

    /** @var string[] */
    public $log = [];

    public $failOn;

    public function execute($sql)
    {
        $this->log[] = $sql;
        if ($this->failOn !== null && strpos($sql, $this->failOn) !== false) {
            return false;
        }

        if (preg_match('/^CREATE TABLE IF NOT EXISTS `([^`]+)` \((.*)\) ENGINE=/s', $sql, $m)) {
            if (!isset($this->tables[$m[1]])) {
                preg_match_all('/(?:^|, )`([^`]+)` [A-Z]/', $m[2], $cols);
                preg_match_all('/KEY `([^`]+)`/', $m[2], $keys);
                $this->tables[$m[1]] = ['columns' => $cols[1], 'indexes' => array_merge(['PRIMARY'], $keys[1])];
            }

            return true;
        }
        if (preg_match('/^ALTER TABLE `([^`]+)` ADD `([^`]+)` /', $sql, $m)) {
            $this->tables[$m[1]]['columns'][] = $m[2];

            return true;
        }
        if (preg_match('/^ALTER TABLE `([^`]+)` ADD KEY `([^`]+)`/', $sql, $m)) {
            $this->tables[$m[1]]['indexes'][] = $m[2];

            return true;
        }
        if (preg_match('/^DROP TABLE IF EXISTS `([^`]+)`/', $sql, $m)) {
            unset($this->tables[$m[1]]);

            return true;
        }

        throw new RuntimeException('Unexpected SQL: ' . $sql);
    }

    public $cacheFlags = [];

    public function executeS($sql, $array = true, $useCache = true)
    {
        $this->cacheFlags[] = $useCache;
        if (preg_match("/^SHOW COLUMNS FROM `([^`]+)` LIKE '([^']+)'/", $sql, $m)) {
            return isset($this->tables[$m[1]]) && in_array($m[2], $this->tables[$m[1]]['columns'], true)
                ? [['Field' => $m[2]]] : [];
        }
        if (preg_match("/^SHOW INDEX FROM `([^`]+)` WHERE `Key_name` = '([^']+)'/", $sql, $m)) {
            return isset($this->tables[$m[1]]) && in_array($m[2], $this->tables[$m[1]]['indexes'], true)
                ? [['Key_name' => $m[2]]] : [];
        }

        throw new RuntimeException('Unexpected SQL: ' . $sql);
    }

    public function alters()
    {
        return array_values(array_filter($this->log, function ($sql) {
            return strpos($sql, 'ALTER TABLE') === 0;
        }));
    }
}

require dirname(__DIR__) . '/classes/EmporiqaSchema.php';

$failures = 0;

function check($label, $condition)
{
    global $failures;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        ++$failures;
    }
}

$expected = [
    'ps_emporiqa_order_session' => ['id_order', 'emporiqa_sid', 'date_add'],
    'ps_emporiqa_order_tracked' => ['id_order', 'date_add'],
    'ps_emporiqa_connect_nonce' => ['state_hash', 'verifier', 'created_at', 'exchanging_at'],
    'ps_emporiqa_action_request' => ['request_hash', 'http_code', 'response', 'created_at'],
    'ps_emporiqa_action_rate' => ['bucket_hash', 'hits', 'expires_at'],
];

echo "Scenario 1: empty database gets every table, nothing altered\n";
$db = new FakeDb();
check('ensure succeeds', EmporiqaSchema::ensure($db) === true);
check('all five tables exist', array_keys($db->tables) == array_keys($expected));
foreach ($expected as $table => $columns) {
    check("$table has its columns", $db->tables[$table]['columns'] === $columns);
}
check('nonce has idx_created_at', in_array('idx_created_at', $db->tables['ps_emporiqa_connect_nonce']['indexes'], true));
check('rate table has idx_expires_at', in_array('idx_expires_at', $db->tables['ps_emporiqa_action_rate']['indexes'], true));
check('no ALTER on a fresh install', $db->alters() === []);
check('schema checks bypass the query cache', !in_array(true, $db->cacheFlags, true));

echo "Scenario 2: leftover 1.2.8 nonce table gains exchanging_at\n";
$db = new FakeDb();
$db->tables['ps_emporiqa_connect_nonce'] = [
    'columns' => ['state_hash', 'verifier', 'created_at'],
    'indexes' => ['PRIMARY', 'idx_created_at'],
];
check('ensure succeeds', EmporiqaSchema::ensure($db) === true);
check('exchanging_at added', in_array('exchanging_at', $db->tables['ps_emporiqa_connect_nonce']['columns'], true));
$alters = $db->alters();
check('exactly one ALTER', count($alters) === 1);
check('added after created_at, nullable', isset($alters[0])
    && strpos($alters[0], '`exchanging_at` INT(10) UNSIGNED NULL DEFAULT NULL AFTER `created_at`') !== false);
check('missing tables created alongside', count($db->tables) === 5);

echo "Scenario 3: a leftover table missing its index gets it back\n";
$db = new FakeDb();
$db->tables['ps_emporiqa_action_request'] = [
    'columns' => ['request_hash', 'http_code', 'response', 'created_at'],
    'indexes' => ['PRIMARY'],
];
check('ensure succeeds', EmporiqaSchema::ensure($db) === true);
check('idx_created_at added', in_array('idx_created_at', $db->tables['ps_emporiqa_action_request']['indexes'], true));

echo "Scenario 4: ensure is idempotent\n";
$db = new FakeDb();
EmporiqaSchema::ensure($db);
$before = count($db->alters());
check('second run succeeds', EmporiqaSchema::ensure($db) === true);
check('second run alters nothing', count($db->alters()) === $before);

echo "Scenario 5: a failed ALTER fails the install\n";
$db = new FakeDb();
$db->tables['ps_emporiqa_connect_nonce'] = ['columns' => ['state_hash', 'verifier', 'created_at'], 'indexes' => ['PRIMARY', 'idx_created_at']];
$db->failOn = 'ADD `exchanging_at`';
check('ensure returns false', EmporiqaSchema::ensure($db) === false);

echo "Scenario 6: dropAll removes every module table\n";
$db = new FakeDb();
EmporiqaSchema::ensure($db);
check('dropAll succeeds', EmporiqaSchema::dropAll($db) === true);
check('no tables left', $db->tables === []);

echo "Scenario 7: the table list, which uninstall drops, names every module table\n";
check('tables() lists exactly the five module tables', array_keys(EmporiqaSchema::tables()) === [
    'emporiqa_order_session',
    'emporiqa_order_tracked',
    'emporiqa_connect_nonce',
    'emporiqa_action_request',
    'emporiqa_action_rate',
]);

if ($failures > 0) {
    echo "\n{$failures} assertion(s) FAILED\n";
    exit(1);
}
echo "\nAll assertions passed\n";
exit(0);
