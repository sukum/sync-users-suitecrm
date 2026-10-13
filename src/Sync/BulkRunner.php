<?php
/**
 * Manual bulk synchronization runner.
 *
 * Executes manual bulk user synchronization in scheduled background chunks,
 * monitoring cooperative cancellation, updating progress transients,
 * and scheduling subsequent chunks.
 *
 * @package SyncUsersSuitecrm\Sync
 */

namespace SyncUsersSuitecrm\Sync;

use SyncUsersSuitecrm\Model\BulkProgress;
use SyncUsersSuitecrm\Settings;
use SyncUsersSuitecrm\Storage\BulkProgressStore;

defined('ABSPATH') || exit;

class BulkRunner {

    /**
     * Users chunk size for manual bulk processing.
     */
    public const CHUNK_SIZE = 25;

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
     * Register hook callbacks.
     *
     * @return void
     */
    public function registerHooks(): void {
        add_action('syus_execute_manual_bulk', [$this, 'handleChunk'], 10, 1);
    }

    /**
     * Handle execution of a single manual bulk chunk.
     *
     * - Validates context: run_id, connection_rev, last_user_id.
     * - Verifies run is active and matching in BulkProgressStore.
     * - Traverses users using ID > last_user_id ORDER BY ID ASC LIMIT 25.
     * - Checks cooperative cancellation before each user and module.
     * - Updates progress transient on every outcome and user completion.
     * - Marks completed or cancelled, or schedules continuation for remaining users.
     *
     * @param array<string, mixed> $context Callback context.
     * @return void
     */
    public function handleChunk($context = []): void {
        if (!is_array($context) || empty($context['run_id'])) {
            return;
        }

        $runId       = (string) $context['run_id'];
        $capturedRev = (int) ($context['connection_rev'] ?? 0);
        $lastUserId  = (int) ($context['last_user_id'] ?? 0);

        SyncGuard::prevent_loop(function () use ($runId, $capturedRev, $lastUserId) {
            // 1. Validate current progress transient state
            $progress = $this->progressStore->get();
            if (is_wp_error($progress) || $progress === null) {
                return;
            }

            // Invalidate if run ID does not match or run is no longer running
            if ($progress->getRunId() !== $runId || $progress->getStatus() !== BulkProgress::STATUS_RUNNING) {
                return;
            }

            // Check cooperative cancellation request
            if ($progress->isCancelRequested()) {
                $this->progressStore->finishRun($runId, BulkProgress::STATUS_CANCELLED);
                return;
            }

            // Check connection revision
            if ($this->settings->getConnectionRevision() !== $capturedRev) {
                $this->progressStore->finishRun($runId, BulkProgress::STATUS_INTERRUPTED);
                return;
            }

            // 2. Fetch candidate user IDs
            $eligibleRoles = $this->eligibility->getEligibleRoles();
            $enabledModules = $this->eligibility->getEnabledModules();

            if (empty($eligibleRoles) || empty($enabledModules)) {
                $this->progressStore->finishRun($runId, BulkProgress::STATUS_COMPLETED);
                return;
            }

            $userIds = $this->fetchNextUserIds($lastUserId, self::CHUNK_SIZE, $eligibleRoles);
            if (empty($userIds)) {
                $this->progressStore->finishRun($runId, BulkProgress::STATUS_COMPLETED);
                return;
            }

            // 3. Build manual bulk chunk handler
            $handler = new BulkChunkHandler(
                $runId,
                $capturedRev,
                $enabledModules,
                $eligibleRoles,
                $this->progressStore,
                $this->settings,
                $this->scheduler,
                [$this, 'hasMoreUsers']
            );

            $this->chunkProcessor->process($userIds, $handler);
        });
    }

    /**
     * Query candidate WordPress user IDs where ID > lastUserId.
     *
     * @param int          $lastUserId    Ascending cursor ID.
     * @param int          $limit         Max users to fetch.
     * @param list<string> $eligibleRoles List of qualifying role slugs.
     * @return list<int>
     */
    public function fetchNextUserIds(int $lastUserId, int $limit, array $eligibleRoles): array {
        global $wpdb;

        $filter = function ($query) use ($wpdb, $lastUserId) {
            $query->query_where .= $wpdb->prepare(" AND {$wpdb->users}.ID > %d", $lastUserId);
        };

        add_action('pre_user_query', $filter);

        $results = get_users([
            'role__in' => $eligibleRoles,
            'orderby'  => 'ID',
            'order'    => 'ASC',
            'number'   => $limit,
            'fields'   => 'ID',
        ]);

        remove_action('pre_user_query', $filter);

        if (!is_array($results)) {
            return [];
        }

        return array_values(array_map('intval', $results));
    }

    /**
     * Check if any qualifying WordPress users exist beyond cursor ID.
     *
     * @param int          $lastUserId    Ascending cursor ID.
     * @param list<string> $eligibleRoles List of qualifying role slugs.
     * @return bool
     */
    public function hasMoreUsers(int $lastUserId, array $eligibleRoles): bool {
        $next = $this->fetchNextUserIds($lastUserId, 1, $eligibleRoles);
        return !empty($next);
    }
}
