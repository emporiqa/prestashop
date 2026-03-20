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

    public function postProcess()
    {
        $this->ajax = true;

        if (!Tools::isSubmit('action')) {
            $this->ajaxResponse([
                'success' => false,
                'error' => 'Method not allowed.',
                'checkoutUrl' => null,
                'cart' => null,
            ]);
        }

        if (Configuration::get('PS_CATALOG_MODE') || !Configuration::get('EMPORIQA_CART_ENABLED')) {
            $this->ajaxResponse([
                'success' => false,
                'error' => 'Cart operations are disabled.',
                'checkoutUrl' => null,
                'cart' => null,
            ]);
        }

        if (!$this->validateCsrfToken()) {
            $this->ajaxResponse([
                'success' => false,
                'error' => 'Security check failed. Please refresh the page and try again.',
                'checkoutUrl' => null,
                'cart' => null,
            ]);
        }

        $action = Tools::getValue('action', '');
        $allowedActions = ['add', 'get', 'update', 'remove', 'clear', 'checkout-url'];

        if (!in_array($action, $allowedActions, true)) {
            $this->ajaxResponse([
                'success' => false,
                'error' => 'Invalid action.',
                'checkoutUrl' => null,
                'cart' => null,
            ]);
        }

        try {
            $handler = new EmporiqaCartHandler($this->context);

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
            PrestaShopLogger::addLog(
                '[Emporiqa] Cart error: ' . $e->getMessage(),
                3,
                null,
                'Emporiqa'
            );
            $result = [
                'success' => false,
                'error' => 'An unexpected error occurred.',
                'checkoutUrl' => null,
                'cart' => null,
            ];
        }

        $this->ajaxResponse($result);
    }

    public function initContent()
    {
        parent::initContent();

        if (!$this->ajax) {
            $this->ajaxResponse([
                'success' => false,
                'error' => 'Invalid request.',
                'checkoutUrl' => null,
                'cart' => null,
            ]);
        }
    }

    private function validateCsrfToken()
    {
        $token = Tools::getValue('token', '');

        return !empty($token) && $token === Tools::getToken(false);
    }

    private function ajaxResponse(array $data)
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
        exit(json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
