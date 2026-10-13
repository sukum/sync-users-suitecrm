<?php
/**
 * OAuth2 Token Manager.
 *
 * Governs token freshness, coordinated renewal with distributed locking,
 * and proactive renewal scheduling for SuiteCRM 7 REST V8 authentication.
 *
 * @package SyncSuiteCRMUsers\Auth
 */

namespace SyncSuiteCRMUsers\Auth;

use WP_Error;
use SyncSuiteCRMUsers\Settings;
use SyncSuiteCRMUsers\SuiteCRM\Client;
use SyncSuiteCRMUsers\Sync\LockManager;

defined('ABSPATH') || exit;

class TokenManager {

    /**
     * Lock resource key for token refresh concurrency.
     */
    public const LOCK_RESOURCE_KEY = 'token_refresh';

    /**
     * Expiration buffer in seconds (renew if token expires within 5 minutes).
     */
    public const EXPIRATION_BUFFER = 300;

    /**
     * Cron hook name for proactive renewal.
     */
    public const CRON_HOOK = 'syus_token_renewal_cron';

    /**
     * Settings service.
     *
     * @var Settings
     */
    protected Settings $settings;

    /**
     * SuiteCRM Client instance.
     *
     * @var Client
     */
    protected Client $client;

    /**
     * Lock manager instance.
     *
     * @var LockManager
     */
    protected LockManager $lockManager;

    /**
     * Constructor.
     *
     * @param Settings|null    $settings
     * @param Client|null      $client
     * @param LockManager|null $lockManager
     */
    public function __construct(
        ?Settings $settings = null,
        ?Client $client = null,
        ?LockManager $lockManager = null
    ) {
        $this->settings    = $settings ?? new Settings();
        $this->client      = $client ?? new Client($this->settings, $this);
        $this->lockManager = $lockManager ?? new LockManager();
    }

    /**
     * Retrieve a valid Bearer access token, renewing proactively if expiring.
     *
     * - Checks current connection status and token_expires_at.
     * - If token expires within 300 seconds, initiates token renewal.
     * - Acquires _syus_lock_token_refresh mutex lock before requesting renewal.
     * - On failure, saves connection_status = 'reconnect_required' and returns WP_Error('authentication_unavailable', ...).
     *
     * @return string|WP_Error Valid access token string or WP_Error.
     */
    public function getValidToken() {
        $conn = $this->settings->getConnection();

        // 1. Check if configured
        if (
            empty($conn['base_url']) ||
            empty($conn['client_id']) ||
            empty($conn['client_secret']) ||
            empty($conn['username']) ||
            empty($conn['password'])
        ) {
            $msg = __('SuiteCRM connection credentials are not configured.', 'sync-users-suitecrm');
            $this->settings->setConnectionStatus('not_configured', $msg);
            return new WP_Error('authentication_unavailable', $msg, ['http_status' => 401]);
        }

        $accessToken    = (string) ($conn['access_token'] ?? '');
        $tokenExpiresAt = (int) ($conn['token_expires_at'] ?? 0);
        $currentTime    = time();

        // 2. If token is present and not expiring within the buffer, return it immediately
        if ($accessToken !== '' && ($tokenExpiresAt - $currentTime) > self::EXPIRATION_BUFFER) {
            return $accessToken;
        }

        // 3. Acquire mutex lock for renewal
        $lockHandle = $this->lockManager->acquire(self::LOCK_RESOURCE_KEY, 60);

        if (is_wp_error($lockHandle)) {
            return new WP_Error(
                'authentication_unavailable',
                sprintf(
                    /* translators: %s: Error message */
                    __('Lock acquisition error during token refresh: %s', 'sync-users-suitecrm'),
                    $lockHandle->get_error_message()
                ),
                ['http_status' => 500]
            );
        }

        // Lock conflict: another worker is renewing the token
        if ($lockHandle === null) {
            // Re-read connection options to see if the other worker already completed refresh
            $freshConn       = $this->settings->getConnection();
            $freshToken      = (string) ($freshConn['access_token'] ?? '');
            $freshExpiresAt  = (int) ($freshConn['token_expires_at'] ?? 0);

            if ($freshToken !== '' && ($freshExpiresAt - $currentTime) > self::EXPIRATION_BUFFER) {
                return $freshToken;
            }

            // If the existing token has not yet completely expired, use it
            if ($accessToken !== '' && $tokenExpiresAt > $currentTime) {
                return $accessToken;
            }

            // Wait briefly for the concurrent renewal to finish
            usleep(500000); // 500ms
            $freshConn      = $this->settings->getConnection();
            $freshToken     = (string) ($freshConn['access_token'] ?? '');
            $freshExpiresAt = (int) ($freshConn['token_expires_at'] ?? 0);

            if ($freshToken !== '' && $freshExpiresAt > time()) {
                return $freshToken;
            }

            return new WP_Error(
                'authentication_unavailable',
                __('SuiteCRM token renewal is in progress by another worker.', 'sync-users-suitecrm'),
                ['http_status' => 409]
            );
        }

        // Lock acquired: perform renewal and guarantee release in finally
        try {
            // Check again under lock in case another worker refreshed right before lock acquisition
            $freshConn      = $this->settings->getConnection();
            $freshToken     = (string) ($freshConn['access_token'] ?? '');
            $freshExpiresAt = (int) ($freshConn['token_expires_at'] ?? 0);

            if ($freshToken !== '' && ($freshExpiresAt - time()) > self::EXPIRATION_BUFFER) {
                return $freshToken;
            }

            $refreshResult = $this->refreshToken();
            if (is_wp_error($refreshResult)) {
                return $refreshResult;
            }

            return (string) $refreshResult['access_token'];
        } finally {
            $this->lockManager->release($lockHandle);
        }
    }

    /**
     * Perform token refresh via refresh_token or password grant fallback.
     *
     * - Uses stored refresh_token if available; otherwise falls back to password grant credentials.
     * - Updates syus_connection option atomically with new token and computed UTC expiry timestamp.
     * - On renewal, replaces refresh_token when returned; otherwise retains existing refresh_token.
     * - Schedules next proactive renewal cron event.
     *
     * @return array{access_token: string, expires_in: int, refresh_token: ?string}|WP_Error
     */
    public function refreshToken() {
        $conn = $this->settings->getConnection();

        if (
            empty($conn['base_url']) ||
            empty($conn['client_id']) ||
            empty($conn['client_secret'])
        ) {
            $msg = __('SuiteCRM connection credentials are incomplete.', 'sync-users-suitecrm');
            $this->settings->setConnectionStatus('not_configured', $msg);
            return new WP_Error('authentication_unavailable', $msg, ['http_status' => 401]);
        }

        $storedRefreshToken = !empty($conn['refresh_token']) ? (string) $conn['refresh_token'] : null;
        $response           = null;

        // 1. Attempt renewal with refresh_token if present
        if ($storedRefreshToken !== null && $storedRefreshToken !== '') {
            $response = $this->client->requestTokenWithRefreshToken($storedRefreshToken, $conn);
            if (is_wp_error($response)) {
                // Refresh token invalid or expired; fall through to password grant fallback
                $response = null;
            }
        }

        // 2. Fall back to password grant credentials
        if ($response === null) {
            if (empty($conn['username']) || empty($conn['password'])) {
                $msg = __('SuiteCRM API username and password are required for renewal.', 'sync-users-suitecrm');
                $this->settings->setConnectionStatus('reconnect_required', $msg);
                return new WP_Error('authentication_unavailable', $msg, ['http_status' => 401]);
            }

            $response = $this->client->requestTokenWithPassword($conn);
        }

        // 3. Handle terminal renewal failure
        if (is_wp_error($response)) {
            $errorMsg = $response->get_error_message();
            $this->settings->setConnectionStatus('reconnect_required', $errorMsg);

            return new WP_Error(
                'authentication_unavailable',
                sprintf(
                    /* translators: %s: Error message */
                    __('SuiteCRM authentication failed: %s', 'sync-users-suitecrm'),
                    $errorMsg
                ),
                $response->get_error_data()
            );
        }

        // On renewal, replace the refresh token when one is returned; otherwise retain the current connection's refresh token
        $newRefreshToken = !empty($response['refresh_token'])
            ? (string) $response['refresh_token']
            : $storedRefreshToken;

        $expiresIn = (int) $response['expires_in'];

        $this->settings->updateTokenData(
            (string) $response['access_token'],
            $newRefreshToken,
            $expiresIn
        );

        $this->scheduleTokenRenewal($expiresIn);

        return [
            'access_token'  => (string) $response['access_token'],
            'expires_in'    => $expiresIn,
            'refresh_token' => $newRefreshToken,
        ];
    }

    /**
     * Schedule proactive token renewal event before the access token expires.
     *
     * @param int $expiresIn Token lifespan in seconds.
     * @return void
     */
    public function scheduleTokenRenewal(int $expiresIn): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);

        // Schedule renewal 5 minutes (300s) before expiry, or at least 60s in the future
        $leadTime      = max(60, $expiresIn - self::EXPIRATION_BUFFER);
        $scheduledTime = time() + $leadTime;

        wp_schedule_single_event($scheduledTime, self::CRON_HOOK);
    }

    /**
     * Access Settings instance.
     *
     * @return Settings
     */
    public function getSettings(): Settings {
        return $this->settings;
    }

    /**
     * Access SuiteCRM Client instance.
     *
     * @return Client
     */
    public function getClient(): Client {
        return $this->client;
    }
}
