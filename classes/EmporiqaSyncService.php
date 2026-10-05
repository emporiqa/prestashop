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
    public const DEFAULT_BATCH_SIZE = 25;

    /** Prefix for per-session Configuration rows holding batch stats. */
    public const SESSION_STATS_PREFIX = 'EMPORIQA_SSN_';

    /** Prune session stats older than this (seconds). */
    public const SESSION_STATS_TTL = 86400;

    /** @var EmporiqaWebhookClient */
    private $webhookClient;

    /** @var EmporiqaProductFormatter */
    private $productFormatter;

    /** @var EmporiqaPageFormatter */
    private $pageFormatter;

    /** @var int */
    private $batchSize;

    /** @var EmporiqaChannelResolver */
    private $channelResolver;

    /** @var Context|null */
    private $context;

    public function __construct(
        EmporiqaWebhookClient $webhookClient,
        EmporiqaProductFormatter $productFormatter,
        EmporiqaPageFormatter $pageFormatter,
        EmporiqaChannelResolver $channelResolver,
        ?Context $context = null,
    ) {
        $this->webhookClient = $webhookClient;
        $this->productFormatter = $productFormatter;
        $this->pageFormatter = $pageFormatter;
        $this->channelResolver = $channelResolver;
        $this->context = $context;
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
        try {
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
                    $result = $this->webhookClient->startSyncSession($sessionId, $ent);
                    if (!$result) {
                        $reason = $this->webhookClient->getLastError();
                        $msg = sprintf($this->t('The sync of %s could not start.'), $this->entityLabel($ent));
                        if ($reason) {
                            $msg .= ' ' . $reason;
                        }

                        return [
                            'success' => false,
                            'error' => $msg,
                        ];
                    }
                    $this->registerSession($sessionId);
                }

                $sessions[] = [
                    'entity' => $ent,
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
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => sprintf($this->t('The sync could not start: %s'), $e->getMessage()),
            ];
        }
    }

    /**
     * Process a single batch of items.
     *
     * @param string $entity 'products' or 'pages'
     * @param string $sessionId Sync session ID
     * @param int $afterId keyset cursor: the batch after this id (0 for the
     *                     first). An OFFSET page shifts when an item is
     *                     disabled mid-sync, skipping one live item that
     *                     sync.complete then deletes
     * @param bool $dryRun Skip sending to API
     *
     * @return array
     */
    public function processBatch($entity, $sessionId, $afterId, $dryRun = false)
    {
        if ($entity !== 'products' && $entity !== 'pages') {
            return ['success' => false, 'processed' => 0, 'events' => 0];
        }

        // Presume failure before running the batch so a fatal error or
        // timeout mid-batch still poisons the session; the success path
        // reverses the mark below.
        if (!$dryRun) {
            $this->bumpSessionStats($sessionId, 1, 0);
        }

        $result = $entity === 'products'
            ? $this->processProductBatch($sessionId, (int) $afterId, $dryRun)
            : $this->processPageBatch($sessionId, (int) $afterId, $dryRun);

        if (!$dryRun && !empty($result['success'])) {
            $this->bumpSessionStats($sessionId, -1, (int) $result['processed']);
        }

        return $result;
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
            return ['success' => true, 'message' => $this->t('Dry run complete.')];
        }

        // sync.complete tells the Emporiqa backend to delete every item it
        // did not see during this session, so completing after a failed
        // batch would wipe that batch's items from the remote catalog.
        // Refuse unless every batch succeeded and at least one item synced.
        $stats = $this->getSessionStats($sessionId);
        if ($stats === null) {
            $msg = $this->t('This sync session is unknown or has expired. Run the sync again.');

            return ['success' => false, 'message' => $msg, 'error' => $msg];
        }

        if ($stats['errors'] > 0) {
            self::recordLastSync($entity, false);
            $msg = sprintf(
                $this->t('The sync of %s was not completed: %d batch(es) failed. Fix the errors and run the sync again. Completing it now would delete the failed items from Emporiqa.'),
                $this->entityLabel($entity),
                (int) $stats['errors'],
            );

            return ['success' => false, 'skipped' => true, 'message' => $msg, 'error' => $msg];
        }

        if ($stats['synced'] < 1) {
            $msg = sprintf($this->t('The sync of %s was not completed: no items were synced.'), $this->entityLabel($entity));

            return ['success' => false, 'skipped' => true, 'message' => $msg, 'error' => $msg];
        }

        $result = $this->webhookClient->completeSyncSession($sessionId, $entity);

        if ($result) {
            $this->forgetSession($sessionId);
            self::recordLastSync($entity, true);

            return ['success' => true, 'message' => $this->t('Sync session completed.')];
        }
        self::recordLastSync($entity, false);

        $msg = $this->t('The sync session could not be completed.');
        $reason = $this->webhookClient->getLastError();
        if ($reason) {
            $msg .= ' ' . $reason;
        }

        return ['success' => false, 'message' => $msg, 'error' => $msg];
    }

    /**
     * When the last full sync of products or pages finished, and whether it
     * completed, for the Sync tab's health lines (Emporiqa::getSyncHealth).
     *
     * @param string $entity "products" or "pages"
     * @param bool $ok
     */
    private static function recordLastSync($entity, $ok)
    {
        if (!in_array($entity, ['products', 'pages'], true)) {
            return;
        }
        Configuration::updateGlobalValue(
            'EMPORIQA_LAST_SYNC_' . strtoupper($entity),
            json_encode(['at' => time(), 'ok' => (bool) $ok]),
        );
    }

    // -------------------------------------------------------------------------
    // Per-session batch stats — server-side guard state for completeSync().
    // Persisted in Configuration because each sync step is a separate
    // AJAX request; the driving JavaScript cannot be trusted alone.
    // One Configuration row per session (rather than one shared JSON map)
    // so two syncs running concurrently can't lose each other's error
    // marks in a read-modify-write race.
    // -------------------------------------------------------------------------

    /**
     * Register a freshly started session with zeroed counters.
     *
     * @param string $sessionId
     */
    protected function registerSession($sessionId)
    {
        $this->pruneSessionStats();
        $this->writeSessionStats($sessionId, ['errors' => 0, 'synced' => 0]);
    }

    /**
     * Adjust a session's error/synced counters. Unknown sessions are ignored.
     *
     * @param string $sessionId
     * @param int $errorDelta
     * @param int $syncedDelta
     */
    protected function bumpSessionStats($sessionId, $errorDelta, $syncedDelta)
    {
        $stats = $this->getSessionStats($sessionId);
        if ($stats === null) {
            return;
        }

        $stats['errors'] = max(0, $stats['errors'] + $errorDelta);
        $stats['synced'] += $syncedDelta;
        $this->writeSessionStats($sessionId, $stats);
    }

    /**
     * @param string $sessionId
     *
     * @return array|null ['errors' => int, 'synced' => int] or null if unknown
     */
    protected function getSessionStats($sessionId)
    {
        $raw = Configuration::getGlobalValue($this->sessionStatsKey($sessionId));
        if (!$raw) {
            return null;
        }

        $stats = json_decode($raw, true);
        if (!is_array($stats)) {
            return null;
        }

        return [
            'errors' => isset($stats['errors']) ? (int) $stats['errors'] : 0,
            'synced' => isset($stats['synced']) ? (int) $stats['synced'] : 0,
        ];
    }

    /**
     * @param string $sessionId
     */
    protected function forgetSession($sessionId)
    {
        Configuration::deleteByName($this->sessionStatsKey($sessionId));
    }

    /**
     * @param string $sessionId
     */
    private function writeSessionStats($sessionId, array $stats)
    {
        Configuration::updateGlobalValue($this->sessionStatsKey($sessionId), json_encode($stats));
    }

    /**
     * md5 keeps the name within the charset and length limits for
     * configuration names (session ids contain hyphens, which some PS
     * versions reject in config names).
     *
     * @param string $sessionId
     *
     * @return string
     */
    private function sessionStatsKey($sessionId)
    {
        return self::SESSION_STATS_PREFIX . strtoupper(md5($sessionId));
    }

    /**
     * Delete stats rows of sessions older than SESSION_STATS_TTL —
     * abandoned or cancelled syncs would otherwise accumulate rows forever.
     */
    private function pruneSessionStats()
    {
        $cutoff = date('Y-m-d H:i:s', time() - self::SESSION_STATS_TTL);
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'configuration` '
            . "WHERE `name` LIKE '" . pSQL(self::SESSION_STATS_PREFIX) . "%' "
            . "AND `date_upd` < '" . pSQL($cutoff) . "'",
        );
    }

    /**
     * SQL condition limiting a shop-association column to the shops the
     * merchant enabled.
     *
     * @param string $column e.g. 'ps.id_shop'
     *
     * @return string
     */
    private function enabledShopsCondition($column)
    {
        $shopIds = array_map('intval', array_keys($this->channelResolver->getMapping()));
        if (empty($shopIds)) {
            // A selection that matches no shop syncs nothing.
            return '0 = 1';
        }

        return $column . ' IN (' . implode(',', $shopIds) . ')';
    }

    /**
     * Count distinct active products across the enabled shops.
     */
    public function countProducts()
    {
        $sql = new DbQuery();
        $sql->select('COUNT(DISTINCT p.id_product)');
        $sql->from('product', 'p');
        $sql->innerJoin('product_shop', 'ps', 'p.id_product = ps.id_product');
        $sql->where('ps.active = 1');
        $sql->where($this->enabledShopsCondition('ps.id_shop'));

        return (int) Db::getInstance()->getValue($sql);
    }

    /**
     * Count distinct active CMS pages across the enabled shops.
     */
    public function countPages()
    {
        $sql = new DbQuery();
        $sql->select('COUNT(DISTINCT c.id_cms)');
        $sql->from('cms', 'c');
        $sql->innerJoin('cms_shop', 'cs', 'c.id_cms = cs.id_cms');
        $sql->where('c.active = 1');
        $sql->where($this->enabledShopsCondition('cs.id_shop'));

        return (int) Db::getInstance()->getValue($sql);
    }

    private function processProductBatch($sessionId, $afterId, $dryRun)
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(300);
        }
        EmporiqaProductFormatter::clearBatchCaches();

        $sql = new DbQuery();
        $sql->select('DISTINCT p.id_product');
        $sql->from('product', 'p');
        $sql->innerJoin('product_shop', 'ps', 'p.id_product = ps.id_product');
        $sql->where('ps.active = 1');
        $sql->where($this->enabledShopsCondition('ps.id_shop'));
        $sql->where('p.id_product > ' . (int) $afterId);
        $sql->orderBy('p.id_product ASC');
        $sql->limit($this->batchSize);

        $productIds = Db::getInstance()->executeS($sql);
        if (!$productIds) {
            return ['success' => true, 'processed' => 0, 'events' => 0];
        }

        $events = [];
        $processed = count($productIds);
        $lastId = (int) end($productIds)['id_product'];

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
        $error = null;
        if (!$dryRun && !empty($events)) {
            foreach (array_chunk($events, EmporiqaWebhookClient::FLUSH_BATCH_SIZE) as $chunk) {
                if (!$this->webhookClient->sendBatchEvents($chunk, 30)) {
                    $success = false;
                    $error = $this->webhookClient->getLastError();
                    break;
                }
            }
        }

        $out = [
            'success' => $success,
            'processed' => $processed,
            'events' => count($events),
            'last_id' => $lastId,
        ];
        if (!$success && $error) {
            $out['error'] = $error;
        }

        return $out;
    }

    private function processPageBatch($sessionId, $afterId, $dryRun)
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(300);
        }

        $sql = new DbQuery();
        $sql->select('DISTINCT c.id_cms');
        $sql->from('cms', 'c');
        $sql->innerJoin('cms_shop', 'cs', 'c.id_cms = cs.id_cms');
        $sql->where('c.active = 1');
        $sql->where($this->enabledShopsCondition('cs.id_shop'));
        $sql->where('c.id_cms > ' . (int) $afterId);
        $sql->orderBy('c.id_cms ASC');
        $sql->limit($this->batchSize);

        $cmsIds = Db::getInstance()->executeS($sql);
        if (!$cmsIds) {
            return ['success' => true, 'processed' => 0, 'events' => 0];
        }

        $events = [];
        $processed = count($cmsIds);
        $lastId = (int) end($cmsIds)['id_cms'];

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
        $error = null;
        if (!$dryRun && !empty($events)) {
            foreach (array_chunk($events, EmporiqaWebhookClient::FLUSH_BATCH_SIZE) as $chunk) {
                if (!$this->webhookClient->sendBatchEvents($chunk, 30)) {
                    $success = false;
                    $error = $this->webhookClient->getLastError();
                    break;
                }
            }
        }

        $out = [
            'success' => $success,
            'processed' => $processed,
            'events' => count($events),
            'last_id' => $lastId,
        ];
        if (!$success && $error) {
            $out['error'] = $error;
        }

        return $out;
    }

    private function generateUuid()
    {
        try {
            return sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                random_int(0, 0xFFFF), random_int(0, 0xFFFF),
                random_int(0, 0xFFFF),
                random_int(0, 0x0FFF) | 0x4000,
                random_int(0, 0x3FFF) | 0x8000,
                random_int(0, 0xFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFFF),
            );
        } catch (Exception $e) {
            // random_int can throw on a broken /dev/urandom; fall back to
            // a non-cryptographic id (sync sessions are server-internal,
            // collisions are recoverable).
            return uniqid('emporiqa-sync-', true);
        }
    }

    /**
     * @param string $entity 'products' or 'pages'
     *
     * @return string the entity name in the employee's language
     */
    private function entityLabel($entity)
    {
        return $entity === 'pages' ? $this->t('pages') : $this->t('products');
    }

    /**
     * Translated back-office text; these messages reach the sync log.
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

        return Translate::getModuleTranslation('emporiqa', $string, 'EmporiqaSyncService', null, false, null, true, false);
    }
}
