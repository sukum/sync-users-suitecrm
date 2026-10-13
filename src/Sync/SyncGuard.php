<?php
/**
 * Recursion and synchronization loop prevention guard.
 *
 * Provides a lightweight thread-local re-entrancy context so programmatic
 * WordPress user mutations originating from the synchronization engine
 * are never re-queued for outbound CRM push.
 *
 * @package SyncSuiteCRMUsers\Sync
 */

namespace SyncSuiteCRMUsers\Sync;

defined('ABSPATH') || exit;

class SyncGuard {

    /**
     * Active synchronization in-progress flag for the current execution thread.
     *
     * @var bool
     */
    private static bool $isSyncing = false;

    /**
     * Report whether an active synchronization execution is in progress.
     *
     * @return bool
     */
    public static function is_syncing(): bool {
        return self::$isSyncing;
    }

    /**
     * CamelCase alias for is_syncing().
     *
     * @return bool
     */
    public static function isSyncing(): bool {
        return self::is_syncing();
    }

    /**
     * Execute a callback while setting the re-entrancy guard flag.
     *
     * Restores the previous guard state in a finally block so nested
     * executions and exceptions safely preserve thread state.
     *
     * @template T
     * @param callable(): T $callback Callable to execute inside the guard context.
     * @return T Return value of the callback.
     */
    public static function prevent_loop(callable $callback) {
        $previous = self::$isSyncing;
        self::$isSyncing = true;

        try {
            return $callback();
        } finally {
            self::$isSyncing = $previous;
        }
    }

    /**
     * CamelCase alias for prevent_loop().
     *
     * @template T
     * @param callable(): T $callback Callable to execute inside the guard context.
     * @return T Return value of the callback.
     */
    public static function preventLoop(callable $callback) {
        return self::prevent_loop($callback);
    }
}
