<?php
/**
 * Locks in how long the action endpoint keeps the answers it remembers for a
 * request_id replay.
 *
 * What must never regress: an answer (a customer's name, addresses, orders)
 * is deleted once the 10-minute replay window is over, on a quiet shop too.
 * Reading a remembered answer deletes the expired ones, writing one does, and
 * storefront page views do it now and then, so a shop whose chat stops
 * calling does not keep its last answers for ever. An answer inside the
 * window is kept and replayed. A failing delete never breaks a storefront
 * page.
 *
 * Self-contained (no PHPUnit, no PrestaShop): Db is a fake that keeps the
 * request table in memory and understands only the statements
 * EmporiqaOrderStatus emits; the real Emporiqa module class is loaded for the
 * page-view path.
 *
 * Run: php tests/ActionAnswerRetentionTest.php
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
define('_PS_VERSION_', '8.1.0');
define('_DB_PREFIX_', 'ps_');

function pSQL($string, $htmlOk = false)
{
    return addslashes($string);
}

class Module
{
    /** @var Context */
    public $context;
}

class Context
{
    public $shop;
}

class Configuration
{
    public static function get($key)
    {
        return $key === 'EMPORIQA_STORE_ID' ? 'store-1' : false;
    }
}

class PrestaShopLogger
{
    /** @var string[] */
    public static $logged = [];

    public static function addLog($message)
    {
        self::$logged[] = $message;
    }
}

class Db
{
    public const REPLACE = 3;

    /** @var array<string, array{http_code: int, response: string, created_at: int}> request_hash => row */
    public $rows = [];

    /** @var int DELETE statements run */
    public $deletes = 0;

    /** @var bool when true every statement throws, as on a missing table */
    public $broken = false;

    private static $instance;

    public static function getInstance()
    {
        return self::$instance ?: self::$instance = new self();
    }

    public static function reset()
    {
        self::$instance = new self();
    }

    public function execute($sql)
    {
        if ($this->broken) {
            throw new RuntimeException('Table ps_emporiqa_action_request does not exist');
        }
        if (preg_match('/^DELETE FROM `ps_emporiqa_action_request` WHERE `created_at` < (\d+)$/', $sql, $m)) {
            ++$this->deletes;
            $this->rows = array_filter($this->rows, function ($row) use ($m) {
                return $row['created_at'] >= (int) $m[1];
            });
        }

        return true;
    }

    public function getRow($sql)
    {
        preg_match('/`request_hash` = "([0-9a-f]{64})" AND `created_at` >= (\d+)/', $sql, $m);
        $row = $this->rows[$m[1]] ?? null;

        return $row && $row['created_at'] >= (int) $m[2] ? $row : false;
    }

    public function insert($table, array $data)
    {
        $this->rows[$data['request_hash']] = $data;

        return true;
    }

    /** A row as remember() would have written it $age seconds ago. */
    public function seed($requestId, $age)
    {
        $this->rows[hash('sha256', $requestId)] = [
            'request_hash' => hash('sha256', $requestId),
            'http_code' => 200,
            'response' => '{"status":"found","data":{"customer_name":"Anna Muster"}}',
            'created_at' => time() - $age,
        ];
    }

    public function has($requestId)
    {
        return isset($this->rows[hash('sha256', $requestId)]);
    }
}

class FakeChannelResolver
{
    public function isShopEnabled($shopId)
    {
        return false;
    }
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

$window = EmporiqaOrderStatus::DEDUPE_TTL_SECONDS;

echo "Scenario 1: reading a remembered answer deletes the expired ones\n";
Db::reset();
Db::getInstance()->seed('old', $window + 1);
Db::getInstance()->seed('recent', 30);
$replay = EmporiqaOrderStatus::remembered('recent');
check('the answer inside the window is replayed', is_array($replay) && $replay[0] === 200);
check('the expired answer is gone', !Db::getInstance()->has('old'));
check('the recent answer is kept', Db::getInstance()->has('recent'));

echo "Scenario 2: a lookup that remembers nothing still deletes\n";
Db::reset();
Db::getInstance()->seed('old', $window + 1);
check('no answer for an unknown request_id', EmporiqaOrderStatus::remembered('new') === null);
check('the expired answer is gone', !Db::getInstance()->has('old'));

echo "Scenario 3: writing an answer deletes the expired ones\n";
Db::reset();
Db::getInstance()->seed('old', $window + 1);
EmporiqaOrderStatus::remember('new', 200, '{"status":"found"}');
check('the expired answer is gone', !Db::getInstance()->has('old'));
check('the new answer is kept', Db::getInstance()->has('new'));

echo "Scenario 4: storefront page views delete them on a quiet shop\n";
Db::reset();
Db::getInstance()->seed('old', $window + 1);
$module = (new ReflectionClass('Emporiqa'))->newInstanceWithoutConstructor();
$module->context = new Context();
$module->context->shop = (object) ['id' => 1];
$resolver = new ReflectionProperty($module, 'channelResolver');
$resolver->setAccessible(true);
$resolver->setValue($module, new FakeChannelResolver());
mt_srand(1);
$views = 20 * EmporiqaOrderStatus::FORGET_ODDS;
for ($i = 0; $i < $views; ++$i) {
    $module->hookDisplayHeader([]);
}
$deletes = Db::getInstance()->deletes;
check('the expired answer is gone', !Db::getInstance()->has('old'));
check('only some page views delete (' . $deletes . ' of ' . $views . ')', $deletes > 0 && $deletes < $views / 5);

echo "Scenario 5: a failing delete never breaks the page\n";
Db::reset();
Db::getInstance()->broken = true;
$threw = false;
try {
    for ($i = 0; $i < 20 * EmporiqaOrderStatus::FORGET_ODDS; ++$i) {
        EmporiqaOrderStatus::forgetExpiredSometimes();
    }
} catch (Throwable $e) {
    $threw = true;
}
check('nothing thrown', !$threw);
check('the failure is logged', !empty(PrestaShopLogger::$logged));

if ($failures > 0) {
    echo "\n{$failures} assertion(s) FAILED\n";
    exit(1);
}
echo "\nAll assertions passed\n";
