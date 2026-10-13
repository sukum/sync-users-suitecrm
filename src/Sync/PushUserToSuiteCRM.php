<?php
/**
 * Outbound user synchronization service.
 *
 * Implements explicit outbound matching, creation, and updating logic for
 * synchronizing a WordPress user to a SuiteCRM module (Accounts, Contacts,
 * Leads, or Users) through the REST V8 API.
 *
 * @package SyncSuiteCRMUsers\Sync
 */

namespace SyncSuiteCRMUsers\Sync;

use SyncSuiteCRMUsers\Model\MatchResult;
use SyncSuiteCRMUsers\Model\SyncOutcome;
use SyncSuiteCRMUsers\Settings;
use SyncSuiteCRMUsers\Storage\UserMappingRepository;
use SyncSuiteCRMUsers\SuiteCRM\Client;
use SyncSuiteCRMUsers\SuiteCRM\OutboundFieldMapper;
use SyncSuiteCRMUsers\SuiteCRM\RecordMatcher;
use WP_Error;
use WP_User;

defined('ABSPATH') || exit;

class PushUserToSuiteCRM {

    /**
     * Settings service.
     *
     * @var Settings
     */
    protected Settings $settings;

    /**
     * SuiteCRM API client.
     *
     * @var Client
     */
    protected Client $client;

    /**
     * Serial record matcher.
     *
     * @var RecordMatcher
     */
    protected RecordMatcher $matcher;

    /**
     * Outbound field mapper.
     *
     * @var OutboundFieldMapper
     */
    protected OutboundFieldMapper $fieldMapper;

    /**
     * User mapping repository.
     *
     * @var UserMappingRepository
     */
    protected UserMappingRepository $mappingRepository;

    /**
     * Constructor.
     *
     * @param Settings|null              $settings
     * @param Client|null                $client
     * @param RecordMatcher|null          $matcher
     * @param OutboundFieldMapper|null   $fieldMapper
     * @param UserMappingRepository|null $mappingRepository
     */
    public function __construct(
        ?Settings $settings = null,
        ?Client $client = null,
        ?RecordMatcher $matcher = null,
        ?OutboundFieldMapper $fieldMapper = null,
        ?UserMappingRepository $mappingRepository = null
    ) {
        $this->settings          = $settings ?? new Settings();
        $this->client            = $client ?? new Client($this->settings);
        $this->mappingRepository = $mappingRepository ?? new UserMappingRepository();
        $this->matcher           = $matcher ?? new RecordMatcher($this->client, $this->mappingRepository);
        $this->fieldMapper       = $fieldMapper ?? new OutboundFieldMapper();
    }

    /**
     * Push a WordPress user to a specified SuiteCRM module.
     *
     * Executes the serial matching sequence:
     * 1. Saved CRM ID lookup first.
     * 2. Email lookup fallback if no saved ID exists or saved record no longer exists.
     * 3. Record creation if neither lookup matches.
     *
     * Single-attempt execution: every failure is terminal with no automatic retries.
     *
     * @param WP_User $user          WordPress user instance.
     * @param string  $module        SuiteCRM module name ('Accounts', 'Contacts', 'Leads', 'Users').
     * @param string  $mode          Sync mode ('create_only' or 'create_update').
     * @param int     $connectionRev Captured connection revision.
     * @return SyncOutcome Immutable outcome object.
     */
    public function push(WP_User $user, string $module, string $mode, int $connectionRev): SyncOutcome {
        $userId   = (int) $user->ID;
        $username = $user->user_login ? (string) $user->user_login : null;

        // 1. Invariant: Connection revision must match captured context
        if ($this->settings->getConnectionRevision() !== $connectionRev) {
            return SyncOutcome::failure(
                $userId,
                $username,
                $module,
                SyncOutcome::REASON_CONNECTION_CHANGED,
                __('Connection configuration changed during processing.', 'sync-users-suitecrm')
            );
        }

        // 2. Invariant: SuiteCRM connection must be configured and connected
        $connection = $this->settings->getConnection();
        if (($connection['connection_status'] ?? '') !== 'connected') {
            return SyncOutcome::failure(
                $userId,
                $username,
                $module,
                SyncOutcome::REASON_AUTHENTICATION_UNAVAILABLE,
                __('SuiteCRM connection is not configured or requires reconnect.', 'sync-users-suitecrm')
            );
        }

        // 3. Serial Record Matching (saved ID, then email)
        $match = $this->matcher->match($user, $module);
        if (is_wp_error($match)) {
            $reason = $this->mapErrorToReason($match);
            return SyncOutcome::failure(
                $userId,
                $username,
                $module,
                $reason,
                $match->get_error_message()
            );
        }

        // 4. Branch based on match result
        switch ($match->getKind()) {
            case MatchResult::KIND_SINGLE:
                return $this->handleSingleMatch($user, $module, $mode, $match->getCrmRecordId(), $match->getSource(), $connectionRev);

            case MatchResult::KIND_MULTIPLE:
                return $this->handleMultipleMatch($userId, $username, $module, $mode);

            case MatchResult::KIND_NONE:
            default:
                return $this->handleNoMatch($user, $module, $connectionRev);
        }
    }

    /**
     * Handle single matched record in CRM.
     *
     * @param WP_User $user
     * @param string  $module
     * @param string  $mode
     * @param string  $crmRecordId
     * @param string  $source ('saved_id' or 'email')
     * @param int     $connectionRev
     * @return SyncOutcome
     */
    protected function handleSingleMatch(
        WP_User $user,
        string $module,
        string $mode,
        string $crmRecordId,
        string $source,
        int $connectionRev
    ): SyncOutcome {
        $userId   = (int) $user->ID;
        $username = $user->user_login ? (string) $user->user_login : null;

        // In Create Only mode: skip when matching record exists
        if ($mode === 'create_only') {
            return SyncOutcome::skipped(
                $userId,
                $username,
                $module,
                SyncOutcome::REASON_EXISTING_RECORD,
                __('Matching record found in SuiteCRM (Create only mode).', 'sync-users-suitecrm'),
                $crmRecordId
            );
        }

        // In Create or Update mode: update the matched record
        $mappedAttributes = $this->fieldMapper->map($user, $module, OutboundFieldMapper::ACTION_UPDATE);
        if (is_wp_error($mappedAttributes)) {
            return SyncOutcome::failure(
                $userId,
                $username,
                $module,
                SyncOutcome::REASON_INVALID_USER_DATA,
                $mappedAttributes->get_error_message(),
                $crmRecordId,
                SyncOutcome::ACTION_UPDATE
            );
        }

        $updateResult = $this->client->updateRecord($module, $crmRecordId, $mappedAttributes);
        if (is_wp_error($updateResult)) {
            $reason = $this->mapErrorToReason($updateResult);
            return SyncOutcome::failure(
                $userId,
                $username,
                $module,
                $reason,
                $updateResult->get_error_message(),
                $crmRecordId,
                SyncOutcome::ACTION_UPDATE
            );
        }

        // Save mapping only if connection revision has not changed during API request
        if ($this->settings->getConnectionRevision() === $connectionRev) {
            $this->mappingRepository->set($userId, $module, $crmRecordId);
        }

        return SyncOutcome::success(
            $userId,
            $username,
            $module,
            $crmRecordId,
            SyncOutcome::ACTION_UPDATE,
            SyncOutcome::REASON_UPDATED,
            __('Record updated in SuiteCRM.', 'sync-users-suitecrm')
        );
    }

    /**
     * Handle multiple matching records found by email in CRM.
     *
     * @param int         $userId
     * @param string|null $username
     * @param string      $module
     * @param string      $mode
     * @return SyncOutcome
     */
    protected function handleMultipleMatch(int $userId, ?string $username, string $module, string $mode): SyncOutcome {
        // Create only: Any email match means skip
        if ($mode === 'create_only') {
            return SyncOutcome::skipped(
                $userId,
                $username,
                $module,
                SyncOutcome::REASON_EXISTING_RECORD,
                __('Multiple matching records found by email in SuiteCRM (Create only mode).', 'sync-users-suitecrm')
            );
        }

        // Create or update: Fails because target is ambiguous
        return SyncOutcome::failure(
            $userId,
            $username,
            $module,
            SyncOutcome::REASON_AMBIGUOUS_MATCH,
            __('Multiple records found in SuiteCRM with matching email address.', 'sync-users-suitecrm'),
            null,
            SyncOutcome::ACTION_UPDATE
        );
    }

    /**
     * Handle no matching record found in CRM (Create in both modes).
     *
     * @param WP_User $user
     * @param string  $module
     * @param int     $connectionRev
     * @return SyncOutcome
     */
    protected function handleNoMatch(WP_User $user, string $module, int $connectionRev): SyncOutcome {
        $userId   = (int) $user->ID;
        $username = $user->user_login ? (string) $user->user_login : null;

        $mappedAttributes = $this->fieldMapper->map($user, $module, OutboundFieldMapper::ACTION_CREATE);
        if (is_wp_error($mappedAttributes)) {
            return SyncOutcome::failure(
                $userId,
                $username,
                $module,
                SyncOutcome::REASON_INVALID_USER_DATA,
                $mappedAttributes->get_error_message(),
                null,
                SyncOutcome::ACTION_CREATE
            );
        }

        $createResult = $this->client->createRecord($module, $mappedAttributes);
        if (is_wp_error($createResult)) {
            $reason = $this->mapErrorToReason($createResult);
            return SyncOutcome::failure(
                $userId,
                $username,
                $module,
                $reason,
                $createResult->get_error_message(),
                null,
                SyncOutcome::ACTION_CREATE
            );
        }

        $newCrmId = (string) ($createResult['id'] ?? '');
        if ($newCrmId === '') {
            return SyncOutcome::failure(
                $userId,
                $username,
                $module,
                SyncOutcome::REASON_INVALID_RESPONSE,
                __('SuiteCRM created record response missing record ID.', 'sync-users-suitecrm'),
                null,
                SyncOutcome::ACTION_CREATE
            );
        }

        // Save mapping if connection revision has not changed
        if ($this->settings->getConnectionRevision() === $connectionRev) {
            $this->mappingRepository->set($userId, $module, $newCrmId);
        }

        return SyncOutcome::success(
            $userId,
            $username,
            $module,
            $newCrmId,
            SyncOutcome::ACTION_CREATE,
            SyncOutcome::REASON_CREATED,
            __('Record created in SuiteCRM.', 'sync-users-suitecrm')
        );
    }

    /**
     * Map low-level operational WP_Error codes to canonical SyncOutcome reasons.
     *
     * @param WP_Error $error
     * @return string
     */
    protected function mapErrorToReason(WP_Error $error): string {
        $code = $error->get_error_code();
        $data = $error->get_error_data();
        $httpStatus = (is_array($data) && isset($data['http_status'])) ? (int) $data['http_status'] : 0;

        if ($code === 'authentication_unavailable' || $code === 'token_renewal_failed' || $code === 'invalid_credentials' || $httpStatus === 401) {
            return SyncOutcome::REASON_AUTHENTICATION_UNAVAILABLE;
        }

        if ($code === 'transport_error' || $code === 'http_request_failed') {
            return SyncOutcome::REASON_TRANSPORT_ERROR;
        }

        if ($code === 'invalid_response' || $code === 'unexpected_response') {
            return SyncOutcome::REASON_INVALID_RESPONSE;
        }

        if ($code === 'invalid_user_data') {
            return SyncOutcome::REASON_INVALID_USER_DATA;
        }

        if ($code === 'connection_changed') {
            return SyncOutcome::REASON_CONNECTION_CHANGED;
        }

        if ($code === 'storage_error') {
            return SyncOutcome::REASON_STORAGE_ERROR;
        }

        return SyncOutcome::REASON_API_ERROR;
    }
}
