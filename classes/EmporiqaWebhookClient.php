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
     * @param string|null $language Language code
     *
     * @return bool
     */
    public function startSyncSession($sessionId, $entity, $language = null)
    {
        $data = [
            'session_id' => $sessionId,
            'entity' => $entity,
            'channel' => (string) Configuration::get('EMPORIQA_WIDGET_CHANNEL'),
        ];
        if ($language !== null) {
            $data['language'] = $language;
        }

        return $this->sendEvent('sync.start', $data);
    }

    /**
     * Complete a sync session.
     *
     * @param string $sessionId Session ID
     * @param string $entity Entity type (products, pages)
     * @param string|null $language Language code
     *
     * @return bool
     */
    public function completeSyncSession($sessionId, $entity, $language = null)
    {
        $data = [
            'session_id' => $sessionId,
            'entity' => $entity,
            'channel' => (string) Configuration::get('EMPORIQA_WIDGET_CHANNEL'),
        ];
        if ($language !== null) {
            $data['language'] = $language;
        }

        return $this->sendEvent('sync.complete', $data);
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

        $shopUrl = EmporiqaLanguageHelper::getShopBaseUrl();
        $channel = (string) Configuration::get('EMPORIQA_WIDGET_CHANNEL');

        $priceEntries = [];
        $currencies = Currency::getCurrencies(true);
        foreach ($currencies as $curr) {
            $iso = is_array($curr) ? $curr['iso_code'] : $curr->iso_code;
            $priceEntries[] = [
                'currency' => $iso,
                'current_price' => 0.0,
                'regular_price' => 0.0,
                'price_incl_tax' => 0.0,
                'price_excl_tax' => 0.0,
            ];
        }
        if (empty($priceEntries)) {
            $defaultCurrency = Currency::getDefaultCurrency();
            $priceEntries[] = [
                'currency' => $defaultCurrency ? $defaultCurrency->iso_code : 'EUR',
                'current_price' => 0.0,
                'regular_price' => 0.0,
                'price_incl_tax' => 0.0,
                'price_excl_tax' => 0.0,
            ];
        }

        $payload = [
            'events' => [
                [
                    'type' => 'product.created',
                    'data' => [
                        'identification_number' => 'test-connection',
                        'sku' => 'TEST-001',
                        'channels' => [$channel],
                        'names' => [$channel => ['en' => 'Connection Test']],
                        'descriptions' => [$channel => ['en' => 'This is a connection test.']],
                        'links' => [$channel => ['en' => $shopUrl . '/test']],
                        'attributes' => [$channel => ['en' => new stdClass()]],
                        'categories' => [$channel => ['en' => ['Test > Connection']]],
                        'brands' => [$channel => 'Test'],
                        'prices' => [$channel => $priceEntries],
                        'availability_statuses' => [$channel => 'available'],
                        'stock_quantities' => [$channel => null],
                        'images' => [$channel => []],
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

        $jsonPayload = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
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
