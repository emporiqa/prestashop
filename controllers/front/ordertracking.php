<?php
/**
 * Emporiqa Order Tracking Front Controller
 *
 * HMAC-verified POST endpoint for order status lookup.
 * URL: /module/emporiqa/ordertracking
 *
 * Legacy: kept working for every store that uses it (Advanced > Order
 * tracking). The `order_status` ready-made rule (controllers/front/action.php)
 * replaces it where Emporiqa offers ready-made rules.
 *
 * The work is done by EmporiqaOrderTrackingEndpoint. This file stays PHP 7
 * parseable, like emporiqa.php, so an install left on PHP 7 answers 503
 * instead of a fatal error.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaOrdertrackingModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $this->ajax = true;

        require_once dirname(__FILE__) . '/../../classes/EmporiqaJsonResponse.php';
        if (PHP_VERSION_ID < 80000) {
            EmporiqaJsonResponse::send(503, ['error' => 'Service unavailable.']);
        }

        require_once dirname(__FILE__) . '/../../classes/EmporiqaOrderTrackingEndpoint.php';
        $endpoint = new EmporiqaOrderTrackingEndpoint($this->context);
        $endpoint->run();
    }

    /**
     * Signed server-to-server calls must keep working in maintenance mode,
     * and Emporiqa's servers are not shoppers to geo-block.
     */
    protected function displayMaintenancePage()
    {
    }

    protected function displayRestrictedCountryPage()
    {
    }
}
