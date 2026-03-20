<?php
/**
 * Emporiqa Order Tracking Front Controller
 *
 * HMAC-verified POST endpoint for order status lookup.
 * URL: /module/emporiqa/ordertracking
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
    const TIMESTAMP_TOLERANCE = 300; // 5 minutes

    public $ssl = true;

    public function postProcess()
    {
        $this->ajax = true;

        if (!Configuration::get('EMPORIQA_ORDER_TRACKING')) {
            $this->ajaxError('Order tracking is disabled.', 403);
        }

        $body = file_get_contents('php://input');
        if (empty($body)) {
            $this->ajaxError('Method not allowed.', 405);
        }

        $signature = $this->getRequestHeader('X-Emporiqa-Signature');

        if (empty($signature)) {
            $this->ajaxError('Missing signature.', 401);
        }

        $secret = Configuration::get('EMPORIQA_WEBHOOK_SECRET');
        if (empty($secret)) {
            $this->ajaxError('Service unavailable.', 503);
        }

        if (!EmporiqaSignatureHelper::verifySignature($body, $signature, $secret)) {
            $this->ajaxError('Invalid signature.', 401);
        }

        $payload = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->ajaxError('Invalid JSON in request body.', 400);
        }

        $timestamp = isset($payload['timestamp']) ? (int) $payload['timestamp'] : 0;
        if (abs(time() - $timestamp) > self::TIMESTAMP_TOLERANCE) {
            $this->ajaxError('Request expired.', 400);
        }

        if (empty($payload['order_identifier'])) {
            $this->ajaxError('Invalid payload: order_identifier is required.', 400);
        }

        $orderIdentifier = (string) $payload['order_identifier'];
        $verificationFields = [];
        if (isset($payload['verification_fields']) && is_array($payload['verification_fields'])) {
            $verificationFields = $payload['verification_fields'];
        }

        $this->lookupOrder($orderIdentifier, $verificationFields);
    }

    public function initContent()
    {
        parent::initContent();

        if (!$this->ajax) {
            $this->ajaxError('Invalid request.', 400);
        }
    }

    private function lookupOrder($orderIdentifier, array $verificationFields)
    {
        $order = null;

        $orders = Order::getByReference($orderIdentifier);
        if ($orders->count() > 0) {
            $order = $orders->getFirst();
        }

        if (!$order && is_numeric($orderIdentifier)) {
            $candidate = new Order((int) $orderIdentifier);
            if (Validate::isLoadedObject($candidate)) {
                $order = $candidate;
            }
        }

        if (!$order) {
            $this->ajaxError('Order not found.', 404);
        }

        /** @var Order $order */
        $requireEmail = (bool) Configuration::get('EMPORIQA_ORDER_TRACKING_EMAIL');
        $providedEmail = !empty($verificationFields['email']) ? $verificationFields['email'] : '';

        if ($requireEmail && empty($providedEmail)) {
            $this->ajaxError('Email verification required.', 400);
        }

        if (!empty($providedEmail)) {
            if ((int) $order->id_customer === 0) {
                $this->ajaxError('Order not found.', 404);
            }

            $customer = new Customer((int) $order->id_customer);
            if (!Validate::isLoadedObject($customer)
                || strtolower($customer->email) !== strtolower($providedEmail)
            ) {
                $this->ajaxError('Order not found.', 404);
            }
        }

        $formatter = new EmporiqaOrderFormatter();
        $data = $formatter->formatOrderTracking($order);

        Hook::exec('actionEmporiqaOrderTracking', [
            'data' => &$data,
            'order' => $order,
        ]);

        $this->ajaxSuccess($data);
    }

    /**
     * Read an HTTP request header.
     * Uses $_SERVER which is the standard PHP mechanism for HTTP headers.
     */
    private function getRequestHeader($name)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return isset($_SERVER[$key]) ? $_SERVER[$key] : '';
    }

    private function ajaxError($message, $statusCode = 400)
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        http_response_code($statusCode);
        header('Content-Type: application/json');
        exit(json_encode(['error' => $message]));
    }

    private function ajaxSuccess(array $data)
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        http_response_code(200);
        header('Content-Type: application/json');
        exit(json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
