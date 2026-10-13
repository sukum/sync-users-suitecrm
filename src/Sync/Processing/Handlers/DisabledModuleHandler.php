<?php
/**
 * Report terminal skips for modules disabled in configuration.
 *
 * @package SyncUsersSuitecrm\Sync\Processing\Handlers
 */

namespace SyncUsersSuitecrm\Sync\Processing\Handlers;

use SyncUsersSuitecrm\Model\SyncOutcome;
use SyncUsersSuitecrm\Sync\ChunkHandler;

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
                __('Module is disabled in configuration settings.', 'sync-users-suitecrm')
            );
            $this->logOutcome($outcome);
            $handler->onOutcome($outcome);
            $handledModules[] = $module;
        }

        return $handledModules;
    }
}
