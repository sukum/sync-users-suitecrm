<?php
/**
 * SuiteCRM module settings tab.
 *
 * Variables inherited from settings.php: $settings.
 *
 * @package SyncSuiteCRMUsers
 */

defined('ABSPATH') || exit;

$modulesState = [];
foreach (\SyncSuiteCRMUsers\Settings::SUPPORTED_MODULES as $module) {
    $modulesState['modules_' . strtolower($module) . '_enabled'] = !empty($settings['modules'][$module]['enabled']);
}
?>
        <!-- TAB 3: SUITECRM MODULES -->
        <div id="ssu-tab-modules" class="ssu-tab-content" style="display: none;" data-state="<?php echo esc_attr(wp_json_encode($modulesState)); ?>">
            <h2><?php esc_html_e('SuiteCRM Modules Synchronization', 'sync-suitecrm-users'); ?></h2>
            <p class="description">
                <?php esc_html_e('Choose which SuiteCRM modules are enabled and select whether records should be created only or created and updated.', 'sync-suitecrm-users'); ?>
            </p>

            <table class="wp-list-table widefat fixed striped" style="max-width: 860px; margin-top: 15px;">
                <thead>
                    <tr>
                        <th style="width: 220px;"><?php esc_html_e('SuiteCRM Module', 'sync-suitecrm-users'); ?></th>
                        <th style="width: 140px;"><?php esc_html_e('Enable Sync', 'sync-suitecrm-users'); ?></th>
                        <th><?php esc_html_e('Sync Mode', 'sync-suitecrm-users'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $moduleInfo = [
                        'Accounts' => [
                            'label' => __('Accounts', 'sync-suitecrm-users'),
                            'desc'  => __('Display Name -> name, Email -> email1', 'sync-suitecrm-users'),
                        ],
                        'Contacts' => [
                            'label' => __('Contacts', 'sync-suitecrm-users'),
                            'desc'  => __('First Name, Last Name -> last_name, Email -> email1', 'sync-suitecrm-users'),
                        ],
                        'Leads'    => [
                            'label' => __('Leads', 'sync-suitecrm-users'),
                            'desc'  => __('First Name, Last Name -> last_name, Email -> email1', 'sync-suitecrm-users'),
                        ],
                        'Users'    => [
                            'label' => __('Users', 'sync-suitecrm-users'),
                            'desc'  => __('Username -> user_name, Last Name, Email -> email1. Note: stock SuiteCRM requires api-visible vardef extension.', 'sync-suitecrm-users'),
                        ],
                    ];

                    foreach (\SyncSuiteCRMUsers\Settings::SUPPORTED_MODULES as $module) :
                        $modSettings = $settings['modules'][$module] ?? ['enabled' => false, 'mode' => 'create_update'];
                        $info = $moduleInfo[$module] ?? ['label' => $module, 'desc' => ''];
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($info['label']); ?></strong>
                                <br><small style="color: #646970;"><?php echo esc_html($info['desc']); ?></small>
                            </td>
                            <td>
                                <label>
                                    <input type="checkbox" data-module="<?php echo esc_attr($module); ?>" id="modules_<?php echo esc_attr(strtolower($module)); ?>_enabled"
                                        name="modules[<?php echo esc_attr($module); ?>][enabled]" value="1" <?php checked($modSettings['enabled']); ?>
                                        <?php echo $module === 'Users' ? 'disabled="disabled"' : ''; ?>
                                    >
                                    <?php $module !== 'Users' ? esc_html_e('Enabled', 'sync-suitecrm-users') : esc_html_e('Need a change in SuiteCRM to work', 'sync-suitecrm-users'); ?>
                                </label>
                            </td>
                            <td>
                                <label style="margin-right: 15px;">
                                    <input type="radio" id="modules_<?php echo esc_attr(strtolower($module)); ?>_mode_create_only" name="modules[<?php echo esc_attr($module); ?>][mode]" value="create_only" <?php checked($modSettings['mode'], 'create_only'); ?>>
                                    <?php esc_html_e('Create only', 'sync-suitecrm-users'); ?>
                                </label>
                                <label>
                                    <input type="radio" id="modules_<?php echo esc_attr(strtolower($module)); ?>_mode_create_update" name="modules[<?php echo esc_attr($module); ?>][mode]" value="create_update" <?php checked($modSettings['mode'], 'create_update'); ?>>
                                    <?php esc_html_e('Create or update', 'sync-suitecrm-users'); ?>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

