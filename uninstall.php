<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Explicit uninstall cleanup policy:
 * - Drop plugin custom tables ({prefix}ssu_queue, {prefix}ssu_activity_log).
 * - Remove plugin options (ssu_settings, ssu_connection, ssu_db_version, _ssu_lock_*).
 * - Remove plugin transients (ssu_bulk_progress).
 * - Remove user metadata (_ssu_crm_id_*).
 * - Clear all scheduled cron events.
 * - SuiteCRM records are NEVER deleted.
 *
 * @package SyncSuiteCRMUsers
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

/**
 * Perform single-site uninstall cleanup.
 *
 * @param \wpdb $wpdb
 */
function ssu_uninstall_site_cleanup(\wpdb $wpdb): void {
    // 1. Clear scheduled cron tasks
    $cronHooks = [
        'ssu_batch_sync_cron',
        'ssu_realtime_worker_cron',
        'ssu_manual_bulk_cron',
        'ssu_token_renewal_cron',
    ];
    foreach ($cronHooks as $hook) {
        wp_unschedule_hook($hook);
    }

    // 2. Drop custom tables
    $tables = [
        $wpdb->prefix . 'ssu_queue',
        $wpdb->prefix . 'ssu_activity_log',
    ];
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    // 3. Delete options
    delete_option('ssu_settings');
    delete_option('ssu_connection');
    delete_option('ssu_db_version');

    // Delete lock options
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('_ssu_lock_') . '%'
        )
    );

    // 4. Delete transients
    delete_transient('ssu_bulk_progress');
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_ssu_') . '%',
            $wpdb->esc_like('_transient_timeout_ssu_') . '%'
        )
    );

    // 5. Delete CRM record mappings in user metadata
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
            $wpdb->esc_like('_ssu_crm_id_') . '%'
        )
    );
}

// Handle multisite vs single-site
if (is_multisite()) {
    $blogIds = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
    $originalBlogId = get_current_blog_id();

    foreach ($blogIds as $blogId) {
        switch_to_blog((int) $blogId);
        ssu_uninstall_site_cleanup($wpdb);
    }

    switch_to_blog($originalBlogId);
} else {
    ssu_uninstall_site_cleanup($wpdb);
}
