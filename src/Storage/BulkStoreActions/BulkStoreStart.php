<?php
/**
 * Starts a bulk run when no fresh running run exists.
 *
 * @package SyncUsersSuitecrm\Storage\BulkStoreActions
 */

namespace SyncUsersSuitecrm\Storage\BulkStoreActions;

use SyncUsersSuitecrm\Model\BulkProgress;
use WP_Error;

defined('ABSPATH') || exit;

class BulkStoreStart implements BulkStoreAction {

    private string $runId;
    private int $connectionRev;
    private int $totalUsers;

    public function __construct(string $runId, int $connectionRev, int $totalUsers) {
        $this->runId         = $runId;
        $this->connectionRev = $connectionRev;
        $this->totalUsers    = $totalUsers;
    }

    /** @return BulkStoreActionResult|WP_Error */
    public function apply(?BulkProgress $current, bool $isStale) {
        if ($current !== null && $current->isRunning() && !$isStale) {
            return new WP_Error(
                'run_active',
                __('A manual bulk synchronization run is already actively in progress.', 'sk-user-sync-suitecrm'),
                ['http_status' => 409]
            );
        }

        $progress = new BulkProgress(
            $this->runId,
            $this->connectionRev,
            BulkProgress::STATUS_RUNNING,
            false,
            time(),
            time(),
            $this->totalUsers
        );

        return BulkStoreActionResult::save(
            $progress,
            __('Failed to persist bulk progress transient.', 'sk-user-sync-suitecrm')
        );
    }
}
