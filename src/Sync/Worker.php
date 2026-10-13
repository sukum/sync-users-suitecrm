<?php
/**
 * Automatic queue worker.
 *
 * Coordinates bounded queue selection and chunk processing for nightly batch
 * and real-time executions, delegating bookkeeping and stale queue expiration.
 *
 * @package SyncSuiteCRMUsers\Sync
 */

namespace SyncSuiteCRMUsers\Sync;

use SyncSuiteCRMUsers\Model\QueueItem;
use SyncSuiteCRMUsers\Settings;
use SyncSuiteCRMUsers\Storage\QueueRepository;
use function SyncSuiteCRMUsers\write_log;

defined('ABSPATH') || exit;

class Worker {

    /**
     * Bounded chunk size for automatic queue selection.
     */
    public const BATCH_CHUNK_SIZE = 50;

    /** @var int Maximum user/module jobs selected per nightly batch chunk. */
    protected int $batchChunkSize;

    /**
     * Stale queue expiration horizon in days.
     */
    public const QUEUE_STALE_DAYS = QueueExpiryProcessor::STALE_DAYS;

    /**
     * Settings service.
     *
     * @var Settings
     */
    protected Settings $settings;

    /**
     * Queue repository.
     *
     * @var QueueRepository
     */
    protected QueueRepository $queueRepository;

    /**
     * Expired queue processing.
     *
     * @var QueueExpiryProcessor
     */
    protected QueueExpiryProcessor $expiryProcessor;

    /**
     * Shared chunk processor.
     *
     * @var ChunkProcessor
     */
    protected ChunkProcessor $chunkProcessor;

    /**
     * Scheduler service.
     *
     * @var Scheduler
     */
    protected Scheduler $scheduler;

    /**
     * Eligibility service.
     *
     * @var Eligibility
     */
    protected Eligibility $eligibility;

    /**
     * Constructor.
     *
     * @param Settings|null             $settings
     * @param QueueRepository|null      $queueRepository
     * @param QueueExpiryProcessor|null $expiryProcessor
     * @param ChunkProcessor|null       $chunkProcessor
     * @param Scheduler|null            $scheduler
     * @param Eligibility|null          $eligibility
     * @param int                       $batchChunkSize Nightly batch limit; defaults to 50.
     */
    public function __construct(
        ?Settings $settings = null,
        ?QueueRepository $queueRepository = null,
        ?QueueExpiryProcessor $expiryProcessor = null,
        ?ChunkProcessor $chunkProcessor = null,
        ?Scheduler $scheduler = null,
        ?Eligibility $eligibility = null,
        int $batchChunkSize = self::BATCH_CHUNK_SIZE
    ) {
        $this->settings        = $settings ?? new Settings();
        $this->queueRepository = $queueRepository ?? new QueueRepository();
        $this->expiryProcessor = $expiryProcessor ?? new QueueExpiryProcessor($this->queueRepository);
        $this->chunkProcessor  = $chunkProcessor ?? new ChunkProcessor();
        $this->scheduler       = $scheduler ?? new Scheduler($this->settings);
        $this->eligibility     = $eligibility ?? new Eligibility($this->settings);
        $this->batchChunkSize  = max(1, $batchChunkSize);
    }

    /**
     * Register worker action and cron hooks.
     *
     * @return void
     */
    public function registerHooks(): void {
        add_action('ssu_execute_batch_sync', [$this, 'handleBatch'], 10, 1);
        add_action('ssu_execute_realtime_worker', [$this, 'handleRealtime'], 10, 1);
        add_action('wp_ajax_ssu_process_queue_worker', [$this, 'handleAjaxRealtimeWorker']);
        add_action('wp_ajax_nopriv_ssu_process_queue_worker', [$this, 'handleAjaxRealtimeWorker']);
    }

    /**
     * AJAX handler for real-time HTTP loopback request.
     *
     * @return void
     */
    public function handleAjaxRealtimeWorker(): void {
        write_log('Processing dispatched realtime loopback.');
        if (!$this->scheduler->consumeRealtimeNonce(wp_unslash($_POST[Scheduler::WORKER_NONCE_FIELD] ?? null))) {
            wp_die('-1', '', ['response' => 403]);
            return;
        }

        $this->handleRealtime();
        if (wp_doing_ajax()) {
            wp_die();
        }
    }

    /**
     * Process nightly batch sync pass.
     *
     * - Runs stale cleanup at start.
     * - Captures maximum queue row ID on initial wake-up.
     * - Selects bounded chunks in ascending queue ID order.
     * - Removes queue row on success, skip, or failure.
     * - Advances cursor and schedules continuation for unvisited rows up to maxQueueId.
     *
     * @param array<string, mixed> $context Optional continuation context.
     * @return void
     */
    public function handleBatch($context = []): void {
        SyncGuard::prevent_loop(function () use ($context) {
            // 1. Process stale expired items first
            $this->processStaleExpiry();

            // 2. Validate current settings
            if (!$this->eligibility->isAutoSyncEnabled() || $this->eligibility->getAutoSyncMode() !== 'batch') {
                return;
            }

            $currentConnRev = $this->settings->getConnectionRevision();

            // 3. Resolve bounds and cursor
            $isContinuation = is_array($context) && isset($context['max_queue_id']);
            if ($isContinuation) {
                $maxQueueId    = (int) $context['max_queue_id'];
                $lastQueueId   = (int) ($context['last_queue_id'] ?? 0);
                $capturedRev   = (int) ($context['connection_rev'] ?? 0);
                $contextMode   = (string) ($context['auto_sync_mode'] ?? '');

                // Obsolete continuation check
                if ($contextMode !== 'batch' || $capturedRev !== $currentConnRev || $maxQueueId <= 0) {
                    return;
                }
            } else {
                $maxQueueId  = (int) $this->queueRepository->getMaxQueueId();
                $lastQueueId = 0;
                $capturedRev = $currentConnRev;

                if ($maxQueueId <= 0) {
                    return; // Queue is empty
                }
            }

            // 4. Select bounded chunk
            $items = $this->queueRepository->selectBatchChunk($lastQueueId, $maxQueueId, $this->batchChunkSize);
            if (is_wp_error($items) || empty($items)) {
                return;
            }

            // 5. Index selected rows by user and module
            [$userIds, $queueRowsMap] = $this->indexQueueItems($items);

            // 6. Build batch chunk handler
            $handler = new BatchChunkHandler(
                $capturedRev,
                $queueRowsMap,
                $items,
                $lastQueueId,
                $maxQueueId,
                $this->settings,
                $this->queueRepository,
                $this->scheduler
            );

            $this->chunkProcessor->process($userIds, $handler);
        });
    }

    /**
     * Process real-time queue work.
     *
     * - Selects a fresh bounded chunk of oldest outstanding queue items.
     * - Removes handled items upon outcome (success, skip, or failure).
     * - Does NOT schedule retries after failures.
     * - If more unvisited work remains, triggers another loopback.
     *
     * @param array<string, mixed> $context
     * @return void
     */
    public function handleRealtime($context = []): void {
        write_log('Handling real-time queue work.');
        SyncGuard::prevent_loop(function () {
            // 1. Process stale expired items
            $this->processStaleExpiry();

            // 2. Validate current settings
            if (!$this->eligibility->isAutoSyncEnabled() || $this->eligibility->getAutoSyncMode() !== 'realtime') {
                return;
            }

            $currentConnRev = $this->settings->getConnectionRevision();

            // 3. Select bounded chunk of oldest items
            $items = $this->queueRepository->selectRealtimeChunk(self::BATCH_CHUNK_SIZE);
            if (is_wp_error($items) || empty($items)) {
                return;
            }

            // 4. Index selected rows
            [$userIds, $queueRowsMap] = $this->indexQueueItems($items);

            // 5. Build real-time chunk handler
            $itemCount = count($items);
            $handler = new RealtimeChunkHandler(
                $currentConnRev,
                $queueRowsMap,
                $itemCount,
                $this->settings,
                $this->queueRepository,
                $this->scheduler,
                self::BATCH_CHUNK_SIZE
            );

            $this->chunkProcessor->process($userIds, $handler);
        });
    }

    /**
     * Clean up stale expired queue items.
     *
     * Stale expiration horizon: QUEUE_STALE_DAYS (4 days).
     * Logs lifecycle failure without making CRM calls, then removes expired rows.
     *
     * @return int Number of expired items processed.
     */
    public function processStaleExpiry(): int {
        return $this->expiryProcessor->process();
    }

    /**
     * Index selected queue rows while preserving the first-seen user order.
     *
     * @param list<QueueItem> $items
     * @return array{0: list<int>, 1: array<int, array<string, QueueItem>>}
     */
    private function indexQueueItems(array $items): array {
        $queueRowsMap = [];
        $userIds      = [];
        foreach ($items as $item) {
            $userId = $item->getUserId();
            $queueRowsMap[$userId][$item->getModule()] = $item;
            $userIds[] = $userId;
        }

        return [array_values(array_unique($userIds)), $queueRowsMap];
    }
}
