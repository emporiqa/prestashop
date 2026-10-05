<?php
/**
 * Emporiqa Actions Front Controller
 *
 * Endpoint for Emporiqa ready-made rules; the work is done by
 * EmporiqaActionEndpoint. This file stays PHP 7 parseable, like emporiqa.php,
 * so an install left on PHP 7 answers 503 instead of a fatal parse error.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaActionModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $this->ajax = true;

        require_once dirname(__FILE__) . '/../../classes/EmporiqaJsonResponse.php';
        if (PHP_VERSION_ID < 80000) {
            EmporiqaJsonResponse::send(503, ['status' => 'error', 'message_code' => 'disabled']);
        }

        require_once dirname(__FILE__) . '/../../classes/EmporiqaActionEndpoint.php';
        $endpoint = new EmporiqaActionEndpoint($this->context);
        $endpoint->run();
    }

    /**
     * Signed server-to-server calls must keep working while the shop is in
     * maintenance mode, and Emporiqa's servers are not shoppers to geo-block.
     */
    protected function displayMaintenancePage()
    {
    }

    protected function displayRestrictedCountryPage()
    {
    }
}
