<?php
/**
 * Outstanding automatic queue item data object.
 *
 * Immutable data object representing one persisted outstanding automatic work item.
 *
 * @package SyncSuiteCRMUsers
 */

namespace SyncSuiteCRMUsers\Model;

defined('ABSPATH') || exit;

class QueueItem {

    public const MODULE_ACCOUNTS = 'Accounts';
    public const MODULE_CONTACTS = 'Contacts';
    public const MODULE_LEADS    = 'Leads';
    public const MODULE_USERS    = 'Users';

    public const ALLOWED_MODULES = [
        self::MODULE_ACCOUNTS,
        self::MODULE_CONTACTS,
        self::MODULE_LEADS,
        self::MODULE_USERS,
    ];

    /**
     * Primary key ID.
     *
     * @var int
     */
    private int $id;

    /**
     * WordPress user ID.
     *
     * @var int
     */
    private int $userId;

    /**
     * SuiteCRM module name (Accounts, Contacts, Leads, Users).
     *
     * @var string
     */
    private string $module;

    /**
     * Creation timestamp as UTC Unix seconds.
     *
     * @var int
     */
    private int $createdAt;

    /**
     * Constructor.
     *
     * @param int    $id        Positive queue row ID.
     * @param int    $userId    Positive WordPress user ID.
     * @param string $module    SuiteCRM module name.
     * @param int    $createdAt UTC Unix timestamp.
     */
    public function __construct(int $id, int $userId, string $module, int $createdAt) {
        $this->id        = $id;
        $this->userId    = $userId;
        $this->module    = $module;
        $this->createdAt = $createdAt;
    }

    /**
     * Get queue row ID.
     *
     * @return int
     */
    public function getId(): int {
        return $this->id;
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
     * Get target module name.
     *
     * @return string
     */
    public function getModule(): string {
        return $this->module;
    }

    /**
     * Get creation timestamp as UTC Unix seconds.
     *
     * @return int
     */
    public function getCreatedAt(): int {
        return $this->createdAt;
    }

    /**
     * Convert to plain array representation.
     *
     * @return array{id: int, user_id: int, module: string, created_at: int}
     */
    public function toArray(): array {
        return [
            'id'         => $this->id,
            'user_id'    => $this->userId,
            'module'     => $this->module,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * Hydrate QueueItem from database row or array.
     *
     * @param object|array<string, mixed> $row Database row object or associative array.
     * @return self
     */
    public static function fromRow($row): self {
        $data = is_object($row) ? get_object_vars($row) : $row;

        $id        = isset($data['id']) ? (int) $data['id'] : 0;
        $userId    = isset($data['user_id']) ? (int) $data['user_id'] : 0;
        $module    = isset($data['module']) ? (string) $data['module'] : '';
        $rawDate   = isset($data['created_at']) ? (string) $data['created_at'] : '';

        // Convert SQL UTC DATETIME string to Unix seconds
        if (is_numeric($rawDate)) {
            $createdAt = (int) $rawDate;
        } else {
            $parsed = strtotime($rawDate . ' UTC');
            $createdAt = ($parsed !== false) ? $parsed : time();
        }

        return new self($id, $userId, $module, $createdAt);
    }

    /**
     * Hydrate QueueItem from associative array.
     *
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self {
        return self::fromRow($data);
    }
}
