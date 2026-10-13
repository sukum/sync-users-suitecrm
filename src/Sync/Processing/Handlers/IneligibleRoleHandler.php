<?php
/**
 * Report terminal skips when the user's role is ineligible.
 *
 * @package SyncSuiteCRMUsers\Sync\Processing\Handlers
 */

namespace SyncSuiteCRMUsers\Sync\Processing\Handlers;

use SyncSuiteCRMUsers\Model\SyncOutcome;
use SyncSuiteCRMUsers\Sync\ChunkHandler;

defined('ABSPATH') || exit;

class IneligibleRoleHandler extends AbstractOutcomeHandler implements OutcomeHandler {

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
                SyncOutcome::REASON_ROLE_INELIGIBLE,
                __('User role is not eligible for synchronization.', 'sync-users-suitecrm')
            );
            $this->logOutcome($outcome);
            $handler->onOutcome($outcome);
            $handledModules[] = $module;
        }

        return $handledModules;
    }
}
