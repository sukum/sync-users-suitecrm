<?php
/**
 * Contract for reporting terminal outcomes for selected modules.
 *
 * @package SyncUsersSuitecrm\Sync\Processing\Handlers
 */

namespace SyncUsersSuitecrm\Sync\Processing\Handlers;

use SyncUsersSuitecrm\Sync\ChunkHandler;

defined('ABSPATH') || exit;

interface OutcomeHandler {

    /**
     * Report one outcome per module without completing the user.
     *
     * @param int          $userId WordPress user ID.
     * @param string|null  $username Current username, when available.
     * @param list<string> $modules Candidate modules, or a one-element list.
     * @param ChunkHandler $handler Source-specific bookkeeping handler.
     * @param string|null  $errorMessage Failure details, when available.
     * @return list<string> Handled modules in the supplied order.
     */
    public function handle(
        int $userId,
        ?string $username,
        array $modules,
        ChunkHandler $handler,
        ?string $errorMessage = null
    ): array;
}
