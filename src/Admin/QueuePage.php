<?php
/**
 * Outstanding automatic synchronization queue administration.
 *
 * @package SyncSuiteCRMUsers\Admin
 */

namespace SyncSuiteCRMUsers\Admin;

use SyncSuiteCRMUsers\Model\QueueItem;
use SyncSuiteCRMUsers\Settings;
use SyncSuiteCRMUsers\Storage\QueueRepository;
use SyncSuiteCRMUsers\Sync\Scheduler;

defined('ABSPATH') || exit;

class QueuePage {

    protected Settings $settings;
    protected QueueRepository $queueRepository;

    public function __construct(?Settings $settings = null, ?QueueRepository $queueRepository = null) {
        $this->settings = $settings ?? new Settings();
        $this->queueRepository = $queueRepository ?? new QueueRepository();
    }

    public function register(): void {
        add_action('admin_menu', [$this, 'addAdminMenu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_syus_delete_queue', [$this, 'handleDelete']);
        add_action('wp_ajax_syus_clear_queue', [$this, 'handleClear']);
    }

    public function addAdminMenu(): void {
        add_submenu_page(
            'syus-settings',
            __('Queue', 'sync-users-suitecrm'),
            __('Queue', 'sync-users-suitecrm'),
            'manage_options',
            'syus-queue',
            [$this, 'render']
        );
    }

    public function enqueueAssets(string $hook): void {
        if (strpos($hook, 'syus-queue') === false) {
            return;
        }
        wp_enqueue_script('syus-admin', SYUS_PLUGIN_URL . 'assets/admin.js', ['jquery'], SYUS_VERSION, true);
        wp_localize_script('syus-admin', 'syusAdminData', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('syus_queue_nonce'),
            'strings' => [
                'confirm_delete_queue' => __('Delete these pending queue records?', 'sync-users-suitecrm'),
                'confirm_clear_queue' => __('Clear all pending queue records, including records on other pages and outside the current filters?', 'sync-users-suitecrm'),
                'queue_error' => __('Could not update the queue. Please try again.', 'sync-users-suitecrm'),
            ],
        ]);
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'sync-users-suitecrm'));
            return;
        }
        if (isset($_GET['username']) || isset($_GET['module']) || isset($_GET['paged'])) {
            $requestNonce = isset($_GET['_syus_queue_nonce']) && is_string($_GET['_syus_queue_nonce'])
                ? sanitize_text_field(wp_unslash($_GET['_syus_queue_nonce'])) : '';
            if (!wp_verify_nonce($requestNonce, 'syus_queue_filters')) {
                wp_die(esc_html__('The queue filter link has expired or is invalid. Please reopen the Queue page.', 'sync-users-suitecrm'), '', ['response' => 403]);
                return;
            }
        }
        $filterUsername = isset($_GET['username']) && is_string($_GET['username'])
            ? sanitize_text_field(wp_unslash($_GET['username'])) : '';
        $filterModule = isset($_GET['module']) && is_string($_GET['module'])
            ? sanitize_text_field(wp_unslash($_GET['module'])) : 'all';
        if (!in_array($filterModule, QueueItem::ALLOWED_MODULES, true)) {
            $filterModule = 'all';
        }
        $currentPage = isset($_GET['paged']) && is_scalar($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $result = $this->queueRepository->query([
            'username' => $filterUsername,
            'module' => $filterModule,
            'page' => $currentPage,
            'per_page' => 20,
        ]);
        $queryError = is_wp_error($result) ? $result->get_error_message() : null;
        $items = $queryError === null ? $result['items'] : [];
        $totalItems = $queryError === null ? $result['total_items'] : 0;
        $totalPages = $queryError === null ? $result['total_pages'] : 0;
        $currentPage = $queryError === null ? $result['current_page'] : 1;
        $renderedIds = array_map(static function (QueueItem $item): int { return $item->getId(); }, $items);
        $queueCount = $this->queueRepository->count();
        $canClear = !is_wp_error($queueCount) && $queueCount > 0;
        $settings = $this->settings->getSettings();
        $connectionRev = $this->settings->getConnectionRevision();
        $cron = _get_cron_array();
        $expectedRuns = [];
        foreach ($items as $item) {
            $expectedRuns[$item->getId()] = $this->expectedRun($item, $settings, $connectionRev, is_array($cron) ? $cron : []);
        }
        include SYUS_PLUGIN_DIR . 'templates/queue.php';
    }

    /**
     * Report the scheduled wake-up, including only continuations that cover this row.
     * Real-time loopbacks have no persisted start time; report immediate intent separately.
     *
     * @return array{label: string, timestamp: int|null, fallback: int|null}
     */
    public function expectedRun(QueueItem $item, array $settings, int $connectionRev, array $cron): array {
        $result = ['label' => '', 'timestamp' => null, 'fallback' => null];
        if (empty($settings['auto_sync_enabled'])) {
            $result['label'] = __('Paused — automatic sync disabled', 'sync-users-suitecrm');
            return $result;
        }
        $mode = $settings['auto_sync_mode'] ?? 'realtime';
        $hook = $mode === 'batch' ? Scheduler::HOOK_BATCH_CRON : Scheduler::HOOK_REALTIME_CRON;
        $next = null;
        foreach ($cron as $timestamp => $hooks) {
            foreach ($hooks[$hook] ?? [] as $event) {
                $context = $event['args'][0] ?? [];
                if ($mode === 'batch' && is_array($context) && isset($context['max_queue_id'])) {
                    if (($context['auto_sync_mode'] ?? '') !== 'batch'
                        || (int) ($context['connection_rev'] ?? 0) !== $connectionRev
                        || $item->getId() <= (int) ($context['last_queue_id'] ?? 0)
                        || $item->getId() > (int) $context['max_queue_id']) {
                        continue;
                    }
                }
                $next = $next === null ? (int) $timestamp : min($next, (int) $timestamp);
            }
        }
        if ($mode === 'realtime') {
            $result['label'] = __('As soon as possible', 'sync-users-suitecrm');
            $result['fallback'] = $next;
        } else {
            $result['timestamp'] = $next;
            $result['label'] = $next === null ? __('No batch run scheduled', 'sync-users-suitecrm') : '';
        }
        return $result;
    }

    public function handleDelete(): void {
        check_ajax_referer('syus_queue_nonce', 'nonce');
        $this->authorizeAction();
        $rawIds = isset($_POST['ids']) && is_array($_POST['ids']) ? wp_unslash($_POST['ids']) : [];
        $ids = array_values(array_unique(array_filter(array_map(static function ($id): int {
            return is_scalar($id) && ctype_digit((string) $id) ? (int) $id : 0;
        }, $rawIds), static function (int $id): bool { return $id > 0; })));
        if (empty($ids)) {
            wp_send_json_error(['message' => __('No valid queue records selected.', 'sync-users-suitecrm')], 400);
            return;
        }
        $this->sendResult($this->queueRepository->deleteByIds($ids));
    }

    public function handleClear(): void {
        check_ajax_referer('syus_queue_nonce', 'nonce');
        $this->authorizeAction();
        $this->sendResult($this->queueRepository->clearAll());
    }

    private function authorizeAction(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized permission.', 'sync-users-suitecrm')], 403);
        }
    }

    private function sendResult($result): void {
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 500);
            return;
        }
        wp_send_json_success([
            'deleted_count' => $result,
            /* translators: %d: number of removed queue records. */
            'message' => sprintf(_n('%d queue record removed.', '%d queue records removed.', $result, 'sync-users-suitecrm'), $result),
        ]);
    }
}
