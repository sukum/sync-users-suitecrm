<?php
/**
 * User-level Manual Synchronization Actions.
 *
 * Provides the manual "Sync to SuiteCRM" interface on WordPress user edit
 * and profile screens, as well as row actions in the Users list table.
 *
 * @package SyncSuiteCRMUsers\Admin
 */

namespace SyncSuiteCRMUsers\Admin;

use SyncSuiteCRMUsers\Settings;
use SyncSuiteCRMUsers\Sync\Eligibility;
use SyncSuiteCRMUsers\Sync\ManualSyncController;
use SyncSuiteCRMUsers\Storage\UserMappingRepository;
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

        $assetFile    = SSU_PLUGIN_DIR . 'assets/user-sync.js';
        $scriptHandle = 'ssu-user-sync';

        if (file_exists($assetFile)) {
            wp_enqueue_script(
                $scriptHandle,
                SSU_PLUGIN_URL . 'assets/user-sync.js',
                ['jquery', 'backbone'],
                (string) filemtime($assetFile),
                true
            );
        } else {
            wp_register_script($scriptHandle, false, ['jquery', 'backbone'], SSU_VERSION, true);
            wp_enqueue_script($scriptHandle);
        }

        wp_localize_script($scriptHandle, 'ssuUserSyncData', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ssu_user_sync_nonce'),
            'strings'  => [
                'syncing'          => __('Synchronizing user with SuiteCRM...', 'sync-suitecrm-users'),
                'sync_complete'    => __('Synchronization complete.', 'sync-suitecrm-users'),
                'sync_failed'      => __('Synchronization failed.', 'sync-suitecrm-users'),
                'confirm_sync'     => __('Synchronize this user to SuiteCRM now?', 'sync-suitecrm-users'),
                'sync_outcomes'    => __('Synchronization Outcomes:', 'sync-suitecrm-users'),
                'row_syncing'      => __('Syncing...', 'sync-suitecrm-users'),
                'row_synced'       => __('Synced', 'sync-suitecrm-users'),
                'row_failed'       => __('Failed', 'sync-suitecrm-users'),
                'connection_error' => __('Error communicating with synchronization engine.', 'sync-suitecrm-users'),
                'column_module'    => __('Module', 'sync-suitecrm-users'),
                'column_action'    => __('Action', 'sync-suitecrm-users'),
                'column_status'    => __('Status', 'sync-suitecrm-users'),
                'column_details'   => __('Details', 'sync-suitecrm-users'),
                'crm_id'           => __('CRM ID:', 'sync-suitecrm-users'),
                'mapped'           => __('Mapped', 'sync-suitecrm-users'),
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
        $nonce          = wp_create_nonce('ssu_user_sync_nonce');

        $templatePath = SSU_PLUGIN_DIR . 'templates/user-sync.php';
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

        $nonce = wp_create_nonce('ssu_user_sync_nonce');
        $actions['ssu_sync'] = sprintf(
            '<a href="#" class="ssu-sync-user-row-btn" data-user-id="%d" data-nonce="%s" title="%s">%s</a>',
            (int) $user->ID,
            esc_attr($nonce),
            esc_attr__('Push this user directly to SuiteCRM', 'sync-suitecrm-users'),
            esc_html__('Sync to SuiteCRM', 'sync-suitecrm-users')
        );

        return $actions;
    }
}
