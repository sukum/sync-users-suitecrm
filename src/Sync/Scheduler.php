<?php
/**
 * Cron and async loopback scheduler for synchronization operations.
 *
 * Manages scheduling for daily batches, real-time HTTP loopbacks and fallbacks,
 * manual bulk chunk continuations, and token renewals.
 *
 * @package SyncUsersSuitecrm\Sync
 */

namespace SyncUsersSuitecrm\Sync;

use SyncUsersSuitecrm\Settings;
use WP_Error;
use function SyncUsersSuitecrm\write_log;

defined('ABSPATH') || exit;

class Scheduler {

    /**
     * Cron action hook names.
     */
    public const HOOK_BATCH_CRON        = 'syus_batch_sync_cron';
    public const HOOK_REALTIME_CRON     = 'syus_realtime_worker_cron';
    public const HOOK_MANUAL_BULK_CRON  = 'syus_manual_bulk_cron';
    public const HOOK_TOKEN_RENEWAL_CRON = 'syus_token_renewal_cron';

    /**
     * Ajax action name for real-time async loopback worker.
     */
    public const AJAX_ACTION_REALTIME_WORKER = 'syus_process_queue_worker';

    /**
     * Short-lived, per-dispatch nonce for the HTTP worker only.
     */
    public const WORKER_NONCE_FIELD = 'worker_nonce';
    private const WORKER_NONCE_PREFIX = 'syus_worker_nonce_';
    private const WORKER_NONCE_TTL = 300;

    /**
     * Fallback cron delay in seconds (5 minutes).
     */
    public const FALLBACK_DELAY_SECONDS = 300;

    /** Distinguish the five-minute fallback from the hourly worker event. */
    private const REALTIME_FALLBACK_ARGS = [['fallback' => true]];

    /**
     * Settings service.
     *
     * @var Settings
     */
    protected Settings $settings;

    /**
     * Constructor.
     *
     * @param Settings|null $settings
     */
    public function __construct(?Settings $settings = null) {
        $this->settings = $settings ?? new Settings();
    }

    /**
     * Request immediate background real-time processing through a non-blocking HTTP loopback.
     *
     * Dispatches an asynchronous non-blocking POST to admin-ajax.php with timeout 1s.
     *
     * @return bool|WP_Error True if dispatched, or WP_Error on transport failure.
     */
    public function dispatchRealtimeLoopback() {
        write_log('Dispatching realtime loopback.');
        $ajaxUrl  = admin_url('admin-ajax.php');
        $nonce    = bin2hex(random_bytes(16));
        $nonceKey = self::WORKER_NONCE_PREFIX . $nonce;

        if (!set_transient($nonceKey, '1', self::WORKER_NONCE_TTL)) {
            return new WP_Error(
                'syus_worker_nonce_failed',
                __('Could not prepare the background sync request.', 'sync-users-suitecrm')
            );
        }

        $response = wp_remote_post(
            $ajaxUrl,
            [
                'timeout'   => 1,
                'blocking'  => false,
                'sslverify' => apply_filters('https_local_ssl_verify', false),
                'body'      => [
                    'action' => self::AJAX_ACTION_REALTIME_WORKER,
                    self::WORKER_NONCE_FIELD => $nonce,
                ],
            ]
        );

        if (is_wp_error($response)) {
            write_log('Failed to dispatch realtime loopback. Deleting transient.');

            delete_transient($nonceKey);
            return $response;
        }
        write_log('Successfully dispatched realtime loopback. Nonce: ' . $nonce);

        return true;
    }

    /**
     * Validate and delete a loopback nonce before processing queue work.
     *
     * Consumption is best effort; concurrent replay is intentionally not locked.
     *
     * @param mixed $nonce Value from the POST body.
     * @return bool
     */
    public function consumeRealtimeNonce($nonce): bool {
        write_log(['message' => 'Consuming realtime loopback nonce.', 'nonce' => $nonce]);
        if (!is_string($nonce) || !preg_match('/\A[a-f0-9]{32}\z/', $nonce)) {
            return false;
        }

        $nonceKey = self::WORKER_NONCE_PREFIX . $nonce;
        if (get_transient($nonceKey) !== '1') {
            write_log('Failed to consume realtime loopback nonce.');
            return false;
        }
        write_log('Consumed realtime loopback nonce.');

        return delete_transient($nonceKey);
    }

    /**
     * Arrange a shared fallback cron wake-up five minutes later for real-time work.
     *
     * Reuses shared wake-ups rather than scheduling duplicate cron events for every user.
     *
     * @return bool True if scheduled or already scheduled.
     */
    public function scheduleRealtimeFallback(): bool {
        if (wp_next_scheduled(self::HOOK_REALTIME_CRON, self::REALTIME_FALLBACK_ARGS)) {
            return true;
        }

        $nextRun = time() + self::FALLBACK_DELAY_SECONDS;
        $scheduled = wp_schedule_single_event($nextRun, self::HOOK_REALTIME_CRON, self::REALTIME_FALLBACK_ARGS);

        return (bool) $scheduled;
    }

    /**
     * Schedule a batch continuation step for the single-pass nightly batch run.
     *
     * Context shape: array{connection_rev: int, auto_sync_mode: 'batch', last_queue_id: int, max_queue_id: int}.
     *
     * @param array{
     *     connection_rev: int,
     *     auto_sync_mode: 'batch',
     *     last_queue_id: int,
     *     max_queue_id: int
     * } $context Validated continuation context.
     * @return bool
     */
    public function scheduleBatchContinuation(array $context): bool {
        $scheduled = wp_schedule_single_event(time(), self::HOOK_BATCH_CRON, [$context]);
        return (bool) $scheduled;
    }

    /**
     * Schedule a manual bulk continuation chunk.
     *
     * Context shape: array{run_id: string, connection_rev: int, last_user_id: int}.
     *
     * @param array{
     *     run_id: string,
     *     connection_rev: int,
     *     last_user_id: int
     * } $context
     * @return bool
     */
    public function scheduleManualBulkContinuation(array $context): bool {
        $scheduled = wp_schedule_single_event(time(), self::HOOK_MANUAL_BULK_CRON, [$context]);
        return (bool) $scheduled;
    }

    /**
     * Synchronize recurring batch sync cron schedule with current configuration settings.
     *
     * If auto sync is enabled and mode is 'batch', schedules daily recurring cron
     * at the configured site-local time. Otherwise, clears any pending recurring batch event.
     *
     * @return void
     */
    public function syncBatchSchedule(): void {
        // Clear any existing recurring batch cron
        $this->clearRecurringBatchCron();

        $settings = $this->settings->getSettings();
        $enabled  = !empty($settings['auto_sync_enabled']);
        $mode     = $settings['auto_sync_mode'] ?? 'realtime';

        if (!$enabled || $mode !== 'batch') {
            return;
        }

        $hour   = (string) ($settings['batch_time_hour'] ?? '02');
        $minute = (string) ($settings['batch_time_minute'] ?? '00');

        $nextTimestamp = $this->calculateNextBatchTimestamp($hour, $minute);
        wp_schedule_event($nextTimestamp, 'daily', self::HOOK_BATCH_CRON);
    }

    /**
     * Keep one hourly worker event while automatic real-time sync is enabled.
     * Clears all real-time events, including fallbacks, when disabled or in batch mode.
     *
     * @return void
     */
    public function syncRealtimeSchedule(): void {
        $settings = $this->settings->getSettings();
        if (empty($settings['auto_sync_enabled']) || ($settings['auto_sync_mode'] ?? '') !== 'realtime') {
            wp_unschedule_hook(self::HOOK_REALTIME_CRON);
            return;
        }

        if (wp_get_schedule(self::HOOK_REALTIME_CRON) === 'hourly') {
            return;
        }

        // Replace legacy single fallback events with the recurring hourly event.
        wp_clear_scheduled_hook(self::HOOK_REALTIME_CRON);
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::HOOK_REALTIME_CRON);
    }

    /**
     * Calculate next UTC execution timestamp for a given site-local hour and minute.
     *
     * Uses wp_timezone() and current_datetime() avoiding legacy gmt_offset.
     *
     * @param string $hour   Two-digit hour ('00'-'23').
     * @param string $minute Two-digit minute ('00', '10', '20', '30', '40', '50').
     * @return int UTC Unix timestamp.
     */
    public function calculateNextBatchTimestamp(string $hour, string $minute): int {
        $tz          = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $now         = function_exists('current_datetime') ? current_datetime() : new \DateTimeImmutable('now', $tz);
        $targetToday = $now->setTime((int) $hour, (int) $minute, 0);

        if ($targetToday->getTimestamp() <= $now->getTimestamp()) {
            // Already passed today, schedule for tomorrow
            $targetNext = $targetToday->modify('+1 day');
        } else {
            $targetNext = $targetToday;
        }

        return $targetNext->getTimestamp();
    }

    /**
     * Schedule proactive OAuth2 token renewal before access token expires.
     *
     * Schedules renewal 5 minutes before expiration.
     *
     * @param int $expiresAtUtc UTC Unix timestamp of token expiration.
     * @return bool
     */
    public function scheduleTokenRenewal(int $expiresAtUtc): bool {
        wp_clear_scheduled_hook(self::HOOK_TOKEN_RENEWAL_CRON);

        $renewalTime = max(time() + 60, $expiresAtUtc - 300);
        $scheduled   = wp_schedule_single_event($renewalTime, self::HOOK_TOKEN_RENEWAL_CRON);

        return (bool) $scheduled;
    }

    /**
     * Clear all scheduled events used by the plugin.
     *
     * @return void
     */
    public function clearAllSchedules(): void {
        $this->clearRecurringBatchCron();
        wp_unschedule_hook(self::HOOK_REALTIME_CRON);
        wp_clear_scheduled_hook(self::HOOK_MANUAL_BULK_CRON);
        wp_clear_scheduled_hook(self::HOOK_TOKEN_RENEWAL_CRON);
    }

    /**
     * Clear the recurring batch cron event.
     *
     * @return void
     */
    public function clearRecurringBatchCron(): void {
        $timestamp = wp_next_scheduled(self::HOOK_BATCH_CRON);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK_BATCH_CRON);
            $timestamp = wp_next_scheduled(self::HOOK_BATCH_CRON);
        }
    }
}
