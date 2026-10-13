<?php
/**
 * Deletes a matching running bulk run, preserving the existing clear policy.
 *
 * @package SyncUsersSuitecrm\Storage\BulkStoreActions
 */

namespace SyncUsersSuitecrm\Storage\BulkStoreActions;

use SyncUsersSuitecrm\Model\BulkProgress;
use WP_Error;

defined('ABSPATH') || exit;

class BulkStoreClear implements BulkStoreAction {

    private string $runId;

    public function __construct(string $runId) {
        $this->runId = $runId;
    }

    /** @return BulkStoreActionResult|WP_Error */
    public function apply(?BulkProgress $current, bool $isStale) {
        if ($current === null || $current->getRunId() !== $this->runId || !$current->isRunning()) {
            return new WP_Error(
                'run_not_active',
                __('The specified bulk run is not active or has already completed.', 'sync-users-suitecrm'),
                ['http_status' => 409]
            );
        }

        return BulkStoreActionResult::delete(
            __('Failed to delete bulk progress transient.', 'sync-users-suitecrm')
        );
    }
}
