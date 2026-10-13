<?php
/**
 * SuiteCRM REST V8 API Client.
 *
 * Encapsulates HTTP transport, serialization, deserialization, and response
 * validation against SuiteCRM 7 REST V8 JSON:API endpoints.
 *
 * @package SyncSuiteCRMUsers\SuiteCRM
 */

namespace SyncSuiteCRMUsers\SuiteCRM;

use WP_Error;
use SyncSuiteCRMUsers\Settings;
use SyncSuiteCRMUsers\Auth\TokenManager;

defined('ABSPATH') || exit;

class Client {

    /**
     * Default request timeout in seconds.
     */
    public const DEFAULT_TIMEOUT = 15;

    /**
     * JSON:API media type header.
     */
    public const JSON_API_CONTENT_TYPE = 'application/vnd.api+json';

    /**
     * Settings service.
     *
     * @var Settings
     */
    protected Settings $settings;

    /**
     * Token manager instance.
     *
     * @var TokenManager|null
     */
    protected ?TokenManager $tokenManager = null;

    /**
     * Constructor.
     *
     * @param Settings|null     $settings
     * @param TokenManager|null $tokenManager
     */
    public function __construct(?Settings $settings = null, ?TokenManager $tokenManager = null) {
        $this->settings     = $settings ?? new Settings();
        $this->tokenManager = $tokenManager;
    }

    /**
     * Access or lazily initialize TokenManager.
     *
     * @return TokenManager
     */
    public function getTokenManager(): TokenManager {
        if ($this->tokenManager === null) {
            $this->tokenManager = new TokenManager($this->settings, $this);
        }
        return $this->tokenManager;
    }

    /**
     * Set TokenManager.
     *
     * @param TokenManager $tokenManager
     * @return void
     */
    public function setTokenManager(TokenManager $tokenManager): void {
        $this->tokenManager = $tokenManager;
    }

    /**
     * Access Settings service.
     *
     * @return Settings
     */
    public function getSettings(): Settings {
        return $this->settings;
    }

    /**
     * Test reachability of SuiteCRM API endpoint (/Api/access_token).
     *
     * @param string|null $baseUrl Optional base URL to test (defaults to saved base_url).
     * @return array{reachable: bool, authentication_required: bool, http_status: int}|WP_Error
     */
    public function testReachability(?string $baseUrl = null) {
        if ($baseUrl === null || trim($baseUrl) === '') {
            $conn    = $this->settings->getConnection();
            $baseUrl = $conn['base_url'] ?? '';
        }

        $baseUrl = untrailingslashit(trim((string) $baseUrl));
        if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            return new WP_Error(
                'invalid_url',
                __('Please enter a valid SuiteCRM base URL.', 'sync-users-suitecrm'),
                ['http_status' => 400]
            );
        }

        $endpoint = $baseUrl . '/Api/access_token';
        $response = wp_remote_post($endpoint, [
            'timeout'   => self::DEFAULT_TIMEOUT,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'headers'   => [
                'Content-Type' => self::JSON_API_CONTENT_TYPE,
                'Accept'       => self::JSON_API_CONTENT_TYPE,
            ],
            'body'      => wp_json_encode(['grant_type' => 'password']),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error(
                'transport_error',
                sprintf(
                    /* translators: %s: Error message */
                    __('Connection failed: %s', 'sync-users-suitecrm'),
                    esc_html($response->get_error_message())
                ),
                ['http_status' => 502]
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        // SuiteCRM REST V8 returns 400/401 when reaching /Api/access_token without valid credentials
        if ($code === 200 || $code === 400 || $code === 401) {
            return [
                'reachable'               => true,
                'authentication_required' => true,
                'http_status'             => $code,
            ];
        }

        return new WP_Error(
            'unexpected_status',
            sprintf(
                /* translators: %d: HTTP status code */
                __('API reachable but returned unexpected HTTP status %d.', 'sync-users-suitecrm'),
                $code
            ),
            ['http_status' => $code]
        );
    }

    /**
     * Request an OAuth2 access token using the password grant type.
     *
     * Wire endpoint: POST /Api/access_token
     * Expected status: 200 OK
     * Response shape: {"access_token": "...", "expires_in": 3600, "refresh_token": "..."}
     *
     * @param array<string, mixed> $credentials Credentials override.
     * @return array{access_token: string, refresh_token: ?string, expires_in: int}|WP_Error
     */
    public function requestTokenWithPassword(array $credentials = []) {
        $conn = wp_parse_args($credentials, $this->settings->getConnection());

        $baseUrl      = untrailingslashit(trim((string) ($conn['base_url'] ?? '')));
        $clientId     = trim((string) ($conn['client_id'] ?? ''));
        $clientSecret = trim((string) ($conn['client_secret'] ?? ''));
        $username     = trim((string) ($conn['username'] ?? ''));
        $password     = trim((string) ($conn['password'] ?? ''));

        if ($baseUrl === '' || $clientId === '' || $clientSecret === '' || $username === '' || $password === '') {
            return new WP_Error(
                'missing_credentials',
                __('SuiteCRM base URL, client ID, client secret, username, and password are required.', 'sync-users-suitecrm'),
                ['http_status' => 400]
            );
        }

        $endpoint = $baseUrl . '/Api/access_token';
        $payload  = [
            'grant_type'    => 'password',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'username'      => $username,
            'password'      => $password,
        ];

        $response = wp_remote_post($endpoint, [
            'timeout'   => self::DEFAULT_TIMEOUT,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'headers'   => [
                'Content-Type' => self::JSON_API_CONTENT_TYPE,
                'Accept'       => self::JSON_API_CONTENT_TYPE,
            ],
            'body'      => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error(
                'transport_error',
                sprintf(
                    /* translators: %s: Error message */
                    __('Transport error during authentication: %s', 'sync-users-suitecrm'),
                    esc_html($response->get_error_message())
                ),
                ['http_status' => 502]
            );
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        if ($statusCode !== 200) {
            return $this->parseErrorResponse($response);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || empty($data['access_token']) || !isset($data['expires_in']) || (int) $data['expires_in'] <= 0) {
            return new WP_Error(
                'invalid_response',
                __('SuiteCRM returned an invalid OAuth token response structure.', 'sync-users-suitecrm'),
                ['http_status' => 200]
            );
        }

        return [
            'access_token'  => (string) $data['access_token'],
            'refresh_token' => !empty($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            'expires_in'    => (int) $data['expires_in'],
        ];
    }

    /**
     * Request an OAuth2 access token using the refresh_token grant type.
     *
     * Wire endpoint: POST /Api/access_token
     * Expected status: 200 OK
     *
     * @param string               $refreshToken OAuth2 refresh token string.
     * @param array<string, mixed> $credentials Credentials override.
     * @return array{access_token: string, refresh_token: ?string, expires_in: int}|WP_Error
     */
    public function requestTokenWithRefreshToken(string $refreshToken, array $credentials = []) {
        $conn = wp_parse_args($credentials, $this->settings->getConnection());

        $baseUrl      = untrailingslashit(trim((string) ($conn['base_url'] ?? '')));
        $clientId     = trim((string) ($conn['client_id'] ?? ''));
        $clientSecret = trim((string) ($conn['client_secret'] ?? ''));
        $refreshToken = trim($refreshToken);

        if ($baseUrl === '' || $clientId === '' || $clientSecret === '' || $refreshToken === '') {
            return new WP_Error(
                'missing_credentials',
                __('SuiteCRM base URL, client ID, client secret, and refresh token are required.', 'sync-users-suitecrm'),
                ['http_status' => 400]
            );
        }

        $endpoint = $baseUrl . '/Api/access_token';
        $payload  = [
            'grant_type'    => 'refresh_token',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ];

        $response = wp_remote_post($endpoint, [
            'timeout'   => self::DEFAULT_TIMEOUT,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'headers'   => [
                'Content-Type' => self::JSON_API_CONTENT_TYPE,
                'Accept'       => self::JSON_API_CONTENT_TYPE,
            ],
            'body'      => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error(
                'transport_error',
                sprintf(
                    /* translators: %s: Error message */
                    __('Transport error during token renewal: %s', 'sync-users-suitecrm'),
                    esc_html($response->get_error_message())
                ),
                ['http_status' => 502]
            );
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        if ($statusCode !== 200) {
            return $this->parseErrorResponse($response);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || empty($data['access_token']) || !isset($data['expires_in']) || (int) $data['expires_in'] <= 0) {
            return new WP_Error(
                'invalid_response',
                __('SuiteCRM returned an invalid OAuth refresh response structure.', 'sync-users-suitecrm'),
                ['http_status' => 200]
            );
        }

        return [
            'access_token'  => (string) $data['access_token'],
            'refresh_token' => !empty($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            'expires_in'    => (int) $data['expires_in'],
        ];
    }

    /**
     * Query a filtered collection of records.
     *
     * Wire endpoint: GET /Api/V8/module/{Module}?filter[...]=...
     * Expected status: 200 OK
     * Normalized result:
     *   array{records: list<array{id: string, module: string, attributes: array<string, mixed>}>, next_page: ?int}
     *
     * @param string                                     $module      Module name ('Accounts', 'Contacts', 'Leads', 'Users').
     * @param array<string, array<string, string>|string> $filters     Field => Operator => Value or Field => Value.
     * @param array{number?: int, size?: int}            $pagination  Positive page number and page size.
     * @param string                                     $sort        Sort field with optional '-' prefix.
     * @param string|null                                $accessToken Optional Bearer token override.
     * @return array{records: list<array{id: string, module: string, attributes: array<string, mixed>}>, next_page: ?int}|WP_Error
     */
    public function getCollection(
        string $module,
        array $filters = [],
        array $pagination = [],
        string $sort = '',
        ?string $accessToken = null
    ) {
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

        $token = $this->resolveAccessToken($accessToken);
        if (is_wp_error($token)) {
            return $token;
        }

        $conn    = $this->settings->getConnection();
        $baseUrl = untrailingslashit(trim((string) ($conn['base_url'] ?? '')));

        if ($baseUrl === '') {
            return new WP_Error(
                'not_configured',
                __('SuiteCRM base URL is not configured.', 'sync-users-suitecrm'),
                ['http_status' => 400]
            );
        }

        $endpoint = $baseUrl . '/Api/V8/module/' . rawurlencode($module);
        $queryArgs = [];

        // 1. Multi-field filters: filter[<field>][<operator>]=<value>
        foreach ($filters as $field => $ops) {
            $field = sanitize_key((string) $field);
            if (is_array($ops)) {
                foreach ($ops as $op => $val) {
                    $op = sanitize_key((string) $op);
                    $queryArgs["filter[{$field}][{$op}]"] = (string) $val;
                }
            } else {
                $queryArgs["filter[{$field}][eq]"] = (string) $ops;
            }
        }

        // 2. Pagination: page[number] and page[size] with positive values
        $pageNumber = isset($pagination['number']) && (int) $pagination['number'] > 0
            ? (int) $pagination['number']
            : 1;

        if (isset($pagination['number']) && (int) $pagination['number'] > 0) {
            $queryArgs['page[number]'] = (int) $pagination['number'];
        }

        if (isset($pagination['size']) && (int) $pagination['size'] > 0) {
            $queryArgs['page[size]'] = (int) $pagination['size'];
        }

        // 3. Sort
        if (trim($sort) !== '') {
            $queryArgs['sort'] = trim($sort);
        }

        if (!empty($queryArgs)) {
            $endpoint = add_query_arg($queryArgs, $endpoint);
        }

        $response = wp_remote_get($endpoint, [
            'timeout'   => self::DEFAULT_TIMEOUT,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'headers'   => [
                'Accept'        => self::JSON_API_CONTENT_TYPE,
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);

        if (is_wp_error($response)) {
            return new WP_Error(
                'transport_error',
                sprintf(
                    /* translators: %s: Error message */
                    __('Transport error querying SuiteCRM collection: %s', 'sync-users-suitecrm'),
                    esc_html($response->get_error_message())
                ),
                ['http_status' => 502]
            );
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        if ($statusCode !== 200) {
            return $this->parseErrorResponse($response);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
            return new WP_Error(
                'invalid_response',
                __('SuiteCRM returned an invalid collection response structure.', 'sync-users-suitecrm'),
                ['http_status' => 200]
            );
        }

        $records = [];
        foreach ($data['data'] as $item) {
            if (is_array($item) && isset($item['id'])) {
                $records[] = [
                    'id'         => (string) $item['id'],
                    'module'     => $module,
                    'attributes' => isset($item['attributes']) && is_array($item['attributes']) ? $item['attributes'] : [],
                ];
            }
        }

        // Determine next_page pagination metadata
        $nextPage = null;
        if (isset($data['links']['next']) && is_string($data['links']['next']) && $data['links']['next'] !== '') {
            if (preg_match('/page(?:%5B|\[)number(?:%5D|\])=(\d+)/i', $data['links']['next'], $matches)) {
                $nextPage = (int) $matches[1];
            } else {
                $nextPage = $pageNumber + 1;
            }
        } elseif (isset($data['meta']['total-pages']) && is_numeric($data['meta']['total-pages'])) {
            $totalPages = (int) $data['meta']['total-pages'];
            if ($pageNumber < $totalPages) {
                $nextPage = $pageNumber + 1;
            }
        }

        return [
            'records'   => $records,
            'next_page' => $nextPage,
        ];
    }

    /**
     * Create a new record in SuiteCRM.
     *
     * Wire endpoint: POST /Api/V8/module
     * Wire payload: {"data": {"type": "<Module>", "attributes": {<attributes>}}}
     * Expected status: 201 Created
     * Expected response: {"data": {"type": "<Module>", "id": "<crm-uuid>", "attributes": {...}}}
     *
     * @param string                $module      Module name.
     * @param array<string, string> $attributes CRM field attributes.
     * @param string|null           $accessToken Optional Bearer token override.
     * @return array{id: string, module: string}|WP_Error
     */
    public function createRecord(string $module, array $attributes, ?string $accessToken = null) {
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

        $token = $this->resolveAccessToken($accessToken);
        if (is_wp_error($token)) {
            return $token;
        }

        $conn    = $this->settings->getConnection();
        $baseUrl = untrailingslashit(trim((string) ($conn['base_url'] ?? '')));

        if ($baseUrl === '') {
            return new WP_Error(
                'not_configured',
                __('SuiteCRM base URL is not configured.', 'sync-users-suitecrm'),
                ['http_status' => 400]
            );
        }

        $endpoint = $baseUrl . '/Api/V8/module';
        $payload  = [
            'data' => [
                'type'       => $module,
                'attributes' => $attributes,
            ],
        ];

        $response = wp_remote_post($endpoint, [
            'timeout'   => self::DEFAULT_TIMEOUT,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'headers'   => [
                'Content-Type'  => self::JSON_API_CONTENT_TYPE,
                'Accept'        => self::JSON_API_CONTENT_TYPE,
                'Authorization' => 'Bearer ' . $token,
            ],
            'body'      => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error(
                'transport_error',
                sprintf(
                    /* translators: %s: Error message */
                    __('Transport error creating record: %s', 'sync-users-suitecrm'),
                    esc_html($response->get_error_message())
                ),
                ['http_status' => 502]
            );
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        if ($statusCode !== 201) {
            return $this->parseErrorResponse($response);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || empty($data['data']['id']) || empty($data['data']['type'])) {
            return new WP_Error(
                'invalid_response',
                __('SuiteCRM returned an invalid create response structure.', 'sync-users-suitecrm'),
                ['http_status' => 201]
            );
        }

        $returnedType = (string) $data['data']['type'];
        // Accept exact module or singular bean name (e.g. Contacts -> Contact, Accounts -> Account)
        if (
            strcasecmp($returnedType, $module) !== 0 &&
            strcasecmp($returnedType, rtrim($module, 's')) !== 0
        ) {
            return new WP_Error(
                'invalid_response',
                sprintf(
                    /* translators: 1: Returned type, 2: Expected module */
                    __('SuiteCRM returned unexpected module type %1$s for requested module %2$s.', 'sync-users-suitecrm'),
                    $returnedType,
                    $module
                ),
                ['http_status' => 201]
            );
        }

        return [
            'id'     => (string) $data['data']['id'],
            'module' => $module,
        ];
    }

    /**
     * Update an existing record in SuiteCRM.
     *
     * Wire endpoint: PATCH /Api/V8/module
     * Wire payload: {"data": {"type": "<Module>", "id": "<crm-uuid>", "attributes": {<attributes>}}}
     * Expected status: 201 Created (or 200 OK)
     * Expected response: {"data": {"type": "<Module>", "id": "<crm-uuid>", "attributes": {...}}}
     *
     * @param string                $module      Module name.
     * @param string                $id          CRM record UUID.
     * @param array<string, string> $attributes Attributes to update.
     * @param string|null           $accessToken Optional Bearer token override.
     * @return array{id: string, module: string}|WP_Error
     */
    public function updateRecord(string $module, string $id, array $attributes, ?string $accessToken = null) {
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

        $id = trim($id);
        if ($id === '') {
            return new WP_Error(
                'invalid_input',
                __('CRM record ID cannot be empty for record update.', 'sync-users-suitecrm'),
                ['http_status' => 400]
            );
        }

        $token = $this->resolveAccessToken($accessToken);
        if (is_wp_error($token)) {
            return $token;
        }

        $conn    = $this->settings->getConnection();
        $baseUrl = untrailingslashit(trim((string) ($conn['base_url'] ?? '')));

        if ($baseUrl === '') {
            return new WP_Error(
                'not_configured',
                __('SuiteCRM base URL is not configured.', 'sync-users-suitecrm'),
                ['http_status' => 400]
            );
        }

        $endpoint = $baseUrl . '/Api/V8/module';
        $payload  = [
            'data' => [
                'type'       => $module,
                'id'         => $id,
                'attributes' => $attributes,
            ],
        ];

        $response = wp_remote_request($endpoint, [
            'method'    => 'PATCH',
            'timeout'   => self::DEFAULT_TIMEOUT,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'headers'   => [
                'Content-Type'  => self::JSON_API_CONTENT_TYPE,
                'Accept'        => self::JSON_API_CONTENT_TYPE,
                'Authorization' => 'Bearer ' . $token,
            ],
            'body'      => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error(
                'transport_error',
                sprintf(
                    /* translators: %s: Error message */
                    __('Transport error updating record: %s', 'sync-users-suitecrm'),
                    esc_html($response->get_error_message())
                ),
                ['http_status' => 502]
            );
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        // Empirical verification: SuiteCRM returns 201 Created on PATCH, accept 200 or 201
        if ($statusCode !== 201 && $statusCode !== 200) {
            return $this->parseErrorResponse($response);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || empty($data['data']['id']) || empty($data['data']['type'])) {
            return new WP_Error(
                'invalid_response',
                __('SuiteCRM returned an invalid update response structure.', 'sync-users-suitecrm'),
                ['http_status' => $statusCode]
            );
        }

        $returnedType = (string) $data['data']['type'];
        if (
            strcasecmp($returnedType, $module) !== 0 &&
            strcasecmp($returnedType, rtrim($module, 's')) !== 0
        ) {
            return new WP_Error(
                'invalid_response',
                sprintf(
                    /* translators: 1: Returned type, 2: Expected module */
                    __('SuiteCRM returned unexpected module type %1$s for requested module %2$s.', 'sync-users-suitecrm'),
                    $returnedType,
                    $module
                ),
                ['http_status' => $statusCode]
            );
        }

        $returnedId = (string) $data['data']['id'];
        if ($returnedId !== $id) {
            return new WP_Error(
                'invalid_response',
                sprintf(
                    /* translators: 1: Returned ID, 2: Expected ID */
                    __('SuiteCRM returned record ID %1$s which does not match requested ID %2$s.', 'sync-users-suitecrm'),
                    $returnedId,
                    $id
                ),
                ['http_status' => $statusCode]
            );
        }

        return [
            'id'     => $returnedId,
            'module' => $module,
        ];
    }

    /**
     * Resolve a valid Bearer access token.
     *
     * @param string|null $accessToken Optional override token.
     * @return string|WP_Error
     */
    protected function resolveAccessToken(?string $accessToken = null) {
        if ($accessToken !== null && trim($accessToken) !== '') {
            return trim($accessToken);
        }

        return $this->getTokenManager()->getValidToken();
    }

    /**
     * Parse SuiteCRM error response into a sanitized WP_Error.
     *
     * Error parsing contract:
     * - Status/OAuth code extracted as WP_Error code.
     * - Message priority: detail -> message -> title -> HTTP status fallback.
     * - Safe data: array{http_status: int}.
     * - Invariant: Credentials, request bodies, stack traces, and local paths are stripped.
     *
     * @param array<string, mixed> $response Transport response array.
     * @return WP_Error
     */
    public function parseErrorResponse($response): WP_Error {
        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $body       = wp_remote_retrieve_body($response);
        $data       = json_decode($body, true);

        $errorCode    = 'api_error';
        $errorMessage = '';

        if (is_array($data)) {
            // Check OAuth error shape: {"error": "...", "message": "..."}
            if (!empty($data['error'])) {
                $errorCode = sanitize_key((string) $data['error']);
            }

            if (!empty($data['detail'])) {
                $errorMessage = (string) $data['detail'];
            } elseif (!empty($data['message'])) {
                $errorMessage = (string) $data['message'];
            } elseif (!empty($data['error_description'])) {
                $errorMessage = (string) $data['error_description'];
            }

            // Check JSON:API error shape: {"errors": [{"status": "400", "title": "...", "detail": "..."}]}
            if (empty($errorMessage) && isset($data['errors'])) {
                $err = is_array($data['errors']) && isset($data['errors'][0])
                    ? $data['errors'][0]
                    : $data['errors'];

                if (is_array($err)) {
                    if (!empty($err['detail'])) {
                        $errorMessage = (string) $err['detail'];
                    } elseif (!empty($err['message'])) {
                        $errorMessage = (string) $err['message'];
                    } elseif (!empty($err['title'])) {
                        $errorMessage = (string) $err['title'];
                    }
                }
            }
        }

        // Status code based fallback codes
        if ($errorCode === 'api_error') {
            if ($statusCode === 401) {
                $errorCode = 'authentication_unavailable';
            } elseif ($statusCode === 404) {
                $errorCode = 'record_not_found';
            } elseif ($statusCode === 400) {
                $errorCode = 'invalid_request';
            } elseif ($statusCode >= 500) {
                $errorCode = 'server_error';
            }
        }

        if ($errorMessage === '') {
            $errorMessage = sprintf(
                /* translators: %d: HTTP status code */
                __('SuiteCRM API request failed with HTTP status %d.', 'sync-users-suitecrm'),
                $statusCode
            );
        }

        $sanitizedMessage = sanitize_text_field(wp_strip_all_tags($errorMessage));

        return new WP_Error(
            $errorCode,
            $sanitizedMessage,
            ['http_status' => $statusCode]
        );
    }
}
