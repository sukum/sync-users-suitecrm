<?php
/**
 * SuiteCRM authentication settings tab.
 *
 * Variables inherited from settings.php: $connection, $connStatus, $statusLabels, $statusColors.
 *
 * @package SyncUsersSuitecrm
 */

defined('ABSPATH') || exit;

$lastConnectedLabel = !empty($connection['last_connected_at']) ? sprintf(
    /* translators: %s: formatted date of last connection */
    __('Last successful handshake: %s', 'sk-user-sync-suitecrm'),
    wp_date('Y-m-d H:i:s T', (int) $connection['last_connected_at'])
) : '';
?>
        <!-- TAB 2: AUTHENTICATION -->
        <div id="syus-tab-authentication" class="syus-tab-content" style="display: none;" data-state="<?php echo esc_attr(wp_json_encode([
            'connection_status'    => $connStatus,
            'original_base_url'    => $connection['base_url'],
            'last_connected_label' => $lastConnectedLabel,
            'last_error'           => $connection['last_error'] ?? '',
        ])); ?>">
            <h2><?php esc_html_e('SuiteCRM 7 REST V8 Authentication', 'sk-user-sync-suitecrm'); ?></h2>

            <div class="notice notice-info inline" style="margin-bottom: 20px;">
                <p>
                    <strong><?php esc_html_e('Connection Status:', 'sk-user-sync-suitecrm'); ?></strong>
                    <span id="syus-connection-badge" style="display: inline-block; padding: 2px 8px; border-radius: 3px; color: #fff; background-color: <?php echo esc_attr($statusColors[$connStatus] ?? '#787c82'); ?>; font-weight: 600;">
                        <?php echo esc_html($statusLabels[$connStatus] ?? $connStatus); ?>
                    </span>
                    <span id="syus-last-connected" style="margin-left: 10px; color: #50575e; <?php echo $lastConnectedLabel ? '' : 'display:none;'; ?>">
                        <?php echo esc_html($lastConnectedLabel); ?>
                    </span>
                </p>
                <p id="syus-last-connection-error" style="color: #d63638; margin-top: 5px; <?php echo !empty($connection['last_error']) ? '' : 'display:none;'; ?>">
                    <strong><?php esc_html_e('Last error:', 'sk-user-sync-suitecrm'); ?></strong>
                    <code><?php echo esc_html($connection['last_error'] ?? ''); ?></code>
                </p>
            </div>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="syus_base_url"><?php esc_html_e('SuiteCRM Base URL', 'sk-user-sync-suitecrm'); ?></label></th>
                        <td>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <input type="url" name="base_url" id="syus_base_url" value="<?php echo esc_attr($connection['base_url']); ?>" class="regular-text" placeholder="https://suitecrm.example.com" style="width: 380px;">
                                <button type="button" class="button button-secondary" id="syus-test-api-btn">
                                    <?php esc_html_e('Test API', 'sk-user-sync-suitecrm'); ?>
                                </button>
                                <span class="spinner" id="syus-test-api-spinner" style="float: none; margin: 0;"></span>
                            </div>
                            <span id="syus-test-api-result" style="display: block; margin-top: 4px; font-weight: 500;"></span>
                            <p class="description">
                                <?php esc_html_e('Base URL of SuiteCRM instance without trailing slash (e.g. https://suitecrm7.example.top). "Test API" verifies server reachability.', 'sk-user-sync-suitecrm'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="syus_client_id"><?php esc_html_e('OAuth2 Client ID', 'sk-user-sync-suitecrm'); ?></label></th>
                        <td>
                            <input type="text" name="client_id" id="syus_client_id" value="<?php echo esc_attr($connection['client_id']); ?>" class="regular-text" placeholder="UUID from SuiteCRM OAuth2 Clients">
                            <p class="description">
                                <?php esc_html_e('Password-grant OAuth2 Client ID configured in SuiteCRM Admin -> OAuth2 Clients.', 'sk-user-sync-suitecrm'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="syus_client_secret"><?php esc_html_e('OAuth2 Client Secret', 'sk-user-sync-suitecrm'); ?></label></th>
                        <td>
                            <input type="password" name="client_secret" id="syus_client_secret" value="<?php echo $connection['has_client_secret'] ? esc_attr(\SyncUsersSuitecrm\Settings::SECRET_MASK) : ''; ?>" class="regular-text" autocomplete="new-password">
                            <p class="description">
                                <?php esc_html_e('Leave masked to preserve existing saved secret. Mask characters are never saved.', 'sk-user-sync-suitecrm'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="syus_username"><?php esc_html_e('SuiteCRM API Username', 'sk-user-sync-suitecrm'); ?></label></th>
                        <td>
                            <input type="text" name="username" id="syus_username" value="<?php echo esc_attr($connection['username']); ?>" class="regular-text" autocomplete="username">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="syus_password"><?php esc_html_e('SuiteCRM API Password', 'sk-user-sync-suitecrm'); ?></label></th>
                        <td>
                            <input type="password" name="password" id="syus_password" value="<?php echo $connection['has_password'] ? esc_attr(\SyncUsersSuitecrm\Settings::SECRET_MASK) : ''; ?>" class="regular-text" autocomplete="current-password">
                            <p class="description">
                                <?php esc_html_e('Leave masked to preserve existing saved password.', 'sk-user-sync-suitecrm'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Connect & Authenticate', 'sk-user-sync-suitecrm'); ?></th>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <button type="button" class="button button-primary" id="syus-connect-btn">
                                    <?php esc_html_e('Connect', 'sk-user-sync-suitecrm'); ?>
                                </button>
                                <span class="spinner" id="syus-connect-spinner" style="float: none; margin: 0;"></span>
                                <span id="syus-connect-result" style="font-weight: 500;"></span>
                            </div>
                            <p class="description">
                                <?php esc_html_e('Obtains and stores an OAuth2 access token, validating credentials against SuiteCRM REST V8.', 'sk-user-sync-suitecrm'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('CRM Migration Safeguard', 'sk-user-sync-suitecrm'); ?></th>
                        <td>
                            <label for="syus_same_crm">
                                <input type="checkbox" name="same_crm" id="syus_same_crm" value="1">
                                <?php esc_html_e('Same CRM different URL (Preserve existing mappings and queue)', 'sk-user-sync-suitecrm'); ?>
                            </label>
                            <p class="description" style="color: #646970;">
                                <?php esc_html_e('Leave unchecked when pointing to a different CRM instance. Unchecked URL changes purge outstanding queue items and clear all saved CRM record IDs in user metadata to prevent cross-instance collisions.', 'sk-user-sync-suitecrm'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

