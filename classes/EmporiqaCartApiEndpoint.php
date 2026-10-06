<?php
/**
 * Emporiqa Cart API endpoint
 *
 * The body of controllers/front/cartapi.php. Kept out of the controller
 * because it needs PHP 8.0 and the controller must still parse on PHP 7.
 *
 * Every action is POST only, reads included: front-cart-handler.js, the one
 * caller, always POSTs, and a GET that changes a cart could be fired by an
 * <img> on any page. Answers are per-visitor and never cached.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaCartApiEndpoint
{
    private const ALLOWED_ACTIONS = ['add', 'get', 'update', 'remove', 'clear', 'checkout-url'];

    private const MUTATIONS = ['add', 'update', 'remove', 'clear'];

    /** @var Context */
    private $context;

    /** @var Module|null */
    private $module;

    /** @var EmporiqaCartapiModuleFrontController */
    private $controller;

    public function __construct(Context $context, $module, $controller)
    {
        $this->context = $context;
        $this->module = $module;
        $this->controller = $controller;
    }

    /**
     * Answer the request and exit.
     */
    public function run()
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(['success' => false, 'error' => 'Method not allowed.'], 405);
        }

        if (!Tools::isSubmit('action')) {
            $this->respond(['success' => false, 'error' => 'Method not allowed.']);
        }

        if (Configuration::get('PS_CATALOG_MODE')) {
            $this->respond(['success' => false, 'error' => 'Cart operations are disabled.']);
        }

        if (!$this->validateCsrfToken()) {
            $this->respond($this->tokenRefusal());
        }

        $action = (string) Tools::getValue('action', '');
        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            $this->respond(['success' => false, 'error' => 'Invalid action.']);
        }

        try {
            $result = $this->dispatch($action, new EmporiqaCartHandler($this->context));
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('[Emporiqa] Cart error: ' . $e->getMessage(), 3, null, 'Emporiqa');
            $result = ['success' => false, 'error' => 'An unexpected error occurred.'];
        }

        if (!empty($result['success']) && in_array($action, self::MUTATIONS, true)) {
            $result['ps_cart'] = $this->controller->presentCartForTheme();
        }

        $this->respond($result);
    }

    private function dispatch(string $action, EmporiqaCartHandler $handler): array
    {
        switch ($action) {
            case 'add':
                return $handler->add(
                    Tools::getValue('product_id', ''),
                    Tools::getValue('variation_id', ''),
                    (int) Tools::getValue('quantity', 1),
                );
            case 'get':
                return $handler->get();
            case 'update':
                return $handler->update(
                    Tools::getValue('product_id', ''),
                    Tools::getValue('variation_id', ''),
                    (int) Tools::getValue('quantity', 1),
                );
            case 'remove':
                return $handler->remove(
                    Tools::getValue('product_id', ''),
                    Tools::getValue('variation_id', ''),
                );
            case 'clear':
                return $handler->clear();
            default:
                return $handler->getCheckoutUrl();
        }
    }

    private function validateCsrfToken(): bool
    {
        $token = (string) Tools::getValue('token', '');
        if ($token === '' || !$this->module instanceof Emporiqa) {
            return false;
        }

        // Per-visitor token bound to a nonce in the encrypted PS cookie.
        // No nonce means the widget never rendered for this visitor (or
        // cookies are blocked): fail closed rather than minting one.
        $expected = $this->module->getCartApiToken(false);

        return $expected !== '' && hash_equals($expected, $token);
    }

    /**
     * A refused token, with this visitor's own token for one retry.
     *
     * A page served from a full-page cache carries the token of the visitor
     * it was rendered for, so every other visitor is refused. The refusal
     * changed nothing, and only a same-origin script can read this answer
     * (no CORS, SameSite cookie), as with a token rendered into the page.
     */
    private function tokenRefusal(): array
    {
        $refusal = [
            'success' => false,
            'error' => 'Security check failed. Please refresh the page and try again.',
            'code' => 'invalid_token',
        ];
        if ($this->module instanceof Emporiqa) {
            $refusal['token'] = $this->module->getCartApiToken();
        }

        return $refusal;
    }

    private function respond(array $data, int $httpCode = 200): void
    {
        $data += ['success' => false, 'error' => null, 'checkoutUrl' => null, 'cart' => null];
        EmporiqaJsonResponse::send($httpCode, $data, ['Cache-Control: no-store, private']);
    }
}
