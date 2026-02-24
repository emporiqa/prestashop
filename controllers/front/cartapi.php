<?php
/**
 * Emporiqa Cart API Front Controller
 *
 * AJAX endpoint for cart operations called by the Emporiqa chat widget.
 * URL: /module/emporiqa/cartapi
 *
 * Actions: add, get, update, remove, clear, checkout-url
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaCartapiModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function initContent()
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->sendJsonResponse([
                'success' => false,
                'error' => 'Method not allowed.',
                'checkoutUrl' => null,
                'cart' => null,
            ]);
        }

        if (Configuration::get('PS_CATALOG_MODE') || !Configuration::get('EMPORIQA_CART_ENABLED')) {
            return $this->sendJsonResponse([
                'success' => false,
                'error' => 'Cart operations are disabled.',
                'checkoutUrl' => null,
                'cart' => null,
            ]);
        }

        if (!$this->validateCsrfToken()) {
            return $this->sendJsonResponse([
                'success' => false,
                'error' => 'Security check failed. Please refresh the page and try again.',
                'checkoutUrl' => null,
                'cart' => null,
            ]);
        }

        $action = Tools::getValue('action', '');
        $allowedActions = ['add', 'get', 'update', 'remove', 'clear', 'checkout-url'];

        if (!in_array($action, $allowedActions, true)) {
            return $this->sendJsonResponse([
                'success' => false,
                'error' => 'Invalid action.',
                'checkoutUrl' => null,
                'cart' => null,
            ]);
        }

        try {
            $handler = new EmporiqaCartHandler();

            switch ($action) {
                case 'add':
                    $result = $handler->add(
                        Tools::getValue('product_id', ''),
                        Tools::getValue('variation_id', ''),
                        (int) Tools::getValue('quantity', 1)
                    );
                    break;

                case 'get':
                    $result = $handler->get();
                    break;

                case 'update':
                    $result = $handler->update(
                        Tools::getValue('product_id', ''),
                        Tools::getValue('variation_id', ''),
                        (int) Tools::getValue('quantity', 1)
                    );
                    break;

                case 'remove':
                    $result = $handler->remove(
                        Tools::getValue('product_id', ''),
                        Tools::getValue('variation_id', '')
                    );
                    break;

                case 'clear':
                    $result = $handler->clear();
                    break;

                case 'checkout-url':
                    $result = $handler->getCheckoutUrl();
                    break;

                default:
                    $result = [
                        'success' => false,
                        'error' => 'Invalid action.',
                        'checkoutUrl' => null,
                        'cart' => null,
                    ];
            }
        } catch (\Exception $e) {
            $result = [
                'success' => false,
                'error' => 'An unexpected error occurred.',
                'checkoutUrl' => null,
                'cart' => null,
            ];
        }

        $this->sendJsonResponse($result);
    }

    private function validateCsrfToken()
    {
        $token = Tools::getValue('token', '');

        return !empty($token) && $token === Tools::getToken(false);
    }

    private function sendJsonResponse(array $data)
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        http_response_code(200);
        header('Content-Type: application/json');
        exit(json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
