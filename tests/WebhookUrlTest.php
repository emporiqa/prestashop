<?php
/**
 * Locks in where the module may send the catalog and its signed webhooks:
 * an https URL on the Emporiqa host (EMPORIQA_BASE_URL's when set, else
 * emporiqa.com), whether it arrives with a connect answer or is typed into
 * the settings form. Anything else (http, another host, a lookalike host,
 * credentials or a port trick) is refused.
 *
 * Self-contained (no PHPUnit, no PrestaShop): PrestaShop classes are stubbed
 * and the real EmporiqaConnectHandshake is loaded.
 *
 * Run: php tests/WebhookUrlTest.php
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
define('_PS_VERSION_', '8.1.0');

class Configuration
{
    public static $values = [];

    public static function get($key)
    {
        return self::$values[$key] ?? false;
    }
}

require __DIR__ . '/../classes/EmporiqaConnectHandshake.php';

$failures = 0;
function check($label, $ok)
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        ++$failures;
    }
}

echo "Scenario 1: the production host\n";
check('https emporiqa.com', EmporiqaConnectHandshake::isEmporiqaWebhookUrl('https://emporiqa.com/webhooks/sync/'));
check('host case does not matter', EmporiqaConnectHandshake::isEmporiqaWebhookUrl('https://EMPORIQA.com/webhooks/sync/'));
foreach ([
    'http://emporiqa.com/webhooks/sync/' => 'http',
    'https://evil.test/webhooks/sync/' => 'another host',
    'https://emporiqa.com.evil.test/webhooks/sync/' => 'a lookalike host',
    'https://evil.test/?https://emporiqa.com/' => 'the host only in the query',
    'https://emporiqa.com@evil.test/' => 'credentials in front of another host',
    '//emporiqa.com/webhooks/sync/' => 'no scheme',
    'not a url' => 'not a URL',
] as $url => $label) {
    check('refused: ' . $label, !EmporiqaConnectHandshake::isEmporiqaWebhookUrl($url));
}

echo "Scenario 2: a staging base URL moves the allowed host\n";
Configuration::$values['EMPORIQA_BASE_URL'] = 'https://test.emporiqa.com';
check('its host', EmporiqaConnectHandshake::isEmporiqaWebhookUrl('https://test.emporiqa.com/webhooks/sync/'));
check('and no longer production', !EmporiqaConnectHandshake::isEmporiqaWebhookUrl('https://emporiqa.com/webhooks/sync/'));

if ($failures > 0) {
    echo "\n{$failures} assertion(s) FAILED\n";
    exit(1);
}
echo "\nAll assertions passed\n";
exit(0);
