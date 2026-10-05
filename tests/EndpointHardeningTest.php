<?php
/**
 * Locks in how the two server-to-server endpoints refuse what Emporiqa
 * never sends:
 *
 * - the connect origin proof MACs only a 64-character lowercase hex nonce,
 *   so whoever saw `state` cannot have the shop MAC a value of their choosing;
 * - an answer remembered for a request_id is replayed byte for byte, signed;
 * - the legacy order tracking endpoint answers a bare 500 on any failure,
 *   never a stack trace, and reads an order id only from plain digits.
 *
 * Self-contained (no PHPUnit, no PrestaShop). EmporiqaJsonResponse is a stub
 * that records the first answer and throws instead of exiting.
 *
 * Run: php tests/EndpointHardeningTest.php
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
define('_PS_VERSION_', '8.1.0');
define('_DB_PREFIX_', 'ps_');

class Sent extends Exception
{
}

class EmporiqaJsonResponse
{
    /** @var array{0: int, 1: mixed, 2: string[]}|null */
    public static $first;

    public static function send($httpCode, $data, array $headers = [])
    {
        if (self::$first === null) {
            self::$first = [(int) $httpCode, $data, $headers];
        }
        throw new Sent();
    }
}

class Context
{
    public $shop;
}

class Configuration
{
    public static $values = [];

    public static function get($key)
    {
        return self::$values[$key] ?? false;
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

class EmporiqaConnectNonce
{
    public static $verifier = 'the-code-verifier';

    public static function exchangingVerifier($state)
    {
        return $state === 'known-state' ? self::$verifier : null;
    }
}

class EmporiqaOrderStatus
{
    public static function rateLimitHit($orderNumber, $email, $shopId)
    {
        return null;
    }
}

class OrderList
{
    public function count()
    {
        return 0;
    }
}

class Order
{
    public static $loadedIds = [];

    public static $throw = false;

    public static function getByReference($reference)
    {
        if (self::$throw) {
            throw new RuntimeException('secret detail /var/www/html/classes/Order.php:42');
        }

        return new OrderList();
    }

    public function __construct($id)
    {
        self::$loadedIds[] = $id;
    }
}

class Validate
{
    public static function isLoadedObject($object)
    {
        return false;
    }
}

require __DIR__ . '/../classes/EmporiqaSignatureHelper.php';
require __DIR__ . '/../classes/EmporiqaActionEndpoint.php';
require __DIR__ . '/../classes/EmporiqaOrderTrackingEndpoint.php';

$failures = 0;
function check($label, $ok)
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        ++$failures;
    }
}

/**
 * Call a private method and return the first answer it sent.
 */
function answerOf($object, $method, array $args)
{
    EmporiqaJsonResponse::$first = null;
    $call = new ReflectionMethod($object, $method);
    $call->setAccessible(true);
    try {
        $call->invokeArgs($object, $args);
    } catch (Sent $e) {
    }

    return EmporiqaJsonResponse::$first;
}

$context = new Context();
$context->shop = (object) ['id' => 1];

echo "Scenario 1: the origin proof answers only a 64-hex nonce\n";
$action = new EmporiqaActionEndpoint($context);
$nonce = bin2hex(random_bytes(32));
$sent = answerOf($action, 'answerOriginProof', [['state' => 'known-state', 'nonce' => $nonce]]);
check('a 64-hex nonce is answered', $sent[0] === 200);
check('with HMAC(verifier, nonce)', $sent[0] === 200
    && json_decode($sent[1], true)['data']['nonce_mac'] === hash_hmac('sha256', $nonce, 'the-code-verifier'));
foreach ([
    'a chosen value' => 'sign-this-for-me',
    'uppercase hex' => strtoupper($nonce),
    '63 hex' => substr($nonce, 1),
    '64 hex and a newline' => $nonce . "\n",
    'not a string' => 42,
] as $label => $bad) {
    $sent = answerOf($action, 'answerOriginProof', [['state' => 'known-state', 'nonce' => $bad]]);
    check($label . ' is a 404', $sent[0] === 404);
}
$sent = answerOf($action, 'answerOriginProof', [['state' => 'other-state', 'nonce' => $nonce]]);
check('an unknown state is a 404', $sent[0] === 404);

echo "Scenario 2: a remembered answer is replayed as it was, signed\n";
$secret = 'shop-secret';
$storeId = 'store-123';
foreach (['secret' => $secret, 'storeId' => $storeId] as $name => $value) {
    $property = new ReflectionProperty($action, $name);
    $property->setAccessible(true);
    $property->setValue($action, $value);
}
$remembered = '{"status":"found","data":{"order_number":"ABC"}}';
$sent = answerOf($action, 'respond', [200, $remembered, 'req-1']);
check('the body is the remembered bytes', $sent[1] === $remembered);
$signature = substr($sent[2][0], strlen('X-Emporiqa-Response-Signature: '));
check('signed over request_id.body with the response key', EmporiqaSignatureHelper::verifyHeader(
    $signature,
    'req-1.' . $remembered,
    $secret,
    $storeId,
    EmporiqaSignatureHelper::LABEL_RESPONSE,
) === 'ok');
$sent = answerOf($action, 'respond', [404, ['status' => 'not_found']]);
check('an array is encoded and an unsigned answer has no signature',
    $sent[1] === '{"status":"not_found"}' && $sent[2] === []);

echo "Scenario 3: the legacy endpoint\n";
Configuration::$values = ['EMPORIQA_ORDER_TRACKING' => 1, 'EMPORIQA_WEBHOOK_SECRET' => $secret];
$legacy = new EmporiqaOrderTrackingEndpoint($context);

function legacyLookup($legacy, $identifier)
{
    $body = json_encode([
        'timestamp' => time(),
        'order_identifier' => $identifier,
        'verification_fields' => ['email' => 'a@example.com'],
    ]);
    $_SERVER['HTTP_X_EMPORIQA_SIGNATURE'] = EmporiqaSignatureHelper::generateSignature($body, 'shop-secret');

    return answerOf($legacy, 'answer', [$body]);
}

Order::$loadedIds = [];
$sent = legacyLookup($legacy, '12');
check('digits are tried as an order id', Order::$loadedIds === [12] && $sent[0] === 404);
foreach (['1e3', ' 12', '12.0', '-5', '0x1A'] as $notAnId) {
    Order::$loadedIds = [];
    $sent = legacyLookup($legacy, $notAnId);
    check('"' . $notAnId . '" is never read as an order id', Order::$loadedIds === [] && $sent[0] === 404);
}

Order::$throw = true;
PrestaShopLogger::$lines = [];
$sent = legacyLookup($legacy, 'ABC');
check('a failure is a 500', $sent[0] === 500);
check('that says nothing about the failure', $sent[1] === ['error' => 'Internal error.']);
check('the detail goes to the shop log', count(PrestaShopLogger::$lines) === 1
    && strpos(PrestaShopLogger::$lines[0], 'secret detail') !== false);

if ($failures > 0) {
    echo "\n{$failures} assertion(s) FAILED\n";
    exit(1);
}
echo "\nAll assertions passed\n";
exit(0);
