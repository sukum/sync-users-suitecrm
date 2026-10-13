<?php
/**
 * Automatic work enqueuer service.
 *
 * Handles atomic queue insertion for qualifying user creations, updates,
 * and role changes, and triggers real-time async loopback/fallback processing.
 *
 * @package SyncUsersSuitecrm\Sync
 */

namespace SyncUsersSuitecrm\Sync;

use SyncUsersSuitecrm\Settings;
use SyncUsersSuitecrm\Storage\QueueRepository;
use WP_Error;
use function SyncUsersSuitecrm\write_log;

defined('ABSPATH') || exit;

class Enqueuer {

    /**
     * Settings service.
     *
     * @var Settings
     */
    protected Settings $settings;

    /**
     * Queue storage repository.
     *
     * @var QueueRepository
     */
    protected QueueRepository $queueRepository;

    /**
     * Eligibility service.
     *
     * @var Eligibility
     */
    protected Eligibility $eligibility;

    /**
     * Scheduler service.
     *
     * @var Scheduler
     */
    protected Scheduler $scheduler;

    /**
     * Is scheduled flag.
     *
     * @var bool
     */
    protected bool $isScheduled = false;

    /**
     * Constructor.
     *
     * @param Settings|null        $settings
     * @param QueueRepository|null $queueRepository
     * @param Eligibility|null     $eligibility
     * @param Scheduler|null       $scheduler
     */
    public function __construct(
        ?Settings $settings = null,
        ?QueueRepository $queueRepository = null,
        ?Eligibility $eligibility = null,
        ?Scheduler $scheduler = null
    ) {
        $this->settings        = $settings ?? new Settings();
        $this->queueRepository = $queueRepository ?? new QueueRepository();
        $this->eligibility     = $eligibility ?? new Eligibility($this->settings);
        $this->scheduler       = $scheduler ?? new Scheduler($this->settings);
    }

    /**
     * Enqueue work for an eligible WordPress user across all enabled modules.
     *
     * - Only operates if automatic synchronization is enabled.
     * - Verifies that the user has at least one qualifying role.
     * - Inserts or coalesces one queue row per enabled module (preserving created_at).
     * - In 'realtime' mode: dispatches immediate async HTTP loopback and sets 5-minute fallback.
     * - In 'batch' mode: items remain in queue until next scheduled batch run.
     *
     * @param int $userId WordPress user ID.
     * @return bool|WP_Error True if enqueued, false if skipped due to settings/roles, or WP_Error on failure.
     */
    public function enqueueUser(int $userId) {
        write_log('Enqueue user: ' . $userId);

        if ($userId <= 0) {
            return false;
        }

        // 1. Verify automatic synchronization is enabled globally
        if (!$this->eligibility->isAutoSyncEnabled()) {
            return false;
        }

        // 2. Verify user role eligibility
        if (!$this->eligibility->isUserEligible($userId)) {
            return false;
        }

        // 3. Retrieve currently enabled modules
        $enabledModules = $this->eligibility->getEnabledModules();
        if (empty($enabledModules)) {
            return false;
        }

        // 4. Enqueue one row per enabled module (atomic upsert)
        $enqueuedAny = false;
        foreach ($enabledModules as $module) {
            $result = $this->queueRepository->enqueue($userId, $module);
            if (is_wp_error($result)) {
                return $result;
            }
            $enqueuedAny = true;
        }

        if (!$enqueuedAny) {
            return false;
        }

        // 5. In real-time mode, trigger immediate background loopback & fallback
        if ($this->eligibility->getAutoSyncMode() === 'realtime' && !$this->isScheduled) {
            $this->scheduler->dispatchRealtimeLoopback();
            $this->scheduler->scheduleRealtimeFallback();
            $this->isScheduled = true;
        }

        return true;
    }
}
