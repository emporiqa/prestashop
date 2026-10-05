<?php
/**
 * Emporiqa Customer Token endpoint
 *
 * The body of controllers/front/token.php: the signed customer token for
 * the chat widget. Kept out of the controller because it needs PHP 8.0 and
 * the controller must still parse on PHP 7.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaTokenEndpoint
{
    /**
     * Answer the request and exit.
     */
    public static function run(Context $context)
    {
        $token = '';
        $isPost = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
        $secret = (string) Configuration::get('EMPORIQA_WEBHOOK_SECRET');
        $storeId = (string) Configuration::get('EMPORIQA_STORE_ID');
        if ($isPost && $secret !== '' && $storeId !== ''
            && $context->customer && $context->customer->isLogged()
        ) {
            $token = EmporiqaSignatureHelper::generateUserToken(
                (string) $context->customer->id,
                $secret,
                $storeId,
            );
        }

        self::send($isPost ? 200 : 405, $token);
    }

    /**
     * @param int $httpCode
     * @param string $token
     */
    public static function send($httpCode, $token)
    {
        EmporiqaJsonResponse::send($httpCode, ['token' => $token], ['Cache-Control: no-store, private', 'Vary: Cookie']);
    }
}
