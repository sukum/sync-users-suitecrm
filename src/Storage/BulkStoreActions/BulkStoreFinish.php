<?php
/**
 * Applies a terminal transition to the matching bulk run.
 *
 * @package SyncSuiteCRMUsers\Storage\BulkStoreActions
 */

namespace SyncSuiteCRMUsers\Storage\BulkStoreActions;

use SyncSuiteCRMUsers\Model\BulkProgress;
use WP_Error;

defined('ABSPATH') || exit;

class BulkStoreFinish implements BulkStoreAction {

    private string $runId;
    private string $newStatus;

    public function __construct(string $runId, string $newStatus = BulkProgress::STATUS_COMPLETED) {
        $this->runId     = $runId;
        $this->newStatus = $newStatus;
    }

    /** @return BulkStoreActionResult|WP_Error */
    public function apply(?BulkProgress $current, bool $isStale) {
        if ($current === null || $current->getRunId() !== $this->runId) {
            return new WP_Error(
                'run_identity_mismatch',
                __('Bulk progress run identity mismatch or snapshot missing.', 'sync-suitecrm-users'),
                ['http_status' => 409]
            );
        }

        $current->transitionTo($this->newStatus);

        return BulkStoreActionResult::save(
            $current,
            __('Failed to persist terminal run status.', 'sync-suitecrm-users')
        );
    }
}
