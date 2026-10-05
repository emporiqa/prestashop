<?php
/**
 * Emporiqa Webhook Client
 *
 * Sends webhook events to the Emporiqa API from PrestaShop hooks. Always
 * waits for the server response: we tried a fire-and-forget curl_multi
 * pattern in 1.1.x but PHP-FPM tears the worker down before the kernel
 * actually flushes the TCP send buffer, so the bytes never leave the
 * machine. The bounded SYNC_HOOK_TIMEOUT (1.5 s, with a 500 ms handshake
 * cap) keeps the merchant admin request from stalling if Emporiqa is
 * slow or down.
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaWebhookClient
{
    public const FLUSH_BATCH_SIZE = 50;

    /**
     * Hard cap on the synchronous hook-driven send, in seconds. The merchant
     * admin / front-controller request must never wait longer than this on
     * a slow Emporiqa response. Paired with SYNC_HOOK_CONNECT_TIMEOUT_MS
     * so a slow DNS/TLS handshake can't eat the whole window — see the
     * connect-timeout branch in doRequest().
     */
    public const SYNC_HOOK_TIMEOUT = 1.5;

    /**
     * Handshake budget (ms) for hook-driven sends. Tighter than the total
     * window because under normal conditions Emporiqa's DNS+TLS resolves
     * in <100ms; anything slower than this and we'd rather fail fast and
     * let the merchant continue than burn their save latency on retries.
     */
    public const SYNC_HOOK_CONNECT_TIMEOUT_MS = 500;

    /** @var array Events queued for deferred sending. Retained for backwards-compat with anything still calling queueEvent + flushPendingEvents. */
    private $pendingEvents = [];

    /** @var string|null Friendly message from the most recent failed sendBatchEvents call, or null after a success. */
    private $lastError;

    /** @var int curl errno of the most recent request, 0 when it reached Emporiqa */
    private $lastErrno = 0;

    /**
     * Per-request circuit breaker for hook-driven sends. A request that
     * queued many products (a bulk edit, a catalog-wide resync) would
     * otherwise wait SYNC_HOOK_TIMEOUT on each one while Emporiqa is
     * unreachable. Once open, the rest of this request's hook sends are
     * skipped; admin Sync and Test connection are not affected.
     *
     * @var bool
     */
    private static $hookCircuitOpen = false;

    /**
     * Whether this request already stamped LAST_AUTO_FAIL_KEY, so a failing
     * bulk edit writes the config table once, not once per send.
     *
     * @var bool
     */
    private static $autoFailRecorded = false;

    /**
     * Emporiqa refuses a signature whose timestamp is more than 300 s away
     * from its own clock. Past this, a 401 on a sync send is logged as a
     * clock problem (once a day) instead of a bare signature error.
     */
    public const CLOCK_SKEW_REJECT_SECONDS = 300;

    /** Last day a clock-skew 401 was logged, so a broken clock logs once a day, not per save. */
    public const CLOCK_SKEW_LOGGED_KEY = 'EMPORIQA_CLOCK_SKEW_LOGGED';

    /** When an automatic (hook) send last failed, 0 once one succeeds again; shown on the Sync tab. */
    public const LAST_AUTO_FAIL_KEY = 'EMPORIQA_LAST_AUTO_FAIL';

    /** @var EmporiqaChannelResolver */
    private $channelResolver;

    /** @var Context|null */
    private $context;

    public function __construct(EmporiqaChannelResolver $channelResolver, ?Context $context = null)
    {
        $this->channelResolver = $channelResolver;
        $this->context = $context;
    }

    /**
     * Dispatch a single event from a PrestaShop hook handler.
     *
     * Sends synchronously with a SYNC_HOOK_TIMEOUT-second ceiling. Under
     * normal conditions the round-trip is ~50-100 ms, so the merchant's
     * save / checkout flow never feels it.
     *
     * @param string $type Event type (e.g. product.updated)
     * @param array $data Event data payload
     *
     * @return bool whether Emporiqa accepted the event
     */
    public function dispatchEvent($type, array $data)
    {
        if (!$this->isConfigured()) {
            return false;
        }

        return $this->dispatchFromHook([['type' => $type, 'data' => $data]]);
    }

    /**
     * Dispatch many events from a hook handler. Used when a single hook
     * needs to emit a parent + all its variations in one call.
     *
     * @param array<int, array{type: string, data: array}> $events
     *
     * @return bool whether Emporiqa accepted the events
     */
    public function dispatchEvents(array $events)
    {
        if (empty($events) || !$this->isConfigured()) {
            return false;
        }

        return $this->dispatchFromHook($events);
    }

    /**
     * @return bool
     */
    private function dispatchFromHook(array $events)
    {
        // An order is reported once and never resent by a later save, so it
        // is always tried, even after a product send in this request failed.
        $isOrder = in_array('order.completed', array_column($events, 'type'), true);
        if (self::$hookCircuitOpen && !$isOrder) {
            return false;
        }
        if ($this->sendBatchEvents($events, self::SYNC_HOOK_TIMEOUT)) {
            // A cached read on every send; a write only after a failure.
            if (Configuration::get(self::LAST_AUTO_FAIL_KEY)) {
                Configuration::updateGlobalValue(self::LAST_AUTO_FAIL_KEY, 0);
                self::$autoFailRecorded = false;
            }

            return true;
        }
        // For the Sync tab: automatic updates are failing.
        if (!self::$autoFailRecorded) {
            Configuration::updateGlobalValue(self::LAST_AUTO_FAIL_KEY, time());
            self::$autoFailRecorded = true;
        }
        if (in_array($this->lastErrno, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEOUTED], true)) {
            self::$hookCircuitOpen = true;
            $this->log('Emporiqa did not answer; the remaining sync webhooks of this request are skipped.');
        }

        return false;
    }

    /**
     * Legacy in-memory queue API. New code should call dispatchEvent()
     * directly. Kept so any third-party module that hooked into our queue
     * still works after upgrade.
     *
     * @param string $type Event type
     * @param array $data Event data payload
     */
    public function queueEvent($type, array $data)
    {
        $this->pendingEvents[] = [
            'type' => $type,
            'data' => $data,
        ];
    }

    /**
     * Legacy flush API — no longer called from hook handlers in 1.2.0+.
     * Retained for backwards-compat (e.g. CLI scripts that built batches).
     * Sends batches via the non-blocking path when available.
     */
    public function flushPendingEvents()
    {
        if (empty($this->pendingEvents)) {
            return;
        }

        $events = $this->pendingEvents;
        $this->pendingEvents = [];

        foreach (array_chunk($events, self::FLUSH_BATCH_SIZE) as $batch) {
            $this->dispatchEvents($batch);
        }
    }

    private function isConfigured()
    {
        $url = Configuration::get('EMPORIQA_WEBHOOK_URL');
        $secret = Configuration::get('EMPORIQA_WEBHOOK_SECRET');
        $storeId = Configuration::get('EMPORIQA_STORE_ID');

        return !empty($url) && !empty($secret) && !empty($storeId);
    }

    /**
     * Send a batch of events immediately.
     *
     * @param array $events Array of event objects
     * @param int|float $timeout Request timeout in seconds (default 10 for deferred, use 30 for sync)
     *
     * @return bool
     */
    public function sendBatchEvents(array $events, $timeout = 10)
    {
        if (empty($events)) {
            return true;
        }

        $payload = ['events' => $events];
        $result = $this->doRequest($payload, false, $timeout);

        if (!$result['success']) {
            $this->lastError = $this->buildFriendlyError($result);
            $this->log('Webhook error: ' . $this->lastError);
        } else {
            $this->lastError = null;
        }

        return $result['success'];
    }

    /**
     * Friendly message from the most recent failed sendBatchEvents call.
     * Cleared after a successful call. Used by user-triggered flows
     * (Sync / Test Connection) to surface a human-readable reason in
     * the admin response instead of a bare boolean false.
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * Turn a doRequest() failure into a single human-readable line by
     * pulling the most informative field out of the Django response
     * (`error`, `detail`, `message`, `errors[0]`, plus `hint` when set).
     * Public so the Test Connection and Sync buttons can surface the
     * same wording the merchant sees on the admin page.
     */
    public function buildFriendlyError(array $result)
    {
        $body = isset($result['response']) && is_array($result['response']) ? $result['response'] : [];
        $parts = [];
        foreach (['error', 'detail', 'message'] as $key) {
            if (!empty($body[$key]) && is_string($body[$key])) {
                $parts[] = $body[$key];
                break;
            }
        }
        if (!empty($body['errors']) && is_array($body['errors'])) {
            $first = reset($body['errors']);
            $parts[] = is_string($first) ? $first : json_encode($first);
        }
        if (!empty($body['hint']) && is_string($body['hint'])) {
            $parts[] = '(' . $body['hint'] . ')';
        }
        if (empty($parts)) {
            $fallback = $result['error'] ?? $this->t('Unknown error');
            $parts[] = is_string($fallback) ? $fallback : json_encode($fallback);
        }

        return implode(' ', $parts);
    }

    /**
     * Send a single event immediately.
     *
     * @param string $type Event type
     * @param array $data Event data
     *
     * @return bool
     */
    public function sendEvent($type, array $data)
    {
        return $this->sendBatchEvents([
            ['type' => $type, 'data' => $data],
        ]);
    }

    /**
     * Start a sync session.
     *
     * @param string $sessionId Session ID
     * @param string $entity Entity type (products, pages)
     *
     * @return bool
     */
    public function startSyncSession($sessionId, $entity)
    {
        return $this->sendEvent('sync.start', [
            'session_id' => $sessionId,
            'entity' => $entity,
        ]);
    }

    /**
     * Complete a sync session.
     *
     * @param string $sessionId Session ID
     * @param string $entity Entity type (products, pages)
     *
     * @return bool
     */
    public function completeSyncSession($sessionId, $entity)
    {
        return $this->sendEvent('sync.complete', [
            'session_id' => $sessionId,
            'entity' => $entity,
        ]);
    }

    /**
     * Test the webhook connection.
     *
     * @return array{success: bool, message: string, dry_run?: array, clock_skew?: int|null}
     */
    public function testConnection()
    {
        $storeId = Configuration::get('EMPORIQA_STORE_ID');
        if (empty($storeId)) {
            return ['success' => false, 'message' => $this->t('No Store ID is saved yet. Connect to Emporiqa on the Settings tab.')];
        }

        $secret = Configuration::get('EMPORIQA_WEBHOOK_SECRET');
        if (empty($secret)) {
            return ['success' => false, 'message' => $this->t('No Connection Secret is saved yet. Connect to Emporiqa on the Settings tab.')];
        }

        $contexts = $this->channelResolver->getShopContexts();

        $channels = [];
        $names = [];
        $descriptions = [];
        $links = [];
        $attributes = [];
        $categories = [];
        $brands = [];
        $prices = [];
        $availabilities = [];
        $stocks = [];
        $images = [];

        foreach ($contexts as $channelKey => $ctx) {
            $channels[] = $channelKey;
            $names[$channelKey] = ['en' => 'Connection Test'];
            $descriptions[$channelKey] = ['en' => 'This is a connection test.'];
            $links[$channelKey] = ['en' => $ctx['domain'] . '/test'];
            $attributes[$channelKey] = ['en' => new stdClass()];
            $categories[$channelKey] = ['en' => ['Test > Connection']];
            $brands[$channelKey] = 'Test';
            $availabilities[$channelKey] = 'available';
            $stocks[$channelKey] = null;
            $images[$channelKey] = [];

            $priceEntries = [];
            if (!empty($ctx['currencies'])) {
                foreach ($ctx['currencies'] as $curr) {
                    $iso = is_array($curr) ? $curr['iso_code'] : $curr->iso_code;
                    $priceEntries[] = [
                        'currency' => $iso,
                        'current_price' => 0.0,
                        'regular_price' => 0.0,
                    ];
                }
            }
            if (empty($priceEntries)) {
                $priceEntries[] = [
                    'currency' => 'EUR',
                    'current_price' => 0.0,
                    'regular_price' => 0.0,
                ];
            }
            $prices[$channelKey] = $priceEntries;
        }

        $payload = [
            'events' => [
                [
                    'type' => 'product.created',
                    'data' => [
                        'identification_number' => 'test-connection',
                        'sku' => 'TEST-001',
                        'channels' => $channels,
                        'names' => $names,
                        'descriptions' => $descriptions,
                        'links' => $links,
                        'attributes' => $attributes,
                        'categories' => $categories,
                        'brands' => $brands,
                        'prices' => $prices,
                        'availability_statuses' => $availabilities,
                        'stock_quantities' => $stocks,
                        'images' => $images,
                        'parent_sku' => null,
                        'is_parent' => false,
                        'variation_attributes' => new stdClass(),
                    ],
                ],
            ],
        ];

        $result = $this->doRequest($payload, true);

        if ($result['success']) {
            return [
                'success' => true,
                'message' => $this->t('Connection works. Emporiqa accepted your Store ID and Connection Secret.'),
                'dry_run' => $result['response'] ?? [],
                'clock_skew' => $result['clock_skew'],
            ];
        }

        return [
            'success' => false,
            'message' => sprintf($this->t('Connection failed: %s'), $this->buildFriendlyError($result)),
            'clock_skew' => $result['clock_skew'],
        ];
    }

    /**
     * Build the full webhook URL from base URL and store ID.
     *
     * @return string|null
     */
    private function getWebhookUrl()
    {
        $baseUrl = Configuration::get('EMPORIQA_WEBHOOK_URL') ?: Emporiqa::DEFAULT_WEBHOOK_URL;
        $storeId = Configuration::get('EMPORIQA_STORE_ID');

        if (empty($storeId)) {
            return null;
        }

        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        if (!in_array($scheme, ['https', 'http'], true)) {
            $this->log('Invalid webhook URL scheme: ' . ($scheme ?: 'none') . '. Only https:// and http:// are allowed.');

            return null;
        }

        return rtrim($baseUrl, '/') . '/' . $storeId . '/';
    }

    /**
     * Send an HTTP POST request to the webhook endpoint.
     *
     * @param array $payload Request payload
     * @param bool $dryRun Append ?dry_run=true to validate without storing
     * @param int|float $timeout Total request timeout in seconds
     *
     * @return array{success: bool, error: ?string, response: ?array, clock_skew: ?int}
     */
    private function doRequest(array $payload, $dryRun = false, $timeout = 30)
    {
        $this->lastErrno = 0;
        $url = $this->getWebhookUrl();
        if ($dryRun && $url) {
            $url .= '?dry_run=true';
        }
        if (empty($url)) {
            return self::failure($this->t('No Store ID is saved yet, or the Webhook URL is not a valid http(s) address.'));
        }

        $secret = Configuration::get('EMPORIQA_WEBHOOK_SECRET');
        if (empty($secret)) {
            return self::failure($this->t('No Connection Secret is saved yet. Connect to Emporiqa on the Settings tab.'));
        }

        $jsonPayload = json_encode($payload);
        if ($jsonPayload === false) {
            $this->log('JSON encode error: ' . json_last_error_msg() . ' — retrying with UTF-8 substitution');
            $jsonPayload = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        }
        if ($jsonPayload === false) {
            $this->log('JSON encode failed: ' . json_last_error_msg());

            return self::failure(sprintf($this->t('The data could not be encoded: %s'), json_last_error_msg()));
        }
        // Both schemes while platforms that only know the old header may
        // still receive this. Built per call, so every send gets a fresh t.
        $signature = EmporiqaSignatureHelper::generateSignature($jsonPayload, $secret);
        $scheme2 = EmporiqaSignatureHelper::buildHeader(
            EmporiqaSignatureHelper::deriveKey(
                $secret,
                EmporiqaSignatureHelper::LABEL_INBOUND,
                (string) Configuration::get('EMPORIQA_STORE_ID'),
            ),
            $jsonPayload,
        );

        $ch = curl_init($url);
        if (!$ch) {
            return self::failure($this->t('PHP could not start an HTTP request (cURL).'));
        }
        // Hook-driven (merchant-request) sends use a tight 500ms handshake
        // budget so the bounded 1.5s total can't be wholly consumed by DNS
        // or TLS variance. Admin-initiated sends (Sync, Test Connection)
        // get the full 5s handshake cap — the merchant is actively waiting
        // and minor handshake jitter on a one-off button click is fine.
        $timeoutMs = (int) round($timeout * 1000);
        $connectTimeoutMs = $timeoutMs <= 2000
            ? self::SYNC_HOOK_CONNECT_TIMEOUT_MS
            : 5000;
        // Emporiqa's Date header, to tell a skewed server clock apart from a
        // wrong secret when a signature is refused.
        $dateHeader = '';
        curl_setopt_array($ch, [
            CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$dateHeader) {
                if (stripos($line, 'Date:') === 0) {
                    $dateHeader = trim(substr($line, 5));
                }

                return strlen($line);
            },
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Webhook-Signature: ' . $signature,
                'X-Emporiqa-Webhook-Signature: ' . $scheme2,
                'X-Emporiqa-Plugin-Version: prestashop/' . Emporiqa::VERSION,
            ],
        ]);

        // Respect PS proxy configuration
        $proxyServer = Configuration::get('PS_PROXY_SERVER');
        if (!empty($proxyServer)) {
            curl_setopt($ch, CURLOPT_PROXY, $proxyServer);
            $proxyPort = Configuration::get('PS_PROXY_PORT');
            if (!empty($proxyPort)) {
                curl_setopt($ch, CURLOPT_PROXYPORT, (int) $proxyPort);
            }
            $proxyUser = Configuration::get('PS_PROXY_USER');
            $proxyPass = Configuration::get('PS_PROXY_PASSWD');
            if (!empty($proxyUser)) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyUser . ':' . $proxyPass);
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $this->lastErrno = (int) curl_errno($ch);

        if ($error) {
            $this->log('HTTP request failed: ' . $error);

            return self::failure($error);
        }

        $body = json_decode($response, true);
        $clockSkew = self::clockSkew($dateHeader, time());

        if (in_array($httpCode, [200, 201, 202], true)) {
            return [
                'success' => true,
                'response' => $body,
                'error' => null,
                'clock_skew' => $clockSkew,
            ];
        }

        if ($httpCode === 401 && $clockSkew !== null && abs($clockSkew) > self::CLOCK_SKEW_REJECT_SECONDS) {
            $this->logClockSkewOncePerDay($clockSkew);
        }

        $truncated = strlen($response) > 500 ? substr($response, 0, 500) . '...' : $response;
        $this->log('Unexpected status code: ' . $httpCode . ' - Response: ' . $truncated);

        return [
            'success' => false,
            'response' => $body,
            'error' => sprintf($this->t('Emporiqa answered with HTTP status %d.'), $httpCode),
            'clock_skew' => $clockSkew,
        ];
    }

    /**
     * A doRequest() answer for a request that got no response.
     *
     * @param string $error
     *
     * @return array{success: bool, error: string, response: null, clock_skew: null}
     */
    private static function failure($error)
    {
        return ['success' => false, 'error' => $error, 'response' => null, 'clock_skew' => null];
    }

    /**
     * Seconds this server's clock is ahead of Emporiqa's (negative when
     * behind), from the response Date header. Null when there is none.
     *
     * @param string $dateHeader
     * @param int $now
     *
     * @return int|null
     */
    public static function clockSkew($dateHeader, $now)
    {
        $remote = $dateHeader !== '' ? strtotime($dateHeader) : false;

        return $remote === false ? null : (int) $now - $remote;
    }

    private function logClockSkewOncePerDay($clockSkew)
    {
        $today = date('Y-m-d');
        if (Configuration::getGlobalValue(self::CLOCK_SKEW_LOGGED_KEY) === $today) {
            return;
        }
        Configuration::updateGlobalValue(self::CLOCK_SKEW_LOGGED_KEY, $today);
        $this->log(sprintf(
            'Emporiqa refused the signature and this server clock is off by %d seconds. Emporiqa refuses signatures more than 5 minutes off; ask your host to enable NTP.',
            (int) $clockSkew,
        ));
    }

    /**
     * Translated back-office text. Messages from here reach the merchant
     * through Test connection and the sync log.
     *
     * @param string $string English source text
     *
     * @return string
     */
    private function t($string)
    {
        // No language outside a web request (a CLI run): keep the English.
        if ($this->context === null || empty($this->context->language)) {
            return $string;
        }

        return Translate::getModuleTranslation('emporiqa', $string, 'EmporiqaWebhookClient', null, false, null, true, false);
    }

    private function log($message)
    {
        PrestaShopLogger::addLog('[Emporiqa] ' . $message, 2, null, 'Emporiqa');
    }
}
