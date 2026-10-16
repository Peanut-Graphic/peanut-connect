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
        register_rest_route($ns, '/videos/(?P<id>\d+)/page', [
            ['methods' => 'POST', 'callback' => [self::class, 'create_page'], 'permission_callback' => $perms],
        ]);
    }

    /** Post meta that ties a WordPress page to the Hub video it was made for. */
    public const PAGE_META = '_peanut_video_slug';

    public static function check_admin_permission(): bool {
        return current_user_can('manage_options');
    }

    public static function list_videos(WP_REST_Request $request) {
        $response = self::forward('GET', '/videos', null, $request->get_query_params());
        if (!$response instanceof WP_REST_Response || $response->get_status() !== 200) {
            return $response;
        }

        // Tell the SPA which videos already have a page, so the button reads
        // "Edit page" instead of making a second one.
        $data = $response->get_data();
        if (isset($data['data']) && is_array($data['data'])) {
            $pages = self::pages_for_slugs(array_column($data['data'], 'slug'));
            foreach ($data['data'] as $i => $video) {
                $data['data'][$i]['page'] = $pages[$video['slug'] ?? ''] ?? null;
            }
            return new WP_REST_Response($data, 200);
        }

        return $response;
    }

    /**
     * Make (or return) a draft WordPress page for one video: title, the video
     * block, a transcript from its captions, and its poster as featured image.
     * Everything comes from Hub's own record of the video, never the request
     * body, so the endpoint can only build pages for this site's videos.
     */
    public static function create_page(WP_REST_Request $request) {
        $id = (int) $request['id'];
        $list = self::forward('GET', '/videos');
        if (!$list instanceof WP_REST_Response || $list->get_status() !== 200) {
            return $list;
        }
        $body = $list->get_data();
        $video = null;
        foreach ((array) ($body['data'] ?? []) as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                $video = $row;
                break;
            }
        }
        if ($video === null || empty($video['slug'])) {
            return new WP_Error('peanut_video_not_found', __('Video not found.', 'peanut-connect'), ['status' => 404]);
        }

        $slug = (string) $video['slug'];
        $existing = self::pages_for_slugs([$slug]);
        if (isset($existing[$slug])) {
            return new WP_REST_Response(['success' => true, 'data' => $existing[$slug] + ['created' => false]], 200);
        }

        $hub_url = (string) get_option('peanut_connect_hub_url', '');
        $transcript = '';
        $caption_url = (string) ($video['caption_url'] ?? '');
        if ($caption_url !== '' && self::is_hub_url($caption_url, $hub_url)) {
            $vtt = wp_remote_get($caption_url, ['timeout' => 10]);
            if (!is_wp_error($vtt) && (int) wp_remote_retrieve_response_code($vtt) === 200) {
                $transcript = self::vtt_to_transcript((string) wp_remote_retrieve_body($vtt));
            }
        }

        $page_id = wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'draft',
            'post_title'   => (string) ($video['title'] ?? $slug),
            'post_content' => self::page_content($slug, (string) ($video['description'] ?? ''), $transcript),
            'meta_input'   => [self::PAGE_META => $slug],
        ], true);
        if (is_wp_error($page_id)) {
            return new WP_Error('peanut_video_page_failed', $page_id->get_error_message(), ['status' => 500]);
        }

        $poster = (string) ($video['poster_url'] ?? '');
        if ($poster !== '' && str_starts_with($poster, 'https://')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attachment_id = media_sideload_image($poster, $page_id, (string) ($video['title'] ?? ''), 'id');
            if (!is_wp_error($attachment_id)) {
                set_post_thumbnail($page_id, (int) $attachment_id);
            }
        }

        return new WP_REST_Response(['success' => true, 'data' => self::page_summary((int) $page_id) + ['created' => true]], 201);
    }

    /**
     * @param list<string> $slugs
     * @return array<string, array{id:int,status:string,edit_url:string,view_url:string}>
     */
    private static function pages_for_slugs(array $slugs): array {
        $slugs = array_values(array_filter(array_map('strval', $slugs)));
        if ($slugs === []) {
            return [];
        }
        $posts = get_posts([
            'post_type'      => 'page',
            'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => -1,
            'meta_query'     => [['key' => self::PAGE_META, 'value' => $slugs, 'compare' => 'IN']],
        ]);
        $out = [];
        foreach ($posts as $post) {
            $slug = (string) get_post_meta($post->ID, self::PAGE_META, true);
            if ($slug !== '' && !isset($out[$slug])) {
                $out[$slug] = self::page_summary((int) $post->ID);
            }
        }
        return $out;
    }

    private static function page_summary(int $page_id): array {
        $status = (string) get_post_status($page_id);
        return [
            'id'       => $page_id,
            'status'   => $status,
            'edit_url' => (string) get_edit_post_link($page_id, 'raw'),
            'view_url' => $status === 'publish' ? (string) get_permalink($page_id) : (string) get_preview_post_link($page_id),
        ];
    }

    /** Captions are fetched only from the connected Hub, never an arbitrary host. */
    public static function is_hub_url(string $url, string $hub_url): bool {
        $host = wp_parse_url($url, PHP_URL_HOST);
        $hub  = wp_parse_url($hub_url, PHP_URL_HOST);
        return is_string($host) && is_string($hub) && $host !== ''
            && strtolower($host) === strtolower($hub)
            && wp_parse_url($url, PHP_URL_SCHEME) === 'https';
    }

    /** WebVTT -> plain transcript: drop the header, NOTE/STYLE/REGION blocks, cue ids and timings. */
    public static function vtt_to_transcript(string $vtt): string {
        $blocks = preg_split('/(?:\r\n|\r|\n){2,}/', trim($vtt)) ?: [];
        $text = [];
        foreach ($blocks as $block) {
            $lines = preg_split('/\r\n|\r|\n/', trim($block)) ?: [];
            $first = trim($lines[0] ?? '');
            if ($first === '' || preg_match('/^(WEBVTT|NOTE|STYLE|REGION)\b/', $first)) {
                continue;
            }
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_contains($line, '-->') || ctype_digit($line)) {
                    continue;
                }
                $text[] = wp_strip_all_tags($line);
            }
        }
        return trim((string) preg_replace('/\s+/', ' ', implode(' ', $text)));
    }

    /** Block markup for the page: the video block, then optional intro and transcript. */
    public static function page_content(string $slug, string $description, string $transcript): string {
        $blocks = [];
        if ($description !== '') {
            $blocks[] = "<!-- wp:paragraph -->\n<p>" . esc_html($description) . "</p>\n<!-- /wp:paragraph -->";
        }
        $blocks[] = '<!-- wp:peanut-connect/video ' . wp_json_encode(['slug' => $slug]) . ' /-->';
        if ($transcript !== '') {
            $blocks[] = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html__('Transcript', 'peanut-connect') . "</h2>\n<!-- /wp:heading -->";
            $blocks[] = "<!-- wp:paragraph -->\n<p>" . esc_html($transcript) . "</p>\n<!-- /wp:paragraph -->";
        }
        return implode("\n\n", $blocks);
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
