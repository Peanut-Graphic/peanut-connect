<?php
/**
 * Peanut Connect Database
 *
 * Creates and manages database tables for visitor tracking and sync queue.
 *
 * @package Peanut_Connect
 * @since 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database class for tracking tables
 */
class Peanut_Connect_Database {

    /**
     * Database version
     */
    const DB_VERSION = '1.2.0';

    /**
     * Option name for DB version
     */
    const DB_VERSION_OPTION = 'peanut_connect_db_version';

    /**
     * Initialize database
     */
    public static function init(): void {
        add_action('plugins_loaded', [__CLASS__, 'check_db_version']);
    }

    /**
     * Check if database needs updating
     */
    public static function check_db_version(): void {
        $installed_version = get_option(self::DB_VERSION_OPTION);

        if ($installed_version !== self::DB_VERSION) {
            self::create_tables();
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
        }
    }

    /**
     * Create all tracking tables
     */
    public static function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Visitors table - tracks unique visitors
        $table_visitors = $wpdb->prefix . 'peanut_connect_visitors';
        $sql_visitors = "CREATE TABLE $table_visitors (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            visitor_id varchar(64) NOT NULL,
            email varchar(255) DEFAULT NULL,
            name varchar(255) DEFAULT NULL,
            first_seen_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            total_visits int UNSIGNED DEFAULT 1,
            total_pageviews int UNSIGNED DEFAULT 1,
            device_type varchar(20) DEFAULT NULL,
            browser varchar(50) DEFAULT NULL,
            os varchar(50) DEFAULT NULL,
            country varchar(2) DEFAULT NULL,
            city varchar(100) DEFAULT NULL,
            synced tinyint(1) DEFAULT 0,
            synced_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY visitor_id (visitor_id),
            KEY synced (synced),
            KEY email (email)
        ) $charset_collate;";
        dbDelta($sql_visitors);

        // Events table - tracks pageviews and interactions
        $table_events = $wpdb->prefix . 'peanut_connect_events';
        $sql_events = "CREATE TABLE $table_events (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            visitor_id varchar(64) NOT NULL,
            event_type varchar(50) NOT NULL,
            page_url text DEFAULT NULL,
            page_title varchar(255) DEFAULT NULL,
            referrer text DEFAULT NULL,
            utm_source varchar(100) DEFAULT NULL,
            utm_medium varchar(100) DEFAULT NULL,
            utm_campaign varchar(255) DEFAULT NULL,
            utm_term varchar(255) DEFAULT NULL,
            utm_content varchar(255) DEFAULT NULL,
            click_id varchar(36) DEFAULT NULL,
            metadata longtext DEFAULT NULL,
            occurred_at datetime NOT NULL,
            synced tinyint(1) DEFAULT 0,
            synced_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY visitor_id (visitor_id),
            KEY event_type (event_type),
            KEY synced (synced),
            KEY occurred_at (occurred_at),
            KEY click_id (click_id),
            KEY visitor_synced (visitor_id, synced)
        ) $charset_collate;";
        dbDelta($sql_events);

        // Attribution touches - tracks marketing touchpoints
        $table_touches = $wpdb->prefix . 'peanut_connect_touches';
        $sql_touches = "CREATE TABLE $table_touches (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            visitor_id varchar(64) NOT NULL,
            channel varchar(100) DEFAULT NULL,
            source varchar(100) DEFAULT NULL,
            medium varchar(100) DEFAULT NULL,
            campaign varchar(255) DEFAULT NULL,
            landing_page text DEFAULT NULL,
            referrer text DEFAULT NULL,
            touch_position int UNSIGNED DEFAULT 1,
            touched_at datetime NOT NULL,
            synced tinyint(1) DEFAULT 0,
            synced_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY visitor_id (visitor_id),
            KEY synced (synced),
            KEY touched_at (touched_at)
        ) $charset_collate;";
        dbDelta($sql_touches);

        // Conversions table
        $table_conversions = $wpdb->prefix . 'peanut_connect_conversions';
        $sql_conversions = "CREATE TABLE $table_conversions (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            visitor_id varchar(64) NOT NULL,
            type varchar(50) NOT NULL,
            value decimal(12,2) DEFAULT NULL,
            currency varchar(3) DEFAULT 'USD',
            customer_email varchar(255) DEFAULT NULL,
            customer_name varchar(255) DEFAULT NULL,
            order_id varchar(100) DEFAULT NULL,
            metadata longtext DEFAULT NULL,
            converted_at datetime NOT NULL,
            synced tinyint(1) DEFAULT 0,
            synced_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY visitor_id (visitor_id),
            KEY synced (synced),
            KEY type (type),
            KEY converted_at (converted_at)
        ) $charset_collate;";
        dbDelta($sql_conversions);

        // Popup interactions - tracks popup views/conversions
        $table_popup_interactions = $wpdb->prefix . 'peanut_connect_popup_interactions';
        $sql_popup_interactions = "CREATE TABLE $table_popup_interactions (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            popup_id bigint(20) UNSIGNED NOT NULL,
            visitor_id varchar(64) DEFAULT NULL,
            action varchar(20) NOT NULL,
            page_url text DEFAULT NULL,
            data longtext DEFAULT NULL,
            occurred_at datetime NOT NULL,
            synced tinyint(1) DEFAULT 0,
            synced_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY popup_id (popup_id),
            KEY synced (synced),
            KEY action (action)
        ) $charset_collate;";
        dbDelta($sql_popup_interactions);

        // Sync state - tracks last sync position
        $table_sync_state = $wpdb->prefix . 'peanut_connect_sync_state';
        $sql_sync_state = "CREATE TABLE $table_sync_state (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            data_type varchar(50) NOT NULL,
            last_synced_id bigint(20) UNSIGNED DEFAULT 0,
            last_synced_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY data_type (data_type)
        ) $charset_collate;";
        dbDelta($sql_sync_state);

        // Hub forms cache - stores forms synced from Hub
        $table_hub_forms = $wpdb->prefix . 'peanut_connect_hub_forms';
        $sql_hub_forms = "CREATE TABLE $table_hub_forms (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            hub_form_id bigint(20) UNSIGNED NOT NULL,
            slug varchar(255) NOT NULL,
            name varchar(255) NOT NULL,
            form_type varchar(50) DEFAULT 'contact',
            fields longtext NOT NULL,
            steps longtext DEFAULT NULL,
            settings longtext DEFAULT NULL,
            status varchar(20) DEFAULT 'active',
            version int DEFAULT 1,
            synced_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY hub_form_id (hub_form_id),
            KEY slug (slug),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql_hub_forms);

        // Form submissions - stores submissions from Hub forms and FormFlow
        $table_form_submissions = $wpdb->prefix . 'peanut_connect_form_submissions';
        $sql_form_submissions = "CREATE TABLE $table_form_submissions (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source varchar(20) NOT NULL,
            form_id bigint(20) UNSIGNED DEFAULT NULL,
            hub_form_id bigint(20) UNSIGNED DEFAULT NULL,
            formflow_instance_id bigint(20) UNSIGNED DEFAULT NULL,
            visitor_id varchar(64) DEFAULT NULL,
            submission_uuid varchar(36) NOT NULL,
            form_name varchar(255) DEFAULT NULL,
            data longtext NOT NULL,
            metadata longtext DEFAULT NULL,
            status varchar(50) DEFAULT 'submitted',
            submitted_at datetime NOT NULL,
            synced tinyint(1) DEFAULT 0,
            synced_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY submission_uuid (submission_uuid),
            KEY source (source),
            KEY synced (synced)
        ) $charset_collate;";
        dbDelta($sql_form_submissions);
    }

    /**
     * Drop all tables (for uninstall)
     */
    public static function drop_tables(): void {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'peanut_connect_visitors',
            $wpdb->prefix . 'peanut_connect_events',
            $wpdb->prefix . 'peanut_connect_touches',
            $wpdb->prefix . 'peanut_connect_conversions',
            $wpdb->prefix . 'peanut_connect_popup_interactions',
            $wpdb->prefix . 'peanut_connect_sync_state',
            $wpdb->prefix . 'peanut_connect_hub_forms',
            $wpdb->prefix . 'peanut_connect_form_submissions',
        ];

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        delete_option(self::DB_VERSION_OPTION);
    }

    /**
     * Get table name with prefix
     */
    public static function table(string $name): string {
        global $wpdb;
        return $wpdb->prefix . 'peanut_connect_' . $name;
    }

    /**
     * Clean old records (retention policy). Only deletes rows that have
     * already been synced to Hub. Visitors get a longer retention so that
     * the visitor row still exists when a delayed event/touch references it.
     *
     * v3.7.22: was previously only cleaning events/touches/popup_interactions.
     * conversions, visitors, and form_submissions accumulated forever.
     */
    public static function cleanup_old_records(int $days = 90): int {
        global $wpdb;

        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
        // Visitors are referenced by events/touches/conversions, so keep them
        // ~50% longer than the dependent rows to avoid orphan inserts.
        $visitor_cutoff = gmdate('Y-m-d H:i:s', strtotime('-' . ((int) ($days * 1.5)) . ' days'));
        $deleted = 0;

        // Per-table date column.
        $tables = [
            'events'             => 'occurred_at',
            'touches'            => 'touched_at',
            'popup_interactions' => 'occurred_at',
            'conversions'        => 'converted_at',
            'form_submissions'   => 'submitted_at',
        ];

        foreach ($tables as $table => $field) {
            $table_name = self::table($table);
            $count = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM $table_name WHERE synced = 1 AND $field < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $cutoff
                )
            );
            $deleted += (int) $count;
        }

        // Visitors: prune synced rows whose last_seen_at is older than the
        // longer cutoff. Joins to events/touches would be safer but expensive;
        // the visitor_cutoff buffer reduces the orphan-insert window.
        $visitors_table = self::table('visitors');
        $count = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $visitors_table WHERE synced = 1 AND last_seen_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $visitor_cutoff
            )
        );
        $deleted += (int) $count;

        return $deleted;
    }

    /**
     * Get unsynced record counts
     */
    public static function get_unsynced_counts(): array {
        global $wpdb;

        $counts = [];
        $tables = ['visitors', 'events', 'touches', 'conversions', 'popup_interactions', 'form_submissions'];

        foreach ($tables as $table) {
            $table_name = self::table($table);
            $counts[$table] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $table_name WHERE synced = 0" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            );
        }

        return $counts;
    }
}
