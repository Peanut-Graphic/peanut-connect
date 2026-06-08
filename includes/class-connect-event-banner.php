<?php
/**
 * Peanut Connect Event Banner
 *
 * Handles PTR (Peak Time Rebates) event banner display on the frontend.
 * Receives banner commands from Hub and renders announcement banners.
 *
 * @since 3.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Connect_Event_Banner {

    /**
     * Option key for storing active banner
     */
    private const OPTION_KEY = 'peanut_connect_event_banner';

    /**
     * Initialize the event banner module
     */
    public static function init(): void {
        // Frontend banner display
        add_action('wp_head', [__CLASS__, 'render_banner'], 1);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        // Auto-hide expired banners
        add_action('init', [__CLASS__, 'check_banner_expiry']);
    }

    /**
     * Get active banner from options
     */
    public static function get_active_banner(): ?array {
        $banner = get_option(self::OPTION_KEY);

        if (empty($banner) || !is_array($banner)) {
            return null;
        }

        // Check if banner should still be active
        if (!empty($banner['hide_at'])) {
            $hide_at = strtotime($banner['hide_at']);
            if ($hide_at && time() > $hide_at) {
                // Banner has expired, clear it
                self::clear_banner();
                return null;
            }
        }

        return $banner['active'] ? $banner : null;
    }

    /**
     * Set active banner (called by API)
     */
    public static function set_banner(array $data): bool {
        $banner = [
            'deployment_id' => intval($data['deployment_id'] ?? 0),
            'event_id' => intval($data['event_id'] ?? 0),
            'html' => self::sanitize_banner_html((string) ($data['html'] ?? '')),
            'css' => self::sanitize_banner_css((string) ($data['css'] ?? '')),
            'position' => self::sanitize_position($data['position'] ?? 'top'),
            'show_at' => sanitize_text_field($data['show_at'] ?? ''),
            'hide_at' => sanitize_text_field($data['hide_at'] ?? ''),
            'active' => true,
            'received_at' => current_time('mysql'),
            'is_test' => !empty($data['is_test']),
        ];

        $result = update_option(self::OPTION_KEY, $banner, false);

        if ($result) {
            // Log activity
            if (class_exists('Peanut_Connect_Activity_Log')) {
                Peanut_Connect_Activity_Log::log(
                    'event_banner_shown',
                    sprintf(
                        'Event banner activated (Deployment #%d, Event #%d)',
                        $banner['deployment_id'],
                        $banner['event_id']
                    ),
                    'success'
                );
            }

            // Send acknowledgment to Hub (async if possible)
            self::send_acknowledgment($banner['deployment_id'], 'show');
        }

        return $result;
    }

    /**
     * Clear active banner (called by API)
     */
    public static function clear_banner(): bool {
        $current_banner = get_option(self::OPTION_KEY);
        $deployment_id = $current_banner['deployment_id'] ?? 0;

        $result = delete_option(self::OPTION_KEY);

        if ($result && $deployment_id) {
            // Log activity
            if (class_exists('Peanut_Connect_Activity_Log')) {
                Peanut_Connect_Activity_Log::log(
                    'event_banner_hidden',
                    sprintf('Event banner removed (Deployment #%d)', $deployment_id),
                    'success'
                );
            }

            // Send acknowledgment to Hub
            self::send_acknowledgment($deployment_id, 'hide');
        }

        return $result;
    }

    /**
     * Render banner on frontend
     */
    public static function render_banner(): void {
        // Don't show in admin
        if (is_admin()) {
            return;
        }

        $banner = self::get_active_banner();

        if (!$banner || empty($banner['html'])) {
            return;
        }

        // Check if banner should be shown yet
        if (!empty($banner['show_at'])) {
            $show_at = strtotime($banner['show_at']);
            if ($show_at && time() < $show_at) {
                return; // Not time to show yet
            }
        }

        // Output custom CSS — re-sanitised at render (defence in depth). CSS is
        // untrusted Hub input shown on every public pageview; sanitize_banner_css
        // neutralises tag breakout, @import, url() exfiltration and JS-in-CSS.
        if (!empty($banner['css'])) {
            $safe_css = self::sanitize_banner_css((string) $banner['css']);
            if ($safe_css !== '') {
                echo '<style id="peanut-event-banner-custom-css">' . $safe_css . '</style>' . "\n";
            }
        }

        // Output banner HTML through the strict banner allowlist (re-applied at
        // render, not just at save).
        echo self::sanitize_banner_html((string) $banner['html']) . "\n";

        // Add body class. position is constrained to a known set and JSON-encoded
        // for the JS string context — never interpolate raw values into <script>.
        $position = self::sanitize_position($banner['position'] ?? 'top');
        echo '<script>document.body.classList.add(' . wp_json_encode('has-peanut-banner-' . $position) . ');</script>' . "\n";
    }

    /**
     * Enqueue banner assets
     */
    public static function enqueue_assets(): void {
        // Only enqueue if banner is active
        $banner = self::get_active_banner();

        if (!$banner) {
            return;
        }

        // Enqueue CSS
        wp_enqueue_style(
            'peanut-event-banner',
            plugins_url('assets/css/event-banner.css', dirname(__FILE__)),
            [],
            PEANUT_CONNECT_VERSION
        );

        // Enqueue JS
        wp_enqueue_script(
            'peanut-event-banner',
            plugins_url('assets/js/event-banner.js', dirname(__FILE__)),
            [],
            PEANUT_CONNECT_VERSION,
            true
        );

        // Pass banner data to JS
        wp_localize_script('peanut-event-banner', 'peanutEventBanner', [
            'deploymentId' => $banner['deployment_id'],
            'position' => $banner['position'],
            'hideAt' => $banner['hide_at'],
        ]);
    }

    /**
     * Check for expired banners and auto-hide
     */
    public static function check_banner_expiry(): void {
        $banner = get_option(self::OPTION_KEY);

        if (empty($banner) || !is_array($banner)) {
            return;
        }

        if (!empty($banner['hide_at'])) {
            $hide_at = strtotime($banner['hide_at']);
            if ($hide_at && time() > $hide_at) {
                self::clear_banner();
            }
        }
    }

    /**
     * Get banner status for Hub
     */
    public static function get_status(): array {
        $banner = get_option(self::OPTION_KEY);

        return [
            'active' => !empty($banner) && !empty($banner['active']),
            'deployment_id' => $banner['deployment_id'] ?? null,
            'event_id' => $banner['event_id'] ?? null,
            'position' => $banner['position'] ?? null,
            'show_at' => $banner['show_at'] ?? null,
            'hide_at' => $banner['hide_at'] ?? null,
            'received_at' => $banner['received_at'] ?? null,
            'is_test' => $banner['is_test'] ?? false,
            'connect_version' => PEANUT_CONNECT_VERSION,
        ];
    }

    /**
     * Get diagnostics for API tester
     */
    public static function get_diagnostics(): array {
        $banner = get_option(self::OPTION_KEY);

        return [
            'module_active' => true,
            'option_key' => self::OPTION_KEY,
            'current_banner' => $banner,
            'assets' => [
                'css_exists' => file_exists(PEANUT_CONNECT_PLUGIN_DIR . 'assets/css/event-banner.css'),
                'js_exists' => file_exists(PEANUT_CONNECT_PLUGIN_DIR . 'assets/js/event-banner.js'),
            ],
            'endpoints' => [
                'show' => '/wp-json/peanut-connect/v1/banner/show',
                'hide' => '/wp-json/peanut-connect/v1/banner/hide',
                'status' => '/wp-json/peanut-connect/v1/banner/status',
                'diagnostics' => '/wp-json/peanut-connect/v1/banner/diagnostics',
            ],
            'server_time' => current_time('mysql'),
            'timezone' => wp_timezone_string(),
        ];
    }

    /**
     * Constrain the banner position to a known set. Prevents injection via the
     * position value, which is emitted into markup and an inline script.
     *
     * @param mixed $position Raw position value.
     * @return string 'top' or 'bottom'.
     */
    private static function sanitize_position($position): string {
        $position = is_string($position) ? strtolower(trim($position)) : '';
        return in_array($position, ['top', 'bottom'], true) ? $position : 'top';
    }

    /**
     * Sanitise Hub-supplied banner HTML to a small banner-appropriate allowlist.
     *
     * Stricter than wp_kses_post: no script/iframe/form/object/style tags, no
     * event-handler or inline-style attributes, and link/image schemes limited
     * to http(s)/mailto. The banner renders on every public pageview, so the
     * markup is treated as fully untrusted (a leaked Hub bearer must not be able
     * to inject active or phishing content site-wide).
     *
     * @param string $html Raw HTML from Hub.
     * @return string Sanitised HTML.
     */
    public static function sanitize_banner_html(string $html): string {
        $allowed = [
            'a'      => ['href' => true, 'title' => true, 'class' => true, 'rel' => true, 'target' => true],
            'span'   => ['class' => true],
            'div'    => ['class' => true],
            'p'      => ['class' => true],
            'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'u' => [], 'br' => [], 'small' => [],
            'button' => ['class' => true, 'type' => true],
            'img'    => ['src' => true, 'alt' => true, 'class' => true, 'width' => true, 'height' => true],
        ];
        return wp_kses($html, $allowed, ['http', 'https', 'mailto']);
    }

    /**
     * Sanitise Hub-supplied banner CSS. CSS cannot run JS in modern browsers,
     * but raw CSS rendered site-wide enables data exfiltration (url()),
     * clickjacking overlays, and <style> tag breakout. Neutralise each:
     *  - strip <,> (no tag breakout, supersedes the old </style>-only strip)
     *  - drop @import, expression(), javascript:/vbscript:, -moz-binding, behavior:
     *  - drop url() unless it is an inline data:image (kills exfil beacons)
     *
     * @param string $css Raw CSS from Hub.
     * @return string Sanitised CSS.
     */
    public static function sanitize_banner_css(string $css): string {
        $css = preg_replace('#[<>]#', '', $css);
        $css = preg_replace('#@import\b#i', '', (string) $css);
        $css = preg_replace('#expression\s*\(#i', '', (string) $css);
        $css = preg_replace('#(?:javascript|vbscript)\s*:#i', '', (string) $css);
        $css = preg_replace('#-moz-binding\b#i', '', (string) $css);
        $css = preg_replace('#behaviou?r\s*:#i', '', (string) $css);
        $css = preg_replace_callback('#url\(\s*([\'"]?)(.*?)\1\s*\)#is', static function ($m) {
            return preg_match('#^\s*data:image/#i', (string) $m[2]) ? $m[0] : '';
        }, (string) $css);
        return trim((string) $css);
    }

    /**
     * Send acknowledgment to Hub
     */
    private static function send_acknowledgment(int $deployment_id, string $type): void {
        if (!$deployment_id) {
            return;
        }

        $hub_url = get_option('peanut_connect_hub_url');
        $api_key = get_option('peanut_connect_hub_api_key');

        if (empty($hub_url) || empty($api_key)) {
            return;
        }

        $endpoint = rtrim($hub_url, '/') . '/api/ptr/banner/acknowledge';

        // Send async if WP HTTP API supports it
        wp_remote_post($endpoint, [
            'timeout' => 5,
            'blocking' => false,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => wp_json_encode([
                'deployment_id' => $deployment_id,
                'type' => $type, // 'show' or 'hide'
                'site_url' => get_site_url(),
                'timestamp' => current_time('c'),
            ]),
        ]);
    }
}
