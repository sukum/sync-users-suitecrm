<?php
/**
 * Report terminal failures when user lock storage fails.
 *
 * @package SyncSuiteCRMUsers\Sync\Processing\Handlers
 */

namespace SyncSuiteCRMUsers\Sync\Processing\Handlers;

use SyncSuiteCRMUsers\Model\SyncOutcome;
use SyncSuiteCRMUsers\Sync\ChunkHandler;

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
