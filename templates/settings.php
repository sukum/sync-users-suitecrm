<?php
/**
 * Template for the SuiteCRM User Sync Settings page.
 *
 * Variables passed from SettingsPage:
 * - $settings (array): Validated ssu_settings options.
 * - $connection (array): Safe ssu_connection options (SafeConnectionSnapshot).
 * - $roles (array): WordPress role slugs and display names.
 * - $timezoneString (string): WordPress site timezone.
 * - $currentTime (string): Current local site time.
 * - $progress (array|null): ssu_bulk_progress transient snapshot if any.
 * - $isProgressStale (bool): Whether the active bulk progress is stale (>60s).
 * - $nonce (string): Security nonce for AJAX actions.
 *
 * @package SyncSuiteCRMUsers
 */

defined('ABSPATH') || exit;

$connStatus = $connection['connection_status'] ?? 'not_configured';
$statusLabels = [
    'connected'          => __('Connected', 'sync-suitecrm-users'),
    'reconnect_required' => __('Reconnect Required', 'sync-suitecrm-users'),
    'not_configured'     => __('Not Configured', 'sync-suitecrm-users'),
];
$statusColors = [
    'connected'          => '#00a32a', // WP green
    'reconnect_required' => '#dba617', // WP warning/amber
    'not_configured'     => '#787c82', // WP gray
];
?>

<div id="ssu-settings-page" class="wrap ssu-wrap">
    <h1><?php echo esc_html__('SuiteCRM User Sync — Settings', 'sync-suitecrm-users'); ?></h1>

    <div id="ssu-notice-container"></div>

    <nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e('Settings Tabs', 'sync-suitecrm-users'); ?>">
        <a href="#tab-general" class="nav-tab nav-tab-active" data-tab="general"><?php esc_html_e('General', 'sync-suitecrm-users'); ?></a>
        <a href="#tab-authentication" class="nav-tab" data-tab="authentication"><?php esc_html_e('Authentication', 'sync-suitecrm-users'); ?></a>
        <a href="#tab-modules" class="nav-tab" data-tab="modules"><?php esc_html_e('SuiteCRM Modules', 'sync-suitecrm-users'); ?></a>
        <a href="#tab-bulk" class="nav-tab" data-tab="bulk"><?php esc_html_e('Bulk Synchronization', 'sync-suitecrm-users'); ?></a>
    </nav>

    <form id="ssu-settings-form" method="post" action="" style="margin-top: 20px;">
        <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">

        <?php
        include __DIR__ . '/settings/general.php';
        include __DIR__ . '/settings/authentication.php';
        include __DIR__ . '/settings/modules.php';
        include __DIR__ . '/settings/bulk.php';
        ?>

        <p class="submit" style="margin-top: 25px;">
            <button type="submit" class="button button-primary" id="ssu-save-btn">
                <?php esc_html_e('Save Settings', 'sync-suitecrm-users'); ?>
            </button>
            <span class="spinner" id="ssu-save-spinner" style="float: none; margin: 0 0 0 8px;"></span>
        </p>
    </form>
</div>

