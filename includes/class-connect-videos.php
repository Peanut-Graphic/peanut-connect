<?php
/**
 * Videos module — proxies the Connect plugin's video endpoints to the Hub
 * videos API (site-key Bearer). Mirrors Peanut_Connect_Marketing.
 *
 * @package Peanut_Connect
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Connect_Videos {

    public static function register_routes(): void {
        $ns = PEANUT_CONNECT_API_NAMESPACE;
        $perms = [self::class, 'check_admin_permission'];

        register_rest_route($ns, '/videos', [
            ['methods' => 'GET',  'callback' => [self::class, 'list_videos'],   'permission_callback' => $perms],
            ['methods' => 'POST', 'callback' => [self::class, 'create_video'],  'permission_callback' => $perms],
        ]);
        register_rest_route($ns, '/videos/(?P<id>\d+)', [
            ['methods' => 'PATCH',  'callback' => [self::class, 'update_video'],  'permission_callback' => $perms],
            ['methods' => 'DELETE', 'callback' => [self::class, 'delete_video'],  'permission_callback' => $perms],
        ]);
        register_rest_route($ns, '/videos/(?P<id>\d+)/analytics', [
            ['methods' => 'GET', 'callback' => [self::class, 'video_analytics'], 'permission_callback' => $perms],
        ]);
    }

    public static function check_admin_permission(): bool {
        return current_user_can('manage_options');
    }

    public static function list_videos(WP_REST_Request $request) {
        return self::forward('GET', '/videos', null, $request->get_query_params());
    }

    public static function create_video(WP_REST_Request $request) {
        return self::forward('POST', '/videos', $request->get_json_params());
    }

    public static function update_video(WP_REST_Request $request) {
        $id = (int) $request['id'];
        return self::forward('PATCH', '/videos/' . $id, $request->get_json_params());
    }

    public static function delete_video(WP_REST_Request $request) {
        $id = (int) $request['id'];
        return self::forward('DELETE', '/videos/' . $id);
    }

    public static function video_analytics(WP_REST_Request $request) {
        $id = (int) $request['id'];
        return self::forward('GET', '/videos/' . $id . '/analytics', null, $request->get_query_params());
    }

    private static function forward(string $method, string $path, ?array $body = null, ?array $query = null) {
        $hub_url = (string) get_option('peanut_connect_hub_url', '');
        $api_key = (string) get_option('peanut_connect_hub_api_key', '');

        if ($hub_url === '' || $api_key === '') {
            return new WP_Error(
                'peanut_connect_not_connected',
                __('This site is not connected to a Hub install yet.', 'peanut-connect'),
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

        if (isset($data['success']) && $data['success'] === true && $status >= 400) {
            $status = 200;
        }

        return new WP_REST_Response($data, $status > 0 ? $status : 502);
    }
}
