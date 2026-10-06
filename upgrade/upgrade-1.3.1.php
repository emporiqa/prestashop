<?php
/**
 * Upgrade script: → 1.3.1 (price sync fixes).
 *
 * Registers the catalog price rule hooks, so the products a rule stops
 * covering (rule deleted or its conditions narrowed) are re-sent without its
 * discount: the rule's own update and delete, and the legacy Catalog price
 * rules page's delete and bulk delete, which fire while the rule's rows still
 * exist. Install registers them too; this brings existing shops level. No
 * table or setting changes.
 *
 * Then clears the CCC cache: the combined file is named after the list of
 * files it holds, not their content, so it would keep serving the previous
 * views/js/front-cart-handler.js.
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
function upgrade_module_1_3_1($module)
{
    $hooks = $module->registerHook('actionObjectSpecificPriceRuleUpdateBefore')
        && $module->registerHook('actionObjectSpecificPriceRuleDeleteBefore')
        && $module->registerHook('actionAdminSpecificPriceRuleControllerDeleteBefore')
        && $module->registerHook('actionAdminSpecificPriceRuleControllerBulkdeleteBefore');

    // A failed cache clear must not fail the upgrade; the merchant can clear it by hand.
    try {
        Media::clearCache();
    } catch (Throwable $e) {
        PrestaShopLogger::addLog('[Emporiqa] 1.3.1 upgrade: cache clear failed: ' . $e->getMessage(), 2, null, 'Emporiqa');
    }

    return $hooks;
}
