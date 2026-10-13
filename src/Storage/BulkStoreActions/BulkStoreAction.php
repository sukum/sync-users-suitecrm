<?php
/**
 * Contract for bulk progress changes evaluated under the store's lock.
 *
 * @package SyncSuiteCRMUsers\Storage\BulkStoreActions
 */

namespace SyncSuiteCRMUsers\Storage\BulkStoreActions;

use SyncSuiteCRMUsers\Model\BulkProgress;
use WP_Error;

defined('ABSPATH') || exit;

interface BulkStoreAction {

    /**
     * Validate and apply a change to freshly loaded progress without storage I/O.
     *
     * @param BulkProgress|null $current Current stored snapshot, or null if absent.
     * @param bool $isStale Whether the current snapshot is a stale running run.
     * @return BulkStoreActionResult|WP_Error
     */
    public function apply(?BulkProgress $current, bool $isStale);
}
