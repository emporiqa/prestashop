<?php
/**
 * Emporiqa Signature Helper
 *
 * HMAC-SHA256 signatures (scheme 1 with the raw secret, scheme 2 with
 * per-purpose HKDF keys), and the customer token.
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
    public const SALT = 'emporiqa-v2';

    /** Sync webhooks, plugin to Emporiqa. */
    public const LABEL_INBOUND = 'plugin-to-emporiqa';

    /** Action calls, Emporiqa to plugin. */
    public const LABEL_OUTBOUND = 'emporiqa-to-plugin';

    /** Signatures on our answers to action calls. */
    public const LABEL_RESPONSE = 'response';

    public const MAX_SKEW_SECONDS = 300;

    /**
     * Most v1 values a header may carry. Emporiqa sends two while a store
     * rotates its secret (the new one and the old one), one otherwise; the
     * cap leaves one spare. More is refused rather than hashed, so a header
     * cannot make us compare against an unbounded list.
     */
    public const MAX_SIGNATURES = 3;

    /**
     * An HTTP request header, '' when absent.
     *
     * @param string $name e.g. "X-Emporiqa-Signature"
     *
     * @return string
     */
    public static function requestHeader($name)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : '';
    }

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
     * HKDF-SHA256 key for one purpose, bound to the public store id.
     *
     * @param string $secret webhook secret
     * @param string $label one of the LABEL_* constants
     * @param string $storeId public Emporiqa store id
     *
     * @return string 32 raw bytes
     */
    public static function deriveKey($secret, $label, $storeId)
    {
        return hash_hkdf('sha256', (string) $secret, 32, $label . ':' . $storeId, self::SALT);
    }

    /**
     * Scheme-2 header value: t=<unix seconds>,v1=<hex HMAC(key, t . "." . message)>.
     *
     * @param string $key derived key
     * @param string $message signed bytes after the "t." prefix
     * @param int|null $timestamp defaults to now
     *
     * @return string
     */
    public static function buildHeader($key, $message, $timestamp = null)
    {
        $timestamp = $timestamp === null ? time() : (int) $timestamp;

        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $message, $key);
    }

    /**
     * Parse a scheme-2 header. Exactly one t (ASCII digits) and one to
     * MAX_SIGNATURES v1 values (64 lowercase hex each); unknown names are
     * ignored. A malformed v1 refuses the whole header.
     *
     * @param string $header
     *
     * @return array{0: int, 1: string[]}|null
     */
    public static function parseHeader($header)
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', (string) $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                return null;
            }
            if ($pair[0] === 't') {
                if ($timestamp !== null || !preg_match('/^[0-9]{1,12}$/D', $pair[1])) {
                    return null;
                }
                $timestamp = (int) $pair[1];
            } elseif ($pair[0] === 'v1') {
                if (count($signatures) >= self::MAX_SIGNATURES || !preg_match('/^[0-9a-f]{64}$/D', $pair[1])) {
                    return null;
                }
                $signatures[] = $pair[1];
            }
        }
        if ($timestamp === null || !$signatures) {
            return null;
        }

        return [$timestamp, $signatures];
    }

    /**
     * Verify a scheme-2 header. Valid when ANY v1 matches: while the store
     * rotates its secret Emporiqa signs with both, and this shop holds one.
     *
     * @param string $header header value
     * @param string $body raw request body
     * @param string $secret webhook secret
     * @param string $storeId public Emporiqa store id
     * @param string $label one of the LABEL_* constants
     * @param int|null $now defaults to now
     *
     * @return string 'ok', 'signature' or 'expired'
     */
    public static function verifyHeader($header, $body, $secret, $storeId, $label, $now = null)
    {
        $parsed = self::parseHeader($header);
        if ($parsed === null || $secret === '' || $storeId === '') {
            return 'signature';
        }
        list($timestamp, $signatures) = $parsed;
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, self::deriveKey($secret, $label, $storeId));
        $matched = false;
        // Every value is compared, with no early exit, so the time taken does
        // not say which position matched.
        foreach ($signatures as $signature) {
            $matched = hash_equals($expected, $signature) || $matched;
        }
        if (!$matched) {
            return 'signature';
        }
        $now = $now === null ? time() : (int) $now;
        if (abs($now - $timestamp) > self::MAX_SKEW_SECONDS) {
            return 'expired';
        }

        return 'ok';
    }

    /**
     * Signed customer token for the chat widget, format base64url_payload.hmac_hex,
     * signed with the raw secret. `aud` binds it to this Emporiqa store.
     *
     * Never write it into page HTML: full-page caches would serve one
     * customer's token to another. It is served by the uncached token
     * controller instead.
     *
     * @param string $userId customer id
     * @param string $webhookSecret webhook secret
     * @param string $storeId public Emporiqa store id
     *
     * @return string
     */
    public static function generateUserToken($userId, $webhookSecret, $storeId)
    {
        $payload = json_encode([
            'uid' => (string) $userId,
            'ts' => time(),
            'aud' => (string) $storeId,
        ]);

        $encodedPayload = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encodedPayload, $webhookSecret);

        return $encodedPayload . '.' . $signature;
    }
}
