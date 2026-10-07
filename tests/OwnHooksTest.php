<?php
/**
 * Locks in that install and the 1.3.3 upgrade create the module's own
 * actionEmporiqaCustomerInfo hook (with a title, so it is listed in the hook
 * positions), and that running either again neither duplicates nor fails.
 *
 * Self-contained (no PHPUnit, no PrestaShop): PrestaShop classes are stubbed,
 * the real Emporiqa module class and upgrade script are loaded.
 *
 * Run: php tests/OwnHooksTest.php
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

class Hook
{
    /** @var array<string, Hook> ps_hook, by name */
    public static $rows = [];
    public $name;
    public $title;
    public $description;

    public static function getIdByName($name)
    {
        return isset(self::$rows[$name]) ? array_search($name, array_keys(self::$rows), true) + 1 : false;
    }

    public function add()
    {
        self::$rows[$this->name] = $this;

        return true;
    }
}

require __DIR__ . '/../emporiqa.php';
require __DIR__ . '/../upgrade/upgrade-1.3.3.php';

$failures = 0;
function check($label, $ok)
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        ++$failures;
    }
}

$module = (new ReflectionClass('Emporiqa'))->newInstanceWithoutConstructor();

echo "Scenario 1: the 1.3.3 upgrade creates the hook\n";
check('upgrade succeeds', upgrade_module_1_3_3($module) === true);
check('actionEmporiqaCustomerInfo exists with a title', isset(Hook::$rows['actionEmporiqaCustomerInfo'])
    && Hook::$rows['actionEmporiqaCustomerInfo']->title !== '');

echo "Scenario 2: again (install after an upgrade, a re-run)\n";
check('succeeds', $module->installOwnHooks() === true);
check('one hook, not two', count(Hook::$rows) === 1);

if ($failures > 0) {
    echo "\n{$failures} assertion(s) FAILED\n";
    exit(1);
}
echo "\nAll assertions passed\n";
exit(0);
