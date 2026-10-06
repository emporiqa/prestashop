<?php
/**
 * Locks in the scheme of the address the Sync tab posts to.
 *
 * What must never regress: behind a proxy or CDN that ends TLS and forwards
 * plain http (X-Forwarded-Proto: https, no HTTPS server variable), the
 * address is https, as PrestaShop's own Tools::usingSecureMode() decides. An
 * http address on an https page is blocked as mixed content, and every Sync
 * tab button then fails with "The sync could not start." and no reason.
 *
 * Self-contained (no PHPUnit, no PrestaShop): PrestaShop classes are stubbed
 * and the real Emporiqa module class is loaded.
 *
 * Run: php tests/AdminSyncUrlTest.php
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
    /** Same order of checks as PrestaShop 8.1 and 9 core. */
    public static function usingSecureMode()
    {
        if (isset($_SERVER['HTTPS'])) {
            return in_array(strtolower($_SERVER['HTTPS']), [1, 'on'], false);
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            return strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
        }

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

function configureUrl(array $server)
{
    $_SERVER = $server + ['REQUEST_URI' => '/admin-x/index.php?controller=AdminModules&configure=emporiqa', 'HTTP_HOST' => 'shop.test'];
    $module = (new ReflectionClass('Emporiqa'))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($module, 'getConfigureUrl');
    $method->setAccessible(true);

    return $method->invoke($module);
}

echo "Behind a proxy that ends TLS\n";
$url = configureUrl(['HTTP_X_FORWARDED_PROTO' => 'https']);
check('is https', strpos($url, 'https://shop.test/admin-x/') === 0);

echo "HTTPS on the server itself\n";
check('is https', strpos(configureUrl(['HTTPS' => 'on']), 'https://') === 0);

echo "Plain http\n";
check('stays http', strpos(configureUrl([]), 'http://shop.test/') === 0);
check('stays http with HTTPS=off', strpos(configureUrl(['HTTPS' => 'off']), 'http://') === 0);

if ($failures) {
    echo "\n$failures check(s) FAILED\n";
    exit(1);
}
echo "\nAll checks passed\n";
