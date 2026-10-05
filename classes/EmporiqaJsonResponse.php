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
        $body = is_array($data) ? json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE) : (string) $data;
        exit($body === false ? '{}' : $body);
    }
}
