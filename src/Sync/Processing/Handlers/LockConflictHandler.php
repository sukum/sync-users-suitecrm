<?php
/**
 * Report terminal skips when another process owns the user lock.
 *
 * @package SyncUsersSuitecrm\Sync\Processing\Handlers
 */

namespace SyncUsersSuitecrm\Sync\Processing\Handlers;

use SyncUsersSuitecrm\Model\SyncOutcome;
use SyncUsersSuitecrm\Sync\ChunkHandler;

defined('ABSPATH') || exit;

class LockConflictHandler extends AbstractOutcomeHandler implements OutcomeHandler {

    /** {@inheritDoc} */
    public function handle(
        int $userId,
        ?string $username,
        array $modules,
        ChunkHandler $handler,
        ?string $errorMessage = null
    ): array {
        $handledModules = [];

        foreach ($modules as $module) {
            $outcome = SyncOutcome::skipped(
                $userId,
                $username,
                $module,
                SyncOutcome::REASON_LOCK_CONFLICT,
                __('Resource locked by another active synchronization process.', 'sync-users-suitecrm')
            );
            $this->logOutcome($outcome);
            $handler->onOutcome($outcome);
            $handledModules[] = $module;
        }

        return $handledModules;
    }
}
