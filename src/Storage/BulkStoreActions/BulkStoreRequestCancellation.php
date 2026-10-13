<?php
/**
 * Requests cooperative cancellation without changing the worker timestamp.
 *
 * @package SyncSuiteCRMUsers\Storage\BulkStoreActions
 */

namespace SyncSuiteCRMUsers\Storage\BulkStoreActions;

use SyncSuiteCRMUsers\Model\BulkProgress;
use WP_Error;

defined('ABSPATH') || exit;

class BulkStoreRequestCancellation implements BulkStoreAction {

    private string $runId;

    public function __construct(string $runId) {
        $this->runId = $runId;
    }

    /** @return BulkStoreActionResult|WP_Error */
    public function apply(?BulkProgress $current, bool $isStale) {
        if ($current === null || $current->getRunId() !== $this->runId || !$current->isRunning()) {
            return new WP_Error(
                'run_not_active',
                __('The specified bulk run is not active or has already completed.', 'sync-suitecrm-users'),
                ['http_status' => 409]
            );
        }

        $current->requestCancellation();

        return BulkStoreActionResult::save(
            $current,
            __('Failed to save cancellation request to bulk progress.', 'sync-suitecrm-users')
        );
    }
}
