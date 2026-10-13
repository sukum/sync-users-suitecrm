<?php
/**
 * Record matcher result data object.
 *
 * Immutable data object representing a successful CRM record lookup result,
 * separate from operational errors.
 *
 * @package SyncSuiteCRMUsers
 */

namespace SyncSuiteCRMUsers\Model;

use InvalidArgumentException;

defined('ABSPATH') || exit;

class MatchResult {

    public const KIND_NONE     = 'none';
    public const KIND_SINGLE   = 'single';
    public const KIND_MULTIPLE = 'multiple';

    public const SOURCE_SAVED_ID = 'saved_id';
    public const SOURCE_EMAIL    = 'email';

    public const ALLOWED_KINDS = [
        self::KIND_NONE,
        self::KIND_SINGLE,
        self::KIND_MULTIPLE,
    ];

    public const ALLOWED_SOURCES = [
        self::SOURCE_SAVED_ID,
        self::SOURCE_EMAIL,
    ];

    /**
     * Match kind: 'none', 'single', or 'multiple'.
     *
     * @var string
     */
    private string $kind;

    /**
     * Match source: 'saved_id' or 'email'.
     * Note: 'none' means the final successful email lookup found no records,
     * so its source is always 'email'.
     *
     * @var string
     */
    private string $source;

    /**
     * CRM record ID, populated only for 'single'. Null for 'none' and 'multiple'.
     *
     * @var string|null
     */
    private ?string $crmRecordId;

    /**
     * Constructor.
     *
     * - 'none' means the final successful email lookup found no records, so its source is 'email'.
     * - 'crm_record_id' is populated strictly for 'single', and must be a non-empty string.
     * - An arbitrary ID is never chosen for 'multiple'.
     *
     * @param string      $kind        'none', 'single', or 'multiple'.
     * @param string      $source      'saved_id' or 'email'.
     * @param string|null $crmRecordId Populated only for 'single'.
     * @throws InvalidArgumentException When invariants are violated.
     */
    public function __construct(string $kind, string $source, ?string $crmRecordId = null) {
        if (!in_array($kind, self::ALLOWED_KINDS, true)) {
            throw new InvalidArgumentException(
                esc_html(sprintf('Invalid MatchResult kind: %s', $kind))
            );
        }

        if (!in_array($source, self::ALLOWED_SOURCES, true)) {
            throw new InvalidArgumentException(
                esc_html(sprintf('Invalid MatchResult source: %s', $source))
            );
        }

        // Invariant: 'none' means final email lookup found no records, so source is email
        if ($kind === self::KIND_NONE) {
            $source      = self::SOURCE_EMAIL;
            $crmRecordId = null;
        } elseif ($kind === self::KIND_MULTIPLE) {
            // Invariant: Never choose an arbitrary ID for multiple matches
            $crmRecordId = null;
        } elseif ($kind === self::KIND_SINGLE) {
            // Invariant: Single match requires a valid non-empty CRM ID
            if ($crmRecordId === null || trim($crmRecordId) === '') {
                throw new InvalidArgumentException(
                    'A single MatchResult requires a non-empty crmRecordId.'
                );
            }
            $crmRecordId = trim($crmRecordId);
        }

        $this->kind        = $kind;
        $this->source      = $source;
        $this->crmRecordId = $crmRecordId;
    }

    /**
     * Factory: Create a no-match result.
     *
     * The source is always 'email' because the final successful lookup stage is email.
     *
     * @return self
     */
    public static function none(): self {
        return new self(self::KIND_NONE, self::SOURCE_EMAIL, null);
    }

    /**
     * Factory: Create a single match result.
     *
     * @param string $source      'saved_id' or 'email'.
     * @param string $crmRecordId Matched CRM record UUID.
     * @return self
     */
    public static function single(string $source, string $crmRecordId): self {
        return new self(self::KIND_SINGLE, $source, $crmRecordId);
    }

    /**
     * Factory: Create a multiple matches result.
     *
     * @param string $source Source where multiple records were found (default 'email').
     * @return self
     */
    public static function multiple(string $source = self::SOURCE_EMAIL): self {
        return new self(self::KIND_MULTIPLE, $source, null);
    }

    /**
     * Get match kind ('none', 'single', or 'multiple').
     *
     * @return string
     */
    public function getKind(): string {
        return $this->kind;
    }

    /**
     * Get match source ('saved_id' or 'email').
     *
     * @return string
     */
    public function getSource(): string {
        return $this->source;
    }

    /**
     * Get matched CRM record ID. Null if no match or multiple matches.
     *
     * @return string|null
     */
    public function getCrmRecordId(): ?string {
        return $this->crmRecordId;
    }

    /**
     * Whether no records were matched.
     *
     * @return bool
     */
    public function isNone(): bool {
        return $this->kind === self::KIND_NONE;
    }

    /**
     * Whether exactly one record was uniquely matched.
     *
     * @return bool
     */
    public function isSingle(): bool {
        return $this->kind === self::KIND_SINGLE;
    }

    /**
     * Whether multiple ambiguous records were matched.
     *
     * @return bool
     */
    public function isMultiple(): bool {
        return $this->kind === self::KIND_MULTIPLE;
    }

    /**
     * Whether the match was established via saved ID lookup.
     *
     * @return bool
     */
    public function isSavedId(): bool {
        return $this->source === self::SOURCE_SAVED_ID;
    }

    /**
     * Whether the match was evaluated via email lookup.
     *
     * @return bool
     */
    public function isEmail(): bool {
        return $this->source === self::SOURCE_EMAIL;
    }

    /**
     * Convert to plain associative array.
     *
     * @return array{kind: string, source: string, crm_record_id: string|null}
     */
    public function toArray(): array {
        return [
            'kind'          => $this->kind,
            'source'        => $this->source,
            'crm_record_id' => $this->crmRecordId,
        ];
    }

    /**
     * Hydrate MatchResult from associative array.
     *
     * @param array<string, mixed> $data
     * @return self|null Returns null if array data is malformed.
     */
    public static function fromArray(array $data): ?self {
        if (
            empty($data['kind']) ||
            !is_string($data['kind']) ||
            empty($data['source']) ||
            !is_string($data['source'])
        ) {
            return null;
        }

        $crmRecordId = isset($data['crm_record_id']) && is_string($data['crm_record_id']) && trim($data['crm_record_id']) !== ''
            ? trim($data['crm_record_id'])
            : null;

        try {
            return new self((string) $data['kind'], (string) $data['source'], $crmRecordId);
        } catch (InvalidArgumentException $e) {
            return null;
        }
    }
}
