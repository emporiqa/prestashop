<?php
/**
 * Emporiqa Webhook Client
 *
 * Handles sending webhook events to the Emporiqa API. Events triggered by
 * PrestaShop hooks are queued and sent in a single batch on shutdown.
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

    /** @var array Events queued for deferred sending */
    private $pendingEvents = [];

    /** @var EmporiqaChannelResolver */
    private $channelResolver;

    public function __construct(EmporiqaChannelResolver $channelResolver)
    {
        $this->channelResolver = $channelResolver;
    }

    /**
     * Queue an event for deferred sending.
     *
     * @param string $type Event type (e.g. product.created)
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
     * Flush all pending events by sending them in batches.
     * Called automatically via register_shutdown_function().
     * Stops on first failure to avoid hanging when the API is down.
     */
    public function flushPendingEvents()
    {
        if (empty($this->pendingEvents)) {
            return;
        }

        $events = $this->pendingEvents;
        $this->pendingEvents = [];

        foreach (array_chunk($events, self::FLUSH_BATCH_SIZE) as $batch) {
            if (!$this->sendBatchEvents($batch)) {
                $remaining = count($events) - count($batch);
                if ($remaining > 0) {
                    $this->log('Stopping flush: API unreachable. ' . $remaining . ' events dropped.');
                }
                break;
            }
        }
    }

    /**
     * Send a batch of events immediately.
     *
     * @param array $events Array of event objects
     * @param int $timeout Request timeout in seconds (default 10 for deferred, use 30 for sync)
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
            $this->log('Webhook error: ' . ($result['error'] ?? 'Unknown'));
        }

        return $result['success'];
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
            return ['success' => false, 'message' => 'Webhook Secret is not configured.'];
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
            'message' => 'Connection failed: ' . ($result['error'] ?? 'Unknown error'),
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
     * @param int $timeout Total request timeout in seconds
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
                'error' => 'Webhook Secret is not configured.',
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
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
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
