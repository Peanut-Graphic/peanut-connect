<?php
/**
 * Peanut Connect Health Checker
 *
 * Gathers health data about the WordPress installation.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Connect_Health {

    /**
     * Cache TTL for health data (30 seconds)
     */
    private const HEALTH_CACHE_TTL = 30;

    /**
     * Cache TTL for SSL data (5 minutes)
     */
    private const SSL_CACHE_TTL = 300;

    /**
     * Get comprehensive health data (with caching)
     *
     * @param bool $force_refresh Force bypass cache
     */
    public static function get_health_data(bool $force_refresh = false): array {
        $cache_key = 'peanut_connect_health';

        // Check cache unless forced refresh
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }

        $data = [
            'wp_version' => self::get_wp_version_data(),
            'php_version' => self::get_php_version_data(),
            'ssl' => self::get_ssl_data(),
            'plugins' => self::get_plugins_data(),
            'themes' => self::get_themes_data(),
            'disk_space' => self::get_disk_space_data(),
            'database' => self::get_database_data(),
            'debug_mode' => defined('WP_DEBUG') && WP_DEBUG,
            'backup' => self::get_backup_data(),
            'file_permissions' => self::get_file_permissions_data(),
            'server' => self::get_server_data(),
            'peanut_suite' => self::get_peanut_suite_data(),
            'error_log' => self::get_error_log_data(),
            '_cached_at' => current_time('mysql'),
        ];

        // ML anomaly detection (v3.5.0+)
        // Non-blocking integration with anomaly detection if ML service is available
        if (class_exists('Peanut_Connect_ML_Anomaly')) {
            $ml = new Peanut_Connect_ML_Anomaly();
            if ($ml->is_available()) {
                $anomaly_data = $ml->detect_anomalies($data);
                if ($anomaly_data) {
                    $data['ml_anomaly_analysis'] = $anomaly_data;
                }
            }
        }

        // Cache the result
        set_transient($cache_key, $data, self::HEALTH_CACHE_TTL);

        return $data;
    }

    /**
     * Invalidate health cache
     */
    public static function invalidate_cache(): void {
        delete_transient('peanut_connect_health');
        delete_transient('peanut_connect_ssl');
    }

    /**
     * Get WordPress version data
     */
    private static function get_wp_version_data(): array {
        global $wp_version;

        require_once ABSPATH . 'wp-admin/includes/update.php';
        $updates = get_core_updates();

        $needs_update = false;
        $latest_version = $wp_version;

        if (!empty($updates) && $updates[0]->response === 'upgrade') {
            $needs_update = true;
            $latest_version = $updates[0]->current;
        }

        return [
            'version' => $wp_version,
            'latest_version' => $latest_version,
            'needs_update' => $needs_update,
        ];
    }

    /**
     * Get PHP version data
     */
    private static function get_php_version_data(): array {
        $version = phpversion();

        return [
            'version' => $version,
            'recommended' => version_compare($version, '8.1', '>='),
            'minimum_met' => version_compare($version, '8.0', '>='),
        ];
    }

    /**
     * Get SSL certificate data (with caching)
     *
     * Actually connects to the server to verify SSL certificate exists,
     * regardless of WordPress URL settings.
     */
    private static function get_ssl_data(): array {
        // Check cache first - SSL data is expensive to fetch
        $cache_key = 'peanut_connect_ssl';
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        // Get the host from site URL
        $site_url = get_site_url();
        $parsed = wp_parse_url($site_url);
        $host = $parsed['host'] ?? '';

        $data = [
            'enabled' => false,
            'valid' => false,
            'days_until_expiry' => null,
            'issuer' => null,
            'wp_url_is_https' => strpos($site_url, 'https://') === 0,
        ];

        if (!$host) {
            set_transient($cache_key, $data, self::SSL_CACHE_TTL);
            return $data;
        }

        // Actually try to connect via SSL to check if certificate exists
        // This works regardless of whether WordPress URL is http or https
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $socket = @stream_socket_client(
            "ssl://{$host}:443",
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket) {
            $params = stream_context_get_params($socket);
            $cert_resource = $params['options']['ssl']['peer_certificate'] ?? null;

            if ($cert_resource) {
                $cert = openssl_x509_parse($cert_resource);

                if ($cert) {
                    $valid_to = $cert['validTo_time_t'] ?? 0;
                    $days_remaining = ($valid_to - time()) / DAY_IN_SECONDS;

                    // SSL is enabled if we can connect and get a certificate
                    $data['enabled'] = true;
                    $data['days_until_expiry'] = (int) $days_remaining;
                    $data['valid'] = $days_remaining > 0;
                    $data['issuer'] = $cert['issuer']['O'] ?? $cert['issuer']['CN'] ?? 'Unknown';
                    $data['expires_at'] = date('Y-m-d H:i:s', $valid_to);
                }
            }

            fclose($socket);
        }

        // Cache SSL data for 5 minutes
        set_transient($cache_key, $data, self::SSL_CACHE_TTL);

        return $data;
    }

    /**
     * Get plugins data
     */
    private static function get_plugins_data(): array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', []);

        // Check for updates
        $update_plugins = get_site_transient('update_plugins');
        $updates_available = 0;
        $plugins_needing_update = [];
        $update_lookup = [];

        if ($update_plugins && !empty($update_plugins->response)) {
            foreach ($update_plugins->response as $plugin_file => $plugin_data) {
                $updates_available++;
                $update_lookup[$plugin_file] = $plugin_data->new_version;
                $plugins_needing_update[] = [
                    'slug' => dirname($plugin_file),
                    'file' => $plugin_file,
                    'name' => $all_plugins[$plugin_file]['Name'] ?? $plugin_file,
                    'version' => $all_plugins[$plugin_file]['Version'] ?? 'unknown',
                    'new_version' => $plugin_data->new_version,
                ];
            }
        }

        // Build full plugin list
        $plugin_list = [];
        foreach ($all_plugins as $plugin_file => $plugin_data) {
            $is_active = in_array($plugin_file, $active_plugins, true);
            $plugin_list[] = [
                'file' => $plugin_file,
                'slug' => dirname($plugin_file) !== '.' ? dirname($plugin_file) : basename($plugin_file, '.php'),
                'name' => $plugin_data['Name'] ?? $plugin_file,
                'version' => $plugin_data['Version'] ?? 'unknown',
                'author' => $plugin_data['Author'] ?? 'Unknown',
                'description' => wp_trim_words($plugin_data['Description'] ?? '', 20),
                'active' => $is_active,
                'update_available' => isset($update_lookup[$plugin_file]),
                'new_version' => $update_lookup[$plugin_file] ?? null,
                'plugin_uri' => $plugin_data['PluginURI'] ?? null,
            ];
        }

        // Sort: active first, then alphabetically
        usort($plugin_list, function ($a, $b) {
            if ($a['active'] !== $b['active']) {
                return $b['active'] - $a['active'];
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return [
            'total' => count($all_plugins),
            'active' => count($active_plugins),
            'inactive' => count($all_plugins) - count($active_plugins),
            'updates_available' => $updates_available,
            'needing_update' => $plugins_needing_update,
            'list' => $plugin_list,
        ];
    }

    /**
     * Get themes data
     */
    private static function get_themes_data(): array {
        $all_themes = wp_get_themes();
        $active_theme = wp_get_theme();
        $active_stylesheet = get_stylesheet();

        // Check for updates
        $update_themes = get_site_transient('update_themes');
        $updates_available = 0;
        $themes_needing_update = [];
        $update_lookup = [];

        if ($update_themes && !empty($update_themes->response)) {
            foreach ($update_themes->response as $stylesheet => $theme_data) {
                $updates_available++;
                $update_lookup[$stylesheet] = $theme_data['new_version'];
                $theme = wp_get_theme($stylesheet);
                $themes_needing_update[] = [
                    'slug' => $stylesheet,
                    'name' => $theme->get('Name'),
                    'version' => $theme->get('Version'),
                    'new_version' => $theme_data['new_version'],
                ];
            }
        }

        // Build full theme list
        $theme_list = [];
        foreach ($all_themes as $stylesheet => $theme) {
            $is_active = ($stylesheet === $active_stylesheet);
            $theme_list[] = [
                'slug' => $stylesheet,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'author' => $theme->get('Author'),
                'description' => wp_trim_words($theme->get('Description'), 20),
                'active' => $is_active,
                'parent' => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
                'update_available' => isset($update_lookup[$stylesheet]),
                'new_version' => $update_lookup[$stylesheet] ?? null,
                'screenshot' => $theme->get_screenshot(),
            ];
        }

        // Sort: active first, then alphabetically
        usort($theme_list, function ($a, $b) {
            if ($a['active'] !== $b['active']) {
                return $b['active'] - $a['active'];
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return [
            'total' => count($all_themes),
            'active' => $active_theme->get('Name'),
            'active_stylesheet' => $active_stylesheet,
            'active_version' => $active_theme->get('Version'),
            'updates_available' => $updates_available,
            'needing_update' => $themes_needing_update,
            'list' => $theme_list,
        ];
    }

    /**
     * Get disk space data
     */
    private static function get_disk_space_data(): array {
        $upload_dir = wp_upload_dir();
        $path = $upload_dir['basedir'];

        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if ($total === false || $free === false) {
            return [
                'available' => false,
            ];
        }

        $used = $total - $free;
        $used_percent = ($used / $total) * 100;

        return [
            'available' => true,
            'total' => $total,
            'total_formatted' => size_format($total),
            'used' => $used,
            'used_formatted' => size_format($used),
            'free' => $free,
            'free_formatted' => size_format($free),
            'used_percent' => round($used_percent, 2),
        ];
    }

    /**
     * Get database data
     */
    private static function get_database_data(): array {
        global $wpdb;

        $size = 0;
        // Use prepare() with LIKE for consistency and to filter to WordPress tables only
        $tables = $wpdb->get_results(
            $wpdb->prepare(
                "SHOW TABLE STATUS LIKE %s",
                $wpdb->esc_like($wpdb->prefix) . '%'
            ),
            ARRAY_A
        );

        foreach ($tables as $table) {
            $size += ($table['Data_length'] ?? 0) + ($table['Index_length'] ?? 0);
        }

        return [
            'size' => $size,
            'size_formatted' => size_format($size),
            'tables_count' => count($tables),
            'prefix' => $wpdb->prefix,
        ];
    }

    /**
     * Get backup status (detect common backup plugins)
     */
    private static function get_backup_data(): array {
        $backup_plugins = [
            'updraftplus/updraftplus.php' => 'UpdraftPlus',
            'backwpup/backwpup.php' => 'BackWPup',
            'duplicator/duplicator.php' => 'Duplicator',
            'jetpack/jetpack.php' => 'Jetpack',
            'blogvault-real-time-backup/blogvault.php' => 'BlogVault',
        ];

        $active_plugins = get_option('active_plugins', []);
        $detected_backup_plugin = null;

        foreach ($backup_plugins as $plugin_file => $plugin_name) {
            if (in_array($plugin_file, $active_plugins)) {
                $detected_backup_plugin = $plugin_name;
                break;
            }
        }

        // Try to get last backup date from UpdraftPlus
        $last_backup = null;
        $days_since_last = null;

        if ($detected_backup_plugin === 'UpdraftPlus') {
            $history = get_option('updraft_backup_history', []);
            if (!empty($history)) {
                $last_backup_time = max(array_keys($history));
                $last_backup = date('Y-m-d H:i:s', $last_backup_time);
                $days_since_last = (int) ((time() - $last_backup_time) / DAY_IN_SECONDS);
            }
        }

        return [
            'plugin_detected' => $detected_backup_plugin,
            'last_backup' => $last_backup,
            'days_since_last' => $days_since_last,
        ];
    }

    /**
     * Get file permissions data
     */
    private static function get_file_permissions_data(): array {
        $checks = [];

        // Check wp-config.php
        $wp_config = ABSPATH . 'wp-config.php';
        if (file_exists($wp_config)) {
            $perms = fileperms($wp_config) & 0777;
            $checks['wp_config'] = [
                'permissions' => decoct($perms),
                'secure' => $perms <= 0644,
            ];
        }

        // Check .htaccess
        $htaccess = ABSPATH . '.htaccess';
        if (file_exists($htaccess)) {
            $perms = fileperms($htaccess) & 0777;
            $checks['htaccess'] = [
                'permissions' => decoct($perms),
                'secure' => $perms <= 0644,
            ];
        }

        // Check wp-content writable
        $wp_content = WP_CONTENT_DIR;
        $checks['wp_content'] = [
            'writable' => is_writable($wp_content),
        ];

        $all_secure = true;
        foreach ($checks as $check) {
            if (isset($check['secure']) && !$check['secure']) {
                $all_secure = false;
                break;
            }
        }

        return [
            'secure' => $all_secure,
            'checks' => $checks,
        ];
    }

    /**
     * Get server information
     */
    private static function get_server_data(): array {
        return [
            'software' => sanitize_text_field($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'),
            'php_sapi' => php_sapi_name(),
            'max_upload_size' => wp_max_upload_size(),
            'max_upload_size_formatted' => size_format(wp_max_upload_size()),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'php_extensions' => [
                'curl' => extension_loaded('curl'),
                'imagick' => extension_loaded('imagick'),
                'gd' => extension_loaded('gd'),
                'zip' => extension_loaded('zip'),
                'openssl' => extension_loaded('openssl'),
            ],
        ];
    }

    /**
     * Get Peanut Suite data if installed
     */
    private static function get_peanut_suite_data(): ?array {
        if (!function_exists('peanut_is_module_active')) {
            return null;
        }

        return [
            'installed' => true,
            'version' => defined('PEANUT_VERSION') ? PEANUT_VERSION : 'unknown',
            'modules' => function_exists('peanut_get_active_modules') ? peanut_get_active_modules() : [],
        ];
    }

    /**
     * Get error log data
     */
    private static function get_error_log_data(): array {
        if (!class_exists('Peanut_Connect_Error_Log')) {
            return [
                'enabled' => false,
                'available' => false,
            ];
        }

        $counts = Peanut_Connect_Error_Log::get_counts();
        $recent = Peanut_Connect_Error_Log::get_recent_counts();

        return [
            'enabled' => get_option('peanut_connect_error_logging', '1') !== '0',
            'available' => true,
            'total_entries' => $counts['total'],
            'counts' => $counts,
            'last_24h' => $recent,
            'has_critical' => $recent['critical'] > 0,
            'has_errors' => $recent['error'] > 0 || $recent['critical'] > 0,
        ];
    }
}
