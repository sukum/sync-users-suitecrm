<?php
/**
 * Bulk synchronization progress store.
 *
 * Manages serialization, deserialization, and atomic coordinated mutations
 * of the `syus_bulk_progress` transient using the shared `bulk_progress` lock.
 *
 * @package SyncUsersSuitecrm
 */

namespace SyncUsersSuitecrm\Storage;

use SyncUsersSuitecrm\Model\BulkProgress;
use SyncUsersSuitecrm\Storage\BulkStoreActions\BulkStoreAction;
use SyncUsersSuitecrm\Storage\BulkStoreActions\BulkStoreActionResult;
use SyncUsersSuitecrm\Storage\BulkStoreActions\BulkStoreStart;
use SyncUsersSuitecrm\Storage\BulkStoreActions\BulkStoreRecordProgress;
use SyncUsersSuitecrm\Storage\BulkStoreActions\BulkStoreRequestCancellation;
use SyncUsersSuitecrm\Storage\BulkStoreActions\BulkStoreFinish;
use SyncUsersSuitecrm\Storage\BulkStoreActions\BulkStoreClear;
use SyncUsersSuitecrm\Sync\LockManager;
use WP_Error;

defined('ABSPATH') || exit;

class BulkProgressStore {

    /**
     * WordPress transient key.
     */
    public const TRANSIENT_KEY = 'syus_bulk_progress';

    /**
     * Retention duration: 2 hours (7200 seconds) so reviewable on Settings.
     */
    public const RETENTION_SECONDS = 7200;

    /**
     * Freshness threshold: 60 seconds without worker update marks progress as stalled/interrupted.
     */
    public const FRESHNESS_THRESHOLD = 60;

    /**
     * Mutex lock resource key.
     */
    public const LOCK_RESOURCE_KEY = 'bulk_progress';

    /**
     * Lock duration for short mutation window (30 seconds).
     */
    public const LOCK_TTL = 30;

    /**
     * Lock manager service.
     *
     * @var LockManager
     */
    private LockManager $lockManager;

    /**
     * Constructor.
     *
     * @param LockManager|null $lockManager
     */
    public function __construct(?LockManager $lockManager = null) {
        $this->lockManager = $lockManager ?? new LockManager();
    }

    /**
     * Read and deserialize current progress transient.
     *
     * A missing/expired transient returns null (no snapshot), never a default completed run.
     * Corrupted records return WP_Error.
     *
     * @return BulkProgress|WP_Error|null
     */
    public function get() {
        $data = get_transient(self::TRANSIENT_KEY);

        if ($data === false || $data === null) {
            return null;
        }

        if (!is_array($data)) {
            return new WP_Error(
                'invalid_bulk_progress',
                __('Stored bulk progress snapshot is malformed.', 'sync-users-suitecrm')
            );
        }

        $progress = BulkProgress::fromArray($data);
        if ($progress === null) {
            return new WP_Error(
                'invalid_bulk_progress',
                __('Stored bulk progress failed schema validation.', 'sync-users-suitecrm')
            );
        }

        return $progress;
    }

    /**
     * Check whether a given progress snapshot (or current storage) is stale.
     *
     * Stale is true only for a present running snapshot whose worker timestamp exceeds the threshold.
     * Missing state gives false.
     *
     * @param BulkProgress|null $progress
     * @return bool
     */
    public function isStale(?BulkProgress $progress = null): bool {
        if ($progress === null) {
            $snapshot = $this->get();
            if (is_wp_error($snapshot) || $snapshot === null) {
                return false;
            }
            $progress = $snapshot;
        }

        if ($progress->getStatus() !== BulkProgress::STATUS_RUNNING) {
            return false;
        }

        return (time() - $progress->getLastUpdateTime()) > self::FRESHNESS_THRESHOLD;
    }

    /**
     * Start a new bulk synchronization run or replace an inactive/stale one.
     *
     * Acquires the shared bulk_progress lock before reading and writing.
     * Returns 409 run_active if another fresh running run is currently active.
     *
     * @param string $runId         Unique run identifier.
     * @param int    $connectionRev Connection revision.
     * @param int    $totalUsers    Approximate count of eligible WordPress users.
     * @return BulkProgress|WP_Error
     */
    public function startRun(string $runId, int $connectionRev, int $totalUsers) {
        return $this->executeAction(new BulkStoreStart($runId, $connectionRev, $totalUsers));
    }

    /**
     * Request cooperative cancellation of an active bulk run.
     *
     * Sets only cancel_requested = true while preserving remaining fields and worker last_update_time.
     *
     * @param string $runId Expected run identifier.
     * @return BulkProgress|WP_Error
     */
    public function requestCancellation(string $runId) {
        return $this->executeAction(new BulkStoreRequestCancellation($runId));
    }

    /**
     * Clear a matching running bulk run (fresh or stale).
     *
     * @param string $runId Expected run identifier.
     * @return bool|WP_Error
     */
    public function clear(string $runId) {
        return $this->executeAction(new BulkStoreClear($runId));
    }

    /**
     * Record outcome progress and user completion increments.
     *
     * Under the shared lock, rereads the transient, validates run identity,
     * preserves cancel_requested = true, increments counters, and updates last_update_time.
     *
     * @param string $runId               Expected run identifier.
     * @param int    $successDelta        Incremental module successes.
     * @param int    $failureDelta        Incremental module failures.
     * @param int    $skippedDelta        Incremental module skips.
     * @param int    $processedUsersDelta Incremental processed users.
     * @return BulkProgress|WP_Error
     */
    public function recordProgress(
        string $runId,
        int $successDelta = 0,
        int $failureDelta = 0,
        int $skippedDelta = 0,
        int $processedUsersDelta = 0
    ) {
        return $this->executeAction(new BulkStoreRecordProgress(
            $runId,
            $successDelta,
            $failureDelta,
            $skippedDelta,
            $processedUsersDelta
        ));
    }

    /**
     * Transition run to terminal status (completed, cancelled, interrupted).
     *
     * @param string $runId     Expected run identifier.
     * @param string $newStatus 'completed'|'cancelled'|'interrupted'.
     * @return BulkProgress|WP_Error
     */
    public function finishRun(string $runId, string $newStatus = BulkProgress::STATUS_COMPLETED) {
        return $this->executeAction(new BulkStoreFinish($runId, $newStatus));
    }

    /**
     * Delete the bulk progress transient entirely.
     *
     * @return bool
     */
    public function delete(): bool {
        return delete_transient(self::TRANSIENT_KEY);
    }

    /**
     * Execute one action against fresh progress within the shared lock.
     *
     * @return BulkProgress|bool|WP_Error Saved progress, true after deletion, or an error.
     */
    private function executeAction(BulkStoreAction $action) {
        $lock = $this->lockManager->acquire(self::LOCK_RESOURCE_KEY, self::LOCK_TTL);
        if ($lock === null) {
            return new WP_Error(
                'progress_busy',
                __('Bulk progress is currently being updated by another operation.', 'sync-users-suitecrm'),
                ['http_status' => 409]
            );
        }
        if (is_wp_error($lock)) {
            return $lock;
        }

        try {
            $current = $this->get();
            if (is_wp_error($current)) {
                return $current;
            }

            // Snapshot before the action mutates the current progress object.
            $currentData = $current !== null ? $current->toArray() : null;

            // Avoid isStale(null), which would reread the transient.
            $isStale = $current !== null && $this->isStale($current);
            $result  = $action->apply($current, $isStale);
            if (is_wp_error($result)) {
                return $result;
            }

            if ($result->getOperation() === BulkStoreActionResult::DELETE) {
                if (!$this->delete()) {
                    return new WP_Error(
                        'progress_delete_failed',
                        $result->getFailureMessage(),
                        ['http_status' => 500]
                    );
                }
                return true;
            }

            $progress = $result->getProgress();
            $data = $progress->toArray();
            if ($data === $currentData) {
                return $progress;
            }

            if (!set_transient(self::TRANSIENT_KEY, $data, self::RETENTION_SECONDS)) {
                return new WP_Error(
                    'progress_save_failed',
                    $result->getFailureMessage(),
                    ['http_status' => 500]
                );
            }

            return $progress;
        } finally {
            $this->lockManager->release($lock);
        }
    }
}
