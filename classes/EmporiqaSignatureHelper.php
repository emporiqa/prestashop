<?php
/**
 * Emporiqa Signature Helper
 *
 * HMAC-SHA256 signature generation, verification, and user token creation.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaSignatureHelper
{
    /**
     * Generate HMAC-SHA256 signature for a payload string.
     *
     * @param string $payload JSON payload
     * @param string $secret Webhook secret
     *
     * @return string Hex-encoded signature
     */
    public static function generateSignature($payload, $secret)
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Verify HMAC-SHA256 signature.
     *
     * @param string $payload JSON payload
     * @param string $signature Provided signature
     * @param string $secret Webhook secret
     *
     * @return bool
     */
    public static function verifySignature($payload, $signature, $secret)
    {
        $expected = self::generateSignature($payload, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Generate a signed user identity token for the chat widget.
     *
     * The token includes a timestamp component and produces a unique value
     * on each call. PrestaShop does not full-page cache pages for logged-in
     * users, so per-request generation is safe.
     *
     * Format: base64url_payload.hmac_hex
     *
     * @param string $userId Customer ID
     * @param string $webhookSecret Webhook secret key
     *
     * @return string Signed token
     */
    public static function generateUserToken($userId, $webhookSecret)
    {
        $payload = json_encode([
            'uid' => $userId,
            'ts' => time(),
        ]);

        $encodedPayload = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encodedPayload, $webhookSecret);

        return $encodedPayload . '.' . $signature;
    }
}
