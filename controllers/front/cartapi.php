<?php
/**
 * Emporiqa Cart API Front Controller
 *
 * AJAX endpoint for cart operations called by the Emporiqa chat widget
 * (views/js/front-cart-handler.js). POST only, never cached.
 * URL: /module/emporiqa/cartapi
 *
 * Actions: add, get, update, remove, clear, checkout-url
 *
 * The work is done by EmporiqaCartApiEndpoint. This file stays PHP 7
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

class EmporiqaCartapiModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $this->ajax = true;

        require_once dirname(__FILE__) . '/../../classes/EmporiqaJsonResponse.php';
        if (PHP_VERSION_ID < 80000) {
            EmporiqaJsonResponse::send(503, [
                'success' => false,
                'error' => 'Cart operations are unavailable.',
                'checkoutUrl' => null,
                'cart' => null,
            ], ['Cache-Control: no-store, private']);
        }

        require_once dirname(__FILE__) . '/../../classes/EmporiqaCartApiEndpoint.php';
        $endpoint = new EmporiqaCartApiEndpoint($this->context, $this->module, $this);
        $endpoint->run();
    }

    /**
     * The cart as PrestaShop's own CartController returns it. The theme's
     * updateCart listener (themes/core.js) assigns event.resp.cart to
     * prestashop.cart, so the storefront JS needs the presented shape.
     * Here, not in the endpoint, because the presenter is the controller's.
     *
     * @return array|null
     */
    public function presentCartForTheme()
    {
        try {
            // Reloaded, not the context object: a cart the handler just
            // created holds null address ids, and CartLazyArray's typed
            // properties fatal on them during json_encode.
            $cart = new Cart((int) $this->context->cart->id);
            if (!Validate::isLoadedObject($cart)) {
                return null;
            }
            $presented = $this->cart_presenter->present($cart, true);
            $filter = $this->get('prestashop.core.filter.front_end_object.product_collection');
            if (isset($presented['products'])) {
                $presented['products'] = $filter->filter($presented['products']);
            }

            // Serialize here so a lazy-array failure lands in the catch
            // instead of breaking the whole response.
            $json = json_encode($presented, JSON_INVALID_UTF8_SUBSTITUTE);

            return $json === false ? null : json_decode($json, true);
        } catch (Throwable $e) {
            return null;
        }
    }
}
