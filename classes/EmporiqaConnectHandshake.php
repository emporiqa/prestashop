<?php
/**
 * One-click connect handshake (PS 8.1+ and PS 9.x compatible).
 *
 * The body of controllers/admin/AdminEmporiqaConnectController.php, which
 * documents the flow. Kept out of the controller because it needs PHP 8.0
 * and the controller must still parse on PHP 7.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaConnectHandshake
{
    public const DEFAULT_BASE_URL = 'https://emporiqa.com';

    /**
     * The whole exchange, the origin proof Emporiqa runs inside it included,
     * must finish before this. Emporiqa caps its proof at 4 s; past this
     * timeout the shop would keep its old secret while Emporiqa has already
     * issued the new one, and stay cut off until the next connect.
     */
    private const EXCHANGE_TIMEOUT_SECONDS = 20;

    /** @var Context */
    private $context;

    /** @var Module */
    private $module;

    /** @var string|null the state whose nonce is claimed for an exchange; fail() releases it */
    private $exchangingState;

    public function __construct(Context $context, Module $module)
    {
        $this->context = $context;
        $this->module = $module;
    }

    /**
     * Run ?action=initiate or ?action=callback. Always ends in a redirect.
     */
    public function handle(string $action): void
    {
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
     * Always exits with a redirect.
     */
    private function handleInitiate(): void
    {
        if (!Configuration::get('PS_SSL_ENABLED') && !Tools::usingSecureMode()) {
            $this->fail('https_required', $this->t('One-click connect needs your shop to be served over HTTPS.'));
        }

        if ($this->actionsBaseUrl() === '') {
            $this->fail(
                'actions_url_not_https',
                $this->t(
                    'PrestaShop builds this shop\'s module links with http://, and Emporiqa only calls https:// addresses. Turn on SSL under Shop Parameters > General.',
                ),
            );
        }

        // PS does not have a CSRF token natively on inbound admin GET; the
        // bootstrap menu link includes the admin token, so an attacker would
        // need that to land us here. Belt-and-braces: rate-limit via the
        // in-flight transient (cleared on completion or after NONCE_TTL).
        $state = $this->randomToken(32);
        $verifier = $this->randomToken(64);
        $challenge = $this->pkceChallenge($verifier);

        if (!EmporiqaConnectNonce::store($state, $verifier)) {
            $this->fail('persist_failed', $this->t('One-click connect could not start.'));
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

        $base = self::baseUrl();
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
            $this->fail('invalid_iss', $this->t('The connection came back from an unexpected address.'));
        }

        if ($state === '' || $code === '') {
            $this->fail('missing_params', $this->t('Emporiqa sent you back without the connection details.'));
        }

        $verifier = EmporiqaConnectNonce::beginExchange($state);
        if ($verifier === null) {
            $this->fail('invalid_state', $this->t('This connection link has expired or was already used.'));
        }

        $this->exchangingState = $state;
        $result = $this->exchangeCode($code, $verifier, $storeIdParam);
        EmporiqaConnectNonce::finish($state);
        $this->exchangingState = null;

        $this->persistCredentials($result);

        $this->redirectToSettings(['emporiqa_connected' => 1]);
    }

    /**
     * POST {code, verifier, store_id?} → /connect/exchange. Returns the
     * decoded JSON; any failure ends in fail().
     *
     * @return array<string, mixed>
     */
    private function exchangeCode(string $code, string $verifier, string $storeIdHint): array
    {
        // Django's connect_exchange reads the verifier as `code_verifier`
        // and requires `shop_origin` to match the original intent. Matches
        // the WC plugin's POST body shape.
        $payload = [
            'code' => $code,
            'code_verifier' => $verifier,
            'shop_origin' => $this->shopOrigin(),
            'actions_base_url' => $this->actionsBaseUrl(),
        ];
        if ($storeIdHint !== '') {
            $payload['store_id'] = $storeIdHint;
        }

        $url = self::baseUrl() . '/connect/exchange';
        $body = json_encode($payload);
        if ($body === false) {
            $this->fail('encode_failed', $this->t('The connection request could not be built.'));
        }

        $ch = curl_init($url);
        if ($ch === false) {
            $this->fail('curl_init_failed', $this->t('PHP could not start an HTTP request (cURL).'));
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

        if ($response === false) {
            $this->fail('network_error', $this->t('Could not reach Emporiqa: %s', [$error]));
        }

        $data = json_decode((string) $response, true);

        // A 409 carries a sentence for the merchant (a move waiting for an
        // owner's email confirmation); show it as-is.
        if ($httpCode === 409 && is_array($data) && !empty($data['error']) && is_string($data['error'])) {
            $this->fail('http_409', $data['error']);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->fail(
                'http_' . $httpCode,
                $this->t('Emporiqa refused the connection (HTTP %d).', [$httpCode]),
            );
        }

        if (!is_array($data) || empty($data['webhook_secret']) || empty($data['store_id'])) {
            $this->fail('bad_response', $this->t('Emporiqa sent an unexpected answer.'));
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $result the /connect/exchange answer
     */
    private function persistCredentials(array $result): void
    {
        $webhookUrl = (string) ($result['webhook_url'] ?? '');
        // Defense-in-depth: a compromised Emporiqa response can't redirect
        // every shop's webhooks to attacker-controlled infrastructure.
        if (!self::isEmporiqaWebhookUrl($webhookUrl)) {
            $this->fail('webhook_url_rejected', $this->t('Emporiqa sent a Webhook URL on an unexpected address, so nothing was saved.'));
        }

        // /connect/exchange returns the full sync URL (.../sync/<store_id>/).
        // Our EmporiqaWebhookClient appends store_id itself, so strip the
        // trailing /<store_id>/ to avoid duplication. Mirrors the WC fix.
        $webhookUrl = (string) preg_replace('#/sync/[^/]+/?$#', '/sync/', $webhookUrl);

        Configuration::updateGlobalValue('EMPORIQA_STORE_ID', (string) $result['store_id']);
        Configuration::updateGlobalValue('EMPORIQA_WEBHOOK_URL', $webhookUrl);
        Configuration::updateGlobalValue('EMPORIQA_WEBHOOK_SECRET', (string) $result['webhook_secret']);
        Configuration::updateGlobalValue('EMPORIQA_CART_ENABLED', 1);

        // Whether Emporiqa offers ready-made rules to this store and which
        // are live. A platform that does not say leaves the module's last
        // answer in place.
        Emporiqa::storeRulesStatus($result);

        // Pages cached before the connect were rendered without the widget
        // (no store id yet) — drop them so the widget appears immediately.
        Tools::clearSmartyCache();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function randomToken(int $bytes): string
    {
        // PHP 7.2.5+ on PS 8.1+; PHP 8.1+ on PS 9. random_bytes is available.
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Whether a webhook URL is https on the configured Emporiqa host, the
     * only place the module sends the catalog and its signed webhooks.
     */
    public static function isEmporiqaWebhookUrl(string $url): bool
    {
        $parts = parse_url($url);
        $allowedHost = parse_url(self::baseUrl(), PHP_URL_HOST);

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && !empty($parts['host'])
            && strtolower($parts['host']) === strtolower((string) $allowedHost);
    }

    private static function baseUrl(): string
    {
        // Configurable for staging; defaults to production.
        $stored = (string) Configuration::get('EMPORIQA_BASE_URL');
        $base = $stored !== '' ? $stored : self::DEFAULT_BASE_URL;

        return rtrim($base, '/');
    }

    /**
     * Base for ready-made rule endpoints (`&key=<rule>` is merged into it),
     * built by PrestaShop's own link builder so subdirectory installs,
     * language prefixes and non-friendly URLs come out right.
     *
     * Always https: Emporiqa refuses to call anything else. PrestaShop
     * builds http links when PS_SSL_ENABLED is off even if this request
     * arrived over TLS (a terminating proxy), so the scheme is forced then.
     *
     * @return string '' when no https URL can be produced
     */
    public function actionsBaseUrl(): string
    {
        $url = $this->context->link->getModuleLink(
            'emporiqa',
            'action',
            [],
            true,
            (int) Configuration::get('PS_LANG_DEFAULT'),
            (int) $this->context->shop->id,
        );
        if (stripos($url, 'http://') === 0 && (Configuration::get('PS_SSL_ENABLED') || Tools::usingSecureMode())) {
            $url = 'https://' . substr($url, 7);
        }

        return stripos($url, 'https://') === 0 ? $url : '';
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
     *
     * @return never
     */
    private function redirectToSettings(array $extra = []): void
    {
        $params = array_merge(['configure' => 'emporiqa'], $extra);
        $url = $this->context->link->getAdminLink('AdminModules', true, [], $params);
        Tools::redirectAdmin($url);
        exit;
    }

    /**
     * Back-office text in the employee's language, from the module's
     * translations/<iso>.php like the rest of the module.
     *
     * @param array<int, scalar> $args sprintf arguments
     */
    private function t(string $string, array $args = []): string
    {
        $text = Translate::getModuleTranslation('emporiqa', $string, 'AdminEmporiqaConnectController', null, false, null, true, false);

        return $args ? vsprintf($text, $args) : $text;
    }

    /**
     * Keep the error for the settings page (as "code: message"; the page
     * shows only the message) and go back there.
     *
     * @return never
     */
    private function fail(string $code, string $message): void
    {
        Configuration::updateGlobalValue('EMPORIQA_CONNECT_LAST_ERROR', $code . ': ' . $message);
        if ($this->exchangingState !== null) {
            EmporiqaConnectNonce::finish($this->exchangingState);
        }
        $this->redirectToSettings();
    }
}
