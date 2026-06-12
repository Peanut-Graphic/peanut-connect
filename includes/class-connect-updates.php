<?php
/**
 * Peanut Connect Updates Handler
 *
 * Manages plugin and theme updates on the child site.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Connect_Updates {

    /**
     * Plugin folder slugs that must never be remotely deactivated or deleted by
     * the Hub. Remote teardown of a site's security perimeter (Wordfence,
     * Sucuri, iThemes/Solid Security, etc.) via a single API call — or a leaked
     * key — would collapse the site's defenses silently, so these are refused
     * regardless of permission flags. Matched on the plugin's folder slug (the
     * path segment before the first slash), an exact set membership rather than
     * a fragile substring search.
     *
     * @var string[]
     */
    private const PROTECTED_PLUGIN_SLUGS = [
        'peanut-connect',      // self — never let the Hub sever its own link
        'wordfence',
        'better-wp-security',  // iThemes / Solid Security
        'sucuri-scanner',
        'all-in-one-wp-security-and-firewall',
        'wp-security-audit-log',
        'ninjafirewall',
        'sg-security',
        'malcare-security',
        'cerber-security',     // WP Cerber
        'shield-security',     // Shield Security
    ];

    /**
     * Is this plugin file protected from remote deactivation/deletion?
     *
     * @param string $plugin_file e.g. "wordfence/wordfence.php".
     */
    public static function is_protected_plugin(string $plugin_file): bool {
        $slug = strtolower(explode('/', trim($plugin_file), 2)[0]);
        return in_array($slug, self::PROTECTED_PLUGIN_SLUGS, true);
    }

    /**
     * Get all available updates
     */
    public static function get_available_updates(): array {
        // Force update check
        wp_update_plugins();
        wp_update_themes();

        return [
            'plugins' => self::get_plugin_updates(),
            'themes' => self::get_theme_updates(),
            'core' => self::get_core_update(),
        ];
    }

    /**
     * Get plugin updates
     */
    private static function get_plugin_updates(): array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $update_plugins = get_site_transient('update_plugins');
        $updates = [];

        if ($update_plugins && !empty($update_plugins->response)) {
            foreach ($update_plugins->response as $plugin_file => $plugin_data) {
                $updates[] = [
                    'slug' => $plugin_data->slug ?? dirname($plugin_file),
                    'file' => $plugin_file,
                    'name' => $all_plugins[$plugin_file]['Name'] ?? $plugin_file,
                    'version' => $all_plugins[$plugin_file]['Version'] ?? 'unknown',
                    'new_version' => $plugin_data->new_version,
                    'url' => $plugin_data->url ?? '',
                    'package' => $plugin_data->package ?? '',
                    'requires_php' => $plugin_data->requires_php ?? '',
                    'requires_wp' => $plugin_data->requires ?? '',
                ];
            }
        }

        return $updates;
    }

    /**
     * Get theme updates
     */
    private static function get_theme_updates(): array {
        $update_themes = get_site_transient('update_themes');
        $updates = [];

        if ($update_themes && !empty($update_themes->response)) {
            foreach ($update_themes->response as $stylesheet => $theme_data) {
                $theme = wp_get_theme($stylesheet);
                $updates[] = [
                    'slug' => $stylesheet,
                    'name' => $theme->get('Name'),
                    'version' => $theme->get('Version'),
                    'new_version' => $theme_data['new_version'],
                    'url' => $theme_data['url'] ?? '',
                    'package' => $theme_data['package'] ?? '',
                ];
            }
        }

        return $updates;
    }

    /**
     * Get WordPress core update
     */
    private static function get_core_update(): ?array {
        require_once ABSPATH . 'wp-admin/includes/update.php';

        $updates = get_core_updates();

        if (empty($updates) || $updates[0]->response !== 'upgrade') {
            return null;
        }

        global $wp_version;

        return [
            'current_version' => $wp_version,
            'new_version' => $updates[0]->current,
            'locale' => $updates[0]->locale,
            'package' => $updates[0]->package ?? '',
        ];
    }

    /**
     * Perform a plugin update
     */
    public static function update_plugin(string $plugin_file): array|WP_Error {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        // Verify plugin exists and has update
        $all_plugins = get_plugins();
        if (!isset($all_plugins[$plugin_file])) {
            return new WP_Error('plugin_not_found', __('Plugin not found.', 'peanut-connect'));
        }

        $update_plugins = get_site_transient('update_plugins');
        if (!isset($update_plugins->response[$plugin_file])) {
            return new WP_Error('no_update', __('No update available for this plugin.', 'peanut-connect'));
        }

        // Perform the update
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);

        $result = $upgrader->upgrade($plugin_file);

        if (is_wp_error($result)) {
            return $result;
        }

        if ($result === false) {
            return new WP_Error('update_failed', __('Plugin update failed.', 'peanut-connect'));
        }

        // Clear update cache
        delete_site_transient('update_plugins');
        wp_update_plugins();

        // Get new version
        $all_plugins = get_plugins();
        $new_version = $all_plugins[$plugin_file]['Version'] ?? 'unknown';

        return [
            'success' => true,
            'plugin' => $plugin_file,
            'new_version' => $new_version,
            'message' => sprintf(__('Plugin updated to version %s.', 'peanut-connect'), $new_version),
        ];
    }

    /**
     * Perform a theme update
     */
    public static function update_theme(string $stylesheet): array|WP_Error {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        // Verify theme exists
        $theme = wp_get_theme($stylesheet);
        if (!$theme->exists()) {
            return new WP_Error('theme_not_found', __('Theme not found.', 'peanut-connect'));
        }

        // Check for update
        $update_themes = get_site_transient('update_themes');
        if (!isset($update_themes->response[$stylesheet])) {
            return new WP_Error('no_update', __('No update available for this theme.', 'peanut-connect'));
        }

        // Perform the update
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Theme_Upgrader($skin);

        $result = $upgrader->upgrade($stylesheet);

        if (is_wp_error($result)) {
            return $result;
        }

        if ($result === false) {
            return new WP_Error('update_failed', __('Theme update failed.', 'peanut-connect'));
        }

        // Clear update cache
        delete_site_transient('update_themes');
        wp_update_themes();

        // Get new version
        $theme = wp_get_theme($stylesheet);
        $new_version = $theme->get('Version');

        return [
            'success' => true,
            'theme' => $stylesheet,
            'new_version' => $new_version,
            'message' => sprintf(__('Theme updated to version %s.', 'peanut-connect'), $new_version),
        ];
    }

    /**
     * Perform WordPress core update
     */
    public static function update_core(): array|WP_Error {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';

        // Check for core update
        $updates = get_core_updates();
        if (empty($updates) || $updates[0]->response !== 'upgrade') {
            return new WP_Error('no_update', __('WordPress is already up to date.', 'peanut-connect'));
        }

        $update = $updates[0];

        // Perform the update
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Core_Upgrader($skin);

        $result = $upgrader->upgrade($update);

        if (is_wp_error($result)) {
            return $result;
        }

        global $wp_version;

        return [
            'success' => true,
            'new_version' => $update->current,
            'message' => sprintf(__('WordPress updated to version %s.', 'peanut-connect'), $update->current),
        ];
    }

    /**
     * Perform update based on type
     */
    public static function perform_update(string $type, string $slug): array|WP_Error {
        switch ($type) {
            case 'plugin':
                // Find plugin file from slug
                $plugin_file = self::find_plugin_file($slug);
                if (!$plugin_file) {
                    return new WP_Error('plugin_not_found', __('Plugin not found.', 'peanut-connect'));
                }
                return self::update_plugin($plugin_file);

            case 'theme':
                return self::update_theme($slug);

            case 'core':
                return self::update_core();

            default:
                return new WP_Error('invalid_type', __('Invalid update type.', 'peanut-connect'));
        }
    }

    /**
     * Find plugin file from slug
     */
    private static function find_plugin_file(string $slug): ?string {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $slug_lower = strtolower($slug);

        // Try direct match first (case-insensitive on dirname/file).
        // Tolerates installs where the plugin folder is upper-cased
        // (e.g. PEANUT-CONNECT/peanut-connect.php) or version-suffixed
        // (e.g. peanut-connect-3.3.9/peanut-connect.php).
        foreach ($all_plugins as $plugin_file => $plugin_data) {
            $dir = dirname($plugin_file);
            $base = basename($plugin_file, '.php');

            if (
                strcasecmp($slug, $plugin_file) === 0
                || strcasecmp($slug, $dir) === 0
                || strcasecmp($slug, $base) === 0
            ) {
                return $plugin_file;
            }

            // Versioned folder fallback: dirname starts with "<slug>-"
            if (stripos($dir.'-', $slug_lower.'-') === 0) {
                return $plugin_file;
            }
        }

        // Try matching by slug from update data (also case-insensitive)
        $update_plugins = get_site_transient('update_plugins');
        if ($update_plugins && !empty($update_plugins->response)) {
            foreach ($update_plugins->response as $plugin_file => $plugin_data) {
                if (isset($plugin_data->slug) && strcasecmp($plugin_data->slug, $slug) === 0) {
                    return $plugin_file;
                }
            }
        }

        return null;
    }

    /**
     * Get ALL installed plugins with full details
     */
    public static function get_all_plugins(): array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', []);
        $update_plugins = get_site_transient('update_plugins');
        $plugins = [];

        foreach ($all_plugins as $plugin_file => $plugin_data) {
            $slug = dirname($plugin_file);
            if ($slug === '.') {
                $slug = basename($plugin_file, '.php');
            }

            $has_update = isset($update_plugins->response[$plugin_file]);
            $new_version = $has_update ? $update_plugins->response[$plugin_file]->new_version : null;

            $plugins[] = [
                'file' => $plugin_file,
                'slug' => $slug,
                'name' => $plugin_data['Name'],
                'version' => $plugin_data['Version'],
                'description' => $plugin_data['Description'] ?? '',
                'author' => $plugin_data['Author'] ?? '',
                'author_uri' => $plugin_data['AuthorURI'] ?? '',
                'plugin_uri' => $plugin_data['PluginURI'] ?? '',
                'active' => in_array($plugin_file, $active_plugins),
                'has_update' => $has_update,
                'new_version' => $new_version,
                'network_active' => is_multisite() && is_plugin_active_for_network($plugin_file),
                'requires_wp' => $plugin_data['RequiresWP'] ?? '',
                'requires_php' => $plugin_data['RequiresPHP'] ?? '',
                'auto_update' => self::is_auto_update_enabled('plugin', $plugin_file),
            ];
        }

        // Sort by name
        usort($plugins, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        return $plugins;
    }

    /**
     * Get ALL installed themes with full details
     */
    public static function get_all_themes(): array {
        $all_themes = wp_get_themes();
        $active_theme = get_stylesheet();
        $parent_theme = get_template();
        $update_themes = get_site_transient('update_themes');
        $themes = [];

        foreach ($all_themes as $stylesheet => $theme) {
            $has_update = isset($update_themes->response[$stylesheet]);
            $new_version = $has_update ? $update_themes->response[$stylesheet]['new_version'] : null;

            $themes[] = [
                'slug' => $stylesheet,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'description' => $theme->get('Description'),
                'author' => $theme->get('Author'),
                'author_uri' => $theme->get('AuthorURI'),
                'theme_uri' => $theme->get('ThemeURI'),
                'active' => $stylesheet === $active_theme,
                'parent' => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
                'is_parent' => $stylesheet === $parent_theme && $stylesheet !== $active_theme,
                'has_update' => $has_update,
                'new_version' => $new_version,
                'screenshot' => $theme->get_screenshot(),
                'requires_wp' => $theme->get('RequiresWP'),
                'requires_php' => $theme->get('RequiresPHP'),
                'auto_update' => self::is_auto_update_enabled('theme', $stylesheet),
            ];
        }

        // Sort: active first, then by name
        usort($themes, function($a, $b) {
            if ($a['active'] !== $b['active']) {
                return $a['active'] ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return $themes;
    }

    /**
     * Check if auto-update is enabled for a plugin or theme
     */
    private static function is_auto_update_enabled(string $type, string $item): bool {
        if ($type === 'plugin') {
            $auto_updates = get_site_option('auto_update_plugins', []);
            return in_array($item, $auto_updates);
        } elseif ($type === 'theme') {
            $auto_updates = get_site_option('auto_update_themes', []);
            return in_array($item, $auto_updates);
        }
        return false;
    }

    /**
     * Activate a plugin
     */
    public static function activate_plugin(string $plugin_file): array|WP_Error {
        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Verify plugin exists
        $all_plugins = get_plugins();
        if (!isset($all_plugins[$plugin_file])) {
            return new WP_Error('plugin_not_found', __('Plugin not found.', 'peanut-connect'));
        }

        // Check if already active
        if (is_plugin_active($plugin_file)) {
            return new WP_Error('already_active', __('Plugin is already active.', 'peanut-connect'));
        }

        // Activate
        $result = activate_plugin($plugin_file);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'success' => true,
            'plugin' => $plugin_file,
            'name' => $all_plugins[$plugin_file]['Name'],
            'message' => sprintf(__('Plugin "%s" activated.', 'peanut-connect'), $all_plugins[$plugin_file]['Name']),
        ];
    }

    /**
     * Deactivate a plugin
     */
    public static function deactivate_plugin(string $plugin_file): array|WP_Error {
        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Verify plugin exists
        $all_plugins = get_plugins();
        if (!isset($all_plugins[$plugin_file])) {
            return new WP_Error('plugin_not_found', __('Plugin not found.', 'peanut-connect'));
        }

        // Check if already inactive
        if (!is_plugin_active($plugin_file)) {
            return new WP_Error('already_inactive', __('Plugin is already inactive.', 'peanut-connect'));
        }

        // Refuse to remotely deactivate this plugin or any known security
        // plugin — a single Hub call (or a leaked key) must not be able to
        // strip the site's defenses.
        if (self::is_protected_plugin($plugin_file)) {
            return new WP_Error('cannot_deactivate', __('This plugin is protected from remote deactivation.', 'peanut-connect'));
        }

        // Deactivate
        deactivate_plugins($plugin_file);

        return [
            'success' => true,
            'plugin' => $plugin_file,
            'name' => $all_plugins[$plugin_file]['Name'],
            'message' => sprintf(__('Plugin "%s" deactivated.', 'peanut-connect'), $all_plugins[$plugin_file]['Name']),
        ];
    }

    /**
     * Delete a plugin
     */
    public static function delete_plugin(string $plugin_file): array|WP_Error {
        if (!function_exists('delete_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // Verify plugin exists
        $all_plugins = get_plugins();
        if (!isset($all_plugins[$plugin_file])) {
            return new WP_Error('plugin_not_found', __('Plugin not found.', 'peanut-connect'));
        }

        // Refuse to remotely delete this plugin or any known security plugin.
        if (self::is_protected_plugin($plugin_file)) {
            return new WP_Error('cannot_delete', __('This plugin is protected from remote deletion.', 'peanut-connect'));
        }

        // Ensure plugin is deactivated first
        if (is_plugin_active($plugin_file)) {
            deactivate_plugins($plugin_file);
        }

        $plugin_name = $all_plugins[$plugin_file]['Name'];

        // Delete
        $result = delete_plugins([$plugin_file]);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'success' => true,
            'plugin' => $plugin_file,
            'name' => $plugin_name,
            'message' => sprintf(__('Plugin "%s" deleted.', 'peanut-connect'), $plugin_name),
        ];
    }

    /**
     * Switch active theme
     */
    public static function activate_theme(string $stylesheet): array|WP_Error {
        $theme = wp_get_theme($stylesheet);

        if (!$theme->exists()) {
            return new WP_Error('theme_not_found', __('Theme not found.', 'peanut-connect'));
        }

        if (!$theme->is_allowed()) {
            return new WP_Error('theme_not_allowed', __('Theme is not allowed.', 'peanut-connect'));
        }

        // Check if already active
        if (get_stylesheet() === $stylesheet) {
            return new WP_Error('already_active', __('Theme is already active.', 'peanut-connect'));
        }

        switch_theme($stylesheet);

        return [
            'success' => true,
            'theme' => $stylesheet,
            'name' => $theme->get('Name'),
            'message' => sprintf(__('Theme "%s" activated.', 'peanut-connect'), $theme->get('Name')),
        ];
    }

    /**
     * Delete a theme
     */
    public static function delete_theme(string $stylesheet): array|WP_Error {
        $theme = wp_get_theme($stylesheet);

        if (!$theme->exists()) {
            return new WP_Error('theme_not_found', __('Theme not found.', 'peanut-connect'));
        }

        // Prevent deleting active theme
        if (get_stylesheet() === $stylesheet) {
            return new WP_Error('cannot_delete_active', __('Cannot delete the active theme.', 'peanut-connect'));
        }

        // Prevent deleting parent of active theme
        if (get_template() === $stylesheet && get_stylesheet() !== get_template()) {
            return new WP_Error('cannot_delete_parent', __('Cannot delete the parent theme of the active theme.', 'peanut-connect'));
        }

        $theme_name = $theme->get('Name');

        $result = delete_theme($stylesheet);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'success' => true,
            'theme' => $stylesheet,
            'name' => $theme_name,
            'message' => sprintf(__('Theme "%s" deleted.', 'peanut-connect'), $theme_name),
        ];
    }

    /**
     * Toggle auto-update for a plugin or theme
     */
    public static function toggle_auto_update(string $type, string $item, bool $enable): array|WP_Error {
        if ($type === 'plugin') {
            $auto_updates = get_site_option('auto_update_plugins', []);
            if ($enable) {
                if (!in_array($item, $auto_updates)) {
                    $auto_updates[] = $item;
                }
            } else {
                $auto_updates = array_diff($auto_updates, [$item]);
            }
            update_site_option('auto_update_plugins', $auto_updates);
        } elseif ($type === 'theme') {
            $auto_updates = get_site_option('auto_update_themes', []);
            if ($enable) {
                if (!in_array($item, $auto_updates)) {
                    $auto_updates[] = $item;
                }
            } else {
                $auto_updates = array_diff($auto_updates, [$item]);
            }
            update_site_option('auto_update_themes', $auto_updates);
        } else {
            return new WP_Error('invalid_type', __('Invalid type. Use "plugin" or "theme".', 'peanut-connect'));
        }

        return [
            'success' => true,
            'type' => $type,
            'item' => $item,
            'auto_update' => $enable,
            'message' => $enable
                ? __('Auto-update enabled.', 'peanut-connect')
                : __('Auto-update disabled.', 'peanut-connect'),
        ];
    }

    /**
     * Bulk update all plugins with available updates
     */
    public static function bulk_update_plugins(): array {
        $updates = self::get_plugin_updates();
        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($updates as $plugin) {
            $result = self::update_plugin($plugin['file']);
            if (is_wp_error($result)) {
                $results['failed'][] = [
                    'plugin' => $plugin['name'],
                    'error' => $result->get_error_message(),
                ];
            } else {
                $results['success'][] = [
                    'plugin' => $plugin['name'],
                    'new_version' => $result['new_version'],
                ];
            }
        }

        return $results;
    }

    /**
     * Bulk update all themes with available updates
     */
    public static function bulk_update_themes(): array {
        $updates = self::get_theme_updates();
        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($updates as $theme) {
            $result = self::update_theme($theme['slug']);
            if (is_wp_error($result)) {
                $results['failed'][] = [
                    'theme' => $theme['name'],
                    'error' => $result->get_error_message(),
                ];
            } else {
                $results['success'][] = [
                    'theme' => $theme['name'],
                    'new_version' => $result['new_version'],
                ];
            }
        }

        return $results;
    }
}
