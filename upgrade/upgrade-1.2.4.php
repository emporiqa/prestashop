<?php
/**
 * Upgrade script: → 1.2.4.
 *
 * 1.2.4 is code-only — no database or schema changes. Two hardening tweaks:
 *
 * 1. The emporiqa_sid cookie reader (Emporiqa::getEmporiqaSessionId) now
 *    rebuilds its value by construction with preg_replace, so it can only
 *    contain [A-Za-z0-9_-] (max 128 chars) and is never the raw cookie string.
 * 2. hookActionValidateOrder now attaches the chat session id to the order
 *    payload AFTER the actionEmporiqaFormatOrder hook, so the cookie-derived
 *    value never flows through the hook dispatch path.
 *
 * Behaviour is unchanged for valid session ids and the webhook payload still
 * carries emporiqa_session_id. Together these clear a static-analysis false
 * positive that misread the `Hook::exec` dispatcher as an OS command sink.
 * Note: subscribers to actionEmporiqaFormatOrder no longer see
 * emporiqa_session_id during the hook (it is added immediately after). This
 * script only registers the new version.
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
 * @return bool
 */
function upgrade_module_1_2_4($module)
{
    // Pure code change — nothing to migrate.
    return true;
}
