<?php
/**
 * Records outcome and user counts for the matching bulk run.
 *
 * @package SyncUsersSuitecrm\Storage\BulkStoreActions
 */

namespace SyncUsersSuitecrm\Storage\BulkStoreActions;

use SyncUsersSuitecrm\Model\BulkProgress;
use WP_Error;

defined('ABSPATH') || exit;

class BulkStoreRecordProgress implements BulkStoreAction {

    private string $runId;
    private int $successDelta;
    private int $failureDelta;
    private int $skippedDelta;
    private int $processedUsersDelta;

    public function __construct(
        string $runId,
        int $successDelta = 0,
        int $failureDelta = 0,
        int $skippedDelta = 0,
        int $processedUsersDelta = 0
    ) {
        $this->runId               = $runId;
        $this->successDelta        = $successDelta;
        $this->failureDelta        = $failureDelta;
        $this->skippedDelta        = $skippedDelta;
        $this->processedUsersDelta = $processedUsersDelta;
    }

    /** @return BulkStoreActionResult|WP_Error */
    public function apply(?BulkProgress $current, bool $isStale) {
        if ($current === null || $current->getRunId() !== $this->runId) {
            return new WP_Error(
                'run_identity_mismatch',
                __('Bulk progress run identity mismatch or snapshot missing.', 'sk-user-sync-suitecrm'),
                ['http_status' => 409]
            );
        }

        $current->recordProgress(
            $this->successDelta,
            $this->failureDelta,
            $this->skippedDelta,
            $this->processedUsersDelta
        );

        return BulkStoreActionResult::save(
            $current,
            __('Failed to persist progress update.', 'sk-user-sync-suitecrm')
        );
    }
}
