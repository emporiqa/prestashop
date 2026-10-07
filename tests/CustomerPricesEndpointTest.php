<?php
/**
 * Locks in how the action endpoint guards `customer_prices`, with the same
 * code as order_status:
 *
 * - only a request signed with this shop's secret for emporiqa-to-plugin,
 *   within 5 minutes, reaches the action (a wrong key, a wrong label, an old
 *   timestamp or a `rule` that is not the `key` are refused);
 * - a request_id already answered gets the same bytes back, signed, without
 *   running the action or counting against the limits again;
 * - a call over a limit is a signed 429 rate_limited naming the scope, with
 *   Retry-After;
 * - a failure is a signed 500 that says nothing about it;
 * - `customer_info` goes through the same guards to its own action and its
 *   own limits, and its rule must match its key too.
 *
 * Self-contained (no PHPUnit, no PrestaShop). EmporiqaJsonResponse is a stub
 * that records the answer and throws instead of exiting.
 *
 * Run: php tests/CustomerPricesEndpointTest.php
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
define('_PS_VERSION_', '8.1.0');

class Sent extends Exception
{
}

class EmporiqaJsonResponse
{
    /** @var array{0: int, 1: mixed, 2: string[]}|null */
    public static $sent;

    public static function send($httpCode, $data, array $headers = [])
    {
        // The first answer only: the real send exits, so nothing after it runs.
        if (self::$sent === null) {
            self::$sent = [(int) $httpCode, $data, $headers];
        }
        throw new Sent();
    }

    public static function encode($data, $flags = 0)
    {
        return json_encode($data, $flags);
    }
}

class Context
{
    public $shop;
}

class Configuration
{
    public static $values = ['EMPORIQA_WEBHOOK_SECRET' => 'shop-secret', 'EMPORIQA_STORE_ID' => 'store-123'];

    public static function get($key)
    {
        return self::$values[$key] ?? false;
    }
}

class Tools
{
    public static $key = 'customer_prices';

    public static function getValue($name)
    {
        return $name === 'key' ? self::$key : false;
    }

    public static function strlen($value)
    {
        return strlen($value);
    }
}

class PrestaShopLogger
{
    public static $lines = [];

    public static function addLog($message)
    {
        self::$lines[] = $message;
    }
}

class EmporiqaOrderStatus
{
    /** @var array<string, array{0: int, 1: string}> */
    public static $memory = [];

    public static function remembered($requestId)
    {
        return self::$memory[$requestId] ?? null;
    }

    public static function remember($requestId, $httpCode, $body)
    {
        self::$memory[$requestId] = [$httpCode, $body];
    }
}

class EmporiqaCustomerPrices
{
    public static $handled = 0;
    public static $counted = 0;
    public static $limit;
    public static $throw = false;

    public function __construct($context)
    {
    }

    public static function rateLimitHit(array $payload, $shopId)
    {
        ++self::$counted;

        return self::$limit;
    }

    public function handle(array $payload)
    {
        if (self::$throw) {
            throw new RuntimeException('secret detail /var/www/x.php:42');
        }
        ++self::$handled;

        return ['status' => 'found', 'data' => ['currency' => 'EUR', 'prices_include_tax' => true, 'products' => ['product-1' => ['current_price' => 9.5]]]];
    }
}

class EmporiqaCustomerInfo
{
    public static $handled = 0;
    public static $counted = 0;
    public static $limit;

    public function __construct($context)
    {
    }

    public static function rateLimitHit(array $payload, $shopId)
    {
        ++self::$counted;

        return self::$limit;
    }

    public function handle(array $payload)
    {
        ++self::$handled;

        return ['status' => 'found', 'data' => ['customer' => ['name' => 'Emporiqa Test'], 'orders' => []]];
    }
}

require __DIR__ . '/../classes/EmporiqaSignatureHelper.php';
require __DIR__ . '/../classes/EmporiqaActionEndpoint.php';

class TestEndpoint extends EmporiqaActionEndpoint
{
    public static $body = '';

    protected function requestBody()
    {
        return self::$body;
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

function post(array $payload, $secret = 'shop-secret', $label = EmporiqaSignatureHelper::LABEL_OUTBOUND, $timestamp = null)
{
    $body = json_encode($payload);
    $key = EmporiqaSignatureHelper::deriveKey($secret, $label, 'store-123');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_X_EMPORIQA_ACTION_SIGNATURE'] = EmporiqaSignatureHelper::buildHeader($key, $body, $timestamp);
    TestEndpoint::$body = $body;
    $context = new Context();
    $context->shop = (object) ['id' => 1];
    EmporiqaJsonResponse::$sent = null;
    try {
        (new TestEndpoint($context))->run();
    } catch (Sent $e) {
    }

    return EmporiqaJsonResponse::$sent;
}

function signedOk(array $sent, $requestId)
{
    foreach ($sent[2] as $header) {
        if (strpos($header, 'X-Emporiqa-Response-Signature: ') === 0) {
            return EmporiqaSignatureHelper::verifyHeader(
                substr($header, strlen('X-Emporiqa-Response-Signature: ')),
                $requestId . '.' . (is_array($sent[1]) ? json_encode($sent[1]) : $sent[1]),
                'shop-secret',
                'store-123',
                EmporiqaSignatureHelper::LABEL_RESPONSE,
            ) === 'ok';
        }
    }

    return false;
}

$request = ['rule' => 'customer_prices', 'request_id' => 'req-1', 'customer' => ['id' => '77'], 'products' => ['product-1']];

echo "Scenario 1: only a valid signature reaches the action\n";
foreach ([
    'another secret' => ['other-secret', EmporiqaSignatureHelper::LABEL_OUTBOUND, null],
    'the response label' => ['shop-secret', EmporiqaSignatureHelper::LABEL_RESPONSE, null],
    'a 6-minute-old timestamp' => ['shop-secret', EmporiqaSignatureHelper::LABEL_OUTBOUND, time() - 360],
] as $label => [$secret, $purpose, $timestamp]) {
    $sent = post($request, $secret, $purpose, $timestamp);
    check($label . ' is a 401', $sent[0] === 401);
}
check('none of them ran it', EmporiqaCustomerPrices::$handled === 0 && EmporiqaCustomerPrices::$counted === 0);
$sent = post(['rule' => 'order_status'] + $request);
check('a rule other than the key is a 404', $sent[0] === 404);

echo "Scenario 2: a good call is answered and signed\n";
$sent = post($request);
check('200 with the prices', $sent[0] === 200 && json_decode($sent[1], true)['data']['products']['product-1']['current_price'] === 9.5);
check('signed over request_id.body', signedOk($sent, 'req-1'));

echo "Scenario 3: a replayed request_id gets the same answer, free\n";
$first = $sent[1];
$sent = post($request);
check('same bytes, signed', $sent[0] === 200 && $sent[1] === $first && signedOk($sent, 'req-1'));
check('not run or counted again', EmporiqaCustomerPrices::$handled === 1 && EmporiqaCustomerPrices::$counted === 1);

echo "Scenario 4: over a limit\n";
EmporiqaCustomerPrices::$limit = ['scope' => 'value', 'retry_after' => 120];
$sent = post(['request_id' => 'req-2'] + $request);
$body = json_decode($sent[1], true);
check('a signed 429 rate_limited with its scope', $sent[0] === 429 && $body['message_code'] === 'rate_limited'
    && $body['data']['scope'] === 'value' && signedOk($sent, 'req-2'));
check('with Retry-After', in_array('Retry-After: 120', $sent[2], true));
EmporiqaCustomerPrices::$limit = null;

echo "Scenario 5: a failure says nothing about it\n";
EmporiqaCustomerPrices::$throw = true;
$sent = post(['request_id' => 'req-3'] + $request);
check('a signed 500', $sent[0] === 500 && $sent[1] === '{"status":"error","message_code":"internal"}' && signedOk($sent, 'req-3'));
check('the detail goes to the shop log only', strpos(implode(' ', PrestaShopLogger::$lines), 'secret detail') !== false);

echo "Scenario 6: customer_info has its own action and limits\n";
EmporiqaCustomerPrices::$throw = false;
$prices = [EmporiqaCustomerPrices::$handled, EmporiqaCustomerPrices::$counted];
$info = ['rule' => 'customer_info', 'request_id' => 'info-1', 'customer' => ['id' => '77']];
Tools::$key = 'customer_info';
$sent = post($info, 'other-secret');
check('a wrong secret is a 401 and runs nothing', $sent[0] === 401 && EmporiqaCustomerInfo::$handled === 0 && EmporiqaCustomerInfo::$counted === 0);
$sent = post($info);
check('answered by customer_info, signed', $sent[0] === 200 && json_decode($sent[1], true)['data']['customer']['name'] === 'Emporiqa Test'
    && signedOk($sent, 'info-1'));
check('counted on its own limits only', EmporiqaCustomerInfo::$counted === 1 && EmporiqaCustomerInfo::$handled === 1
    && [EmporiqaCustomerPrices::$handled, EmporiqaCustomerPrices::$counted] === $prices);
$sent = post(['rule' => 'customer_prices', 'request_id' => 'info-2'] + $info);
check('a rule other than the key is a 404', $sent[0] === 404);
EmporiqaCustomerInfo::$limit = ['scope' => 'store', 'retry_after' => 30];
$sent = post(['request_id' => 'info-3'] + $info);
check('over its limit: a signed 429', $sent[0] === 429 && json_decode($sent[1], true)['data']['scope'] === 'store' && signedOk($sent, 'info-3'));

if ($failures > 0) {
    echo "\n{$failures} assertion(s) FAILED\n";
    exit(1);
}
echo "\nAll assertions passed\n";
exit(0);
