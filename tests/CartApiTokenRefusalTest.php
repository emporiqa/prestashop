<?php
/**
 * Locks in how the cart API refuses a token that is not this visitor's.
 *
 * What must never regress: a refused token changes nothing (the cart handler
 * is never reached) and the refusal says `invalid_token` and carries this
 * visitor's own token, so views/js/front-cart-handler.js can retry once. A
 * page from a full-page cache holds the token of whoever it was rendered
 * for, and without this every other visitor's add to cart from the chat
 * fails. A valid token goes through with no refusal fields.
 *
 * Self-contained (no PHPUnit, no PrestaShop). EmporiqaJsonResponse is a stub
 * that records the answer and throws instead of exiting.
 *
 * Run: php tests/CartApiTokenRefusalTest.php
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
        self::$sent = [(int) $httpCode, $data, $headers];
        throw new Sent();
    }
}

class Configuration
{
    public static function get($key)
    {
        return false;
    }
}

class Tools
{
    public static $request = [];

    public static function isSubmit($key)
    {
        return isset(self::$request[$key]);
    }

    public static function getValue($key, $default = false)
    {
        return self::$request[$key] ?? $default;
    }
}

class PrestaShopLogger
{
    public static function addLog($message)
    {
    }
}

class Context
{
}

class Emporiqa
{
    /** @var string the token the visitor's cookie nonce gives */
    public $token = 'visitor-own-token';

    /** @var bool[] the $mintNonce argument of every call */
    public $calls = [];

    public function getCartApiToken($mintNonce = true)
    {
        $this->calls[] = $mintNonce;

        return $this->token;
    }
}

class EmporiqaCartHandler
{
    /** @var string[] */
    public static $called = [];

    public function __construct($context)
    {
    }

    public function get()
    {
        self::$called[] = 'get';

        return ['success' => true, 'cart' => ['items' => []]];
    }

    public function add($productId, $variationId, $quantity)
    {
        self::$called[] = 'add';

        return ['success' => true, 'cart' => ['items' => []]];
    }
}

class FakeController
{
    public function presentCartForTheme()
    {
        return ['products' => []];
    }
}

require __DIR__ . '/../classes/EmporiqaCartApiEndpoint.php';

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
 * @return array{0: int, 1: array}
 */
function call(array $request, $module)
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    Tools::$request = $request;
    EmporiqaJsonResponse::$sent = null;
    EmporiqaCartHandler::$called = [];
    try {
        (new EmporiqaCartApiEndpoint(new Context(), $module, new FakeController()))->run();
    } catch (Sent $e) {
    }

    return [EmporiqaJsonResponse::$sent[0], EmporiqaJsonResponse::$sent[1]];
}

echo "A token rendered for another visitor\n";
$module = new Emporiqa();
[$status, $body] = call(['action' => 'add', 'token' => 'someone-elses-token', 'product_id' => '7'], $module);
check('is refused', $status === 200 && $body['success'] === false);
check('changes nothing (the cart handler is never reached)', EmporiqaCartHandler::$called === []);
check('says invalid_token', ($body['code'] ?? null) === 'invalid_token');
check('carries this visitor\'s own token', ($body['token'] ?? null) === 'visitor-own-token');
check('the check never mints, the refusal does', $module->calls === [false, true]);
check('keeps the error a refresh would fix', strpos((string) $body['error'], 'refresh') !== false);
check('is not cached', in_array('Cache-Control: no-store, private', EmporiqaJsonResponse::$sent[2], true));

echo "No token at all\n";
[$status, $body] = call(['action' => 'get', 'token' => ''], new Emporiqa());
check('is refused with a fresh token too', ($body['code'] ?? null) === 'invalid_token' && ($body['token'] ?? null) === 'visitor-own-token');
check('changes nothing', EmporiqaCartHandler::$called === []);

echo "No nonce in the cookie yet (cached page, first request)\n";
$module = new Emporiqa();
$module->token = '';
[$status, $body] = call(['action' => 'get', 'token' => 'cached-token'], $module);
check('is refused', ($body['code'] ?? null) === 'invalid_token');

echo "The visitor's own token\n";
$module = new Emporiqa();
[$status, $body] = call(['action' => 'add', 'token' => 'visitor-own-token', 'product_id' => '7'], $module);
check('goes through', $body['success'] === true && EmporiqaCartHandler::$called === ['add']);
check('carries no refusal fields', !isset($body['code']) && !isset($body['token']));

echo "Not the Emporiqa module\n";
[$status, $body] = call(['action' => 'get', 'token' => 'anything'], null);
check('is refused without a token', $body['success'] === false && !isset($body['token']));
check('changes nothing', EmporiqaCartHandler::$called === []);

echo "A GET\n";
$_SERVER['REQUEST_METHOD'] = 'GET';
Tools::$request = ['action' => 'get', 'token' => 'x'];
EmporiqaJsonResponse::$sent = null;
try {
    (new EmporiqaCartApiEndpoint(new Context(), new Emporiqa(), new FakeController()))->run();
} catch (Sent $e) {
}
check('is 405 and hands out no token', EmporiqaJsonResponse::$sent[0] === 405 && !isset(EmporiqaJsonResponse::$sent[1]['token']));

if ($failures) {
    echo "\n$failures check(s) FAILED\n";
    exit(1);
}
echo "\nAll checks passed\n";
