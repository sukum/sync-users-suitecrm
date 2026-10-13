<?php
/**
 * Explicit save or delete instruction returned by a bulk store action.
 *
 * @package SyncUsersSuitecrm\Storage\BulkStoreActions
 */

namespace SyncUsersSuitecrm\Storage\BulkStoreActions;

use LogicException;
use SyncUsersSuitecrm\Model\BulkProgress;

defined('ABSPATH') || exit;

final class BulkStoreActionResult {

    public const SAVE = 'save';
    public const DELETE = 'delete';

    private string $operation;
    private ?BulkProgress $progress;
    private string $failureMessage;

    private function __construct(string $operation, ?BulkProgress $progress, string $failureMessage) {
        $this->operation      = $operation;
        $this->progress       = $progress;
        $this->failureMessage = $failureMessage;
    }

    public static function save(BulkProgress $progress, string $failureMessage): self {
        return new self(self::SAVE, $progress, $failureMessage);
    }

    public static function delete(string $failureMessage): self {
        return new self(self::DELETE, null, $failureMessage);
    }

    public function getOperation(): string {
        return $this->operation;
    }

    /** Retrieve the progress to persist for a SAVE result. */
    public function getProgress(): BulkProgress {
        if ($this->progress === null) {
            throw new LogicException('A delete result has no progress to save.');
        }
        return $this->progress;
    }

    public function getFailureMessage(): string {
        return $this->failureMessage;
    }
}
