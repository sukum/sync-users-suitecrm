<?php
/**
 * Manual bulk chunk lifecycle and progress bookkeeping.
 *
 * Checks live cancellation and connection validity, records outcomes and user
 * completion, and finalizes the run or schedules its next chunk.
 *
 * @package SyncUsersSuitecrm\Sync
 */

namespace SyncUsersSuitecrm\Sync;

use SyncUsersSuitecrm\Model\BulkProgress;
use SyncUsersSuitecrm\Model\SyncOutcome;
use SyncUsersSuitecrm\Settings;
use SyncUsersSuitecrm\Storage\BulkProgressStore;

defined('ABSPATH') || exit;

class BulkChunkHandler implements ChunkHandler {

    private string $runId;
    private int $connRev;
    private array $enabledModules;
    private array $eligibleRoles;
    private BulkProgressStore $store;
    private Settings $settings;
    private Scheduler $scheduler;

    /** @var \Closure(int, list<string>): bool */
    private \Closure $hasMoreUsers;

    /**
     * @param list<string> $enabledModules
     * @param list<string> $eligibleRoles
     * @param callable(int, list<string>): bool $hasMoreUsers Check for users beyond the cursor.
     */
    public function __construct(
        string $runId,
        int $connRev,
        array $enabledModules,
        array $eligibleRoles,
        BulkProgressStore $store,
        Settings $settings,
        Scheduler $scheduler,
        callable $hasMoreUsers
    ) {
        $this->runId          = $runId;
        $this->connRev        = $connRev;
        $this->enabledModules = $enabledModules;
        $this->eligibleRoles  = $eligibleRoles;
        $this->store          = $store;
        $this->settings       = $settings;
        $this->scheduler      = $scheduler;
        $this->hasMoreUsers   = \Closure::fromCallable($hasMoreUsers);
    }

    public function connectionRevision(): int {
        return $this->connRev;
    }

    public function modulesForUser(int $userId): array {
        return $this->enabledModules;
    }

    public function shouldContinue(): bool {
        // Reread progress transient directly to check live cancellation
        $current = $this->store->get();
        if (is_wp_error($current) || $current === null) {
            return false;
        }

        if ($current->getRunId() !== $this->runId || $current->getStatus() !== BulkProgress::STATUS_RUNNING) {
            return false;
        }

        if ($current->isCancelRequested()) {
            return false;
        }

        if ($this->settings->getConnectionRevision() !== $this->connRev) {
            return false;
        }

        return true;
    }

    public function onOutcome(SyncOutcome $outcome): void {
        $successInc = 0;
        $failureInc = 0;
        $skippedInc = 0;

        switch ($outcome->getStatus()) {
            case SyncOutcome::STATUS_SUCCESS:
                $successInc = 1;
                break;
            case SyncOutcome::STATUS_FAILURE:
                $failureInc = 1;
                break;
            case SyncOutcome::STATUS_SKIPPED:
                $skippedInc = 1;
                break;
        }

        $this->store->recordProgress(
            $this->runId,
            $successInc,
            $failureInc,
            $skippedInc,
            0
        );
    }

    public function onUserComplete(int $userId): void {
        $this->store->recordProgress(
            $this->runId,
            0,
            0,
            0,
            1 // user progress increment
        );
    }

    public function afterDone(array $summary): void {
        $current = $this->store->get();
        if (
            is_wp_error($current) ||
            $current === null ||
            $current->getRunId() !== $this->runId ||
            $current->getStatus() !== BulkProgress::STATUS_RUNNING
        ) {
            return;
        }

        // Acknowledge cooperative cancellation
        if ($current->isCancelRequested()) {
            $this->store->finishRun($this->runId, BulkProgress::STATUS_CANCELLED);
            return;
        }

        // Invalidate if connection changed
        if ($this->settings->getConnectionRevision() !== $this->connRev) {
            $this->store->finishRun($this->runId, BulkProgress::STATUS_INTERRUPTED);
            return;
        }

        $completedIds = $summary['completed_user_ids'];
        if (empty($completedIds)) {
            // Traversal stopped without completing any users
            if ($summary['stopped']) {
                return;
            }
            $this->store->finishRun($this->runId, BulkProgress::STATUS_COMPLETED);
            return;
        }

        $newLastUserId = max($completedIds);

        // Check if more users remain ahead of the cursor
        $hasMore = ($this->hasMoreUsers)($newLastUserId, $this->eligibleRoles);

        if ($hasMore && !$summary['stopped']) {
            $scheduled = $this->scheduler->scheduleManualBulkContinuation([
                'run_id'         => $this->runId,
                'connection_rev' => $this->connRev,
                'last_user_id'   => $newLastUserId,
            ]);
            if (!$scheduled) {
                $this->store->finishRun($this->runId, BulkProgress::STATUS_INTERRUPTED);
            }
        } else {
            $this->store->finishRun($this->runId, BulkProgress::STATUS_COMPLETED);
        }
    }
}
