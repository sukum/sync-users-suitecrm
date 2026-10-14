<?php
/**
 * Outbound field mapper (wp_to_crm).
 *
 * Transforms WordPress user fields into SuiteCRM module attribute payloads
 * for Accounts, Contacts, Leads, and Users modules.
 *
 * @package SyncUsersSuitecrm\SuiteCRM
 */

namespace SyncUsersSuitecrm\SuiteCRM;

use WP_Error;
use WP_User;
use SyncUsersSuitecrm\Settings;

defined('ABSPATH') || exit;

class OutboundFieldMapper {

    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';

    public const ALLOWED_ACTIONS = [
        self::ACTION_CREATE,
        self::ACTION_UPDATE,
    ];

    /**
     * Map WordPress user data into SuiteCRM attributes.
     *
     * - Accounts: name (display_name -> user_login), email1.
     * - Contacts: first_name, last_name (last_name -> display_name -> user_login), email1.
     * - Leads: same as Contacts.
     * - Users: user_name (user_login), last_name, email1, password (only on create).
     *
     * Invariant: Attribute values must be strings (empty as "", never null).
     *
     * @param WP_User $user   WordPress user instance.
     * @param string  $module SuiteCRM module ('Accounts', 'Contacts', 'Leads', 'Users').
     * @param string  $action Action type ('create' or 'update').
     * @return array<string, string>|WP_Error Normalized attributes or WP_Error.
     */
    public function map(WP_User $user, string $module, string $action) {
        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            return new WP_Error(
                'invalid_action',
                sprintf(
                    /* translators: %s: Action name */
                    __('Invalid sync action: %s. Must be create or update.', 'sk-user-sync-suitecrm'),
                    $action
                )
            );
        }

        if (!in_array($module, Settings::SUPPORTED_MODULES, true)) {
            return new WP_Error(
                'invalid_module',
                sprintf(
                    /* translators: %s: Module name */
                    __('Unsupported SuiteCRM module: %s.', 'sk-user-sync-suitecrm'),
                    $module
                )
            );
        }

        $email = trim((string) $user->user_email);
        if (!is_email($email)) {
            return new WP_Error(
                'invalid_user_data',
                __('WordPress user has no valid email address', 'sk-user-sync-suitecrm'),
                ['user_id' => (int) $user->ID]
            );
        }

        $login       = trim((string) $user->user_login);
        $displayName = trim((string) $user->display_name);
        $firstName   = trim((string) $user->first_name);
        $lastName    = trim((string) $user->last_name);

        switch ($module) {
            case 'Accounts':
                $name = $displayName !== '' ? $displayName : $login;
                return [
                    'name'   => (string) $name,
                    'email1' => (string) $email,
                ];

            case 'Contacts':
            case 'Leads':
                $resolvedLastName = $lastName !== '' ? $lastName : ($displayName !== '' ? $displayName : $login);
                return [
                    'first_name' => (string) $firstName,
                    'last_name'  => (string) $resolvedLastName,
                    'email1'     => (string) $email,
                ];

            case 'Users':
                $resolvedLastName = $lastName !== '' ? $lastName : ($displayName !== '' ? $displayName : $login);
                $attributes = [
                    'user_name' => (string) $login,
                    'last_name' => (string) $resolvedLastName,
                    'email1'    => (string) $email,
                ];

                if ($action === self::ACTION_CREATE) {
                    $attributes['password'] = (string) wp_generate_password(16, true, true);
                }

                return $attributes;

            default:
                return new WP_Error(
                    'invalid_module',
                    sprintf(
                        /* translators: %s: Module name */
                        __('Unsupported SuiteCRM module: %s.', 'sk-user-sync-suitecrm'),
                        $module
                    )
                );
        }
    }
}
