<?php
/**
 * Shared activity log persistence for terminal outcome handlers.
 *
 * @package SyncUsersSuitecrm\Sync\Processing\Handlers
 */

namespace SyncUsersSuitecrm\Sync\Processing\Handlers;

use SyncUsersSuitecrm\Model\SyncOutcome;
use SyncUsersSuitecrm\Storage\ActivityLogRepository;

defined('ABSPATH') || exit;

abstract class AbstractOutcomeHandler {

    /** @var ActivityLogRepository */
    protected ActivityLogRepository $activityLogRepository;

    public function __construct(?ActivityLogRepository $activityLogRepository = null) {
        $this->activityLogRepository = $activityLogRepository ?? new ActivityLogRepository();
    }

    /**
     * Log failures must not prevent outcome callbacks or replay CRM work.
     */
    protected function logOutcome(SyncOutcome $outcome): void {
        try {
            $this->activityLogRepository->insert($outcome->toActivityLogEntry());
        } catch (\Throwable $e) {
            // Failure to write a log entry must not change execution flow.
        }
    }
}
