<?php
/**
 * Shared chunk processor for user synchronization.
 *
 * Coordinates user iteration, current-data reads, eligibility checks,
 * atomic user locking, outbound CRM pushes, and common activity logging
 * across all execution paths (automatic real-time, nightly batch, manual bulk,
 * and individual user sync).
 *
 * @package SyncUsersSuitecrm\Sync
 */

namespace SyncUsersSuitecrm\Sync;

use SyncUsersSuitecrm\Model\SyncOutcome;
use SyncUsersSuitecrm\Settings;
use SyncUsersSuitecrm\Storage\ActivityLogRepository;
use SyncUsersSuitecrm\Sync\Processing\Handlers\DisabledModuleHandler;
use SyncUsersSuitecrm\Sync\Processing\Handlers\IneligibleRoleHandler;
use SyncUsersSuitecrm\Sync\Processing\Handlers\LockConflictHandler;
use SyncUsersSuitecrm\Sync\Processing\Handlers\LockStorageErrorHandler;
use SyncUsersSuitecrm\Sync\Processing\Handlers\MissingUserHandler;
use SyncUsersSuitecrm\Sync\Processing\Handlers\OutcomeHandler;
use WP_User;

defined('ABSPATH') || exit;

class ChunkProcessor {

    /**
     * Lock manager service.
     *
     * @var LockManager
     */
    protected LockManager $lockManager;

    /**
     * Outbound push service.
     *
     * @var PushUserToSuiteCRM
     */
    protected PushUserToSuiteCRM $pushService;

    /**
     * Eligibility service.
     *
     * @var Eligibility
     */
    protected Eligibility $eligibility;

    /**
     * Activity log repository.
     *
     * @var ActivityLogRepository
     */
    protected ActivityLogRepository $activityLogRepository;

    /** @var OutcomeHandler */
    protected OutcomeHandler $lockConflictHandler;

    /** @var OutcomeHandler */
    protected OutcomeHandler $lockStorageErrorHandler;

    /** @var OutcomeHandler */
    protected OutcomeHandler $missingUserHandler;

    /** @var OutcomeHandler */
    protected OutcomeHandler $ineligibleRoleHandler;

    /** @var OutcomeHandler */
    protected OutcomeHandler $disabledModuleHandler;

    /**
     * Constructor.
     *
     * @param LockManager|null           $lockManager
     * @param PushUserToSuiteCRM|null    $pushService
     * @param Eligibility|null           $eligibility
     * @param ActivityLogRepository|null $activityLogRepository
     */
    public function __construct(
        ?LockManager $lockManager = null,
        ?PushUserToSuiteCRM $pushService = null,
        ?Eligibility $eligibility = null,
        ?ActivityLogRepository $activityLogRepository = null
    ) {
        $this->lockManager           = $lockManager ?? new LockManager();
        $this->pushService           = $pushService ?? new PushUserToSuiteCRM();
        $this->eligibility           = $eligibility ?? new Eligibility();
        $this->activityLogRepository = $activityLogRepository ?? new ActivityLogRepository();

        $this->lockConflictHandler     = new LockConflictHandler($this->activityLogRepository);
        $this->lockStorageErrorHandler = new LockStorageErrorHandler($this->activityLogRepository);
        $this->missingUserHandler      = new MissingUserHandler($this->activityLogRepository);
        $this->ineligibleRoleHandler   = new IneligibleRoleHandler($this->activityLogRepository);
        $this->disabledModuleHandler   = new DisabledModuleHandler($this->activityLogRepository);
    }

    /**
     * Process an ordered list of WordPress user IDs through a source-specific handler.
     *
     * - Checks shouldContinue() before each user and module.
     * - Acquires resource lock _syus_lock_user_{user_id} before user processing.
     * - Releases acquired locks in finally.
     * - Rechecks current user data, role eligibility, and module enablement immediately before push.
     * - Invokes common logging and handler callbacks for all outcomes.
     * - Never invokes onUserComplete() for partially processed users if traversal stopped.
     *
     * @param list<int>    $userIds List of WordPress user IDs to process.
     * @param ChunkHandler $handler Source-specific bookkeeping handler.
     * @return array{
     *     completed_user_ids: list<int>,
     *     handled_modules: array<int, list<string>>,
     *     stopped: bool
     * }
     */
    public function process(array $userIds, ChunkHandler $handler): array {
        // Deduplicate user IDs preserving order
        $sanitizedUserIds = array_values(
            array_filter(
                array_unique(array_map('intval', $userIds)),
                static function (int $id): bool {
                    return $id > 0;
                }
            )
        );

        $completedUserIds = [];
        $handledModules   = [];
        $stopped          = false;

        // Check handler validity before starting traversal
        if (!$handler->shouldContinue()) {
            $summary = [
                'completed_user_ids' => [],
                'handled_modules'   => [],
                'stopped'            => true,
            ];
            $handler->afterDone($summary);
            return $summary;
        }

        foreach ($sanitizedUserIds as $userId) {
            // Check before each user
            if (!$handler->shouldContinue()) {
                $stopped = true;
                break;
            }

            // Candidate modules for user in canonical order
            $rawCandidateModules = $handler->modulesForUser($userId);
            $candidateModules    = $this->sortCanonicalModules($rawCandidateModules);

            if (empty($candidateModules)) {
                $handler->onUserComplete($userId);
                $completedUserIds[] = $userId;
                continue;
            }

            // Acquire atomic user lock
            $lockKey    = 'user_' . $userId;
            $lockHandle = $this->lockManager->acquire($lockKey);

            if ($lockHandle === null) {
                // Lock conflict held by another process: terminal skip
                $handledModules[$userId] = $this->lockConflictHandler->handle(
                    $userId,
                    null,
                    $candidateModules,
                    $handler
                );
                $handler->onUserComplete($userId);
                $completedUserIds[] = $userId;
                continue;
            }

            if (is_wp_error($lockHandle)) {
                // Operational lock storage failure
                $handledModules[$userId] = $this->lockStorageErrorHandler->handle(
                    $userId,
                    null,
                    $candidateModules,
                    $handler,
                    $lockHandle->get_error_message()
                );
                $handler->onUserComplete($userId);
                $completedUserIds[] = $userId;
                continue;
            }

            // Process user under acquired lock
            try {
                $user = get_userdata($userId);

                // Handle missing user
                if (!($user instanceof WP_User) || $user->ID <= 0) {
                    $handledModules[$userId] = $this->missingUserHandler->handle(
                        $userId,
                        null,
                        $candidateModules,
                        $handler
                    );
                    $handler->onUserComplete($userId);
                    $completedUserIds[] = $userId;
                    continue;
                }

                $username = (string) $user->user_login;

                // Handle role ineligibility
                if (!$this->eligibility->isUserEligible($user)) {
                    $handledModules[$userId] = $this->ineligibleRoleHandler->handle(
                        $userId,
                        $username,
                        $candidateModules,
                        $handler
                    );
                    $handler->onUserComplete($userId);
                    $completedUserIds[] = $userId;
                    continue;
                }

                // Process candidate modules for eligible user
                $userModulesCompleted = true;
                foreach ($candidateModules as $module) {
                    // Check before each module
                    if (!$handler->shouldContinue()) {
                        $stopped              = true;
                        $userModulesCompleted = false;
                        break;
                    }

                    if (!$this->eligibility->isModuleEnabled($module)) {
                        $reportedModules = $this->disabledModuleHandler->handle(
                            $userId,
                            $username,
                            [$module],
                            $handler
                        );
                        $handledModules[$userId] = array_merge($handledModules[$userId] ?? [], $reportedModules);
                        continue;
                    }

                    $mode          = $this->eligibility->getModuleMode($module);
                    $connectionRev = $handler->connectionRevision();
                    $outcome       = $this->pushService->push($user, $module, $mode, $connectionRev);

                    $this->logOutcome($outcome);
                    $handler->onOutcome($outcome);
                    $handledModules[$userId][] = $module;
                }

                if ($userModulesCompleted) {
                    $handler->onUserComplete($userId);
                    $completedUserIds[] = $userId;
                } else {
                    // Traversal stopped mid-user; break outer user loop
                    break;
                }
            } finally {
                $this->lockManager->release($lockHandle);
            }
        }

        $summary = [
            'completed_user_ids' => $completedUserIds,
            'handled_modules'   => $handledModules,
            'stopped'            => $stopped,
        ];

        $handler->afterDone($summary);
        return $summary;
    }

    /**
     * Log outcome to activity log repository.
     *
     * Log write failures do not affect outcome or execution flow.
     *
     * @param SyncOutcome $outcome
     * @return void
     */
    protected function logOutcome(SyncOutcome $outcome): void {
        try {
            $entry = $outcome->toActivityLogEntry();
            $this->activityLogRepository->insert($entry);
        } catch (\Throwable $e) {
            // Failure to write log entry must not abort or replay CRM work
        }
    }

    /**
     * Sort module names in canonical order (Accounts, Contacts, Leads, Users).
     *
     * @param list<string> $modules
     * @return list<string>
     */
    protected function sortCanonicalModules(array $modules): array {
        $canonicalOrder = Settings::SUPPORTED_MODULES;
        $filtered       = array_values(array_intersect($canonicalOrder, array_unique($modules)));
        return $filtered;
    }
}
