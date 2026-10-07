<?php
/**
 * Upgrade script: → 1.3.3 (customer_info action, sync fixes).
 *
 * Creates the actionEmporiqaCustomerInfo hook, which the new customer_info
 * action runs after building its answer, as install does. The sync fixes
 * need nothing migrated: a Sync products after the update corrects the
 * links already sent.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');

/**
 * @param Emporiqa $module
 *
 * @return bool
 */
function upgrade_module_1_3_3($module)
{
    return $module->installOwnHooks();
}
