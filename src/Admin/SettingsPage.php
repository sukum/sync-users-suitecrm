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
        if (!has_action('wp_ajax_ssu_save_settings')) {
            add_action('wp_ajax_ssu_save_settings', [$this, 'handleSaveSettings']);
        }
        if (!has_action('wp_ajax_ssu_test_api')) {
            add_action('wp_ajax_ssu_test_api', [$this, 'handleTestApi']);
        }
        if (!has_action('wp_ajax_ssu_connect')) {
            add_action('wp_ajax_ssu_connect', [$this, 'handleConnect']);
        }
        if (!has_action('wp_ajax_ssu_start_bulk')) {
            add_action('wp_ajax_ssu_start_bulk', [$this, 'handleStartBulk']);
        }
        if (!has_action('wp_ajax_ssu_poll_progress')) {
            add_action('wp_ajax_ssu_poll_progress', [$this, 'handlePollProgress']);
        }
        if (!has_action('wp_ajax_ssu_cancel_bulk')) {
            add_action('wp_ajax_ssu_cancel_bulk', [$this, 'handleCancelBulk']);
        }
    }

    /**
     * Add admin menu pages.
     *
     * @return void
     */
    public function addAdminMenu(): void {
        add_menu_page(
            __('SuiteCRM Sync', 'sync-suitecrm-users'),
            __('SuiteCRM Sync', 'sync-suitecrm-users'),
            'manage_options',
            'ssu-settings',
            [$this, 'render'],
            'dashicons-update',
            80
        );

        add_submenu_page(
            'ssu-settings',
            __('Settings', 'sync-suitecrm-users'),
            __('Settings', 'sync-suitecrm-users'),
            'manage_options',
            'ssu-settings',
            [$this, 'render']
        );

        add_submenu_page(
            'ssu-settings',
            __('Activity Log', 'sync-suitecrm-users'),
            __('Activity Log', 'sync-suitecrm-users'),
            'manage_options',
            'ssu-activity-log',
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
        if (strpos($hook, 'ssu-settings') === false && strpos($hook, 'ssu-activity-log') === false) {
            return;
        }

        wp_enqueue_style('dashicons');

        $assetFile    = SSU_PLUGIN_DIR . 'assets/admin.js';
        $scriptHandle = 'ssu-admin';
        $dependencies = ['jquery'];

        if (strpos($hook, 'ssu-settings') !== false) {
            $settingsAssetFile = SSU_PLUGIN_DIR . 'assets/settings.js';
            wp_enqueue_script(
                'ssu-settings',
                SSU_PLUGIN_URL . 'assets/settings.js',
                ['jquery', 'backbone'],
                file_exists($settingsAssetFile) ? (string) filemtime($settingsAssetFile) : SSU_VERSION,
                true
            );
            $dependencies[] = 'ssu-settings';
        }

        if (file_exists($assetFile)) {
            wp_enqueue_script(
                $scriptHandle,
                SSU_PLUGIN_URL . 'assets/admin.js',
                $dependencies,
                (string) filemtime($assetFile),
                true
            );
        } else {
            wp_register_script($scriptHandle, false, $dependencies, SSU_VERSION, true);
            wp_enqueue_script($scriptHandle);
        }

        wp_localize_script($scriptHandle, 'ssuAdminData', [
            'ajax_url'      => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('ssu_admin_nonce'),
            'poll_interval' => 3000,
            'strings'       => [
                'saving'               => __('Saving settings...', 'sync-suitecrm-users'),
                'saved'                => __('Settings saved successfully.', 'sync-suitecrm-users'),
                'testing'              => __('Testing API reachability...', 'sync-suitecrm-users'),
                'connecting'           => __('Connecting to SuiteCRM...', 'sync-suitecrm-users'),
                'connected'            => __('Connected successfully.', 'sync-suitecrm-users'),
                'connect_failed'       => __('Connection failed.', 'sync-suitecrm-users'),
                'connection_connected' => __('Connected', 'sync-suitecrm-users'),
                'connection_reconnect_required' => __('Reconnect Required', 'sync-suitecrm-users'),
                'connection_not_configured' => __('Not Configured', 'sync-suitecrm-users'),
                'api_reachable'        => __('SuiteCRM REST V8 API is reachable.', 'sync-suitecrm-users'),
                'api_test_failed'      => __('API reachability test failed.', 'sync-suitecrm-users'),
                'start_bulk'           => __('Sync all existing users', 'sync-suitecrm-users'),
                'cancel_bulk'          => __('Cancel sync', 'sync-suitecrm-users'),
                'clear_bulk'           => __('Clear sync', 'sync-suitecrm-users'),
                'bulk_started'         => __('Bulk synchronization initiated in background.', 'sync-suitecrm-users'),
                'bulk_start_failed'    => __('Failed to start bulk synchronization.', 'sync-suitecrm-users'),
                'bulk_cancel_requested' => __('Cancellation requested. Processing will halt at the next user boundary.', 'sync-suitecrm-users'),
                'bulk_cancel_failed'   => __('Failed to request cancellation.', 'sync-suitecrm-users'),
                'bulk_cleared'         => __('Bulk synchronization progress cleared.', 'sync-suitecrm-users'),
                'bulk_clear_failed'    => __('Failed to clear bulk synchronization progress.', 'sync-suitecrm-users'),
                'bulk_idle'            => __('IDLE', 'sync-suitecrm-users'),
                'bulk_running'         => __('RUNNING', 'sync-suitecrm-users'),
                'bulk_completed'       => __('COMPLETED', 'sync-suitecrm-users'),
                'bulk_cancelled'       => __('CANCELLED', 'sync-suitecrm-users'),
                'bulk_interrupted'     => __('INTERRUPTED', 'sync-suitecrm-users'),
                'bulk_stalled'         => __('STALLED / INTERRUPTED', 'sync-suitecrm-users'),
                'poll_failed'          => __('Unable to retrieve bulk synchronization progress.', 'sync-suitecrm-users'),
                'starting_bulk'        => __('Starting bulk sync...', 'sync-suitecrm-users'),
                'cancelling_bulk'      => __('Requesting cancellation...', 'sync-suitecrm-users'),
                'confirm_cancel'       => __('Are you sure you want to cancel the active bulk synchronization?', 'sync-suitecrm-users'),
                'confirm_same_crm'     => __('Changing SuiteCRM URL without checking "Same CRM" will purge outstanding queue items and reset all saved CRM record IDs. Do you wish to continue?', 'sync-suitecrm-users'),
                'confirm_delete_shown' => __('Are you sure you want to delete the records currently displayed on this page?', 'sync-suitecrm-users'),
                'confirm_clear_all'    => __('Are you sure you want to permanently clear ALL activity log records? This cannot be undone.', 'sync-suitecrm-users'),
                'deleting'             => __('Deleting records...', 'sync-suitecrm-users'),
                'clearing'             => __('Clearing all logs...', 'sync-suitecrm-users'),
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
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'sync-suitecrm-users'));
        }

        $settings   = $this->settings->getSettings();
        $connection = $this->settings->getSafeConnection();
        $roles      = wp_roles()->get_names();

        $timezoneString = wp_timezone_string();
        $currentTime    = wp_date('Y-m-d H:i:s');

        $progress        = get_transient('ssu_bulk_progress');
        $isProgressStale = false;
        if (is_array($progress) && !empty($progress['last_update_time'])) {
            $isProgressStale = (time() - (int) $progress['last_update_time'] > 60);
        }

        $nonce = wp_create_nonce('ssu_admin_nonce');

        $templatePath = SSU_PLUGIN_DIR . 'templates/settings.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        } else {
            echo '<div class="wrap"><p>' . esc_html__('Settings template not found.', 'sync-suitecrm-users') . '</p></div>';
        }
    }

    /**
     * Render Activity Log page.
     *
     * @return void
     */
    public function renderActivityLog(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'sync-suitecrm-users'));
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
