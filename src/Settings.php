<?php
/**
 * Settings and connection options manager.
 *
 * Provides validated options access, normalization, secret masking,
 * and connection revision tracking.
 *
 * @package SyncSuiteCRMUsers
 */

namespace SyncSuiteCRMUsers;

use SyncSuiteCRMUsers\Storage\MySqlLock;

defined('ABSPATH') || exit;

class Settings {

    public const OPTION_SETTINGS = 'syus_settings';
    public const OPTION_CONNECTION = 'syus_connection';
    public const OPTION_DB_VERSION = 'syus_db_version';

    public const SECRET_MASK = '••••••••';

    public const SUPPORTED_MODULES = [
        'Accounts',
        'Contacts',
        'Leads',
        'Users',
    ];

    public const SYNC_MODES = [
        'create_only',
        'create_update',
    ];

    public const AUTO_SYNC_MODES = [
        'realtime',
        'batch',
    ];

    public const CONNECTION_STATUSES = [
        'not_configured',
        'connected',
        'reconnect_required',
    ];

    /**
     * Get default settings schema.
     *
     * @return array<string, mixed>
     */
    public function getDefaultSettings(): array {
        return [
            'auto_sync_enabled' => false,
            'auto_sync_mode'    => 'realtime',
            'batch_time_hour'   => '02',
            'batch_time_minute' => '00',
            'eligible_roles'    => ['subscriber'],
            'modules'           => [
                'Accounts' => [
                    'enabled' => false,
                    'mode'    => 'create_update',
                ],
                'Contacts' => [
                    'enabled' => true,
                    'mode'    => 'create_update',
                ],
                'Leads' => [
                    'enabled' => false,
                    'mode'    => 'create_update',
                ],
                'Users' => [
                    'enabled' => false,
                    'mode'    => 'create_update',
                ],
            ],
        ];
    }

    /**
     * Get default connection schema.
     *
     * @return array<string, mixed>
     */
    public function getDefaultConnection(): array {
        return [
            'base_url'          => '',
            'client_id'         => '',
            'client_secret'     => '',
            'username'          => '',
            'password'          => '',
            'access_token'      => null,
            'refresh_token'     => null,
            'token_expires_at'  => 0,
            'connection_status' => 'not_configured',
            'last_connected_at' => null,
            'connection_rev'    => 1,
            'last_error'        => '',
        ];
    }

    /**
     * Retrieve validated settings.
     *
     * @return array<string, mixed>
     */
    public function getSettings(): array {
        $stored = get_option(self::OPTION_SETTINGS, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $defaults = $this->getDefaultSettings();

        $settings = [
            'auto_sync_enabled' => !empty($stored['auto_sync_enabled']),
            'auto_sync_mode'    => in_array($stored['auto_sync_mode'] ?? '', self::AUTO_SYNC_MODES, true)
                ? $stored['auto_sync_mode']
                : $defaults['auto_sync_mode'],
            'batch_time_hour'   => isset($stored['batch_time_hour'])
                ? str_pad((string) max(0, min(23, (int) $stored['batch_time_hour'])), 2, '0', STR_PAD_LEFT)
                : $defaults['batch_time_hour'],
            'batch_time_minute' => in_array($stored['batch_time_minute'] ?? '', ['00', '10', '20', '30', '40', '50'], true)
                ? $stored['batch_time_minute']
                : $defaults['batch_time_minute'],
            'eligible_roles'    => is_array($stored['eligible_roles'] ?? null)
                ? array_values(array_filter(array_map('sanitize_key', $stored['eligible_roles'])))
                : $defaults['eligible_roles'],
            'modules'           => [],
        ];

        foreach (self::SUPPORTED_MODULES as $module) {
            $modData = $stored['modules'][$module] ?? [];
            $settings['modules'][$module] = [
                'enabled' => !empty($modData['enabled']),
                'mode'    => in_array($modData['mode'] ?? '', self::SYNC_MODES, true)
                    ? $modData['mode']
                    : $defaults['modules'][$module]['mode'],
            ];
        }

        return $settings;
    }

    /**
     * Validate and update settings.
     *
     * @param array<string, mixed> $input Raw input data.
     * @return array<string, mixed>|\WP_Error Validated settings or error.
     */
    public function updateSettings(array $input) {
        $current = $this->getSettings();

        // 1. Auto sync enabled
        $current['auto_sync_enabled'] = !empty($input['auto_sync_enabled']);

        // 2. Auto sync mode
        if (isset($input['auto_sync_mode'])) {
            $mode = sanitize_key($input['auto_sync_mode']);
            if (in_array($mode, self::AUTO_SYNC_MODES, true)) {
                $current['auto_sync_mode'] = $mode;
            }
        }

        // 3. Batch time hour & minute
        if (isset($input['batch_time_hour'])) {
            $hour = (int) $input['batch_time_hour'];
            if ($hour >= 0 && $hour <= 23) {
                $current['batch_time_hour'] = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
            }
        }

        if (isset($input['batch_time_minute'])) {
            $minute = sanitize_text_field($input['batch_time_minute']);
            if (in_array($minute, ['00', '10', '20', '30', '40', '50'], true)) {
                $current['batch_time_minute'] = $minute;
            }
        }

        // 4. Eligible roles
        if (isset($input['eligible_roles']) && is_array($input['eligible_roles'])) {
            $current['eligible_roles'] = array_values(array_filter(array_map('sanitize_key', $input['eligible_roles'])));
        } elseif (isset($input['eligible_roles']) && empty($input['eligible_roles'])) {
            $current['eligible_roles'] = [];
        }

        // 5. Modules
        if (isset($input['modules']) && is_array($input['modules'])) {
            foreach (self::SUPPORTED_MODULES as $module) {
                // To push to Users, a special change in SuiteCRM is required, so we skip it here.
                // user_name field required, but set as api-visible=false blocking API access.
                if ($module === 'Users') {
                    continue;
                }
                $modInput = $input['modules'][$module] ?? [];
                $current['modules'][$module]['enabled'] = !empty($modInput['enabled']);
                if (isset($modInput['mode']) && in_array($modInput['mode'], self::SYNC_MODES, true)) {
                    $current['modules'][$module]['mode'] = $modInput['mode'];
                }
            }
        }

        update_option(self::OPTION_SETTINGS, $current);
        return $current;
    }

    /**
     * Retrieve full connection options including secrets (for CRM client only).
     *
     * @return array<string, mixed>
     */
    public function getConnection(): array {
        $stored = get_option(self::OPTION_CONNECTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return wp_parse_args($stored, $this->getDefaultConnection());
    }

    /**
     * Retrieve safe connection snapshot excluding raw credentials.
     *
     * @return array{
     *     base_url: string,
     *     client_id: string,
     *     has_client_secret: bool,
     *     username: string,
     *     has_password: bool,
     *     connection_status: string,
     *     last_connected_at: int|null,
     *     last_error: string
     * }
     */
    public function getSafeConnection(): array {
        $conn = $this->getConnection();

        return [
            'base_url'          => (string) $conn['base_url'],
            'client_id'         => (string) $conn['client_id'],
            'has_client_secret' => !empty($conn['client_secret']),
            'username'          => (string) $conn['username'],
            'has_password'      => !empty($conn['password']),
            'connection_status' => (string) $conn['connection_status'],
            'last_connected_at' => $conn['last_connected_at'] !== null ? (int) $conn['last_connected_at'] : null,
            'last_error'        => (string) $conn['last_error'],
        ];
    }

    /**
     * Validate and update connection options, handling secret preservation and connection revision.
     *
     * @param array<string, mixed> $input
     * @param bool $sameCrm Whether URL change points to the same CRM instance.
     * @return array<string, mixed>|\WP_Error
     */
    public function updateConnection(array $input, bool $sameCrm = false) {
        $lock = new MySqlLock('connection');
        if (!$lock->acquire()) {
            return new \WP_Error(
                'connection_busy',
                __('The connection could not be locked for updating.', 'sync-users-suitecrm'),
                ['http_status' => 409]
            );
        }

        try {
            // Discard earlier option reads before reading under the lock.
            wp_cache_delete(self::OPTION_CONNECTION, 'options');
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
            $current = $this->getConnection();

            $newBaseUrl = isset($input['base_url']) ? untrailingslashit(trim(sanitize_text_field($input['base_url']))) : $current['base_url'];
            $newClientId = isset($input['client_id']) ? trim(sanitize_text_field($input['client_id'])) : $current['client_id'];
            $newUsername = isset($input['username']) ? trim(sanitize_text_field($input['username'])) : $current['username'];

            // Secret preservation: empty or mask keeps existing value
            $inputSecret = isset($input['client_secret']) ? trim((string) $input['client_secret']) : '';
            $newSecret = ($inputSecret === '' || $inputSecret === self::SECRET_MASK)
                ? $current['client_secret']
                : $inputSecret;

            $inputPassword = isset($input['password']) ? trim((string) $input['password']) : '';
            $newPassword = ($inputPassword === '' || $inputPassword === self::SECRET_MASK)
                ? $current['password']
                : $inputPassword;

            $credentialsChanged = (
                $newBaseUrl !== $current['base_url'] ||
                $newClientId !== $current['client_id'] ||
                $newUsername !== $current['username'] ||
                $newSecret !== $current['client_secret'] ||
                $newPassword !== $current['password']
            );

            $urlChanged = ($newBaseUrl !== $current['base_url']);

            $updated = [
                'base_url'          => $newBaseUrl,
                'client_id'         => $newClientId,
                'client_secret'     => $newSecret,
                'username'          => $newUsername,
                'password'          => $newPassword,
                'access_token'      => $credentialsChanged ? null : $current['access_token'],
                'refresh_token'     => $credentialsChanged ? null : $current['refresh_token'],
                'token_expires_at'  => $credentialsChanged ? 0 : (int) $current['token_expires_at'],
                'connection_status' => $credentialsChanged ? 'not_configured' : $current['connection_status'],
                'last_connected_at' => $current['last_connected_at'],
                'connection_rev'    => $credentialsChanged ? ((int) $current['connection_rev'] + 1) : (int) $current['connection_rev'],
                'last_error'        => $credentialsChanged ? '' : (string) $current['last_error'],
            ];

            // If URL changed without "Same CRM different URL", clear queue and user mappings
            if ($urlChanged && !$sameCrm && !empty($current['base_url'])) {
                $this->clearAllMappingsAndQueue();
            }

            update_option(self::OPTION_CONNECTION, $updated);
            return $updated;
        } finally {
            $lock->release();
        }
    }

    /**
     * Atomically update token state upon successful authentication or refresh.
     *
     * @param string $accessToken
     * @param string|null $refreshToken
     * @param int $expiresIn Expiration window in seconds.
     * @return bool
     */
    public function updateTokenData(string $accessToken, ?string $refreshToken, int $expiresIn): bool {
        $conn = $this->getConnection();
        $conn['access_token'] = $accessToken;
        if ($refreshToken !== null) {
            $conn['refresh_token'] = $refreshToken;
        }
        $conn['token_expires_at'] = time() + max(0, $expiresIn);
        $conn['connection_status'] = 'connected';
        $conn['last_connected_at'] = time();
        $conn['last_error'] = '';

        return update_option(self::OPTION_CONNECTION, $conn);
    }

    /**
     * Update connection status and recorded error.
     *
     * @param string $status
     * @param string $error
     * @return bool
     */
    public function setConnectionStatus(string $status, string $error = ''): bool {
        if (!in_array($status, self::CONNECTION_STATUSES, true)) {
            return false;
        }

        $conn = $this->getConnection();
        $conn['connection_status'] = $status;
        if ($error !== '') {
            $conn['last_error'] = sanitize_text_field($error);
        }

        return update_option(self::OPTION_CONNECTION, $conn);
    }

    /**
     * Get current connection revision.
     *
     * @return int
     */
    public function getConnectionRevision(): int {
        $conn = $this->getConnection();
        return (int) ($conn['connection_rev'] ?? 1);
    }

    /**
     * Check if automatic synchronization is enabled.
     *
     * @return bool
     */
    public function isAutoSyncEnabled(): bool {
        $settings = $this->getSettings();
        return !empty($settings['auto_sync_enabled']);
    }

    /**
     * Get automatic sync mode ('realtime' or 'batch').
     *
     * @return string
     */
    public function getAutoSyncMode(): string {
        $settings = $this->getSettings();
        return (string) ($settings['auto_sync_mode'] ?? 'realtime');
    }

    /**
     * Get list of eligible role slugs.
     *
     * @return list<string>
     */
    public function getEligibleRoles(): array {
        $settings = $this->getSettings();
        return (array) ($settings['eligible_roles'] ?? []);
    }

    /**
     * Check if a WordPress user is eligible for synchronization based on roles.
     *
     * @param int|\WP_User $user
     * @return bool
     */
    public function isUserEligible($user): bool {
        if (is_numeric($user)) {
            $user = get_userdata((int) $user);
        }

        if (!($user instanceof \WP_User)) {
            return false;
        }

        $eligibleRoles = $this->getEligibleRoles();
        if (empty($eligibleRoles)) {
            return false;
        }

        foreach ((array) $user->roles as $userRole) {
            if (in_array($userRole, $eligibleRoles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a specific module is enabled.
     *
     * @param string $module
     * @return bool
     */
    public function isModuleEnabled(string $module): bool {
        $settings = $this->getSettings();
        return !empty($settings['modules'][$module]['enabled']);
    }

    /**
     * Get sync mode for a module ('create_only' or 'create_update').
     *
     * @param string $module
     * @return string
     */
    public function getModuleMode(string $module): string {
        $settings = $this->getSettings();
        return (string) ($settings['modules'][$module]['mode'] ?? 'create_update');
    }

    /**
     * Get list of enabled module names in canonical order.
     *
     * @return list<string>
     */
    public function getEnabledModules(): array {
        $enabled = [];
        foreach (self::SUPPORTED_MODULES as $module) {
            if ($this->isModuleEnabled($module)) {
                $enabled[] = $module;
            }
        }
        return $enabled;
    }

    /**
     * Purge the automatic queue and clear all CRM record mappings in user metadata.
     *
     * @return void
     */
    public function clearAllMappingsAndQueue(): void {
        global $wpdb;

        // Purge queue table
        $queueTable = $wpdb->prefix . 'syus_queue';
        $wpdb->query($wpdb->prepare("TRUNCATE TABLE `%1\$s`", $queueTable));

        // Clear CRM mappings
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
                $wpdb->esc_like('_syus_crm_id_') . '%'
            )
        );
    }
}
