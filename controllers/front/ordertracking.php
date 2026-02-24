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

    public function initContent()
    {
        if (!Configuration::get('EMPORIQA_ORDER_TRACKING')) {
            $this->sendError('Order tracking is disabled.', 403);
        }

        if ($this->getRequestMethod() !== 'POST') {
            $this->sendError('Method not allowed.', 405);
        }

        $body = file_get_contents('php://input');
        $signature = $this->getRequestHeader('X-Emporiqa-Signature');

        if (empty($signature)) {
            $this->sendError('Missing signature.', 401);
        }

        $secret = Configuration::get('EMPORIQA_WEBHOOK_SECRET');
        if (empty($secret)) {
            $this->sendError('Service unavailable.', 503);
        }

        if (!EmporiqaSignatureHelper::verifySignature($body, $signature, $secret)) {
            $this->sendError('Invalid signature.', 401);
        }

        $payload = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendError('Invalid JSON in request body.', 400);
        }

        $timestamp = isset($payload['timestamp']) ? (int) $payload['timestamp'] : 0;
        if (abs(time() - $timestamp) > self::TIMESTAMP_TOLERANCE) {
            $this->sendError('Request expired.', 400);
        }

        if (empty($payload['order_identifier'])) {
            $this->sendError('Invalid payload: order_identifier is required.', 400);
        }

        $orderIdentifier = (string) $payload['order_identifier'];
        $verificationFields = [];
        if (isset($payload['verification_fields']) && is_array($payload['verification_fields'])) {
            $verificationFields = $payload['verification_fields'];
        }

        $this->lookupOrder($orderIdentifier, $verificationFields);
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
            $this->sendError('Order not found.', 404);
        }

        /** @var Order $order */
        $requireEmail = (bool) Configuration::get('EMPORIQA_ORDER_TRACKING_EMAIL');
        $providedEmail = !empty($verificationFields['email']) ? $verificationFields['email'] : '';

        if ($requireEmail && empty($providedEmail)) {
            $this->sendError('Email verification required.', 400);
        }

        if (!empty($providedEmail)) {
            if ((int) $order->id_customer === 0) {
                $this->sendError('Order not found.', 404);
            }

            $customer = new Customer((int) $order->id_customer);
            if (!Validate::isLoadedObject($customer)
                || strtolower($customer->email) !== strtolower($providedEmail)
            ) {
                $this->sendError('Order not found.', 404);
            }
        }

        $formatter = new EmporiqaOrderFormatter();
        $data = $formatter->formatOrderTracking($order);

        Hook::exec('actionEmporiqaOrderTracking', [
            'data' => &$data,
            'order' => $order,
        ]);

        $this->sendSuccess($data);
    }

    private function getRequestMethod()
    {
        return isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
    }

    private function getRequestHeader($name)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return isset($_SERVER[$key]) ? $_SERVER[$key] : '';
    }

    private function sendError($message, $statusCode = 400)
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        http_response_code($statusCode);
        header('Content-Type: application/json');
        exit(json_encode(['error' => $message]));
    }

    private function sendSuccess(array $data)
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        http_response_code(200);
        header('Content-Type: application/json');
        exit(json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
