<?php
/**
 * Upgrade script: → 1.3.0 (ready-made rules).
 *
 * 1. emporiqa_connect_nonce gains `exchanging_at`: the PKCE verifier now
 *    survives until /connect/exchange returns, so Emporiqa can prove the
 *    shop origin during it.
 * 2. emporiqa_action_request: request_id dedupe for the action endpoint.
 *    Both go through EmporiqaSchema::ensure(), the same routine install
 *    runs, which brings every module table up to the 1.3.0 schema.
 * 3. emporiqa_action_rate: per-value counters for the order_status rate
 *    limit, also created by ensure().
 * 4. Order tracking stays as it was: on and offered. Ready-made rules,
 *    which replace it, are offered only where Emporiqa says so
 *    (EMPORIQA_RULES_AVAILABLE, 0 until the next connect or Test
 *    connection says otherwise). 1.1 through 1.2.8 answered the endpoint
 *    whatever EMPORIQA_ORDER_TRACKING said, and 1.3.0 is the first version
 *    to honour it, so a store still carrying the 1.0.x '0' is set to 1
 *    rather than going dark.
 * 5. The Smarty cache is cleared: the header template no longer writes the
 *    customer token, and a cached copy would keep serving the old markup.
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

require_once dirname(__FILE__) . '/../classes/EmporiqaSchema.php';

/**
 * @param Emporiqa $module
 *
 * @return bool
 */
function upgrade_module_1_3_0($module)
{
    if (PHP_VERSION_ID < 80000) {
        if (method_exists($module, 'addUpgradeError')) {
            $module->addUpgradeError($module->l('Emporiqa 1.3.0 needs PHP 8.0 or newer. Ask your host to switch this shop to PHP 8.0 or newer, then run the upgrade again.'));
        }

        return false;
    }

    if (!EmporiqaSchema::ensure()) {
        return false;
    }

    if ((string) Configuration::getGlobalValue('EMPORIQA_ORDER_TRACKING') !== '1') {
        Configuration::updateGlobalValue('EMPORIQA_ORDER_TRACKING', 1);
    }
    if (!Configuration::hasKey('EMPORIQA_RULES_AVAILABLE')) {
        Configuration::updateGlobalValue('EMPORIQA_RULES_AVAILABLE', 0);
        Configuration::updateGlobalValue('EMPORIQA_LIVE_RULES', '[]');
    }

    // A failed cache clear must not fail the upgrade; the merchant can clear it by hand.
    try {
        Tools::clearSmartyCache();
        Media::clearCache();
    } catch (Throwable $e) {
        PrestaShopLogger::addLog('[Emporiqa] 1.3.0 upgrade: cache clear failed: ' . $e->getMessage(), 2, null, 'Emporiqa');
    }

    return true;
}
