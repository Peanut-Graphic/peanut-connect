<?php
/**
 * Peanut Connect Marketing Proxy
 *
 * Exposes campaign / UTM / link / analytics endpoints inside wp-admin
 * by forwarding authenticated requests to the connected Hub install.
 *
 * The plugin's React SPA never holds the Hub API key directly — every
 * call goes through this WP REST surface, which reads the saved key
 * from options and signs the upstream request.
 *
 * @package Peanut_Connect
 * @since 3.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Connect_Marketing {

    /**
     * Register REST routes.
     */
    public static function register_routes(): void {
        $ns = PEANUT_CONNECT_API_NAMESPACE;

        $perms = [self::class, 'check_admin_permission'];

        // Campaign builder
        register_rest_route($ns, '/marketing/campaigns', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'create_campaign'],
            'permission_callback' => $perms,
        ]);

        // UTMs
        register_rest_route($ns, '/marketing/utms', [
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'list_utms'],
                'permission_callback' => $perms,
            ],
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'create_utm'],
                'permission_callback' => $perms,
            ],
        ]);
        register_rest_route($ns, '/marketing/utms/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [self::class, 'update_utm'],
                'permission_callback' => $perms,
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [self::class, 'delete_utm'],
                'permission_callback' => $perms,
            ],
        ]);
        register_rest_route($ns, '/marketing/utms/(?P<id>\d+)/archive', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'archive_utm'],
            'permission_callback' => $perms,
        ]);
        register_rest_route($ns, '/marketing/utms/(?P<id>\d+)/restore', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'restore_utm'],
            'permission_callback' => $perms,
        ]);

        // Links
        register_rest_route($ns, '/marketing/links', [
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'list_links'],
                'permission_callback' => $perms,
            ],
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'create_link'],
                'permission_callback' => $perms,
            ],
        ]);
        register_rest_route($ns, '/marketing/links/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [self::class, 'update_link'],
                'permission_callback' => $perms,
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [self::class, 'delete_link'],
                'permission_callback' => $perms,
            ],
        ]);
        register_rest_route($ns, '/marketing/links/(?P<id>\d+)/toggle', [
            'methods'             => 'PATCH',
            'callback'            => [self::class, 'toggle_link'],
            'permission_callback' => $perms,
        ]);

        // Analytics passthrough — Hub already exposes these routes;
        // we just relay them so the SPA can stay behind one origin.
        register_rest_route($ns, '/marketing/journeys', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'list_journeys'],
            'permission_callback' => $perms,
        ]);
        register_rest_route($ns, '/marketing/journeys/stats', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'journey_stats'],
            'permission_callback' => $perms,
        ]);
        register_rest_route($ns, '/marketing/journeys/(?P<click_id>[A-Za-z0-9-]+)', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'journey_detail'],
            'permission_callback' => $perms,
        ]);

        // Tracking-setup helper — exposes the saved Hub URL + a non-secret
        // identifier so the SPA can render the GTM snippets. The actual
        // API key never leaves the server.
        register_rest_route($ns, '/marketing/tracking-setup', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'tracking_setup'],
            'permission_callback' => $perms,
        ]);
    }

    public static function check_admin_permission(): bool {
        return current_user_can('manage_options');
    }

    // ===== Campaign builder =====

    public static function create_campaign(WP_REST_Request $request) {
        return self::forward('POST', '/marketing/campaigns', $request->get_json_params());
    }

    // ===== UTMs =====

    public static function list_utms(WP_REST_Request $request) {
        return self::forward('GET', '/marketing/utms', null, $request->get_query_params());
    }

    public static function create_utm(WP_REST_Request $request) {
        return self::forward('POST', '/marketing/utms', $request->get_json_params());
    }

    public static function update_utm(WP_REST_Request $request) {
        $id = (int) $request['id'];
        return self::forward('PUT', "/marketing/utms/{$id}", $request->get_json_params());
    }

    public static function delete_utm(WP_REST_Request $request) {
        $id = (int) $request['id'];
        return self::forward('DELETE', "/marketing/utms/{$id}");
    }

    public static function archive_utm(WP_REST_Request $request) {
        $id = (int) $request['id'];
        return self::forward('POST', "/marketing/utms/{$id}/archive");
    }

    public static function restore_utm(WP_REST_Request $request) {
        $id = (int) $request['id'];
        return self::forward('POST', "/marketing/utms/{$id}/restore");
    }

    // ===== Links =====

    public static function list_links(WP_REST_Request $request) {
        return self::forward('GET', '/marketing/links', null, $request->get_query_params());
    }

    public static function create_link(WP_REST_Request $request) {
        return self::forward('POST', '/marketing/links', $request->get_json_params());
    }

    public static function update_link(WP_REST_Request $request) {
        $id = (int) $request['id'];
        return self::forward('PUT', "/marketing/links/{$id}", $request->get_json_params());
    }

    public static function delete_link(WP_REST_Request $request) {
        $id = (int) $request['id'];
        return self::forward('DELETE', "/marketing/links/{$id}");
    }

    public static function toggle_link(WP_REST_Request $request) {
        $id = (int) $request['id'];
        return self::forward('PATCH', "/marketing/links/{$id}/toggle");
    }

    // ===== Analytics =====

    public static function list_journeys(WP_REST_Request $request) {
        return self::forward('GET', '/journeys', null, $request->get_query_params());
    }

    public static function journey_stats(WP_REST_Request $request) {
        return self::forward('GET', '/journeys/stats', null, $request->get_query_params());
    }

    public static function journey_detail(WP_REST_Request $request) {
        $click_id = sanitize_text_field((string) $request['click_id']);
        return self::forward('GET', "/journeys/{$click_id}");
    }

    // ===== Tracking setup =====

    public static function tracking_setup(): WP_REST_Response {
        $hub_url = (string) get_option('peanut_connect_hub_url', '');
        $has_key = (bool) get_option('peanut_connect_hub_api_key', '');

        return new WP_REST_Response([
            'success'    => true,
            'connected'  => $hub_url !== '' && $has_key,
            'hub_url'    => $hub_url,
            'tracker_js' => $hub_url !== '' ? trailingslashit($hub_url) . 'js/tracker.min.js' : '',
            'site_key'   => self::masked_key(),
        ], 200);
    }

    // ===== Internal helpers =====

    /**
     * Forward a request to Hub's API and return the result as a WP_REST_Response.
     *
     * @param string             $method  HTTP method.
     * @param string             $path    Path under /api/v1 (must start with /).
     * @param array<mixed>|null  $body    JSON body for POST/PUT/PATCH.
     * @param array<string, mixed>|null $query Query string for GET.
     */
    private static function forward(string $method, string $path, ?array $body = null, ?array $query = null) {
        $hub_url = (string) get_option('peanut_connect_hub_url', '');
        $api_key = (string) get_option('peanut_connect_hub_api_key', '');

        if ($hub_url === '' || $api_key === '') {
            return new WP_Error(
                'peanut_connect_not_connected',
                __('This site is not connected to a Hub install. Configure the Hub URL and API key in Settings.', 'peanut-connect'),
                ['status' => 412]
            );
        }

        $url = trailingslashit($hub_url) . 'api/v1' . $path;
        if (!empty($query)) {
            $url = add_query_arg($query, $url);
        }

        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
        ];

        if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return new WP_Error(
                'peanut_connect_hub_unreachable',
                $response->get_error_message(),
                ['status' => 502]
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw    = (string) wp_remote_retrieve_body($response);
        $data   = json_decode($raw, true);

        if (!is_array($data)) {
            $data = ['raw' => $raw];
        }

        // Some hosts (cPanel + ImunifyAV / mod_security) rewrite 2xx responses
        // to 406 / 405 for "suspicious" payloads while passing the body
        // through unchanged. If the body explicitly says success, honour it
        // and return a clean 200 — otherwise the SPA's axios layer rejects
        // the response purely on status code.
        if (isset($data['success']) && $data['success'] === true && $status >= 400 && $status < 500) {
            $status = 200;
        }

        return new WP_REST_Response($data, $status > 0 ? $status : 502);
    }

    /**
     * Return a masked version of the saved API key for display.
     */
    private static function masked_key(): string {
        $key = (string) get_option('peanut_connect_hub_api_key', '');
        if ($key === '') {
            return '';
        }
        $len = strlen($key);
        if ($len <= 8) {
            return str_repeat('•', $len);
        }
        return substr($key, 0, 4) . str_repeat('•', $len - 8) . substr($key, -4);
    }
}
