<?php
/**
 * User mapping repository.
 *
 * Manages mappings between WordPress users and SuiteCRM record IDs stored
 * as user metadata keys formatted as `_ssu_crm_id_{module_lowercase}`.
 *
 * @package SyncSuiteCRMUsers
 */

namespace SyncSuiteCRMUsers\Storage;

use WP_Error;

defined('ABSPATH') || exit;

class UserMappingRepository {

    public const META_PREFIX = '_ssu_crm_id_';

    public const MODULES = [
        'Accounts',
        'Contacts',
        'Leads',
        'Users',
    ];

    /**
     * Generate metadata key for a given module.
     *
     * Lowercase module name when forming metadata keys.
     *
     * @param string $module Module name (e.g., Accounts -> _ssu_crm_id_accounts).
     * @return string
     */
    public static function getMetaKey(string $module): string {
        return self::META_PREFIX . strtolower($module);
    }

    /**
     * Retrieve the SuiteCRM record ID for a user and module.
     *
     * Returns CRM ID string, WP_Error on operational failure, or null for absence.
     * Empty metadata values normalize to absence.
     *
     * @param int    $userId WordPress user ID.
     * @param string $module SuiteCRM module name.
     * @return string|WP_Error|null
     */
    public function get(int $userId, string $module) {
        if ($userId <= 0 || empty($module)) {
            return null;
        }

        $metaKey = self::getMetaKey($module);
        $val     = get_user_meta($userId, $metaKey, true);

        if ($val === '' || $val === false || $val === null) {
            return null;
        }

        return (string) $val;
    }

    /**
     * Retrieve all mapped SuiteCRM module record IDs for a user.
     *
     * Aggregate accessor returns array<Module, string> containing ONLY mapped modules on success.
     *
     * @param int $userId WordPress user ID.
     * @return array<string, string>|WP_Error Map of Module name => CRM Record ID.
     */
    public function getAllForUser(int $userId) {
        if ($userId <= 0) {
            return [];
        }

        $mappings = [];

        foreach (self::MODULES as $module) {
            $crmId = $this->get($userId, $module);

            if (is_wp_error($crmId)) {
                return $crmId;
            }

            if ($crmId !== null && $crmId !== '') {
                $mappings[$module] = $crmId;
            }
        }

        return $mappings;
    }

    /**
     * Save the SuiteCRM record ID mapping for a user and module.
     *
     * @param int    $userId WordPress user ID.
     * @param string $module SuiteCRM module name.
     * @param string $crmId  SuiteCRM record ID.
     * @return bool|WP_Error
     */
    public function set(int $userId, string $module, string $crmId) {
        if ($userId <= 0 || empty($module) || empty($crmId)) {
            return new WP_Error(
                'invalid_mapping_input',
                __('User ID, module, and CRM ID are required to set mapping.', 'sync-suitecrm-users')
            );
        }

        $metaKey = self::getMetaKey($module);
        $current = get_user_meta($userId, $metaKey, true);

        // If value is unchanged, WordPress update_user_meta returns false; treat as success.
        if ($current === $crmId) {
            return true;
        }

        $result = update_user_meta($userId, $metaKey, $crmId);

        if ($result === false) {
            // Confirm whether the value took effect despite returning false
            if (get_user_meta($userId, $metaKey, true) === $crmId) {
                return true;
            }

            return new WP_Error(
                'mapping_save_failed',
                sprintf(
                    /* translators: 1: User ID, 2: Module */
                    __('Failed to save CRM ID mapping for user %1$d and module %2$s.', 'sync-suitecrm-users'),
                    $userId,
                    $module
                )
            );
        }

        return true;
    }

    /**
     * Delete a single module metadata mapping for a user.
     *
     * @param int    $userId WordPress user ID.
     * @param string $module SuiteCRM module name.
     * @return bool|WP_Error
     */
    public function delete(int $userId, string $module) {
        if ($userId <= 0 || empty($module)) {
            return true;
        }

        $metaKey = self::getMetaKey($module);
        $current = get_user_meta($userId, $metaKey, true);

        if ($current === '' || $current === false || $current === null) {
            return true;
        }

        $result = delete_user_meta($userId, $metaKey);

        if ($result === false && get_user_meta($userId, $metaKey, true) !== '') {
            return new WP_Error(
                'mapping_delete_failed',
                sprintf(
                    /* translators: 1: User ID, 2: Module */
                    __('Failed to delete CRM ID mapping for user %1$d and module %2$s.', 'sync-suitecrm-users'),
                    $userId,
                    $module
                )
            );
        }

        return true;
    }

    /**
     * Clear all CRM record ID mappings across all WordPress users.
     *
     * Executed when switching CRM base URL without "Same CRM" checked.
     *
     * @return int|WP_Error Count of deleted metadata records.
     */
    public function clearAllMappings() {
        global $wpdb;

        $like = $wpdb->esc_like(self::META_PREFIX) . '%';
        $sql  = $wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $like);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result = $wpdb->query($sql);

        if ($result === false || !empty($wpdb->last_error)) {
            return new WP_Error(
                'clear_mappings_failed',
                sprintf(
                    /* translators: %s: Database error */
                    __('Database error clearing CRM user mappings: %s', 'sync-suitecrm-users'),
                    $wpdb->last_error
                )
            );
        }

        wp_cache_flush();

        return (int) $result;
    }

    /**
     * Reverse lookup searching WordPress user ID by SuiteCRM record ID and module.
     *
     * @param string $module SuiteCRM module name.
     * @param string $crmId  SuiteCRM record ID.
     * @return int|WP_Error|null Returns positive WordPress user ID, null if not found, or WP_Error on error.
     */
    public function findUserByCrmId(string $module, string $crmId) {
        global $wpdb;

        if (empty($module) || empty($crmId)) {
            return null;
        }

        $metaKey = self::getMetaKey($module);
        $sql     = "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $userId = $wpdb->get_var($wpdb->prepare($sql, $metaKey, $crmId));

        if (!empty($wpdb->last_error)) {
            return new WP_Error(
                'find_user_by_crm_id_failed',
                sprintf(
                    /* translators: %s: Database error */
                    __('Database error looking up user by CRM ID: %s', 'sync-suitecrm-users'),
                    $wpdb->last_error
                )
            );
        }

        return $userId !== null ? (int) $userId : null;
    }
}
