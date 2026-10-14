<?php
/**
 * SuiteCRM module settings tab.
 *
 * Variables inherited from settings.php: $settings.
 *
 * @package SyncUsersSuitecrm
 */

defined('ABSPATH') || exit;

$modulesState = [];
foreach (\SyncUsersSuitecrm\Settings::SUPPORTED_MODULES as $module) {
    $modulesState['modules_' . strtolower($module) . '_enabled'] = !empty($settings['modules'][$module]['enabled']);
}
?>
        <!-- TAB 3: SUITECRM MODULES -->
        <div id="syus-tab-modules" class="syus-tab-content" style="display: none;" data-state="<?php echo esc_attr(wp_json_encode($modulesState)); ?>">
            <h2><?php esc_html_e('SuiteCRM Modules Synchronization', 'sk-user-sync-suitecrm'); ?></h2>
            <p class="description">
                <?php esc_html_e('Choose which SuiteCRM modules are enabled and select whether records should be created only or created and updated.', 'sk-user-sync-suitecrm'); ?>
            </p>

            <table class="wp-list-table widefat fixed striped" style="max-width: 860px; margin-top: 15px;">
                <thead>
                    <tr>
                        <th style="width: 220px;"><?php esc_html_e('SuiteCRM Module', 'sk-user-sync-suitecrm'); ?></th>
                        <th style="width: 140px;"><?php esc_html_e('Enable Sync', 'sk-user-sync-suitecrm'); ?></th>
                        <th><?php esc_html_e('Sync Mode', 'sk-user-sync-suitecrm'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $moduleInfo = [
                        'Accounts' => [
                            'label' => __('Accounts', 'sk-user-sync-suitecrm'),
                            'desc'  => __('Display Name -> name, Email -> email1', 'sk-user-sync-suitecrm'),
                        ],
                        'Contacts' => [
                            'label' => __('Contacts', 'sk-user-sync-suitecrm'),
                            'desc'  => __('First Name, Last Name -> last_name, Email -> email1', 'sk-user-sync-suitecrm'),
                        ],
                        'Leads'    => [
                            'label' => __('Leads', 'sk-user-sync-suitecrm'),
                            'desc'  => __('First Name, Last Name -> last_name, Email -> email1', 'sk-user-sync-suitecrm'),
                        ],
                        'Users'    => [
                            'label' => __('Users', 'sk-user-sync-suitecrm'),
                            'desc'  => __('Username -> user_name, Last Name, Email -> email1. Note: stock SuiteCRM requires api-visible vardef extension.', 'sk-user-sync-suitecrm'),
                        ],
                    ];

                    foreach (\SyncUsersSuitecrm\Settings::SUPPORTED_MODULES as $module) :
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
                                    <?php $module !== 'Users' ? esc_html_e('Enabled', 'sk-user-sync-suitecrm') : esc_html_e('Need a change in SuiteCRM to work', 'sk-user-sync-suitecrm'); ?>
                                </label>
                            </td>
                            <td>
                                <label style="margin-right: 15px;">
                                    <input type="radio" id="modules_<?php echo esc_attr(strtolower($module)); ?>_mode_create_only" name="modules[<?php echo esc_attr($module); ?>][mode]" value="create_only" <?php checked($modSettings['mode'], 'create_only'); ?>>
                                    <?php esc_html_e('Create only', 'sk-user-sync-suitecrm'); ?>
                                </label>
                                <label>
                                    <input type="radio" id="modules_<?php echo esc_attr(strtolower($module)); ?>_mode_create_update" name="modules[<?php echo esc_attr($module); ?>][mode]" value="create_update" <?php checked($modSettings['mode'], 'create_update'); ?>>
                                    <?php esc_html_e('Create or update', 'sk-user-sync-suitecrm'); ?>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

