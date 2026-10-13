<?php
/**
 * WordPress user lifecycle mutation hooks.
 *
 * Intercepts user registrations, profile updates, and role alterations
 * to queue qualifying users for automatic SuiteCRM synchronization.
 * Includes loop prevention via SyncGuard.
 *
 * @package SyncSuiteCRMUsers\Sync
 */

namespace SyncSuiteCRMUsers\Sync;

use WP_User;

defined('ABSPATH') || exit;

class UserHooks {

    /**
     * Automatic enqueuer service.
     *
     * @var Enqueuer
     */
    protected Enqueuer $enqueuer;

    /**
     * Eligibility service.
     *
     * @var Eligibility
     */
    protected Eligibility $eligibility;

    /**
     * Multiple hooks prevention.
     *
     * @var array<int, bool> $userMap
     */
    protected array $userMap = [];

    /**
     * Constructor.
     *
     * @param Enqueuer|null    $enqueuer
     * @param Eligibility|null $eligibility
     */
    public function __construct(?Enqueuer $enqueuer = null, ?Eligibility $eligibility = null) {
        $this->enqueuer    = $enqueuer ?? new Enqueuer();
        $this->eligibility = $eligibility ?? new Eligibility();
    }

    /**
     * Register core WordPress hooks.
     *
     * @return void
     */
    public function register(): void {
        add_action('user_register', [$this, 'onUserRegister'], 10, 1);
        add_action('profile_update', [$this, 'onProfileUpdate'], 10, 2);
        add_action('set_user_role', [$this, 'onSetUserRole'], 10, 3);
        add_action('add_user_role', [$this, 'onRoleChanged'], 10, 2);
        add_action('remove_user_role', [$this, 'onRoleChanged'], 10, 2);
    }

    /**
     * Hook callback: user_register.
     *
     * @param int $userId WordPress user ID.
     * @return void
     */
    public function onUserRegister(int $userId): void {
        if (SyncGuard::is_syncing()) {
            return;
        }

        $this->enqueueUser($userId);
    }

    /**
     * Hook callback: profile_update.
     *
     * Compares new user attributes against old user attributes so that
     * only updates touching mapped fields or roles trigger synchronization.
     *
     * @param int          $userId      WordPress user ID.
     * @param WP_User|null $oldUserData User object before the update.
     * @return void
     */
    public function onProfileUpdate(int $userId, ?WP_User $oldUserData = null): void {
        if (SyncGuard::is_syncing()) {
            return;
        }

        $currentUser = get_userdata($userId);
        if (!($currentUser instanceof WP_User) || $currentUser->ID <= 0) {
            return;
        }

        if (!$this->eligibility->hasRelevantChanges($currentUser, $oldUserData)) {
            return;
        }

        $this->enqueueUser($userId);
    }

    /**
     * Hook callback: set_user_role.
     *
     * @param int          $userId   WordPress user ID.
     * @param string       $role     New primary role.
     * @param list<string> $oldRoles Previous roles.
     * @return void
     */
    public function onSetUserRole(int $userId, string $role, array $oldRoles = []): void {
        if (SyncGuard::is_syncing()) {
            return;
        }

        $this->enqueueUser($userId);
    }

    /**
     * Hook callback: add_user_role or remove_user_role.
     *
     * @param int    $userId WordPress user ID.
     * @param string $role   Added or removed role slug.
     * @return void
     */
    public function onRoleChanged(int $userId, string $role = ''): void {
        if (SyncGuard::is_syncing()) {
            return;
        }

        $this->enqueueUser($userId);
    }

    private function enqueueUser(int $userId): void {
        if (isset($this->userMap[$userId])) {
            return;
        }
        $this->enqueuer->enqueueUser($userId);
        $this->userMap[$userId] = true;
    }
}
