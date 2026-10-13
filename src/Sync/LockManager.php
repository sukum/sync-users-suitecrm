<?php
/**
 * Resource-keyed mutex locks stored directly in the WordPress options table.
 *
 * Bypasses option caches and uses conditional SQL writes for ownership changes.
 *
 * @package SyncUsersSuitecrm\Sync
 */

namespace SyncUsersSuitecrm\Sync;

use SyncUsersSuitecrm\Model\LockHandle;
use WP_Error;

defined('ABSPATH') || exit;

class LockManager {

    public const DEFAULT_TTL = 3600; // 1 hour horizon

    /**
     * Option prefix for locks.
     */
    public const OPTION_PREFIX = '_syus_lock_';

    /**
     * Acquire a resource lock.
     *
     * Inserts a unique owner and UTC expiration, or conditionally replaces an
     * expired payload. Contention returns immediately without retrying.
     *
     * @param string $resourceKey Resource identifier (e.g. 'bulk_progress', 'user_42', 'token_refresh').
     * @param int    $ttl         Time to live in seconds.
     * @return LockHandle|WP_Error|null Returns LockHandle on success, null on lock conflict, or WP_Error on failure.
     */
    public function acquire(string $resourceKey, int $ttl = self::DEFAULT_TTL) {
        global $wpdb;

        $optionName = self::OPTION_PREFIX . $resourceKey;
        $token      = wp_generate_uuid4();
        $expires    = time() + $ttl;

        $payload = [
            'owner'   => $token,
            'expires' => $expires,
        ];
        $storedPayload = maybe_serialize($payload);

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $optionName,
            $storedPayload
        ));
        if ($inserted === false) {
            return new WP_Error('lock_storage_error', __('Failed to insert synchronization lock.', 'sync-users-suitecrm'), ['http_status' => 500]);
        }
        if ($inserted === 1) {
            return new LockHandle($resourceKey, $token, $expires);
        }

        $existingValue = $this->readStoredValue($optionName);
        if (is_wp_error($existingValue)) {
            return $existingValue;
        }
        if ($existingValue === null) {
            // Another worker released the lock after our insertion attempt.
            return null;
        }

        $existing = maybe_unserialize($existingValue);
        if (!$this->isValidPayload($existing)) {
            return new WP_Error('invalid_lock', __('Stored synchronization lock is malformed.', 'sync-users-suitecrm'), ['http_status' => 500]);
        }
        if (time() <= $existing['expires']) {
            return null;
        }

        // Replace only the expired payload we read; another owner's lock is untouched.
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s, autoload = 'no' WHERE option_name = %s AND BINARY option_value = %s",
            $storedPayload,
            $optionName,
            $existingValue
        ));
        if ($updated === false) {
            return new WP_Error('lock_storage_error', __('Failed to replace synchronization lock.', 'sync-users-suitecrm'), ['http_status' => 500]);
        }

        return $updated === 1 ? new LockHandle($resourceKey, $token, $expires) : null;
    }

    /**
     * Release an acquired lock.
     *
     * Deletes only if the complete stored payload still matches the handle.
     *
     * @param LockHandle|null $handle LockHandle to release or null.
     * @return bool|WP_Error True if deleted or given a null handle; false if missing/replaced.
     */
    public function release(?LockHandle $handle = null) {
        global $wpdb;

        if ($handle === null) {
            return true;
        }

        $optionName = self::OPTION_PREFIX . $handle->getResourceKey();
        $payload = maybe_serialize([
            'owner'   => $handle->getOwnerToken(),
            'expires' => $handle->getExpiresAt(),
        ]);
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = %s",
            $optionName,
            $payload
        ));
        if ($deleted === false) {
            return new WP_Error('lock_storage_error', __('Failed to release synchronization lock.', 'sync-users-suitecrm'), ['http_status' => 500]);
        }

        return $deleted === 1;
    }

    /**
     * Check if a resource key is currently actively locked.
     * Unreadable or malformed locks are treated as locked.
     *
     * @param string $resourceKey
     * @return bool
     */
    public function isLocked(string $resourceKey): bool {
        $optionName = self::OPTION_PREFIX . $resourceKey;
        $existingValue = $this->readStoredValue($optionName);
        if (is_wp_error($existingValue)) {
            return true;
        }
        if ($existingValue === null) {
            return false;
        }

        $existing = maybe_unserialize($existingValue);
        return !$this->isValidPayload($existing) || time() <= $existing['expires'];
    }

    /** @return string|WP_Error|null Raw payload, read error, or absence. */
    private function readStoredValue(string $optionName) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $optionName
        ));
        if ($wpdb->last_error !== '') {
            return new WP_Error('lock_storage_error', __('Failed to read synchronization lock.', 'sync-users-suitecrm'), ['http_status' => 500]);
        }

        return $row === null ? null : $row->option_value;
    }

    private function isValidPayload($payload): bool {
        return is_array($payload)
            && isset($payload['owner'], $payload['expires'])
            && is_string($payload['owner'])
            && $payload['owner'] !== ''
            && is_int($payload['expires']);
    }
}
