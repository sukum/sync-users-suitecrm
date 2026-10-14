<?php
/**
 * Queue repository.
 *
 * Owns enqueue/upsert, selection, expiry cleanup, and deletion by row ID for
 * automatic synchronization queue items.
 *
 * @package SyncUsersSuitecrm
 */

namespace SyncUsersSuitecrm\Storage;

use SyncUsersSuitecrm\Model\QueueItem;
use WP_Error;

defined('ABSPATH') || exit;

class QueueRepository {

    /**
     * Get the queue table name.
     *
     * @return string
     */
    public function getTableName(): string {
        return Schema::getQueueTableName();
    }

    /**
     * Query outstanding rows, oldest first, with admin filters and pagination.
     *
     * @param array<string, mixed> $args Username, module, page and per_page.
     * @return array<string, mixed>|WP_Error
     */
    public function query(array $args = []) {
        global $wpdb;

        $tableName = $this->getTableName();
        $page = max(1, (int) ($args['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($args['per_page'] ?? 20)));
        $username = trim((string) ($args['username'] ?? ''));
        $usernameLike = '%' . $wpdb->esc_like($username) . '%';
        $module = in_array($args['module'] ?? 'all', QueueItem::ALLOWED_MODULES, true)
            ? $args['module']
            : '';

        // LEFT JOIN keeps queue rows for users who have since been deleted.
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `%5\$s` q LEFT JOIN `%6\$s` u ON u.ID = q.user_id
             WHERE (%s = '' OR u.user_login LIKE %s) AND (%s = '' OR q.module = %s)",
            $username,
            $usernameLike,
            $module,
            $module,
            $tableName,
            $wpdb->users
        ));
        if (!empty($wpdb->last_error)) {
            return new WP_Error('queue_query_failed', __('Could not count queue records.', 'sk-user-sync-suitecrm'));
        }

        $total = (int) $total;
        $pages = (int) ceil($total / $perPage);
        $page = min($page, max(1, $pages));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT q.id, q.user_id, q.module, q.created_at
             FROM `%7\$s` q LEFT JOIN `%8\$s` u ON u.ID = q.user_id
             WHERE (%s = '' OR u.user_login LIKE %s) AND (%s = '' OR q.module = %s)
             ORDER BY q.id ASC LIMIT %d OFFSET %d",
            $username,
            $usernameLike,
            $module,
            $module,
            $perPage,
            ($page - 1) * $perPage,
            $tableName,
            $wpdb->users
        ));
        if (!empty($wpdb->last_error)) {
            return new WP_Error('queue_query_failed', __('Could not read queue records.', 'sk-user-sync-suitecrm'));
        }

        return [
            'items' => array_map([QueueItem::class, 'fromRow'], is_array($rows) ? $rows : []),
            'total_items' => $total,
            'total_pages' => $pages,
            'current_page' => $page,
        ];
    }

    /**
     * Atomic upsert into the queue.
     *
     * A profile edit coalesces into an existing entry, preserving its original created_at.
     *
     * @param int    $userId WordPress user ID.
     * @param string $module SuiteCRM module name (Accounts, Contacts, Leads, Users).
     * @return bool|WP_Error Returns true if created or coalesced, or WP_Error on failure.
     */
    public function enqueue(int $userId, string $module) {
        global $wpdb;

        if ($userId <= 0 || empty($module)) {
            return new WP_Error(
                'invalid_queue_input',
                __('Invalid user ID or module provided for queue enqueue.', 'sk-user-sync-suitecrm')
            );
        }

        $tableName = $this->getTableName();
        $createdAt = gmdate('Y-m-d H:i:s');

        $stmt = $wpdb->prepare("INSERT INTO `%4\$s` (user_id, module, created_at)
                VALUES (%d, %s, %s)
                ON DUPLICATE KEY UPDATE id = id", $userId, $module, $createdAt, $tableName);

        $result = $wpdb->query($stmt);

        if ($result === false || !empty($wpdb->last_error)) {
            return new WP_Error(
                'queue_enqueue_failed',
                sprintf(
                    /* translators: %s: Database error */
                    __('Failed to enqueue item in queue: %s', 'sk-user-sync-suitecrm'),
                    $wpdb->last_error
                )
            );
        }

        return true;
    }

    /**
     * Select a batch chunk between last queue ID and max queue ID.
     *
     * Used by nightly batch processing for stable ascending cursor traversal.
     *
     * @param int $lastQueueId Lower bound cursor (exclusive).
     * @param int $maxQueueId  Upper bound ID ceiling (inclusive).
     * @param int $limit       Max rows to fetch.
     * @return list<QueueItem>|WP_Error
     */
    public function selectBatchChunk(int $lastQueueId, int $maxQueueId, int $limit) {
        global $wpdb;

        $tableName = $this->getTableName();
        $limit     = max(1, $limit);

        $stmt = $wpdb->prepare("SELECT id, user_id, module, created_at
                FROM `%4\$s`
                WHERE id > %d AND id <= %d
                ORDER BY id ASC
                LIMIT %d", $lastQueueId, $maxQueueId, $limit, $tableName);

        $rows = $wpdb->get_results($stmt);

        if (!empty($wpdb->last_error)) {
            return new WP_Error(
                'queue_select_failed',
                sprintf(
                    /* translators: %s: Database error */
                    __('Database error selecting batch queue chunk: %s', 'sk-user-sync-suitecrm'),
                    $wpdb->last_error
                )
            );
        }

        if (!is_array($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = QueueItem::fromRow($row);
        }

        return $items;
    }

    /**
     * Select the oldest rows for real-time fallback processing.
     *
     * @param int $limit Max rows to fetch.
     * @return list<QueueItem>|WP_Error
     */
    public function selectRealtimeChunk(int $limit) {
        global $wpdb;

        $tableName = $this->getTableName();
        $limit     = max(1, $limit);

        $stmt = $wpdb->prepare("SELECT id, user_id, module, created_at
                FROM `%2\$s`
                ORDER BY id ASC
                LIMIT %d", $limit, $tableName);

        $rows = $wpdb->get_results($stmt);

        if (!empty($wpdb->last_error)) {
            return new WP_Error(
                'queue_select_failed',
                sprintf(
                    /* translators: %s: Database error */
                    __('Database error selecting real-time queue chunk: %s', 'sk-user-sync-suitecrm'),
                    $wpdb->last_error
                )
            );
        }

        if (!is_array($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = QueueItem::fromRow($row);
        }

        return $items;
    }

    /**
     * Get the highest existing queue row ID at the start of a batch pass.
     *
     * @return int|WP_Error Returns highest row ID, or 0 if queue is empty.
     */
    public function getMaxQueueId() {
        global $wpdb;

        $tableName = $this->getTableName();
        $maxId     = $wpdb->get_var($wpdb->prepare("SELECT MAX(id) FROM `%1\$s`", $tableName));

        if (!empty($wpdb->last_error)) {
            return new WP_Error(
                'queue_max_id_failed',
                sprintf(
                    /* translators: %s: Database error */
                    __('Database error querying max queue ID: %s', 'sk-user-sync-suitecrm'),
                    $wpdb->last_error
                )
            );
        }

        return $maxId !== null ? (int) $maxId : 0;
    }

    /**
     * Select items that have exceeded the stale expiration cutoff.
     *
     * Allows logging lifecycle failures prior to deletion.
     *
     * @param int $cutoffUtcSeconds Cutoff timestamp in UTC Unix seconds.
     * @param int $limit            Max rows to fetch.
     * @return list<QueueItem>|WP_Error
     */
    public function getExpiredItems(int $cutoffUtcSeconds, int $limit) {
        global $wpdb;

        $tableName      = $this->getTableName();
        $cutoffDatetime = gmdate('Y-m-d H:i:s', $cutoffUtcSeconds);
        $limit          = max(1, $limit);

        $stmt = $wpdb->prepare("SELECT id, user_id, module, created_at
                FROM `%3\$s`
                WHERE created_at <= %s
                ORDER BY id ASC
                LIMIT %d", $cutoffDatetime, $limit, $tableName);

        $rows = $wpdb->get_results($stmt);

        if (!empty($wpdb->last_error)) {
            return new WP_Error(
                'queue_expired_query_failed',
                sprintf(
                    /* translators: %s: Database error */
                    __('Database error querying expired queue items: %s', 'sk-user-sync-suitecrm'),
                    $wpdb->last_error
                )
            );
        }

        if (!is_array($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = QueueItem::fromRow($row);
        }

        return $items;
    }

    /**
     * Delete queue rows by primary key IDs.
     *
     * @param list<int> $queueIds List of primary key IDs.
     * @return int|WP_Error Count of removed rows.
     */
    public function deleteByIds(array $queueIds) {
        global $wpdb;

        $sanitizedIds = array_values(
            array_filter(
                array_map('intval', $queueIds),
                static function (int $id): bool {
                    return $id > 0;
                }
            )
        );

        if (empty($sanitizedIds)) {
            return 0;
        }

        $tableName    = $this->getTableName();
        $idList = $wpdb->prepare(
            implode(',', array_fill(0, count($sanitizedIds), '%d')),
            ...$sanitizedIds
        );
        $stmt = $wpdb->prepare(
            "DELETE FROM `%1\$s` WHERE id IN (%2\$s)", $tableName, $idList
        );

        $result = $wpdb->query($stmt);

        if ($result === false || !empty($wpdb->last_error)) {
            return new WP_Error(
                'queue_delete_failed',
                sprintf(
                    /* translators: %s: Database error */
                    __('Database error deleting queue rows: %s', 'sk-user-sync-suitecrm'),
                    $wpdb->last_error
                )
            );
        }

        return (int) $result;
    }

    /**
     * Get total number of outstanding items in the queue.
     *
     * @return int|WP_Error
     */
    public function count() {
        global $wpdb;

        $tableName = $this->getTableName();
        $count     = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `%1\$s`", $tableName));

        if (!empty($wpdb->last_error)) {
            return new WP_Error(
                'queue_count_failed',
                sprintf(
                    /* translators: %s: Database error */
                    __('Database error counting queue rows: %s', 'sk-user-sync-suitecrm'),
                    $wpdb->last_error
                )
            );
        }

        return (int) $count;
    }

    /**
     * Clear all rows from queue.
     *
     * @return int|WP_Error Number of cleared rows.
     */
    public function clearAll() {
        global $wpdb;

        $tableName = $this->getTableName();
        // Preserve auto-increment IDs: in-flight workers may still hold old row IDs.
        $result = $wpdb->query($wpdb->prepare("DELETE FROM `%1\$s`", $tableName));

        if ($result === false || !empty($wpdb->last_error)) {
            return new WP_Error(
                'queue_clear_failed',
                sprintf(
                    /* translators: %s: Database error */
                    __('Database error clearing queue: %s', 'sk-user-sync-suitecrm'),
                    $wpdb->last_error
                )
            );
        }

        return (int) $result;
    }
}
