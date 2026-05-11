<?php
/**
 * Peanut Connect Short Link Redirect
 *
 * Intercepts 404 requests for single-segment URIs (e.g. /usa_1_display)
 * and, if the slug matches an active link in Hub, 302-redirects to
 * Hub's /go/{slug} route so Hub can handle UTM expansion and click
 * tracking.
 *
 * @package Peanut_Connect
 * @since 3.7.15
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Connect_Short_Links {

    private const CACHE_KEY      = 'peanut_connect_short_link_slugs';
    private const CACHE_TTL      = HOUR_IN_SECONDS;
    private const SKIP_PREFIXES  = [
        'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login.php',
        'wp-cron.php', 'xmlrpc.php', 'feed', 'comments', 'sitemap',
        'robots.txt', 'favicon.ico',
    ];

    public static function init(): void {
        // Run before WP serves its 404 template.
        add_action('template_redirect', [self::class, 'maybe_redirect'], 1);
    }

    public static function maybe_redirect(): void {
        if (!is_404()) {
            return;
        }

        $slug = self::extract_slug();
        if ($slug === null) {
            return;
        }

        $hub_url = self::hub_url();
        if ($hub_url === '') {
            return;
        }

        $slugs = self::get_slug_cache();
        if (!in_array($slug, $slugs, true)) {
            return;
        }

        $target = trailingslashit($hub_url) . 'go/' . rawurlencode($slug);
        wp_redirect($target, 302);
        exit;
    }

    /**
     * Pull the first URI segment, or null if the request can't be a short link.
     */
    private static function extract_slug(): ?string {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ($request_uri === '') {
            return null;
        }

        $path = (string) parse_url($request_uri, PHP_URL_PATH);
        $path = trim($path, '/');
        if ($path === '') {
            return null;
        }

        // Single segment only.
        if (strpos($path, '/') !== false) {
            return null;
        }

        // Skip WP paths and obvious file requests.
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (strpos($path, $prefix) === 0) {
                return null;
            }
        }
        if (strpos($path, '.') !== false) {
            return null;
        }

        // Slugs are alnum + dash + underscore.
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $path)) {
            return null;
        }

        return $path;
    }

    /**
     * Return the cached active-slug list, fetching from Hub on miss.
     *
     * @return array<int, string>
     */
    private static function get_slug_cache(): array {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $slugs = self::fetch_slugs_from_hub();
        set_transient(self::CACHE_KEY, $slugs, self::CACHE_TTL);
        return $slugs;
    }

    /**
     * Hit Hub's /marketing/links?active=1 endpoint and return slug strings.
     * Negative results are cached as an empty array so we don't hammer Hub.
     *
     * @return array<int, string>
     */
    private static function fetch_slugs_from_hub(): array {
        $hub_url = self::hub_url();
        $api_key = (string) get_option('peanut_connect_hub_api_key', '');
        if ($hub_url === '' || $api_key === '') {
            return [];
        }

        $url = trailingslashit($hub_url) . 'api/v1/marketing/links?active=1&per_page=100';
        $response = wp_remote_get($url, [
            'timeout' => 8,
            'headers' => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
        ]);

        if (is_wp_error($response)) {
            return [];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return [];
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return [];
        }

        // Response shape: { success: true, data: { data: [Link, ...], ... } }
        $rows = $body['data']['data'] ?? $body['data'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $slugs = [];
        foreach ($rows as $row) {
            if (isset($row['slug']) && is_string($row['slug']) && $row['slug'] !== '') {
                $slugs[] = $row['slug'];
            }
        }
        return $slugs;
    }

    private static function hub_url(): string {
        return rtrim((string) get_option('peanut_connect_hub_url', ''), '/');
    }

    /**
     * Public cache-bust — called by the Marketing proxy after a link
     * is created/updated/toggled/deleted so new slugs become live
     * without waiting for the hourly TTL.
     */
    public static function clear_cache(): void {
        delete_transient(self::CACHE_KEY);
    }
}
