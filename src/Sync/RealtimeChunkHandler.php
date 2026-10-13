<?php
/**
 * Loopback continuation for automatic real-time queue chunks.
 *
 * @package SyncSuiteCRMUsers\Sync
 */

namespace SyncSuiteCRMUsers\Sync;

use SyncSuiteCRMUsers\Model\QueueItem;
use SyncSuiteCRMUsers\Settings;
use SyncSuiteCRMUsers\Storage\QueueRepository;

defined('ABSPATH') || exit;

class RealtimeChunkHandler extends AbstractQueueChunkHandler {

    private int $initialCount;
    private int $chunkSize;

    /**
     * @param array<int, array<string, QueueItem>> $queueRowsMap
     */
    public function __construct(
        int $connRev,
        array $queueRowsMap,
        int $initialCount,
        Settings $settings,
        QueueRepository $queueRepo,
        Scheduler $scheduler,
        int $chunkSize
    ) {
        parent::__construct($connRev, $queueRowsMap, $settings, $queueRepo, $scheduler, 'realtime');
        $this->initialCount = $initialCount;
        $this->chunkSize    = $chunkSize;
    }

    public function afterDone(array $summary): void {
        // A full chunk may leave unvisited work for another loopback.
        if ($this->initialCount >= $this->chunkSize && !$summary['stopped'] && $this->shouldContinue()) {
            $this->scheduler->dispatchRealtimeLoopback();
        }
    }
}
