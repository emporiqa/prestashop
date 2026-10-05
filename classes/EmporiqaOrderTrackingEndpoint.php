<?php
/**
 * Emporiqa legacy order tracking endpoint
 *
 * The body of controllers/front/ordertracking.php. Kept out of the
 * controller because it needs PHP 8.0 and the controller must still parse on
 * PHP 7. The request and answer shapes are 1.2.8's, which Emporiqa's legacy
 * adapter reads (docs/store-actions-design.md §5.7).
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaOrderTrackingEndpoint
{
    public const TIMESTAMP_TOLERANCE = 300; // 5 minutes

    /** @var Context */
    private $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    /**
     * Answer the request and exit.
     */
    public function run()
    {
        $this->answer((string) file_get_contents('php://input'));
    }

    /**
     * Any failure is a bare 500, so a shop in dev mode never shows a stack
     * trace to the caller.
     */
    private function answer(string $body): void
    {
        try {
            $this->handle($body);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('[Emporiqa] order tracking failed: ' . $e->getMessage(), 3, null, 'Emporiqa');
            $this->error('Internal error.', 500);
        }
    }

    private function handle(string $body): void
    {
        if (!Configuration::get('EMPORIQA_ORDER_TRACKING')) {
            $this->error('Order tracking is disabled.', 404);
        }

        if ($body === '') {
            $this->error('Method not allowed.', 405);
        }

        $signature = EmporiqaSignatureHelper::requestHeader('X-Emporiqa-Signature');
        if ($signature === '') {
            $this->error('Missing signature.', 401);
        }

        $secret = (string) Configuration::get('EMPORIQA_WEBHOOK_SECRET');
        if ($secret === '') {
            $this->error('Service unavailable.', 503);
        }

        if (!EmporiqaSignatureHelper::verifySignature($body, $signature, $secret)) {
            $this->error('Invalid signature.', 401);
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            $this->error('Invalid JSON in request body.', 400);
        }

        $timestamp = isset($payload['timestamp']) ? (int) $payload['timestamp'] : 0;
        if (abs(time() - $timestamp) > self::TIMESTAMP_TOLERANCE) {
            $this->error('Request expired.', 400);
        }

        if (empty($payload['order_identifier'])) {
            $this->error('Invalid payload: order_identifier is required.', 400);
        }

        $orderIdentifier = (string) $payload['order_identifier'];
        $verificationFields = isset($payload['verification_fields']) && is_array($payload['verification_fields'])
            ? $payload['verification_fields']
            : [];

        // The same limits as the order_status rule (per shop, per order
        // number, per email), so a leaked secret cannot be used to walk the
        // order book through this older door either.
        $limited = EmporiqaOrderStatus::rateLimitHit(
            $orderIdentifier,
            isset($verificationFields['email']) && is_scalar($verificationFields['email']) ? (string) $verificationFields['email'] : '',
            (int) $this->context->shop->id,
        );
        if ($limited !== null) {
            EmporiqaJsonResponse::send(
                429,
                ['error' => 'Too many requests.'],
                ['Retry-After: ' . $limited['retry_after']],
            );
        }

        $this->lookupOrder($orderIdentifier, $verificationFields);
    }

    private function lookupOrder(string $orderIdentifier, array $verificationFields): void
    {
        // Checked before the lookup, so the answer never tells whether an
        // order exists. Same 400 and text as 1.2.8, which Emporiqa reads as
        // "ask the shopper for the email".
        $providedEmail = !empty($verificationFields['email']) ? (string) $verificationFields['email'] : '';
        if ($providedEmail === '') {
            $this->error('Email verification required.', 400);
        }

        $order = null;
        $orders = Order::getByReference($orderIdentifier);
        if ($orders->count() > 0) {
            $order = $orders->getFirst();
        }

        // Order ids are plain digits; is_numeric() also took "1e3" and " 12".
        if (!$order && ctype_digit($orderIdentifier)) {
            $candidate = new Order((int) $orderIdentifier);
            if (Validate::isLoadedObject($candidate)) {
                $order = $candidate;
            }
        }

        if (!$order) {
            $this->error('Order not found.', 404);
        }

        /** @var Order $order */
        // Only orders of the shops chosen under Shops and languages, as order_status.
        $resolver = new EmporiqaChannelResolver($this->context);
        if (!$resolver->isShopEnabled((int) $order->id_shop)) {
            $this->error('Order not found.', 404);
        }

        if ((int) $order->id_customer === 0) {
            $this->error('Order not found.', 404);
        }

        $customer = new Customer((int) $order->id_customer);
        if (!Validate::isLoadedObject($customer)
            || strtolower($customer->email) !== strtolower($providedEmail)
        ) {
            $this->error('Order not found.', 404);
        }

        $data = (new EmporiqaOrderFormatter())->formatOrderTracking($order);

        Hook::exec('actionEmporiqaOrderTracking', [
            'data' => &$data,
            'order' => $order,
        ]);

        EmporiqaJsonResponse::send(200, $data);
    }

    private function error(string $message, int $statusCode): void
    {
        EmporiqaJsonResponse::send($statusCode, ['error' => $message]);
    }
}
