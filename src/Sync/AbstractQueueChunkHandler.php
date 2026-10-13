<?php
/**
 * Shared lifecycle and outcome bookkeeping for automatic queue chunks.
 *
 * @package SyncSuiteCRMUsers\Sync
 */

namespace SyncSuiteCRMUsers\Sync;

use SyncSuiteCRMUsers\Model\QueueItem;
use SyncSuiteCRMUsers\Model\SyncOutcome;
use SyncSuiteCRMUsers\Settings;
use SyncSuiteCRMUsers\Storage\QueueRepository;

defined('ABSPATH') || exit;

abstract class AbstractQueueChunkHandler implements ChunkHandler {

    protected int $connRev;
    protected Scheduler $scheduler;

    /** @var array<int, array<string, QueueItem>> */
    private array $queueRowsMap;
    private Settings $settings;
    private QueueRepository $queueRepo;
    private string $mode;

    /**
     * @param array<int, array<string, QueueItem>> $queueRowsMap Selected rows by user and module.
     */
    protected function __construct(
        int $connRev,
        array $queueRowsMap,
        Settings $settings,
        QueueRepository $queueRepo,
        Scheduler $scheduler,
        string $mode
    ) {
        $this->connRev      = $connRev;
        $this->queueRowsMap = $queueRowsMap;
        $this->settings     = $settings;
        $this->queueRepo    = $queueRepo;
        $this->scheduler    = $scheduler;
        $this->mode         = $mode;
    }

    public function connectionRevision(): int {
        return $this->connRev;
    }

    public function modulesForUser(int $userId): array {
        return array_keys($this->queueRowsMap[$userId] ?? []);
    }

    public function shouldContinue(): bool {
        return $this->settings->isAutoSyncEnabled()
            && $this->settings->getAutoSyncMode() === $this->mode
            && $this->settings->getConnectionRevision() === $this->connRev;
    }

    public function onOutcome(SyncOutcome $outcome): void {
        $userId = $outcome->getUserId();
        $module = $outcome->getModule();
        if (isset($this->queueRowsMap[$userId][$module])) {
            $item = $this->queueRowsMap[$userId][$module];
            $this->queueRepo->deleteByIds([$item->getId()]);
        }
    }

    public function onUserComplete(int $userId): void {
        // Automatic queue processing has no user-level counters.
    }
}
