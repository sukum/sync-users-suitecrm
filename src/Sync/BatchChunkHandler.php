<?php
/**
 * Cursor advancement and continuation for bounded automatic batch chunks.
 *
 * @package SyncUsersSuitecrm\Sync
 */

namespace SyncUsersSuitecrm\Sync;

use SyncUsersSuitecrm\Model\QueueItem;
use SyncUsersSuitecrm\Settings;
use SyncUsersSuitecrm\Storage\QueueRepository;

defined('ABSPATH') || exit;

class BatchChunkHandler extends AbstractQueueChunkHandler {

    /** @var list<QueueItem> Rows in ascending queue ID order. */
    private array $selectedItems;
    private int $lastQueueId;
    private int $maxQueueId;

    /**
     * @param array<int, array<string, QueueItem>> $queueRowsMap
     * @param list<QueueItem> $selectedItems
     */
    public function __construct(
        int $connRev,
        array $queueRowsMap,
        array $selectedItems,
        int $lastQueueId,
        int $maxQueueId,
        Settings $settings,
        QueueRepository $queueRepo,
        Scheduler $scheduler
    ) {
        parent::__construct($connRev, $queueRowsMap, $settings, $queueRepo, $scheduler, 'batch');
        $this->selectedItems = $selectedItems;
        $this->lastQueueId   = $lastQueueId;
        $this->maxQueueId    = $maxQueueId;
    }

    public function afterDone(array $summary): void {
        $handledMap = $summary['handled_modules'];
        $newCursor  = $this->lastQueueId;

        // User grouping can handle later rows first; never advance past a gap.
        foreach ($this->selectedItems as $item) {
            $userId = $item->getUserId();
            $module = $item->getModule();
            if (!isset($handledMap[$userId]) || !in_array($module, $handledMap[$userId], true)) {
                break;
            }
            $newCursor = max($newCursor, $item->getId());
        }

        if ($newCursor < $this->maxQueueId && $this->shouldContinue() && !$summary['stopped']) {
            $this->scheduler->scheduleBatchContinuation([
                'connection_rev' => $this->connRev,
                'auto_sync_mode' => 'batch',
                'last_queue_id'  => $newCursor,
                'max_queue_id'   => $this->maxQueueId,
            ]);
        }
    }
}
