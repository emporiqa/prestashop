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
    const FLUSH_BATCH_SIZE = 50;

    /**
     * Hard cap on the synchronous hook-driven send, in seconds. The merchant
     * admin / front-controller request must never wait longer than this on
     * a slow Emporiqa response. Paired with SYNC_HOOK_CONNECT_TIMEOUT_MS
     * so a slow DNS/TLS handshake can't eat the whole window — see the
     * connect-timeout branch in doRequest().
     */
    const SYNC_HOOK_TIMEOUT = 1.5;

    /**
     * Handshake budget (ms) for hook-driven sends. Tighter than the total
     * window because under normal conditions Emporiqa's DNS+TLS resolves
     * in <100ms; anything slower than this and we'd rather fail fast and
     * let the merchant continue than burn their save latency on retries.
     */
    const SYNC_HOOK_CONNECT_TIMEOUT_MS = 500;

    /** @var array Events queued for deferred sending. Retained for backwards-compat with anything still calling queueEvent + flushPendingEvents. */
    private $pendingEvents = [];

    /** @var string|null Friendly message from the most recent failed sendBatchEvents call, or null after a success. */
    private $lastError;

    /** @var EmporiqaChannelResolver */
    private $channelResolver;

    public function __construct(EmporiqaChannelResolver $channelResolver)
    {
        $this->channelResolver = $channelResolver;
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
     */
    public function dispatchEvent($type, array $data)
    {
        if (!$this->isConfigured()) {
            return;
        }

        $this->sendBatchEvents(
            [['type' => $type, 'data' => $data]],
            self::SYNC_HOOK_TIMEOUT
        );
    }

    /**
     * Dispatch many events from a hook handler. Used when a single hook
     * needs to emit a parent + all its variations in one call.
     *
     * @param array<int, array{type: string, data: array}> $events
     */
    public function dispatchEvents(array $events)
    {
        if (empty($events) || !$this->isConfigured()) {
            return;
        }

        $this->sendBatchEvents($events, self::SYNC_HOOK_TIMEOUT);
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
            $fallback = $result['error'] ?? 'Unknown error';
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
     * @return array{success: bool, message: string}
     */
    public function testConnection()
    {
        $storeId = Configuration::get('EMPORIQA_STORE_ID');
        if (empty($storeId)) {
            return ['success' => false, 'message' => 'Store ID is not configured.'];
        }

        $secret = Configuration::get('EMPORIQA_WEBHOOK_SECRET');
        if (empty($secret)) {
            return ['success' => false, 'message' => 'Connection Secret is not configured.'];
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
                'message' => 'Connection successful!',
                'dry_run' => $result['response'] ?? [],
            ];
        }

        return [
            'success' => false,
            'message' => 'Connection failed: ' . $this->buildFriendlyError($result),
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
     * @return array{success: bool, error: ?string, response: ?array}
     */
    private function doRequest(array $payload, $dryRun = false, $timeout = 30)
    {
        $url = $this->getWebhookUrl();
        if ($dryRun && $url) {
            $url .= '?dry_run=true';
        }
        if (empty($url)) {
            return [
                'success' => false,
                'error' => 'Webhook not configured (missing Store ID).',
                'response' => null,
            ];
        }

        $secret = Configuration::get('EMPORIQA_WEBHOOK_SECRET');
        if (empty($secret)) {
            return [
                'success' => false,
                'error' => 'Connection Secret is not configured.',
                'response' => null,
            ];
        }

        $jsonPayload = json_encode($payload);
        if ($jsonPayload === false) {
            $this->log('JSON encode error: ' . json_last_error_msg() . ' — retrying with UTF-8 substitution');
            $jsonPayload = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        }
        if ($jsonPayload === false) {
            $this->log('JSON encode failed: ' . json_last_error_msg());

            return [
                'success' => false,
                'error' => 'Failed to encode payload: ' . json_last_error_msg(),
                'response' => null,
            ];
        }
        $signature = EmporiqaSignatureHelper::generateSignature($jsonPayload, $secret);

        $ch = curl_init($url);
        if (!$ch) {
            return [
                'success' => false,
                'error' => 'Failed to initialize HTTP client.',
                'response' => null,
            ];
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
        curl_setopt_array($ch, [
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
        curl_close($ch);

        if ($error) {
            $this->log('HTTP request failed: ' . $error);

            return [
                'success' => false,
                'error' => $error,
                'response' => null,
            ];
        }

        $body = json_decode($response, true);

        if (in_array($httpCode, [200, 201, 202], true)) {
            return [
                'success' => true,
                'response' => $body,
                'error' => null,
            ];
        }

        $errorMsg = 'Unexpected status code: ' . $httpCode;
        $truncated = strlen($response) > 500 ? substr($response, 0, 500) . '...' : $response;
        $this->log($errorMsg . ' - Response: ' . $truncated);

        return [
            'success' => false,
            'response' => $body,
            'error' => $errorMsg,
        ];
    }

    private function log($message)
    {
        PrestaShopLogger::addLog('[Emporiqa] ' . $message, 2, null, 'Emporiqa');
    }
}
