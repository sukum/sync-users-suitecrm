<?php
/**
 * Role and module synchronization eligibility service.
 *
 * Encapsulates role qualification, module enablement checks, sync modes,
 * and detection of relevant profile changes on mapped fields.
 *
 * @package SyncUsersSuitecrm\Sync
 */

namespace SyncUsersSuitecrm\Sync;

use SyncUsersSuitecrm\Settings;
use WP_User;

defined('ABSPATH') || exit;

class Eligibility {

    /**
     * Settings service.
     *
     * @var Settings
     */
    protected Settings $settings;

    /**
     * Mapped user fields across all supported modules.
     */
    public const MAPPED_USER_FIELDS = [
        'user_email',
        'first_name',
        'last_name',
        'display_name',
        'user_login',
    ];

    /**
     * Constructor.
     *
     * @param Settings|null $settings Optional settings instance.
     */
    public function __construct(?Settings $settings = null) {
        $this->settings = $settings ?? new Settings();
    }

    /**
     * Determine if a WordPress user is eligible for synchronization.
     *
     * A user qualifies if any assigned role is selected in eligible_roles.
     * No selected roles in configuration means no eligible users.
     *
     * @param WP_User|int $user WP_User object or WordPress user ID.
     * @return bool True if user is eligible, false otherwise.
     */
    public function isUserEligible($user): bool {
        if (is_numeric($user)) {
            $user = get_userdata((int) $user);
        }

        if (!($user instanceof WP_User) || $user->ID <= 0) {
            return false;
        }

        $eligibleRoles = $this->getEligibleRoles();
        if (empty($eligibleRoles)) {
            return false;
        }

        $userRoles = (array) $user->roles;
        if (empty($userRoles)) {
            return false;
        }

        // Qualifying if any assigned role is selected
        $intersect = array_intersect($userRoles, $eligibleRoles);
        return !empty($intersect);
    }

    /**
     * Get configured eligible role slugs.
     *
     * @return list<string>
     */
    public function getEligibleRoles(): array {
        return $this->settings->getEligibleRoles();
    }

    /**
     * Determine if a specific SuiteCRM module is enabled for sync.
     *
     * @param string $module Module name (Accounts, Contacts, Leads, Users).
     * @return bool
     */
    public function isModuleEnabled(string $module): bool {
        return $this->settings->isModuleEnabled($module);
    }

    /**
     * Get the synchronization mode for a module ('create_only' or 'create_update').
     *
     * @param string $module Module name.
     * @return string
     */
    public function getModuleMode(string $module): string {
        return $this->settings->getModuleMode($module);
    }

    /**
     * Get all currently enabled modules in canonical order.
     *
     * Canonical order: Accounts, Contacts, Leads, Users.
     *
     * @return list<string>
     */
    public function getEnabledModules(): array {
        return $this->settings->getEnabledModules();
    }

    /**
     * Check whether automatic synchronization is enabled globally.
     *
     * @return bool
     */
    public function isAutoSyncEnabled(): bool {
        return $this->settings->isAutoSyncEnabled();
    }

    /**
     * Get automatic synchronization mode ('realtime' or 'batch').
     *
     * @return string
     */
    public function getAutoSyncMode(): string {
        return $this->settings->getAutoSyncMode();
    }

    /**
     * Check if an update to a user profile changed any mapped fields or roles.
     *
     * Compares the old user data with the current user state to ensure
     * unnecessary profile updates (e.g. bio, admin color scheme) do not
     * trigger CRM synchronization.
     *
     * @param WP_User      $currentUser Current user instance after update.
     * @param WP_User|null $oldUserData  Previous user data before update, if available.
     * @return bool True if mapped fields or roles changed, or if old data is unavailable.
     */
    public function hasRelevantChanges(WP_User $currentUser, ?WP_User $oldUserData = null): bool {
        if ($oldUserData === null) {
            return true;
        }

        // 1. Check roles
        $oldRoles = (array) $oldUserData->roles;
        $newRoles = (array) $currentUser->roles;
        sort($oldRoles);
        sort($newRoles);
        if ($oldRoles !== $newRoles) {
            return true;
        }

        // 2. Check mapped fields
        foreach (self::MAPPED_USER_FIELDS as $field) {
            $oldVal = isset($oldUserData->$field) ? (string) $oldUserData->$field : '';
            $newVal = isset($currentUser->$field) ? (string) $currentUser->$field : '';
            if ($oldVal !== $newVal) {
                return true;
            }
        }

        return false;
    }
}
