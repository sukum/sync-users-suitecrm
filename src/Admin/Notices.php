<?php
/**
 * Administrator Notices handler.
 *
 * Displays system and connection state notices across WordPress admin.
 *
 * @package SyncUsersSuitecrm\Admin
 */

namespace SyncUsersSuitecrm\Admin;

use SyncUsersSuitecrm\Settings;

defined('ABSPATH') || exit;

class Notices {

    /**
     * Settings service.
     *
     * @var Settings
     */
    protected Settings $settings;

    /**
     * Constructor.
     *
     * @param Settings|null $settings
     */
    public function __construct(?Settings $settings = null) {
        $this->settings = $settings ?? new Settings();
    }

    /**
     * Register admin notices hooks.
     *
     * @return void
     */
    public function register(): void {
        add_action('admin_notices', [$this, 'renderAdminNotices']);
    }

    /**
     * Render global administrative notices.
     *
     * On renewal failure, save the failure state and display "Reconnect required"
     * on the plugin page and in an administrator notice.
     *
     * @return void
     */
    public function renderAdminNotices(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $connection = $this->settings->getConnection();
        $status     = $connection['connection_status'] ?? 'not_configured';

        // Check if SuiteCRM reconnection is required
        if ($status === 'reconnect_required') {
            $settingsUrl = admin_url('admin.php?page=syus-settings#tab-authentication');
            $lastError   = !empty($connection['last_error']) ? $connection['last_error'] : '';
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong><?php esc_html_e('SuiteCRM User Sync:', 'sk-user-sync-suitecrm'); ?></strong>
                    <?php esc_html_e('SuiteCRM reconnection is required. API requests are currently paused.', 'sk-user-sync-suitecrm'); ?>
                    <?php if ($lastError !== '') : ?>
                        <br>
                        <span style="color: #646970;">
                            <?php
                            printf(
                                /* translators: %s: Sanitized last error message */
                                esc_html__('Reason: %s', 'sk-user-sync-suitecrm'),
                                esc_html($lastError)
                            );
                            ?>
                        </span>
                    <?php endif; ?>
                    <br>
                    <a href="<?php echo esc_url($settingsUrl); ?>" class="button button-small" style="margin-top: 5px;">
                        <?php esc_html_e('Update SuiteCRM Credentials', 'sk-user-sync-suitecrm'); ?>
                    </a>
                </p>
            </div>
            <?php
        }

        // Render transient flash notice if stored
        $flash = get_transient('syus_admin_flash_notice');
        if (is_array($flash) && !empty($flash['message'])) {
            delete_transient('syus_admin_flash_notice');
            $type = in_array($flash['type'] ?? 'info', ['info', 'success', 'warning', 'error'], true)
                ? $flash['type']
                : 'info';
            ?>
            <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible">
                <p><?php echo esc_html($flash['message']); ?></p>
            </div>
            <?php
        }
    }

    /**
     * Helper to set a flash notice to display on the next page load.
     *
     * @param string $message Notice message.
     * @param string $type Notice type ('info', 'success', 'warning', 'error').
     * @return void
     */
    public static function setFlashNotice(string $message, string $type = 'info'): void {
        set_transient('syus_admin_flash_notice', [
            'message' => $message,
            'type'    => $type,
        ], 60);
    }
}
