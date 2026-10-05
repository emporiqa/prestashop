<?php
/**
 * Locks in what a bulk edit costs when it is flushed after the response:
 *
 * - the queued products go out in requests of about FLUSH_BATCH_SIZE events,
 *   not one request per product, and a product's parent and variations are
 *   never split across two requests;
 * - delete still wins over an update, and a stock-only event is dropped for a
 *   product that also got a full sync;
 * - a failing automatic send stamps EMPORIQA_LAST_AUTO_FAIL once per request,
 *   not once per send.
 *
 * Self-contained (no PHPUnit, no PrestaShop): PrestaShop classes are stubbed,
 * the real Emporiqa module class is loaded, and the webhook client and
 * product formatter are fakes.
 *
 * Run: php tests/HookDispatchTest.php
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
    public static function addLog($message)
    {
    }
}

class Configuration
{
    public static $values = [];

    public static $writes = [];

    public static function get($key)
    {
        return self::$values[$key] ?? false;
    }

    public static function updateGlobalValue($key, $value)
    {
        self::$writes[] = $key;
        self::$values[$key] = $value;

        return true;
    }
}

class Product
{
    /** @var array<int, int> productId => number of variations */
    public static $variations = [];

    public $id;

    public $active = true;

    public function __construct($id)
    {
        $this->id = $id;
    }

    public static function getProductAttributesIds($id)
    {
        $ids = [];
        for ($i = 1; $i <= (self::$variations[$id] ?? 0); ++$i) {
            $ids[] = ['id_product_attribute' => $id * 1000 + $i];
        }

        return $ids;
    }
}

require __DIR__ . '/../emporiqa.php';

class FakeFormatter extends EmporiqaProductFormatter
{
    public function __construct()
    {
    }

    public function format(Product $product, $syncSessionId = null)
    {
        $items = [['identification_number' => 'product-' . $product->id]];
        for ($i = 1; $i <= (Product::$variations[$product->id] ?? 0); ++$i) {
            $items[] = ['identification_number' => 'variation-' . ($product->id * 1000 + $i)];
        }

        return $items;
    }

    public function formatAvailability(Product $product)
    {
        return [['identification_number' => 'product-' . $product->id]];
    }
}

class RecordingClient extends EmporiqaWebhookClient
{
    /** @var array<int, array> one entry per request */
    public $requests = [];

    public function __construct()
    {
    }

    public function dispatchEvents(array $events)
    {
        $this->requests[] = $events;

        return true;
    }
}

class FailingClient extends EmporiqaWebhookClient
{
    public function __construct()
    {
    }

    public function sendBatchEvents(array $events, $timeout = 10)
    {
        return false;
    }
}

$failures = 0;
function check($label, $ok)
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        ++$failures;
    }
}

function setPrivate($object, $name, $value)
{
    $property = new ReflectionProperty($object, $name);
    $property->setAccessible(true);
    $property->setValue($object, $value);
}

/**
 * Flush $pending through a fresh module and return the requests it sent.
 */
function flushQueued(array $pending)
{
    $module = (new ReflectionClass('Emporiqa'))->newInstanceWithoutConstructor();
    $client = new RecordingClient();
    setPrivate($module, 'webhookClient', $client);
    setPrivate($module, 'productFormatter', new FakeFormatter());
    setPrivate($module, 'pending', $pending + [
        'orders' => [],
        'product_syncs' => [],
        'product_deletes' => [],
        'stock' => [],
        'page_syncs' => [],
        'page_deletes' => [],
    ]);
    $module->flushPendingProductSyncs();

    return $client->requests;
}

function ids(array $requests)
{
    $ids = [];
    foreach ($requests as $events) {
        foreach ($events as $event) {
            $ids[] = $event['type'] . ' ' . $event['data']['identification_number'];
        }
    }

    return $ids;
}

echo "Scenario 1: a bulk edit of 120 simple products is 3 requests, not 120\n";
Product::$variations = [];
$syncs = [];
for ($id = 1; $id <= 120; ++$id) {
    $syncs[$id] = 'product.updated';
}
$requests = flushQueued(['product_syncs' => $syncs]);
check('3 requests', count($requests) === 3);
check('none over the batch size', max(array_map('count', $requests)) <= EmporiqaWebhookClient::FLUSH_BATCH_SIZE);
check('every product sent once', count(ids($requests)) === 120 && count(array_unique(ids($requests))) === 120);

echo "Scenario 2: a product's parent and variations stay in one request\n";
Product::$variations = [1 => 30, 2 => 30, 3 => 70];
$requests = flushQueued(['product_syncs' => [1 => 'product.updated', 2 => 'product.updated', 3 => 'product.updated']]);
check('30 + 30 cannot share a 50-event request, so 3 requests', count($requests) === 3);
check('the 71-event product goes alone and whole', count($requests[2]) === 71);
$split = false;
foreach ($requests as $events) {
    $parents = array_filter($events, function ($event) {
        return strpos($event['data']['identification_number'], 'product-') === 0;
    });
    $split = $split || count($parents) !== 1;
}
check('each request holds exactly one parent here', !$split);

echo "Scenario 3: delete wins, and stock is dropped for a fully synced product\n";
Product::$variations = [7 => 2];
$requests = flushQueued([
    'product_syncs' => [5 => 'product.updated', 7 => 'product.updated'],
    'product_deletes' => [7 => true],
    'stock' => [5 => true, 9 => true],
]);
check('one request', count($requests) === 1);
check('sync 5, delete 7 with its variations, stock 9', ids($requests) === [
    'product.updated product-5',
    'product.deleted product-7',
    'product.deleted variation-7001',
    'product.deleted variation-7002',
    'product.availability product-9',
]);

echo "Scenario 4: nothing queued sends nothing\n";
check('no request', flushQueued([]) === []);

echo "Scenario 5: a failing automatic send stamps the failure once per request\n";
Configuration::$values = [
    'EMPORIQA_WEBHOOK_URL' => 'https://emporiqa.test/webhooks/sync/',
    'EMPORIQA_WEBHOOK_SECRET' => 'secret',
    'EMPORIQA_STORE_ID' => 'store',
];
Configuration::$writes = [];
$client = new FailingClient();
for ($i = 0; $i < 5; ++$i) {
    $client->dispatchEvents([['type' => 'product.updated', 'data' => []]]);
}
check('five failed sends, one config write',
    array_count_values(Configuration::$writes)[EmporiqaWebhookClient::LAST_AUTO_FAIL_KEY] === 1);

if ($failures > 0) {
    echo "\n{$failures} assertion(s) FAILED\n";
    exit(1);
}
echo "\nAll assertions passed\n";
exit(0);
