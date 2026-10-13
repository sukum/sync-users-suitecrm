<?php
/**
 * Report terminal failures when user lock storage fails.
 *
 * @package SyncUsersSuitecrm\Sync\Processing\Handlers
 */

namespace SyncUsersSuitecrm\Sync\Processing\Handlers;

use SyncUsersSuitecrm\Model\SyncOutcome;
use SyncUsersSuitecrm\Sync\ChunkHandler;

defined('ABSPATH') || exit;

class LockStorageErrorHandler extends AbstractOutcomeHandler implements OutcomeHandler {

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
            $outcome = SyncOutcome::failure(
                $userId,
                $username,
                $module,
                SyncOutcome::REASON_STORAGE_ERROR,
                $errorMessage ?? ''
            );
            $this->logOutcome($outcome);
            $handler->onOutcome($outcome);
            $handledModules[] = $module;
        }

        return $handledModules;
    }
}
