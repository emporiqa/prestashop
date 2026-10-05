<?php
/**
 * Emporiqa Customer Token Front Controller
 *
 * Returns the signed customer token for the chat widget. POST only and
 * no-store, so no page cache or proxy can hand one customer's token to
 * another; views/js/front-customer-token.js fetches it when the chat opens.
 * URL: /module/emporiqa/token
 *
 * The work is done by EmporiqaTokenEndpoint. This file stays PHP 7
 * parseable, like emporiqa.php, so an install left on PHP 7 answers with an
 * empty token instead of a fatal parse error.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaTokenModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $this->ajax = true;

        require_once dirname(__FILE__) . '/../../classes/EmporiqaJsonResponse.php';
        if (PHP_VERSION_ID < 80000) {
            EmporiqaJsonResponse::send(200, ['token' => ''], ['Cache-Control: no-store, private']);
        }

        require_once dirname(__FILE__) . '/../../classes/EmporiqaTokenEndpoint.php';
        EmporiqaTokenEndpoint::run($this->context);
    }
}
