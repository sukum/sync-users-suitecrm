<?php
/**
 * Logs and removes expired automatic queue work without making CRM calls.
 *
 * @package SyncUsersSuitecrm\Sync
 */

namespace SyncUsersSuitecrm\Sync;

use SyncUsersSuitecrm\Model\SyncOutcome;
use SyncUsersSuitecrm\Storage\ActivityLogRepository;
use SyncUsersSuitecrm\Storage\QueueRepository;

defined('ABSPATH') || exit;

class QueueExpiryProcessor {

    public const STALE_DAYS = 4;

    private QueueRepository $queueRepository;
    private ActivityLogRepository $activityLogRepository;

    public function __construct(
        ?QueueRepository $queueRepository = null,
        ?ActivityLogRepository $activityLogRepository = null
    ) {
        $this->queueRepository       = $queueRepository ?? new QueueRepository();
        $this->activityLogRepository = $activityLogRepository ?? new ActivityLogRepository();
    }

    /**
     * Process one bounded selection of expired rows, including when auto sync is disabled.
     *
     * @return int Number of expired items processed.
     */
    public function process(): int {
        $cutoffSeconds = time() - (self::STALE_DAYS * DAY_IN_SECONDS);
        $expiredItems  = $this->queueRepository->getExpiredItems($cutoffSeconds, 100);

        if (is_wp_error($expiredItems) || empty($expiredItems)) {
            return 0;
        }

        $processedCount = 0;
        foreach ($expiredItems as $item) {
            $outcome = SyncOutcome::expired(
                $item->getUserId(),
                null,
                $item->getModule(),
                sprintf(
                    /* translators: %d: Number of days */
                    __('Queue item exceeded %d days stale expiration threshold.', 'sk-user-sync-suitecrm'),
                        esc_html(self::STALE_DAYS)
                )
            );

            try {
                $this->activityLogRepository->insert($outcome->toActivityLogEntry());
            } catch (\Throwable $e) {
                // Log failure must not prevent deletion of expired work.
            }

            $this->queueRepository->deleteByIds([$item->getId()]);
            $processedCount++;
        }

        return $processedCount;
    }
}
