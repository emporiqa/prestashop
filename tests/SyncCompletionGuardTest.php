<?php
/**
 * Locks in the sync-completion guard: sync.complete must NOT be sent for a
 * session that had a failed batch or synced zero items, because completion
 * makes the Emporiqa backend delete every item unseen in the session.
 *
 * Self-contained (no PHPUnit, no PrestaShop): PS classes are stubbed below,
 * then the real EmporiqaSyncService is loaded and driven through
 * initSync → processBatch → completeSync.
 *
 * Run: php tests/SyncCompletionGuardTest.php
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

// ---------------------------------------------------------------------------
// PrestaShop stubs
// ---------------------------------------------------------------------------

class Configuration
{
    public static $values = [];

    public static function get($key)
    {
        return isset(self::$values[$key]) ? self::$values[$key] : false;
    }

    public static function getGlobalValue($key)
    {
        return self::get($key);
    }

    public static function updateGlobalValue($key, $value)
    {
        self::$values[$key] = $value;

        return true;
    }

    public static function deleteByName($key)
    {
        unset(self::$values[$key]);

        return true;
    }
}

class DbQuery
{
    public function __call($name, $args)
    {
        return $this;
    }
}

class Db
{
    public static $instance;

    /** @var array Rows returned by the next executeS() calls. */
    public $rows = [];

    /** @var int Value returned by getValue() (entity counts). */
    public $value = 0;

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getValue($sql)
    {
        return $this->value;
    }

    public function executeS($sql)
    {
        return $this->rows;
    }

    public function execute($sql)
    {
        return true;
    }
}

class Validate
{
    public static function isLoadedObject($object)
    {
        return true;
    }
}

class Hook
{
    public static function exec($name, $params = [])
    {
    }
}

class Product
{
    public $id;

    public function __construct($id)
    {
        $this->id = $id;
    }
}

class CMS
{
    public $id;

    public function __construct($id)
    {
        $this->id = $id;
    }
}

class EmporiqaWebhookClient
{
    public const FLUSH_BATCH_SIZE = 50;

    public $sendBatchReturn = true;

    public $completeCalls = [];

    public function startSyncSession($sessionId, $entity)
    {
        return true;
    }

    public function sendBatchEvents($events, $timeout = 30)
    {
        return $this->sendBatchReturn;
    }

    public function completeSyncSession($sessionId, $entity)
    {
        $this->completeCalls[] = [$sessionId, $entity];

        return true;
    }

    public function getLastError()
    {
        return 'stub error';
    }
}

class EmporiqaProductFormatter
{
    /** @var Exception|null Throw this from format() to simulate a fatal. */
    public $throwOnFormat;

    public static function clearBatchCaches()
    {
    }

    public function format($product, $sessionId)
    {
        if ($this->throwOnFormat) {
            throw $this->throwOnFormat;
        }

        return [['id' => (string) $product->id, 'title' => 'Stub product']];
    }
}

class EmporiqaPageFormatter
{
    public function format($cms, $sessionId)
    {
        return ['id' => (string) $cms->id, 'title' => 'Stub page'];
    }
}

require dirname(__DIR__) . '/classes/EmporiqaSyncService.php';

// ---------------------------------------------------------------------------
// Minimal assertion harness
// ---------------------------------------------------------------------------

$failures = 0;

function check($label, $condition)
{
    global $failures;
    if ($condition) {
        echo "  ok    {$label}\n";
    } else {
        ++$failures;
        echo "  FAIL  {$label}\n";
    }
}

function freshService()
{
    Configuration::$values = [];
    Db::$instance = new Db();
    $client = new EmporiqaWebhookClient();
    $service = new EmporiqaSyncService($client, new EmporiqaProductFormatter(), new EmporiqaPageFormatter());

    return [$service, $client];
}

function initProductSession($service)
{
    Db::getInstance()->value = 1; // countProducts()
    $init = $service->initSync('products');
    check('initSync succeeds', !empty($init['success']));

    return $init['sessions'][0]['session_id'];
}

// ---------------------------------------------------------------------------
// Scenario 1: every batch succeeds -> completion IS sent
// ---------------------------------------------------------------------------

echo "Scenario 1: all batches succeed => sync.complete sent\n";
list($service, $client) = freshService();
$sessionId = initProductSession($service);

Db::getInstance()->rows = [['id_product' => 1]];
$batch = $service->processBatch('products', $sessionId, 1);
check('batch succeeds', !empty($batch['success']) && $batch['processed'] === 1);

$complete = $service->completeSync('products', $sessionId);
check('completeSync succeeds', !empty($complete['success']));
check('completeSyncSession called exactly once', count($client->completeCalls) === 1);

// ---------------------------------------------------------------------------
// Scenario 2: a batch fails -> completion is REFUSED
// ---------------------------------------------------------------------------

echo "Scenario 2: failed batch => sync.complete refused\n";
list($service, $client) = freshService();
$sessionId = initProductSession($service);

Db::getInstance()->rows = [['id_product' => 1]];
$client->sendBatchReturn = false;
$batch = $service->processBatch('products', $sessionId, 1);
check('batch reports failure', empty($batch['success']));

$client->sendBatchReturn = true; // backend recovered — must not matter
$complete = $service->completeSync('products', $sessionId);
check('completeSync refuses', empty($complete['success']));
check('refusal mentions failed batches', strpos($complete['error'], 'batch(es) failed') !== false);
check('completeSyncSession NOT called', count($client->completeCalls) === 0);

// ---------------------------------------------------------------------------
// Scenario 3: zero items synced -> completion is REFUSED
// ---------------------------------------------------------------------------

echo "Scenario 3: nothing synced => sync.complete refused\n";
list($service, $client) = freshService();
$sessionId = initProductSession($service);

Db::getInstance()->rows = []; // empty batch, technically "successful"
$batch = $service->processBatch('products', $sessionId, 1);
check('empty batch succeeds', !empty($batch['success']) && $batch['processed'] === 0);

$complete = $service->completeSync('products', $sessionId);
check('completeSync refuses', empty($complete['success']));
check('refusal mentions no items', strpos($complete['error'], 'no items were synced') !== false);
check('completeSyncSession NOT called', count($client->completeCalls) === 0);

// ---------------------------------------------------------------------------
// Scenario 4: unknown session id -> completion is REFUSED
// ---------------------------------------------------------------------------

echo "Scenario 4: unknown session => sync.complete refused\n";
list($service, $client) = freshService();

$complete = $service->completeSync('products', 'ps-products-never-started');
check('completeSync refuses', empty($complete['success']));
check('completeSyncSession NOT called', count($client->completeCalls) === 0);

// ---------------------------------------------------------------------------
// Scenario 5: fatal mid-batch (exception) -> session poisoned, refused
// ---------------------------------------------------------------------------

echo "Scenario 5: fatal mid-batch => session poisoned, sync.complete refused\n";
Configuration::$values = [];
Db::$instance = new Db();
$client = new EmporiqaWebhookClient();
$formatter = new EmporiqaProductFormatter();
$formatter->throwOnFormat = new RuntimeException('boom');
$service = new EmporiqaSyncService($client, $formatter, new EmporiqaPageFormatter());
$sessionId = initProductSession($service);

Db::getInstance()->rows = [['id_product' => 1]];
$threw = false;
try {
    $service->processBatch('products', $sessionId, 1);
} catch (RuntimeException $e) {
    $threw = true;
}
check('batch threw', $threw);

$complete = $service->completeSync('products', $sessionId);
check('completeSync refuses', empty($complete['success']));
check('completeSyncSession NOT called', count($client->completeCalls) === 0);

// ---------------------------------------------------------------------------
// Scenario 6: two interleaved sessions — a failure in one must survive
// activity in the other (per-session rows, no shared-blob lost update)
// ---------------------------------------------------------------------------

echo "Scenario 6: interleaved sessions keep independent error marks\n";
list($service, $client) = freshService();
$sessionA = initProductSession($service);
$sessionB = initProductSession($service);

Db::getInstance()->rows = [['id_product' => 1]];
$client->sendBatchReturn = false;
$service->processBatch('products', $sessionA, 1); // A fails
$client->sendBatchReturn = true;
$service->processBatch('products', $sessionB, 1); // B succeeds afterwards

$completeA = $service->completeSync('products', $sessionA);
check('failed session A refused', empty($completeA['success']));
$completeB = $service->completeSync('products', $sessionB);
check('healthy session B completes', !empty($completeB['success']));
check('only B reached the backend', count($client->completeCalls) === 1 && $client->completeCalls[0][0] === $sessionB);

// ---------------------------------------------------------------------------

if ($failures > 0) {
    echo "\n{$failures} assertion(s) FAILED\n";
    exit(1);
}
echo "\nAll assertions passed\n";
exit(0);
