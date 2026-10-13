<?php
/**
 * Admin Settings Page controller.
 *
 * Registers the SuiteCRM Sync admin menus, enqueues scripts,
 * renders the settings view, and handles administrative AJAX operations.
 *
 * @package SyncSuiteCRMUsers\Admin
 */

namespace SyncSuiteCRMUsers\Admin;

use SyncSuiteCRMUsers\Settings;
use SyncSuiteCRMUsers\Sync\ManualSyncController;

defined('ABSPATH') || exit;

class SettingsPage {

    /**
     * @var Settings
     */
    protected Settings $settings;

    /**
     * @var ActivityLogPage|null
     */
    protected ?ActivityLogPage $activityLogPage = null;

    /**
     * SettingsPage constructor.
     *
     * @param Settings             $settings
     * @param ActivityLogPage|null $activityLogPage
     */
    public function __construct(Settings $settings, ?ActivityLogPage $activityLogPage = null) {
        $this->settings        = $settings;
        $this->activityLogPage = $activityLogPage;
    }

    /**
     * Register admin hooks and AJAX actions.
     *
     * @return void
     */
    public function register(): void {
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);

        // Register AJAX fallback hooks if ActionHandlers has not already registered them
        if (!has_action('wp_ajax_syus_save_settings')) {
            add_action('wp_ajax_syus_save_settings', [$this, 'handleSaveSettings']);
        }
        if (!has_action('wp_ajax_syus_test_api')) {
            add_action('wp_ajax_syus_test_api', [$this, 'handleTestApi']);
        }
        if (!has_action('wp_ajax_syus_connect')) {
            add_action('wp_ajax_syus_connect', [$this, 'handleConnect']);
        }
        if (!has_action('wp_ajax_syus_start_bulk')) {
            add_action('wp_ajax_syus_start_bulk', [$this, 'handleStartBulk']);
        }
        if (!has_action('wp_ajax_syus_poll_progress')) {
            add_action('wp_ajax_syus_poll_progress', [$this, 'handlePollProgress']);
        }
        if (!has_action('wp_ajax_syus_cancel_bulk')) {
            add_action('wp_ajax_syus_cancel_bulk', [$this, 'handleCancelBulk']);
        }
    }

    /**
     * Add admin menu pages.
     *
     * @return void
     */
    public function addAdminMenu(): void {
        add_menu_page(
            __('SuiteCRM Sync', 'sync-users-suitecrm'),
            __('SuiteCRM Sync', 'sync-users-suitecrm'),
            'manage_options',
            'syus-settings',
            [$this, 'render'],
            'dashicons-update',
            80
        );

        add_submenu_page(
            'syus-settings',
            __('Settings', 'sync-users-suitecrm'),
            __('Settings', 'sync-users-suitecrm'),
            'manage_options',
            'syus-settings',
            [$this, 'render']
        );

        add_submenu_page(
            'syus-settings',
            __('Activity Log', 'sync-users-suitecrm'),
            __('Activity Log', 'sync-users-suitecrm'),
            'manage_options',
            'syus-activity-log',
            [$this, 'renderActivityLog']
        );
    }

    /**
     * Enqueue admin assets on plugin pages.
     *
     * @param string $hook
     * @return void
     */
    public function enqueueAssets(string $hook): void {
        if (strpos($hook, 'syus-settings') === false && strpos($hook, 'syus-activity-log') === false) {
            return;
        }

        wp_enqueue_style('dashicons');

        $assetFile    = SYUS_PLUGIN_DIR . 'assets/admin.js';
        $scriptHandle = 'syus-admin';
        $dependencies = ['jquery'];

        if (strpos($hook, 'syus-settings') !== false) {
            $settingsAssetFile = SYUS_PLUGIN_DIR . 'assets/settings.js';
            wp_enqueue_script(
                'syus-settings',
                SYUS_PLUGIN_URL . 'assets/settings.js',
                ['jquery', 'backbone'],
                file_exists($settingsAssetFile) ? (string) filemtime($settingsAssetFile) : SYUS_VERSION,
                true
            );
            $dependencies[] = 'syus-settings';
        }

        if (file_exists($assetFile)) {
            wp_enqueue_script(
                $scriptHandle,
                SYUS_PLUGIN_URL . 'assets/admin.js',
                $dependencies,
                (string) filemtime($assetFile),
                true
            );
        } else {
            wp_register_script($scriptHandle, false, $dependencies, SYUS_VERSION, true);
            wp_enqueue_script($scriptHandle);
        }

        wp_localize_script($scriptHandle, 'syusAdminData', [
            'ajax_url'      => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('syus_admin_nonce'),
            'poll_interval' => 3000,
            'strings'       => [
                'saving'               => __('Saving settings...', 'sync-users-suitecrm'),
                'saved'                => __('Settings saved successfully.', 'sync-users-suitecrm'),
                'testing'              => __('Testing API reachability...', 'sync-users-suitecrm'),
                'connecting'           => __('Connecting to SuiteCRM...', 'sync-users-suitecrm'),
                'connected'            => __('Connected successfully.', 'sync-users-suitecrm'),
                'connect_failed'       => __('Connection failed.', 'sync-users-suitecrm'),
                'connection_connected' => __('Connected', 'sync-users-suitecrm'),
                'connection_reconnect_required' => __('Reconnect Required', 'sync-users-suitecrm'),
                'connection_not_configured' => __('Not Configured', 'sync-users-suitecrm'),
                'api_reachable'        => __('SuiteCRM REST V8 API is reachable.', 'sync-users-suitecrm'),
                'api_test_failed'      => __('API reachability test failed.', 'sync-users-suitecrm'),
                'start_bulk'           => __('Sync all existing users', 'sync-users-suitecrm'),
                'cancel_bulk'          => __('Cancel sync', 'sync-users-suitecrm'),
                'clear_bulk'           => __('Clear sync', 'sync-users-suitecrm'),
                'bulk_started'         => __('Bulk synchronization initiated in background.', 'sync-users-suitecrm'),
                'bulk_start_failed'    => __('Failed to start bulk synchronization.', 'sync-users-suitecrm'),
                'bulk_cancel_requested' => __('Cancellation requested. Processing will halt at the next user boundary.', 'sync-users-suitecrm'),
                'bulk_cancel_failed'   => __('Failed to request cancellation.', 'sync-users-suitecrm'),
                'bulk_cleared'         => __('Bulk synchronization progress cleared.', 'sync-users-suitecrm'),
                'bulk_clear_failed'    => __('Failed to clear bulk synchronization progress.', 'sync-users-suitecrm'),
                'bulk_idle'            => __('IDLE', 'sync-users-suitecrm'),
                'bulk_running'         => __('RUNNING', 'sync-users-suitecrm'),
                'bulk_completed'       => __('COMPLETED', 'sync-users-suitecrm'),
                'bulk_cancelled'       => __('CANCELLED', 'sync-users-suitecrm'),
                'bulk_interrupted'     => __('INTERRUPTED', 'sync-users-suitecrm'),
                'bulk_stalled'         => __('STALLED / INTERRUPTED', 'sync-users-suitecrm'),
                'poll_failed'          => __('Unable to retrieve bulk synchronization progress.', 'sync-users-suitecrm'),
                'starting_bulk'        => __('Starting bulk sync...', 'sync-users-suitecrm'),
                'cancelling_bulk'      => __('Requesting cancellation...', 'sync-users-suitecrm'),
                'confirm_cancel'       => __('Are you sure you want to cancel the active bulk synchronization?', 'sync-users-suitecrm'),
                'confirm_same_crm'     => __('Changing SuiteCRM URL without checking "Same CRM" will purge outstanding queue items and reset all saved CRM record IDs. Do you wish to continue?', 'sync-users-suitecrm'),
                'confirm_delete_shown' => __('Are you sure you want to delete the records currently displayed on this page?', 'sync-users-suitecrm'),
                'confirm_clear_all'    => __('Are you sure you want to permanently clear ALL activity log records? This cannot be undone.', 'sync-users-suitecrm'),
                'deleting'             => __('Deleting records...', 'sync-users-suitecrm'),
                'clearing'             => __('Clearing all logs...', 'sync-users-suitecrm'),
            ],
        ]);
    }

    /**
     * Render the settings page.
     *
     * @return void
     */
    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'sync-users-suitecrm'));
        }

        $settings   = $this->settings->getSettings();
        $connection = $this->settings->getSafeConnection();
        $roles      = wp_roles()->get_names();

        $timezoneString = wp_timezone_string();
        $currentTime    = wp_date('Y-m-d H:i:s');

        $progress        = get_transient('syus_bulk_progress');
        $isProgressStale = false;
        if (is_array($progress) && !empty($progress['last_update_time'])) {
            $isProgressStale = (time() - (int) $progress['last_update_time'] > 60);
        }

        $nonce = wp_create_nonce('syus_admin_nonce');

        $templatePath = SYUS_PLUGIN_DIR . 'templates/settings.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        } else {
            echo '<div class="wrap"><p>' . esc_html__('Settings template not found.', 'sync-users-suitecrm') . '</p></div>';
        }
    }

    /**
     * Render Activity Log page.
     *
     * @return void
     */
    public function renderActivityLog(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'sync-users-suitecrm'));
        }

        if ($this->activityLogPage !== null) {
            $this->activityLogPage->render();
            return;
        }

        (new ActivityLogPage())->render();
    }

    /**
     * AJAX handler: Save settings.
     *
     * @return void
     */
    public function handleSaveSettings(): void {
        (new ActionHandlers($this->settings))->handleSaveSettings();
    }

    /**
     * AJAX handler: Test SuiteCRM API reachability.
     *
     * @return void
     */
    public function handleTestApi(): void {
        (new ActionHandlers($this->settings))->handleTestApi();
    }

    /**
     * AJAX handler: Connect to SuiteCRM and obtain OAuth2 access token.
     *
     * @return void
     */
    public function handleConnect(): void {
        (new ActionHandlers($this->settings))->handleConnect();
    }

    /**
     * AJAX handler: Start background manual bulk sync.
     *
     * @return void
     */
    public function handleStartBulk(): void {
        (new ActionHandlers($this->settings))->handleStartBulk();
    }

    /**
     * AJAX handler: Poll bulk sync progress.
     *
     * @return void
     */
    public function handlePollProgress(): void {
        (new ActionHandlers($this->settings))->handlePollProgress();
    }

    /**
     * AJAX handler: Request cancellation of running bulk sync.
     *
     * @return void
     */
    public function handleCancelBulk(): void {
        (new ActionHandlers($this->settings))->handleCancelBulk();
    }
}
