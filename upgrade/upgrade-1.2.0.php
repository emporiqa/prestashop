<?php
/**
 * Upgrade script: 1.1.1 → 1.2.0.
 *
 * 1.2.0 ships:
 *   - the hidden AdminEmporiqaConnect tab (one-click connect handshake)
 *   - the ps_emporiqa_connect_nonce table
 *   - new SpecificPrice / Currency / Tax / CartRule / Category /
 *     Manufacturer / Image / ProductOutOfStock / Language hooks for
 *     fuller catalog-change coverage
 *
 * PrestaShop does not re-run getTabs() or installDb() on a version bump,
 * so this script back-fills those for installs upgrading from 1.1.1.
 * Fresh 1.2.0 installs handle everything via Emporiqa::install().
 *
 * Follows the canonical pattern documented at
 * https://devdocs.prestashop-project.org/9/modules/creation/enabling-auto-update/
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

// Make sure no PHP notice or warning emitted during the upgrade leaks to
// the merchant's browser response (PrestaShop already logs to its own
// channel via PrestaShopLogger).
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');

/**
 * @param Emporiqa $module
 *
 * @return bool
 */
function upgrade_module_1_2_0($module)
{
    return $module->installTabs()
        && upgrade_module_1_2_0_install_nonce_table()
        && $module->registerHook([
            'actionObjectSpecificPriceAddAfter',
            'actionObjectSpecificPriceUpdateAfter',
            'actionObjectSpecificPriceDeleteAfter',
            'actionObjectCurrencyUpdateAfter',
            'actionObjectTaxUpdateAfter',
            'actionObjectTaxRulesGroupUpdateAfter',
            'actionObjectCartRuleAddAfter',
            'actionObjectCartRuleUpdateAfter',
            'actionObjectCartRuleDeleteAfter',
            'actionProductOutOfStock',
            'actionObjectCategoryUpdateAfter',
            'actionObjectCategoryDeleteAfter',
            'actionObjectManufacturerUpdateAfter',
            'actionObjectManufacturerDeleteAfter',
            'actionObjectImageAddAfter',
            'actionObjectImageUpdateAfter',
            'actionObjectImageDeleteAfter',
            'actionObjectLanguageAddAfter',
        ]);
}

function upgrade_module_1_2_0_install_nonce_table()
{
    $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'emporiqa_connect_nonce` (
        `state_hash` CHAR(64) NOT NULL,
        `verifier` VARCHAR(128) NOT NULL,
        `created_at` INT(10) UNSIGNED NOT NULL,
        PRIMARY KEY (`state_hash`),
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

    return (bool) Db::getInstance()->execute($sql);
}
