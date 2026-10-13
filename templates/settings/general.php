<?php
/**
 * General synchronization settings tab.
 *
 * Variables inherited from settings.php: $settings, $roles, $timezoneString, $currentTime.
 *
 * @package SyncSuiteCRMUsers
 */

defined('ABSPATH') || exit;
?>
        <!-- TAB 1: GENERAL SETTINGS -->
        <div id="ssu-tab-general" class="ssu-tab-content">
            <h2><?php esc_html_e('General Synchronization Settings', 'sync-suitecrm-users'); ?></h2>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Run Automatically', 'sync-suitecrm-users'); ?></th>
                        <td>
                            <fieldset>
                                <label for="auto_sync_enabled">
                                    <input type="checkbox" name="auto_sync_enabled" id="auto_sync_enabled" value="1" <?php checked($settings['auto_sync_enabled']); ?>>
                                    <?php esc_html_e('Enable automatic synchronization on WordPress user creation and updates', 'sync-suitecrm-users'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('Disabling pauses automatic queue processing; explicit manual synchronization actions remain available.', 'sync-suitecrm-users'); ?>
                                </p>
                            </fieldset>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Automatic Mode', 'sync-suitecrm-users'); ?></th>
                        <td>
                            <fieldset id="ssu-auto-mode-fieldset">
                                <label>
                                    <input type="radio" name="auto_sync_mode" value="realtime" <?php checked($settings['auto_sync_mode'], 'realtime'); ?>>
                                    <?php esc_html_e('Real time (Immediate asynchronous queue push with 5-minute fallback)', 'sync-suitecrm-users'); ?>
                                </label>
                                <br>
                                <label>
                                    <input type="radio" name="auto_sync_mode" value="batch" <?php checked($settings['auto_sync_mode'], 'batch'); ?>>
                                    <?php esc_html_e('Batches (Scheduled nightly batch execution)', 'sync-suitecrm-users'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>

                    <tr id="ssu-batch-time-row">
                        <th scope="row"><?php esc_html_e('Batch Execution Time', 'sync-suitecrm-users'); ?></th>
                        <td>
                            <fieldset>
                                <label for="batch_time_hour"><?php esc_html_e('Hour:', 'sync-suitecrm-users'); ?></label>
                                <select name="batch_time_hour" id="batch_time_hour">
                                    <?php for ($h = 0; $h <= 23; $h++) :
                                        $val = str_pad((string) $h, 2, '0', STR_PAD_LEFT);
                                    ?>
                                        <option value="<?php echo esc_attr($val); ?>" <?php selected($settings['batch_time_hour'], $val); ?>>
                                            <?php echo esc_html($val); ?>:00
                                        </option>
                                    <?php endfor; ?>
                                </select>

                                <label for="batch_time_minute" style="margin-left: 10px;"><?php esc_html_e('Minute:', 'sync-suitecrm-users'); ?></label>
                                <select name="batch_time_minute" id="batch_time_minute">
                                    <?php foreach (['00', '10', '20', '30', '40', '50'] as $min) : ?>
                                        <option value="<?php echo esc_attr($min); ?>" <?php selected($settings['batch_time_minute'], $min); ?>>
                                            :<?php echo esc_html($min); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: 1: site timezone, 2: current site local time */
                                        esc_html__('Configured in WordPress site timezone: %1$s (Current time: %2$s). Stored internally in UTC.', 'sync-suitecrm-users'),
                                        '<strong>' . esc_html($timezoneString) . '</strong>',
                                        '<code>' . esc_html($currentTime) . '</code>'
                                    );
                                    ?>
                                </p>
                            </fieldset>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Eligible Roles', 'sync-suitecrm-users'); ?></th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><span><?php esc_html_e('Eligible WordPress Roles', 'sync-suitecrm-users'); ?></span></legend>
                                <?php foreach ($roles as $roleSlug => $roleName) : ?>
                                    <label style="display: inline-block; margin-right: 15px; margin-bottom: 5px;">
                                        <input type="checkbox" name="eligible_roles[]" value="<?php echo esc_attr($roleSlug); ?>" <?php checked(in_array($roleSlug, $settings['eligible_roles'], true)); ?>>
                                        <?php echo esc_html($roleName); ?> (<code><?php echo esc_html($roleSlug); ?></code>)
                                    </label>
                                <?php endforeach; ?>
                                <p class="description">
                                    <?php esc_html_e('A user qualifies if any assigned role is selected. If no roles are selected, no users are eligible for synchronization.', 'sync-suitecrm-users'); ?>
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

