<?php
/**
 * Outstanding queue table; variables supplied by Admin\QueuePage.
 *
 * @package SyncSuiteCRMUsers
 */

namespace SyncSuiteCRMUsers;

use SyncSuiteCRMUsers\Model\QueueItem;

defined('ABSPATH') || exit;

$pagination = $totalPages > 1 ? paginate_links([
    'base' => add_query_arg('paged', '%#%', add_query_arg([
        'page' => 'ssu-queue',
        'username' => $filterUsername,
        'module' => $filterModule,
        '_ssu_queue_nonce' => wp_create_nonce('ssu_queue_filters'),
    ], admin_url('admin.php'))),
    'format' => '',
    'prev_text' => '&laquo;',
    'next_text' => '&raquo;',
    'total' => $totalPages,
    'current' => $currentPage,
]) : '';
?>
<div class="wrap ssu-wrap ssu-queue-wrap">
    <h1><?php esc_html_e('SuiteCRM Sync — Queue', 'sync-suitecrm-users'); ?></h1>
    <div id="ssu-notice-container" aria-live="polite"></div>
    <p class="description">
        <?php esc_html_e('Pending automatic synchronization records, oldest first. Queued at is when the record first entered the queue. Times use the WordPress site timezone.', 'sync-suitecrm-users'); ?>
    </p>
    <p class="description">
        <?php esc_html_e('Expected runs are estimates; background processing and WP-Cron may run later. Removing queued records does not cancel work already in progress.', 'sync-suitecrm-users'); ?>
    </p>
    <?php if ($queryError !== null) : ?>
        <div class="notice notice-error inline"><p><?php echo esc_html($queryError); ?></p></div>
    <?php endif; ?>

    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
        <input type="hidden" name="page" value="ssu-queue">
        <?php wp_nonce_field('ssu_queue_filters', '_ssu_queue_nonce', false); ?>
        <div class="tablenav top">
            <div class="alignleft actions">
                <label for="ssu-queue-username" class="screen-reader-text"><?php esc_html_e('Filter by username', 'sync-suitecrm-users'); ?></label>
                <input type="text" name="username" id="ssu-queue-username" value="<?php echo esc_attr($filterUsername); ?>" placeholder="<?php esc_attr_e('Filter by username...', 'sync-suitecrm-users'); ?>">
                <label for="ssu-queue-module" class="screen-reader-text"><?php esc_html_e('Filter by module', 'sync-suitecrm-users'); ?></label>
                <select name="module" id="ssu-queue-module">
                    <option value="all" <?php selected($filterModule, 'all'); ?>><?php esc_html_e('All Modules', 'sync-suitecrm-users'); ?></option>
                    <?php foreach (QueueItem::ALLOWED_MODULES as $module) : ?>
                        <option value="<?php echo esc_attr($module); ?>" <?php selected($filterModule, $module); ?>><?php echo esc_html($module); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button"><?php esc_html_e('Filter', 'sync-suitecrm-users'); ?></button>
                <?php if ($filterUsername !== '' || $filterModule !== 'all') : ?>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ssu-queue')); ?>"><?php esc_html_e('Reset', 'sync-suitecrm-users'); ?></a>
                <?php endif; ?>
            </div>
        </div>
    </form>
    <div class="tablenav top">
        <div class="alignleft actions">
            <button type="button" class="button ssu-queue-delete" data-ids="<?php echo esc_attr(wp_json_encode($renderedIds)); ?>" <?php disabled(empty($renderedIds)); ?>>
                <?php esc_html_e('Delete records shown on this page', 'sync-suitecrm-users'); ?>
            </button>
            <button type="button" class="button button-link-delete" id="ssu-clear-queue" <?php disabled(!$canClear); ?>><?php esc_html_e('Clear all queue records', 'sync-suitecrm-users'); ?></button>
            <span class="spinner" id="ssu-queue-spinner" style="float: none;"></span>
        </div>
        <div class="tablenav-pages">
            <span class="displaying-num">
                <?php
                /* translators: %s: number of matching queue records. */
                printf(esc_html(_n('%s queue record', '%s queue records', $totalItems, 'sync-suitecrm-users')), esc_html(number_format_i18n($totalItems)));
                ?>
            </span>
            <?php echo wp_kses_post($pagination); ?>
        </div>
    </div>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('User', 'sync-suitecrm-users'); ?></th>
                <th scope="col"><?php esc_html_e('Module', 'sync-suitecrm-users'); ?></th>
                <th scope="col"><?php esc_html_e('Queued at', 'sync-suitecrm-users'); ?></th>
                <th scope="col"><?php esc_html_e('Expected run', 'sync-suitecrm-users'); ?></th>
                <th scope="col"><?php esc_html_e('Actions', 'sync-suitecrm-users'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)) : ?>
                <tr class="no-items"><td colspan="5"><?php esc_html_e('No queue records found.', 'sync-suitecrm-users'); ?></td></tr>
            <?php else : ?>
                <?php foreach ($items as $item) :
                    $user = get_userdata($item->getUserId());
                    $run = $expectedRuns[$item->getId()];
                    ?>
                    <tr>
                        <td>
                            <?php if ($user) : ?>
                                <a href="<?php echo esc_url(get_edit_user_link($item->getUserId())); ?>">#<?php echo esc_html($item->getUserId()); ?> <strong><?php echo esc_html($user->user_login); ?></strong></a>
                            <?php else : ?>
                                #<?php echo esc_html($item->getUserId()); ?> — <?php esc_html_e('User deleted', 'sync-suitecrm-users'); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($item->getModule()); ?></td>
                        <td><time datetime="<?php echo esc_attr(gmdate('c', $item->getCreatedAt())); ?>"><?php echo esc_html(wp_date('Y-m-d H:i:s', $item->getCreatedAt())); ?></time></td>
                        <td>
                            <?php if ($run['timestamp'] !== null) : ?>
                                <time datetime="<?php echo esc_attr(gmdate('c', $run['timestamp'])); ?>"><?php echo esc_html(wp_date('Y-m-d H:i:s', $run['timestamp'])); ?></time>
                                <?php if ($run['timestamp'] <= time()) : ?>
                                    <br><span class="description"><?php esc_html_e('Due now', 'sync-suitecrm-users'); ?></span>
                                <?php endif; ?>
                            <?php else : ?>
                                <?php echo esc_html($run['label']); ?>
                            <?php endif; ?>
                            <?php if ($run['fallback'] !== null) : ?>
                                <br><span class="description">
                                    <?php
                                    /* translators: %s: site-local date and time of the fallback run. */
                                    printf(esc_html__('Fallback: %s', 'sync-suitecrm-users'), esc_html(wp_date('Y-m-d H:i:s', $run['fallback'])));
                                    ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><button type="button" class="button-link-delete ssu-queue-delete" data-ids="<?php echo esc_attr(wp_json_encode([$item->getId()])); ?>" aria-label="<?php
                            /* translators: %d: queue record ID. */
                            echo esc_attr(sprintf(__('Delete queue record %d', 'sync-suitecrm-users'), $item->getId()));
                        ?>"><?php esc_html_e('Delete', 'sync-suitecrm-users'); ?></button></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php if ($pagination !== '') : ?>
        <div class="tablenav bottom"><div class="tablenav-pages"><?php echo wp_kses_post($pagination); ?></div></div>
    <?php endif; ?>
</div>
