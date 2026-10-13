<?php
/**
 * Bulk synchronization progress data object.
 *
 * Represents an active, completed, cancelled, or interrupted manual bulk synchronization run.
 * Provides controlled state-transition methods to enforce local lifecycle invariants.
 *
 * @package SyncSuiteCRMUsers
 */

namespace SyncSuiteCRMUsers\Model;

defined('ABSPATH') || exit;

class BulkProgress {

    public const STATUS_RUNNING     = 'running';
    public const STATUS_COMPLETED   = 'completed';
    public const STATUS_CANCELLED   = 'cancelled';
    public const STATUS_INTERRUPTED = 'interrupted';

    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_INTERRUPTED,
    ];

    /**
     * Unique run identifier.
     *
     * @var string
     */
    private string $runId;

    /**
     * Connection revision captured at run start.
     *
     * @var int
     */
    private int $connectionRev;

    /**
     * Current execution status.
     *
     * @var string
     */
    private string $status;

    /**
     * Whether cancellation has been requested by administrator.
     *
     * @var bool
     */
    private bool $cancelRequested;

    /**
     * UTC Unix timestamp when bulk run began.
     *
     * @var int
     */
    private int $startedAt;

    /**
     * UTC Unix timestamp of most recent worker progress/status update.
     *
     * @var int
     */
    private int $lastUpdateTime;

    /**
     * Approximate eligible WP users at start of run.
     *
     * @var int
     */
    private int $totalUsers;

    /**
     * Count of WP users evaluated so far across all modules.
     *
     * @var int
     */
    private int $processedUsers;

    /**
     * Count of successful module outcomes.
     *
     * @var int
     */
    private int $successCount;

    /**
     * Count of failed module outcomes.
     *
     * @var int
     */
    private int $failureCount;

    /**
     * Count of skipped module outcomes.
     *
     * @var int
     */
    private int $skippedCount;

    /**
     * Constructor.
     *
     * @param string $runId
     * @param int    $connectionRev
     * @param string $status
     * @param bool   $cancelRequested
     * @param int    $startedAt
     * @param int    $lastUpdateTime
     * @param int    $totalUsers
     * @param int    $processedUsers
     * @param int    $successCount
     * @param int    $failureCount
     * @param int    $skippedCount
     */
    public function __construct(
        string $runId,
        int $connectionRev,
        string $status = self::STATUS_RUNNING,
        bool $cancelRequested = false,
        int $startedAt = 0,
        int $lastUpdateTime = 0,
        int $totalUsers = 0,
        int $processedUsers = 0,
        int $successCount = 0,
        int $failureCount = 0,
        int $skippedCount = 0
    ) {
        $now = time();

        $this->runId           = $runId;
        $this->connectionRev   = $connectionRev;
        $this->status          = $status;
        $this->cancelRequested = $cancelRequested;
        $this->startedAt       = $startedAt > 0 ? $startedAt : $now;
        $this->lastUpdateTime  = $lastUpdateTime > 0 ? $lastUpdateTime : $now;
        $this->totalUsers      = max(0, $totalUsers);
        $this->processedUsers  = max(0, $processedUsers);
        $this->successCount    = max(0, $successCount);
        $this->failureCount    = max(0, $failureCount);
        $this->skippedCount    = max(0, $skippedCount);
    }

    /**
     * Get run ID.
     *
     * @return string
     */
    public function getRunId(): string {
        return $this->runId;
    }

    /**
     * Get connection revision.
     *
     * @return int
     */
    public function getConnectionRev(): int {
        return $this->connectionRev;
    }

    /**
     * Get status string.
     *
     * @return string
     */
    public function getStatus(): string {
        return $this->status;
    }

    /**
     * Whether cancellation has been requested.
     *
     * @return bool
     */
    public function isCancelRequested(): bool {
        return $this->cancelRequested;
    }

    /**
     * Get started timestamp.
     *
     * @return int
     */
    public function getStartedAt(): int {
        return $this->startedAt;
    }

    /**
     * Get last update timestamp.
     *
     * @return int
     */
    public function getLastUpdateTime(): int {
        return $this->lastUpdateTime;
    }

    /**
     * Get approximate total users.
     *
     * @return int
     */
    public function getTotalUsers(): int {
        return $this->totalUsers;
    }

    /**
     * Get processed users count.
     *
     * @return int
     */
    public function getProcessedUsers(): int {
        return $this->processedUsers;
    }

    /**
     * Get module success count.
     *
     * @return int
     */
    public function getSuccessCount(): int {
        return $this->successCount;
    }

    /**
     * Get module failure count.
     *
     * @return int
     */
    public function getFailureCount(): int {
        return $this->failureCount;
    }

    /**
     * Get module skipped count.
     *
     * @return int
     */
    public function getSkippedCount(): int {
        return $this->skippedCount;
    }

    /**
     * Whether the run is currently in a running state.
     *
     * @return bool
     */
    public function isRunning(): bool {
        return $this->status === self::STATUS_RUNNING;
    }

    /**
     * Whether the run completed normally.
     *
     * @return bool
     */
    public function isCompleted(): bool {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Whether the run was cancelled.
     *
     * @return bool
     */
    public function isCancelled(): bool {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Whether the run was interrupted.
     *
     * @return bool
     */
    public function isInterrupted(): bool {
        return $this->status === self::STATUS_INTERRUPTED;
    }

    /**
     * Whether the run is currently in a terminal state.
     *
     * @return bool
     */
    public function isTerminal(): bool {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * Request cancellation of the run.
     *
     * Requesting cancellation sets cancel_requested = true while preserving last_update_time
     * (which reflects worker progress).
     *
     * @return void
     */
    public function requestCancellation(): void {
        $this->cancelRequested = true;
    }

    /**
     * Record outcome counts and user progress.
     *
     * Updates worker timestamp and preserves cancel_requested state.
     *
     * @param int $successDelta        Incremental module successes.
     * @param int $failureDelta        Incremental module failures.
     * @param int $skippedDelta        Incremental module skips.
     * @param int $processedUsersDelta Incremental processed users.
     * @return void
     */
    public function recordProgress(
        int $successDelta = 0,
        int $failureDelta = 0,
        int $skippedDelta = 0,
        int $processedUsersDelta = 0
    ): void {
        if ($this->isTerminal()) {
            return;
        }

        $this->successCount    += max(0, $successDelta);
        $this->failureCount    += max(0, $failureDelta);
        $this->skippedCount    += max(0, $skippedDelta);
        $this->processedUsers  += max(0, $processedUsersDelta);
        $this->lastUpdateTime  = time();
    }

    /**
     * Perform controlled state transition to terminal status.
     *
     * A terminal run cannot transition back to running.
     * If cancellation was requested, completion must transition to cancelled.
     *
     * @param string $newStatus 'completed'|'cancelled'|'interrupted'.
     * @return void
     */
    public function transitionTo(string $newStatus): void {
        if ($this->isTerminal()) {
            return;
        }

        // Cancellation takes precedence over normal completion
        if ($this->cancelRequested && $newStatus === self::STATUS_COMPLETED) {
            $newStatus = self::STATUS_CANCELLED;
        }

        $this->status         = $newStatus;
        $this->lastUpdateTime = time();
    }

    /**
     * Mark the bulk run as completed.
     *
     * Note: If cancellation was previously requested, transitionTo()
     * will automatically resolve this to cancelled.
     *
     * @return void
     */
    public function markCompleted(): void {
        $this->transitionTo(self::STATUS_COMPLETED);
    }

    /**
     * Mark the bulk run as cancelled.
     *
     * @return void
     */
    public function markCancelled(): void {
        $this->transitionTo(self::STATUS_CANCELLED);
    }

    /**
     * Mark the bulk run as interrupted.
     *
     * @return void
     */
    public function markInterrupted(): void {
        $this->transitionTo(self::STATUS_INTERRUPTED);
    }

    /**
     * Convert to a plain associative array matching the schema.
     *
     * @return array{
     *     run_id: string,
     *     connection_rev: int,
     *     status: string,
     *     cancel_requested: bool,
     *     started_at: int,
     *     last_update_time: int,
     *     total_users: int,
     *     processed_users: int,
     *     success_count: int,
     *     failure_count: int,
     *     skipped_count: int
     * }
     */
    public function toArray(): array {
        return [
            'run_id'           => $this->runId,
            'connection_rev'   => $this->connectionRev,
            'status'           => $this->status,
            'cancel_requested' => $this->cancelRequested,
            'started_at'       => $this->startedAt,
            'last_update_time' => $this->lastUpdateTime,
            'total_users'      => $this->totalUsers,
            'processed_users'  => $this->processedUsers,
            'success_count'    => $this->successCount,
            'failure_count'    => $this->failureCount,
            'skipped_count'    => $this->skippedCount,
        ];
    }

    /**
     * Hydrate and validate BulkProgress from associative array.
     *
     * Rejects malformed records by returning null or throwing.
     *
     * @param array<string, mixed> $data
     * @return self|null Returns null if array is malformed.
     */
    public static function fromArray(array $data): ?self {
        if (
            empty($data['run_id']) ||
            !is_string($data['run_id']) ||
            !isset($data['connection_rev']) ||
            !is_numeric($data['connection_rev']) ||
            empty($data['status']) ||
            !is_string($data['status'])
        ) {
            return null;
        }

        return new self(
            (string) $data['run_id'],
            (int) $data['connection_rev'],
            (string) $data['status'],
            !empty($data['cancel_requested']),
            isset($data['started_at']) ? (int) $data['started_at'] : 0,
            isset($data['last_update_time']) ? (int) $data['last_update_time'] : 0,
            isset($data['total_users']) ? (int) $data['total_users'] : 0,
            isset($data['processed_users']) ? (int) $data['processed_users'] : 0,
            isset($data['success_count']) ? (int) $data['success_count'] : 0,
            isset($data['failure_count']) ? (int) $data['failure_count'] : 0,
            isset($data['skipped_count']) ? (int) $data['skipped_count'] : 0
        );
    }
}
