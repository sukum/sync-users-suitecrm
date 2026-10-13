<?php
/**
 * Bulk synchronization settings tab.
 *
 * Variables inherited from settings.php: $progress, $isProgressStale.
 *
 * @package SyncSuiteCRMUsers
 */

defined('ABSPATH') || exit;
?>
        <!-- TAB 4: BULK SYNCHRONIZATION -->
        <div id="ssu-tab-bulk" class="ssu-tab-content" style="display: none;" data-state="<?php echo esc_attr(wp_json_encode([
            'progress' => is_array($progress) ? $progress : null,
            'stale'    => $isProgressStale,
        ])); ?>">
            <h2><?php esc_html_e('Manual Bulk Synchronization', 'sync-suitecrm-users'); ?></h2>
            <p class="description">
                <?php esc_html_e('Synchronize all existing eligible WordPress users directly to enabled SuiteCRM modules in scheduled background chunks.', 'sync-suitecrm-users'); ?>
            </p>

            <div style="margin: 20px 0; display: flex; align-items: center; gap: 10px;">
                <button type="button" class="button button-secondary" id="ssu-start-bulk-btn" <?php disabled(is_array($progress) && ($progress['status'] ?? '') === 'running' && !$isProgressStale); ?>>
                    <?php esc_html_e('Sync all existing users', 'sync-suitecrm-users'); ?>
                </button>

                <button type="button" class="button button-link-delete" id="ssu-cancel-bulk-btn" style="<?php echo (is_array($progress) && ($progress['status'] ?? '') === 'running') ? '' : 'display:none;'; ?>">
                    <?php esc_html_e('Cancel sync', 'sync-suitecrm-users'); ?>
                </button>

                <span class="spinner" id="ssu-bulk-spinner" style="float: none; margin: 0;"></span>
            </div>

            <!-- Bulk Progress Display Panel -->
            <div id="ssu-bulk-panel" class="card" style="max-width: 650px; padding: 15px 20px; <?php echo is_array($progress) ? '' : 'display:none;'; ?>">
                <h3 style="margin-top: 0; display: flex; justify-content: space-between; align-items: center;">
                    <span><?php esc_html_e('Bulk Run Progress', 'sync-suitecrm-users'); ?></span>
                    <span id="ssu-bulk-status-badge" style="font-size: 13px; font-weight: 600; padding: 2px 8px; border-radius: 3px; color: #fff; background-color: #2271b1;">
                        <?php echo is_array($progress) ? esc_html(strtoupper($progress['status'] ?? 'IDLE')) : 'IDLE'; ?>
                    </span>
                </h3>

                <!-- Progress Bar -->
                <?php
                $pct = 0;
                if (is_array($progress) && !empty($progress['total_users'])) {
                    $pct = min(100, (int) round(($progress['processed_users'] / $progress['total_users']) * 100));
                }
                ?>
                <div style="background-color: #dcdcde; height: 16px; border-radius: 8px; overflow: hidden; margin: 12px 0;">
                    <div id="ssu-bulk-progress-bar" style="background-color: #2271b1; height: 100%; width: <?php echo esc_attr($pct); ?>%; transition: width 0.3s ease;"></div>
                </div>

                <div id="ssu-bulk-stale-notice" class="notice notice-warning inline" style="margin: 10px 0; <?php echo $isProgressStale ? '' : 'display:none;'; ?>">
                    <p><?php esc_html_e('Background bulk run appears stalled or interrupted (>60 seconds without worker update). You may start a new run.', 'sync-suitecrm-users'); ?></p>
                    <button type="button" class="button button-link-delete" id="ssu-clear-bulk-btn" style="<?php echo $isProgressStale ? '' : 'display:none;'; ?>">
                        <?php esc_html_e('Clear sync', 'sync-suitecrm-users'); ?>
                    </button>

                </div>

                <table class="widefat" style="margin-top: 10px;">
                    <tbody>
                        <tr>
                            <td><strong><?php esc_html_e('Triggered:', 'sync-suitecrm-users'); ?></strong></td>
                            <td id="ssu-stat-started-at">
                                <code>
                                    <?php echo esc_html(
                                        $progress['started_at'] && is_numeric($progress['started_at']) ? 
                                        wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $progress['started_at'] ) : '—'
                                    );?>
                                </code>
                            </td>
                            <td></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Run ID:', 'sync-suitecrm-users'); ?></strong></td>
                            <td id="ssu-stat-run-id"><code><?php echo esc_html($progress['run_id'] ?? '—'); ?></code></td>
                            <td><strong><?php esc_html_e('Total Eligible Users:', 'sync-suitecrm-users'); ?></strong></td>
                            <td id="ssu-stat-total-users"><?php echo esc_html($progress['total_users'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Processed Users:', 'sync-suitecrm-users'); ?></strong></td>
                            <td id="ssu-stat-processed-users"><?php echo esc_html($progress['processed_users'] ?? 0); ?></td>
                            <td><strong><?php esc_html_e('Module Successes:', 'sync-suitecrm-users'); ?></strong></td>
                            <td id="ssu-stat-successes" style="color: #00a32a;"><?php echo esc_html($progress['success_count'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Module Failures:', 'sync-suitecrm-users'); ?></strong></td>
                            <td id="ssu-stat-failures" style="color: #d63638;"><?php echo esc_html($progress['failure_count'] ?? 0); ?></td>
                            <td><strong><?php esc_html_e('Module Skips:', 'sync-suitecrm-users'); ?></strong></td>
                            <td id="ssu-stat-skips" style="color: #646970;"><?php echo esc_html($progress['skipped_count'] ?? 0); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

