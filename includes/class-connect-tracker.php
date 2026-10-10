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
     * Idle time after which the next pageview counts as a new visit.
     *
     * 30 minutes is the long-standing web-analytics session convention, so
     * "visits" here means the same thing it means in every other tool the
     * client is likely comparing against.
     */
    const SESSION_GAP = 1800;

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
        $api_key = Peanut_Connect_Auth::get_hub_api_key();
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

        // Check cookie (32 hex characters, written by tracker.js)
        if (isset($_COOKIE[self::COOKIE_NAME]) && is_string($_COOKIE[self::COOKIE_NAME])
            && preg_match('/^[a-f0-9]{32}$/i', $_COOKIE[self::COOKIE_NAME])) {
            self::$visitor_id = strtolower($_COOKIE[self::COOKIE_NAME]);
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

        // Same reasoning as record_event(): the visitors table is the other
        // thing the public REST routes write to, and it is where the bogus
        // rows actually accumulated -- 690,977 of 1.37M on one site came from
        // user-agents that matched nothing at all.
        if (self::is_bot()) {
            return;
        }

        $table = Peanut_Connect_Database::table('visitors');
        $now = current_time('mysql', true);

        // Check if visitor exists
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, total_visits, total_pageviews, last_seen_at, email, name FROM $table WHERE visitor_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $visitor_id
            )
        );

        if ($existing) {
            $update = [
                'last_seen_at' => $now,
                'total_pageviews' => $existing->total_pageviews + 1,
                'synced' => 0, // Mark for re-sync
            ];
            $formats = ['%s', '%d', '%d'];

            // A new visit is a return after the session gap, which is what
            // makes the column mean anything. total_visits used to be written
            // as 1 on insert and then never appear in this update at all, so
            // it was permanently 1 for every row ever created -- 1,368,120 of
            // 1,368,120 on nattybumpercar.com, max 1 -- and any "visits"
            // figure was really just a count of visitor rows.
            $last_seen = strtotime((string) $existing->last_seen_at);
            if ($last_seen === false || (strtotime($now) - $last_seen) >= self::SESSION_GAP) {
                $update['total_visits'] = ((int) $existing->total_visits) + 1;
                $formats[] = '%d';
            }

            // Only ever fill identification in, never blank it out. This was
            // `'email' => $data['email'] ?? null` on every pageview, so one
            // ordinary anonymous hit after a form submission wiped the
            // captured email and name back to NULL.
            if (!empty($data['email'])) {
                $update['email'] = $data['email'];
                $formats[] = '%s';
            }
            if (!empty($data['name'])) {
                $update['name'] = $data['name'];
                $formats[] = '%s';
            }

            $wpdb->update($table, $update, ['id' => $existing->id], $formats, ['%d']);
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
     *
     * @param string $visitor_id Visitor identifier.
     * @param string $event_type Event type — pageview, click, custom, etc.
     * @param array  $data       Optional payload. Recognized keys:
     *   - event_name: distinguishes one custom event from another
     *                  (e.g. `click_to_portal` vs `video_play`). Required
     *                  for Hub funnel classification on `event_type=custom`
     *                  rows; ignored for canonical types like `pageview`.
     *   - page_url, page_title, referrer
     *   - click_id (falls back to URL ?click_id= param)
     *   - metadata: arbitrary JSON-serializable hash
     *
     * Canonicalizes `page_view` (camelCase variant historically emitted
     * by older tracker.js) to `pageview` (snake-case, the schema's
     * canonical form). The dual emission was splitting funnel counts.
     */
    public static function record_event(string $visitor_id, string $event_type, array $data = []): int {
        global $wpdb;

        // Guard the write, not the entry point.
        //
        // is_bot() used to be called in exactly one place -- track_pageview()
        // -- so the server-side path filtered bots while every REST tracking
        // route (all `permission_callback => __return_true`, since the browser
        // tracker posts unauthenticated) reached this method directly and
        // filtered nothing.
        //
        // Checking here instead of in the REST prechecks covers all of them at
        // once and, importantly, does not preempt request validation: an
        // oversized or malformed payload still earns its 400/413 rather than a
        // silent 200. An earlier attempt at the precheck did preempt it, and
        // the public-endpoint hardening tests caught it.
        if (self::is_bot()) {
            return 0;
        }

        // Canonicalize event_type aliases.
        if ($event_type === 'page_view') {
            $event_type = 'pageview';
        }

        $event_name = isset($data['event_name']) && $data['event_name'] !== ''
            ? substr((string) $data['event_name'], 0, 64)
            : null;

        $table = Peanut_Connect_Database::table('events');
        $utm = self::get_utm_params();
        // A click id from the request body (POST /track) is caller input: keep
        // it only when it is a well-formed UUID, otherwise fall back to the
        // validated URL/cookie value. Junk ids used to be stored and shipped
        // to Hub (the sync ships every row WHERE click_id IS NOT NULL).
        $click_id = self::is_valid_click_id($data['click_id'] ?? null)
            ? strtolower((string) $data['click_id'])
            : self::get_click_id();

        $wpdb->insert(
            $table,
            [
                'visitor_id' => $visitor_id,
                'event_type' => $event_type,
                'event_name' => $event_name,
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
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
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
     *
     * Fill-only. The public /identify and /conversion routes reach this with
     * whatever visitor_id the caller names, so an overwrite let anyone who
     * knew (or, from a cached page, was handed) another visitor's id replace
     * that visitor's identity. An email is written only when the visitor has
     * none; a name only when the visitor has none and the email on record is
     * this one, so a second identity never mixes into the first.
     */
    public static function identify_visitor(string $visitor_id, string $email, ?string $name = null): void {
        global $wpdb;

        $email = sanitize_email($email);
        if ($email === '' || $visitor_id === '') {
            return;
        }

        $table = Peanut_Connect_Database::table('visitors');

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table SET email = %s, synced = 0 WHERE visitor_id = %s AND (email IS NULL OR email = '')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $email,
                $visitor_id
            )
        );

        $name = $name ? sanitize_text_field($name) : '';
        if ($name !== '') {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $table SET name = %s, synced = 0 WHERE visitor_id = %s AND email = %s AND (name IS NULL OR name = '')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $name,
                    $visitor_id,
                    $email
                )
            );
        }
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
            // Validate it is a UUID (Hub issues and validates click ids as UUIDs)
            if (self::is_valid_click_id($click_id)) {
                self::$click_id = $click_id;
                // Cookie will be set via JavaScript for better compatibility
                return self::$click_id;
            }
        }

        // Check cookie (subsequent pageviews in same journey). Two cookie
        // names are checked: this plugin's own (CLICK_ID_COOKIE, default
        // `peanut_click_id`) and Hub's separate tracker.min.js cookie
        // `_pnut_cid`. Hub's tracker writes _pnut_cid on every click-
        // through but historically this plugin only read its own cookie —
        // so on sites where tracker.min.js set the cookie before this
        // plugin's tracker.js had a chance to, server-side click_id
        // recovery silently failed.
        $cookie_names = [self::CLICK_ID_COOKIE, '_pnut_cid'];
        foreach ($cookie_names as $name) {
            if (isset($_COOKIE[$name])) {
                $click_id = sanitize_text_field($_COOKIE[$name]);
                if (self::is_valid_click_id($click_id)) {
                    self::$click_id = $click_id;
                    return self::$click_id;
                }
            }
        }

        return null;
    }

    /**
     * Whether a value is a Hub click id: a canonical 8-4-4-4-12 hex UUID,
     * the same shape Hub's journey API validates (Laravel 'uuid'). The old
     * check accepted any 36 characters of hex and dashes.
     */
    public static function is_valid_click_id($click_id): bool {
        return is_string($click_id)
            && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $click_id);
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
        $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

        // No user-agent at all is not a browser. This was the biggest hole:
        // an empty string contains none of the patterns below, so it fell
        // through and was recorded -- and because get_device_info() defaults
        // to desktop/Unknown, it was stored as a desktop visitor. On
        // nattybumpercar.com 690,977 rows (~50% of the table) had browser,
        // OS *and* device all unresolved, which is exactly that signature.
        if ($ua === '') {
            return true;
        }

        $ua_lower = strtolower($ua);

        $bot_patterns = [
            'bot', 'crawl', 'spider', 'slurp', 'facebook', 'twitter',
            'linkedin', 'pinterest', 'whatsapp', 'telegram', 'preview',
            'headless', 'phantom', 'selenium', 'puppeteer',
            // Plain HTTP clients and common SEO/monitoring agents. None of
            // these are a person looking at the page.
            'curl/', 'wget', 'python-requests', 'python-urllib', 'go-http-client',
            'java/', 'okhttp', 'axios', 'guzzle', 'libwww-perl', 'scrapy',
            'ahrefs', 'semrush', 'mj12', 'dotbot', 'petalsearch', 'yandex',
            'baidu', 'duckduck', 'pingdom', 'uptimerobot', 'lighthouse',
            'gtmetrix', 'statuscake', 'newrelic', 'datadog',
        ];

        foreach ($bot_patterns as $pattern) {
            if (strpos($ua_lower, $pattern) !== false) {
                return true;
            }
        }

        // Positive check, deliberately last: essentially every real browser
        // sends a UA beginning "Mozilla/" -- a convention every engine kept
        // for compatibility -- while scripted HTTP clients generally do not.
        // Requiring it catches the long tail of one-off scrapers that no
        // blocklist will ever enumerate.
        //
        // Note this is a check on the UA *shape*, not on whether we could
        // parse a browser name out of it: a niche or new browser still says
        // Mozilla/5.0 and is still tracked, it just reports as "Unknown".
        if (strpos($ua_lower, 'mozilla/') === false) {
            return true;
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

        // Pass config to script. Only site-wide values: this lands in the
        // page HTML, which page caches serve to every later visitor. The
        // visitor id and click id used to be printed here, so with a page
        // cache the first visitor's ids were handed to everyone after them
        // (all attached to one journey, and /identify on any of them
        // relabelled that visitor). tracker.js now reads both from its own
        // cookies / the URL and mints a visitor id in the browser.
        wp_localize_script('peanut-connect-tracker', 'peanutConnectTracker', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('peanut-connect/v1'),
            'nonce' => wp_create_nonce('peanut_connect_track'),
            'cookieName' => self::COOKIE_NAME,
            'cookieExpiry' => self::COOKIE_EXPIRY,
            // Hub journey tracking
            'clickIdCookie' => self::CLICK_ID_COOKIE,
            'clickIdExpiry' => self::CLICK_ID_EXPIRY,
        ]);
    }
}
