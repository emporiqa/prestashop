<?php
/**
 * Emporiqa Actions endpoint
 *
 * The body of controllers/front/action.php, the endpoint for Emporiqa
 * ready-made rules. The base URL is sent at connect
 * (getModuleLink('emporiqa', 'action')); the rule travels as ?key=:
 *
 *   ?key=order_status  read-only order lookup, signed both ways (scheme 2)
 *   ?key=verify        the origin proof during one-click connect, and the
 *                      signed endpoint challenge
 *
 * It lives here, not in the controller, because it needs PHP 8.0 and the
 * controller must still parse on PHP 7 to answer there.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaActionEndpoint
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /** @var Context */
    private $context;

    /** @var string */
    private $secret = '';

    /** @var string */
    private $storeId = '';

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    /**
     * Answer the request and exit.
     */
    public function run()
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(405, ['status' => 'error', 'message_code' => 'invalid_field']);
        }

        $body = (string) file_get_contents('php://input');
        $payload = json_decode($body, true);
        $key = (string) Tools::getValue('key');

        if ($key === 'verify' && is_array($payload) && isset($payload['state'], $payload['nonce'])) {
            $this->answerOriginProof($payload);
        }

        $this->secret = (string) Configuration::get('EMPORIQA_WEBHOOK_SECRET');
        $this->storeId = (string) Configuration::get('EMPORIQA_STORE_ID');
        if ($this->secret === '' || $this->storeId === '') {
            $this->respond(503, ['status' => 'error', 'message_code' => 'disabled']);
        }

        $verdict = EmporiqaSignatureHelper::verifyHeader(
            EmporiqaSignatureHelper::requestHeader('X-Emporiqa-Action-Signature'),
            $body,
            $this->secret,
            $this->storeId,
            EmporiqaSignatureHelper::LABEL_OUTBOUND,
        );
        if ($verdict !== 'ok') {
            // Unsigned: nothing proves the caller, so there is nothing to sign for.
            $this->respond(401, ['status' => 'error', 'message_code' => $verdict]);
        }

        $requestId = is_array($payload) && isset($payload['request_id']) && is_string($payload['request_id'])
            ? $payload['request_id']
            : '';
        if ($requestId === '' || Tools::strlen($requestId) > 100 || !isset($payload['rule'])) {
            $this->respond(400, ['status' => 'error', 'message_code' => 'invalid_field'], '');
        }

        if ($key === 'verify' && $payload['rule'] === 'verify') {
            $this->answerChallenge($payload, $requestId);
        }

        if ($key !== 'order_status' || $payload['rule'] !== 'order_status') {
            $this->respond(404, ['status' => 'error', 'message_code' => 'disabled'], $requestId);
        }

        $encoded = '';
        try {
            $remembered = EmporiqaOrderStatus::remembered($requestId);
            if ($remembered !== null) {
                $this->respond($remembered[0], $remembered[1], $requestId);
            }

            // Counted after the dedupe, so Emporiqa's retry of one call is free.
            $limited = EmporiqaOrderStatus::rateLimitHit(
                EmporiqaOrderStatus::field($payload, 'order_number'),
                EmporiqaOrderStatus::field($payload, 'email'),
                (int) $this->context->shop->id,
            );
            if ($limited !== null) {
                $this->respond(
                    429,
                    ['status' => 'error', 'message_code' => 'rate_limited', 'data' => ['scope' => $limited['scope']]],
                    $requestId,
                    ['Retry-After: ' . $limited['retry_after']],
                );
            }

            $encoded = (string) json_encode((new EmporiqaOrderStatus($this->context))->handle($payload), self::JSON_FLAGS);
            EmporiqaOrderStatus::remember($requestId, 200, $encoded);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('[Emporiqa] order_status failed: ' . $e->getMessage(), 3, null, 'Emporiqa');
            $this->respond(500, ['status' => 'error', 'message_code' => 'internal'], $requestId);
        }

        $this->respond(200, $encoded, $requestId);
    }

    /**
     * Connect origin proof: answer only for an exchange this shop started
     * with that state, with HMAC-SHA256(code_verifier, nonce). Unsigned,
     * since a first connect has no secret yet; the verifier is the proof.
     *
     * Emporiqa's nonce is always 64 lowercase hex (token_hex(32)). Anything
     * else is refused, so whoever saw `state` in the admin callback URL
     * cannot have the shop MAC a value of their choosing.
     */
    private function answerOriginProof(array $payload)
    {
        $state = is_string($payload['state']) ? $payload['state'] : '';
        $nonce = is_string($payload['nonce']) ? $payload['nonce'] : '';
        $verifier = ($state !== '' && preg_match('/^[0-9a-f]{64}$/D', $nonce))
            ? EmporiqaConnectNonce::exchangingVerifier($state)
            : null;
        if ($verifier === null) {
            $this->respond(404, ['status' => 'not_found']);
        }

        $this->respond(200, [
            'status' => 'found',
            'data' => ['nonce_mac' => hash_hmac('sha256', $nonce, $verifier)],
        ]);
    }

    /**
     * Endpoint challenge: HMAC-SHA256(K_resp, challenge), signed as any answer.
     */
    private function answerChallenge(array $payload, $requestId)
    {
        $challenge = isset($payload['challenge']) && is_string($payload['challenge']) ? $payload['challenge'] : '';
        if (!preg_match('/^[0-9a-f]{64}$/D', $challenge)) {
            $this->respond(200, ['status' => 'rejected', 'message_code' => 'invalid_field'], $requestId);
        }
        $key = EmporiqaSignatureHelper::deriveKey($this->secret, EmporiqaSignatureHelper::LABEL_RESPONSE, $this->storeId);

        $this->respond(200, [
            'status' => 'found',
            'data' => ['challenge_mac' => hash_hmac('sha256', $challenge, $key)],
        ], $requestId);
    }

    /**
     * Send and exit.
     *
     * @param array|string $envelope an array is encoded; a string is an already encoded body (a remembered answer)
     * @param string|null $requestId when set, the answer carries X-Emporiqa-Response-Signature
     * @param string[] $headers extra response headers
     */
    private function respond($httpCode, $envelope, $requestId = null, array $headers = [])
    {
        $body = is_array($envelope) ? (string) json_encode($envelope, self::JSON_FLAGS) : (string) $envelope;
        if ($requestId !== null) {
            $key = EmporiqaSignatureHelper::deriveKey($this->secret, EmporiqaSignatureHelper::LABEL_RESPONSE, $this->storeId);
            $headers[] = 'X-Emporiqa-Response-Signature: ' . EmporiqaSignatureHelper::buildHeader($key, $requestId . '.' . $body);
        }
        EmporiqaJsonResponse::send($httpCode, $body, $headers);
    }
}
