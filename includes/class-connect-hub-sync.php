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
     * Maximum batches per cron tick (per record type) before we bail out
     * and defer the remainder to the next run. 50 × 200 = 10,000 rows
     * worth of headroom per record type per tick — generous, but bounded
     * so a backlog can't trigger a PHP timeout that leaves data in a
     * half-synced state.
     */
    const MAX_BATCHES_PER_RUN = 50;

    /**
     * Sync interval in minutes
     */
    const SYNC_INTERVAL = 15;

    /**
     * Deprecated: superseded by hooks in peanut-connect.php main file.
     * Kept as a no-op for safety in case any caller still references it.
     *
     * Cron scheduling now happens exclusively in Peanut_Connect::init_hooks()
     * under the 'peanut_connect_hub_sync' hook. The old
     * 'peanut_connect_sync_to_hub' schedule is cleaned up at plugin load
     * (see peanut_connect_cleanup_stale_schedules() in peanut-connect.php).
     */
    public static function init(): void {
        // Intentionally empty.
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

    // Removed in 3.7.20: register_sync_endpoint(), handle_manual_sync(),
    // and get_sync_status() registered /sync/trigger and /sync/status but
    // were never wired to rest_api_init. The SPA uses /settings/hub/sync
    // (class-connect-api.php) instead. Dead routes that never existed at
    // runtime.

    /**
     * Run the sync process
     *
     * Only syncs campaign-related data (events with click_id from Hub links)
     * and the visitors associated with those events. This keeps sync fast
     * even on high-traffic sites with tens of thousands of visitors.
     */
    public static function run_sync(): array {
        $hub_url = get_option('peanut_connect_hub_url');
        $api_key = Peanut_Connect_Auth::get_hub_api_key();

        if (empty($hub_url) || empty($api_key)) {
            return [
                'success' => false,
                'message' => __('Hub not configured', 'peanut-connect'),
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
    /**
     * Shared batched-sync driver. Fetches up to BATCH_SIZE rows via
     * $fetcher, optionally transforms them via $formatter, POSTs to Hub
     * keyed by $payload_key, then calls $marker with the synced row ids.
     * Bails after MAX_BATCHES_PER_RUN batches per cron tick.
     *
     * @param callable      $fetcher   fn(int $size): array<int, array<string,mixed>> rows (each must include 'id')
     * @param callable      $marker    fn(array<int,int> $ids): void
     * @param ?callable     $formatter Optional fn(array $rows): array transform
     * @since 3.7.22
     */
    private static function sync_in_batches(
        string $hub_url,
        string $api_key,
        string $payload_key,
        string $error_label,
        callable $fetcher,
        callable $marker,
        ?callable $formatter = null
    ): int {
        $synced = 0;
        $batches = 0;

        while ($batches < self::MAX_BATCHES_PER_RUN) {
            $rows = $fetcher(self::BATCH_SIZE);
            if (empty($rows)) {
                break;
            }

            $payload = $formatter ? $formatter($rows) : $rows;
            $response = self::send_to_hub($hub_url, $api_key, [$payload_key => $payload]);

            if (!$response['success']) {
                throw new \Exception(
                    sprintf(
                        /* translators: 1: record type label (events, visitors, etc.); 2: error message */
                        __('Failed to sync %1$s: %2$s', 'peanut-connect'),
                        $error_label,
                        $response['message'] ?? __('Unknown error', 'peanut-connect')
                    )
                );
            }

            $marker(array_column($rows, 'id'));
            $synced += count($rows);
            $batches++;
        }

        return $synced;
    }

    private static function sync_campaign_events(string $hub_url, string $api_key): int {
        global $wpdb;
        $table = Peanut_Connect_Database::table('events');

        return self::sync_in_batches(
            $hub_url, $api_key, 'events', 'events',
            fn(int $size) => $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table WHERE synced = 0 AND click_id IS NOT NULL AND click_id != '' ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $size
                ),
                ARRAY_A
            ),
            fn(array $ids) => self::mark_synced($table, $ids),
        );
    }

    /**
     * Sync only visitors that are associated with campaign events (have click_id)
     */
    private static function sync_campaign_visitors(string $hub_url, string $api_key): int {
        global $wpdb;
        $visitors_table = Peanut_Connect_Database::table('visitors');
        $events_table = Peanut_Connect_Database::table('events');

        return self::sync_in_batches(
            $hub_url, $api_key, 'visitors', 'visitors',
            fn(int $size) => $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT v.* FROM $visitors_table v
                     INNER JOIN $events_table e ON v.visitor_id = e.visitor_id
                     WHERE v.synced = 0 AND e.click_id IS NOT NULL AND e.click_id != ''
                     ORDER BY v.id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $size
                ),
                ARRAY_A
            ),
            fn(array $ids) => self::mark_synced($visitors_table, $ids),
        );
    }

    /**
     * Sync popup interactions to hub
     */
    private static function sync_popup_interactions(string $hub_url, string $api_key): int {
        global $wpdb;
        $table = Peanut_Connect_Database::table('popup_interactions');

        return self::sync_in_batches(
            $hub_url, $api_key, 'popup_interactions', 'popup interactions',
            fn(int $size) => $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table WHERE synced = 0 ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $size
                ),
                ARRAY_A
            ),
            fn(array $ids) => self::mark_synced($table, $ids),
        );
    }

    /**
     * Sync form submissions to hub
     */
    private static function sync_form_submissions(string $hub_url, string $api_key): int {
        if (!class_exists('Peanut_Connect_Forms')) {
            return 0;
        }

        return self::sync_in_batches(
            $hub_url, $api_key, 'form_submissions', 'form submissions',
            fn(int $size) => Peanut_Connect_Forms::get_unsynced_submissions($size),
            fn(array $ids) => Peanut_Connect_Forms::mark_submissions_synced($ids),
            // form_submissions need a column transform before send_to_hub.
            fn(array $rows) => array_map(static fn(array $sub) => [
                'submission_uuid' => $sub['submission_uuid'],
                'source' => $sub['source'],
                'hub_form_id' => $sub['hub_form_id'],
                'form_id' => $sub['formflow_instance_id'] ? (string) $sub['formflow_instance_id'] : null,
                'form_name' => $sub['form_name'],
                'visitor_id' => $sub['visitor_id'],
                'data' => $sub['data'],
                'metadata' => $sub['metadata'],
                'submitted_at' => $sub['submitted_at'],
            ], $rows),
        );
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
        $api_key = Peanut_Connect_Auth::get_hub_api_key();

        if (empty($hub_url) || empty($api_key)) {
            return [
                'success' => false,
                'message' => __('Hub not configured', 'peanut-connect'),
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

            // Store the public JS-tracker key (NOT the Hub bearer) so the
            // tracker snippet can use it. Hub sends it on every heartbeat;
            // null when JS tracking is disabled.
            if (array_key_exists('tracker_key', $body)) {
                update_option('peanut_connect_tracker_key', sanitize_text_field((string) ($body['tracker_key'] ?? '')), false);
            }
            if (array_key_exists('js_tracker_enabled', $body)) {
                update_option('peanut_connect_js_tracker_enabled', !empty($body['js_tracker_enabled']), false);
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
        $api_key = Peanut_Connect_Auth::get_hub_api_key();

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

    // Removed in 3.7.20: unschedule() only cleared the legacy
    // peanut_connect_sync_to_hub hook (dead since 3.7.17). Real cleanup
    // lives in the deactivation hook in peanut-connect.php.
}
