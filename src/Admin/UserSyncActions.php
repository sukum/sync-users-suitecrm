<?php
/**
 * User-level Manual Synchronization Actions.
 *
 * Provides the manual "Sync to SuiteCRM" interface on WordPress user edit
 * and profile screens, as well as row actions in the Users list table.
 *
 * @package SyncUsersSuitecrm\Admin
 */

namespace SyncUsersSuitecrm\Admin;

use SyncUsersSuitecrm\Settings;
use SyncUsersSuitecrm\Sync\Eligibility;
use SyncUsersSuitecrm\Sync\ManualSyncController;
use SyncUsersSuitecrm\Storage\UserMappingRepository;
use WP_User;

defined('ABSPATH') || exit;

class UserSyncActions {

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
     * User mapping repository.
     *
     * @var UserMappingRepository
     */
    protected UserMappingRepository $mappingRepository;

    /**
     * Eligibility service.
     *
     * @var Eligibility
     */
    protected Eligibility $eligibility;

    /**
     * Constructor.
     *
     * @param Settings|null              $settings
     * @param ManualSyncController|null  $manualSyncController
     * @param UserMappingRepository|null $mappingRepository
     * @param Eligibility|null            $eligibility
     */
    public function __construct(
        ?Settings $settings = null,
        ?ManualSyncController $manualSyncController = null,
        ?UserMappingRepository $mappingRepository = null,
        ?Eligibility $eligibility = null
    ) {
        $this->settings             = $settings ?? new Settings();
        $this->manualSyncController = $manualSyncController ?? new ManualSyncController($this->settings);
        $this->mappingRepository    = $mappingRepository ?? new UserMappingRepository();
        $this->eligibility          = $eligibility ?? new Eligibility($this->settings);
    }

    /**
     * Register WordPress hooks for user-level sync actions.
     *
     * @return void
     */
    public function register(): void {
        add_action('show_user_profile', [$this, 'renderUserSyncSection']);
        add_action('edit_user_profile', [$this, 'renderUserSyncSection']);
        add_filter('user_row_actions', [$this, 'addUserRowAction'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueueUserAssets']);
    }

    /**
     * Enqueue script assets on user edit and user list screens.
     *
     * @param string $hook Screen hook.
     * @return void
     */
    public function enqueueUserAssets(string $hook): void {
        if (!in_array($hook, ['user-edit.php', 'profile.php', 'users.php'], true)) {
            return;
        }

        wp_enqueue_style('dashicons');

        $assetFile    = SYUS_PLUGIN_DIR . 'assets/user-sync.js';
        $scriptHandle = 'syus-user-sync';

        if (file_exists($assetFile)) {
            wp_enqueue_script(
                $scriptHandle,
                SYUS_PLUGIN_URL . 'assets/user-sync.js',
                ['jquery', 'backbone'],
                (string) filemtime($assetFile),
                true
            );
        } else {
            wp_register_script($scriptHandle, false, ['jquery', 'backbone'], SYUS_VERSION, true);
            wp_enqueue_script($scriptHandle);
        }

        wp_localize_script($scriptHandle, 'syusUserSyncData', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('syus_user_sync_nonce'),
            'strings'  => [
                'syncing'          => __('Synchronizing user with SuiteCRM...', 'sk-user-sync-suitecrm'),
                'sync_complete'    => __('Synchronization complete.', 'sk-user-sync-suitecrm'),
                'sync_failed'      => __('Synchronization failed.', 'sk-user-sync-suitecrm'),
                'confirm_sync'     => __('Synchronize this user to SuiteCRM now?', 'sk-user-sync-suitecrm'),
                'sync_outcomes'    => __('Synchronization Outcomes:', 'sk-user-sync-suitecrm'),
                'row_syncing'      => __('Syncing...', 'sk-user-sync-suitecrm'),
                'row_synced'       => __('Synced', 'sk-user-sync-suitecrm'),
                'row_failed'       => __('Failed', 'sk-user-sync-suitecrm'),
                'connection_error' => __('Error communicating with synchronization engine.', 'sk-user-sync-suitecrm'),
                'column_module'    => __('Module', 'sk-user-sync-suitecrm'),
                'column_action'    => __('Action', 'sk-user-sync-suitecrm'),
                'column_status'    => __('Status', 'sk-user-sync-suitecrm'),
                'column_details'   => __('Details', 'sk-user-sync-suitecrm'),
                'crm_id'           => __('CRM ID:', 'sk-user-sync-suitecrm'),
                'mapped'           => __('Mapped', 'sk-user-sync-suitecrm'),
            ],
        ]);
    }

    /**
     * Render the SuiteCRM Synchronization section on user profile/edit pages.
     *
     * - Executes directly without inserting an automatic queue entry.
     * - Respects selected roles and modules.
     * - Can run when Run automatically is disabled.
     * - Shows per-module outcomes visibly.
     *
     * @param WP_User $user
     * @return void
     */
    public function renderUserSyncSection(WP_User $user): void {
        if (!current_user_can('edit_user', $user->ID)) {
            return;
        }

        $userId         = $user->ID;
        $mappings       = $this->mappingRepository->getAllForUser($userId);
        $mappings       = is_array($mappings) ? $mappings : [];
        $enabledModules = $this->eligibility->getEnabledModules();
        $isEligible     = $this->eligibility->isUserEligible($user);
        $eligibleRoles  = $this->eligibility->getEligibleRoles();
        $userRoles      = (array) $user->roles;
        $nonce          = wp_create_nonce('syus_user_sync_nonce');

        $templatePath = SYUS_PLUGIN_DIR . 'templates/user-sync.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
    }

    /**
     * Add "Sync to SuiteCRM" row action in the Users list table.
     *
     * @param array<string, string> $actions Existing row actions.
     * @param WP_User               $user    Target user object.
     * @return array<string, string>
     */
    public function addUserRowAction(array $actions, WP_User $user): array {
        if (!current_user_can('edit_user', $user->ID)) {
            return $actions;
        }

        $nonce = wp_create_nonce('syus_user_sync_nonce');
        $actions['syus_sync'] = sprintf(
            '<a href="#" class="syus-sync-user-row-btn" data-user-id="%d" data-nonce="%s" title="%s">%s</a>',
            (int) $user->ID,
            esc_attr($nonce),
            esc_attr__('Push this user directly to SuiteCRM', 'sk-user-sync-suitecrm'),
            esc_html__('Sync to SuiteCRM', 'sk-user-sync-suitecrm')
        );

        return $actions;
    }
}
