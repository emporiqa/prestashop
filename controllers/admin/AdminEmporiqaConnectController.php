<?php
/**
 * One-click connect handshake controller (PS 8.1+ and PS 9.x compatible).
 *
 * Mirrors the WooCommerce Emporiqa_Connect flow. Two actions:
 *
 *   ?action=initiate  — merchant clicks "Connect to Emporiqa". We mint a
 *                       random state + PKCE verifier, persist them in the
 *                       nonce table, then 302 to emporiqa.com/connect/start.
 *
 *   ?action=callback  — emporiqa.com redirects back with state + code.
 *                       We atomically consume the nonce, exchange the code
 *                       for a connection secret via /connect/exchange, and
 *                       persist (store_id, webhook_url, webhook_secret).
 *
 * Security model: the `state` parameter is itself the CSRF nonce. PS' own
 * admin token (Tools::getAdminToken) protects the inbound initiate; the
 * outbound callback comes from a third-party redirect, so wp_verify_nonce
 * doesn't apply — atomic state consumption is the equivalent guarantee.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminEmporiqaConnectController extends ModuleAdminController
{
    public const DEFAULT_BASE_URL = 'https://emporiqa.com';

    public const NONCE_TTL_SECONDS = 300; // 5 minutes — matches WC

    private const EXCHANGE_TIMEOUT_SECONDS = 10;

    public function __construct()
    {
        parent::__construct();
        $this->bootstrap = false;
    }

    /**
     * Skip the default ModuleAdminController template rendering. We always
     * exit via Tools::redirectAdmin(), so there is nothing to display.
     */
    public function initContent()
    {
        // Hidden tab — some PS minor versions skip ModuleAdminController::checkAccess
        // when visible=false. Re-assert the employee is logged in before dispatching.
        if (!$this->context->employee || !$this->context->employee->isLoggedBack()) {
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminLogin'));
            exit;
        }

        $action = (string) Tools::getValue('action');

        if ($action === 'initiate') {
            $this->handleInitiate();
            return;
        }

        if ($action === 'callback') {
            $this->handleCallback();
            return;
        }

        $this->redirectToSettings();
    }

    // -------------------------------------------------------------------------
    // Initiate (step 3 of the connect spec)
    // -------------------------------------------------------------------------

    /**
     * Mint state + PKCE verifier, persist, 302 to emporiqa.com/connect/start.
     * Aborts with a redirect — never returns.
     */
    private function handleInitiate(): void
    {
        if (!Configuration::get('PS_SSL_ENABLED') && !Tools::usingSecureMode()) {
            $this->setError(
                'https_required',
                $this->trans(
                    'Your shop must be served over HTTPS to use one-click connect.',
                    [],
                    'Modules.Emporiqa.Admin'
                )
            );
            $this->redirectToSettings();
            return;
        }

        // PS does not have a CSRF token natively on inbound admin GET; the
        // bootstrap menu link includes the admin token, so an attacker would
        // need that to land us here. Belt-and-braces: rate-limit via the
        // in-flight transient (cleared on completion or after NONCE_TTL).
        $state = $this->randomToken(32);
        $verifier = $this->randomToken(64);
        $challenge = $this->pkceChallenge($verifier);

        if (!$this->storeNonce($state, $verifier)) {
            $this->setError(
                'persist_failed',
                $this->trans(
                    'Could not start the connect handshake. Please try again.',
                    [],
                    'Modules.Emporiqa.Admin'
                )
            );
            $this->redirectToSettings();
            return;
        }

        $params = [
            'platform' => 'prestashop',
            'plugin_version' => $this->module->version,
            'shop_origin' => $this->shopOrigin(),
            'return_path' => $this->returnPath(),
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            // Django's connect_start reads this as `state` (matches WC).
            'state' => $state,
            'shop_name' => Configuration::get('PS_SHOP_NAME'),
        ];

        $base = $this->baseUrl();
        $url = $base . '/connect/start?' . http_build_query($params);

        // Don't leak the admin URL (with admin token) to emporiqa.com via
        // the Referer header. Use raw header() rather than Tools::redirect()
        // because PS 8.1+ Tools::redirect strips/rewrites cross-host URLs.
        header('Referrer-Policy: no-referrer');
        header('Location: ' . $url, true, 302);
        exit;
    }

    // -------------------------------------------------------------------------
    // Callback (step 6 of the connect spec)
    // -------------------------------------------------------------------------

    /**
     * Emporiqa → plugin callback. Validate iss + state, atomically consume
     * the nonce, POST code + verifier to /connect/exchange, persist secret.
     * Always exits with a redirect.
     */
    private function handleCallback(): void
    {
        $state = (string) Tools::getValue('state');
        $code = (string) Tools::getValue('code');
        $iss = (string) Tools::getValue('iss');
        $storeIdParam = (string) Tools::getValue('emporiqa_store_id');

        if ($iss !== 'emporiqa.com') {
            $this->setError(
                'invalid_iss',
                $this->trans('Unexpected redirect source.', [], 'Modules.Emporiqa.Admin')
            );
            $this->redirectToSettings();
            return;
        }

        if ($state === '' || $code === '') {
            $this->setError(
                'missing_params',
                $this->trans('Callback missing required parameters.', [], 'Modules.Emporiqa.Admin')
            );
            $this->redirectToSettings();
            return;
        }

        $verifier = $this->consumeNonce($state);
        if ($verifier === null) {
            $this->setError(
                'invalid_state',
                $this->trans(
                    'Connection link expired or already used. Click Connect to try again.',
                    [],
                    'Modules.Emporiqa.Admin'
                )
            );
            $this->redirectToSettings();
            return;
        }

        $result = $this->exchangeCode($code, $verifier, $storeIdParam);
        if ($result === null) {
            // exchangeCode already set the error.
            $this->redirectToSettings();
            return;
        }

        if (!$this->persistCredentials(
            (string) ($result['store_id'] ?? ''),
            (string) ($result['webhook_url'] ?? ''),
            (string) ($result['webhook_secret'] ?? '')
        )) {
            $this->redirectToSettings();
            return;
        }

        $this->redirectToSettings(['emporiqa_connected' => 1]);
    }

    /**
     * POST {code, verifier, store_id?} → /connect/exchange. Returns the
     * decoded JSON on success, or null on failure (after setting an error).
     *
     * @return array<string, mixed>|null
     */
    private function exchangeCode(string $code, string $verifier, string $storeIdHint): ?array
    {
        // Django's connect_exchange reads the verifier as `code_verifier`
        // and requires `shop_origin` to match the original intent. Matches
        // the WC plugin's POST body shape.
        $payload = [
            'code' => $code,
            'code_verifier' => $verifier,
            'shop_origin' => $this->shopOrigin(),
        ];
        if ($storeIdHint !== '') {
            $payload['store_id'] = $storeIdHint;
        }

        $url = $this->baseUrl() . '/connect/exchange';
        $body = json_encode($payload);
        if ($body === false) {
            $this->setError(
                'encode_failed',
                $this->trans('Could not build connect request.', [], 'Modules.Emporiqa.Admin')
            );
            return null;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            $this->setError(
                'curl_init_failed',
                $this->trans('Could not contact Emporiqa.', [], 'Modules.Emporiqa.Admin')
            );
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: Emporiqa-PrestaShop/' . $this->module->version,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => self::EXCHANGE_TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->setError(
                'network_error',
                $this->trans('Network error talking to Emporiqa: %s', [$error], 'Modules.Emporiqa.Admin')
            );
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->setError(
                'http_' . $httpCode,
                $this->trans(
                    'Emporiqa rejected the connect request (HTTP %d).',
                    [$httpCode],
                    'Modules.Emporiqa.Admin'
                )
            );
            return null;
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data) || empty($data['webhook_secret']) || empty($data['store_id'])) {
            $this->setError(
                'bad_response',
                $this->trans('Unexpected response from Emporiqa.', [], 'Modules.Emporiqa.Admin')
            );
            return null;
        }

        return $data;
    }

    private function persistCredentials(string $storeId, string $webhookUrl, string $webhookSecret): bool
    {
        // Defense-in-depth: only persist a webhook URL on https:// and whose
        // host matches the configured Emporiqa base. A compromised Emporiqa
        // response can't redirect every shop's webhooks to attacker-controlled
        // infrastructure.
        $parts = parse_url($webhookUrl);
        $allowedHost = parse_url($this->baseUrl(), PHP_URL_HOST);
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host'])
            || strtolower($parts['host']) !== strtolower((string) $allowedHost)
        ) {
            $this->setError(
                'webhook_url_rejected',
                $this->trans('Refusing webhook URL from unexpected host.', [], 'Modules.Emporiqa.Admin')
            );

            return false;
        }

        // /connect/exchange returns the full sync URL (.../sync/<store_id>/).
        // Our EmporiqaWebhookClient appends store_id itself, so strip the
        // trailing /<store_id>/ to avoid duplication. Mirrors the WC fix.
        $webhookUrl = (string) preg_replace('#/sync/[^/]+/?$#', '/sync/', $webhookUrl);

        Configuration::updateGlobalValue('EMPORIQA_STORE_ID', $storeId);
        Configuration::updateGlobalValue('EMPORIQA_WEBHOOK_URL', $webhookUrl);
        Configuration::updateGlobalValue('EMPORIQA_WEBHOOK_SECRET', $webhookSecret);
        Configuration::updateGlobalValue('EMPORIQA_CART_ENABLED', 1);
        Configuration::updateGlobalValue('EMPORIQA_ORDER_TRACKING', 1);

        return true;
    }

    // -------------------------------------------------------------------------
    // Nonce storage (atomic one-shot consume)
    // -------------------------------------------------------------------------

    /**
     * Store (state, verifier) with a 5-minute TTL. State is hashed before
     * storage so a database leak does not let the attacker complete an
     * in-flight handshake.
     */
    private function storeNonce(string $state, string $verifier): bool
    {
        $stateHash = hash('sha256', $state);
        $now = time();
        $db = Db::getInstance();

        // Opportunistically clean up expired rows so the table stays small.
        $cutoff = $now - self::NONCE_TTL_SECONDS;
        $db->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'emporiqa_connect_nonce` '
            . 'WHERE `created_at` < ' . (int) $cutoff
        );

        return (bool) $db->insert('emporiqa_connect_nonce', [
            'state_hash' => pSQL($stateHash),
            'verifier' => pSQL($verifier),
            'created_at' => (int) $now,
        ]);
    }

    /**
     * Return the verifier for $state and atomically delete the row. Returns
     * null if the state is missing, expired, or the delete affected 0 rows
     * (i.e., another request consumed it first).
     */
    private function consumeNonce(string $state): ?string
    {
        $stateHash = hash('sha256', $state);
        $cutoff = time() - self::NONCE_TTL_SECONDS;
        $db = Db::getInstance();

        $verifier = $db->getValue(
            'SELECT `verifier` FROM `' . _DB_PREFIX_ . 'emporiqa_connect_nonce` '
            . 'WHERE `state_hash` = "' . pSQL($stateHash) . '" '
            . 'AND `created_at` >= ' . (int) $cutoff
        );

        if ($verifier === false || $verifier === null || $verifier === '') {
            return null;
        }

        // Atomic single-use: only the request whose DELETE affects 1 row
        // wins. A racing tab will get 0 affected rows and abort.
        $db->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'emporiqa_connect_nonce` '
            . 'WHERE `state_hash` = "' . pSQL($stateHash) . '"'
        );
        if ((int) $db->Affected_Rows() < 1) {
            return null;
        }

        return (string) $verifier;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function randomToken(int $bytes): string
    {
        // PHP 7.4+ on PS 8.1+; PHP 8.1+ on PS 9. random_bytes is available.
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function baseUrl(): string
    {
        // Configurable for staging; defaults to production.
        $stored = (string) Configuration::get('EMPORIQA_BASE_URL');
        $base = $stored !== '' ? $stored : self::DEFAULT_BASE_URL;
        return rtrim($base, '/');
    }

    private function shopOrigin(): string
    {
        $protocol = (Configuration::get('PS_SSL_ENABLED') || Tools::usingSecureMode()) ? 'https://' : 'http://';
        return rtrim($protocol . Tools::getShopDomainSsl(), '/');
    }

    /**
     * Where emporiqa.com should redirect the merchant back to. PS' admin
     * URL embeds a per-employee token, so we point at this controller and
     * let the merchant's session re-validate it on return. Django's
     * RETURN_PATH_ALLOWLIST matches `/<admin-slug>/index.php/AdminEmporiqa*`.
     */
    private function returnPath(): string
    {
        $link = $this->context->link->getAdminLink('AdminEmporiqaConnect', true, [], [
            'action' => 'callback',
        ]);

        // Django allowlist expects a relative path. Strip scheme + host.
        $parts = parse_url($link);
        $path = $parts['path'] ?? '/';
        if (!empty($parts['query'])) {
            $path .= '?' . $parts['query'];
        }
        return $path;
    }

    /**
     * @param array<string, scalar> $extra Extra query args (e.g. emporiqa_connected=1).
     */
    private function redirectToSettings(array $extra = []): void
    {
        $params = array_merge(['configure' => 'emporiqa'], $extra);
        $url = $this->context->link->getAdminLink('AdminModules', true, [], $params);
        Tools::redirectAdmin($url);
        exit;
    }

    private function setError(string $code, string $message): void
    {
        Configuration::updateGlobalValue('EMPORIQA_CONNECT_LAST_ERROR', $code . ': ' . $message);
    }
}
