<?php
/**
 * Administrative AJAX Action Handlers.
 *
 * Centralizes administrative AJAX operations: settings management,
 * API connectivity tests, OAuth authentication, bulk run triggers,
 * user synchronization, and audit activity log pruning.
 *
 * @package SyncSuiteCRMUsers\Admin
 */

namespace SyncSuiteCRMUsers\Admin;

use SyncSuiteCRMUsers\Settings;
use SyncSuiteCRMUsers\Sync\ManualSyncController;
use SyncSuiteCRMUsers\Sync\Scheduler;
use SyncSuiteCRMUsers\Storage\ActivityLogRepository;
use WP_Error;

defined('ABSPATH') || exit;

class ActionHandlers {

    /**
     * Settings service.
     *
     * @var Settings
     */
    protected Settings $settings;

    /**
     * Manual sync controller.
     *
     * @var ManualSyncController
     */
    protected ManualSyncController $manualSyncController;

    /**
     * Activity log repository.
     *
     * @var ActivityLogRepository
     */
    protected ActivityLogRepository $logRepository;

    /**
     * Constructor.
     *
     * @param Settings|null              $settings
     * @param ManualSyncController|null  $manualSyncController
     * @param ActivityLogRepository|null $logRepository
     */
    public function __construct(
        ?Settings $settings = null,
        ?ManualSyncController $manualSyncController = null,
        ?ActivityLogRepository $logRepository = null
    ) {
        $this->settings             = $settings ?? new Settings();
        $this->manualSyncController = $manualSyncController ?? new ManualSyncController($this->settings);
        $this->logRepository        = $logRepository ?? new ActivityLogRepository();
    }

    /**
     * Register all administrative AJAX action hooks.
     *
     * @return void
     */
    public function register(): void {
        add_action('wp_ajax_syus_save_settings', [$this, 'handleSaveSettings']);
        add_action('wp_ajax_syus_test_api', [$this, 'handleTestApi']);
        add_action('wp_ajax_syus_connect', [$this, 'handleConnect']);
        add_action('wp_ajax_syus_start_bulk', [$this, 'handleStartBulk']);
        add_action('wp_ajax_syus_poll_progress', [$this, 'handlePollProgress']);
        add_action('wp_ajax_syus_cancel_bulk', [$this, 'handleCancelBulk']);
        add_action('wp_ajax_syus_clear_bulk', [$this, 'handleClearBulk']);
        add_action('wp_ajax_syus_sync_user', [$this, 'handleSyncUser']);
        add_action('wp_ajax_syus_delete_logs', [$this, 'handleDeleteLogs']);
        add_action('wp_ajax_syus_clear_logs', [$this, 'handleClearLogs']);
    }

    /**
     * AJAX handler: Save settings.
     *
     * @return void
     */
    public function handleSaveSettings(): void {
        check_ajax_referer('syus_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'code'    => 'unauthorized',
                'message' => __('Unauthorized permission.', 'sync-users-suitecrm'),
            ], 403);
        }

        $postData = wp_unslash($_POST);

        $input = [
            'auto_sync_enabled' => !empty($postData['auto_sync_enabled']),
            'auto_sync_mode'    => sanitize_key($postData['auto_sync_mode'] ?? 'realtime'),
            'batch_time_hour'   => sanitize_text_field($postData['batch_time_hour'] ?? '02'),
            'batch_time_minute' => sanitize_text_field($postData['batch_time_minute'] ?? '00'),
            'eligible_roles'    => isset($postData['eligible_roles']) && is_array($postData['eligible_roles'])
                ? array_map('sanitize_key', $postData['eligible_roles'])
                : [],
            'modules'           => [],
        ];

        foreach (Settings::SUPPORTED_MODULES as $module) {
            $input['modules'][$module] = [
                'enabled' => !empty($postData['modules'][$module]['enabled']),
                'mode'    => sanitize_key($postData['modules'][$module]['mode'] ?? 'create_update'),
            ];
        }

        $updated = $this->settings->updateSettings($input);

        if (is_wp_error($updated)) {
            wp_send_json_error([
                'code'    => $updated->get_error_code(),
                'message' => $updated->get_error_message(),
            ], 400);
        }

        $scheduler = new Scheduler($this->settings);
        if (($updated['auto_sync_mode'] ?? '') === 'batch') {
            $scheduler->syncBatchSchedule();
        } else {
            $scheduler->clearRecurringBatchCron();
        }
        $scheduler->syncRealtimeSchedule();

        wp_send_json_success([
            'message'  => __('Settings saved successfully.', 'sync-users-suitecrm'),
            'settings' => $updated,
        ]);
    }

    /**
     * AJAX handler: Test SuiteCRM API reachability.
     *
     * @return void
     */
    public function handleTestApi(): void {
        check_ajax_referer('syus_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'code'    => 'unauthorized',
                'message' => __('Unauthorized permission.', 'sync-users-suitecrm'),
            ], 403);
        }

        $baseUrl = isset($_POST['base_url']) ? untrailingslashit(trim(sanitize_text_field(wp_unslash($_POST['base_url'])))) : '';
        if (empty($baseUrl)) {
            $conn    = $this->settings->getConnection();
            $baseUrl = $conn['base_url'];
        }

        if (empty($baseUrl) || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            wp_send_json_error([
                'code'    => 'invalid_url',
                'message' => __('Please enter a valid SuiteCRM base URL.', 'sync-users-suitecrm'),
            ], 400);
        }

        $endpoint = $baseUrl . '/Api/access_token';
        $response = wp_remote_post($endpoint, [
            'timeout'   => 15,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'headers'   => [
                'Content-Type' => 'application/vnd.api+json',
                'Accept'       => 'application/vnd.api+json',
            ],
            'body'      => wp_json_encode(['grant_type' => 'password']),
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error([
                'code'    => 'transport_error',
                'message' => sprintf(
                    /* translators: %s: transport error message */
                    __('Connection failed: %s', 'sync-users-suitecrm'),
                    esc_html($response->get_error_message())
                ),
            ], 502);
        }

        $code = wp_remote_retrieve_response_code($response);

        // SuiteCRM 7 REST V8 returns 400 or 401 when reached without valid credentials
        if ($code === 400 || $code === 401 || $code === 200) {
            wp_send_json_success([
                'reachable'               => true,
                'authentication_required' => true,
                'http_status'             => $code,
                'message'                 => __('SuiteCRM REST V8 API is reachable.', 'sync-users-suitecrm'),
            ]);
        }

        wp_send_json_error([
            'code'        => 'unexpected_status',
            'message'     => sprintf(
                /* translators: %d: HTTP status code */
                __('API reachable but returned unexpected HTTP status %d.', 'sync-users-suitecrm'),
                $code
            ),
            'http_status' => $code,
        ], 502);
    }

    /**
     * AJAX handler: Connect to SuiteCRM and obtain OAuth2 access token.
     *
     * @return void
     */
    public function handleConnect(): void {
        check_ajax_referer('syus_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'code'    => 'unauthorized',
                'message' => __('Unauthorized permission.', 'sync-users-suitecrm'),
            ], 403);
        }

        $postData = wp_unslash($_POST);
        $sameCrm  = !empty($postData['same_crm']);

        $input = [
            'base_url'      => sanitize_text_field($postData['base_url'] ?? ''),
            'client_id'     => sanitize_text_field($postData['client_id'] ?? ''),
            'client_secret' => (string) ($postData['client_secret'] ?? ''),
            'username'      => sanitize_text_field($postData['username'] ?? ''),
            'password'      => (string) ($postData['password'] ?? ''),
        ];

        // Persist connection values first (handling secret preservation)
        $conn = $this->settings->updateConnection($input, $sameCrm);
        if (is_wp_error($conn)) {
            $errorData = $conn->get_error_data();
            $status = (is_array($errorData) && isset($errorData['http_status'])) ? (int) $errorData['http_status'] : 500;
            wp_send_json_error([
                'code'    => $conn->get_error_code(),
                'message' => $conn->get_error_message(),
            ], $status);
            return;
        }

        if (empty($conn['base_url']) || empty($conn['client_id']) || empty($conn['client_secret']) || empty($conn['username']) || empty($conn['password'])) {
            wp_send_json_error([
                'code'    => 'missing_credentials',
                'message' => __('All connection fields are required to establish an API connection.', 'sync-users-suitecrm'),
            ], 400);
        }

        $endpoint = untrailingslashit($conn['base_url']) . '/Api/access_token';
        $payload  = [
            'grant_type'    => 'password',
            'client_id'     => $conn['client_id'],
            'client_secret' => $conn['client_secret'],
            'username'      => $conn['username'],
            'password'      => $conn['password'],
        ];

        $response = wp_remote_post($endpoint, [
            'timeout'   => 15,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'headers'   => [
                'Content-Type' => 'application/vnd.api+json',
                'Accept'       => 'application/vnd.api+json',
            ],
            'body'      => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            $errorMessage = $response->get_error_message();
            $this->settings->setConnectionStatus('reconnect_required', $errorMessage);
            wp_send_json_error([
                'code'    => 'transport_error',
                'message' => sprintf(
                    /* translators: %s: transport error message */
                    __('Transport error: %s', 'sync-users-suitecrm'),
                    esc_html($errorMessage)
                ),
            ], 502);
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body       = wp_remote_retrieve_body($response);
        $data       = json_decode($body, true);

        if ($statusCode === 200 && is_array($data) && !empty($data['access_token'])) {
            $accessToken  = (string) $data['access_token'];
            $refreshToken = isset($data['refresh_token']) ? (string) $data['refresh_token'] : null;
            $expiresIn    = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;

            $this->settings->updateTokenData($accessToken, $refreshToken, $expiresIn);
            $lastConnectedAt = time();

            wp_send_json_success([
                'connection_status' => 'connected',
                'last_connected_at' => $lastConnectedAt,
                'last_connected_label' => sprintf(
                    /* translators: %s: formatted date of last connection */
                    __('Last successful handshake: %s', 'sync-users-suitecrm'),
                    wp_date('Y-m-d H:i:s T', $lastConnectedAt)
                ),
                'message'           => __('Connection established and OAuth2 token saved successfully.', 'sync-users-suitecrm'),
            ]);
        }

        // Parse sanitized error message without exposing secrets
        $errorMessage = __('Authentication failed.', 'sync-users-suitecrm');
        if (is_array($data)) {
            if (!empty($data['message'])) {
                $errorMessage = (string) $data['message'];
            } elseif (!empty($data['error_description'])) {
                $errorMessage = (string) $data['error_description'];
            } elseif (!empty($data['errors'][0]['detail'])) {
                $errorMessage = (string) $data['errors'][0]['detail'];
            } elseif (!empty($data['errors'][0]['title'])) {
                $errorMessage = (string) $data['errors'][0]['title'];
            }
        }

        $this->settings->setConnectionStatus('reconnect_required', $errorMessage);

        wp_send_json_error([
            'code'        => 'authentication_unavailable',
            'message'     => esc_html($errorMessage),
            'http_status' => $statusCode,
        ], 400);
    }

    /**
     * AJAX handler: Start background manual bulk sync.
     *
     * @return void
     */
    public function handleStartBulk(): void {
        check_ajax_referer('syus_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'code'    => 'unauthorized',
                'message' => __('Unauthorized permission.', 'sync-users-suitecrm'),
            ], 403);
        }

        $result = $this->manualSyncController->startBulkRun();

        if (is_wp_error($result)) {
            $data   = $result->get_error_data();
            $status = (is_array($data) && isset($data['http_status'])) ? (int) $data['http_status'] : 400;
            wp_send_json_error([
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], $status);
        }

        wp_send_json_success(array_merge([
            'message' => __('Manual bulk synchronization started in background.', 'sync-users-suitecrm'),
        ], $result));
    }

    /**
     * AJAX handler: Poll bulk sync progress.
     *
     * @return void
     */
    public function handlePollProgress(): void {
        check_ajax_referer('syus_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'code'    => 'unauthorized',
                'message' => __('Unauthorized permission.', 'sync-users-suitecrm'),
            ], 403);
        }

        $result = $this->manualSyncController->pollBulkProgress();

        if (is_wp_error($result)) {
            wp_send_json_error([
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], 500);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX handler: Request cancellation of running bulk sync.
     *
     * @return void
     */
    public function handleCancelBulk(): void {
        check_ajax_referer('syus_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'code'    => 'unauthorized',
                'message' => __('Unauthorized permission.', 'sync-users-suitecrm'),
            ], 403);
        }

        $postData = wp_unslash($_POST);
        $runId    = sanitize_text_field($postData['run_id'] ?? '');

        $result = $this->manualSyncController->cancelBulkRun($runId);

        if (is_wp_error($result)) {
            $data   = $result->get_error_data();
            $status = (is_array($data) && isset($data['http_status'])) ? (int) $data['http_status'] : 400;
            wp_send_json_error([
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], $status);
        }

        wp_send_json_success(array_merge([
            'message' => __('Cancellation requested. Worker will halt at the next user boundary.', 'sync-users-suitecrm'),
        ], $result));
    }

    /**
     * AJAX handler: Request clearing of stale bulk sync.
     *
     * @return void
     */
    public function handleClearBulk(): void {
        check_ajax_referer('syus_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'code'    => 'unauthorized',
                'message' => __('Unauthorized permission.', 'sync-users-suitecrm'),
            ], 403);
        }

        $postData = wp_unslash($_POST);
        $runId    = sanitize_text_field($postData['run_id'] ?? '');

        $result = $this->manualSyncController->clearBulkRun($runId);

        if (is_wp_error($result)) {
            $data   = $result->get_error_data();
            $status = (is_array($data) && isset($data['http_status'])) ? (int) $data['http_status'] : 400;
            wp_send_json_error([
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], $status);
        }

        wp_send_json_success([
            'message' => __('Cleared.', 'sync-users-suitecrm'),
        ]);
    }

    /**
     * AJAX handler: Synchronize an individual user directly to SuiteCRM.
     *
     * Nonce action: syus_user_sync_nonce
     * Capability: edit_users (verified for target user ID)
     *
     * @return void
     */
    public function handleSyncUser(): void {
        check_ajax_referer('syus_user_sync_nonce', 'nonce');

        $userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        if ($userId <= 0) {
            wp_send_json_error([
                'code'    => 'invalid_user_id',
                'message' => __('Invalid user ID provided.', 'sync-users-suitecrm'),
            ], 400);
        }

        if (!current_user_can('edit_user', $userId)) {
            wp_send_json_error([
                'code'    => 'unauthorized',
                'message' => __('You do not have permission to sync this user.', 'sync-users-suitecrm'),
            ], 403);
        }

        $result = $this->manualSyncController->syncUser($userId);

        if (is_wp_error($result)) {
            $data   = $result->get_error_data();
            $status = (is_array($data) && isset($data['http_status'])) ? (int) $data['http_status'] : 400;
            wp_send_json_error([
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], $status);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX handler: Delete displayed activity log rows.
     *
     * Nonce action: syus_admin_nonce
     * Capability: manage_options
     * Input: {ids: list<int>} containing exact displayed row IDs.
     *
     * @return void
     */
    public function handleDeleteLogs(): void {
        check_ajax_referer('syus_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'code'    => 'unauthorized',
                'message' => __('Unauthorized permission.', 'sync-users-suitecrm'),
            ], 403);
        }

        $postData = wp_unslash($_POST);
        $rawIds   = $postData['ids'] ?? [];

        // Support both JSON string and array
        if (is_string($rawIds)) {
            $decoded = json_decode($rawIds, true);
            if (is_array($decoded)) {
                $rawIds = $decoded;
            } else {
                $rawIds = explode(',', $rawIds);
            }
        }

        if (!is_array($rawIds)) {
            $rawIds = [];
        }

        $sanitizedIds = array_values(
            array_filter(
                array_map('intval', $rawIds),
                static function (int $id): bool {
                    return $id > 0;
                }
            )
        );

        if (empty($sanitizedIds)) {
            wp_send_json_error([
                'code'    => 'invalid_input',
                'message' => __('No valid record IDs provided for deletion.', 'sync-users-suitecrm'),
            ], 400);
        }

        $deletedCount = $this->logRepository->deleteByIds($sanitizedIds);

        if (is_wp_error($deletedCount)) {
            wp_send_json_error([
                'code'    => $deletedCount->get_error_code(),
                'message' => $deletedCount->get_error_message(),
            ], 500);
        }

        wp_send_json_success([
            'deleted_count' => $deletedCount,
            'message'       => sprintf(
                /* translators: %d: number of deleted rows */
                _n('%d log entry deleted successfully.', '%d log entries deleted successfully.', $deletedCount, 'sync-users-suitecrm'),
                $deletedCount
            ),
        ]);
    }

    /**
     * AJAX handler: Clear all activity log entries.
     *
     * Nonce action: syus_admin_nonce
     * Capability: manage_options
     *
     * @return void
     */
    public function handleClearLogs(): void {
        check_ajax_referer('syus_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'code'    => 'unauthorized',
                'message' => __('Unauthorized permission.', 'sync-users-suitecrm'),
            ], 403);
        }

        $clearedCount = $this->logRepository->clearAll();

        if (is_wp_error($clearedCount)) {
            wp_send_json_error([
                'code'    => $clearedCount->get_error_code(),
                'message' => $clearedCount->get_error_message(),
            ], 500);
        }

        wp_send_json_success([
            'deleted_count' => $clearedCount,
            'message'       => sprintf(
                /* translators: %d: number of cleared records */
                __('Successfully cleared %d activity log records.', 'sync-users-suitecrm'),
                $clearedCount
            ),
        ]);
    }
}
