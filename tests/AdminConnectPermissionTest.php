<?php
/**
 * Locks in who may connect the shop to Emporiqa and open its settings.
 *
 * What must never regress: an employee without the module's configure
 * permission never reaches the one-click connect handshake, and the settings
 * page refuses them before the form is read or shown. Ticking the Emporiqa
 * menu entry for a profile also grants the hidden AdminEmporiqaConnect tab,
 * and PrestaShop opens a module's settings page for anyone who may see the
 * module list; both paths replace the store id and the secret, so without
 * this check such an employee could point the shop at an Emporiqa store of
 * their own. An employee with the permission goes through as before.
 *
 * Self-contained (no PHPUnit, no PrestaShop): PrestaShop classes are stubbed,
 * the real controller and module class are loaded, and Tools::redirectAdmin
 * throws instead of redirecting.
 *
 * Run: php tests/AdminConnectPermissionTest.php
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
define('_PS_VERSION_', '8.1.0');
define('_DB_PREFIX_', 'ps_');

class Redirected extends Exception
{
}

class Module
{
    /** @var array<string, bool> permission => granted, for the employee under test */
    public static $granted = [];

    /** @var Context */
    public $context;

    /** @var string[] permissions asked for, in order */
    public static $asked = [];

    public function getPermission($variable, $employee = null)
    {
        self::$asked[] = $variable;

        return !empty(self::$granted[$variable]) && $employee instanceof Employee;
    }

    public function displayError($error)
    {
        return '<error>' . $error . '</error>';
    }

    public function l($string)
    {
        return $string;
    }
}

class ModuleAdminController
{
    /** @var Context */
    public $context;

    /** @var Module */
    public $module;

    public $bootstrap = true;

    public function __construct()
    {
    }
}

class Employee
{
    public $id = 7;

    public function isLoggedBack()
    {
        return true;
    }
}

class Link
{
    public function getAdminLink($controller, $withToken = true, $sfRouteParams = [], $params = [])
    {
        return '/admin-x/' . $controller . '?' . http_build_query($params);
    }
}

class Context
{
    public $employee;

    public $link;
}

class Tools
{
    /** @var array<string, string> */
    public static $values = [];

    public static function redirectAdmin($url)
    {
        throw new Redirected($url);
    }

    public static function getValue($key, $default = false)
    {
        return self::$values[$key] ?? $default;
    }

    public static function isSubmit($key)
    {
        return isset(self::$values[$key]);
    }
}

require __DIR__ . '/../emporiqa.php';
require __DIR__ . '/../controllers/admin/AdminEmporiqaConnectController.php';

$failures = 0;
function check($label, $ok)
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        ++$failures;
    }
}

function context()
{
    $context = new Context();
    $context->employee = new Employee();
    $context->link = new Link();

    return $context;
}

/** Where initContent sends the employee, and whether the handshake class was loaded on the way. */
function connect($action)
{
    Tools::$values = ['action' => $action];
    $controller = new AdminEmporiqaConnectController();
    $controller->context = context();
    $controller->module = (new ReflectionClass('Emporiqa'))->newInstanceWithoutConstructor();
    try {
        $controller->initContent();
    } catch (Redirected $e) {
        return $e->getMessage();
    }

    return null;
}

function settingsPage(array $values)
{
    Tools::$values = $values;
    $module = (new ReflectionClass('Emporiqa'))->newInstanceWithoutConstructor();
    $module->context = context();
    $method = new ReflectionMethod($module, 'getContent');

    return $method->invoke($module);
}

echo "Scenario 1: an employee without the configure permission starts a connect\n";
Module::$granted = ['view' => true];
Module::$asked = [];
$to = connect('initiate');
check('the configure permission is asked for', Module::$asked === ['configure']);
check('sent back to the settings page', $to === '/admin-x/AdminModules?configure=emporiqa');
check('the handshake is never reached', !class_exists('EmporiqaConnectHandshake', false));

echo "Scenario 2: the same employee comes back on the callback\n";
$to = connect('callback');
check('sent back to the settings page', $to === '/admin-x/AdminModules?configure=emporiqa');
check('the handshake is never reached', !class_exists('EmporiqaConnectHandshake', false));

echo "Scenario 3: the same employee opens or posts the settings page\n";
$page = settingsPage([]);
check('permission is denied', $page === '<error>Permission denied.</error>');
$page = settingsPage(['submitEmporiqaSettings' => '1', 'EMPORIQA_STORE_ID' => 'their-store']);
check('a posted form is refused before it is read', $page === '<error>Permission denied.</error>');

echo "Scenario 4: an employee with the configure permission\n";
Module::$granted = ['view' => true, 'configure' => true];
$to = connect('unknown');
check('the handshake runs', class_exists('EmporiqaConnectHandshake', false));
check('and answers with its own redirect', $to === '/admin-x/AdminModules?configure=emporiqa');

if ($failures) {
    echo "\n$failures check(s) FAILED\n";
    exit(1);
}
echo "\nAll checks passed\n";
