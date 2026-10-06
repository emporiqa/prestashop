<?php
/**
 * Emporiqa JSON response
 *
 * The one "drop PrestaShop's output buffers, send JSON, exit" every endpoint
 * and the back-office AJAX use. PHP 7 parseable (no trailing commas in
 * calls or parameters), because the PHP 7 entry shells use it to answer 503.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaJsonResponse
{
    /**
     * Send and exit. Never cached: every answer is per request.
     *
     * @param int $httpCode
     * @param array|string $data an array is JSON-encoded; a string is sent as it is
     * @param string[] $headers extra headers; a Cache-Control here replaces the default
     */
    public static function send($httpCode, $data, array $headers = [])
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        http_response_code((int) $httpCode);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        foreach ($headers as $header) {
            header($header);
        }
        $body = is_array($data) ? self::encode($data, JSON_INVALID_UTF8_SUBSTITUTE) : (string) $data;
        exit($body === false ? '{}' : $body);
    }

    /**
     * json_encode with every float in its shortest exact form (17.925, not
     * 17.925000000000001), whatever serialize_precision the host's php.ini sets.
     *
     * @param mixed $data
     * @param int $flags
     *
     * @return string|false
     */
    public static function encode($data, $flags = 0)
    {
        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            return json_encode($data, $flags);
        } finally {
            if ($precision !== false) {
                ini_set('serialize_precision', $precision);
            }
        }
    }
}
