<?php
/**
 * Activity log repository.
 *
 * Owns insertion, querying with filtering/pagination, deletion, and truncation
 * for the synchronization audit activity log.
 *
 * @package SyncSuiteCRMUsers
 */

namespace SyncSuiteCRMUsers\Storage;

use SyncSuiteCRMUsers\Model\ActivityLogEntry;
use WP_Error;

defined('ABSPATH') || exit;

class ActivityLogRepository {

    public const ALLOWED_STATUSES = [
        'all',
        'success',
        'failure',
        'skipped',
    ];

    public const ALLOWED_MODULES = [
        'all',
        'Accounts',
        'Contacts',
        'Leads',
        'Users',
    ];

    public const ALLOWED_ORDERBY = [
        'timestamp',
        'id',
    ];

    public const ALLOWED_ORDER = [
        'ASC',
        'DESC',
    ];

    /**
     * Get the activity log table name.
     *
     * @return string
     */
    public function getTableName(): string {
        return Schema::getActivityLogTableName();
    }

    /**
     * Persist an activity log entry.
     *
     * The entry is passive and append-only.
     * Insertion returns the auto-increment primary key ID without mutating the entry.
     *
     * @param ActivityLogEntry $entry
     * @return int|WP_Error Auto-increment primary key ID on success, or WP_Error on failure.
     */
    public function insert(ActivityLogEntry $entry) {
        global $wpdb;

        $tableName = $this->getTableName();

        $data = [
            'timestamp'     => gmdate('Y-m-d H:i:s', $entry->getTimestamp()),
            'direction'     => $entry->getDirection(),
            'user_id'       => $entry->getUserId(),
            'username'      => $entry->getUsername(),
            'module'        => $entry->getModule(),
            'crm_record_id' => $entry->getCrmRecordId(),
            'action'        => $entry->getAction(),
            'status'        => $entry->getStatus(),
            'details'       => $entry->getDetails(),
        ];

        $format = [
            '%s',
            '%s',
            $entry->getUserId() !== null ? '%d' : null,
            $entry->getUsername() !== null ? '%s' : null,
            '%s',
            $entry->getCrmRecordId() !== null ? '%s' : null,
            '%s',
            '%s',
            '%s',
        ];

        $result = $wpdb->insert($tableName, $data, $format);

        if ($result === false || !empty($wpdb->last_error)) {
            return new WP_Error(
                'activity_log_insert_failed',
                sprintf(
                    /* translators: %s: Database error */
                    __('Database error inserting activity log entry: %s', 'sync-users-suitecrm'),
                    $wpdb->last_error
                )
            );
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Query activity log entries with filtering and pagination.
     *
     * @param array{
     *     username?: string,
     *     status?: string,
     *     module?: string,
     *     page?: int,
     *     per_page?: int,
     *     orderby?: string,
     *     order?: string
     * } $args
     * @return array{
     *     items: list<ActivityLogEntry>,
     *     total_items: int,
     *     total_pages: int,
     *     current_page: int
     * }|WP_Error
     */
    public function query(array $args = []) {
        global $wpdb;

        $tableName = $this->getTableName();

        // 1. Sanitize pagination and sorting parameters
        $page    = isset($args['page']) ? max(1, (int) $args['page']) : 1;
        $perPage = isset($args['per_page']) ? max(1, min(100, (int) $args['per_page'])) : 20;
        $offset  = ($page - 1) * $perPage;

        $orderby = isset($args['orderby']) && in_array($args['orderby'], self::ALLOWED_ORDERBY, true)
            ? $args['orderby']
            : 'timestamp';

        $order = isset($args['order']) && in_array(strtoupper((string) $args['order']), self::ALLOWED_ORDER, true)
            ? strtoupper((string) $args['order'])
            : 'DESC';

        // 2. Normalize optional filters; bind every value through prepare().
        $username = !empty($args['username']) && is_string($args['username'])
            ? trim($args['username'])
            : '';
        $usernameLike = '%' . $wpdb->esc_like($username) . '%';
        $status = isset($args['status']) && $args['status'] !== 'all' && in_array($args['status'], self::ALLOWED_STATUSES, true)
            ? $args['status']
            : '';
        $module = isset($args['module']) && $args['module'] !== 'all' && in_array($args['module'], self::ALLOWED_MODULES, true)
            ? $args['module']
            : '';

        // 3. Count total records matching criteria
        // Keep value arguments first when mixing legacy numbered table placeholders.
        $totalItems = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `%7\$s`
             WHERE (%s = '' OR username LIKE %s)
               AND (%s = '' OR status = %s) AND (%s = '' OR module = %s)",
            $username,
            $usernameLike,
            $status,
            $status,
            $module,
            $module,
            $tableName
        ));

        if (!empty($wpdb->last_error)) {
            return new WP_Error(
                'activity_log_query_failed',
                sprintf(
                    /* translators: %s: Database error */
                    __('Database error counting activity log records: %s', 'sync-users-suitecrm'),
                    $wpdb->last_error
                )
            );
        }

        $totalItems = $totalItems !== null ? (int) $totalItems : 0;
        $totalPages = $totalItems > 0 ? (int) ceil($totalItems / $perPage) : 0;

        // If requested page is beyond total pages and totalItems > 0
        if ($totalPages > 0 && $page > $totalPages) {
            $page   = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        // 4. Query matching records
        $orderClause = ($orderby === 'timestamp')
            ? "timestamp {$order}, id {$order}"
            : "id {$order}";

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, timestamp, direction, user_id, username, module, crm_record_id, action, status, details
             FROM `%9\$s`
             WHERE (%s = '' OR username LIKE %s)
               AND (%s = '' OR status = %s) AND (%s = '' OR module = %s)
             ORDER BY %10\$s LIMIT %d OFFSET %d",
            $username,
            $usernameLike,
            $status,
            $status,
            $module,
            $module,
            $perPage,
            $offset,
            $tableName,
            $orderClause            
        ));

        if (!empty($wpdb->last_error)) {
            return new WP_Error(
                'activity_log_query_failed',
                sprintf(
                    /* translators: %s: Database error */
                    __('Database error querying activity log records: %s', 'sync-users-suitecrm'),
                    $wpdb->last_error
                )
            );
        }

        $items = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $items[] = ActivityLogEntry::fromRow($row);
            }
        }

        return [
            'items'        => $items,
            'total_items'  => $totalItems,
            'total_pages'  => $totalPages,
            'current_page' => $page,
        ];
    }

    /**
     * Delete activity log rows by exact displayed primary key IDs.
     *
     * @param list<int> $ids
     * @return int|WP_Error Number of deleted records.
     */
    public function deleteByIds(array $ids) {
        global $wpdb;

        $sanitizedIds = array_values(
            array_filter(
                array_map('intval', $ids),
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
        $stmt = $wpdb->prepare("DELETE FROM `%1\$s` WHERE id IN (%2\$s)", $tableName, $idList);
        $result = $wpdb->query($stmt);

        if ($result === false || !empty($wpdb->last_error)) {
            return new WP_Error(
                'activity_log_delete_failed',
                sprintf(
                    /* translators: %s: Database error */
                    __('Database error deleting activity log rows: %s', 'sync-users-suitecrm'),
                    $wpdb->last_error
                )
            );
        }

        return (int) $result;
    }

    /**
     * Clear (truncate) all activity log rows.
     *
     * @return int|WP_Error Number of cleared records.
     */
    public function clearAll() {
        global $wpdb;

        $tableName = $this->getTableName();
        $count     = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `%1$s`', $tableName));

        if (!empty($wpdb->last_error)) {
            return new WP_Error(
                'activity_log_clear_failed',
                sprintf(
                    /* translators: %s: Database error */
                    __('Database error counting activity log rows for clearing: %s', 'sync-users-suitecrm'),
                    $wpdb->last_error
                )
            );
        }

        $totalCount = $count !== null ? (int) $count : 0;

        $result = $wpdb->query($wpdb->prepare('TRUNCATE TABLE `%1$s`', $tableName));

        if ($result === false || !empty($wpdb->last_error)) {
            return new WP_Error(
                'activity_log_clear_failed',
                sprintf(
                    /* translators: %s: Database error */
                    __('Database error truncating activity log: %s', 'sync-users-suitecrm'),
                    $wpdb->last_error
                )
            );
        }

        return $totalCount;
    }
}
