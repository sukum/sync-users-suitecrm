<?php
/**
 * Manual synchronization controller.
 *
 * Provides direct individual user synchronization and coordinates manual
 * bulk runs (starting runs, polling progress, requesting cancellation).
 *
 * @package SyncSuiteCRMUsers\Sync
 */

namespace SyncSuiteCRMUsers\Sync;

use SyncSuiteCRMUsers\Model\BulkProgress;
use SyncSuiteCRMUsers\Model\SyncOutcome;
use SyncSuiteCRMUsers\Settings;
use SyncSuiteCRMUsers\Storage\BulkProgressStore;
use WP_Error;
use WP_User_Query;

defined('ABSPATH') || exit;

class ManualSyncController {

    /**
     * Settings service.
     *
     * @var Settings
     */
    protected Settings $settings;

    /**
     * Bulk progress transient store.
     *
     * @var BulkProgressStore
     */
    protected BulkProgressStore $progressStore;

    /**
     * Shared chunk processor.
     *
     * @var ChunkProcessor
     */
    protected ChunkProcessor $chunkProcessor;

    /**
     * Scheduler service.
     *
     * @var Scheduler
     */
    protected Scheduler $scheduler;

    /**
     * Eligibility service.
     *
     * @var Eligibility
     */
    protected Eligibility $eligibility;

    /**
     * Constructor.
     *
     * @param Settings|null          $settings
     * @param BulkProgressStore|null $progressStore
     * @param ChunkProcessor|null    $chunkProcessor
     * @param Scheduler|null         $scheduler
     * @param Eligibility|null       $eligibility
     */
    public function __construct(
        ?Settings $settings = null,
        ?BulkProgressStore $progressStore = null,
        ?ChunkProcessor $chunkProcessor = null,
        ?Scheduler $scheduler = null,
        ?Eligibility $eligibility = null
    ) {
        $this->settings       = $settings ?? new Settings();
        $this->progressStore  = $progressStore ?? new BulkProgressStore();
        $this->chunkProcessor = $chunkProcessor ?? new ChunkProcessor();
        $this->scheduler      = $scheduler ?? new Scheduler($this->settings);
        $this->eligibility    = $eligibility ?? new Eligibility($this->settings);
    }

    /**
     * Synchronize an individual WordPress user to SuiteCRM directly.
     *
     * - Executes directly without inserting into the automatic queue.
     * - Respects selected roles and enabled modules.
     * - Can run even when automatic synchronization is disabled.
     * - Makes one attempt per enabled module with no retries.
     * - Collects and returns per-module outcomes.
     *
     * @param int $userId WordPress user ID.
     * @return array{outcomes: list<array<string, mixed>>}|WP_Error
     */
    public function syncUser(int $userId) {
        if ($userId <= 0) {
            return new WP_Error(
                'invalid_user_id',
                __('Invalid user ID provided.', 'sync-suitecrm-users'),
                ['http_status' => 400]
            );
        }

        // Connection status check
        $conn = $this->settings->getConnection();
        if (($conn['connection_status'] ?? '') !== 'connected') {
            return new WP_Error(
                'authentication_unavailable',
                __('SuiteCRM connection is not configured or requires reconnect.', 'sync-suitecrm-users'),
                ['http_status' => 502]
            );
        }

        $enabledModules = $this->eligibility->getEnabledModules();
        if (empty($enabledModules)) {
            return new WP_Error(
                'no_modules_enabled',
                __('No SuiteCRM modules are currently enabled in settings.', 'sync-suitecrm-users'),
                ['http_status' => 400]
            );
        }

        $capturedRev = $this->settings->getConnectionRevision();
        $outcomes    = [];

        // Build single-user manual chunk handler
        $handler = new class($capturedRev, $enabledModules, $outcomes) implements ChunkHandler {

            private int $connRev;
            private array $modules;
            public array $collectedOutcomes = [];

            public function __construct(int $connRev, array $modules, array &$outcomesRef) {
                $this->connRev           = $connRev;
                $this->modules           = $modules;
                $this->collectedOutcomes = &$outcomesRef;
            }

            public function connectionRevision(): int {
                return $this->connRev;
            }

            public function modulesForUser(int $userId): array {
                return $this->modules;
            }

            public function shouldContinue(): bool {
                return true;
            }

            public function onOutcome(SyncOutcome $outcome): void {
                $this->collectedOutcomes[] = $outcome;
            }

            public function onUserComplete(int $userId): void {
                // No-op
            }

            public function afterDone(array $summary): void {
                // No-op
            }
        };

        SyncGuard::prevent_loop(function () use ($userId, $handler) {
            $this->chunkProcessor->process([$userId], $handler);
        });

        $serializedOutcomes = array_map(
            static function (SyncOutcome $outcome): array {
                return $outcome->toArray();
            },
            $outcomes
        );

        return [
            'outcomes' => $serializedOutcomes,
        ];
    }

    /**
     * Start a new manual bulk synchronization background run.
     *
     * - Generates a new run_id.
     * - Computes approximate eligible user count.
     * - Initializes progress transient with status = 'running'.
     * - Schedules the first chunk callback (last_user_id = 0).
     *
     * @return array{progress: array<string, mixed>}|WP_Error
     */
    public function startBulkRun() {
        // 1. Validate connection readiness
        $conn = $this->settings->getConnection();
        if (($conn['connection_status'] ?? '') !== 'connected') {
            return new WP_Error(
                'authentication_unavailable',
                __('SuiteCRM connection is not configured or requires reconnect.', 'sync-suitecrm-users'),
                ['http_status' => 502]
            );
        }

        // 2. Validate configuration
        $enabledModules = $this->eligibility->getEnabledModules();
        if (empty($enabledModules)) {
            return new WP_Error(
                'no_modules_enabled',
                __('No SuiteCRM modules are enabled in settings.', 'sync-suitecrm-users'),
                ['http_status' => 400]
            );
        }

        $eligibleRoles = $this->eligibility->getEligibleRoles();
        if (empty($eligibleRoles)) {
            return new WP_Error(
                'no_roles_eligible',
                __('No user roles are selected for synchronization.', 'sync-suitecrm-users'),
                ['http_status' => 400]
            );
        }

        $connectionRev = $this->settings->getConnectionRevision();
        $totalUsers    = $this->calculateTotalEligibleUsers($eligibleRoles);
        $runId         = 'bulk_' . substr(md5(wp_generate_uuid4() . microtime()), 0, 10);

        // 3. Atomically start run in progress store
        $progress = $this->progressStore->startRun($runId, $connectionRev, $totalUsers);
        if (is_wp_error($progress)) {
            return $progress;
        }

        // 4. Schedule first background chunk callback
        $scheduled = $this->scheduler->scheduleManualBulkContinuation([
            'run_id'         => $runId,
            'connection_rev' => $connectionRev,
            'last_user_id'   => 0,
        ]);

        if (!$scheduled) {
            $this->progressStore->finishRun($runId, BulkProgress::STATUS_INTERRUPTED);
            return new WP_Error(
                'scheduling_failed',
                __('Failed to schedule background bulk execution chunk.', 'sync-suitecrm-users'),
                ['http_status' => 500]
            );
        }

        return [
            'progress' => $progress->toArray(),
        ];
    }

    /**
     * Request cooperative cancellation of an active manual bulk run.
     *
     * Accepts displayed run_id and requests cancellation without touching other runs.
     *
     * @param string $runId Expected active run identifier.
     * @return array{progress: array<string, mixed>}|WP_Error
     */
    public function cancelBulkRun(string $runId) {
        if (empty($runId)) {
            return new WP_Error(
                'invalid_run_id',
                __('Run ID is required for cancellation.', 'sync-suitecrm-users'),
                ['http_status' => 400]
            );
        }

        $progress = $this->progressStore->requestCancellation($runId);
        if (is_wp_error($progress)) {
            return $progress;
        }

        return [
            'progress' => $progress->toArray(),
        ];
    }

    /**
     * Clear a stale manual bulk run.
     *
     * @param string $runId Expected active run identifier.
     * @return * @return array{status: bool}|WP_Error
     */
    public function clearBulkRun(string $runId) {
        if (empty($runId)) {
            return new WP_Error(
                'invalid_run_id',
                __('Run ID is required for cancellation.', 'sync-suitecrm-users'),
                ['http_status' => 400]
            );
        }

        $progress = $this->progressStore->clear($runId);
        if (is_wp_error($progress)) {
            return $progress;
        }

        return [
            'status' => $progress,
        ];
    }

    /**
     * Poll manual bulk progress snapshot for Settings page AJAX.
     *
     * Returns progress snapshot or null, and stale boolean indicator.
     *
     * @return array{progress: array<string, mixed>|null, stale: bool}|WP_Error
     */
    public function pollBulkProgress() {
        $progress = $this->progressStore->get();
        if (is_wp_error($progress)) {
            return $progress;
        }

        $isStale = $this->progressStore->isStale($progress);

        return [
            'progress' => $progress !== null ? $progress->toArray() : null,
            'stale'    => $isStale,
        ];
    }

    /**
     * Calculate approximate count of eligible WordPress users.
     *
     * @param list<string> $eligibleRoles
     * @return int
     */
    protected function calculateTotalEligibleUsers(array $eligibleRoles): int {
        if (empty($eligibleRoles)) {
            return 0;
        }

        $query = new WP_User_Query([
            'role__in'    => $eligibleRoles,
            'count_total' => true,
            'number'      => 1,
            'fields'      => 'ID',
        ]);

        return (int) $query->get_total();
    }
}
