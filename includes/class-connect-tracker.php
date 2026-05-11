<?php
/**
 * Peanut Connect Tracker
 *
 * Handles visitor identification, pageview tracking, and event recording.
 *
 * @package Peanut_Connect
 * @since 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tracker class for visitor and event tracking
 */
class Peanut_Connect_Tracker {

    /**
     * Visitor ID cookie name
     */
    const COOKIE_NAME = 'peanut_vid';

    /**
     * Hub click ID cookie name (for journey tracking)
     */
    const CLICK_ID_COOKIE = 'peanut_click_id';

    /**
     * Click ID cookie expiry (24 hours - journeys are typically short-lived)
     */
    const CLICK_ID_EXPIRY = 86400;

    /**
     * Cookie expiry (1 year)
     */
    const COOKIE_EXPIRY = 31536000;

    /**
     * Current visitor ID
     */
    private static ?string $visitor_id = null;

    /**
     * Current Hub click ID (for journey tracking)
     */
    private static ?string $click_id = null;

    /**
     * Initialize tracker
     */
    public static function init(): void {
        // Only track on frontend
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || defined('REST_REQUEST')) {
            return;
        }

        // Check if tracking is enabled
        if (!self::is_tracking_enabled()) {
            return;
        }

        add_action('wp', [__CLASS__, 'track_pageview'], 20);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_tracking_script']);
    }

    /**
     * Check if tracking is enabled
     */
    public static function is_tracking_enabled(): bool {
        $hub_url = get_option('peanut_connect_hub_url');
        $api_key = get_option('peanut_connect_hub_api_key');
        $tracking_enabled = get_option('peanut_connect_tracking_enabled', false);

        return !empty($hub_url) && !empty($api_key) && $tracking_enabled;
    }

    /**
     * Get or create visitor ID
     */
    public static function get_visitor_id(): string {
        if (self::$visitor_id !== null) {
            return self::$visitor_id;
        }

        // Check cookie
        if (isset($_COOKIE[self::COOKIE_NAME])) {
            self::$visitor_id = sanitize_text_field($_COOKIE[self::COOKIE_NAME]);
            return self::$visitor_id;
        }

        // Generate new visitor ID
        self::$visitor_id = self::generate_visitor_id();

        // Set cookie (done via JavaScript for better compatibility)
        return self::$visitor_id;
    }

    /**
     * Generate unique visitor ID
     */
    private static function generate_visitor_id(): string {
        return bin2hex(random_bytes(16)); // 32 character hex string
    }

    /**
     * Track a pageview
     */
    public static function track_pageview(): void {
        // Don't track admin users if option is set
        if (is_user_logged_in() && !get_option('peanut_connect_track_logged_in', false)) {
            return;
        }

        // Don't track bots
        if (self::is_bot()) {
            return;
        }

        $visitor_id = self::get_visitor_id();

        // Update or create visitor
        self::update_visitor($visitor_id);

        // Record pageview event
        self::record_event($visitor_id, 'pageview', [
            'page_url' => self::get_current_url(),
            'page_title' => wp_get_document_title(),
            'referrer' => esc_url_raw((string) (wp_get_referer() ?: ($_SERVER['HTTP_REFERER'] ?? ''))),
        ]);

        // Check for UTM parameters and create attribution touch
        $utm = self::get_utm_params();
        if (!empty($utm['source']) || !empty($utm['medium']) || !empty($utm['campaign'])) {
            self::record_touch($visitor_id, $utm);
        }
    }

    /**
     * Update or create visitor record
     */
    public static function update_visitor(string $visitor_id, array $data = []): void {
        global $wpdb;

        $table = Peanut_Connect_Database::table('visitors');
        $now = current_time('mysql', true);

        // Check if visitor exists
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, total_visits, total_pageviews FROM $table WHERE visitor_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $visitor_id
            )
        );

        if ($existing) {
            // Update existing visitor
            $wpdb->update(
                $table,
                [
                    'last_seen_at' => $now,
                    'total_pageviews' => $existing->total_pageviews + 1,
                    'email' => $data['email'] ?? null,
                    'name' => $data['name'] ?? null,
                    'synced' => 0, // Mark for re-sync
                ],
                ['id' => $existing->id],
                ['%s', '%d', '%s', '%s', '%d'],
                ['%d']
            );
        } else {
            // Create new visitor
            $device_info = self::get_device_info();

            $wpdb->insert(
                $table,
                [
                    'visitor_id' => $visitor_id,
                    'email' => $data['email'] ?? null,
                    'name' => $data['name'] ?? null,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'total_visits' => 1,
                    'total_pageviews' => 1,
                    'device_type' => $device_info['device_type'],
                    'browser' => $device_info['browser'],
                    'os' => $device_info['os'],
                    'country' => self::get_country_code(),
                    'synced' => 0,
                ],
                ['%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d']
            );
        }
    }

    /**
     * Record an event
     */
    public static function record_event(string $visitor_id, string $event_type, array $data = []): int {
        global $wpdb;

        $table = Peanut_Connect_Database::table('events');
        $utm = self::get_utm_params();
        $click_id = $data['click_id'] ?? self::get_click_id();

        $wpdb->insert(
            $table,
            [
                'visitor_id' => $visitor_id,
                'event_type' => $event_type,
                'page_url' => $data['page_url'] ?? self::get_current_url(),
                'page_title' => $data['page_title'] ?? null,
                'referrer' => $data['referrer'] ?? null,
                'utm_source' => $utm['source'] ?? null,
                'utm_medium' => $utm['medium'] ?? null,
                'utm_campaign' => $utm['campaign'] ?? null,
                'utm_term' => $utm['term'] ?? null,
                'utm_content' => $utm['content'] ?? null,
                'click_id' => $click_id,
                'metadata' => isset($data['metadata']) ? wp_json_encode($data['metadata']) : null,
                'occurred_at' => current_time('mysql', true),
                'synced' => 0,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Record an attribution touch
     */
    public static function record_touch(string $visitor_id, array $utm): int {
        global $wpdb;

        $table = Peanut_Connect_Database::table('touches');

        // Get touch position (count previous touches + 1)
        $position = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) + 1 FROM $table WHERE visitor_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $visitor_id
            )
        );

        // Determine channel
        $referer = esc_url_raw((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        $channel = self::determine_channel(
            $utm['source'] ?? null,
            $utm['medium'] ?? null,
            $referer !== '' ? $referer : null
        );

        $wpdb->insert(
            $table,
            [
                'visitor_id' => $visitor_id,
                'channel' => $channel,
                'source' => $utm['source'] ?? null,
                'medium' => $utm['medium'] ?? null,
                'campaign' => $utm['campaign'] ?? null,
                'landing_page' => self::get_current_url(),
                'referrer' => $referer !== '' ? $referer : null,
                'touch_position' => $position,
                'touched_at' => current_time('mysql', true),
                'synced' => 0,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Record a conversion
     */
    public static function record_conversion(string $visitor_id, string $type, array $data = []): int {
        global $wpdb;

        $table = Peanut_Connect_Database::table('conversions');

        $wpdb->insert(
            $table,
            [
                'visitor_id' => $visitor_id,
                'type' => $type,
                'value' => $data['value'] ?? null,
                'currency' => $data['currency'] ?? 'USD',
                'customer_email' => $data['email'] ?? null,
                'customer_name' => $data['name'] ?? null,
                'order_id' => $data['order_id'] ?? null,
                'metadata' => isset($data['metadata']) ? wp_json_encode($data['metadata']) : null,
                'converted_at' => current_time('mysql', true),
                'synced' => 0,
            ],
            ['%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
        );

        // Update visitor with email if provided
        if (!empty($data['email'])) {
            self::identify_visitor($visitor_id, $data['email'], $data['name'] ?? null);
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Identify a visitor (attach email/name)
     */
    public static function identify_visitor(string $visitor_id, string $email, ?string $name = null): void {
        global $wpdb;

        $table = Peanut_Connect_Database::table('visitors');

        $wpdb->update(
            $table,
            [
                'email' => sanitize_email($email),
                'name' => $name ? sanitize_text_field($name) : null,
                'synced' => 0,
            ],
            ['visitor_id' => $visitor_id],
            ['%s', '%s', '%d'],
            ['%s']
        );
    }

    /**
     * Record popup interaction
     */
    public static function record_popup_interaction(int $popup_id, string $action, ?string $visitor_id = null, array $data = []): int {
        global $wpdb;

        $table = Peanut_Connect_Database::table('popup_interactions');

        $wpdb->insert(
            $table,
            [
                'popup_id' => $popup_id,
                'visitor_id' => $visitor_id ?: self::get_visitor_id(),
                'action' => $action,
                'page_url' => $data['page_url'] ?? self::get_current_url(),
                'data' => isset($data['form_data']) ? wp_json_encode($data['form_data']) : null,
                'occurred_at' => current_time('mysql', true),
                'synced' => 0,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%d']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Get UTM parameters from URL
     */
    public static function get_utm_params(): array {
        return [
            'source' => isset($_GET['utm_source']) ? sanitize_text_field($_GET['utm_source']) : null,
            'medium' => isset($_GET['utm_medium']) ? sanitize_text_field($_GET['utm_medium']) : null,
            'campaign' => isset($_GET['utm_campaign']) ? sanitize_text_field($_GET['utm_campaign']) : null,
            'term' => isset($_GET['utm_term']) ? sanitize_text_field($_GET['utm_term']) : null,
            'content' => isset($_GET['utm_content']) ? sanitize_text_field($_GET['utm_content']) : null,
        ];
    }

    /**
     * Get Hub click ID from URL or cookie (for journey tracking)
     *
     * When a visitor arrives via a Hub short link, the click_id parameter
     * is appended to the URL. We capture it and store it in a cookie so
     * subsequent pageviews can be linked to the same journey.
     *
     * @return string|null The click ID if present
     */
    public static function get_click_id(): ?string {
        if (self::$click_id !== null) {
            return self::$click_id;
        }

        // Check URL first (new visit from Hub short link)
        if (isset($_GET['click_id'])) {
            $click_id = sanitize_text_field($_GET['click_id']);
            // Validate it looks like a UUID
            if (preg_match('/^[a-f0-9\-]{36}$/i', $click_id)) {
                self::$click_id = $click_id;
                // Cookie will be set via JavaScript for better compatibility
                return self::$click_id;
            }
        }

        // Check cookie (subsequent pageviews in same journey)
        if (isset($_COOKIE[self::CLICK_ID_COOKIE])) {
            $click_id = sanitize_text_field($_COOKIE[self::CLICK_ID_COOKIE]);
            if (preg_match('/^[a-f0-9\-]{36}$/i', $click_id)) {
                self::$click_id = $click_id;
                return self::$click_id;
            }
        }

        return null;
    }

    /**
     * Determine channel from UTM/referrer
     */
    public static function determine_channel(?string $source, ?string $medium, ?string $referrer): string {
        // Direct traffic
        if (empty($referrer) && empty($source)) {
            return 'direct';
        }

        // Paid traffic
        if (in_array($medium, ['cpc', 'ppc', 'paid', 'paidsocial', 'paid_social'], true)) {
            return 'paid';
        }

        // Email
        if ($medium === 'email' || $source === 'email') {
            return 'email';
        }

        // Social
        $social_domains = ['facebook.com', 'twitter.com', 'linkedin.com', 'instagram.com', 'pinterest.com', 'tiktok.com', 't.co', 'fb.me'];
        if ($medium === 'social') {
            return 'social';
        }
        if ($referrer) {
            foreach ($social_domains as $domain) {
                if (stripos($referrer, $domain) !== false) {
                    return 'social';
                }
            }
        }

        // Organic search
        $search_domains = ['google.', 'bing.com', 'yahoo.com', 'duckduckgo.com', 'baidu.com', 'yandex.'];
        if ($medium === 'organic') {
            return 'organic';
        }
        if ($referrer) {
            foreach ($search_domains as $domain) {
                if (stripos($referrer, $domain) !== false) {
                    return 'organic';
                }
            }
        }

        // Referral
        if (!empty($referrer)) {
            return 'referral';
        }

        return 'other';
    }

    /**
     * Get device info from user agent
     */
    public static function get_device_info(): array {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Device type
        $device_type = 'desktop';
        if (preg_match('/Mobile|Android|iPhone|iPad|iPod/i', $ua)) {
            $device_type = preg_match('/iPad|Tablet/i', $ua) ? 'tablet' : 'mobile';
        }

        // Browser
        $browser = 'Unknown';
        if (preg_match('/Chrome\/[\d.]+/i', $ua) && !preg_match('/Edg/i', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Safari\/[\d.]+/i', $ua) && !preg_match('/Chrome/i', $ua)) {
            $browser = 'Safari';
        } elseif (preg_match('/Firefox\/[\d.]+/i', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Edg\/[\d.]+/i', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/MSIE|Trident/i', $ua)) {
            $browser = 'IE';
        }

        // OS
        $os = 'Unknown';
        if (preg_match('/Windows NT/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/Mac OS X/i', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/Linux/i', $ua)) {
            $os = 'Linux';
        } elseif (preg_match('/Android/i', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/iOS|iPhone|iPad/i', $ua)) {
            $os = 'iOS';
        }

        return [
            'device_type' => $device_type,
            'browser' => $browser,
            'os' => $os,
        ];
    }

    /**
     * Get country code from IP (basic implementation)
     */
    public static function get_country_code(): ?string {
        // This is a placeholder - in production, you'd use a geo-IP service
        // or the hub could determine this from the IP
        return null;
    }

    /**
     * Check if request is from a bot
     */
    public static function is_bot(): bool {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $bot_patterns = [
            'bot', 'crawl', 'spider', 'slurp', 'facebook', 'twitter',
            'linkedin', 'pinterest', 'whatsapp', 'telegram', 'preview',
            'headless', 'phantom', 'selenium', 'puppeteer',
        ];

        $ua_lower = strtolower($ua);
        foreach ($bot_patterns as $pattern) {
            if (strpos($ua_lower, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get current URL
     */
    public static function get_current_url(): string {
        $protocol = is_ssl() ? 'https://' : 'http://';
        return $protocol . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
    }

    /**
     * Enqueue frontend tracking script
     */
    public static function enqueue_tracking_script(): void {
        wp_enqueue_script(
            'peanut-connect-tracker',
            plugins_url('assets/js/tracker.js', dirname(__FILE__)),
            [],
            PEANUT_CONNECT_VERSION,
            true
        );

        // Pass config to script
        wp_localize_script('peanut-connect-tracker', 'peanutConnectTracker', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('peanut-connect/v1'),
            'nonce' => wp_create_nonce('peanut_connect_track'),
            'visitorId' => self::get_visitor_id(),
            'cookieName' => self::COOKIE_NAME,
            'cookieExpiry' => self::COOKIE_EXPIRY,
            // Hub journey tracking
            'clickId' => self::get_click_id(),
            'clickIdCookie' => self::CLICK_ID_COOKIE,
            'clickIdExpiry' => self::CLICK_ID_EXPIRY,
        ]);
    }
}
