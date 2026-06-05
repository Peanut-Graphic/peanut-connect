<?php
/**
 * Peanut Connect REST API
 *
 * Exposes endpoints for the manager site to communicate with this child site.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Connect_API {

    /**
     * Register all REST routes
     */
    public function register_routes(): void {
        // =====================
        // Admin endpoints (for React SPA)
        // =====================

        // Get settings for admin
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/settings', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_settings'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // Hub settings - auto-connect (generates key and sends to Hub)
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/settings/hub/connect', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'auto_connect_to_hub'],
            'permission_callback' => [$this, 'admin_permission_check'],
            'args' => [
                'hub_url' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                ],
            ],
        ]);

        // Hub settings - manual-connect (paste an existing API key from Hub)
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/settings/hub/manual-connect', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'manual_connect_to_hub'],
            'permission_callback' => [$this, 'admin_permission_check'],
            'args' => [
                'hub_url' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                ],
                'api_key' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Hub settings - test connection
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/settings/hub/test', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'test_hub_connection'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // Hub settings - disconnect
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/settings/hub/disconnect', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'disconnect_hub'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // Hub settings - trigger sync
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/settings/hub/sync', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'trigger_hub_sync'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // 3.9.5 — Resync click_to_portal events. Flips synced=0 on rows the
        // 3.9.4 backfill identified locally so they replay through Hub and
        // populate event_name + metadata on the existing Hub rows (Hub's
        // upsert path lands those fills without duplicating).
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/settings/hub/resync-click-to-portal', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'resync_click_to_portal'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // Hub settings - update mode (v2.6.0+)
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/settings/hub/mode', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'update_hub_mode'],
            'permission_callback' => [$this, 'admin_permission_check'],
            'args' => [
                'mode' => [
                    'required' => true,
                    'type' => 'string',
                    'enum' => ['standard', 'hide_suite', 'disable_suite'],
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Dashboard data
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/dashboard', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_dashboard'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // Podcast publish — Hub-driven (Bearer + publish_content). Idempotent
        // upsert of a PowerPress episode post keyed by the upstream GUID.
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/podcast/publish', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'publish_podcast_episode'],
            'permission_callback' => Peanut_Connect_Auth::hub_permission_callback_for('publish_content'),
            'args' => [
                'guid' => ['required' => true, 'type' => 'string'],
                'title' => ['required' => true, 'type' => 'string'],
                'enclosure_url' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        // Admin health endpoint (no Bearer token required)
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/admin/health', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_admin_health'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // Admin updates endpoint (no Bearer token required)
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/admin/updates', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_admin_updates'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // Admin perform update (no Bearer token required)
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/admin/update', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'admin_perform_update'],
            'permission_callback' => [$this, 'admin_permission_check'],
            'args' => [
                'type' => [
                    'required' => true,
                    'type' => 'string',
                    'enum' => ['plugin', 'theme', 'core'],
                ],
                'slug' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Force check for plugin updates (clears cache)
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/admin/check-updates', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'force_check_updates'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // =====================
        // Error Log endpoints
        // =====================

        // Get error log entries
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/errors', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_error_log'],
            'permission_callback' => [$this, 'admin_permission_check'],
            'args' => [
                'limit' => [
                    'type' => 'integer',
                    'default' => 50,
                    'minimum' => 1,
                    'maximum' => 500,
                ],
                'offset' => [
                    'type' => 'integer',
                    'default' => 0,
                ],
                'level' => [
                    'type' => 'string',
                    'enum' => ['critical', 'error', 'warning', 'notice', ''],
                ],
            ],
        ]);

        // Get error counts
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/errors/counts', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_error_counts'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // Clear error log
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/errors/clear', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'clear_error_log'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // Export error log
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/errors/export', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'export_error_log'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // Update error logging settings
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/errors/settings', [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => [$this, 'update_error_settings'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // =====================
        // Activity Log endpoints
        // =====================

        // Get activity log entries
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/activity', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_activity_log'],
            'permission_callback' => [$this, 'admin_permission_check'],
            'args' => [
                'limit' => [
                    'type' => 'integer',
                    'default' => 50,
                    'minimum' => 1,
                    'maximum' => 500,
                ],
                'offset' => [
                    'type' => 'integer',
                    'default' => 0,
                ],
                'type' => [
                    'type' => 'string',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['success', 'warning', 'error', 'info', ''],
                ],
            ],
        ]);

        // Get activity counts
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/activity/counts', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_activity_counts'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // Clear activity log
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/activity/clear', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'clear_activity_log'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // Export activity log
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/activity/export', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'export_activity_log'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // =====================
        // Plugin Management endpoints (admin)
        // =====================

        // Get all plugins with full details
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/plugins', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_all_plugins'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // Activate plugin
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/plugin/activate', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'activate_plugin'],
            'permission_callback' => [$this, 'admin_permission_check'],
            'args' => [
                'plugin' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Deactivate plugin
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/plugin/deactivate', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'deactivate_plugin'],
            'permission_callback' => [$this, 'admin_permission_check'],
            'args' => [
                'plugin' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Delete plugin
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/plugin/delete', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'delete_plugin'],
            'permission_callback' => [$this, 'admin_permission_check'],
            'args' => [
                'plugin' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Bulk update plugins
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/plugins/bulk-update', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'bulk_update_plugins'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // =====================
        // Theme Management endpoints (admin)
        // =====================

        // Get all themes with full details
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/themes', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_all_themes'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // Activate theme
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/theme/activate', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'activate_theme'],
            'permission_callback' => [$this, 'admin_permission_check'],
            'args' => [
                'theme' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Delete theme
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/theme/delete', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'delete_theme'],
            'permission_callback' => [$this, 'admin_permission_check'],
            'args' => [
                'theme' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Bulk update themes
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/themes/bulk-update', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'bulk_update_themes'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // Toggle auto-update for plugin or theme
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/auto-update', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'toggle_auto_update'],
            'permission_callback' => [$this, 'admin_permission_check'],
            'args' => [
                'type' => [
                    'required' => true,
                    'type' => 'string',
                    'enum' => ['plugin', 'theme'],
                ],
                'item' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'enable' => [
                    'required' => true,
                    'type' => 'boolean',
                ],
            ],
        ]);

        // =====================
        // Security Settings endpoints (admin)
        // =====================

        // Get security settings
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/security', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_security_settings'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // Update security settings
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/security', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'update_security_settings'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // =====================
        // Permissions endpoints (admin)
        // =====================

        // Get hub permissions
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/permissions', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_permissions'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // Update hub permissions
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/permissions', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'update_permissions'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // =====================
        // Hub Management endpoints (authenticated via Hub API key)
        // =====================

        // Get all plugins for Hub
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/hub/plugins', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_all_plugins'],
            'permission_callback' => [Peanut_Connect_Auth::class, 'hub_permission_callback'],
        ]);

        // Get all themes for Hub
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/hub/themes', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_all_themes'],
            'permission_callback' => [Peanut_Connect_Auth::class, 'hub_permission_callback'],
        ]);

        // Update plugin from Hub
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/hub/plugin/update', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'perform_update'],
            'permission_callback' => Peanut_Connect_Auth::hub_permission_callback_for('perform_updates'),
            'args' => [
                'type' => [
                    'default' => 'plugin',
                    'type' => 'string',
                ],
                'slug' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Update theme from Hub
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/hub/theme/update', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'perform_update'],
            'permission_callback' => Peanut_Connect_Auth::hub_permission_callback_for('perform_updates'),
            'args' => [
                'type' => [
                    'default' => 'theme',
                    'type' => 'string',
                ],
                'slug' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Activate plugin from Hub
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/hub/plugin/activate', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'activate_plugin'],
            'permission_callback' => Peanut_Connect_Auth::hub_permission_callback_for('perform_updates'),
            'args' => [
                'plugin' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Deactivate plugin from Hub
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/hub/plugin/deactivate', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'deactivate_plugin'],
            'permission_callback' => Peanut_Connect_Auth::hub_permission_callback_for('perform_updates'),
            'args' => [
                'plugin' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Bulk update plugins from Hub
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/hub/plugins/bulk-update', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'bulk_update_plugins'],
            'permission_callback' => Peanut_Connect_Auth::hub_permission_callback_for('perform_updates'),
        ]);

        // Get error log entries for Hub
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/hub/error-log', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_error_log_entries'],
            'permission_callback' => [Peanut_Connect_Auth::class, 'hub_permission_callback'],
            'args' => [
                'limit' => [
                    'default' => 50,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Get health data for Hub (used to refresh health data after updates)
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/hub/health', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_hub_health'],
            'permission_callback' => [Peanut_Connect_Auth::class, 'hub_permission_callback'],
        ]);

        // Force a fresh update check (clears WP transients + Connect's own cache).
        // Hub-authenticated mirror of /admin/check-updates.
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/hub/check-updates', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'force_check_updates'],
            'permission_callback' => [Peanut_Connect_Auth::class, 'hub_permission_callback'],
        ]);

        // API Proxy for Hub (v3.3.0+)
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/hub/api-proxy', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [Peanut_Connect_API_Proxy::class, 'handle_request'],
            'permission_callback' => Peanut_Connect_Auth::hub_permission_callback_for('api_proxy'),
            'args' => [
                'url' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                ],
                'method' => [
                    'default' => 'GET',
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'params' => [
                    'default' => [],
                    'type' => 'object',
                ],
                'timeout' => [
                    'default' => 30,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // =====================
        // Hub Tracking endpoints (public, for frontend tracking)
        // =====================

        // Track event (pageview, click, etc.)
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/track', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'track_event'],
            'permission_callback' => '__return_true',
            'args' => [
                'visitor_id' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'event_type' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'event_name' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // GTM Hub Beacon — same-origin proxy to Hub's signed-beacon endpoint.
        // The GTM tag posts the (HMAC-signed) payload here instead of directly
        // to hub.peanutgraphic.com, which Safari's Intelligent Tracking
        // Prevention blocks as a cross-site sendBeacon. We forward server-side
        // so Safari sees only a first-party POST. Body is passed through
        // unmodified — the signature is computed over the JSON the GTM tag
        // produced, so any mutation here would break HMAC verification.
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/gtm-beacon', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'proxy_gtm_beacon'],
            'permission_callback' => '__return_true',
        ]);

        // Identify visitor (attach email/name)
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/identify', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'identify_visitor'],
            'permission_callback' => '__return_true',
            'args' => [
                'visitor_id' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'email' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_email',
                ],
            ],
        ]);

        // Track conversion
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/conversion', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'track_conversion'],
            'permission_callback' => '__return_true',
            'args' => [
                'visitor_id' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'type' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Track popup interaction
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/popup-interaction', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'track_popup_interaction'],
            'permission_callback' => '__return_true',
            'args' => [
                'popup_id' => [
                    'required' => true,
                    'type' => 'integer',
                ],
                'action' => [
                    'required' => true,
                    'type' => 'string',
                    'enum' => ['view', 'click', 'convert', 'dismiss', 'close'],
                ],
            ],
        ]);

        // =====================
        // Hub Settings endpoints (admin)
        // =====================

        // Get hub connection settings
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/hub/settings', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_hub_settings'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // Update hub connection settings
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/hub/settings', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'update_hub_settings'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // Test hub connection
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/hub/test', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'test_hub_connection'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // Disconnect from hub
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/hub/disconnect', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'disconnect_hub'],
            'permission_callback' => [$this, 'admin_permission_check'],
        ]);

        // =====================
        // Status & Diagnostics endpoints (for Hub API Tester)
        // =====================

        // Public status endpoint (used by Hub to verify connectivity)
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/status', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_public_status'],
            'permission_callback' => [Peanut_Connect_Auth::class, 'hub_permission_callback'],
        ]);

        // Form sync diagnostics (used by Hub API Tester)
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/forms/diagnostics', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_forms_diagnostics'],
            'permission_callback' => [Peanut_Connect_Auth::class, 'hub_permission_callback'],
        ]);

        // Test form sync (validates forms can be received from Hub)
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/forms/test-sync', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'test_form_sync'],
            'permission_callback' => [Peanut_Connect_Auth::class, 'hub_permission_callback'],
        ]);

        // =====================
        // Event Banner endpoints (v3.3.0+)
        // =====================

        // Show banner (Hub command)
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/banner/show', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'show_event_banner'],
            'permission_callback' => [Peanut_Connect_Auth::class, 'hub_permission_callback'],
            'args' => [
                'deployment_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'event_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'html' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'css' => [
                    'type' => 'string',
                    'default' => '',
                ],
                'position' => [
                    'type' => 'string',
                    'default' => 'top',
                    'enum' => ['top', 'bottom', 'fixed-top', 'fixed-bottom'],
                ],
                'show_at' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'hide_at' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'is_test' => [
                    'type' => 'boolean',
                    'default' => false,
                ],
            ],
        ]);

        // Hide banner (Hub command)
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/banner/hide', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'hide_event_banner'],
            'permission_callback' => [Peanut_Connect_Auth::class, 'hub_permission_callback'],
            'args' => [
                'deployment_id' => [
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Get banner status
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/banner/status', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_banner_status'],
            'permission_callback' => [Peanut_Connect_Auth::class, 'hub_permission_callback'],
        ]);

        // Banner diagnostics (for API tester)
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/banner/diagnostics', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_banner_diagnostics'],
            'permission_callback' => [Peanut_Connect_Auth::class, 'hub_permission_callback'],
        ]);

        // =====================
        // Backup, Restore & Update endpoints (v3.4.0+)
        // =====================

        // Create a site backup (database + files)
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/backup', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_backup'],
            'permission_callback' => [Peanut_Connect_Auth::class, 'hub_permission_callback'],
            'args' => [
                'type' => [
                    'default' => 'full',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'storage_driver' => [
                    'default' => 'local',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Restore from a backup archive
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/restore', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'restore_backup'],
            'permission_callback' => [Peanut_Connect_Auth::class, 'hub_permission_callback'],
            'args' => [
                'backup_url' => [
                    'required' => true,
                    'sanitize_callback' => 'esc_url_raw',
                ],
            ],
        ]);

        // Unified update endpoint (plugin, theme, or core)
        register_rest_route(PEANUT_CONNECT_API_NAMESPACE, '/update', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'apply_update'],
            'permission_callback' => Peanut_Connect_Auth::hub_permission_callback_for('perform_updates'),
            'args' => [
                'component_type' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'slug' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'version' => [
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * Perform update endpoint (used by Hub)
     */
    public function perform_update(WP_REST_Request $request): WP_REST_Response {
        $type = $request->get_param('type');
        $slug = $request->get_param('slug');

        $result = Peanut_Connect_Updates::perform_update($type, $slug);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], 400);
        }

        return new WP_REST_Response($result, 200);
    }

    // =====================
    // Admin endpoint callbacks
    // =====================

    /**
     * Check if current user has admin permissions
     */
    public function admin_permission_check(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Get settings for admin panel (Hub-only)
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response {
        // Hub settings
        $hub_url = get_option('peanut_connect_hub_url');
        $hub_api_key = get_option('peanut_connect_hub_api_key');
        $hub_last_sync = get_option('peanut_connect_last_hub_sync');
        $hub_mode = get_option('peanut_connect_hub_mode', 'standard');
        $tracking_enabled = get_option('peanut_connect_tracking_enabled', false);
        $track_logged_in = get_option('peanut_connect_track_logged_in', false);

        // Get Peanut Suite info
        $peanut_suite = null;
        if (function_exists('peanut_is_module_active')) {
            $peanut_suite = [
                'installed' => true,
                'version' => defined('PEANUT_VERSION') ? PEANUT_VERSION : 'unknown',
                'modules' => function_exists('peanut_get_active_modules') ? peanut_get_active_modules() : [],
            ];
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'hub' => [
                    'connected' => !empty($hub_url) && !empty($hub_api_key),
                    'url' => $hub_url ?: '',
                    'api_key_set' => !empty($hub_api_key),
                    'last_sync' => $hub_last_sync,
                    'mode' => $hub_mode,
                    'tracking_enabled' => (bool) $tracking_enabled,
                    'track_logged_in' => (bool) $track_logged_in,
                ],
                'peanut_suite' => $peanut_suite,
            ],
        ], 200);
    }

    /**
     * Auto-connect to Hub by generating a key locally and sending it to Hub
     *
     * This is the preferred connection method:
     * 1. WordPress generates a random 64-char API key
     * 2. WordPress sends the key to Hub's /api/v1/sites/connect endpoint
     * 3. Hub finds the site by URL (must already exist in Hub)
     * 4. Hub stores the key hash and activates the site
     * 5. WordPress saves both Hub URL and API key locally
     */
    public function auto_connect_to_hub(WP_REST_Request $request): WP_REST_Response {
        $hub_url = $request->get_param('hub_url');

        if (empty($hub_url)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Hub URL is required.', 'peanut-connect'),
            ], 400);
        }

        // Validate URL format
        if (!filter_var($hub_url, FILTER_VALIDATE_URL)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Invalid Hub URL format.', 'peanut-connect'),
            ], 400);
        }

        // Generate a random 64-character API key
        $api_key = wp_generate_password(64, false, false);

        // Build the connect endpoint URL
        $endpoint = rtrim($hub_url, '/') . '/api/v1/sites/connect';

        // Send the key to Hub
        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => wp_json_encode([
                'site_url' => get_site_url(),
                'api_key' => $api_key,
                'connect_version' => PEANUT_CONNECT_VERSION,
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => sprintf(
                    __('Failed to connect to Hub: %s', 'peanut-connect'),
                    $response->get_error_message()
                ),
            ], 400);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        // Check for specific error codes
        if (!empty($body['code'])) {
            $error_message = $body['message'] ?? __('Connection failed.', 'peanut-connect');

            switch ($body['code']) {
                case 'SITE_NOT_FOUND':
                    $error_message = __('This site is not registered in Hub. Please ask your agency to add this site first.', 'peanut-connect');
                    break;
                case 'ALREADY_CONNECTED':
                    $error_message = __('This site is already connected to Hub. Disconnect from Hub first to reconnect.', 'peanut-connect');
                    break;
            }

            return new WP_REST_Response([
                'success' => false,
                'message' => $error_message,
                'code' => $body['code'],
            ], $status_code);
        }

        // Check for success
        // Debug logging
        error_log('Peanut Connect: Hub response status=' . $status_code . ', success=' . ($body['success'] ?? 'null'));

        if ($status_code >= 200 && $status_code < 300 && ($body['success'] ?? false)) {
            // Save the Hub URL and API key locally
            error_log('Peanut Connect: Saving hub_url=' . $hub_url);
            $url_saved = update_option('peanut_connect_hub_url', $hub_url);
            error_log('Peanut Connect: hub_url saved=' . ($url_saved ? 'yes' : 'no'));

            error_log('Peanut Connect: Saving api_key (length=' . strlen($api_key) . ')');
            $key_saved = update_option('peanut_connect_hub_api_key', $api_key);
            error_log('Peanut Connect: api_key saved=' . ($key_saved ? 'yes' : 'no'));

            // Log activity
            Peanut_Connect_Activity_Log::log('hub_connected', 'success', 'Connected to Hub', [
                'hub_url' => $hub_url,
                'site_name' => $body['site']['name'] ?? '',
                'agency' => $body['agency']['name'] ?? '',
            ]);

            // Send initial heartbeat
            Peanut_Connect_Hub_Sync::send_heartbeat();

            return new WP_REST_Response([
                'success' => true,
                'message' => __('Successfully connected to Hub!', 'peanut-connect'),
                'data' => [
                    'site' => $body['site'] ?? [],
                    'client' => $body['client'] ?? [],
                    'agency' => $body['agency'] ?? [],
                ],
            ], 200);
        }

        // Generic error
        return new WP_REST_Response([
            'success' => false,
            'message' => $body['message'] ?? __('Failed to connect to Hub.', 'peanut-connect'),
        ], $status_code ?: 400);
    }

    /**
     * Manually connect to Hub using an existing API key.
     *
     * Use this when the site is already registered in Hub and has an active
     * api_key — the auto-connect flow refuses to overwrite an active key, so
     * you'd otherwise have to disconnect from Hub admin first. This route
     * verifies the supplied key against Hub's /sites/verify endpoint and,
     * on success, stores both values locally.
     */
    public function manual_connect_to_hub(WP_REST_Request $request): WP_REST_Response {
        $hub_url = rtrim((string) $request->get_param('hub_url'), '/');
        $api_key = trim((string) $request->get_param('api_key'));

        if ($hub_url === '' || $api_key === '') {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Hub URL and API key are both required.', 'peanut-connect'),
            ], 400);
        }

        // Validate the credentials against Hub before saving them locally.
        // Some hosts mangle response status codes (e.g. cPanel/ImunifyAV
        // rewriting 200 → 406 for POSTs / "suspicious" payloads) while
        // leaving the body intact, so we treat the body's `success` field
        // as authoritative and only fall back on status when the body is
        // unparseable.
        $verify_response = wp_remote_post($hub_url . '/api/v1/sites/verify', [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => '{}',
        ]);

        if (is_wp_error($verify_response)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => sprintf(
                    /* translators: %s: error message */
                    __('Could not reach Hub: %s', 'peanut-connect'),
                    $verify_response->get_error_message()
                ),
            ], 502);
        }

        $status = (int) wp_remote_retrieve_response_code($verify_response);
        $body   = json_decode((string) wp_remote_retrieve_body($verify_response), true);
        $body_says_success = is_array($body) && ! empty($body['success']);

        if ($body_says_success) {
            update_option('peanut_connect_hub_url', $hub_url);
            update_option('peanut_connect_hub_api_key', $api_key);

            return new WP_REST_Response([
                'success' => true,
                'message' => __('Connected to Hub.', 'peanut-connect'),
                'hub_url' => $hub_url,
                'site' => $body['site'] ?? null,
            ], 200);
        }

        if ($status === 401 || $status === 403) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Hub rejected the API key. Double-check it was copied correctly.', 'peanut-connect'),
            ], 401);
        }

        if (is_array($body) && isset($body['error']['message'])) {
            return new WP_REST_Response([
                'success' => false,
                'message' => (string) $body['error']['message'],
                'hub_status' => $status,
            ], 502);
        }

        return new WP_REST_Response([
            'success' => false,
            'message' => sprintf(
                /* translators: %d: HTTP status code */
                __('Hub returned %d during verification.', 'peanut-connect'),
                $status
            ),
            'hub_response' => $body,
        ], 502);
    }

    /**
     * Test Hub connection
     */
    public function test_hub_connection(WP_REST_Request $request): WP_REST_Response {
        $hub_url = get_option('peanut_connect_hub_url');
        $api_key = get_option('peanut_connect_hub_api_key');

        if (empty($hub_url) || empty($api_key)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Hub not configured.', 'peanut-connect'),
            ], 400);
        }

        $result = Peanut_Connect_Hub_Sync::verify_hub_connection($hub_url, $api_key);

        if ($result['success']) {
            return new WP_REST_Response([
                'success' => true,
                'message' => __('Hub connection successful.', 'peanut-connect'),
                'data' => [
                    'site' => $result['site'] ?? [],
                    'client' => $result['client'] ?? [],
                    'agency' => $result['agency'] ?? [],
                ],
            ], 200);
        }

        return new WP_REST_Response([
            'success' => false,
            'message' => $result['message'] ?? __('Failed to connect to Hub.', 'peanut-connect'),
        ], 400);
    }

    /**
     * Disconnect from Hub
     */
    public function disconnect_hub(WP_REST_Request $request): WP_REST_Response {
        // Get current Hub URL and API key before deleting
        $hub_url = get_option('peanut_connect_hub_url');
        $api_key = get_option('peanut_connect_hub_api_key');

        // Notify Hub about disconnect (best effort - don't fail if Hub is unreachable)
        if (!empty($hub_url) && !empty($api_key)) {
            $disconnect_endpoint = rtrim($hub_url, '/') . '/api/v1/sites/disconnect';
            wp_remote_post($disconnect_endpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => wp_json_encode([
                    'site_url' => get_site_url(),
                    'api_key' => $api_key,
                ]),
                'timeout' => 10,
                'blocking' => false, // Don't wait for response
            ]);
        }

        // Clear local options
        delete_option('peanut_connect_hub_url');
        delete_option('peanut_connect_hub_api_key');
        delete_option('peanut_connect_last_hub_sync');

        // Log activity
        Peanut_Connect_Activity_Log::log_disconnect('admin');

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Disconnected from Hub.', 'peanut-connect'),
        ], 200);
    }

    /**
     * Resync click_to_portal events to Hub.
     *
     * The 3.9.4 backfill set event_name='click_to_portal' + metadata locally
     * on historical primary-CTA click rows, but those rows were already
     * synced=1 so the next normal sync didn't re-send them — Hub stayed on
     * the lossy snapshot. This one-shot endpoint flips them back to
     * synced=0 so the next sync replays them with their now-complete
     * payload. Hub's resync-collision upsert path (peanut-hub#419) fills
     * in the missing fields on the existing Hub rows without duplicating.
     *
     * Idempotent for the no-op direction: re-running after rows are
     * resynced sets nothing.
     *
     * @since 3.9.5
     */
    public function resync_click_to_portal(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = Peanut_Connect_Database::table('events');

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $eligible = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table WHERE event_name = 'click_to_portal' AND synced = 1"
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $flipped = (int) $wpdb->query(
            "UPDATE $table SET synced = 0, synced_at = NULL WHERE event_name = 'click_to_portal' AND synced = 1"
        );

        Peanut_Connect_Activity_Log::log('hub_sync', 'resync_click_to_portal', $flipped, [
            'eligible' => $eligible,
            'flipped' => $flipped,
        ]);

        return new WP_REST_Response([
            'success' => true,
            'eligible' => $eligible,
            'flipped' => $flipped,
            'message' => $flipped > 0
                ? sprintf(
                    /* translators: %d = number of events queued for resync */
                    __('Queued %d click_to_portal events for resync. Run "Sync to Hub" to replay them.', 'peanut-connect'),
                    $flipped
                )
                : __('No click_to_portal events need resync.', 'peanut-connect'),
        ], 200);
    }

    /**
     * Trigger Hub sync
     */
    public function trigger_hub_sync(WP_REST_Request $request): WP_REST_Response {
        // Send heartbeat with health data
        $heartbeat_result = Peanut_Connect_Hub_Sync::send_heartbeat();

        // Run the full data sync (visitors, events, touches, conversions, etc.)
        $sync_result = Peanut_Connect_Hub_Sync::run_sync();

        $sync_success = $sync_result['success'] ?? false;
        $heartbeat_success = $heartbeat_result['success'] ?? false;
        $stats = $sync_result['stats'] ?? null;

        // Build detailed message showing both results
        $messages = [];

        if ($sync_success && $stats) {
            $total = array_sum($stats);
            if ($total > 0) {
                $parts = [];
                foreach ($stats as $type => $count) {
                    if ($count > 0) {
                        $parts[] = "$count $type";
                    }
                }
                $messages[] = sprintf(
                    __('Synced: %s.', 'peanut-connect'),
                    implode(', ', $parts)
                );
            } else {
                $messages[] = __('No new records to sync.', 'peanut-connect');
            }
        } elseif (!$sync_success) {
            $messages[] = sprintf(
                __('Sync failed: %s', 'peanut-connect'),
                $sync_result['message'] ?? __('Unknown error', 'peanut-connect')
            );
        }

        if (!$heartbeat_success) {
            $messages[] = sprintf(
                __('Heartbeat failed: %s', 'peanut-connect'),
                $heartbeat_result['message'] ?? __('Unknown error', 'peanut-connect')
            );
        }

        $success = $sync_success;
        $message = implode(' ', $messages) ?: __('Sync completed.', 'peanut-connect');

        return new WP_REST_Response([
            'success' => $success,
            'message' => $message,
            'stats' => $stats,
            'debug' => [
                'heartbeat' => $heartbeat_success,
                'sync' => $sync_success,
                'sync_error' => !$sync_success ? ($sync_result['message'] ?? null) : null,
            ],
        ], $success ? 200 : 400);
    }

    /**
     * Update Hub Mode setting (v2.6.0+)
     *
     * Controls how Peanut Suite behaves when connected to Hub:
     * - standard: Suite works normally
     * - hide_suite: Suite menu hidden but still runs
     * - disable_suite: Suite fully disabled
     */
    public function update_hub_mode(WP_REST_Request $request): WP_REST_Response {
        $mode = $request->get_param('mode');

        // Validate mode (already validated by REST API args, but double-check)
        $valid_modes = ['standard', 'hide_suite', 'disable_suite'];
        if (!in_array($mode, $valid_modes, true)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Invalid hub mode.', 'peanut-connect'),
            ], 400);
        }

        update_option('peanut_connect_hub_mode', $mode);

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Hub mode updated.', 'peanut-connect'),
            'mode' => $mode,
        ], 200);
    }

    /**
     * Get dashboard data (Hub-focused)
     */
    public function get_dashboard(WP_REST_Request $request): WP_REST_Response {
        // Hub connection info
        $hub_url = get_option('peanut_connect_hub_url');
        $hub_api_key = get_option('peanut_connect_hub_api_key');
        $hub_last_sync = get_option('peanut_connect_last_hub_sync');

        // Get health summary
        $health_data = Peanut_Connect_Health::get_health_data();
        $issues = [];
        $status = 'healthy';

        // Check for issues (with null safety)
        if (!empty($health_data['wordpress']['needs_update'])) {
            $issues[] = 'WordPress core update available';
            $status = 'warning';
        }
        if (isset($health_data['php']) && !($health_data['php']['is_recommended'] ?? true)) {
            $issues[] = 'PHP version is below recommended (' . ($health_data['php']['recommended'] ?? '8.0') . ')';
            if (!($health_data['php']['is_minimum'] ?? true)) {
                $status = 'critical';
            } elseif ($status !== 'critical') {
                $status = 'warning';
            }
        }
        if (isset($health_data['ssl']) && !($health_data['ssl']['enabled'] ?? true)) {
            $issues[] = 'SSL is not enabled';
            $status = 'critical';
        } elseif (isset($health_data['ssl']['days_until_expiry']) && $health_data['ssl']['days_until_expiry'] < 14) {
            $issues[] = 'SSL certificate expiring soon (' . $health_data['ssl']['days_until_expiry'] . ' days)';
            if ($status !== 'critical') {
                $status = 'warning';
            }
        }
        $plugins_updates = $health_data['plugins']['updates_available'] ?? 0;
        if ($plugins_updates > 0) {
            $issues[] = $plugins_updates . ' plugin update(s) available';
            if ($status !== 'critical') {
                $status = 'warning';
            }
        }
        $themes_updates = $health_data['themes']['updates_available'] ?? 0;
        if ($themes_updates > 0) {
            $issues[] = $themes_updates . ' theme update(s) available';
            if ($status !== 'critical') {
                $status = 'warning';
            }
        }
        $disk_used = $health_data['disk_space']['used_percentage'] ?? 0;
        if ($disk_used > 90) {
            $issues[] = 'Disk space is running low (' . $disk_used . '% used)';
            $status = 'critical';
        } elseif ($disk_used > 80) {
            $issues[] = 'Disk space is getting low (' . $disk_used . '% used)';
            if ($status !== 'critical') {
                $status = 'warning';
            }
        }

        // Get updates summary
        $updates = Peanut_Connect_Updates::get_available_updates();

        // Get Peanut Suite info
        $peanut_suite = null;
        if (function_exists('peanut_is_module_active')) {
            $peanut_suite = [
                'installed' => true,
                'version' => defined('PEANUT_VERSION') ? PEANUT_VERSION : null,
            ];
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'hub' => [
                    'connected' => !empty($hub_url) && !empty($hub_api_key),
                    'url' => $hub_url ?: '',
                    'last_sync' => $hub_last_sync,
                ],
                'health_summary' => [
                    'status' => $status,
                    'issues' => $issues,
                ],
                'updates' => [
                    'plugins' => count($updates['plugins'] ?? []),
                    'themes' => count($updates['themes'] ?? []),
                    'core' => $updates['core']['needs_update'] ?? false,
                ],
                'peanut_suite' => $peanut_suite,
            ],
        ], 200);
    }

    /**
     * Get health data for admin (no Bearer token required)
     */
    public function get_admin_health(WP_REST_Request $request): WP_REST_Response {
        $health = Peanut_Connect_Health::get_health_data();

        return new WP_REST_Response([
            'success' => true,
            'data' => $health,
        ], 200);
    }

    /**
     * Get health data for Hub (requires Bearer token)
     * Used to refresh health data after plugin/theme updates
     */
    public function get_hub_health(WP_REST_Request $request): WP_REST_Response {
        $health = Peanut_Connect_Health::get_health_data();

        return new WP_REST_Response([
            'success' => true,
            'data' => $health,
        ], 200);
    }

    /**
     * Get updates for admin (no Bearer token required)
     */
    public function get_admin_updates(WP_REST_Request $request): WP_REST_Response {
        $updates = Peanut_Connect_Updates::get_available_updates();

        return new WP_REST_Response([
            'success' => true,
            'data' => $updates,
        ], 200);
    }

    /**
     * Perform update for admin (no Bearer token required)
     */
    public function admin_perform_update(WP_REST_Request $request): WP_REST_Response {
        $type = $request->get_param('type');
        $slug = $request->get_param('slug');

        $result = Peanut_Connect_Updates::perform_update($type, $slug);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], 400);
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $result,
        ], 200);
    }

    /**
     * Force check for plugin updates by clearing cache
     */
    public function force_check_updates(WP_REST_Request $request): WP_REST_Response {
        // Clear the self-updater cache
        $updater = new Peanut_Connect_Self_Updater();
        $updater->clear_update_cache();

        // Force WordPress to re-check updates
        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');
        delete_site_transient('update_core');

        // Trigger fresh update check
        wp_clean_plugins_cache(true);
        wp_update_plugins();

        // Get current plugin version
        $plugin_data = get_plugin_data(PEANUT_CONNECT_FILE);
        $current_version = $plugin_data['Version'] ?? '0.0.0';

        // Get fresh update info
        $update_info = $updater->force_update_check();

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Update cache cleared. Check the Updates page for available updates.', 'peanut-connect'),
            'data' => [
                'current_version' => $current_version,
                'latest_version' => $update_info->version ?? $current_version,
                'update_available' => $update_info && isset($update_info->version) && version_compare($update_info->version, $current_version, '>'),
            ],
        ], 200);
    }

    // =====================
    // Error Log endpoint callbacks
    // =====================

    /**
     * Get error log entries
     */
    public function get_error_log(WP_REST_Request $request): WP_REST_Response {
        $limit = $request->get_param('limit') ?? 50;
        $offset = $request->get_param('offset') ?? 0;
        $level = $request->get_param('level') ?? '';

        if ($level) {
            $entries = Peanut_Connect_Error_Log::get_entries_by_level($level, $limit);
        } else {
            $entries = Peanut_Connect_Error_Log::get_entries($limit, $offset);
        }

        $counts = Peanut_Connect_Error_Log::get_counts();

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'entries' => $entries,
                'counts' => $counts,
                'logging_enabled' => get_option('peanut_connect_error_logging', '1') !== '0',
            ],
        ], 200);
    }

    /**
     * Get error counts
     */
    public function get_error_counts(WP_REST_Request $request): WP_REST_Response {
        $counts = Peanut_Connect_Error_Log::get_counts();
        $recent = Peanut_Connect_Error_Log::get_recent_counts();

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'all_time' => $counts,
                'last_24h' => $recent,
                'logging_enabled' => get_option('peanut_connect_error_logging', '1') !== '0',
            ],
        ], 200);
    }

    /**
     * Clear error log
     */
    public function clear_error_log(WP_REST_Request $request): WP_REST_Response {
        $result = Peanut_Connect_Error_Log::clear();

        return new WP_REST_Response([
            'success' => $result,
            'message' => $result
                ? __('Error log cleared successfully.', 'peanut-connect')
                : __('Failed to clear error log.', 'peanut-connect'),
        ], $result ? 200 : 500);
    }

    /**
     * Export error log as CSV
     */
    public function export_error_log(WP_REST_Request $request): WP_REST_Response {
        $csv = Peanut_Connect_Error_Log::export_csv();

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'csv' => $csv,
                'filename' => 'peanut-error-log-' . date('Y-m-d') . '.csv',
            ],
        ], 200);
    }

    /**
     * Update error logging settings
     */
    public function update_error_settings(WP_REST_Request $request): WP_REST_Response {
        $params = $request->get_json_params();

        if (isset($params['enabled'])) {
            update_option('peanut_connect_error_logging', $params['enabled'] ? '1' : '0');
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'logging_enabled' => get_option('peanut_connect_error_logging', '1') !== '0',
            ],
        ], 200);
    }

    // =====================
    // Activity Log Handlers
    // =====================

    /**
     * Get activity log entries
     */
    public function get_activity_log(WP_REST_Request $request): WP_REST_Response {
        $limit = $request->get_param('limit') ?? 50;
        $offset = $request->get_param('offset') ?? 0;
        $type = $request->get_param('type') ?? '';
        $status = $request->get_param('status') ?? '';

        $entries = Peanut_Connect_Activity_Log::get_entries($limit, $offset, $type, $status);
        $counts = Peanut_Connect_Activity_Log::get_recent_counts();

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'entries' => $entries,
                'counts' => $counts,
            ],
        ], 200);
    }

    /**
     * Get activity log counts
     */
    public function get_activity_counts(WP_REST_Request $request): WP_REST_Response {
        $by_type = Peanut_Connect_Activity_Log::get_counts_by_type();
        $recent = Peanut_Connect_Activity_Log::get_recent_counts();

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'by_type' => $by_type,
                'last_24h' => $recent,
            ],
        ], 200);
    }

    /**
     * Clear activity log
     */
    public function clear_activity_log(WP_REST_Request $request): WP_REST_Response {
        $result = Peanut_Connect_Activity_Log::clear();

        return new WP_REST_Response([
            'success' => $result,
            'message' => $result
                ? __('Activity log cleared.', 'peanut-connect')
                : __('Failed to clear activity log.', 'peanut-connect'),
        ], $result ? 200 : 500);
    }

    /**
     * Export activity log as CSV
     */
    public function export_activity_log(WP_REST_Request $request): WP_REST_Response {
        $csv = Peanut_Connect_Activity_Log::export_csv();

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'csv' => $csv,
                'filename' => 'peanut-activity-log-' . date('Y-m-d') . '.csv',
            ],
        ], 200);
    }

    // =====================
    // Hub Tracking endpoint callbacks
    // =====================

    /**
     * Check rate limit for tracking endpoints
     *
     * @param WP_REST_Request $request The request object.
     * @param string $endpoint Endpoint identifier for rate limiting.
     * @return WP_REST_Response|null Returns response if rate limited, null if allowed.
     */
    private function check_tracking_rate_limit(WP_REST_Request $request, string $endpoint): ?WP_REST_Response {
        $client_id = Peanut_Connect_Rate_Limiter::get_client_identifier($request);
        $result = Peanut_Connect_Rate_Limiter::check($client_id, $endpoint);

        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $response = new WP_REST_Response([
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => $result->get_error_message(),
            ], 429);
            $response->header('Retry-After', $data['retry_after'] ?? 60);
            return $response;
        }

        return null;
    }

    /**
     * Track event (pageview, click, etc.)
     */
    public function track_event(WP_REST_Request $request): WP_REST_Response {
        // Check rate limit
        $rate_limited = $this->check_tracking_rate_limit($request, 'track');
        if ($rate_limited) {
            return $rate_limited;
        }

        if (!class_exists('Peanut_Connect_Tracker')) {
            return new WP_REST_Response(['success' => false, 'message' => 'Tracking not initialized'], 500);
        }

        $visitor_id = $request->get_param('visitor_id');
        $event_type = $request->get_param('event_type');

        $data = [
            // event_name distinguishes custom events from each other
            // (e.g. click_to_portal vs video_play). Required for Hub
            // funnel classification when event_type='custom'. The 3.9.4
            // release added this — every release before 3.9.4 silently
            // dropped event_name on the floor here.
            'event_name' => $request->get_param('event_name'),
            'page_url'   => $request->get_param('page_url'),
            'page_title' => $request->get_param('page_title'),
            'referrer'   => $request->get_param('referrer'),
            'metadata'   => $request->get_param('metadata'),
        ];

        $event_id = Peanut_Connect_Tracker::record_event($visitor_id, $event_type, $data);

        return new WP_REST_Response([
            'success' => true,
            'event_id' => $event_id,
        ], 201);
    }

    /**
     * Forward an HMAC-signed GTM beacon payload to Hub. Pass-through proxy —
     * the request body is the JSON the GTM tag built and signed; mutating it
     * here would invalidate the signature. Fire-and-forget so the browser
     * doesn't wait on the round-trip. Returns 204 regardless (mirrors Hub).
     */
    public function proxy_gtm_beacon(WP_REST_Request $request): WP_REST_Response {
        $hub_url = get_option('peanut_connect_hub_url');
        if (empty($hub_url)) {
            // Not paired with a Hub yet — silently drop, same shape as Hub.
            return new WP_REST_Response(null, 204);
        }

        $endpoint = rtrim($hub_url, '/') . '/api/v1/gtm-beacon';
        $body = $request->get_body();

        if (is_string($body) && $body !== '') {
            wp_remote_post($endpoint, [
                'method' => 'POST',
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $body,
                'timeout' => 3,
                'blocking' => false,
            ]);
        }

        return new WP_REST_Response(null, 204);
    }

    /**
     * Identify visitor (attach email/name)
     */
    public function identify_visitor(WP_REST_Request $request): WP_REST_Response {
        // Check rate limit
        $rate_limited = $this->check_tracking_rate_limit($request, 'identify');
        if ($rate_limited) {
            return $rate_limited;
        }

        if (!class_exists('Peanut_Connect_Tracker')) {
            return new WP_REST_Response(['success' => false, 'message' => 'Tracking not initialized'], 500);
        }

        $visitor_id = $request->get_param('visitor_id');
        $email = $request->get_param('email');
        $name = $request->get_param('name');

        Peanut_Connect_Tracker::identify_visitor($visitor_id, $email, $name);

        return new WP_REST_Response([
            'success' => true,
        ], 200);
    }

    /**
     * Track conversion
     */
    public function track_conversion(WP_REST_Request $request): WP_REST_Response {
        // Check rate limit
        $rate_limited = $this->check_tracking_rate_limit($request, 'conversion');
        if ($rate_limited) {
            return $rate_limited;
        }

        if (!class_exists('Peanut_Connect_Tracker')) {
            return new WP_REST_Response(['success' => false, 'message' => 'Tracking not initialized'], 500);
        }

        $visitor_id = $request->get_param('visitor_id');
        $type = $request->get_param('type');

        $data = [
            'value' => $request->get_param('value'),
            'currency' => $request->get_param('currency') ?? 'USD',
            'email' => $request->get_param('email'),
            'name' => $request->get_param('name'),
            'order_id' => $request->get_param('order_id'),
            'metadata' => $request->get_param('metadata'),
        ];

        $conversion_id = Peanut_Connect_Tracker::record_conversion($visitor_id, $type, $data);

        return new WP_REST_Response([
            'success' => true,
            'conversion_id' => $conversion_id,
        ], 201);
    }

    /**
     * Track popup interaction
     */
    public function track_popup_interaction(WP_REST_Request $request): WP_REST_Response {
        // Check rate limit
        $rate_limited = $this->check_tracking_rate_limit($request, 'popup_interaction');
        if ($rate_limited) {
            return $rate_limited;
        }

        if (!class_exists('Peanut_Connect_Tracker')) {
            return new WP_REST_Response(['success' => false, 'message' => 'Tracking not initialized'], 500);
        }

        $popup_id = (int) $request->get_param('popup_id');
        $action = $request->get_param('action');
        $visitor_id = $request->get_param('visitor_id');

        $data = [
            'page_url' => $request->get_param('page_url'),
            'form_data' => $request->get_param('data'),
        ];

        $interaction_id = Peanut_Connect_Tracker::record_popup_interaction($popup_id, $action, $visitor_id, $data);

        return new WP_REST_Response([
            'success' => true,
            'interaction_id' => $interaction_id,
        ], 201);
    }

    // =====================
    // Hub Settings endpoint callbacks
    // =====================

    /**
     * Get hub connection settings
     */
    public function get_hub_settings(WP_REST_Request $request): WP_REST_Response {
        $hub_url = get_option('peanut_connect_hub_url', '');
        $api_key = get_option('peanut_connect_hub_api_key', '');
        $tracking_enabled = get_option('peanut_connect_tracking_enabled', false);
        $last_sync = get_option('peanut_connect_last_hub_sync', '');

        // Get unsynced counts if database class is loaded
        $pending = [];
        if (class_exists('Peanut_Connect_Database')) {
            $pending = Peanut_Connect_Database::get_unsynced_counts();
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'hub_url' => $hub_url,
                'connected' => !empty($hub_url) && !empty($api_key),
                'api_key_set' => !empty($api_key),
                'tracking_enabled' => $tracking_enabled,
                'last_sync' => $last_sync,
                'pending_sync' => $pending,
            ],
        ], 200);
    }

    /**
     * Update hub connection settings
     */
    /**
     * Return true if a Hub host can be safely stored — public HTTPS only,
     * no loopback / RFC1918 / link-local / multicast / unspecified.
     *
     * @since 3.7.21
     */
    private static function is_safe_hub_host(string $host, string $scheme): bool {
        if ($host === '') return false;
        if ($scheme !== '' && $scheme !== 'https') {
            // Allow localhost-only HTTP during dev environments? No — production
            // installs need TLS to protect the bearer header. Use HTTPS.
            return false;
        }
        // Block raw IP-literal forms.
        $ip = filter_var($host, FILTER_VALIDATE_IP);
        if ($ip !== false) {
            // Reject private/reserved ranges. FILTER_FLAG_NO_PRIV_RANGE +
            // NO_RES_RANGE returns false if the IP is in those ranges.
            $public = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            return $public !== false;
        }
        // Block obvious local hostnames + metadata service hostnames.
        $blocked_hosts = ['localhost', 'metadata.google.internal'];
        if (in_array($host, $blocked_hosts, true)) return false;
        if (str_ends_with($host, '.local') || str_ends_with($host, '.internal')) return false;
        // Resolve once and reject if it points to a non-public address.
        $resolved = gethostbyname($host);
        if ($resolved !== $host) {
            $public = filter_var(
                $resolved,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if ($public === false) return false;
        }
        return true;
    }

    public function update_hub_settings(WP_REST_Request $request): WP_REST_Response {
        $hub_url = $request->get_param('hub_url');
        $api_key = $request->get_param('api_key');
        $tracking_enabled = $request->get_param('tracking_enabled');
        $hub_mode = $request->get_param('mode');

        // Validate and save hub URL
        if ($hub_url !== null) {
            $hub_url = esc_url_raw($hub_url);
            // v3.7.21: SSRF guard — reject internal hosts so a compromised
            // admin can't repoint the plugin at metadata endpoints or
            // RFC1918 services that would receive the Hub bearer header.
            $host = strtolower((string) wp_parse_url($hub_url, PHP_URL_HOST));
            $scheme = strtolower((string) wp_parse_url($hub_url, PHP_URL_SCHEME));
            if ($hub_url !== '' && !self::is_safe_hub_host($host, $scheme)) {
                return new WP_REST_Response([
                    'success' => false,
                    'code' => 'peanut_connect_hub_url_invalid',
                    'message' => __('Hub URL must be a public HTTPS host (no internal/loopback addresses).', 'peanut-connect'),
                ], 400);
            }
            update_option('peanut_connect_hub_url', $hub_url);
        }

        // Save API key (only if provided, don't clear existing)
        if (!empty($api_key)) {
            update_option('peanut_connect_hub_api_key', sanitize_text_field($api_key));
        }

        // Save tracking enabled setting
        if ($tracking_enabled !== null) {
            update_option('peanut_connect_tracking_enabled', (bool) $tracking_enabled);
        }

        // Save track logged-in users setting
        $track_logged_in = $request->get_param('track_logged_in');
        if ($track_logged_in !== null) {
            update_option('peanut_connect_track_logged_in', (bool) $track_logged_in);
        }

        // Save Hub Mode setting (v2.6.0+)
        if ($hub_mode !== null) {
            $valid_modes = ['standard', 'hide_suite', 'disable_suite'];
            if (in_array($hub_mode, $valid_modes, true)) {
                update_option('peanut_connect_hub_mode', $hub_mode);
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Hub settings updated.', 'peanut-connect'),
        ], 200);
    }

    // =====================
    // Plugin Management Handlers
    // =====================

    /**
     * Get all plugins with full details
     */
    public function get_all_plugins(WP_REST_Request $request): WP_REST_Response {
        $plugins = Peanut_Connect_Updates::get_all_plugins();

        return new WP_REST_Response([
            'success' => true,
            'data' => $plugins,
            'total' => count($plugins),
            'active' => count(array_filter($plugins, fn($p) => $p['active'])),
            'updates_available' => count(array_filter($plugins, fn($p) => $p['has_update'])),
        ], 200);
    }

    /**
     * Build the PowerPress `enclosure` postmeta value.
     *
     * Classic PowerPress format = 4 newline-delimited fields:
     *   <media URL>\n<length bytes>\n<mime>\n<PHP-serialized settings array>
     * Verified against PowerPress 11.16.5 on nattybumpercar.com. Pure +
     * static so it is unit-testable without WordPress.
     */
    public static function build_powerpress_enclosure_meta(array $f): string {
        return implode("\n", [
            (string) ($f['url'] ?? ''),
            (string) ((int) ($f['bytes'] ?? 0)),
            (string) ($f['mime'] ?? 'audio/mpeg'),
            serialize($f['settings'] ?? []),
        ]);
    }

    /**
     * Publish (idempotent upsert) a PowerPress episode post from the Hub.
     *
     * Dedupe order: explicit wp_post_id -> post carrying peanut_episode_guid
     * meta == guid -> create new. Always (re)writes a CLEAN full meta set so
     * a re-publish never duplicates and never inherits drifted meta from a
     * duplicated post. `dry_run` returns the resolved target + computed meta
     * without writing (boardroom gate).
     */
    public function publish_podcast_episode(WP_REST_Request $request): WP_REST_Response {
        $p = $request->get_json_params() ?: $request->get_params();

        $guid = sanitize_text_field($p['guid'] ?? '');
        if ($guid === '') {
            return new WP_REST_Response(['success' => false, 'message' => 'guid is required'], 400);
        }
        if (empty($p['enclosure_url']) || empty($p['title'])) {
            return new WP_REST_Response(['success' => false, 'message' => 'title and enclosure_url are required'], 400);
        }
        $dry_run = ! empty($p['dry_run']);

        // Resolve target post.
        $post_id = 0;
        if (! empty($p['wp_post_id']) && get_post((int) $p['wp_post_id'])) {
            $post_id = (int) $p['wp_post_id'];
        } else {
            $found = get_posts([
                'post_type' => 'post',
                'post_status' => 'any',
                'numberposts' => 1,
                'fields' => 'ids',
                'meta_key' => 'peanut_episode_guid',
                'meta_value' => $guid,
            ]);
            if (! empty($found)) {
                $post_id = (int) $found[0];
            }
        }
        $action = $post_id ? 'update' : 'create';

        $explicit_in = (string) ($p['explicit'] ?? 'clean');
        $type_in = (string) ($p['episode_type'] ?? 'full');
        $settings = [
            'location' => [],
            'credits' => [],
            'copyright' => sanitize_text_field($p['author'] ?? ''),
            'copyright_url' => '',
            'duration' => sanitize_text_field($p['duration'] ?? '0:00:00'),
            'set_duration' => '0',
            'set_size' => '0',
            'author' => sanitize_text_field($p['author'] ?? ''),
            'explicit' => in_array($explicit_in, ['1', 'explicit', 'yes', 'true'], true) ? '1' : '2',
            'itunes_image' => esc_url_raw($p['itunes_image'] ?? ''),
            'episode_title' => sanitize_text_field($p['episode_title'] ?? $p['title']),
            'episode_no' => (int) ($p['episode_no'] ?? 0),
            'episode_no_display' => sanitize_text_field($p['episode_no_display'] ?? ''),
            'show_notes' => sanitize_textarea_field($p['show_notes'] ?? ''),
            'season' => (string) ($p['season'] ?? ''),
            'episode_type' => in_array($type_in, ['full', 'trailer', 'bonus'], true) ? $type_in : 'full',
            'feed_title' => sanitize_text_field($p['episode_title'] ?? $p['title']),
            'pci_transcript' => ! empty($p['pci_transcript_url']) ? 1 : 0,
            'pci_transcript_url' => esc_url_raw($p['pci_transcript_url'] ?? ''),
            'pci_chapters' => ! empty($p['pci_chapters_url']) ? 1 : 0,
            'pci_chapters_url' => esc_url_raw($p['pci_chapters_url'] ?? ''),
            'podcast_id' => '',
        ];
        $enclosure_meta = self::build_powerpress_enclosure_meta([
            'url' => $p['enclosure_url'],
            'bytes' => (int) ($p['enclosure_bytes'] ?? 0),
            'mime' => sanitize_text_field($p['enclosure_mime'] ?? 'audio/mpeg'),
            'settings' => $settings,
        ]);

        if ($dry_run) {
            return new WP_REST_Response(['success' => true, 'dry_run' => true, 'data' => [
                'action' => $action,
                'wp_post_id' => $post_id ?: null,
                'guid' => $guid,
                'enclosure_meta' => $enclosure_meta,
                'settings' => $settings,
            ]], 200);
        }

        $status_in = (string) ($p['status'] ?? 'publish');
        $postarr = [
            'post_title' => sanitize_text_field($p['title']),
            'post_content' => wp_kses_post($p['content'] ?? ''),
            'post_status' => in_array($status_in, ['publish', 'draft', 'pending', 'private'], true) ? $status_in : 'publish',
            'post_type' => 'post',
        ];
        if ($post_id) {
            $postarr['ID'] = $post_id;
            $res = wp_update_post($postarr, true);
        } else {
            $res = wp_insert_post($postarr, true);
        }
        if (is_wp_error($res)) {
            return new WP_REST_Response(['success' => false, 'message' => $res->get_error_message()], 500);
        }
        $post_id = (int) $res;

        update_post_meta($post_id, 'enclosure', $enclosure_meta);
        update_post_meta($post_id, 'peanut_episode_guid', $guid);

        // Apply slug + SEO meta regardless of post status, so drafts get them
        // too. Only set when provided — never clobber an existing value with an
        // empty payload field (older Hullabaloo releases omit these).
        if (! empty($p['slug'])) {
            wp_update_post(['ID' => $post_id, 'post_name' => sanitize_title($p['slug'])]);
        }
        if (! empty($p['meta_description'])) {
            update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_text_field($p['meta_description']));
        }

        if (class_exists('Peanut_Connect_Activity_Log')) {
            Peanut_Connect_Activity_Log::log('podcast_published', 'success', sanitize_text_field($p['title']), [
                'guid' => $guid,
                'post_id' => $post_id,
                'action' => $action,
            ]);
        }

        return new WP_REST_Response(['success' => true, 'data' => [
            'wp_post_id' => $post_id,
            'permalink' => get_permalink($post_id),
            'guid' => $guid,
            'action' => $action,
        ]], 200);
    }

    /**
     * Activate a plugin
     */
    public function activate_plugin(WP_REST_Request $request): WP_REST_Response {
        $plugin_file = $request->get_param('plugin');
        $result = Peanut_Connect_Updates::activate_plugin($plugin_file);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], 400);
        }

        // Log activity
        if (class_exists('Peanut_Connect_Activity_Log')) {
            Peanut_Connect_Activity_Log::log('plugin_activated', 'success', $result['name'], [
                'plugin' => $result['plugin'],
            ]);
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $result,
        ], 200);
    }

    /**
     * Deactivate a plugin
     */
    public function deactivate_plugin(WP_REST_Request $request): WP_REST_Response {
        $plugin_file = $request->get_param('plugin');
        $result = Peanut_Connect_Updates::deactivate_plugin($plugin_file);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], 400);
        }

        // Log activity
        if (class_exists('Peanut_Connect_Activity_Log')) {
            Peanut_Connect_Activity_Log::log('plugin_deactivated', 'success', $result['name'], [
                'plugin' => $result['plugin'],
            ]);
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $result,
        ], 200);
    }

    /**
     * Delete a plugin
     */
    public function delete_plugin(WP_REST_Request $request): WP_REST_Response {
        $plugin_file = $request->get_param('plugin');
        $result = Peanut_Connect_Updates::delete_plugin($plugin_file);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], 400);
        }

        // Log activity
        if (class_exists('Peanut_Connect_Activity_Log')) {
            Peanut_Connect_Activity_Log::log('plugin_deleted', 'warning', $result['name'], [
                'plugin' => $result['plugin'],
            ]);
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $result,
        ], 200);
    }

    /**
     * Bulk update all plugins
     */
    public function bulk_update_plugins(WP_REST_Request $request): WP_REST_Response {
        $results = Peanut_Connect_Updates::bulk_update_plugins();

        // Log activity
        if (class_exists('Peanut_Connect_Activity_Log')) {
            $success_count = count($results['success']);
            $failed_count = count($results['failed']);
            Peanut_Connect_Activity_Log::log(
                'bulk_plugin_update',
                $failed_count > 0 ? 'warning' : 'success',
                sprintf('%d updated, %d failed', $success_count, $failed_count),
                $results
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $results,
        ], 200);
    }

    // =====================
    // Theme Management Handlers
    // =====================

    /**
     * Get all themes with full details
     */
    public function get_all_themes(WP_REST_Request $request): WP_REST_Response {
        $themes = Peanut_Connect_Updates::get_all_themes();

        return new WP_REST_Response([
            'success' => true,
            'data' => $themes,
            'total' => count($themes),
            'updates_available' => count(array_filter($themes, fn($t) => $t['has_update'])),
        ], 200);
    }

    /**
     * Activate a theme
     */
    public function activate_theme(WP_REST_Request $request): WP_REST_Response {
        $stylesheet = $request->get_param('theme');
        $result = Peanut_Connect_Updates::activate_theme($stylesheet);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], 400);
        }

        // Log activity
        if (class_exists('Peanut_Connect_Activity_Log')) {
            Peanut_Connect_Activity_Log::log('theme_activated', 'success', $result['name'], [
                'theme' => $result['theme'],
            ]);
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $result,
        ], 200);
    }

    /**
     * Delete a theme
     */
    public function delete_theme(WP_REST_Request $request): WP_REST_Response {
        $stylesheet = $request->get_param('theme');
        $result = Peanut_Connect_Updates::delete_theme($stylesheet);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], 400);
        }

        // Log activity
        if (class_exists('Peanut_Connect_Activity_Log')) {
            Peanut_Connect_Activity_Log::log('theme_deleted', 'warning', $result['name'], [
                'theme' => $result['theme'],
            ]);
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $result,
        ], 200);
    }

    /**
     * Bulk update all themes
     */
    public function bulk_update_themes(WP_REST_Request $request): WP_REST_Response {
        $results = Peanut_Connect_Updates::bulk_update_themes();

        // Log activity
        if (class_exists('Peanut_Connect_Activity_Log')) {
            $success_count = count($results['success']);
            $failed_count = count($results['failed']);
            Peanut_Connect_Activity_Log::log(
                'bulk_theme_update',
                $failed_count > 0 ? 'warning' : 'success',
                sprintf('%d updated, %d failed', $success_count, $failed_count),
                $results
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $results,
        ], 200);
    }

    /**
     * Toggle auto-update for plugin or theme
     */
    public function toggle_auto_update(WP_REST_Request $request): WP_REST_Response {
        $type = $request->get_param('type');
        $item = $request->get_param('item');
        $enable = $request->get_param('enable');

        $result = Peanut_Connect_Updates::toggle_auto_update($type, $item, $enable);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], 400);
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $result,
        ], 200);
    }

    // =====================
    // Security Settings Handlers
    // =====================

    /**
     * Get security settings
     */
    public function get_security_settings(WP_REST_Request $request): WP_REST_Response {
        if (!class_exists('Peanut_Connect_Security')) {
            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'hide_login' => [
                        'enabled' => false,
                        'custom_slug' => '',
                        'available' => false,
                    ],
                    'disable_comments' => [
                        'enabled' => false,
                        'hide_existing' => false,
                    ],
                    'disable_xmlrpc' => get_option('peanut_connect_disable_xmlrpc', '0') === '1',
                    'disable_file_editing' => defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT,
                    'remove_version' => get_option('peanut_connect_remove_version', '0') === '1',
                ],
            ], 200);
        }

        $settings = Peanut_Connect_Security::get_settings();

        return new WP_REST_Response([
            'success' => true,
            'data' => $settings,
        ], 200);
    }

    /**
     * Update security settings
     */
    public function update_security_settings(WP_REST_Request $request): WP_REST_Response {
        $params = $request->get_json_params();
        $updated = [];

        // Disable XML-RPC
        if (isset($params['disable_xmlrpc'])) {
            update_option('peanut_connect_disable_xmlrpc', $params['disable_xmlrpc'] ? '1' : '0');
            $updated['disable_xmlrpc'] = (bool) $params['disable_xmlrpc'];
        }

        // Remove WordPress version
        if (isset($params['remove_version'])) {
            update_option('peanut_connect_remove_version', $params['remove_version'] ? '1' : '0');
            $updated['remove_version'] = (bool) $params['remove_version'];
        }

        // Disable comments
        if (isset($params['disable_comments'])) {
            update_option('peanut_connect_disable_comments', $params['disable_comments'] ? '1' : '0');
            $updated['disable_comments'] = (bool) $params['disable_comments'];
        }

        if (isset($params['hide_existing_comments'])) {
            update_option('peanut_connect_hide_existing_comments', $params['hide_existing_comments'] ? '1' : '0');
            $updated['hide_existing_comments'] = (bool) $params['hide_existing_comments'];
        }

        // Hide login - requires additional class
        if (isset($params['hide_login_enabled'])) {
            update_option('peanut_connect_hide_login', $params['hide_login_enabled'] ? '1' : '0');
            $updated['hide_login_enabled'] = (bool) $params['hide_login_enabled'];
        }

        if (isset($params['hide_login_slug'])) {
            $slug = sanitize_title($params['hide_login_slug']);
            if (!empty($slug) && $slug !== 'wp-admin' && $slug !== 'wp-login') {
                update_option('peanut_connect_login_slug', $slug);
                $updated['hide_login_slug'] = $slug;
            }
        }

        // Log activity
        if (class_exists('Peanut_Connect_Activity_Log')) {
            Peanut_Connect_Activity_Log::log('security_settings_updated', 'info', 'Security settings changed', $updated);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Security settings updated.', 'peanut-connect'),
            'data' => $updated,
        ], 200);
    }

    // =====================
    // Permissions Handlers
    // =====================

    /**
     * Get hub permissions
     */
    public function get_permissions(WP_REST_Request $request): WP_REST_Response {
        $permissions = get_option('peanut_connect_permissions', [
            'perform_updates' => false,
            'access_analytics' => false,
            'api_proxy' => false,
        ]);

        return new WP_REST_Response([
            'perform_updates' => !empty($permissions['perform_updates']),
            'access_analytics' => !empty($permissions['access_analytics']),
            'api_proxy' => !empty($permissions['api_proxy']),
        ], 200);
    }

    /**
     * Update hub permissions
     */
    public function update_permissions(WP_REST_Request $request): WP_REST_Response {
        $params = $request->get_json_params();
        $permissions = get_option('peanut_connect_permissions', [
            'perform_updates' => false,
            'access_analytics' => false,
        ]);

        if (isset($params['perform_updates'])) {
            $permissions['perform_updates'] = (bool) $params['perform_updates'];
        }

        if (isset($params['access_analytics'])) {
            $permissions['access_analytics'] = (bool) $params['access_analytics'];
        }

        if (isset($params['api_proxy'])) {
            $permissions['api_proxy'] = (bool) $params['api_proxy'];
        }

        update_option('peanut_connect_permissions', $permissions);

        // Log activity
        if (class_exists('Peanut_Connect_Activity_Log')) {
            Peanut_Connect_Activity_Log::log('permissions_updated', 'info', 'Hub permissions changed', $permissions);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Hub permissions updated.', 'peanut-connect'),
        ], 200);
    }

    // =====================
    // Hub Error Log Handlers
    // =====================

    /**
     * Get error log entries for Hub
     *
     * Returns PHP error log entries captured by Peanut Connect's error logger.
     *
     * @param WP_REST_Request $request REST request object.
     * @return WP_REST_Response Response with error log entries.
     */
    public function get_error_log_entries(WP_REST_Request $request): WP_REST_Response {
        $limit = $request->get_param('limit') ?: 50;

        // Initialize the error log class to set up paths
        Peanut_Connect_Error_Log::init();

        // Get entries
        $entries = Peanut_Connect_Error_Log::get_entries($limit);

        // Get counts for summary
        $counts = Peanut_Connect_Error_Log::get_recent_counts();

        return new WP_REST_Response([
            'success' => true,
            'entries' => $entries,
            'total' => count($entries),
            'counts' => $counts,
        ], 200);
    }

    // =====================
    // Status & Diagnostics endpoint callbacks (for Hub API Tester)
    // =====================

    /**
     * Get public status for Hub API Tester
     *
     * Returns Connect plugin version, Hub connection status, and sync status.
     * Used by Hub to verify connectivity and plugin health.
     *
     * @param WP_REST_Request $request REST request object.
     * @return WP_REST_Response Response with status information.
     */
    public function get_public_status(WP_REST_Request $request): WP_REST_Response {
        $hub_url = get_option('peanut_connect_hub_url', '');
        $hub_api_key = get_option('peanut_connect_hub_api_key', '');
        $last_sync = get_option('peanut_connect_last_hub_sync', '');
        $tracking_enabled = get_option('peanut_connect_tracking_enabled', false);

        // Check FormFlow status
        $formflow_active = class_exists('ISF\\Plugin') || class_exists('FormFlow');
        $formflow_version = null;
        if ($formflow_active) {
            if (defined('ISF_VERSION')) {
                $formflow_version = ISF_VERSION;
            } elseif (defined('FORMFLOW_VERSION')) {
                $formflow_version = FORMFLOW_VERSION;
            }
        }

        // Get pending sync counts
        $pending_sync = [];
        if (class_exists('Peanut_Connect_Database')) {
            $pending_sync = Peanut_Connect_Database::get_unsynced_counts();
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'connect_version' => PEANUT_CONNECT_VERSION,
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'site_url' => get_site_url(),
                'hub_connected' => !empty($hub_url) && !empty($hub_api_key),
                'hub_url' => $hub_url,
                'last_sync' => $last_sync,
                'tracking_enabled' => (bool) $tracking_enabled,
                'formflow' => [
                    'active' => $formflow_active,
                    'version' => $formflow_version,
                ],
                'pending_sync' => $pending_sync,
                'timestamp' => current_time('mysql', true),
            ],
        ], 200);
    }

    /**
     * Get form sync diagnostics for Hub API Tester
     *
     * Returns form sync statistics, cached forms count, and submission counts.
     * Used by Hub to diagnose form sync issues.
     *
     * @param WP_REST_Request $request REST request object.
     * @return WP_REST_Response Response with diagnostics information.
     */
    public function get_forms_diagnostics(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $diagnostics = [
            'hub_forms' => [
                'table_exists' => false,
                'count' => 0,
                'forms' => [],
            ],
            'form_submissions' => [
                'table_exists' => false,
                'total_count' => 0,
                'synced_count' => 0,
                'unsynced_count' => 0,
                'by_source' => [],
            ],
            'formflow' => [
                'active' => false,
                'instances_count' => 0,
                'submissions_count' => 0,
            ],
            'sync_status' => [
                'last_sync' => get_option('peanut_connect_last_hub_sync', ''),
                'last_form_sync' => get_option('peanut_connect_last_form_sync', ''),
            ],
        ];

        // Check Hub forms table
        $hub_forms_table = $wpdb->prefix . 'peanut_connect_hub_forms';
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $hub_forms_table
        ));

        if ($table_exists) {
            $diagnostics['hub_forms']['table_exists'] = true;
            $diagnostics['hub_forms']['count'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $hub_forms_table"
            );

            // Get form summaries
            $forms = $wpdb->get_results(
                "SELECT hub_form_id, slug, name, form_type, status, version, synced_at
                 FROM $hub_forms_table
                 ORDER BY synced_at DESC
                 LIMIT 20",
                ARRAY_A
            );
            $diagnostics['hub_forms']['forms'] = $forms ?: [];
        }

        // Check form submissions table
        $submissions_table = $wpdb->prefix . 'peanut_connect_form_submissions';
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $submissions_table
        ));

        if ($table_exists) {
            $diagnostics['form_submissions']['table_exists'] = true;
            $diagnostics['form_submissions']['total_count'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $submissions_table"
            );
            $diagnostics['form_submissions']['synced_count'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $submissions_table WHERE synced = 1"
            );
            $diagnostics['form_submissions']['unsynced_count'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $submissions_table WHERE synced = 0"
            );

            // Get counts by source
            $by_source = $wpdb->get_results(
                "SELECT source, COUNT(*) as count FROM $submissions_table GROUP BY source",
                ARRAY_A
            );
            foreach ($by_source as $row) {
                $diagnostics['form_submissions']['by_source'][$row['source']] = (int) $row['count'];
            }
        }

        // Check FormFlow
        $formflow_active = class_exists('ISF\\Plugin') || class_exists('FormFlow');
        $diagnostics['formflow']['active'] = $formflow_active;

        if ($formflow_active) {
            // Try to get FormFlow instance and submission counts
            $ff_instances_table = $wpdb->prefix . 'isf_instances';
            $ff_submissions_table = $wpdb->prefix . 'isf_submissions';

            $ff_instances_exists = $wpdb->get_var($wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $ff_instances_table
            ));

            if ($ff_instances_exists) {
                $diagnostics['formflow']['instances_count'] = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM $ff_instances_table"
                );
            }

            $ff_submissions_exists = $wpdb->get_var($wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $ff_submissions_table
            ));

            if ($ff_submissions_exists) {
                $diagnostics['formflow']['submissions_count'] = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM $ff_submissions_table"
                );
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => $diagnostics,
        ], 200);
    }

    /**
     * Test form sync from Hub
     *
     * Validates that form data can be properly received and stored.
     * Used by Hub API Tester to verify form sync is working.
     *
     * @param WP_REST_Request $request REST request object with test form data.
     * @return WP_REST_Response Response with validation results.
     */
    public function test_form_sync(WP_REST_Request $request): WP_REST_Response {
        $test_form = $request->get_json_params();

        $validation = [
            'received' => true,
            'parsed' => false,
            'schema_valid' => false,
            'can_store' => false,
            'errors' => [],
            'warnings' => [],
        ];

        // Check if we received valid JSON
        if (empty($test_form)) {
            $validation['errors'][] = 'No form data received or invalid JSON';
            return new WP_REST_Response([
                'success' => false,
                'data' => $validation,
            ], 400);
        }

        $validation['parsed'] = true;

        // Validate required schema fields
        $required_fields = ['id', 'slug', 'name', 'form_type', 'fields', 'version'];
        $missing_fields = [];

        foreach ($required_fields as $field) {
            if (!isset($test_form[$field])) {
                $missing_fields[] = $field;
            }
        }

        if (!empty($missing_fields)) {
            $validation['errors'][] = 'Missing required fields: ' . implode(', ', $missing_fields);
        } else {
            $validation['schema_valid'] = true;
        }

        // Validate field types
        if (isset($test_form['id']) && !is_numeric($test_form['id'])) {
            $validation['errors'][] = 'Field "id" must be numeric';
            $validation['schema_valid'] = false;
        }

        if (isset($test_form['slug']) && !is_string($test_form['slug'])) {
            $validation['errors'][] = 'Field "slug" must be a string';
            $validation['schema_valid'] = false;
        }

        if (isset($test_form['fields']) && !is_array($test_form['fields'])) {
            $validation['errors'][] = 'Field "fields" must be an array';
            $validation['schema_valid'] = false;
        }

        if (isset($test_form['version']) && !is_numeric($test_form['version'])) {
            $validation['errors'][] = 'Field "version" must be numeric';
            $validation['schema_valid'] = false;
        }

        // Check valid form types
        $valid_form_types = ['contact', 'lead', 'survey', 'enrollment', 'scheduler', 'custom'];
        if (isset($test_form['form_type']) && !in_array($test_form['form_type'], $valid_form_types, true)) {
            $validation['warnings'][] = 'Unknown form_type "' . $test_form['form_type'] . '". Expected one of: ' . implode(', ', $valid_form_types);
        }

        // Check if we can store (database table exists)
        global $wpdb;
        $hub_forms_table = $wpdb->prefix . 'peanut_connect_hub_forms';
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $hub_forms_table
        ));

        if ($table_exists) {
            $validation['can_store'] = true;
        } else {
            $validation['warnings'][] = 'Hub forms table does not exist. Form sync may not be set up yet.';
        }

        // Validate individual fields in the fields array
        if (isset($test_form['fields']) && is_array($test_form['fields'])) {
            $field_errors = [];
            $field_required = ['id', 'type', 'name', 'label'];

            foreach ($test_form['fields'] as $index => $field) {
                if (!is_array($field)) {
                    $field_errors[] = "Field at index $index is not an object";
                    continue;
                }

                $missing = [];
                foreach ($field_required as $req) {
                    if (!isset($field[$req])) {
                        $missing[] = $req;
                    }
                }

                if (!empty($missing)) {
                    $field_errors[] = "Field at index $index missing: " . implode(', ', $missing);
                }
            }

            if (!empty($field_errors)) {
                $validation['warnings'] = array_merge($validation['warnings'], $field_errors);
            }
        }

        // Return success based on whether schema is valid
        return new WP_REST_Response([
            'success' => $validation['schema_valid'],
            'data' => array_merge($validation, [
                'received_fields' => array_keys($test_form),
                'field_count' => isset($test_form['fields']) && is_array($test_form['fields'])
                    ? count($test_form['fields'])
                    : 0,
            ]),
        ], $validation['schema_valid'] ? 200 : 400);
    }

    // =====================
    // Event Banner endpoint callbacks (v3.3.0+)
    // =====================

    /**
     * Show event banner
     *
     * Receives banner data from Hub and activates it on the frontend.
     *
     * @param WP_REST_Request $request REST request object.
     * @return WP_REST_Response Response with success status.
     */
    public function show_event_banner(WP_REST_Request $request): WP_REST_Response {
        if (!class_exists('Peanut_Connect_Event_Banner')) {
            return new WP_REST_Response([
                'success' => false,
                'code' => 'module_not_available',
                'message' => 'Event banner module is not available.',
            ], 500);
        }

        $banner_data = [
            'deployment_id' => $request->get_param('deployment_id'),
            'event_id' => $request->get_param('event_id'),
            'html' => $request->get_param('html'),
            'css' => $request->get_param('css'),
            'position' => $request->get_param('position'),
            'show_at' => $request->get_param('show_at'),
            'hide_at' => $request->get_param('hide_at'),
            'is_test' => $request->get_param('is_test'),
        ];

        $result = Peanut_Connect_Event_Banner::set_banner($banner_data);

        if ($result) {
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Banner activated successfully.',
                'data' => Peanut_Connect_Event_Banner::get_status(),
            ], 200);
        }

        return new WP_REST_Response([
            'success' => false,
            'code' => 'banner_set_failed',
            'message' => 'Failed to activate banner.',
        ], 500);
    }

    /**
     * Hide event banner
     *
     * Clears the active banner from the frontend.
     *
     * @param WP_REST_Request $request REST request object.
     * @return WP_REST_Response Response with success status.
     */
    public function hide_event_banner(WP_REST_Request $request): WP_REST_Response {
        if (!class_exists('Peanut_Connect_Event_Banner')) {
            return new WP_REST_Response([
                'success' => false,
                'code' => 'module_not_available',
                'message' => 'Event banner module is not available.',
            ], 500);
        }

        $result = Peanut_Connect_Event_Banner::clear_banner();

        return new WP_REST_Response([
            'success' => true,
            'message' => $result ? 'Banner hidden successfully.' : 'No active banner to hide.',
            'data' => Peanut_Connect_Event_Banner::get_status(),
        ], 200);
    }

    /**
     * Get event banner status
     *
     * Returns the current banner status for Hub monitoring.
     *
     * @param WP_REST_Request $request REST request object.
     * @return WP_REST_Response Response with banner status.
     */
    public function get_banner_status(WP_REST_Request $request): WP_REST_Response {
        if (!class_exists('Peanut_Connect_Event_Banner')) {
            return new WP_REST_Response([
                'success' => false,
                'code' => 'module_not_available',
                'message' => 'Event banner module is not available.',
            ], 500);
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => Peanut_Connect_Event_Banner::get_status(),
        ], 200);
    }

    /**
     * Get event banner diagnostics
     *
     * Returns detailed diagnostics for the Hub API tester.
     *
     * @param WP_REST_Request $request REST request object.
     * @return WP_REST_Response Response with diagnostics information.
     */
    public function get_banner_diagnostics(WP_REST_Request $request): WP_REST_Response {
        if (!class_exists('Peanut_Connect_Event_Banner')) {
            return new WP_REST_Response([
                'success' => false,
                'code' => 'module_not_available',
                'message' => 'Event banner module is not available.',
                'data' => [
                    'module_active' => false,
                    'connect_version' => PEANUT_CONNECT_VERSION,
                ],
            ], 200);
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => Peanut_Connect_Event_Banner::get_diagnostics(),
        ], 200);
    }

    // =====================
    // Backup, Restore & Update endpoint callbacks (v3.4.0+)
    // =====================

    /**
     * Create a site backup (database + wp-content)
     *
     * @param WP_REST_Request $request REST request object.
     * @return WP_REST_Response Response with backup details or error.
     * @since 3.4.0
     */
    public function create_backup(WP_REST_Request $request): WP_REST_Response {
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-backup.php';

        $result = Peanut_Connect_Backup::create_backup([
            'type' => $request->get_param('type'),
            'storage_driver' => $request->get_param('storage_driver'),
        ]);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], 500);
        }

        Peanut_Connect_Activity_Log::log('backup_created', $result);

        return new WP_REST_Response($result, 200);
    }

    /**
     * Restore from a backup archive URL
     *
     * @param WP_REST_Request $request REST request object with backup_url parameter.
     * @return WP_REST_Response Response with restore result or error.
     * @since 3.4.0
     */
    public function restore_backup(WP_REST_Request $request): WP_REST_Response {
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-backup.php';

        $backup_url = (string) $request->get_param('backup_url');

        // v3.7.21: restrict the source host to the configured Hub. Without
        // this, the bearer-protected /restore endpoint accepts any URL and
        // executes its SQL dump + copies its files into wp-content — full
        // RCE-equivalent if the bearer ever leaks.
        $hub_url = (string) get_option('peanut_connect_hub_url', '');
        $hub_host = $hub_url !== '' ? strtolower((string) wp_parse_url($hub_url, PHP_URL_HOST)) : '';
        $url_host = strtolower((string) wp_parse_url($backup_url, PHP_URL_HOST));
        if ($hub_host === '' || $url_host === '' || $url_host !== $hub_host) {
            return new WP_REST_Response([
                'success' => false,
                'code' => 'peanut_connect_backup_url_not_allowed',
                'message' => 'Backup URL must be hosted on the configured Hub.',
            ], 400);
        }

        $result = Peanut_Connect_Backup::restore_backup($backup_url);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], 500);
        }

        Peanut_Connect_Activity_Log::log('backup_restored', $result);

        return new WP_REST_Response($result, 200);
    }

    /**
     * Unified update endpoint — update a plugin, theme, or WordPress core
     *
     * Delegates to the existing Peanut_Connect_Updates::perform_update() method
     * and runs a post-update health check.
     *
     * @param WP_REST_Request $request REST request with component_type, slug, and optional version.
     * @return WP_REST_Response Response with update result and health check.
     * @since 3.4.0
     */
    public function apply_update(WP_REST_Request $request): WP_REST_Response {
        $type = $request->get_param('component_type'); // plugin, theme, core
        $slug = $request->get_param('slug');

        // Use existing Updates class
        $result = Peanut_Connect_Updates::perform_update($type, $slug);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ], 400);
        }

        // Run health check after update
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-backup.php';
        $health = Peanut_Connect_Backup::health_check();

        Peanut_Connect_Activity_Log::log('update_applied', array_merge($result, ['health' => $health]));

        return new WP_REST_Response([
            'success' => true,
            'update' => $result,
            'health_check' => $health,
        ], 200);
    }
}
