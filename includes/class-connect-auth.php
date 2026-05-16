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
        $permissions = get_option('peanut_connect_permissions', []);

        // Health check and list updates are always allowed
        if (in_array($permission, ['health_check', 'list_updates'])) {
            return true;
        }

        return !empty($permissions[$permission]);
    }

    /**
     * Get all permissions
     */
    public static function get_permissions(): array {
        return get_option('peanut_connect_permissions', [
            'health_check' => true,
            'list_updates' => true,
            'perform_updates' => true,
            'access_analytics' => true,
            'publish_content' => true,
        ]);
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
        $stored_key = get_option('peanut_connect_hub_api_key');

        if (empty($stored_key)) {
            return new WP_Error(
                'not_configured',
                __('Hub API key not configured. Please connect to Hub first.', 'peanut-connect'),
                ['status' => 403]
            );
        }

        // Timing-safe comparison
        if (!hash_equals($stored_key, $provided_key)) {
            return new WP_Error(
                'invalid_key',
                __('Invalid Hub API key.', 'peanut-connect'),
                ['status' => 401]
            );
        }

        return true;
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
