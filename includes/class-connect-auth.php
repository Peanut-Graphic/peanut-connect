<?php
/**
 * Peanut Connect Authentication
 *
 * Handles request verification from manager sites with rate limiting
 * to prevent brute force attacks and API abuse.
 *
 * @package Peanut_Connect
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Connect_Auth {

    /**
     * Max age (seconds) of a signed request's timestamp — anti-replay window.
     */
    private const SIGNATURE_WINDOW = 300;

    /**
     * Wire-protocol versions this edge understands (X-Peanut-Protocol header).
     * A request that declares a version NOT in this set is rejected with a
     * distinct, legible error instead of a cryptic signature failure — so the
     * next breaking canonicalization change fails loudly, not silently. A
     * request with NO header is treated as v1 (backward-compatible: older Hubs
     * that predate the header keep working).
     */
    private const SUPPORTED_PROTOCOLS = ['1'];

    /**
     * Is this declared wire-protocol version one we understand? Empty string =
     * no header = legacy v1 (accepted). Any other value must be in the
     * supported set. Pure + testable; used by verify_hub_request().
     */
    public static function is_supported_protocol(string $protocol): bool {
        return $protocol === '' || in_array($protocol, self::SUPPORTED_PROTOCOLS, true);
    }

    /**
     * Canonical default Hub permission flags — the single source of truth for
     * has_permission(), get_permissions(), and the activation seeding. Before
     * this constant existed those three sites disagreed (publish_content was
     * missing from activation, api_proxy from the auth defaults), so a freshly
     * paired site could silently 403 a capability the SPA showed as available.
     *
     * High-impact capabilities (remote updates, content publishing, the
     * outbound proxy) default to FALSE — the site owner must explicitly opt in.
     * Read-only visibility (health, update listing, analytics) defaults to TRUE.
     * health_check / list_updates are additionally always-allowed in
     * has_permission() regardless of the stored option.
     */
    public const DEFAULT_PERMISSIONS = [
        'health_check'     => true,
        'list_updates'     => true,
        'access_analytics' => true,
        'perform_updates'  => false,
        'publish_content'  => false,
        'backup_restore'   => false,
        'api_proxy'        => false,
    ];

    /**
     * Verify incoming request from manager
     *
     * Performs rate limiting check before authentication to prevent
     * brute force attacks. Uses timing-safe comparison for key validation.
     *
     * @param WP_REST_Request $request The incoming REST request
     * @param string          $endpoint Optional endpoint identifier for rate limiting
     * @return bool|WP_Error True if authenticated, WP_Error on failure
     */
    public static function verify_request(WP_REST_Request $request, string $endpoint = 'default'): bool|WP_Error {
        // Check rate limit first (before any authentication logic)
        $client_id = Peanut_Connect_Rate_Limiter::get_client_identifier($request);
        $rate_check = Peanut_Connect_Rate_Limiter::check($client_id, $endpoint);

        if (is_wp_error($rate_check)) {
            return $rate_check;
        }

        $auth_header = $request->get_header('Authorization');

        if (empty($auth_header)) {
            return new WP_Error(
                'missing_authorization',
                __('Authorization header is required.', 'peanut-connect'),
                ['status' => 401]
            );
        }

        // Extract Bearer token
        if (!preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches)) {
            return new WP_Error(
                'invalid_authorization',
                __('Invalid authorization format. Use Bearer token.', 'peanut-connect'),
                ['status' => 401]
            );
        }

        $provided_key = $matches[1];
        $stored_key = get_option('peanut_connect_site_key');

        if (empty($stored_key)) {
            return new WP_Error(
                'not_configured',
                __('Site key not configured. Please generate a site key first.', 'peanut-connect'),
                ['status' => 403]
            );
        }

        // Timing-safe comparison
        if (!hash_equals($stored_key, $provided_key)) {
            return new WP_Error(
                'invalid_key',
                __('Invalid site key.', 'peanut-connect'),
                ['status' => 401]
            );
        }

        // Store manager URL from header
        $manager_url = $request->get_header('X-Peanut-Manager');
        if ($manager_url) {
            update_option('peanut_connect_manager_url', esc_url_raw($manager_url));
        }

        // Update last sync time
        update_option('peanut_connect_last_sync', current_time('mysql'));

        return true;
    }

    /**
     * Add rate limit headers to REST response
     *
     * @param WP_REST_Response $response The response object
     * @param WP_REST_Request  $request  The request object
     * @param string           $endpoint Endpoint identifier
     * @return WP_REST_Response Modified response with rate limit headers
     */
    public static function add_rate_limit_headers(
        WP_REST_Response $response,
        WP_REST_Request $request,
        string $endpoint = 'default'
    ): WP_REST_Response {
        $client_id = Peanut_Connect_Rate_Limiter::get_client_identifier($request);
        $headers = Peanut_Connect_Rate_Limiter::get_headers($client_id, $endpoint);

        foreach ($headers as $name => $value) {
            $response->header($name, $value);
        }

        return $response;
    }

    /**
     * Check if a specific permission is allowed
     */
    public static function has_permission(string $permission): bool {
        // Health check and list updates are always allowed.
        if (in_array($permission, ['health_check', 'list_updates'], true)) {
            return true;
        }

        $permissions = self::get_permissions();
        return !empty($permissions[$permission]);
    }

    /**
     * Get all permissions, merged over the canonical defaults.
     *
     * Merging (rather than returning the stored array verbatim) guarantees that
     * flags added after a site was first paired — publish_content, api_proxy —
     * always have a defined value, while a site owner's explicit stored choices
     * (e.g. perform_updates they enabled) are preserved.
     */
    public static function get_permissions(): array {
        $stored = get_option('peanut_connect_permissions', null);
        if (!is_array($stored)) {
            return self::DEFAULT_PERMISSIONS;
        }
        return array_merge(self::DEFAULT_PERMISSIONS, $stored);
    }

    /**
     * Centralized accessor for the Hub API key option. ALL reads/writes of
     * peanut_connect_hub_api_key go through these three methods so the at-rest
     * representation (Phase B: encrypted) lives in exactly one place.
     */

    /**
     * Read the Hub API key, decrypted. Returns '' when unset or when the
     * stored value can no longer be decrypted (e.g. after a WP salt rotation),
     * in which case it flags the site for the admin re-pair notice. A legacy
     * plaintext value is returned as-is and transparently re-stored encrypted.
     */
    public static function get_hub_api_key(): string {
        $stored = (string) get_option('peanut_connect_hub_api_key', '');
        if ($stored === '') {
            return '';
        }
        if (Peanut_Connect_Secret::is_ciphertext($stored)) {
            $plain = Peanut_Connect_Secret::decrypt($stored);
            if ($plain === null) {
                update_option('peanut_connect_hub_key_undecryptable', 1);
                return '';
            }
            return $plain;
        }
        // Legacy plaintext: return it, and migrate to encrypted on this read.
        self::set_hub_api_key($stored);
        return $stored;
    }

    /**
     * Store the Hub API key encrypted at rest. Clears any prior
     * "undecryptable" flag (the key is now freshly set).
     *
     * @param string $key The raw Hub API key.
     * @return bool Whether the option was written.
     */
    public static function set_hub_api_key(string $key): bool {
        $ok = (bool) update_option('peanut_connect_hub_api_key', Peanut_Connect_Secret::encrypt($key));
        delete_option('peanut_connect_hub_key_undecryptable');
        return $ok;
    }

    /**
     * Remove the stored Hub API key and any "undecryptable" flag.
     */
    public static function clear_hub_api_key(): void {
        delete_option('peanut_connect_hub_api_key');
        delete_option('peanut_connect_hub_key_undecryptable');
    }

    /**
     * Permission callback for REST endpoints
     *
     * Creates a permission callback with rate limiting for the specified endpoint.
     *
     * @param WP_REST_Request $request The REST request
     * @return bool|WP_Error True if authorized, WP_Error on failure
     */
    public static function permission_callback(WP_REST_Request $request): bool|WP_Error {
        // Extract endpoint from route for rate limiting
        $route = $request->get_route();
        $endpoint = self::extract_endpoint_from_route($route);

        return self::verify_request($request, $endpoint);
    }

    /**
     * Permission callback requiring specific permission
     *
     * Creates a closure that verifies both authentication and specific permission.
     * Includes rate limiting based on the permission type.
     *
     * @param string $permission The required permission
     * @return callable Permission callback function
     */
    public static function permission_callback_for(string $permission): callable {
        return function(WP_REST_Request $request) use ($permission): bool|WP_Error {
            // Map permissions to endpoints for rate limiting
            $endpoint_map = [
                'perform_updates' => 'update',
                'access_analytics' => 'analytics',
            ];
            $endpoint = $endpoint_map[$permission] ?? 'default';

            $verified = self::verify_request($request, $endpoint);

            if (is_wp_error($verified)) {
                return $verified;
            }

            if (!self::has_permission($permission)) {
                return new WP_Error(
                    'permission_denied',
                    sprintf(__('Permission "%s" is not allowed on this site.', 'peanut-connect'), $permission),
                    ['status' => 403]
                );
            }

            return true;
        };
    }

    /**
     * Extract endpoint identifier from REST route
     *
     * @param string $route The REST route path
     * @return string Endpoint identifier for rate limiting
     */
    private static function extract_endpoint_from_route(string $route): string {
        // Remove namespace prefix
        $route = str_replace('/peanut-connect/v1/', '', $route);

        // Map routes to endpoint identifiers
        $endpoint_map = [
            'verify' => 'verify',
            'health' => 'health',
            'updates' => 'updates',
            'update' => 'update',
            'analytics' => 'analytics',
            'disconnect' => 'disconnect',
        ];

        foreach ($endpoint_map as $pattern => $endpoint) {
            if (str_starts_with($route, $pattern)) {
                return $endpoint;
            }
        }

        return 'default';
    }

    /**
     * Verify incoming request from Hub
     *
     * Validates the request against the Hub API key.
     *
     * @param WP_REST_Request $request The incoming REST request
     * @return bool|WP_Error True if authenticated, WP_Error on failure
     */
    public static function verify_hub_request(WP_REST_Request $request): bool|WP_Error {
        // Check rate limit first
        $client_id = Peanut_Connect_Rate_Limiter::get_client_identifier($request);
        $rate_check = Peanut_Connect_Rate_Limiter::check($client_id, 'hub');

        if (is_wp_error($rate_check)) {
            return $rate_check;
        }

        // Protocol negotiation: reject a declared-but-unsupported wire version
        // with a clear error rather than letting it fail later as an opaque
        // signature mismatch. A missing header means v1 (legacy Hubs).
        $protocol = (string) $request->get_header('X-Peanut-Protocol');
        if (!self::is_supported_protocol($protocol)) {
            return new WP_Error(
                'unsupported_protocol',
                sprintf(
                    /* translators: %s: the requested protocol version */
                    __('Unsupported Hub protocol version "%s". This site needs an update.', 'peanut-connect'),
                    $protocol
                ),
                ['status' => 400]
            );
        }

        $stored_key = self::get_hub_api_key();
        if ($stored_key === '') {
            return new WP_Error(
                'not_configured',
                __('Hub API key not configured. Please connect to Hub first.', 'peanut-connect'),
                ['status' => 403]
            );
        }

        // Preferred path: HMAC-signed request. The signature proves possession
        // of the shared key WITHOUT transmitting it, and the timestamp + nonce
        // make a captured request non-replayable. Used whenever Hub sends the
        // X-Peanut-Signature header.
        $signature = $request->get_header('X-Peanut-Signature');
        if (!empty($signature)) {
            return self::verify_signed_hub_request($request, $stored_key, (string) $signature);
        }

        // Legacy path: static Bearer token, for backward compatibility with
        // Hubs/sites not yet emitting signed requests. A fully migrated site can
        // set the `peanut_connect_require_signed_requests` option to reject
        // unsigned requests — which makes a leaked bearer useless.
        if (get_option('peanut_connect_require_signed_requests', false)) {
            return new WP_Error(
                'signature_required',
                __('This site requires signed Hub requests.', 'peanut-connect'),
                ['status' => 401]
            );
        }

        $auth_header = $request->get_header('Authorization');
        if (empty($auth_header)) {
            return new WP_Error(
                'missing_authorization',
                __('Authorization header is required.', 'peanut-connect'),
                ['status' => 401]
            );
        }
        if (!preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches)) {
            return new WP_Error(
                'invalid_authorization',
                __('Invalid authorization format. Use Bearer token.', 'peanut-connect'),
                ['status' => 401]
            );
        }
        if (!hash_equals($stored_key, $matches[1])) {
            return new WP_Error(
                'invalid_key',
                __('Invalid Hub API key.', 'peanut-connect'),
                ['status' => 401]
            );
        }

        return true;
    }

    /**
     * Verify an HMAC-signed Hub request: timestamp freshness, single-use nonce
     * (both anti-replay), then a constant-time signature check.
     *
     * @param WP_REST_Request $request    The incoming request.
     * @param string          $stored_key The shared Hub key.
     * @param string          $signature  The provided X-Peanut-Signature.
     * @return bool|WP_Error
     */
    private static function verify_signed_hub_request(WP_REST_Request $request, string $stored_key, string $signature): bool|WP_Error {
        $timestamp = (string) $request->get_header('X-Peanut-Timestamp');
        $nonce = (string) $request->get_header('X-Peanut-Nonce');

        if ($timestamp === '' || $nonce === '') {
            return new WP_Error('invalid_signature', __('Signed request is missing its timestamp or nonce.', 'peanut-connect'), ['status' => 401]);
        }

        // Freshness window — a stale captured request is rejected.
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::SIGNATURE_WINDOW) {
            return new WP_Error('stale_request', __('Signed request timestamp is outside the allowed window.', 'peanut-connect'), ['status' => 401]);
        }

        // Verify the signature BEFORE claiming the nonce, so a bad-signature
        // request can't burn a victim's nonce.
        $valid = self::verify_signature(
            $stored_key,
            (string) $request->get_method(),
            (string) $request->get_route(),
            $timestamp,
            $nonce,
            (string) $request->get_body(),
            $signature
        );
        if (!$valid) {
            return new WP_Error('invalid_signature', __('Invalid request signature.', 'peanut-connect'), ['status' => 401]);
        }

        // Single-use nonce, claimed ATOMICALLY. add_option() performs an INSERT
        // that returns false if the row already exists, so two concurrent
        // requests carrying the same captured nonce cannot both pass — closing
        // the check-then-set race the previous get_transient()/set_transient()
        // pair allowed. The stored value is the expiry (swept by
        // purge_expired_nonces() on the daily cleanup cron); correctness does
        // not depend on the sweep, because a request older than the freshness
        // window is already rejected above. autoload 'no' keeps these off the
        // always-loaded options.
        $nonce_key = 'pc_hub_nonce_' . hash('sha256', $nonce);
        $claimed = add_option($nonce_key, (string) (time() + self::SIGNATURE_WINDOW * 2), '', 'no');
        if (!$claimed) {
            return new WP_Error('replayed_request', __('Signed request nonce has already been used.', 'peanut-connect'), ['status' => 401]);
        }

        return true;
    }

    /**
     * Sweep expired single-use nonce claims left by verify_signed_hub_request().
     * Hooked to the daily peanut_connect_cleanup cron. Bounded, prepared query.
     */
    public static function purge_expired_nonces(): void {
        global $wpdb;
        if (!isset($wpdb)) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
                $wpdb->esc_like('pc_hub_nonce_') . '%',
                time()
            )
        );
    }

    /**
     * Canonical HMAC-SHA256 signature for a Hub→site request. Hub computes the
     * identical string with the shared site key. The body is hashed (not
     * included raw) so large payloads aren't buffered twice.
     *
     * @return string Lowercase-hex signature.
     */
    public static function compute_request_signature(string $key, string $method, string $route, string $timestamp, string $nonce, string $body): string {
        $canonical = implode("\n", [
            strtoupper($method),
            $route,
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
        return hash_hmac('sha256', $canonical, $key);
    }

    /**
     * Constant-time verification of a provided request signature.
     */
    public static function verify_signature(string $key, string $method, string $route, string $timestamp, string $nonce, string $body, string $provided): bool {
        if ($key === '' || $provided === '') {
            return false;
        }
        $expected = self::compute_request_signature($key, $method, $route, $timestamp, $nonce, $body);
        return hash_equals($expected, strtolower(trim($provided)));
    }

    /**
     * Permission callback for Hub endpoints
     *
     * @param WP_REST_Request $request The REST request
     * @return bool|WP_Error True if authorized, WP_Error on failure
     */
    public static function hub_permission_callback(WP_REST_Request $request): bool|WP_Error {
        return self::verify_hub_request($request);
    }

    /**
     * Permission callback for Hub endpoints requiring specific permission
     *
     * @param string $permission The required permission
     * @return callable Permission callback function
     */
    public static function hub_permission_callback_for(string $permission): callable {
        return function(WP_REST_Request $request) use ($permission): bool|WP_Error {
            $verified = self::verify_hub_request($request);

            if (is_wp_error($verified)) {
                return $verified;
            }

            if (!self::has_permission($permission)) {
                return new WP_Error(
                    'permission_denied',
                    sprintf(__('Permission "%s" is not allowed on this site.', 'peanut-connect'), $permission),
                    ['status' => 403]
                );
            }

            return true;
        };
    }
}
