<?php
/**
 * Plugin Name:       Sync users to SuiteCRM
 * Plugin URI:        https://github.com/sukum/sync-users-suitecrm
 * Description:       Synchronize WordPress users one-way to SuiteCRM as Accounts, Contacts, Leads using the REST V8 API.
 * Version:           1.0.0
 * Requires at least: 5.3
 * Requires PHP:      7.4
 * Author:            sukum
 * Author URI:        https://github.com/sukum
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sync-users-suitecrm
 */

defined('ABSPATH') || exit;

// Plugin version and constants
define('SYUS_VERSION', '1.0.0');
define('SYUS_DB_VERSION', '1.0.0');
define('SYUS_PLUGIN_FILE', __FILE__);
define('SYUS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SYUS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SYUS_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Functions must be loaded explicitly with either class autoloader.
require_once SYUS_PLUGIN_DIR . 'src/functions.php';

// Autoloader: Composer autoload if present, otherwise fallback PSR-4 autoloader
if (file_exists(SYUS_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once SYUS_PLUGIN_DIR . 'vendor/autoload.php';
} else {
    spl_autoload_register(function ($class) {
        $prefix = 'SyncSuiteCRMUsers\\';
        $baseDir = SYUS_PLUGIN_DIR . 'src/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
        }
    });
}

// Lifecycle registration
register_activation_hook(__FILE__, ['SyncSuiteCRMUsers\\Lifecycle', 'activate']);
register_deactivation_hook(__FILE__, ['SyncSuiteCRMUsers\\Lifecycle', 'deactivate']);

/**
 * Access the main plugin instance.
 *
 * @return \SyncSuiteCRMUsers\Plugin
 */
function sync_suitecrm_users(): SyncSuiteCRMUsers\Plugin {
    return SyncSuiteCRMUsers\Plugin::instance();
}

// Bootstrap plugin on plugins_loaded
add_action('plugins_loaded', function () {
    sync_suitecrm_users()->init();
});
