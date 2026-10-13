<?php
/**
 * Admin Activity Log Page controller.
 *
 * Renders the SuiteCRM synchronization audit activity log, providing
 * filtering, pagination, and log pruning actions.
 *
 * @package SyncSuiteCRMUsers\Admin
 */

namespace SyncSuiteCRMUsers\Admin;

use SyncSuiteCRMUsers\Storage\ActivityLogRepository;

defined('ABSPATH') || exit;

class ActivityLogPage {

    /**
     * Activity log repository.
     *
     * @var ActivityLogRepository
     */
    protected ActivityLogRepository $logRepository;

    /**
     * Constructor.
     *
     * @param ActivityLogRepository|null $logRepository
     */
    public function __construct(?ActivityLogRepository $logRepository = null) {
        $this->logRepository = $logRepository ?? new ActivityLogRepository();
    }

    /**
     * Register hooks for the activity log page.
     *
     * @return void
     */
    public function register(): void {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    /**
     * Enqueue assets when viewing the Activity Log page.
     *
     * @param string $hook Screen hook.
     * @return void
     */
    public function enqueueAssets(string $hook): void {
        if (strpos($hook, 'ssu-activity-log') === false) {
            return;
        }

        wp_enqueue_style('dashicons');

        $assetFile    = SSU_PLUGIN_DIR . 'assets/admin.js';
        $scriptHandle = 'ssu-admin';

        if (file_exists($assetFile)) {
            wp_enqueue_script(
                $scriptHandle,
                SSU_PLUGIN_URL . 'assets/admin.js',
                ['jquery'],
                SSU_VERSION,
                true
            );
        } else {
            wp_register_script($scriptHandle, false, ['jquery'], SSU_VERSION, true);
            wp_enqueue_script($scriptHandle);
        }

        wp_localize_script($scriptHandle, 'ssuAdminData', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ssu_admin_nonce'),
            'strings'  => [
                'confirm_delete_shown' => __('Are you sure you want to delete the records currently displayed on this page?', 'sync-suitecrm-users'),
                'confirm_clear_all'    => __('Are you sure you want to permanently clear ALL activity log records? This cannot be undone.', 'sync-suitecrm-users'),
                'deleting'             => __('Deleting records...', 'sync-suitecrm-users'),
                'clearing'             => __('Clearing all logs...', 'sync-suitecrm-users'),
            ],
        ]);
    }

    /**
     * Render the Activity Log view.
     *
     * - Order: timestamp DESC, id DESC.
     * - Filters: Username, Status (All, Success, Failure, Skipped), Module (All, Accounts, Contacts, Leads, Users).
     * - Pagination.
     * - Delete records shown on this page button.
     * - Clear all logs button.
     *
     * @return void
     */
    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'sync-suitecrm-users'));
            return;
        }

        if (isset($_GET['username']) || isset($_GET['status']) || isset($_GET['module']) || isset($_GET['paged'])) {
            $requestNonce = isset($_GET['_ssu_activity_log_nonce']) && is_string($_GET['_ssu_activity_log_nonce'])
                ? sanitize_text_field(wp_unslash($_GET['_ssu_activity_log_nonce'])) : '';
            if (!wp_verify_nonce($requestNonce, 'ssu_activity_log_filters')) {
                wp_die(esc_html__('The activity log filter link has expired or is invalid. Please reopen the Activity Log page.', 'sync-suitecrm-users'), '', ['response' => 403]);
                return;
            }
        }

        // 1. Sanitize GET filters
        $filterUsername = isset($_GET['username']) ? sanitize_text_field(wp_unslash($_GET['username'])) : '';
        $filterStatus   = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'all';
        $filterModule   = isset($_GET['module']) ? sanitize_text_field(wp_unslash($_GET['module'])) : 'all';
        $currentPage    = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $perPage        = 20;

        if (!in_array($filterStatus, ActivityLogRepository::ALLOWED_STATUSES, true)) {
            $filterStatus = 'all';
        }

        if (!in_array($filterModule, ActivityLogRepository::ALLOWED_MODULES, true)) {
            $filterModule = 'all';
        }

        // 2. Query repository
        $queryArgs = [
            'username' => $filterUsername,
            'status'   => $filterStatus,
            'module'   => $filterModule,
            'page'     => $currentPage,
            'per_page' => $perPage,
            'orderby'  => 'timestamp',
            'order'    => 'DESC',
        ];

        $result = $this->logRepository->query($queryArgs);

        if (is_wp_error($result)) {
            $items       = [];
            $totalItems  = 0;
            $totalPages  = 0;
            $currentPage = 1;
            $queryError  = $result->get_error_message();
        } else {
            $items       = $result['items'];
            $totalItems  = $result['total_items'];
            $totalPages  = $result['total_pages'];
            $currentPage = $result['current_page'];
            $queryError  = null;
        }

        // Extract rendered IDs to safely power "Delete records shown on this page"
        $renderedIds = array_values(
            array_filter(
                array_map(
                    static function ($item): int {
                        return (int) $item->getId();
                    },
                    $items
                ),
                static function (int $id): bool {
                    return $id > 0;
                }
            )
        );

        $nonce = wp_create_nonce('ssu_admin_nonce');

        $templatePath = SSU_PLUGIN_DIR . 'templates/activity-log.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        } else {
            echo '<div class="wrap"><p>' . esc_html__('Activity log template not found.', 'sync-suitecrm-users') . '</p></div>';
        }
    }
}
