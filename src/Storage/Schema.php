<?php
/**
 * Database schema definitions and migrations.
 *
 * Manages plugin custom database tables:
 * - {prefix}syus_queue: Automatic synchronization queue.
 * - {prefix}syus_activity_log: Synchronization audit activity log.
 *
 * @package SyncSuiteCRMUsers
 */

namespace SyncSuiteCRMUsers\Storage;

use SyncSuiteCRMUsers\Settings;

defined('ABSPATH') || exit;

class Schema {

    /**
     * Database schema version.
     */
    public const DB_VERSION = '1.0.0';

    /**
     * Base table names without prefix.
     */
    public const QUEUE_TABLE        = 'syus_queue';
    public const ACTIVITY_LOG_TABLE = 'syus_activity_log';

    /**
     * Get fully qualified queue table name.
     *
     * @return string
     */
    public static function getQueueTableName(): string {
        global $wpdb;
        return $wpdb->prefix . self::QUEUE_TABLE;
    }

    /**
     * Get fully qualified activity log table name.
     *
     * @return string
     */
    public static function getActivityLogTableName(): string {
        global $wpdb;
        return $wpdb->prefix . self::ACTIVITY_LOG_TABLE;
    }

    /**
     * Generate SQL definition for the queue table.
     *
     * Conforms strictly to WordPress dbDelta rules:
     * - Two spaces after PRIMARY KEY
     * - KEY on its own line
     * - Explicit field types
     *
     * @return string
     */
    public static function getQueueTableSql(): string {
        global $wpdb;

        $tableName      = self::getQueueTableName();
        $charsetCollate = $wpdb->get_charset_collate();

        return "CREATE TABLE {$tableName} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            module varchar(16) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_module (user_id, module),
            KEY created_at (created_at)
        ) {$charsetCollate};";
    }

    /**
     * Generate SQL definition for the activity log table.
     *
     * Conforms strictly to WordPress dbDelta rules:
     * - Two spaces after PRIMARY KEY
     * - KEY on its own line
     * - Explicit field types
     *
     * @return string
     */
    public static function getActivityLogTableSql(): string {
        global $wpdb;

        $tableName      = self::getActivityLogTableName();
        $charsetCollate = $wpdb->get_charset_collate();

        return "CREATE TABLE {$tableName} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            timestamp datetime NOT NULL,
            direction varchar(16) NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            username varchar(60) DEFAULT NULL,
            module varchar(16) NOT NULL,
            crm_record_id varchar(36) DEFAULT NULL,
            action varchar(16) NOT NULL,
            status varchar(16) NOT NULL,
            details text NOT NULL,
            PRIMARY KEY  (id),
            KEY timestamp_id (timestamp, id),
            KEY user_id (user_id),
            KEY username (username),
            KEY module (module),
            KEY status (status)
        ) {$charsetCollate};";
    }

    /**
     * Create or upgrade database tables using WordPress dbDelta.
     *
     * @return void
     */
    public static function createTables(): void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(self::getQueueTableSql());
        dbDelta(self::getActivityLogTableSql());
    }

    /**
     * Conditionally upgrade database tables if version has increased.
     *
     * @return void
     */
    public static function maybeUpgrade(): void {
        $installedVersion = get_option(Settings::OPTION_DB_VERSION, '0.0.0');

        if (version_compare((string) $installedVersion, self::DB_VERSION, '<')) {
            self::createTables();
            update_option(Settings::OPTION_DB_VERSION, self::DB_VERSION);
        }
    }

    /**
     * Drop plugin custom tables during uninstall.
     *
     * @return void
     */
    public static function dropTables(): void {
        global $wpdb;

        $queueTable    = self::getQueueTableName();
        $activityTable = self::getActivityLogTableName();

        // Numbered, backtick-quoted placeholders retain WordPress 5.3 support.
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS `%1$s`', $queueTable));
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS `%1$s`', $activityTable));
    }
}
