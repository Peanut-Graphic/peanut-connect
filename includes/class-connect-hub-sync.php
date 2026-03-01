<?php
/**
 * Peanut Connect Hub Sync
 *
 * Handles syncing tracking data to the Peanut Hub SaaS platform.
 *
 * @package Peanut_Connect
 * @since 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hub sync class
 */
class Peanut_Connect_Hub_Sync {

    /**
     * Batch size for syncing
     */
    const BATCH_SIZE = 200;

    /**
     * Sync interval in minutes
     */
    const SYNC_INTERVAL = 15;

    /**
     * Initialize sync
     */
    public static function init(): void {
        // Register cron hook
        add_action('peanut_connect_sync_to_hub', [__CLASS__, 'run_sync']);

        // Register hook for Hub-requested immediate sync
        add_action('peanut_connect_sync_requested', [__CLASS__, 'run_sync']);

        // Schedule cron if not already
        if (!wp_next_scheduled('peanut_connect_sync_to_hub')) {
            wp_schedule_event(time(), 'peanut_fifteen_minutes', 'peanut_connect_sync_to_hub');
        }

        // Add custom cron interval
        add_filter('cron_schedules', [__CLASS__, 'add_cron_interval']);

        // REST endpoint for manual trigger
        add_action('rest_api_init', [__CLASS__, 'register_sync_endpoint']);
    }

    /**
     * Add custom cron interval
     */
    public static function add_cron_interval(array $schedules): array {
        $schedules['peanut_fifteen_minutes'] = [
            'interval' => self::SYNC_INTERVAL * 60,
            'display' => sprintf(__('Every %d minutes', 'peanut-connect'), self::SYNC_INTERVAL),
        ];
        return $schedules;
    }

    /**
     * Register REST endpoint for manual sync
     */
    public static function register_sync_endpoint(): void {
        register_rest_route('peanut-connect/v1', '/sync/trigger', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'handle_manual_sync'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('peanut-connect/v1', '/sync/status', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_sync_status'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);
    }

    /**
     * Handle manual sync trigger
     */
    public static function handle_manual_sync(\WP_REST_Request $request): \WP_REST_Response {
        $result = self::run_sync();

        return new \WP_REST_Response([
            'success' => $result['success'],
            'stats' => $result['stats'] ?? null,
            'message' => $result['message'] ?? null,
        ]);
    }

    /**
     * Get sync status
     */
    public static function get_sync_status(\WP_REST_Request $request): \WP_REST_Response {
        $unsynced = Peanut_Connect_Database::get_unsynced_counts();
        $last_sync = get_option('peanut_connect_last_hub_sync');
        $hub_url = get_option('peanut_connect_hub_url');
        $connected = !empty($hub_url) && !empty(get_option('peanut_connect_hub_api_key'));

        return new \WP_REST_Response([
            'connected' => $connected,
            'hub_url' => $hub_url,
            'last_sync' => $last_sync,
            'pending' => $unsynced,
            'total_pending' => array_sum($unsynced),
        ]);
    }

    /**
     * Run the sync process
     *
     * Only syncs campaign-related data (events with click_id from Hub links)
     * and the visitors associated with those events. This keeps sync fast
     * even on high-traffic sites with tens of thousands of visitors.
     */
    public static function run_sync(): array {
        $hub_url = get_option('peanut_connect_hub_url');
        $api_key = get_option('peanut_connect_hub_api_key');

        if (empty($hub_url) || empty($api_key)) {
            return [
                'success' => false,
                'message' => 'Hub not configured',
            ];
        }

        $stats = [
            'events' => 0,
            'visitors' => 0,
            'conversions' => 0,
            'form_submissions' => 0,
            'popup_interactions' => 0,
        ];

        try {
            // 1. Sync campaign events (events with click_id) — this is the core data
            $stats['events'] = self::sync_campaign_events($hub_url, $api_key);

            // 2. Sync visitors that are associated with campaign events
            $stats['visitors'] = self::sync_campaign_visitors($hub_url, $api_key);

            // 3. Sync popup interactions (usually small volume)
            $stats['popup_interactions'] = self::sync_popup_interactions($hub_url, $api_key);

            // 4. Sync form submissions (usually small volume)
            $stats['form_submissions'] = self::sync_form_submissions($hub_url, $api_key);

            // 5. Mark all non-campaign events as synced so they don't pile up
            self::mark_non_campaign_data_synced();

            // Update last sync time
            update_option('peanut_connect_last_hub_sync', current_time('mysql', true));

            Peanut_Connect_Activity_Log::log('hub_sync', 'success', 0, [
                'stats' => $stats,
            ]);

            return [
                'success' => true,
                'stats' => $stats,
            ];

        } catch (\Exception $e) {
            Peanut_Connect_Activity_Log::log('hub_sync', 'error', 0, [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Sync only events that have a click_id (came from Hub campaign links)
     */
    private static function sync_campaign_events(string $hub_url, string $api_key): int {
        global $wpdb;

        $table = Peanut_Connect_Database::table('events');
        $synced = 0;

        while (true) {
            $events = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table WHERE synced = 0 AND click_id IS NOT NULL AND click_id != '' ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    self::BATCH_SIZE
                ),
                ARRAY_A
            );

            if (empty($events)) {
                break;
            }

            $response = self::send_to_hub($hub_url, $api_key, ['events' => $events]);

            if ($response['success']) {
                $ids = array_column($events, 'id');
                self::mark_synced($table, $ids);
                $synced += count($events);
            } else {
                throw new \Exception('Failed to sync events: ' . ($response['message'] ?? 'Unknown error'));
            }
        }

        return $synced;
    }

    /**
     * Sync only visitors that are associated with campaign events (have click_id)
     */
    private static function sync_campaign_visitors(string $hub_url, string $api_key): int {
        global $wpdb;

        $visitors_table = Peanut_Connect_Database::table('visitors');
        $events_table = Peanut_Connect_Database::table('events');
        $synced = 0;

        while (true) {
            // Get unsynced visitors that have at least one event with a click_id
            $visitors = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT v.* FROM $visitors_table v
                     INNER JOIN $events_table e ON v.visitor_id = e.visitor_id
                     WHERE v.synced = 0 AND e.click_id IS NOT NULL AND e.click_id != ''
                     ORDER BY v.id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    self::BATCH_SIZE
                ),
                ARRAY_A
            );

            if (empty($visitors)) {
                break;
            }

            $response = self::send_to_hub($hub_url, $api_key, ['visitors' => $visitors]);

            if ($response['success']) {
                $ids = array_column($visitors, 'id');
                self::mark_synced($visitors_table, $ids);
                $synced += count($visitors);
            } else {
                throw new \Exception('Failed to sync visitors: ' . ($response['message'] ?? 'Unknown error'));
            }
        }

        return $synced;
    }

    /**
     * Sync popup interactions to hub
     */
    private static function sync_popup_interactions(string $hub_url, string $api_key): int {
        global $wpdb;

        $table = Peanut_Connect_Database::table('popup_interactions');
        $synced = 0;

        while (true) {
            $interactions = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table WHERE synced = 0 ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    self::BATCH_SIZE
                ),
                ARRAY_A
            );

            if (empty($interactions)) {
                break;
            }

            $response = self::send_to_hub($hub_url, $api_key, ['popup_interactions' => $interactions]);

            if ($response['success']) {
                $ids = array_column($interactions, 'id');
                self::mark_synced($table, $ids);
                $synced += count($interactions);
            } else {
                throw new \Exception('Failed to sync popup interactions: ' . ($response['message'] ?? 'Unknown error'));
            }
        }

        return $synced;
    }

    /**
     * Sync form submissions to hub
     */
    private static function sync_form_submissions(string $hub_url, string $api_key): int {
        if (!class_exists('Peanut_Connect_Forms')) {
            return 0;
        }

        $synced = 0;

        while (true) {
            $submissions = Peanut_Connect_Forms::get_unsynced_submissions(self::BATCH_SIZE);

            if (empty($submissions)) {
                break;
            }

            $formatted = [];
            foreach ($submissions as $sub) {
                $formatted[] = [
                    'submission_uuid' => $sub['submission_uuid'],
                    'source' => $sub['source'],
                    'hub_form_id' => $sub['hub_form_id'],
                    'form_id' => $sub['formflow_instance_id'] ? (string) $sub['formflow_instance_id'] : null,
                    'form_name' => $sub['form_name'],
                    'visitor_id' => $sub['visitor_id'],
                    'data' => $sub['data'],
                    'metadata' => $sub['metadata'],
                    'submitted_at' => $sub['submitted_at'],
                ];
            }

            $response = self::send_to_hub($hub_url, $api_key, ['form_submissions' => $formatted]);

            if ($response['success']) {
                $ids = array_column($submissions, 'id');
                Peanut_Connect_Forms::mark_submissions_synced($ids);
                $synced += count($submissions);
            } else {
                throw new \Exception('Failed to sync form submissions: ' . ($response['message'] ?? 'Unknown error'));
            }
        }

        return $synced;
    }

    /**
     * Mark non-campaign data as synced so it doesn't pile up forever.
     * Events without click_id and visitors not tied to campaigns get marked synced.
     */
    private static function mark_non_campaign_data_synced(): void {
        global $wpdb;

        $events_table = Peanut_Connect_Database::table('events');
        $visitors_table = Peanut_Connect_Database::table('visitors');
        $touches_table = Peanut_Connect_Database::table('touches');
        $conversions_table = Peanut_Connect_Database::table('conversions');
        $now = current_time('mysql', true);

        // Mark non-campaign events as synced
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $events_table SET synced = 1, synced_at = %s WHERE synced = 0 AND (click_id IS NULL OR click_id = '')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $now
            )
        );

        // Mark non-campaign visitors as synced
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $visitors_table SET synced = 1, synced_at = %s WHERE synced = 0 AND visitor_id NOT IN (
                    SELECT DISTINCT visitor_id FROM $events_table WHERE click_id IS NOT NULL AND click_id != ''
                )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $now
            )
        );

        // Mark all touches as synced (not needed for journey tracking)
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $touches_table SET synced = 1, synced_at = %s WHERE synced = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $now
            )
        );

        // Mark all conversions as synced (not needed for journey tracking)
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $conversions_table SET synced = 1, synced_at = %s WHERE synced = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $now
            )
        );
    }

    /**
     * Send data to hub
     */
    private static function send_to_hub(string $hub_url, string $api_key, array $data): array {
        $endpoint = rtrim($hub_url, '/') . '/api/v1/sync/push';

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => wp_json_encode($data),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code >= 200 && $status_code < 300 && ($body['success'] ?? false)) {
            return [
                'success' => true,
                'stats' => $body['stats'] ?? [],
            ];
        }

        return [
            'success' => false,
            'message' => $body['message'] ?? "HTTP $status_code",
        ];
    }

    /**
     * Mark records as synced
     */
    private static function mark_synced(string $table, array $ids): void {
        global $wpdb;

        if (empty($ids)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $now = current_time('mysql', true);

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table SET synced = 1, synced_at = %s WHERE id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
                array_merge([$now], $ids)
            )
        );
    }

    /**
     * Send heartbeat to hub
     */
    public static function send_heartbeat(): array {
        $hub_url = get_option('peanut_connect_hub_url');
        $api_key = get_option('peanut_connect_hub_api_key');

        if (empty($hub_url) || empty($api_key)) {
            return [
                'success' => false,
                'message' => 'Hub not configured',
            ];
        }

        $endpoint = rtrim($hub_url, '/') . '/api/v1/sync/heartbeat';

        $health = new Peanut_Connect_Health();

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => wp_json_encode([
                'health_data' => $health->get_health_data(),
                'connect_version' => PEANUT_CONNECT_VERSION,
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code >= 200 && $status_code < 300 && ($body['success'] ?? false)) {
            // Store active popups if returned
            if (!empty($body['popups'])) {
                update_option('peanut_connect_hub_popups', $body['popups']);
            }

            // Check if Hub requested an immediate sync
            $syncNow = $body['sync_now'] ?? false;
            if ($syncNow) {
                // Trigger sync immediately (async-style to avoid blocking)
                // Use wp_schedule_single_event to run sync in the background
                if (!wp_next_scheduled('peanut_connect_sync_requested')) {
                    wp_schedule_single_event(time(), 'peanut_connect_sync_requested');
                }
            }

            return [
                'success' => true,
                'sync_enabled' => $body['sync_enabled'] ?? true,
                'sync_now' => $syncNow,
                'popups' => $body['popups'] ?? [],
            ];
        }

        return [
            'success' => false,
            'message' => $body['message'] ?? "HTTP $status_code",
        ];
    }

    /**
     * Verify hub connection
     */
    public static function verify_hub_connection(string $hub_url, string $api_key): array {
        $endpoint = rtrim($hub_url, '/') . '/api/v1/sites/verify';

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code >= 200 && $status_code < 300 && ($body['success'] ?? false)) {
            return [
                'success' => true,
                'site' => $body['site'] ?? [],
                'client' => $body['client'] ?? [],
                'agency' => $body['agency'] ?? [],
            ];
        }

        return [
            'success' => false,
            'message' => $body['message'] ?? "HTTP $status_code",
        ];
    }

    /**
     * Fetch active popups from Hub via GET request
     *
     * @return array Array of popups or empty array on failure
     */
    public static function fetch_popups(): array {
        $hub_url = get_option('peanut_connect_hub_url');
        $api_key = get_option('peanut_connect_hub_api_key');

        if (empty($hub_url) || empty($api_key)) {
            return [];
        }

        $endpoint = rtrim($hub_url, '/') . '/api/v1/popups/active';

        $response = wp_remote_get($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Accept' => 'application/json',
            ],
            'timeout' => 10,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code >= 200 && $status_code < 300 && ($body['success'] ?? false)) {
            $popups = $body['popups'] ?? [];
            // Cache popups for future use
            update_option('peanut_connect_hub_popups', $popups);
            return $popups;
        }

        return [];
    }

    /**
     * Unschedule sync cron
     */
    public static function unschedule(): void {
        $timestamp = wp_next_scheduled('peanut_connect_sync_to_hub');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'peanut_connect_sync_to_hub');
        }
    }
}
