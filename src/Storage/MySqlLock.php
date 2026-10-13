<?php
/**
 * Non-blocking MySQL advisory lock scoped to a WordPress installation.
 *
 * @package SyncSuiteCRMUsers\Storage
 */

namespace SyncSuiteCRMUsers\Storage;

defined('ABSPATH') || exit;

class MySqlLock {

    private string $name;

    public function __construct(string $resource) {
        global $wpdb;

        // MySQL lock names are server-wide and limited to 64 characters.
        $this->name = 'ssu:' . sha1(DB_NAME . ':' . $wpdb->prefix . ':' . $resource);
    }

    public function acquire(): bool {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, 0)', $this->name)
        ) === 1;
    }

    public function release(): bool {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->name)
        ) === 1;
    }
}
