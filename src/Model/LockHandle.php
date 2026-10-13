<?php
/**
 * Lock handle data object.
 *
 * Represents an acquired resource lock, holding the resource key,
 * owner token, and expiry timestamp.
 *
 * @package SyncSuiteCRMUsers
 */

namespace SyncSuiteCRMUsers\Model;

defined('ABSPATH') || exit;

class LockHandle {

    /**
     * Unique resource identifier (e.g., 'user_42', 'bulk_progress', 'token_refresh').
     *
     * @var string
     */
    private string $resourceKey;

    /**
     * Unique owner token (UUID or random string).
     *
     * @var string
     */
    private string $ownerToken;

    /**
     * Expiration Unix timestamp.
     *
     * @var int
     */
    private int $expiresAt;

    /**
     * Constructor.
     *
     * @param string $resourceKey
     * @param string $ownerToken
     * @param int    $expiresAt
     */
    public function __construct(string $resourceKey, string $ownerToken, int $expiresAt) {
        $this->resourceKey = $resourceKey;
        $this->ownerToken  = $ownerToken;
        $this->expiresAt   = $expiresAt;
    }

    /**
     * Get resource key.
     *
     * @return string
     */
    public function getResourceKey(): string {
        return $this->resourceKey;
    }

    /**
     * Get owner token.
     *
     * @return string
     */
    public function getOwnerToken(): string {
        return $this->ownerToken;
    }

    /**
     * Get expiry timestamp.
     *
     * @return int
     */
    public function getExpiresAt(): int {
        return $this->expiresAt;
    }

    /**
     * Whether the lock handle has reached its expiration time.
     *
     * @return bool
     */
    public function isExpired(): bool {
        return time() >= $this->expiresAt;
    }

    /**
     * Convert to plain array representation.
     *
     * @return array{resource_key: string, owner_token: string, expires_at: int}
     */
    public function toArray(): array {
        return [
            'resource_key' => $this->resourceKey,
            'owner_token'  => $this->ownerToken,
            'expires_at'   => $this->expiresAt,
        ];
    }

    /**
     * Hydrate LockHandle from associative array.
     *
     * @param array<string, mixed> $data
     * @return self|null Returns null if array data is malformed.
     */
    public static function fromArray(array $data): ?self {
        if (
            empty($data['resource_key']) ||
            !is_string($data['resource_key']) ||
            empty($data['owner_token']) ||
            !is_string($data['owner_token']) ||
            !isset($data['expires_at']) ||
            !is_numeric($data['expires_at'])
        ) {
            return null;
        }

        return new self(
            (string) $data['resource_key'],
            (string) $data['owner_token'],
            (int) $data['expires_at']
        );
    }
}
