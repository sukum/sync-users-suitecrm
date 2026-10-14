<?php
/**
 * Template for the SuiteCRM User Sync Settings page.
 *
 * Variables passed from SettingsPage:
 * - $settings (array): Validated syus_settings options.
 * - $connection (array): Safe syus_connection options (SafeConnectionSnapshot).
 * - $roles (array): WordPress role slugs and display names.
 * - $timezoneString (string): WordPress site timezone.
 * - $currentTime (string): Current local site time.
 * - $progress (array|null): syus_bulk_progress transient snapshot if any.
 * - $isProgressStale (bool): Whether the active bulk progress is stale (>60s).
 * - $nonce (string): Security nonce for AJAX actions.
 *
 * @package SyncUsersSuitecrm
 */

defined('ABSPATH') || exit;

$connStatus = $connection['connection_status'] ?? 'not_configured';
$statusLabels = [
    'connected'          => __('Connected', 'sk-user-sync-suitecrm'),
    'reconnect_required' => __('Reconnect Required', 'sk-user-sync-suitecrm'),
    'not_configured'     => __('Not Configured', 'sk-user-sync-suitecrm'),
];
$statusColors = [
    'connected'          => '#00a32a', // WP green
    'reconnect_required' => '#dba617', // WP warning/amber
    'not_configured'     => '#787c82', // WP gray
];
?>

<div id="syus-settings-page" class="wrap syus-wrap">
    <h1><?php echo esc_html__('SuiteCRM User Sync — Settings', 'sk-user-sync-suitecrm'); ?></h1>

    <div id="syus-notice-container"></div>

    <nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e('Settings Tabs', 'sk-user-sync-suitecrm'); ?>">
        <a href="#tab-general" class="nav-tab nav-tab-active" data-tab="general"><?php esc_html_e('General', 'sk-user-sync-suitecrm'); ?></a>
        <a href="#tab-authentication" class="nav-tab" data-tab="authentication"><?php esc_html_e('Authentication', 'sk-user-sync-suitecrm'); ?></a>
        <a href="#tab-modules" class="nav-tab" data-tab="modules"><?php esc_html_e('SuiteCRM Modules', 'sk-user-sync-suitecrm'); ?></a>
        <a href="#tab-bulk" class="nav-tab" data-tab="bulk"><?php esc_html_e('Bulk Synchronization', 'sk-user-sync-suitecrm'); ?></a>
    </nav>

    <form id="syus-settings-form" method="post" action="" style="margin-top: 20px;">
        <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">

        <?php
        include __DIR__ . '/settings/general.php';
        include __DIR__ . '/settings/authentication.php';
        include __DIR__ . '/settings/modules.php';
        include __DIR__ . '/settings/bulk.php';
        ?>

        <p class="submit" style="margin-top: 25px;">
            <button type="submit" class="button button-primary" id="syus-save-btn">
                <?php esc_html_e('Save Settings', 'sk-user-sync-suitecrm'); ?>
            </button>
            <span class="spinner" id="syus-save-spinner" style="float: none; margin: 0 0 0 8px;"></span>
        </p>
    </form>
</div>

