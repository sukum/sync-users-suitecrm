<?php
/**
 * Source-specific bookkeeping contract for chunk processing.
 *
 * Implemented by automatic queue workers, manual bulk runners, and individual
 * manual sync handlers to customize candidate module selection, lifecycle
 * callbacks, and post-chunk continuation scheduling.
 *
 * @package SyncUsersSuitecrm\Sync
 */

namespace SyncUsersSuitecrm\Sync;

use SyncUsersSuitecrm\Model\SyncOutcome;

defined('ABSPATH') || exit;

interface ChunkHandler {

    /**
     * Get the captured connection revision for the current execution chunk.
     *
     * Ensures in-flight operations do not silently adopt a newer connection revision.
     *
     * @return int
     */
    public function connectionRevision(): int;

    /**
     * Retrieve the list of candidate SuiteCRM modules to process for a given user.
     *
     * In automatic queue processing, returns modules corresponding to the selected queue rows.
     * In manual sync processing, returns all currently enabled modules.
     *
     * @param int $userId WordPress user ID.
     * @return list<string> List of candidate module names ('Accounts', 'Contacts', 'Leads', 'Users').
     */
    public function modulesForUser(int $userId): array;

    /**
     * Evaluate whether execution should continue before visiting each user and module.
     *
     * Rereads execution validity (e.g., settings, run identity, cancellation requests)
     * and returns false to gracefully stop traversal early.
     *
     * @return bool True to continue processing, false to stop.
     */
    public function shouldContinue(): bool;

    /**
     * Outcome notification callback invoked once for every evaluated module.
     *
     * Called for all terminal outcomes (success, failure, skipped, lock conflict),
     * even when activity log persistence fails.
     *
     * @param SyncOutcome $outcome
     * @return void
     */
    public function onOutcome(SyncOutcome $outcome): void;

    /**
     * User completion callback invoked once after all candidate modules for a user finish.
     *
     * Never called for partially processed users if traversal stopped mid-user.
     *
     * @param int $userId WordPress user ID.
     * @return void
     */
    public function onUserComplete(int $userId): void;

    /**
     * Post-chunk bookkeeping callback invoked once after the processor loop finishes.
     *
     * Receives the execution summary to advance cursors, schedule continuations,
     * or mark runs completed/cancelled.
     *
     * @param array{
     *     completed_user_ids: list<int>,
     *     handled_modules: array<int, list<string>>,
     *     stopped: bool
     * } $summary
     * @return void
     */
    public function afterDone(array $summary): void;
}
