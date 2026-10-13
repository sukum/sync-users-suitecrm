<?php
/**
 * Report terminal skips for modules disabled in configuration.
 *
 * @package SyncSuiteCRMUsers\Sync\Processing\Handlers
 */

namespace SyncSuiteCRMUsers\Sync\Processing\Handlers;

use SyncSuiteCRMUsers\Model\SyncOutcome;
use SyncSuiteCRMUsers\Sync\ChunkHandler;

defined('ABSPATH') || exit;

class DisabledModuleHandler extends AbstractOutcomeHandler implements OutcomeHandler {

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
                SyncOutcome::REASON_MODULE_DISABLED,
                __('Module is disabled in configuration settings.', 'sync-suitecrm-users')
            );
            $this->logOutcome($outcome);
            $handler->onOutcome($outcome);
            $handledModules[] = $module;
        }

        return $handledModules;
    }
}
