<?php
/**
 * SuiteCRM Record Matcher.
 *
 * Implements serial matching: saved ID lookup first, then email fallback,
 * inspecting results to distinguish none, single, and multiple matches.
 *
 * @package SyncUsersSuitecrm\SuiteCRM
 */

namespace SyncUsersSuitecrm\SuiteCRM;

use WP_Error;
use WP_User;
use SyncUsersSuitecrm\Model\MatchResult;
use SyncUsersSuitecrm\Storage\UserMappingRepository;
use SyncUsersSuitecrm\Settings;

defined('ABSPATH') || exit;

class RecordMatcher {

    /**
     * SuiteCRM Client instance.
     *
     * @var Client
     */
    protected Client $client;

    /**
     * User mapping repository.
     *
     * @var UserMappingRepository
     */
    protected UserMappingRepository $mappingRepository;

    /**
     * Constructor.
     *
     * @param Client                     $client
     * @param UserMappingRepository|null $mappingRepository
     */
    public function __construct(Client $client, ?UserMappingRepository $mappingRepository = null) {
        $this->client            = $client;
        $this->mappingRepository = $mappingRepository ?? new UserMappingRepository();
    }

    /**
     * Execute serial matching for a WordPress user and SuiteCRM module.
     *
     * 1. If a saved CRM ID exists, check for that record via filtered collection lookup:
     *    filter[id][eq]={id}.
     *    - If exactly one record found, return MatchResult::single('saved_id', $id).
     *    - If multiple records found, return WP_Error('invalid_response', ...).
     *    - If no records found (clean 200 OK with data: []), proceed to email lookup.
     *    - If lookup returns an error, halt and return that WP_Error.
     * 2. Search by email via filtered collection lookup:
     *    filter[email1][eq]={email}.
     *    - If no records found, return MatchResult::none() (source is 'email').
     *    - If exactly one record found, return MatchResult::single('email', $id).
     *    - If multiple records found (>=2 or next_page present), return MatchResult::multiple('email').
     *    - If lookup returns an error, halt and return that WP_Error.
     *
     * @param WP_User $user   WordPress user instance.
     * @param string  $module SuiteCRM module ('Accounts', 'Contacts', 'Leads', 'Users').
     * @return MatchResult|WP_Error MatchResult object or WP_Error on operational failure.
     */
    public function match(WP_User $user, string $module) {
        if (!in_array($module, Settings::SUPPORTED_MODULES, true)) {
            return new WP_Error(
                'invalid_module',
                sprintf(
                    /* translators: %s: Module name */
                    __('Unsupported SuiteCRM module: %s.', 'sync-users-suitecrm'),
                    $module
                ),
                ['http_status' => 400]
            );
        }

        $userId = (int) $user->ID;

        // 1. Saved CRM ID Lookup
        $savedId = $this->mappingRepository->get($userId, $module);
        if (is_wp_error($savedId)) {
            return $savedId;
        }

        if ($savedId !== null && trim($savedId) !== '') {
            $savedId = trim($savedId);

            // Filtered collection lookup by ID: filter[id][eq]={id}
            $result = $this->client->getCollection(
                $module,
                ['id' => ['eq' => $savedId]]
            );

            if (is_wp_error($result)) {
                return $result;
            }

            $records = $result['records'] ?? [];
            $count   = count($records);

            if ($count === 1) {
                return MatchResult::single(MatchResult::SOURCE_SAVED_ID, (string) $records[0]['id']);
            }

            if ($count > 1) {
                return new WP_Error(
                    'invalid_response',
                    sprintf(
                        /* translators: 1: Saved CRM ID, 2: Module name */
                        __('Multiple records returned for saved ID %1$s in module %2$s.', 'sync-users-suitecrm'),
                        $savedId,
                        $module
                    ),
                    ['http_status' => 200]
                );
            }

            // count === 0: Saved ID does not exist in CRM, fall through to email lookup
        }

        // 2. Email Lookup
        $email = trim((string) $user->user_email);
        if (!is_email($email)) {
            return new WP_Error(
                'invalid_user_data',
                __('WordPress user has no valid email address', 'sync-users-suitecrm'),
                ['user_id' => $userId]
            );
        }

        // Request page size 2 to distinguish between 0, 1, and 2+ records
        $result = $this->client->getCollection(
            $module,
            ['email1' => ['eq' => $email]],
            ['size' => 2, 'number' => 1]
        );

        if (is_wp_error($result)) {
            return $result;
        }

        $records  = $result['records'] ?? [];
        $count    = count($records);
        $nextPage = $result['next_page'] ?? null;

        if ($count === 0) {
            return MatchResult::none();
        }

        if ($count >= 2 || $nextPage !== null) {
            return MatchResult::multiple(MatchResult::SOURCE_EMAIL);
        }

        return MatchResult::single(MatchResult::SOURCE_EMAIL, (string) $records[0]['id']);
    }

    /**
     * Access SuiteCRM Client instance.
     *
     * @return Client
     */
    public function getClient(): Client {
        return $this->client;
    }

    /**
     * Access UserMappingRepository instance.
     *
     * @return UserMappingRepository
     */
    public function getMappingRepository(): UserMappingRepository {
        return $this->mappingRepository;
    }
}
