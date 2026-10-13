<?php
/**
 * Activity log entry data object.
 *
 * Immutable data object representing one outcome for logging or display.
 * Its database-generated id is null before insertion.
 *
 * @package SyncSuiteCRMUsers
 */

namespace SyncSuiteCRMUsers\Model;

defined('ABSPATH') || exit;

class ActivityLogEntry {

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

    /**
     * Database-generated ID (null before insertion).
     *
     * @var int|null
     */
    private ?int $id;

    /**
     * UTC Unix timestamp.
     *
     * @var int
     */
    private int $timestamp;

    /**
     * Direction ('wp_to_crm' in v1).
     *
     * @var string
     */
    private string $direction;

    /**
     * WordPress user ID (nullable).
     *
     * @var int|null
     */
    private ?int $userId;

    /**
     * Username snapshot (nullable).
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
     * CRM record ID (nullable).
     *
     * @var string|null
     */
    private ?string $crmRecordId;

    /**
     * Action performed ('create', 'update', 'none', 'expire').
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
     * Outcome details or error message.
     *
     * @var string
     */
    private string $details;

    /**
     * Constructor.
     *
     * @param int|null    $id          Database ID or null if unpersisted.
     * @param int         $timestamp   UTC Unix seconds.
     * @param string      $direction   'wp_to_crm'.
     * @param int|null    $userId      WordPress user ID.
     * @param string|null $username    Username login snapshot.
     * @param string      $module      SuiteCRM module name.
     * @param string|null $crmRecordId Target CRM record ID.
     * @param string      $action      'create'|'update'|'none'|'expire'.
     * @param string      $status      'success'|'failure'|'skipped'.
     * @param string      $details     Sanitized explanation string.
     */
    public function __construct(
        ?int $id,
        int $timestamp,
        string $direction,
        ?int $userId,
        ?string $username,
        string $module,
        ?string $crmRecordId,
        string $action,
        string $status,
        string $details
    ) {
        $this->id          = $id;
        $this->timestamp   = $timestamp;
        $this->direction   = $direction;
        $this->userId      = $userId !== null && $userId > 0 ? $userId : null;
        $this->username    = $username !== null && $username !== '' ? $username : null;
        $this->module      = $module;
        $this->crmRecordId = $crmRecordId !== null && $crmRecordId !== '' ? $crmRecordId : null;
        $this->action      = $action;
        $this->status      = $status;
        $this->details     = $details;
    }

    /**
     * Get database ID.
     *
     * @return int|null
     */
    public function getId(): ?int {
        return $this->id;
    }

    /**
     * Get UTC Unix timestamp.
     *
     * @return int
     */
    public function getTimestamp(): int {
        return $this->timestamp;
    }

    /**
     * Get direction.
     *
     * @return string
     */
    public function getDirection(): string {
        return $this->direction;
    }

    /**
     * Get WordPress user ID.
     *
     * @return int|null
     */
    public function getUserId(): ?int {
        return $this->userId;
    }

    /**
     * Get username snapshot.
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
     * Get CRM record ID.
     *
     * @return string|null
     */
    public function getCrmRecordId(): ?string {
        return $this->crmRecordId;
    }

    /**
     * Get outcome action.
     *
     * @return string
     */
    public function getAction(): string {
        return $this->action;
    }

    /**
     * Get outcome status.
     *
     * @return string
     */
    public function getStatus(): string {
        return $this->status;
    }

    /**
     * Get outcome details.
     *
     * @return string
     */
    public function getDetails(): string {
        return $this->details;
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return [
            'id'            => $this->id,
            'timestamp'     => $this->timestamp,
            'direction'     => $this->direction,
            'user_id'       => $this->userId,
            'username'      => $this->username,
            'module'        => $this->module,
            'crm_record_id' => $this->crmRecordId,
            'action'        => $this->action,
            'status'        => $this->status,
            'details'       => $this->details,
        ];
    }

    /**
     * Hydrate ActivityLogEntry from database row or array.
     *
     * @param object|array<string, mixed> $row
     * @return self
     */
    public static function fromRow($row): self {
        $data = is_object($row) ? get_object_vars($row) : $row;

        $id          = isset($data['id']) ? (int) $data['id'] : null;
        $rawDate     = isset($data['timestamp']) ? (string) $data['timestamp'] : '';
        $direction   = isset($data['direction']) ? (string) $data['direction'] : 'wp_to_crm';
        $userId      = isset($data['user_id']) && $data['user_id'] !== null ? (int) $data['user_id'] : null;
        $username    = isset($data['username']) && $data['username'] !== null ? (string) $data['username'] : null;
        $module      = isset($data['module']) ? (string) $data['module'] : '';
        $crmRecordId = isset($data['crm_record_id']) && $data['crm_record_id'] !== null ? (string) $data['crm_record_id'] : null;
        $action      = isset($data['action']) ? (string) $data['action'] : 'none';
        $status      = isset($data['status']) ? (string) $data['status'] : 'failure';
        $details     = isset($data['details']) ? (string) $data['details'] : '';

        if (is_numeric($rawDate)) {
            $timestamp = (int) $rawDate;
        } else {
            $parsed = strtotime($rawDate . ' UTC');
            $timestamp = ($parsed !== false) ? $parsed : time();
        }

        return new self(
            $id,
            $timestamp,
            $direction,
            $userId,
            $username,
            $module,
            $crmRecordId,
            $action,
            $status,
            $details
        );
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
     * Create an unpersisted ActivityLogEntry from a SyncOutcome domain object.
     *
     * @param SyncOutcome $outcome
     * @return self
     */
    public static function fromOutcome(SyncOutcome $outcome): self {
        return $outcome->toActivityLogEntry();
    }

    /**
     * Alias for fromRow when dealing with associative arrays.
     *
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self {
        return self::fromRow($data);
    }
}
