<?php
/**
 * Template for user edit/profile SuiteCRM Synchronization section.
 *
 * Variables passed from UserSyncActions:
 * - $user (WP_User): WordPress user object.
 * - $userId (int): WordPress user ID.
 * - $mappings (array<string, string>): Array of module => CRM record ID.
 * - $enabledModules (list<string>): Currently enabled SuiteCRM modules.
 * - $isEligible (bool): Whether the user is eligible based on role settings.
 * - $eligibleRoles (list<string>): List of configured eligible roles.
 * - $userRoles (list<string>): List of roles assigned to this user.
 * - $nonce (string): Security nonce for syus_user_sync_nonce.
 *
 * @package SyncUsersSuitecrm
 */

namespace SyncUsersSuitecrm;

defined('ABSPATH') || exit;
?>

<div class="syus-user-sync-section" style="margin-top: 25px;">
    <h2><?php esc_html_e('SuiteCRM Synchronization', 'sync-users-suitecrm'); ?></h2>
    <p class="description">
        <?php esc_html_e('Manual synchronization pushes this user directly to enabled SuiteCRM modules without inserting into the background queue.', 'sync-users-suitecrm'); ?>
    </p>

    <table class="form-table" role="presentation">
        <tbody>
            <!-- 1. ELIGIBILITY -->
            <tr>
                <th scope="row"><?php esc_html_e('Sync Eligibility', 'sync-users-suitecrm'); ?></th>
                <td>
                    <?php if ($isEligible) : ?>
                        <span class="syus-badge syus-badge-success" style="display: inline-block; padding: 2px 8px; border-radius: 3px; color: #fff; background-color: #00a32a; font-weight: 600;">
                            <?php esc_html_e('Eligible', 'sync-users-suitecrm'); ?>
                        </span>
                        <span style="margin-left: 8px; color: #50575e;">
                            <?php
                            printf(
                                /* translators: %s: user roles */
                                esc_html__('User role (%s) matches configured eligible roles.', 'sync-users-suitecrm'),
                                esc_html(implode(', ', $userRoles))
                            );
                            ?>
                        </span>
                    <?php else : ?>
                        <span class="syus-badge syus-badge-warning" style="display: inline-block; padding: 2px 8px; border-radius: 3px; color: #fff; background-color: #dba617; font-weight: 600;">
                            <?php esc_html_e('Role Ineligible', 'sync-users-suitecrm'); ?>
                        </span>
                        <span style="margin-left: 8px; color: #50575e;">
                            <?php
                            printf(
                                /* translators: %s: user roles */
                                esc_html__('User role (%s) is not in eligible roles list. Syncing will skip unless roles are updated.', 'sync-users-suitecrm'),
                                esc_html(implode(', ', $userRoles))
                            );
                            ?>
                        </span>
                    <?php endif; ?>
                </td>
            </tr>

            <!-- 2. MODULE MAPPINGS -->
            <tr>
                <th scope="row"><?php esc_html_e('SuiteCRM Record Mappings', 'sync-users-suitecrm'); ?></th>
                <td>
                    <table class="widefat fixed striped" style="max-width: 600px;">
                        <thead>
                            <tr>
                                <th style="width: 140px;"><?php esc_html_e('Module', 'sync-users-suitecrm'); ?></th>
                                <th style="width: 100px;"><?php esc_html_e('Status', 'sync-users-suitecrm'); ?></th>
                                <th><?php esc_html_e('SuiteCRM Record ID', 'sync-users-suitecrm'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="syus-user-mappings-tbody">
                            <?php foreach (['Accounts', 'Contacts', 'Leads', 'Users'] as $module) :
                                $isEnabled = in_array($module, $enabledModules, true);
                                $crmId     = $mappings[$module] ?? null;
                            ?>
                                <tr id="syus-mapping-row-<?php echo esc_attr(strtolower($module)); ?>">
                                    <td>
                                        <strong><?php echo esc_html($module); ?></strong>
                                        <?php if (!$isEnabled) : ?>
                                            <span style="color: #646970; font-size: 11px;"><?php esc_html_e('(Disabled)', 'sync-users-suitecrm'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($crmId)) : ?>
                                            <span style="color: #00a32a; font-weight: 600;"><?php esc_html_e('Mapped', 'sync-users-suitecrm'); ?></span>
                                        <?php else : ?>
                                            <span style="color: #646970;"><?php esc_html_e('Unmapped', 'sync-users-suitecrm'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="syus-mapping-id-cell">
                                        <?php if (!empty($crmId)) : ?>
                                            <code><?php echo esc_html($crmId); ?></code>
                                        <?php else : ?>
                                            <span style="color: #a7aaad;">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="description" style="margin-top: 6px;">
                        <?php esc_html_e('Mapped record IDs are saved in user metadata and used for subsequent create-or-update passes.', 'sync-users-suitecrm'); ?>
                    </p>
                </td>
            </tr>

            <!-- 3. ACTIONS -->
            <tr>
                <th scope="row"><?php esc_html_e('Actions', 'sync-users-suitecrm'); ?></th>
                <td>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <button type="button" class="button button-primary" id="syus-sync-user-btn" data-user-id="<?php echo esc_attr($userId); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
                            <span class="dashicons dashicons-update" style="vertical-align: middle; margin-top: -2px; font-size: 16px; width: 16px; height: 16px;"></span>
                            <?php esc_html_e('Sync to SuiteCRM', 'sync-users-suitecrm'); ?>
                        </button>
                        <span class="spinner" id="syus-sync-user-spinner" style="float: none; margin: 0;"></span>
                    </div>

                    <!-- RESULT CONTAINER -->
                    <div id="syus-user-sync-result" style="margin-top: 15px; max-width: 650px; display: none;"></div>
                </td>
            </tr>
        </tbody>
    </table>
</div>
