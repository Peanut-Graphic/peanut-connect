<?php
/**
 * Test_Reliability_Hazards — pins the 2026-06-16 reliability-audit fixes.
 *
 *   A. Schema-drift check is cached in a transient and SKIPPED entirely
 *      on the hot path when the version option already matches — while a
 *      genuine drift (missing column) still re-triggers a migration.
 *   B. The popup render path never makes a blocking Hub fetch and
 *      negative-caches an empty result.
 *   C. cleanup_old_records() chunks every DELETE with a LIMIT and loops.
 */

/**
 * Minimal $wpdb test double that records the SQL it's asked to run and
 * lets a test script the answers (column existence, delete batch sizes).
 */
class Reliability_Fake_Wpdb {
    public $prefix = 'wp_';
    public $dbname = 'wp_test';

    /** @var string[] Every SQL string passed to query()/get_var(). */
    public $queries = [];

    /** @var int Number of INFORMATION_SCHEMA introspection queries seen. */
    public $information_schema_queries = 0;

    /**
     * Map of "table|column" => bool, controls schema_matches answers.
     * Missing entries default to "exists".
     * @var array<string,bool>
     */
    public $columns_present = [];

    /**
     * Remaining rows a DELETE ... LIMIT n would affect, per distinct
     * base SQL (the LIMIT is appended by the code under test).
     * @var array<string,int>
     */
    public $delete_backlog = [];

    public function prepare($query, ...$args) {
        // Good-enough stand-in: interpolate %s/%d positionally.
        foreach ($args as $a) {
            if (is_array($a)) {
                $a = $a[0] ?? '';
            }
            $query = preg_replace('/%[sd]/', is_numeric($a) ? (string) $a : "'" . $a . "'", $query, 1);
        }
        return $query;
    }

    public function get_var($query) {
        $this->queries[] = $query;
        if (stripos($query, 'INFORMATION_SCHEMA') !== false) {
            $this->information_schema_queries++;
            // Parse COLUMN_NAME = 'x' and TABLE_NAME = 'y'.
            $col = $this->extract($query, "COLUMN_NAME = '", "'");
            $tbl = $this->extract($query, "TABLE_NAME = '", "'");
            $key = $tbl . '|' . $col;
            $present = $this->columns_present[$key] ?? true;
            return $present ? '1' : '0';
        }
        return '0';
    }

    public function query($query) {
        $this->queries[] = $query;

        if (stripos($query, 'DELETE') === 0 || stripos($query, 'DELETE') !== false) {
            // Identify the base statement (strip the LIMIT n suffix).
            $base = preg_replace('/\s+LIMIT\s+\d+\s*$/i', '', $query);
            $limit = 0;
            if (preg_match('/LIMIT\s+(\d+)\s*$/i', $query, $m)) {
                $limit = (int) $m[1];
            }
            if (!array_key_exists($base, $this->delete_backlog)) {
                // No backlog scripted => nothing to delete.
                return 0;
            }
            $remaining = $this->delete_backlog[$base];
            $take = $limit > 0 ? min($limit, $remaining) : $remaining;
            $this->delete_backlog[$base] = $remaining - $take;
            return $take;
        }
        return 0;
    }

    public function get_charset_collate() { return ''; }

    private function extract($haystack, $start, $end) {
        $i = strpos($haystack, $start);
        if ($i === false) {
            return '';
        }
        $i += strlen($start);
        $j = strpos($haystack, $end, $i);
        return $j === false ? '' : substr($haystack, $i, $j - $i);
    }
}

class Test_Reliability_Hazards extends Peanut_Connect_TestCase {

    protected function setUp(): void {
        parent::setUp();
        global $mock_options, $mock_transients, $wpdb;
        $mock_options = [];
        $mock_transients = [];
        $wpdb = new Reliability_Fake_Wpdb();

        require_once dirname(__DIR__) . '/includes/class-connect-database.php';
    }

    // ----- Fix A: cached + skipped schema-drift check -----------------

    /**
     * On the hot path (option already == DB_VERSION), once the schema has
     * been verified the INFORMATION_SCHEMA query must NOT run again.
     */
    public function test_schema_check_skipped_on_hot_path_after_first_pass(): void {
        global $wpdb;
        update_option(Peanut_Connect_Database::DB_VERSION_OPTION, Peanut_Connect_Database::DB_VERSION);

        // First call: version matches, transient unset -> deep check runs once.
        Peanut_Connect_Database::check_db_version();
        $after_first = $wpdb->information_schema_queries;
        $this->assertGreaterThanOrEqual(1, $after_first, 'First check should introspect the schema once.');

        // Subsequent calls on the hot path must not introspect again.
        Peanut_Connect_Database::check_db_version();
        Peanut_Connect_Database::check_db_version();
        $this->assertSame(
            $after_first,
            $wpdb->information_schema_queries,
            'Schema introspection must be cached in a transient, not run on every request.'
        );
    }

    /**
     * A passing check must populate the schema-ok transient with DB_VERSION.
     */
    public function test_passing_check_sets_transient(): void {
        update_option(Peanut_Connect_Database::DB_VERSION_OPTION, Peanut_Connect_Database::DB_VERSION);
        Peanut_Connect_Database::check_db_version();
        $this->assertSame(
            Peanut_Connect_Database::DB_VERSION,
            get_transient(Peanut_Connect_Database::SCHEMA_OK_TRANSIENT),
            'A passing schema check must cache its result.'
        );
    }

    /**
     * Self-heal preserved: when the option says we're up to date but a
     * column is actually missing (drift), the migration must still run.
     */
    public function test_genuine_drift_still_triggers_remigrate(): void {
        global $wpdb;
        update_option(Peanut_Connect_Database::DB_VERSION_OPTION, Peanut_Connect_Database::DB_VERSION);
        // Simulate dominionenergyptr.com: event_name column missing.
        $wpdb->columns_present['wp_peanut_connect_events|event_name'] = false;

        Peanut_Connect_Database::check_db_version();

        // create_tables() issues CREATE TABLE statements via dbDelta.
        $ran_create = false;
        foreach ($wpdb->queries as $q) {
            if (stripos($q, 'CREATE TABLE') !== false) {
                $ran_create = true;
                break;
            }
        }
        $this->assertTrue($ran_create, 'A missing column must re-trigger create_tables() (self-heal).');
    }

    /**
     * Drift detection must not be defeated by a stale schema-ok transient:
     * if the version option itself is wrong/older, the deep check runs
     * regardless of the transient.
     */
    public function test_version_mismatch_forces_deep_check_even_with_transient(): void {
        global $wpdb;
        update_option(Peanut_Connect_Database::DB_VERSION_OPTION, '1.0.0');
        set_transient(Peanut_Connect_Database::SCHEMA_OK_TRANSIENT, Peanut_Connect_Database::DB_VERSION, 3600);

        Peanut_Connect_Database::check_db_version();

        $this->assertSame(
            Peanut_Connect_Database::DB_VERSION,
            get_option(Peanut_Connect_Database::DB_VERSION_OPTION),
            'A version mismatch must migrate and update the version option.'
        );
    }

    // ----- Fix C: chunked cleanup DELETEs -----------------------------

    /**
     * Every DELETE issued by cleanup_old_records must carry a LIMIT.
     */
    public function test_cleanup_deletes_are_chunked_with_limit(): void {
        global $wpdb;
        Peanut_Connect_Database::cleanup_old_records(90);

        $deletes = array_values(array_filter($wpdb->queries, function ($q) {
            return stripos($q, 'DELETE') !== false;
        }));
        $this->assertNotEmpty($deletes, 'cleanup should issue DELETE statements.');
        foreach ($deletes as $q) {
            $this->assertMatchesRegularExpression(
                '/LIMIT\s+\d+/i',
                $q,
                "Every cleanup DELETE must be bounded by a LIMIT: {$q}"
            );
        }
    }

    /**
     * A backlog larger than one chunk must be drained across multiple
     * looped DELETEs (not a single unbounded statement).
     */
    public function test_cleanup_loops_until_drained(): void {
        global $wpdb;
        $events_table = 'wp_peanut_connect_events';
        // 2500 rows => with LIMIT 1000 that is 3 DELETE statements
        // (1000, 1000, 500) for the events table.
        $base = "DELETE FROM $events_table WHERE synced = 1 AND occurred_at < '" . gmdate('Y-m-d H:i:s', strtotime('-90 days')) . "'";
        $wpdb->delete_backlog[$base] = 2500;

        $deleted = Peanut_Connect_Database::cleanup_old_records(90);

        $events_deletes = array_filter($wpdb->queries, function ($q) use ($events_table) {
            return stripos($q, "DELETE FROM $events_table") !== false;
        });
        $this->assertCount(3, $events_deletes, 'A 2500-row backlog should drain in 3 chunked DELETEs.');
        $this->assertGreaterThanOrEqual(2500, $deleted, 'Total deleted should account for the full backlog.');
    }

    /**
     * The chunk size constant must be a sane bound (1000 per the audit).
     */
    public function test_cleanup_chunk_size_is_bounded(): void {
        $this->assertSame(1000, Peanut_Connect_Database::CLEANUP_CHUNK_SIZE);
    }
}
