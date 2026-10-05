<?php
/**
 * Locks in two answers Emporiqa reads: the order_status 429 says which limit
 * was hit (`value` or `store`) and when its window ends, and a response Date
 * header turns into this server's clock skew. Also that the limiter fails
 * closed: a counter it cannot write is the shop limit, never a free lookup.
 *
 * Self-contained (no PHPUnit, no PrestaShop): Db is a fake that keeps the
 * rate counters in memory and understands only the statements
 * EmporiqaOrderStatus::rateLimitHit() emits.
 *
 * Run: php tests/RateLimitAndClockTest.php
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

class Tools
{
    public static function strtolower($string)
    {
        return mb_strtolower($string);
    }
}

class Db
{
    /** @var array<string, int> */
    public $hits = [];

    /** @var bool when true every write fails, as on a full disk or a missing table */
    public $failWrites = false;

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
        if ($this->failWrites) {
            return false;
        }
        if (preg_match('/VALUES \("([0-9a-f]{64})"/', $sql, $m)) {
            $this->hits[$m[1]] = ($this->hits[$m[1]] ?? 0) + 1;
        }

        return true;
    }

    public function getValue($sql, $useCache = true)
    {
        preg_match('/"([0-9a-f]{64})"/', $sql, $m);

        return $this->hits[$m[1]] ?? false;
    }
}

require __DIR__ . '/../classes/EmporiqaOrderStatus.php';
require __DIR__ . '/../classes/EmporiqaWebhookClient.php';

$failures = 0;
function check($label, $ok)
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        ++$failures;
    }
}

function lookup($order, $email)
{
    return EmporiqaOrderStatus::rateLimitHit($order, $email, 1);
}

echo "Scenario 1: one order number asked too often is a value limit\n";
Db::reset();
for ($i = 0; $i < EmporiqaOrderStatus::RATE_PER_VALUE; ++$i) {
    $last = lookup('ABC', 'a@example.com');
}
check('under the limit answers null', $last === null);
$hit = lookup('ABC', 'a@example.com');
check('over the limit has scope value', is_array($hit) && $hit['scope'] === 'value');
check('retry_after is within the window',
    $hit['retry_after'] >= 1 && $hit['retry_after'] <= EmporiqaOrderStatus::RATE_WINDOW_SECONDS);

echo "Scenario 2: the shop ceiling is a store limit\n";
Db::reset();
for ($i = 0; $i < EmporiqaOrderStatus::RATE_PER_SHOP; ++$i) {
    lookup('O' . $i, 'e' . $i . '@example.com');
}
$hit = lookup('NEW', 'new@example.com');
check('varied values over the shop ceiling have scope store', is_array($hit) && $hit['scope'] === 'store');

echo "Scenario 3: both over says store\n";
Db::reset();
for ($i = 0; $i < EmporiqaOrderStatus::RATE_PER_SHOP; ++$i) {
    lookup('SAME', 'same@example.com');
}
$hit = lookup('SAME', 'same@example.com');
check('shop and value both over has scope store', is_array($hit) && $hit['scope'] === 'store');

echo "Scenario 4: a counter that cannot be written is the shop limit\n";
Db::reset();
Db::getInstance()->failWrites = true;
$hit = lookup('ABC', 'a@example.com');
check('a failed write is limited with scope store', is_array($hit) && $hit['scope'] === 'store');
$hit = lookup('', '');
check('even with no order number or email', is_array($hit) && $hit['scope'] === 'store');

echo "Scenario 5: clock skew from the Date header\n";
$now = 1790000000;
check('a clock 10 minutes ahead is +600',
    EmporiqaWebhookClient::clockSkew(gmdate('D, d M Y H:i:s', $now - 600) . ' GMT', $now) === 600);
check('a clock behind is negative',
    EmporiqaWebhookClient::clockSkew(gmdate('D, d M Y H:i:s', $now + 90) . ' GMT', $now) === -90);
check('no header is null', EmporiqaWebhookClient::clockSkew('', $now) === null);
check('a garbled header is null', EmporiqaWebhookClient::clockSkew('not a date', $now) === null);

if ($failures > 0) {
    echo "\n{$failures} assertion(s) FAILED\n";
    exit(1);
}
echo "\nAll assertions passed\n";
exit(0);
