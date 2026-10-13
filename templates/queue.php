<?php
/**
 * Outstanding queue table; variables supplied by Admin\QueuePage.
 *
 * @package SyncUsersSuitecrm
 */

namespace SyncUsersSuitecrm;

use SyncUsersSuitecrm\Model\QueueItem;

defined('ABSPATH') || exit;

$pagination = $totalPages > 1 ? paginate_links([
    'base' => add_query_arg('paged', '%#%', add_query_arg([
        'page' => 'syus-queue',
        'username' => $filterUsername,
        'module' => $filterModule,
        '_syus_queue_nonce' => wp_create_nonce('syus_queue_filters'),
    ], admin_url('admin.php'))),
    'format' => '',
    'prev_text' => '&laquo;',
    'next_text' => '&raquo;',
    'total' => $totalPages,
    'current' => $currentPage,
]) : '';
?>
<div class="wrap syus-wrap syus-queue-wrap">
    <h1><?php esc_html_e('SuiteCRM Sync — Queue', 'sync-users-suitecrm'); ?></h1>
    <div id="syus-notice-container" aria-live="polite"></div>
    <p class="description">
        <?php esc_html_e('Pending automatic synchronization records, oldest first. Queued at is when the record first entered the queue. Times use the WordPress site timezone.', 'sync-users-suitecrm'); ?>
    </p>
    <p class="description">
        <?php esc_html_e('Expected runs are estimates; background processing and WP-Cron may run later. Removing queued records does not cancel work already in progress.', 'sync-users-suitecrm'); ?>
    </p>
    <?php if ($queryError !== null) : ?>
        <div class="notice notice-error inline"><p><?php echo esc_html($queryError); ?></p></div>
    <?php endif; ?>

    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
        <input type="hidden" name="page" value="syus-queue">
        <?php wp_nonce_field('syus_queue_filters', '_syus_queue_nonce', false); ?>
        <div class="tablenav top">
            <div class="alignleft actions">
                <label for="syus-queue-username" class="screen-reader-text"><?php esc_html_e('Filter by username', 'sync-users-suitecrm'); ?></label>
                <input type="text" name="username" id="syus-queue-username" value="<?php echo esc_attr($filterUsername); ?>" placeholder="<?php esc_attr_e('Filter by username...', 'sync-users-suitecrm'); ?>">
                <label for="syus-queue-module" class="screen-reader-text"><?php esc_html_e('Filter by module', 'sync-users-suitecrm'); ?></label>
                <select name="module" id="syus-queue-module">
                    <option value="all" <?php selected($filterModule, 'all'); ?>><?php esc_html_e('All Modules', 'sync-users-suitecrm'); ?></option>
                    <?php foreach (QueueItem::ALLOWED_MODULES as $module) : ?>
                        <option value="<?php echo esc_attr($module); ?>" <?php selected($filterModule, $module); ?>><?php echo esc_html($module); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button"><?php esc_html_e('Filter', 'sync-users-suitecrm'); ?></button>
                <?php if ($filterUsername !== '' || $filterModule !== 'all') : ?>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=syus-queue')); ?>"><?php esc_html_e('Reset', 'sync-users-suitecrm'); ?></a>
                <?php endif; ?>
            </div>
        </div>
    </form>
    <div class="tablenav top">
        <div class="alignleft actions">
            <button type="button" class="button syus-queue-delete" data-ids="<?php echo esc_attr(wp_json_encode($renderedIds)); ?>" <?php disabled(empty($renderedIds)); ?>>
                <?php esc_html_e('Delete records shown on this page', 'sync-users-suitecrm'); ?>
            </button>
            <button type="button" class="button button-link-delete" id="syus-clear-queue" <?php disabled(!$canClear); ?>><?php esc_html_e('Clear all queue records', 'sync-users-suitecrm'); ?></button>
            <span class="spinner" id="syus-queue-spinner" style="float: none;"></span>
        </div>
        <div class="tablenav-pages">
            <span class="displaying-num">
                <?php
                /* translators: %s: number of matching queue records. */
                printf(esc_html(_n('%s queue record', '%s queue records', $totalItems, 'sync-users-suitecrm')), esc_html(number_format_i18n($totalItems)));
                ?>
            </span>
            <?php echo wp_kses_post($pagination); ?>
        </div>
    </div>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('User', 'sync-users-suitecrm'); ?></th>
                <th scope="col"><?php esc_html_e('Module', 'sync-users-suitecrm'); ?></th>
                <th scope="col"><?php esc_html_e('Queued at', 'sync-users-suitecrm'); ?></th>
                <th scope="col"><?php esc_html_e('Expected run', 'sync-users-suitecrm'); ?></th>
                <th scope="col"><?php esc_html_e('Actions', 'sync-users-suitecrm'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)) : ?>
                <tr class="no-items"><td colspan="5"><?php esc_html_e('No queue records found.', 'sync-users-suitecrm'); ?></td></tr>
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
                                #<?php echo esc_html($item->getUserId()); ?> — <?php esc_html_e('User deleted', 'sync-users-suitecrm'); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($item->getModule()); ?></td>
                        <td><time datetime="<?php echo esc_attr(gmdate('c', $item->getCreatedAt())); ?>"><?php echo esc_html(wp_date('Y-m-d H:i:s', $item->getCreatedAt())); ?></time></td>
                        <td>
                            <?php if ($run['timestamp'] !== null) : ?>
                                <time datetime="<?php echo esc_attr(gmdate('c', $run['timestamp'])); ?>"><?php echo esc_html(wp_date('Y-m-d H:i:s', $run['timestamp'])); ?></time>
                                <?php if ($run['timestamp'] <= time()) : ?>
                                    <br><span class="description"><?php esc_html_e('Due now', 'sync-users-suitecrm'); ?></span>
                                <?php endif; ?>
                            <?php else : ?>
                                <?php echo esc_html($run['label']); ?>
                            <?php endif; ?>
                            <?php if ($run['fallback'] !== null) : ?>
                                <br><span class="description">
                                    <?php
                                    /* translators: %s: site-local date and time of the fallback run. */
                                    printf(esc_html__('Fallback: %s', 'sync-users-suitecrm'), esc_html(wp_date('Y-m-d H:i:s', $run['fallback'])));
                                    ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><button type="button" class="button-link-delete syus-queue-delete" data-ids="<?php echo esc_attr(wp_json_encode([$item->getId()])); ?>" aria-label="<?php
                            /* translators: %d: queue record ID. */
                            echo esc_attr(sprintf(__('Delete queue record %d', 'sync-users-suitecrm'), $item->getId()));
                        ?>"><?php esc_html_e('Delete', 'sync-users-suitecrm'); ?></button></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php if ($pagination !== '') : ?>
        <div class="tablenav bottom"><div class="tablenav-pages"><?php echo wp_kses_post($pagination); ?></div></div>
    <?php endif; ?>
</div>
