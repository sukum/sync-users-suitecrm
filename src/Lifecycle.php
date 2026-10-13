<?php
/**
 * Plugin Lifecycle handler.
 *
 * Handles activation, deactivation, and database schema migrations.
 *
 * @package SyncSuiteCRMUsers
 */

namespace SyncSuiteCRMUsers;

use SyncSuiteCRMUsers\Storage\Schema;

defined('ABSPATH') || exit;

class Lifecycle {

    /**
     * Database schema version.
     */
    public const DB_VERSION = '1.0.0';

    /**
     * Plugin activation hook callback.
     *
     * @return void
     */
    public static function activate(): void {
        self::createTables();
        self::initDefaultOptions();

        // Update stored DB version
        update_option(Settings::OPTION_DB_VERSION, self::DB_VERSION);
    }

    /**
     * Plugin deactivation hook callback.
     *
     * Halts scheduled processing without deleting any data or CRM records.
     *
     * @return void
     */
    public static function deactivate(): void {
        $cronHooks = [
            'syus_batch_sync_cron',
            'syus_realtime_worker_cron',
            'syus_manual_bulk_cron',
            'syus_token_renewal_cron',
        ];

        foreach ($cronHooks as $hook) {
            wp_unschedule_hook($hook);
        }
    }

    /**
     * Run schema migrations if stored version does not match current version.
     *
     * @return void
     */
    public static function maybeUpgradeSchema(): void {
        Schema::maybeUpgrade();
    }

    /**
     * Create or upgrade plugin database tables using dbDelta.
     *
     * @return void
     */
    public static function createTables(): void {
        Schema::createTables();
    }

    /**
     * Initialize default options if not previously stored.
     *
     * @return void
     */
    private static function initDefaultOptions(): void {
        $settings = new Settings();

        if (get_option(Settings::OPTION_SETTINGS) === false) {
            add_option(Settings::OPTION_SETTINGS, $settings->getDefaultSettings(), '', 'yes');
        }

        if (get_option(Settings::OPTION_CONNECTION) === false) {
            add_option(Settings::OPTION_CONNECTION, $settings->getDefaultConnection(), '', 'no');
        }

        if (get_option(Settings::OPTION_DB_VERSION) === false) {
            add_option(Settings::OPTION_DB_VERSION, self::DB_VERSION, '', 'yes');
        }
    }
}
