<?php
/**
 * Peanut Connect Self-Updater
 *
 * Handles self-hosted plugin updates from peanutgraphic.com
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Connect_Self_Updater {

    /**
     * Update server API URL - uses path parameter instead of query string
     * due to server configuration stripping query parameters
     */
    private const API_URL = 'https://www.peanutgraphic.com/wp-json/peanut-api/v1/updates/peanut-connect';

    /**
     * Plugin slug
     */
    private const PLUGIN_SLUG = 'peanut-connect';

    /**
     * Hosts the update package is allowed to be served from. A compromised or
     * spoofed update-server response that points `download_url` anywhere else
     * is rejected before it can be handed to WordPress's installer — otherwise
     * a single poisoned response is remote code execution on every paired site.
     */
    private const TRUSTED_PACKAGE_HOSTS = [
        'peanutgraphic.com',
        'www.peanutgraphic.com',
        'github.com',
        'codeload.github.com',
        'objects.githubusercontent.com',
    ];

    /**
     * Plugin file path
     */
    private string $plugin_file;

    /**
     * Cached update info
     */
    private ?object $update_info = null;

    /**
     * Constructor
     */
    public function __construct() {
        $this->plugin_file = 'peanut-connect/peanut-connect.php';
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        // Check for updates
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);

        // Plugin information popup
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
    }

    /**
     * Check for plugin updates
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // Get current version
        $current_version = $transient->checked[$this->plugin_file] ?? '0.0.0';

        // Get remote update info
        $remote = $this->get_remote_update_info($current_version);

        if ($remote && isset($remote->version)) {
            if (version_compare($remote->version, $current_version, '>')) {
                $transient->response[$this->plugin_file] = (object) [
                    'slug' => self::PLUGIN_SLUG,
                    'plugin' => $this->plugin_file,
                    'new_version' => $remote->version,
                    'package' => $remote->download_url ?? '',
                    'url' => $remote->homepage ?? 'https://peanutgraphic.com/peanut-connect',
                    'tested' => $remote->tested ?? '',
                    'requires_php' => $remote->requires_php ?? '8.0',
                    'requires' => $remote->requires ?? '6.0',
                ];
            } else {
                // No update available
                $transient->no_update[$this->plugin_file] = (object) [
                    'slug' => self::PLUGIN_SLUG,
                    'plugin' => $this->plugin_file,
                    'new_version' => $current_version,
                    'url' => $remote->homepage ?? 'https://peanutgraphic.com/peanut-connect',
                ];
            }
        }

        return $transient;
    }

    /**
     * Plugin information for popup
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || $args->slug !== self::PLUGIN_SLUG) {
            return $result;
        }

        $remote = $this->get_remote_update_info('0.0.0');

        if (!$remote) {
            return $result;
        }

        return (object) [
            'name' => $remote->name ?? 'Peanut End to End',
            'slug' => self::PLUGIN_SLUG,
            'version' => $remote->version ?? '1.0.0',
            'author' => $remote->author ?? '<a href="https://peanutgraphic.com">Peanut Graphic</a>',
            'author_profile' => 'https://peanutgraphic.com',
            'homepage' => $remote->homepage ?? 'https://peanutgraphic.com/peanut-connect',
            'download_link' => $remote->download_url ?? '',
            'trunk' => $remote->download_url ?? '',
            'requires' => $remote->requires ?? '6.0',
            'tested' => $remote->tested ?? '',
            'requires_php' => $remote->requires_php ?? '8.0',
            'sections' => [
                'description' => '<p>Peanut End to End is a campaign + site platform for WordPress — runs marketing campaigns, UTM links, popups, forms, and on-site tracking, plus health monitoring, updates, and backups, all paired to a Peanut Hub.</p>',
                'installation' => '<ol><li>Upload the plugin to your /wp-content/plugins/ directory</li><li>Activate the plugin</li><li>Configure your connection token in Settings</li></ol>',
                'changelog' => '<h4>' . ($remote->version ?? '2.1.1') . '</h4><ul><li>Latest stable release</li></ul>',
            ],
        ];
    }

    /**
     * Get remote update info using path parameter
     */
    private function get_remote_update_info(string $current_version): ?object {
        // Check cache
        $cache_key = 'peanut_connect_update_' . md5($current_version);
        $cached = get_transient($cache_key);

        if ($cached !== false && is_object($cached)) {
            return $cached;
        }

        // Use path parameter instead of query string
        // Format: /updates/peanut-connect/{version}
        $url = self::API_URL . '/' . urlencode($current_version);

        // Make request
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        // Only trust a clean 200. A non-200 (error page, WAF challenge, captive
        // portal) that happens to carry attacker-controlled JSON must never be
        // cached as authoritative update info for the next 12 hours.
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response));

        if (!$body || !isset($body->plugin_info)) {
            return null;
        }

        $info = $body->plugin_info;

        // A version we can't parse is not a version we'll act on.
        if (isset($info->version)) {
            $info->version = self::sanitize_version((string) $info->version);
            if ($info->version === '') {
                return null;
            }
        }

        // Pin the package host. If the server response points the installer at
        // an untrusted host, drop the download URL so WordPress offers no
        // package rather than fetching code from an attacker.
        if (!empty($info->download_url) && !self::is_trusted_package_url((string) $info->download_url)) {
            unset($info->download_url);
        }

        // Cache for 12 hours
        set_transient($cache_key, $info, 12 * HOUR_IN_SECONDS);

        return $info;
    }

    /**
     * True only for an HTTPS URL whose host is on the trusted-package allowlist
     * (exact match — no suffix games like "peanutgraphic.com.evil.test").
     */
    private static function is_trusted_package_url(string $url): bool {
        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https' || empty($parts['host'])) {
            return false;
        }
        return in_array(strtolower($parts['host']), self::TRUSTED_PACKAGE_HOSTS, true);
    }

    /**
     * Normalize a dotted-numeric version, or '' if it isn't one.
     */
    private static function sanitize_version(string $version): string {
        $version = trim($version);
        return preg_match('/^[0-9]+(\.[0-9]+){0,3}$/', $version) ? $version : '';
    }

    /**
     * Clear update cache
     *
     * Removes all cached update information including version-specific
     * transients. Uses parameterized queries for security.
     */
    public function clear_update_cache(): void {
        delete_site_transient('update_plugins');

        // Clear version-specific caches using parameterized LIKE patterns
        global $wpdb;

        // Use $wpdb->esc_like() to properly escape the LIKE pattern
        // Then use $wpdb->prepare() to parameterize the full query
        $transient_pattern = $wpdb->esc_like('_transient_peanut_connect_update_') . '%';
        $timeout_pattern = $wpdb->esc_like('_transient_timeout_peanut_connect_update_') . '%';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $transient_pattern
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $timeout_pattern
            )
        );
    }

    /**
     * Manually check for updates
     */
    public function force_update_check(): ?object {
        $this->clear_update_cache();

        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $this->plugin_file);
        $current_version = $plugin_data['Version'] ?? '0.0.0';

        return $this->get_remote_update_info($current_version);
    }
}
