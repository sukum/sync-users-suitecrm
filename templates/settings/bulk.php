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
        <div id="syus-tab-bulk" class="syus-tab-content" style="display: none;" data-state="<?php echo esc_attr(wp_json_encode([
            'progress' => is_array($progress) ? $progress : null,
            'stale'    => $isProgressStale,
        ])); ?>">
            <h2><?php esc_html_e('Manual Bulk Synchronization', 'sync-users-suitecrm'); ?></h2>
            <p class="description">
                <?php esc_html_e('Synchronize all existing eligible WordPress users directly to enabled SuiteCRM modules in scheduled background chunks.', 'sync-users-suitecrm'); ?>
            </p>

            <div style="margin: 20px 0; display: flex; align-items: center; gap: 10px;">
                <button type="button" class="button button-secondary" id="syus-start-bulk-btn" <?php disabled(is_array($progress) && ($progress['status'] ?? '') === 'running' && !$isProgressStale); ?>>
                    <?php esc_html_e('Sync all existing users', 'sync-users-suitecrm'); ?>
                </button>

                <button type="button" class="button button-link-delete" id="syus-cancel-bulk-btn" style="<?php echo (is_array($progress) && ($progress['status'] ?? '') === 'running') ? '' : 'display:none;'; ?>">
                    <?php esc_html_e('Cancel sync', 'sync-users-suitecrm'); ?>
                </button>

                <span class="spinner" id="syus-bulk-spinner" style="float: none; margin: 0;"></span>
            </div>

            <!-- Bulk Progress Display Panel -->
            <div id="syus-bulk-panel" class="card" style="max-width: 650px; padding: 15px 20px; <?php echo is_array($progress) ? '' : 'display:none;'; ?>">
                <h3 style="margin-top: 0; display: flex; justify-content: space-between; align-items: center;">
                    <span><?php esc_html_e('Bulk Run Progress', 'sync-users-suitecrm'); ?></span>
                    <span id="syus-bulk-status-badge" style="font-size: 13px; font-weight: 600; padding: 2px 8px; border-radius: 3px; color: #fff; background-color: #2271b1;">
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
                    <div id="syus-bulk-progress-bar" style="background-color: #2271b1; height: 100%; width: <?php echo esc_attr($pct); ?>%; transition: width 0.3s ease;"></div>
                </div>

                <div id="syus-bulk-stale-notice" class="notice notice-warning inline" style="margin: 10px 0; <?php echo $isProgressStale ? '' : 'display:none;'; ?>">
                    <p><?php esc_html_e('Background bulk run appears stalled or interrupted (>60 seconds without worker update). You may start a new run.', 'sync-users-suitecrm'); ?></p>
                    <button type="button" class="button button-link-delete" id="syus-clear-bulk-btn" style="<?php echo $isProgressStale ? '' : 'display:none;'; ?>">
                        <?php esc_html_e('Clear sync', 'sync-users-suitecrm'); ?>
                    </button>

                </div>

                <table class="widefat" style="margin-top: 10px;">
                    <tbody>
                        <tr>
                            <td><strong><?php esc_html_e('Triggered:', 'sync-users-suitecrm'); ?></strong></td>
                            <td id="syus-stat-started-at">
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
                            <td><strong><?php esc_html_e('Run ID:', 'sync-users-suitecrm'); ?></strong></td>
                            <td id="syus-stat-run-id"><code><?php echo esc_html($progress['run_id'] ?? '—'); ?></code></td>
                            <td><strong><?php esc_html_e('Total Eligible Users:', 'sync-users-suitecrm'); ?></strong></td>
                            <td id="syus-stat-total-users"><?php echo esc_html($progress['total_users'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Processed Users:', 'sync-users-suitecrm'); ?></strong></td>
                            <td id="syus-stat-processed-users"><?php echo esc_html($progress['processed_users'] ?? 0); ?></td>
                            <td><strong><?php esc_html_e('Module Successes:', 'sync-users-suitecrm'); ?></strong></td>
                            <td id="syus-stat-successes" style="color: #00a32a;"><?php echo esc_html($progress['success_count'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Module Failures:', 'sync-users-suitecrm'); ?></strong></td>
                            <td id="syus-stat-failures" style="color: #d63638;"><?php echo esc_html($progress['failure_count'] ?? 0); ?></td>
                            <td><strong><?php esc_html_e('Module Skips:', 'sync-users-suitecrm'); ?></strong></td>
                            <td id="syus-stat-skips" style="color: #646970;"><?php echo esc_html($progress['skipped_count'] ?? 0); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

