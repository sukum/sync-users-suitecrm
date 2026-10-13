<?php
/**
 * Shared plugin helpers.
 *
 * @package SyncSuiteCRMUsers
 */

namespace SyncSuiteCRMUsers;

defined('ABSPATH') || exit;

/**
 * Write diagnostic data to PHP's configured error log when debugging is enabled.
 *
 * @param mixed $data Message, array or object to log.
 * @return void
 */
function write_log($data): void {
    if (!defined('WP_DEBUG') || WP_DEBUG !== true) {
        return;
    }

    error_log(is_array($data) || is_object($data) ? print_r($data, true) : (string) $data);
}
