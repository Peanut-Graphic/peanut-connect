<?php
/**
 * Peanut Connect Popup Display
 *
 * Handles displaying popups from Peanut Hub on client sites.
 *
 * @package Peanut_Connect
 * @since 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Popup display class
 */
class Peanut_Connect_Popup_Display {

    /**
     * Initialize popup display
     */
    public static function init(): void {
        // Only display on frontend
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || defined('REST_REQUEST')) {
            return;
        }

        // Check if hub is connected
        if (!self::is_connected()) {
            return;
        }

        add_action('wp_footer', [__CLASS__, 'render_popups']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_popup_assets']);
    }

    /**
     * Check if hub is connected
     */
    public static function is_connected(): bool {
        $hub_url = get_option('peanut_connect_hub_url');
        $api_key = Peanut_Connect_Auth::get_hub_api_key();
        return !empty($hub_url) && !empty($api_key);
    }

    /**
     * Short-lived negative cache so an empty popup option can't turn the
     * render path into a repeated blocking Hub fetch. The heartbeat is
     * the authoritative populator of `peanut_connect_hub_popups`; the
     * render path only ever READS it.
     */
    const EMPTY_POPUPS_TRANSIENT = 'peanut_connect_popups_empty';
    const EMPTY_POPUPS_TTL = 300; // 5 minutes

    /**
     * Get active popups.
     *
     * This is called from the render path (wp_footer / wp_enqueue_scripts),
     * so it must NEVER make a blocking outbound request. Before 3.x it
     * fell through to a synchronous wp_remote_get (10s) to the Hub whenever
     * the cached option was empty, and an empty/failed result wasn't
     * cached — so every pageview re-attempted the blocking call. When the
     * Hub was down, that turned each visitor request into a 10s hang: a
     * cascade amplifier exactly during the outage window.
     *
     * Now the option (populated by the heartbeat) is the single source of
     * truth, and an empty result is negative-cached briefly so we don't
     * even re-read/re-filter pointlessly on every request.
     */
    public static function get_active_popups(): array {
        // Negative cache: if we recently saw no popups, skip the work.
        if (get_transient(self::EMPTY_POPUPS_TRANSIENT)) {
            return [];
        }

        // Get cached popups from last heartbeat. The render path NEVER
        // synchronously fetches from the Hub — that's the heartbeat's job.
        $popups = get_option('peanut_connect_hub_popups', []);

        if (empty($popups) || !is_array($popups)) {
            set_transient(self::EMPTY_POPUPS_TRANSIENT, 1, self::EMPTY_POPUPS_TTL);
            return [];
        }

        // Filter by targeting rules
        return array_filter($popups, [__CLASS__, 'matches_targeting']);
    }

    /**
     * Check if popup matches targeting rules
     */
    private static function matches_targeting(array $popup): bool {
        $targeting = $popup['targeting'] ?? [];

        // Page targeting
        if (!empty($targeting['pages'])) {
            $current_url = Peanut_Connect_Tracker::get_current_url();
            $matches_page = false;

            foreach ($targeting['pages'] as $page) {
                if ($page['type'] === 'contains' && strpos($current_url, $page['value']) !== false) {
                    $matches_page = true;
                    break;
                }
                if ($page['type'] === 'exact' && $current_url === $page['value']) {
                    $matches_page = true;
                    break;
                }
                if ($page['type'] === 'regex' && preg_match($page['value'], $current_url)) {
                    $matches_page = true;
                    break;
                }
            }

            // Check if it's an exclude rule
            $exclude = $targeting['pages_exclude'] ?? false;
            if ($exclude && $matches_page) {
                return false;
            }
            if (!$exclude && !$matches_page) {
                return false;
            }
        }

        // Device targeting
        if (!empty($targeting['devices'])) {
            $device_info = Peanut_Connect_Tracker::get_device_info();
            if (!in_array($device_info['device_type'], $targeting['devices'], true)) {
                return false;
            }
        }

        // Logged-in targeting
        if (isset($targeting['logged_in'])) {
            $is_logged_in = is_user_logged_in();
            if ($targeting['logged_in'] === 'only' && !$is_logged_in) {
                return false;
            }
            if ($targeting['logged_in'] === 'exclude' && $is_logged_in) {
                return false;
            }
        }

        return true;
    }

    /**
     * Enqueue popup assets
     */
    public static function enqueue_popup_assets(): void {
        $popups = self::get_active_popups();

        if (empty($popups)) {
            return;
        }

        wp_enqueue_style(
            'peanut-connect-popups',
            plugins_url('assets/css/popups.css', dirname(__FILE__)),
            [],
            PEANUT_CONNECT_VERSION
        );

        wp_enqueue_script(
            'peanut-connect-popups',
            plugins_url('assets/js/popups.js', dirname(__FILE__)),
            [],
            PEANUT_CONNECT_VERSION,
            true
        );

        wp_localize_script('peanut-connect-popups', 'peanutConnectPopups', [
            'popups' => $popups,
            'restUrl' => rest_url('peanut-connect/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'visitorId' => Peanut_Connect_Tracker::get_visitor_id(),
        ]);
    }

    /**
     * Render popup containers
     */
    public static function render_popups(): void {
        $popups = self::get_active_popups();

        if (empty($popups)) {
            return;
        }

        echo '<div id="peanut-connect-popups-container"></div>';
    }
}
