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

        // GTM container coverage — proxies to Hub's site-scoped endpoint
        // which only returns captures for containers paired with this
        // site under Hub → Sites → Tracked GTM Containers.
        register_rest_route($ns, '/marketing/gtm-coverage', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'gtm_coverage'],
            'permission_callback' => $perms,
        ]);

        // Dominion PTR enrollment funnel — proxies to Hub's site-scoped
        // /api/v1/marketing/dominion-funnel (marketing → portal → enrolled).
        register_rest_route($ns, '/marketing/dominion-funnel', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'dominion_funnel'],
            'permission_callback' => $perms,
        ]);

        // Campaign lifecycle story — proxies to Hub's
        // /api/v1/marketing/campaign/{campaign}/story.
        register_rest_route($ns, '/marketing/campaign/(?P<campaign>[A-Za-z0-9_.\-]+)/story', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'campaign_story'],
            'permission_callback' => $perms,
        ]);
    }

    public static function check_admin_permission(): bool {
        return current_user_can('manage_options');
    }

    // ===== Campaign builder =====

    public static function create_campaign(WP_REST_Request $request) {
        $response = self::forward('POST', '/marketing/campaigns', $request->get_json_params());
        Peanut_Connect_Short_Links::clear_cache();
        return $response;
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
        $response = self::forward('POST', '/marketing/links', $request->get_json_params());
        Peanut_Connect_Short_Links::clear_cache();
        return $response;
    }

    public static function update_link(WP_REST_Request $request) {
        $id = (int) $request['id'];
        $response = self::forward('PUT', "/marketing/links/{$id}", $request->get_json_params());
        Peanut_Connect_Short_Links::clear_cache();
        return $response;
    }

    public static function delete_link(WP_REST_Request $request) {
        $id = (int) $request['id'];
        $response = self::forward('DELETE', "/marketing/links/{$id}");
        Peanut_Connect_Short_Links::clear_cache();
        return $response;
    }

    public static function toggle_link(WP_REST_Request $request) {
        $id = (int) $request['id'];
        $response = self::forward('PATCH', "/marketing/links/{$id}/toggle");
        Peanut_Connect_Short_Links::clear_cache();
        return $response;
    }

    // ===== Analytics =====

    public static function list_journeys(WP_REST_Request $request) {
        return self::forward('GET', '/journeys', null, $request->get_query_params());
    }

    public static function journey_stats(WP_REST_Request $request) {
        $params = $request->get_query_params();

        return self::forward('GET', '/journeys/stats', null, $params);
    }

    public static function journey_detail(WP_REST_Request $request) {
        $click_id = sanitize_text_field((string) $request['click_id']);
        return self::forward('GET', "/journeys/{$click_id}");
    }

    public static function gtm_coverage(WP_REST_Request $request) {
        return self::forward('GET', '/marketing/gtm-coverage', null, $request->get_query_params());
    }

    public static function dominion_funnel(WP_REST_Request $request) {
        return self::forward('GET', '/marketing/dominion-funnel', null, $request->get_query_params());
    }

    public static function campaign_story(WP_REST_Request $request) {
        $campaign = sanitize_text_field((string) $request['campaign']);
        return self::forward('GET', "/marketing/campaign/{$campaign}/story", null, $request->get_query_params());
    }

    // ===== Tracking setup =====

    public static function tracking_setup(): WP_REST_Response {
        $hub_url = (string) get_option('peanut_connect_hub_url', '');
        $connected = $hub_url !== '' && Peanut_Connect_Auth::get_hub_api_key() !== '';
        // The tracker snippet uses the PUBLIC tracker_key (delivered by Hub on
        // heartbeat), never the Hub bearer. The bearer authorises /restore,
        // /update, banner, etc., so it must never be returned to the browser or
        // embedded in public page HTML.
        $tracker_key = (string) get_option('peanut_connect_tracker_key', '');

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'connected'        => $connected,
                'hub_url'          => $hub_url,
                'tracker_js'       => $hub_url !== '' ? trailingslashit($hub_url) . 'js/tracker.min.js' : '',
                // Public tracker identifier — safe to embed in the snippet.
                'site_key'         => $tracker_key,
                'site_key_masked'  => self::masked_key(),
                // false until JS tracking is enabled for this site in Hub (then
                // Hub starts sending the tracker_key on heartbeat).
                'tracker_ready'    => $tracker_key !== '',
            ],
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
        $api_key = Peanut_Connect_Auth::get_hub_api_key();

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

        $encoded_body = '';
        if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $encoded_body = wp_json_encode($body);
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = $encoded_body;
        }

        $args['headers'] = array_merge(
            $args['headers'],
            Peanut_Connect_Auth::outbound_signature_headers($method, $url, $encoded_body)
        );

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
        // to 406 / 405 / 503 for "suspicious" URL patterns while passing the
        // body through unchanged. If the body explicitly says success, honour
        // it and return a clean 200 regardless of the rewritten status —
        // otherwise the SPA's axios layer rejects on status code.
        if (isset($data['success']) && $data['success'] === true && $status >= 400) {
            $status = 200;
        }

        return new WP_REST_Response($data, $status > 0 ? $status : 502);
    }

    /**
     * Return a masked version of the public tracker key for display.
     */
    private static function masked_key(): string {
        $key = (string) get_option('peanut_connect_tracker_key', '');
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
