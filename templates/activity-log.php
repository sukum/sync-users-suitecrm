<?php
/**
 * Template for SuiteCRM User Sync Activity Log page.
 *
 * Variables passed from ActivityLogPage:
 * - $items (list<ActivityLogEntry>): List of log entry objects.
 * - $totalItems (int): Total records matching current filters.
 * - $totalPages (int): Total pages.
 * - $currentPage (int): Current page number.
 * - $renderedIds (list<int>): List of exact displayed record IDs.
 * - $filterUsername (string): Current username filter value.
 * - $filterStatus (string): Current status filter value.
 * - $filterModule (string): Current module filter value.
 * - $nonce (string): Security nonce for AJAX actions.
 * - $queryError (string|null): Error message if query failed.
 *
 * @package SyncSuiteCRMUsers
 */

namespace SyncSuiteCRMUsers;

use SyncSuiteCRMUsers\Model\ActivityLogEntry;
use SyncSuiteCRMUsers\Storage\ActivityLogRepository;

defined('ABSPATH') || exit;

$statusColors = [
    'success' => '#00a32a', // WP green
    'failure' => '#d63638', // WP red
    'skipped' => '#787c82', // WP gray
];

$actionLabels = [
    'create' => __('Create', 'sync-suitecrm-users'),
    'update' => __('Update', 'sync-suitecrm-users'),
    'none'   => __('None', 'sync-suitecrm-users'),
    'expire' => __('Expire', 'sync-suitecrm-users'),
];

$pagination = $totalPages > 1 ? paginate_links([
    'base' => add_query_arg('paged', '%#%', add_query_arg([
        'page' => 'ssu-activity-log',
        'username' => $filterUsername,
        'status' => $filterStatus,
        'module' => $filterModule,
        '_ssu_activity_log_nonce' => wp_create_nonce('ssu_activity_log_filters'),
    ], admin_url('admin.php'))),
    'format' => '',
    'prev_text' => '&laquo;',
    'next_text' => '&raquo;',
    'total' => $totalPages,
    'current' => $currentPage,
]) : '';
?>

<div class="wrap ssu-wrap ssu-activity-log-wrap">
    <h1 class="wp-heading-inline"><?php echo esc_html__('SuiteCRM Sync — Activity Log', 'sync-suitecrm-users'); ?></h1>

    <div id="ssu-notice-container" style="margin-top: 10px;"></div>

    <?php if (!empty($queryError)) : ?>
        <div class="notice notice-error inline" style="margin-top: 15px;">
            <p><?php echo esc_html($queryError); ?></p>
        </div>
    <?php endif; ?>

    <!-- FILTER BAR -->
    <form method="get" action="" style="margin-top: 15px; margin-bottom: 15px;">
        <input type="hidden" name="page" value="ssu-activity-log">
        <?php wp_nonce_field('ssu_activity_log_filters', '_ssu_activity_log_nonce', false); ?>

        <div class="tablenav top wp-clearfix" style="padding: 0; margin: 0; clear: both;">
            <div class="alignleft actions">
                <!-- Username Search -->
                <label for="ssu-filter-username" class="screen-reader-text"><?php esc_html_e('Filter by Username', 'sync-suitecrm-users'); ?></label>
                <input type="text" name="username" id="ssu-filter-username" value="<?php echo esc_attr($filterUsername); ?>" placeholder="<?php esc_attr_e('Filter by username...', 'sync-suitecrm-users'); ?>" style="vertical-align: middle;">

                <!-- Status Filter -->
                <label for="ssu-filter-status" class="screen-reader-text"><?php esc_html_e('Filter by Status', 'sync-suitecrm-users'); ?></label>
                <select name="status" id="ssu-filter-status" style="vertical-align: middle;">
                    <option value="all" <?php selected($filterStatus, 'all'); ?>><?php esc_html_e('All Statuses', 'sync-suitecrm-users'); ?></option>
                    <option value="success" <?php selected($filterStatus, 'success'); ?>><?php esc_html_e('Success', 'sync-suitecrm-users'); ?></option>
                    <option value="failure" <?php selected($filterStatus, 'failure'); ?>><?php esc_html_e('Failure', 'sync-suitecrm-users'); ?></option>
                    <option value="skipped" <?php selected($filterStatus, 'skipped'); ?>><?php esc_html_e('Skipped', 'sync-suitecrm-users'); ?></option>
                </select>

                <!-- Module Filter -->
                <label for="ssu-filter-module" class="screen-reader-text"><?php esc_html_e('Filter by Module', 'sync-suitecrm-users'); ?></label>
                <select name="module" id="ssu-filter-module" style="vertical-align: middle;">
                    <option value="all" <?php selected($filterModule, 'all'); ?>><?php esc_html_e('All Modules', 'sync-suitecrm-users'); ?></option>
                    <?php foreach (['Accounts', 'Contacts', 'Leads', 'Users'] as $mod) : ?>
                        <option value="<?php echo esc_attr($mod); ?>" <?php selected($filterModule, $mod); ?>><?php echo esc_html($mod); ?></option>
                    <?php endforeach; ?>
                </select>

                <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'sync-suitecrm-users'); ?>" style="vertical-align: middle;">

                <?php if ($filterUsername !== '' || $filterStatus !== 'all' || $filterModule !== 'all') : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ssu-activity-log')); ?>" class="button" style="vertical-align: middle; margin-left: 5px;">
                        <?php esc_html_e('Reset', 'sync-suitecrm-users'); ?>
                    </a>
                <?php endif; ?>
            </div>

            <!-- ACTION BUTTONS: DELETE SHOWN / CLEAR ALL -->
            <div class="alignright actions">
                <button type="button" class="button button-secondary" id="ssu-delete-shown-logs-btn" data-ids="<?php echo esc_attr(wp_json_encode($renderedIds)); ?>" <?php disabled(empty($renderedIds)); ?>>
                    <?php esc_html_e('Delete records shown on this page', 'sync-suitecrm-users'); ?>
                </button>

                <button type="button" class="button button-link-delete" id="ssu-clear-all-logs-btn" style="margin-left: 8px;" <?php disabled($totalItems === 0); ?>>
                    <?php esc_html_e('Clear all logs', 'sync-suitecrm-users'); ?>
                </button>

                <span class="spinner" id="ssu-log-action-spinner" style="float: none; margin: 0 0 0 6px;"></span>
            </div>
        </div>
    </form>

    <!-- SUMMARY / TOTALS -->
    <div class="tablenav top" style="height: auto; margin-bottom: 8px;">
        <div class="tablenav-pages">
            <span class="displaying-num">
                <?php
                printf(
                    /* translators: %s: total record count */
                    esc_html(_n('%s log entry', '%s log entries', $totalItems, 'sync-suitecrm-users')),
                    esc_html(number_format_i18n($totalItems))
                );
                ?>
            </span>
            <?php echo wp_kses_post($pagination); ?>
        </div>
    </div>

    <!-- LOG TABLE -->
    <table class="wp-list-table widefat fixed striped" role="presentation">
        <thead>
            <tr>
                <th scope="col" style="width: 140px;"><?php esc_html_e('Time', 'sync-suitecrm-users'); ?></th>
                <th scope="col" style="width: 120px;"><?php esc_html_e('User', 'sync-suitecrm-users'); ?></th>
                <th scope="col" style="width: 110px;"><?php esc_html_e('Module', 'sync-suitecrm-users'); ?></th>
                <th scope="col" style="width: 80px;"><?php esc_html_e('Action', 'sync-suitecrm-users'); ?></th>
                <th scope="col" style="width: 95px;"><?php esc_html_e('Status', 'sync-suitecrm-users'); ?></th>
                <th scope="col" style="min-width: 120px; width: 100%;"><?php esc_html_e('Details', 'sync-suitecrm-users'); ?></th>
            </tr>
        </thead>
        <tbody id="ssu-activity-log-tbody">
            <?php if (empty($items)) : ?>
                <tr class="no-items">
                    <td class="colspanchange" colspan="7" style="text-align: center; padding: 25px; color: #646970;">
                        <?php esc_html_e('No activity log entries found.', 'sync-suitecrm-users'); ?>
                    </td>
                </tr>
            <?php else : ?>
                <?php foreach ($items as $entry) :
                    /** @var ActivityLogEntry $entry */
                    $status = $entry->getStatus();
                    $badgeBg = $statusColors[$status] ?? '#787c82';
                    $userId = $entry->getUserId();
                    $crmId = $entry->getCrmRecordId();
                    $timeFormatted = wp_date('Y-m-d H:i:s', $entry->getTimestamp());
                ?>
                    <tr id="ssu-log-row-<?php echo esc_attr((int) $entry->getId()); ?>">
                        <!-- 1. TIME -->
                        <td>
                            <time datetime="<?php echo esc_attr(gmdate('c', $entry->getTimestamp())); ?>" title="<?php echo esc_attr(gmdate('Y-m-d H:i:s \U\T\C', $entry->getTimestamp())); ?>">
                                <?php echo esc_html($timeFormatted); ?>
                            </time>
                        </td>

                        <!-- 2. USER ID -->
                        <!-- 3. USERNAME -->
                        <td>
                            <?php if ($userId !== null && $userId > 0) : ?>
                                <?php if (get_userdata($userId)) : ?>
                                    <a href="<?php echo esc_url(get_edit_user_link($userId)); ?>" title="<?php esc_attr_e('Edit WordPress User', 'sync-suitecrm-users'); ?>">
                                        #<?php echo esc_html($userId); ?>
                                    </a>
                                <?php else : ?>
                                    <span style="color: #646970;">#<?php echo esc_html($userId); ?></span>
                                <?php endif; ?>
                            <?php else : ?>
                                <span style="color: #a7aaad;">&mdash;</span>
                            <?php endif; ?>
                            <?php
                            $username = $entry->getUsername();
                            if ($username !== null && $username !== ''):
                            ?>
                            <strong><?php echo  esc_html($username); ?></strong>
                            <?php endif; ?>
                        </td>

                        <!-- 4. MODULE -->
                        <td>
                            <code><?php echo esc_html($entry->getModule()); ?></code>
                        </td>

                        <!-- 5. ACTION -->
                        <td>
                            <?php echo esc_html($actionLabels[$entry->getAction()] ?? ucfirst($entry->getAction())); ?>
                        </td>

                        <!-- 6. STATUS -->
                        <td>
                            <span style="display: inline-block; padding: 2px 7px; border-radius: 3px; color: #fff; font-size: 11px; font-weight: 600; text-transform: uppercase; background-color: <?php echo esc_attr($badgeBg); ?>;">
                                <?php echo esc_html($status); ?>
                            </span>
                        </td>

                        <!-- 7. DETAILS -->
                        <td>
                            <?php if (!empty($crmId)) : ?>
                                <span style="display: inline-block; margin-right: 8px; font-size: 12px;">
                                    <strong><?php esc_html_e('CRM ID:', 'sync-suitecrm-users'); ?></strong>
                                    <code><?php echo esc_html($crmId); ?></code>
                                </span>
                            <?php endif; ?>

                            <span style="display: inline-block; margin-right: 8px; color: #646970; font-size: 11px;">
                                (<?php echo esc_html($entry->getDirection()); ?>)
                            </span>

                            <span class="ssu-log-detail-text">
                                <?php echo esc_html($entry->getDetails()); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <th scope="col"><?php esc_html_e('Time', 'sync-suitecrm-users'); ?></th>
                <th scope="col"><?php esc_html_e('User', 'sync-suitecrm-users'); ?></th>
                <th scope="col"><?php esc_html_e('Module', 'sync-suitecrm-users'); ?></th>
                <th scope="col"><?php esc_html_e('Action', 'sync-suitecrm-users'); ?></th>
                <th scope="col"><?php esc_html_e('Status', 'sync-suitecrm-users'); ?></th>
                <th scope="col"><?php esc_html_e('Details', 'sync-suitecrm-users'); ?></th>
            </tr>
        </tfoot>
    </table>

    <!-- BOTTOM PAGINATION -->
    <?php if ($totalPages > 1) : ?>
        <div class="tablenav bottom" style="margin-top: 10px;">
            <div class="tablenav-pages">
                <?php echo wp_kses_post($pagination); ?>
            </div>
        </div>
    <?php endif; ?>
</div>
