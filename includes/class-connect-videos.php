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
        $api_key = Peanut_Connect_Auth::get_hub_api_key();

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

        if (isset($data['success']) && $data['success'] === true && $status >= 400) {
            $status = 200;
        }

        return new WP_REST_Response($data, $status > 0 ? $status : 502);
    }

    public static function init(): void {
        add_shortcode('peanut_video', [self::class, 'shortcode']);
    }

    public static function register_block(): void {
        if (!function_exists('register_block_type')) {
            return;
        }
        register_block_type(PEANUT_CONNECT_PLUGIN_DIR . 'blocks/peanut-video', [
            'render_callback' => [self::class, 'render_block'],
        ]);
    }

    public static function render_block($attributes, $content): string {
        $slug = isset($attributes['slug']) ? (string) $attributes['slug'] : '';
        return self::shortcode(['slug' => $slug]);
    }

    public static function shortcode($atts): string {
        $atts = shortcode_atts(['slug' => '', 'max_width' => '', 'autoplay' => ''], $atts, 'peanut_video');
        // Case-preserving on purpose: Hub slugs end in a mixed-case Str::random(6) suffix.
        // Do NOT switch to sanitize_title()/sanitize_key() — they lowercase and 404 the embed.
        $slug = (string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) $atts['slug']);
        if ($slug === '') {
            return '<!-- Peanut Video: No slug specified -->';
        }

        $hub_url = (string) get_option('peanut_connect_hub_url', '');
        if ($hub_url === '') {
            $msg = '<!-- Peanut Video: site not connected to a Hub install -->';
            if (current_user_can('manage_options')) {
                $msg .= '<p style="font-size:12px;color:#a00">Peanut Video: this site is not connected to a Hub install.</p>';
            }
            return $msg;
        }

        $src = trailingslashit($hub_url) . 'video/' . rawurlencode($slug) . '/embed';
        if ($atts['autoplay'] !== '') {
            $src = add_query_arg('autoplay', '1', $src);
        }

        $style_wrap = 'position:relative;width:100%;padding-top:56.25%;';
        if ($atts['max_width'] !== '') {
            $mw = preg_replace('/[^0-9]/', '', (string) $atts['max_width']);
            if ($mw !== '') {
                $style_wrap = 'max-width:' . $mw . 'px;margin:0 auto;' . $style_wrap;
            }
        }

        return sprintf(
            '<div class="peanut-video" style="%s"><iframe src="%s" title="Video" loading="lazy" allow="fullscreen; encrypted-media" style="position:absolute;inset:0;width:100%%;height:100%%;border:0" allowfullscreen></iframe></div>',
            esc_attr($style_wrap),
            esc_url($src)
        );
    }
}
