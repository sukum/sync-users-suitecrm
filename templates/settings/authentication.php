<?php
/**
 * SuiteCRM authentication settings tab.
 *
 * Variables inherited from settings.php: $connection, $connStatus, $statusLabels, $statusColors.
 *
 * @package SyncSuiteCRMUsers
 */

defined('ABSPATH') || exit;

$lastConnectedLabel = !empty($connection['last_connected_at']) ? sprintf(
    /* translators: %s: formatted date of last connection */
    __('Last successful handshake: %s', 'sync-suitecrm-users'),
    wp_date('Y-m-d H:i:s T', (int) $connection['last_connected_at'])
) : '';
?>
        <!-- TAB 2: AUTHENTICATION -->
        <div id="ssu-tab-authentication" class="ssu-tab-content" style="display: none;" data-state="<?php echo esc_attr(wp_json_encode([
            'connection_status'    => $connStatus,
            'original_base_url'    => $connection['base_url'],
            'last_connected_label' => $lastConnectedLabel,
            'last_error'           => $connection['last_error'] ?? '',
        ])); ?>">
            <h2><?php esc_html_e('SuiteCRM 7 REST V8 Authentication', 'sync-suitecrm-users'); ?></h2>

            <div class="notice notice-info inline" style="margin-bottom: 20px;">
                <p>
                    <strong><?php esc_html_e('Connection Status:', 'sync-suitecrm-users'); ?></strong>
                    <span id="ssu-connection-badge" style="display: inline-block; padding: 2px 8px; border-radius: 3px; color: #fff; background-color: <?php echo esc_attr($statusColors[$connStatus] ?? '#787c82'); ?>; font-weight: 600;">
                        <?php echo esc_html($statusLabels[$connStatus] ?? $connStatus); ?>
                    </span>
                    <span id="ssu-last-connected" style="margin-left: 10px; color: #50575e; <?php echo $lastConnectedLabel ? '' : 'display:none;'; ?>">
                        <?php echo esc_html($lastConnectedLabel); ?>
                    </span>
                </p>
                <p id="ssu-last-connection-error" style="color: #d63638; margin-top: 5px; <?php echo !empty($connection['last_error']) ? '' : 'display:none;'; ?>">
                    <strong><?php esc_html_e('Last error:', 'sync-suitecrm-users'); ?></strong>
                    <code><?php echo esc_html($connection['last_error'] ?? ''); ?></code>
                </p>
            </div>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="ssu_base_url"><?php esc_html_e('SuiteCRM Base URL', 'sync-suitecrm-users'); ?></label></th>
                        <td>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <input type="url" name="base_url" id="ssu_base_url" value="<?php echo esc_attr($connection['base_url']); ?>" class="regular-text" placeholder="https://suitecrm.example.com" style="width: 380px;">
                                <button type="button" class="button button-secondary" id="ssu-test-api-btn">
                                    <?php esc_html_e('Test API', 'sync-suitecrm-users'); ?>
                                </button>
                                <span class="spinner" id="ssu-test-api-spinner" style="float: none; margin: 0;"></span>
                            </div>
                            <span id="ssu-test-api-result" style="display: block; margin-top: 4px; font-weight: 500;"></span>
                            <p class="description">
                                <?php esc_html_e('Base URL of SuiteCRM instance without trailing slash (e.g. https://suitecrm7.example.top). "Test API" verifies server reachability.', 'sync-suitecrm-users'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="ssu_client_id"><?php esc_html_e('OAuth2 Client ID', 'sync-suitecrm-users'); ?></label></th>
                        <td>
                            <input type="text" name="client_id" id="ssu_client_id" value="<?php echo esc_attr($connection['client_id']); ?>" class="regular-text" placeholder="UUID from SuiteCRM OAuth2 Clients">
                            <p class="description">
                                <?php esc_html_e('Password-grant OAuth2 Client ID configured in SuiteCRM Admin -> OAuth2 Clients.', 'sync-suitecrm-users'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="ssu_client_secret"><?php esc_html_e('OAuth2 Client Secret', 'sync-suitecrm-users'); ?></label></th>
                        <td>
                            <input type="password" name="client_secret" id="ssu_client_secret" value="<?php echo $connection['has_client_secret'] ? esc_attr(\SyncSuiteCRMUsers\Settings::SECRET_MASK) : ''; ?>" class="regular-text" autocomplete="new-password">
                            <p class="description">
                                <?php esc_html_e('Leave masked to preserve existing saved secret. Mask characters are never saved.', 'sync-suitecrm-users'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="ssu_username"><?php esc_html_e('SuiteCRM API Username', 'sync-suitecrm-users'); ?></label></th>
                        <td>
                            <input type="text" name="username" id="ssu_username" value="<?php echo esc_attr($connection['username']); ?>" class="regular-text" autocomplete="username">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="ssu_password"><?php esc_html_e('SuiteCRM API Password', 'sync-suitecrm-users'); ?></label></th>
                        <td>
                            <input type="password" name="password" id="ssu_password" value="<?php echo $connection['has_password'] ? esc_attr(\SyncSuiteCRMUsers\Settings::SECRET_MASK) : ''; ?>" class="regular-text" autocomplete="current-password">
                            <p class="description">
                                <?php esc_html_e('Leave masked to preserve existing saved password.', 'sync-suitecrm-users'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Connect & Authenticate', 'sync-suitecrm-users'); ?></th>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <button type="button" class="button button-primary" id="ssu-connect-btn">
                                    <?php esc_html_e('Connect', 'sync-suitecrm-users'); ?>
                                </button>
                                <span class="spinner" id="ssu-connect-spinner" style="float: none; margin: 0;"></span>
                                <span id="ssu-connect-result" style="font-weight: 500;"></span>
                            </div>
                            <p class="description">
                                <?php esc_html_e('Obtains and stores an OAuth2 access token, validating credentials against SuiteCRM REST V8.', 'sync-suitecrm-users'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('CRM Migration Safeguard', 'sync-suitecrm-users'); ?></th>
                        <td>
                            <label for="ssu_same_crm">
                                <input type="checkbox" name="same_crm" id="ssu_same_crm" value="1">
                                <?php esc_html_e('Same CRM different URL (Preserve existing mappings and queue)', 'sync-suitecrm-users'); ?>
                            </label>
                            <p class="description" style="color: #646970;">
                                <?php esc_html_e('Leave unchecked when pointing to a different CRM instance. Unchecked URL changes purge outstanding queue items and clear all saved CRM record IDs in user metadata to prevent cross-instance collisions.', 'sync-suitecrm-users'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

