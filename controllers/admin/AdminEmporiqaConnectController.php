<?php
/**
 * One-click connect handshake controller (PS 8.1+ and PS 9.x compatible).
 *
 * Mirrors the WooCommerce Emporiqa_Connect flow. Two actions:
 *
 *   ?action=initiate  — merchant clicks "Connect to Emporiqa". We mint a
 *                       random state + PKCE verifier, persist them in the
 *                       nonce table, then 302 to emporiqa.com/connect/start.
 *
 *   ?action=callback  — emporiqa.com redirects back with state + code.
 *                       We atomically claim the nonce, exchange the code
 *                       for a connection secret via /connect/exchange, and
 *                       persist (store_id, webhook_url, webhook_secret).
 *                       The verifier is kept until the exchange returns:
 *                       Emporiqa proves our origin during it (see
 *                       EmporiqaConnectNonce and controllers/front/action.php).
 *
 * The work is done by classes/EmporiqaConnectHandshake.php. This file stays
 * PHP 7 parseable, like emporiqa.php, so an install left on PHP 7 is sent
 * back to the settings page (which says PHP 8 is needed) instead of a fatal
 * error.
 *
 * Security model: the `state` parameter is itself the CSRF nonce. PS' own
 * admin token (Tools::getAdminToken) protects the inbound initiate; the
 * outbound callback comes from a third-party redirect, so wp_verify_nonce
 * doesn't apply — atomic state consumption is the equivalent guarantee.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminEmporiqaConnectController extends ModuleAdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->bootstrap = false;
    }

    /**
     * Skip the default ModuleAdminController template rendering. We always
     * exit via Tools::redirectAdmin(), so there is nothing to display.
     */
    public function initContent()
    {
        // Hidden tab: some PS minor versions skip ModuleAdminController::checkAccess
        // when visible=false. Re-assert the employee is logged in before dispatching.
        if (!$this->context->employee || !$this->context->employee->isLoggedBack()) {
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminLogin'));
            exit;
        }

        if (PHP_VERSION_ID < 80000 || !$this->module instanceof Module) {
            Tools::redirectAdmin(
                $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => 'emporiqa'])
            );
            exit;
        }

        // Ticking the Emporiqa menu entry for a profile also grants this hidden
        // tab, but connecting replaces the store id and secret: only an employee
        // who may configure the module (as the settings page and Sync require)
        // gets through. The settings page then says permission is denied.
        if (!$this->module->getPermission('configure', $this->context->employee)) {
            Tools::redirectAdmin(
                $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => 'emporiqa'])
            );
            exit;
        }

        require_once dirname(__FILE__) . '/../../classes/EmporiqaConnectHandshake.php';
        $handshake = new EmporiqaConnectHandshake($this->context, $this->module);
        $handshake->handle((string) Tools::getValue('action'));
    }
}
