<?php
/**
 * Shared synchronization outcome data object.
 *
 * Immutable data object representing one terminal user/module synchronization outcome.
 * Independent of whether audit logging succeeds.
 *
 * @package SyncSuiteCRMUsers
 */

namespace SyncSuiteCRMUsers\Model;

use InvalidArgumentException;

defined('ABSPATH') || exit;

class SyncOutcome {

    // Directions
    public const DIRECTION_WP_TO_CRM = 'wp_to_crm';
    public const DIRECTION_CRM_TO_WP = 'crm_to_wp';

    // Statuses
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILURE = 'failure';
    public const STATUS_SKIPPED = 'skipped';

    // Actions
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_NONE   = 'none';
    public const ACTION_EXPIRE = 'expire';

    // Modules
    public const MODULE_ACCOUNTS = 'Accounts';
    public const MODULE_CONTACTS = 'Contacts';
    public const MODULE_LEADS    = 'Leads';
    public const MODULE_USERS    = 'Users';

    // Canonical Reasons: Success
    public const REASON_CREATED = 'created';
    public const REASON_UPDATED = 'updated';

    // Canonical Reasons: Skips
    public const REASON_EXISTING_RECORD = 'existing_record';
    public const REASON_USER_MISSING    = 'user_missing';
    public const REASON_ROLE_INELIGIBLE = 'role_ineligible';
    public const REASON_MODULE_DISABLED = 'module_disabled';
    public const REASON_LOCK_CONFLICT   = 'lock_conflict';

    // Canonical Reasons: Failures
    public const REASON_QUEUE_EXPIRED               = 'queue_expired';
    public const REASON_AMBIGUOUS_MATCH             = 'ambiguous_match';
    public const REASON_AUTHENTICATION_UNAVAILABLE = 'authentication_unavailable';
    public const REASON_TRANSPORT_ERROR             = 'transport_error';
    public const REASON_API_ERROR                   = 'api_error';
    public const REASON_INVALID_RESPONSE            = 'invalid_response';
    public const REASON_INVALID_USER_DATA           = 'invalid_user_data';
    public const REASON_CONNECTION_CHANGED          = 'connection_changed';
    public const REASON_STORAGE_ERROR               = 'storage_error';

    public const ALLOWED_DIRECTIONS = [
        self::DIRECTION_WP_TO_CRM,
        self::DIRECTION_CRM_TO_WP,
    ];

    public const ALLOWED_STATUSES = [
        self::STATUS_SUCCESS,
        self::STATUS_FAILURE,
        self::STATUS_SKIPPED,
    ];

    public const ALLOWED_ACTIONS = [
        self::ACTION_CREATE,
        self::ACTION_UPDATE,
        self::ACTION_NONE,
        self::ACTION_EXPIRE,
    ];

    public const ALLOWED_MODULES = [
        self::MODULE_ACCOUNTS,
        self::MODULE_CONTACTS,
        self::MODULE_LEADS,
        self::MODULE_USERS,
    ];

    public const CANONICAL_REASONS = [
        self::REASON_CREATED,
        self::REASON_UPDATED,
        self::REASON_EXISTING_RECORD,
        self::REASON_USER_MISSING,
        self::REASON_ROLE_INELIGIBLE,
        self::REASON_MODULE_DISABLED,
        self::REASON_LOCK_CONFLICT,
        self::REASON_QUEUE_EXPIRED,
        self::REASON_AMBIGUOUS_MATCH,
        self::REASON_AUTHENTICATION_UNAVAILABLE,
        self::REASON_TRANSPORT_ERROR,
        self::REASON_API_ERROR,
        self::REASON_INVALID_RESPONSE,
        self::REASON_INVALID_USER_DATA,
        self::REASON_CONNECTION_CHANGED,
        self::REASON_STORAGE_ERROR,
    ];

    /**
     * UTC outcome Unix timestamp.
     *
     * @var int
     */
    private int $timestamp;

    /**
     * Synchronization direction ('wp_to_crm').
     *
     * @var string
     */
    private string $direction;

    /**
     * Positive WordPress user ID.
     *
     * @var int
     */
    private int $userId;

    /**
     * Username login snapshot when available (null if user deleted).
     *
     * @var string|null
     */
    private ?string $username;

    /**
     * SuiteCRM module name (Accounts, Contacts, Leads, Users).
     *
     * @var string
     */
    private string $module;

    /**
     * Known target or created CRM record ID (null if unmapped or failed create).
     *
     * @var string|null
     */
    private ?string $crmRecordId;

    /**
     * Outcome action ('create', 'update', 'none', 'expire').
     *
     * @var string
     */
    private string $action;

    /**
     * Outcome status ('success', 'failure', 'skipped').
     *
     * @var string
     */
    private string $status;

    /**
     * Stable machine-readable outcome reason code.
     *
     * @var string
     */
    private string $reason;

    /**
     * Sanitized plain explanation, possibly empty on success.
     *
     * @var string
     */
    private string $details;

    /**
     * Constructor.
     *
     * - Success requires action = 'create'|'update' and a valid crmRecordId.
     * - Skips enforce action = 'none'.
     * - Expiry enforces action = 'expire', status = 'failure', and reason = 'queue_expired'.
     * - Missing user retains selected ID with null username.
     *
     * @param int         $timestamp   UTC outcome Unix timestamp.
     * @param string      $direction   'wp_to_crm' (or 'crm_to_wp').
     * @param int         $userId      Positive WordPress user ID.
     * @param string|null $username    Username login snapshot or null.
     * @param string      $module      Module name ('Accounts', 'Contacts', 'Leads', 'Users').
     * @param string|null $crmRecordId Known target/created CRM record ID.
     * @param string      $action      'create'|'update'|'none'|'expire'.
     * @param string      $status      'success'|'failure'|'skipped'.
     * @param string      $reason      Stable machine-readable reason code.
     * @param string      $details     Sanitized plain explanation.
     * @throws InvalidArgumentException When validation invariants fail.
     */
    public function __construct(
        int $timestamp,
        string $direction,
        int $userId,
        ?string $username,
        string $module,
        ?string $crmRecordId,
        string $action,
        string $status,
        string $reason,
        string $details = ''
    ) {
        if ($userId <= 0) {
            throw new InvalidArgumentException(
                sprintf('SyncOutcome requires a positive user_id, received: %d', (int) $userId)
            );
        }

        if (!in_array($direction, self::ALLOWED_DIRECTIONS, true)) {
            $direction = self::DIRECTION_WP_TO_CRM;
        }

        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(
                esc_html(sprintf('Invalid SyncOutcome status: %s', $status))
            );
        }

        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            throw new InvalidArgumentException(
                esc_html(sprintf('Invalid SyncOutcome action: %s', $action))
            );
        }

        if (!in_array($module, self::ALLOWED_MODULES, true)) {
            throw new InvalidArgumentException(
                esc_html(sprintf('Invalid SyncOutcome module: %s', $module))
            );
        }

        $trimmedRecordId = ($crmRecordId !== null && trim($crmRecordId) !== '') ? trim($crmRecordId) : null;
        $trimmedUsername = ($username !== null && trim($username) !== '') ? trim($username) : null;

        // Invariant: Success requires action 'create' or 'update' and a CRM ID
        if ($status === self::STATUS_SUCCESS) {
            if ($action !== self::ACTION_CREATE && $action !== self::ACTION_UPDATE) {
                throw new InvalidArgumentException(
                    esc_html(sprintf('Success status requires action to be create or update, got: %s', $action))
                );
            }
            if ($trimmedRecordId === null) {
                throw new InvalidArgumentException(
                    'Success status requires a non-empty crm_record_id.'
                );
            }
        }

        // Invariant: Skips use action = 'none'
        if ($status === self::STATUS_SKIPPED) {
            $action = self::ACTION_NONE;
        }

        // Invariant: Expiry uses action = 'expire', status = 'failure', reason = 'queue_expired'
        if ($action === self::ACTION_EXPIRE) {
            $status = self::STATUS_FAILURE;
            $reason = self::REASON_QUEUE_EXPIRED;
        }

        $this->timestamp   = $timestamp > 0 ? $timestamp : time();
        $this->direction   = $direction;
        $this->userId      = $userId;
        $this->username    = $trimmedUsername;
        $this->module      = $module;
        $this->crmRecordId = $trimmedRecordId;
        $this->action      = $action;
        $this->status      = $status;
        $this->reason      = $reason;
        $this->details     = trim($details);
    }

    /**
     * Factory: Successful outcome.
     *
     * @param int         $userId      WordPress user ID.
     * @param string|null $username    Username snapshot.
     * @param string      $module      CRM module name.
     * @param string      $crmRecordId Created or updated CRM record UUID.
     * @param string      $action      'create' or 'update'.
     * @param string      $reason      'created' or 'updated'.
     * @param string      $details     Explanation string.
     * @param int|null    $timestamp   UTC timestamp or null for current time.
     * @return self
     */
    public static function success(
        int $userId,
        ?string $username,
        string $module,
        string $crmRecordId,
        string $action = self::ACTION_CREATE,
        string $reason = self::REASON_CREATED,
        string $details = '',
        ?int $timestamp = null
    ): self {
        return new self(
            $timestamp ?? time(),
            self::DIRECTION_WP_TO_CRM,
            $userId,
            $username,
            $module,
            $crmRecordId,
            $action,
            self::STATUS_SUCCESS,
            $reason,
            $details
        );
    }

    /**
     * Factory: Skipped outcome.
     *
     * Action is automatically 'none'.
     *
     * @param int         $userId      WordPress user ID.
     * @param string|null $username    Username snapshot.
     * @param string      $module      CRM module name.
     * @param string      $reason      Skip reason code.
     * @param string      $details     Explanation string.
     * @param string|null $crmRecordId Existing CRM record ID if known.
     * @param int|null    $timestamp   UTC timestamp or null for current time.
     * @return self
     */
    public static function skipped(
        int $userId,
        ?string $username,
        string $module,
        string $reason,
        string $details = '',
        ?string $crmRecordId = null,
        ?int $timestamp = null
    ): self {
        return new self(
            $timestamp ?? time(),
            self::DIRECTION_WP_TO_CRM,
            $userId,
            $username,
            $module,
            $crmRecordId,
            self::ACTION_NONE,
            self::STATUS_SKIPPED,
            $reason,
            $details
        );
    }

    /**
     * Factory: Failed outcome.
     *
     * @param int         $userId      WordPress user ID.
     * @param string|null $username    Username snapshot.
     * @param string      $module      CRM module name.
     * @param string      $reason      Failure reason code.
     * @param string      $details     Sanitized error details.
     * @param string|null $crmRecordId Target CRM record ID if known.
     * @param string      $action      Attempted action ('create', 'update', or 'none').
     * @param int|null    $timestamp   UTC timestamp or null for current time.
     * @return self
     */
    public static function failure(
        int $userId,
        ?string $username,
        string $module,
        string $reason,
        string $details = '',
        ?string $crmRecordId = null,
        string $action = self::ACTION_NONE,
        ?int $timestamp = null
    ): self {
        return new self(
            $timestamp ?? time(),
            self::DIRECTION_WP_TO_CRM,
            $userId,
            $username,
            $module,
            $crmRecordId,
            $action,
            self::STATUS_FAILURE,
            $reason,
            $details
        );
    }

    /**
     * Factory: Queue expired outcome.
     *
     * action = 'expire', status = 'failure', reason = 'queue_expired'.
     *
     * @param int         $userId    WordPress user ID.
     * @param string|null $username  Username snapshot.
     * @param string      $module    CRM module name.
     * @param string      $details   Explanation string.
     * @param int|null    $timestamp UTC timestamp or null for current time.
     * @return self
     */
    public static function expired(
        int $userId,
        ?string $username,
        string $module,
        string $details = '',
        ?int $timestamp = null
    ): self {
        return new self(
            $timestamp ?? time(),
            self::DIRECTION_WP_TO_CRM,
            $userId,
            $username,
            $module,
            null,
            self::ACTION_EXPIRE,
            self::STATUS_FAILURE,
            self::REASON_QUEUE_EXPIRED,
            $details
        );
    }

    /**
     * Get outcome timestamp in UTC Unix seconds.
     *
     * @return int
     */
    public function getTimestamp(): int {
        return $this->timestamp;
    }

    /**
     * Get synchronization direction.
     *
     * @return string
     */
    public function getDirection(): string {
        return $this->direction;
    }

    /**
     * Get WordPress user ID.
     *
     * @return int
     */
    public function getUserId(): int {
        return $this->userId;
    }

    /**
     * Get username snapshot. Null if user no longer exists.
     *
     * @return string|null
     */
    public function getUsername(): ?string {
        return $this->username;
    }

    /**
     * Get module name.
     *
     * @return string
     */
    public function getModule(): string {
        return $this->module;
    }

    /**
     * Get CRM record ID. Null if unmapped or failed creation.
     *
     * @return string|null
     */
    public function getCrmRecordId(): ?string {
        return $this->crmRecordId;
    }

    /**
     * Get outcome action ('create', 'update', 'none', 'expire').
     *
     * @return string
     */
    public function getAction(): string {
        return $this->action;
    }

    /**
     * Get outcome status ('success', 'failure', 'skipped').
     *
     * @return string
     */
    public function getStatus(): string {
        return $this->status;
    }

    /**
     * Get machine-readable reason code.
     *
     * @return string
     */
    public function getReason(): string {
        return $this->reason;
    }

    /**
     * Get sanitized details explanation.
     *
     * @return string
     */
    public function getDetails(): string {
        return $this->details;
    }

    /**
     * Whether outcome is success.
     *
     * @return bool
     */
    public function isSuccess(): bool {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * Whether outcome is failure.
     *
     * @return bool
     */
    public function isFailure(): bool {
        return $this->status === self::STATUS_FAILURE;
    }

    /**
     * Whether outcome is skipped.
     *
     * @return bool
     */
    public function isSkipped(): bool {
        return $this->status === self::STATUS_SKIPPED;
    }

    /**
     * Convert this outcome into an ActivityLogEntry data object.
     *
     * Copies common fields and formats persisted details as the reason code
     * followed by any nonempty explanation.
     *
     * @return ActivityLogEntry
     */
    public function toActivityLogEntry(): ActivityLogEntry {
        $formattedDetails = $this->reason;
        if ($this->details !== '') {
            $formattedDetails .= ': ' . $this->details;
        }

        return new ActivityLogEntry(
            null,
            $this->timestamp,
            $this->direction,
            $this->userId,
            $this->username,
            $this->module,
            $this->crmRecordId,
            $this->action,
            $this->status,
            $formattedDetails
        );
    }

    /**
     * Convert to plain associative array matching SyncOutcomeSnapshot schema.
     *
     * @return array{
     *     timestamp: int,
     *     direction: string,
     *     user_id: int,
     *     username: string|null,
     *     module: string,
     *     crm_record_id: string|null,
     *     action: string,
     *     status: string,
     *     reason: string,
     *     details: string
     * }
     */
    public function toArray(): array {
        return [
            'timestamp'     => $this->timestamp,
            'direction'     => $this->direction,
            'user_id'       => $this->userId,
            'username'      => $this->username,
            'module'        => $this->module,
            'crm_record_id' => $this->crmRecordId,
            'action'        => $this->action,
            'status'        => $this->status,
            'reason'        => $this->reason,
            'details'       => $this->details,
        ];
    }

    /**
     * Hydrate SyncOutcome from array.
     *
     * @param array<string, mixed> $data
     * @return self|null Returns null if array data is malformed.
     */
    public static function fromArray(array $data): ?self {
        if (
            empty($data['user_id']) ||
            !is_numeric($data['user_id']) ||
            empty($data['module']) ||
            !is_string($data['module']) ||
            empty($data['status']) ||
            !is_string($data['status']) ||
            empty($data['reason']) ||
            !is_string($data['reason'])
        ) {
            return null;
        }

        $timestamp   = isset($data['timestamp']) ? (int) $data['timestamp'] : time();
        $direction   = isset($data['direction']) ? (string) $data['direction'] : self::DIRECTION_WP_TO_CRM;
        $userId      = (int) $data['user_id'];
        $username    = isset($data['username']) && $data['username'] !== null ? (string) $data['username'] : null;
        $module      = (string) $data['module'];
        $crmRecordId = isset($data['crm_record_id']) && $data['crm_record_id'] !== null ? (string) $data['crm_record_id'] : null;
        $action      = isset($data['action']) ? (string) $data['action'] : self::ACTION_NONE;
        $status      = (string) $data['status'];
        $reason      = (string) $data['reason'];
        $details     = isset($data['details']) ? (string) $data['details'] : '';

        try {
            return new self(
                $timestamp,
                $direction,
                $userId,
                $username,
                $module,
                $crmRecordId,
                $action,
                $status,
                $reason,
                $details
            );
        } catch (InvalidArgumentException $e) {
            return null;
        }
    }
}
