<?php
/**
 * Emporiqa Sync Service
 *
 * Orchestrates bulk synchronization of products and pages,
 * handling counting, batching, and session management.
 * One sync session per entity (not per-language).
 *
 * @author    Emporiqa
 * @copyright Emporiqa
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EmporiqaSyncService
{
    const DEFAULT_BATCH_SIZE = 25;

    /** @var EmporiqaWebhookClient */
    private $webhookClient;

    /** @var EmporiqaProductFormatter */
    private $productFormatter;

    /** @var EmporiqaPageFormatter */
    private $pageFormatter;

    /** @var int */
    private $batchSize;

    public function __construct(
        EmporiqaWebhookClient $webhookClient,
        EmporiqaProductFormatter $productFormatter,
        EmporiqaPageFormatter $pageFormatter
    ) {
        $this->webhookClient = $webhookClient;
        $this->productFormatter = $productFormatter;
        $this->pageFormatter = $pageFormatter;
        $this->batchSize = (int) Configuration::get('EMPORIQA_BATCH_SIZE') ?: self::DEFAULT_BATCH_SIZE;
    }

    /**
     * Initialize a sync operation — one session per entity.
     *
     * @param string $entity 'products', 'pages', or 'all'
     * @param bool $dryRun Skip sending to API, just count and format
     *
     * @return array
     */
    public function initSync($entity, $dryRun = false)
    {
        $productCount = 0;
        $pageCount = 0;

        if ($entity === 'products' || $entity === 'all') {
            $productCount = $this->countProducts();
        }

        if ($entity === 'pages' || $entity === 'all') {
            $pageCount = $this->countPages();
        }

        $sessions = [];
        $entities = [];

        if ($entity === 'products' || $entity === 'all') {
            $entities[] = 'products';
        }
        if ($entity === 'pages' || $entity === 'all') {
            $entities[] = 'pages';
        }

        foreach ($entities as $ent) {
            $sessionId = 'ps-' . $ent . '-' . $this->generateUuid();

            if (!$dryRun) {
                $result = $this->webhookClient->startSyncSession($sessionId, $ent, null);
                if (!$result) {
                    return [
                        'success' => false,
                        'error' => "Failed to start {$ent} sync session.",
                    ];
                }
            }

            $sessions[] = [
                'entity' => $ent,
                'language' => null,
                'session_id' => $sessionId,
            ];
        }

        return [
            'success' => true,
            'product_count' => $productCount,
            'page_count' => $pageCount,
            'sessions' => $sessions,
            'items_per_batch' => $this->batchSize,
        ];
    }

    /**
     * Process a single batch of items.
     *
     * @param string $entity 'products' or 'pages'
     * @param string $sessionId Sync session ID
     * @param int $page Page number (1-based)
     * @param bool $dryRun Skip sending to API
     *
     * @return array
     */
    public function processBatch($entity, $sessionId, $page, $dryRun = false)
    {
        if ($entity === 'products') {
            return $this->processProductBatch($sessionId, $page, $dryRun);
        }

        if ($entity === 'pages') {
            return $this->processPageBatch($sessionId, $page, $dryRun);
        }

        return ['success' => false, 'processed' => 0, 'events' => 0];
    }

    /**
     * Complete a sync session.
     *
     * @param string $entity 'products' or 'pages'
     * @param string $sessionId Sync session ID
     * @param bool $dryRun Skip sending to API
     *
     * @return array
     */
    public function completeSync($entity, $sessionId, $dryRun = false)
    {
        if ($dryRun) {
            return ['success' => true, 'message' => 'Dry-run session completed.'];
        }

        $result = $this->webhookClient->completeSyncSession($sessionId, $entity, null);

        return [
            'success' => $result,
            'message' => $result ? 'Session completed.' : 'Failed to complete sync session.',
        ];
    }

    public function countProducts()
    {
        $sql = new DbQuery();
        $sql->select('COUNT(*)');
        $sql->from('product', 'p');
        $sql->innerJoin('product_shop', 'ps', 'p.id_product = ps.id_product AND ps.id_shop = ' . (int) Context::getContext()->shop->id);
        $sql->where('ps.active = 1');

        return (int) Db::getInstance()->getValue($sql);
    }

    public function countPages()
    {
        $sql = new DbQuery();
        $sql->select('COUNT(*)');
        $sql->from('cms', 'c');
        $sql->innerJoin('cms_shop', 'cs', 'c.id_cms = cs.id_cms AND cs.id_shop = ' . (int) Context::getContext()->shop->id);
        $sql->where('c.active = 1');

        return (int) Db::getInstance()->getValue($sql);
    }

    private function processProductBatch($sessionId, $page, $dryRun = false)
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(300);
        }
        EmporiqaProductFormatter::clearBatchCaches();
        $offset = ($page - 1) * $this->batchSize;

        $sql = new DbQuery();
        $sql->select('p.id_product');
        $sql->from('product', 'p');
        $sql->innerJoin('product_shop', 'ps', 'p.id_product = ps.id_product AND ps.id_shop = ' . (int) Context::getContext()->shop->id);
        $sql->where('ps.active = 1');
        $sql->orderBy('p.id_product ASC');
        $sql->limit($this->batchSize, $offset);

        $productIds = Db::getInstance()->executeS($sql);
        if (!$productIds) {
            return ['success' => true, 'processed' => 0, 'events' => 0];
        }

        $events = [];
        $processed = count($productIds);

        foreach ($productIds as $row) {
            $product = new Product((int) $row['id_product']);
            if (!Validate::isLoadedObject($product)) {
                continue;
            }

            $formatted = $this->productFormatter->format($product, $sessionId);
            foreach ($formatted as &$item) {
                Hook::exec('actionEmporiqaFormatProduct', [
                    'data' => &$item,
                    'product' => $product,
                    'event_type' => 'product.updated',
                ]);
                $events[] = [
                    'type' => 'product.updated',
                    'data' => $item,
                ];
            }
            unset($item);
        }

        $success = true;
        if (!$dryRun && !empty($events)) {
            foreach (array_chunk($events, EmporiqaWebhookClient::FLUSH_BATCH_SIZE) as $chunk) {
                if (!$this->webhookClient->sendBatchEvents($chunk, 30)) {
                    $success = false;
                }
            }
        }

        return [
            'success' => $success,
            'processed' => $processed,
            'events' => count($events),
        ];
    }

    private function processPageBatch($sessionId, $page, $dryRun = false)
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(300);
        }
        $offset = ($page - 1) * $this->batchSize;

        $sql = new DbQuery();
        $sql->select('c.id_cms');
        $sql->from('cms', 'c');
        $sql->innerJoin('cms_shop', 'cs', 'c.id_cms = cs.id_cms AND cs.id_shop = ' . (int) Context::getContext()->shop->id);
        $sql->where('c.active = 1');
        $sql->orderBy('c.id_cms ASC');
        $sql->limit($this->batchSize, $offset);

        $cmsIds = Db::getInstance()->executeS($sql);
        if (!$cmsIds) {
            return ['success' => true, 'processed' => 0, 'events' => 0];
        }

        $events = [];
        $processed = count($cmsIds);

        foreach ($cmsIds as $row) {
            $cms = new CMS((int) $row['id_cms']);
            if (!Validate::isLoadedObject($cms)) {
                continue;
            }

            $formatted = $this->pageFormatter->format($cms, $sessionId);
            if (!empty($formatted)) {
                Hook::exec('actionEmporiqaFormatPage', [
                    'data' => &$formatted,
                    'page' => $cms,
                    'event_type' => 'page.updated',
                ]);
                $events[] = [
                    'type' => 'page.updated',
                    'data' => $formatted,
                ];
            }
        }

        $success = true;
        if (!$dryRun && !empty($events)) {
            foreach (array_chunk($events, EmporiqaWebhookClient::FLUSH_BATCH_SIZE) as $chunk) {
                if (!$this->webhookClient->sendBatchEvents($chunk, 30)) {
                    $success = false;
                }
            }
        }

        return [
            'success' => $success,
            'processed' => $processed,
            'events' => count($events),
        ];
    }

    private function generateUuid()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xFFFF), random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0x0FFF) | 0x4000,
            random_int(0, 0x3FFF) | 0x8000,
            random_int(0, 0xFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFFF)
        );
    }
}
