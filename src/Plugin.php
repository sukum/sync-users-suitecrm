<?php
/**
 * Main Plugin coordinator.
 *
 * Handles composition, service instantiation, and core hook registration.
 *
 * @package SyncUsersSuitecrm
 */

namespace SyncUsersSuitecrm;

use SyncUsersSuitecrm\Admin\SettingsPage;
use SyncUsersSuitecrm\Admin\ActivityLogPage;
use SyncUsersSuitecrm\Admin\QueuePage;
use SyncUsersSuitecrm\Admin\UserSyncActions;
use SyncUsersSuitecrm\Admin\ActionHandlers;
use SyncUsersSuitecrm\Admin\Notices;

defined('ABSPATH') || exit;

class Plugin {

    /**
     * Singleton instance.
     *
     * @var Plugin|null
     */
    private static ?Plugin $instance = null;

    /**
     * Settings service.
     *
     * @var Settings
     */
    private Settings $settings;

    /**
     * Admin Settings Page controller.
     *
     * @var SettingsPage|null
     */
    private ?SettingsPage $settingsPage = null;

    /**
     * Admin Activity Log Page controller.
     *
     * @var ActivityLogPage|null
     */
    private ?ActivityLogPage $activityLogPage = null;

    /**
     * User-level sync actions controller.
     *
     * @var UserSyncActions|null
     */
    private ?UserSyncActions $userSyncActions = null;

    /**
     * Administrative AJAX action handlers.
     *
     * @var ActionHandlers|null
     */
    private ?ActionHandlers $actionHandlers = null;

    /**
     * Admin notices handler.
     *
     * @var Notices|null
     */
    private ?Notices $notices = null;

    /**
     * Sync scheduler service.
     *
     * @var Sync\Scheduler|null
     */
    private ?Sync\Scheduler $scheduler = null;

    /**
     * Sync worker service.
     *
     * @var Sync\Worker|null
     */
    private ?Sync\Worker $worker = null;

    /**
     * Manual bulk runner service.
     *
     * @var Sync\BulkRunner|null
     */
    private ?Sync\BulkRunner $bulkRunner = null;

    /**
     * User mutation hooks service.
     *
     * @var Sync\UserHooks|null
     */
    private ?Sync\UserHooks $userHooks = null;

    /**
     * Manual sync controller.
     *
     * @var Sync\ManualSyncController|null
     */
    private ?Sync\ManualSyncController $manualSyncController = null;

    /**
     * Get singleton instance.
     *
     * @return Plugin
     */
    public static function instance(): Plugin {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor for singleton pattern.
     */
    private function __construct() {
        $this->settings = new Settings();
    }

    /**
     * Initialize plugin components and register hooks.
     *
     * @return void
     */
    public function init(): void {
        // Ensure database schema is up-to-date
        Lifecycle::maybeUpgradeSchema();

        // Initialize sync services
        $this->scheduler            = new Sync\Scheduler($this->settings);
        $this->worker               = new Sync\Worker($this->settings, null, null, null, $this->scheduler);
        $this->worker->registerHooks();

        $this->bulkRunner           = new Sync\BulkRunner($this->settings, null, null, $this->scheduler);
        $this->bulkRunner->registerHooks();

        $this->userHooks            = new Sync\UserHooks();
        $this->userHooks->register();

        $this->manualSyncController = new Sync\ManualSyncController($this->settings, null, null, $this->scheduler);

        // Initialize admin interface and AJAX endpoints
        if (is_admin()) {
            $this->actionHandlers  = new ActionHandlers($this->settings, $this->manualSyncController);
            $this->actionHandlers->register();

            $this->activityLogPage = new ActivityLogPage();
            $this->activityLogPage->register();

            (new QueuePage($this->settings))->register();

            $this->userSyncActions = new UserSyncActions($this->settings, $this->manualSyncController);
            $this->userSyncActions->register();

            $this->notices         = new Notices($this->settings);
            $this->notices->register();

            $this->settingsPage    = new SettingsPage($this->settings, $this->activityLogPage);
            $this->settingsPage->register();

            // Plugin row action links
            add_filter('plugin_action_links_' . SYUS_PLUGIN_BASENAME, [$this, 'addPluginActionLinks']);
        }

        // Register action hooks for cron callbacks to avoid unhandled hook warnings
        $this->registerCronHooks();
    }

    /**
     * Add settings link to WordPress Plugins list table.
     *
     * @param array<string, string> $links
     * @return array<string, string>
     */
    public function addPluginActionLinks(array $links): array {
        $settingsUrl = admin_url('admin.php?page=syus-settings');
        $settingsLink = sprintf(
            '<a href="%s">%s</a>',
            esc_url($settingsUrl),
            esc_html__('Settings', 'sync-users-suitecrm')
        );

        array_unshift($links, $settingsLink);
        return $links;
    }

    /**
     * Register cron action hooks.
     *
     * @return void
     */
    private function registerCronHooks(): void {
        // Scheduled task hooks defined in specification
        add_action('syus_batch_sync_cron', [$this, 'handleBatchSyncCron'], 10, 1);
        add_action('syus_realtime_worker_cron', [$this, 'handleRealtimeWorkerCron'], 10, 1);
        add_action('syus_manual_bulk_cron', [$this, 'handleManualBulkCron'], 10, 1);
        add_action('syus_token_renewal_cron', [$this, 'handleTokenRenewalCron']);
    }

    /**
     * Cron action: Batch sync callback.
     *
     * @param array<string, mixed> $context
     * @return void
     */
    public function handleBatchSyncCron($context = []): void {
        do_action('syus_execute_batch_sync', $context);
    }

    /**
     * Cron action: Real-time worker fallback callback.
     *
     * @param array<string, mixed> $context
     * @return void
     */
    public function handleRealtimeWorkerCron($context = []): void {
        do_action('syus_execute_realtime_worker', $context);
    }

    /**
     * Cron action: Manual bulk sync callback.
     *
     * @param array<string, mixed> $context
     * @return void
     */
    public function handleManualBulkCron($context = []): void {
        do_action('syus_execute_manual_bulk', $context);
    }

    /**
     * Cron action: Proactive OAuth2 token renewal callback.
     *
     * @return void
     */
    public function handleTokenRenewalCron(): void {
        do_action('syus_execute_token_renewal');
    }

    /**
     * Access Settings service.
     *
     * @return Settings
     */
    public function getSettings(): Settings {
        return $this->settings;
    }

    /**
     * Access Admin SettingsPage controller.
     *
     * @return SettingsPage|null
     */
    public function getSettingsPage(): ?SettingsPage {
        return $this->settingsPage;
    }

    /**
     * Access Admin ActivityLogPage controller.
     *
     * @return ActivityLogPage|null
     */
    public function getActivityLogPage(): ?ActivityLogPage {
        return $this->activityLogPage;
    }

    /**
     * Access UserSyncActions controller.
     *
     * @return UserSyncActions|null
     */
    public function getUserSyncActions(): ?UserSyncActions {
        return $this->userSyncActions;
    }

    /**
     * Access ActionHandlers controller.
     *
     * @return ActionHandlers|null
     */
    public function getActionHandlers(): ?ActionHandlers {
        return $this->actionHandlers;
    }

    /**
     * Access Notices controller.
     *
     * @return Notices|null
     */
    public function getNotices(): ?Notices {
        return $this->notices;
    }

    /**
     * Access Scheduler service.
     *
     * @return Sync\Scheduler|null
     */
    public function getScheduler(): ?Sync\Scheduler {
        return $this->scheduler;
    }

    /**
     * Access Worker service.
     *
     * @return Sync\Worker|null
     */
    public function getWorker(): ?Sync\Worker {
        return $this->worker;
    }

    /**
     * Access BulkRunner service.
     *
     * @return Sync\BulkRunner|null
     */
    public function getBulkRunner(): ?Sync\BulkRunner {
        return $this->bulkRunner;
    }

    /**
     * Access UserHooks service.
     *
     * @return Sync\UserHooks|null
     */
    public function getUserHooks(): ?Sync\UserHooks {
        return $this->userHooks;
    }

    /**
     * Access ManualSyncController service.
     *
     * @return Sync\ManualSyncController|null
     */
    public function getManualSyncController(): ?Sync\ManualSyncController {
        return $this->manualSyncController;
    }
}
