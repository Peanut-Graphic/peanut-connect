<?php
/**
 * EventNamePersistenceTest — protects the 3.9.4 fix.
 *
 * Before 3.9.4, tracker.js sent `event_name` with every event but the
 * server's API ingest dropped it and the database schema had no column
 * for it. The Hub funnel couldn't distinguish click_to_portal from any
 * other custom event and stages stayed at 0.
 *
 * These tests pin the contracts so a future refactor can't silently
 * regress them:
 *   1. The events table schema declares an `event_name` column.
 *   2. `record_event` accepts an `event_name` key in $data and persists it.
 *   3. `record_event` canonicalizes `page_view` → `pageview`.
 *   4. The /track REST endpoint forwards `event_name` from the request.
 *   5. The backfill migration matches click_to_portal-shaped rows.
 */

class Test_Event_Name_Persistence extends Peanut_Connect_TestCase
{
    /** @var string */
    private $plugin_dir;

    /** @var string */
    private $tracker_src;

    /** @var string */
    private $api_src;

    /** @var string */
    private $db_src;

    /** @var string */
    private $js_src;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plugin_dir = dirname(__DIR__);
        $this->tracker_src = file_get_contents($this->plugin_dir . '/includes/class-connect-tracker.php');
        $this->api_src     = file_get_contents($this->plugin_dir . '/includes/class-connect-api.php');
        $this->db_src      = file_get_contents($this->plugin_dir . '/includes/class-connect-database.php');
        $this->js_src      = file_get_contents($this->plugin_dir . '/assets/js/tracker.js');
    }

    /**
     * Schema: the events table CREATE TABLE must declare `event_name`.
     */
    public function test_events_schema_declares_event_name_column(): void
    {
        // Find the events table CREATE TABLE block, then scan it for
        // the event_name column declaration.
        $matches = [];
        $found = preg_match(
            '/CREATE TABLE \$table_events (.+?)\) \$charset_collate;/s',
            $this->db_src,
            $matches
        );
        $this->assertSame(1, $found, 'Could not locate events CREATE TABLE block.');
        $this->assertMatchesRegularExpression(
            '/event_name\s+varchar\(\d+\)/i',
            $matches[1],
            'events schema must declare `event_name varchar(N)` column. Hub funnel classification depends on it.'
        );
    }

    /**
     * Schema must have an index on event_name (Hub queries filter by it).
     */
    public function test_events_schema_indexes_event_name(): void
    {
        $this->assertMatchesRegularExpression(
            '/KEY\s+event_name\s*\(event_name\)/',
            $this->db_src,
            'event_name needs an index — Hub aggregation queries filter by it.'
        );
    }

    /**
     * DB_VERSION must be bumped so existing installs trigger the
     * dbDelta column-add + backfill on next plugin load.
     */
    public function test_db_version_bumped_to_at_least_1_3_0(): void
    {
        $matches = [];
        $found = preg_match("/const DB_VERSION\s*=\s*'(\d+)\.(\d+)\.(\d+)'/", $this->db_src, $matches);
        $this->assertSame(1, $found, 'DB_VERSION constant not found.');
        $version = (int) $matches[1] * 10000 + (int) $matches[2] * 100 + (int) $matches[3];
        $this->assertGreaterThanOrEqual(
            10300,
            $version,
            'DB_VERSION must be at least 1.3.0 so existing installs run the backfill.'
        );
    }

    /**
     * record_event must accept event_name via the $data array and pass
     * it to the INSERT statement.
     */
    public function test_record_event_persists_event_name_from_data(): void
    {
        $this->assertMatchesRegularExpression(
            "/\\\$event_name\s*=\s*isset\(\\\$data\['event_name'\]\)/",
            $this->tracker_src,
            'record_event must read event_name from $data.'
        );
        $this->assertMatchesRegularExpression(
            "/'event_name'\s*=>\s*\\\$event_name/",
            $this->tracker_src,
            'record_event must include event_name in the wpdb->insert payload.'
        );
    }

    /**
     * record_event must canonicalize page_view (old camelCase) →
     * pageview (canonical). Until 3.9.4 both were stored side-by-side
     * because tracker.js emitted page_view and PHP emitted pageview.
     */
    public function test_record_event_canonicalizes_page_view_to_pageview(): void
    {
        $this->assertMatchesRegularExpression(
            "/\\\$event_type\s*===\s*'page_view'/",
            $this->tracker_src,
            'record_event must detect the page_view alias.'
        );
        $this->assertMatchesRegularExpression(
            "/\\\$event_type\s*=\s*'pageview'/",
            $this->tracker_src,
            'record_event must normalize page_view to pageview.'
        );
    }

    /**
     * REST /track ingest must forward event_name from the request to
     * Peanut_Connect_Tracker::record_event.
     */
    public function test_track_endpoint_forwards_event_name(): void
    {
        $this->assertMatchesRegularExpression(
            "/'event_name'\s*=>\s*\\\$request->get_param\(\s*'event_name'\s*\)/",
            $this->api_src,
            "track_event handler must read event_name from the request and forward it to record_event."
        );
    }

    /**
     * The /track route definition must declare event_name as an
     * accepted argument so it gets sanitized.
     */
    public function test_track_route_declares_event_name_arg(): void
    {
        $matches = [];
        $found = preg_match(
            "/register_rest_route\(PEANUT_CONNECT_API_NAMESPACE,\s*'\\/track'.*?'args'\s*=>\s*\[(.*?)^\s*\]\s*\]/sm",
            $this->api_src,
            $matches
        );
        $this->assertSame(1, $found, 'Could not locate /track route definition.');
        $this->assertStringContainsString(
            "'event_name'",
            $matches[1],
            '/track route must declare event_name as an arg for sanitization.'
        );
    }

    /**
     * Backfill must run on upgrade-install from pre-1.3.0.
     */
    public function test_backfill_runs_on_upgrade_from_pre_1_3_0(): void
    {
        $this->assertMatchesRegularExpression(
            '/function\s+backfill_event_names/',
            $this->db_src,
            'backfill_event_names method missing.'
        );
        $this->assertMatchesRegularExpression(
            "/version_compare\(\(string\)\s*\\\$previous_db_version,\s*'1\.3\.0',\s*'>='\)/",
            $this->db_src,
            'backfill must gate on previous version being below 1.3.0 so it runs exactly once.'
        );
    }

    /**
     * Backfill must identify click_to_portal events by metadata shape
     * AND primary-CTA text match (mirrors tracker.js's emission rule).
     */
    public function test_backfill_matches_click_to_portal_shape_and_text(): void
    {
        // The backfill query must:
        //   - target event_type='custom' AND event_name IS NULL
        //   - assert metadata has the click_to_portal shape
        //   - match the text against the primary-CTA regex
        $this->assertMatchesRegularExpression(
            "/event_type\s*=\s*'custom'/",
            $this->db_src,
            'Backfill must target custom events.'
        );
        $this->assertMatchesRegularExpression(
            '/event_name\s+IS\s+NULL/i',
            $this->db_src,
            'Backfill must only touch rows without an existing event_name (idempotent).'
        );
        // JSON_EXTRACT path uses MySQL's $-rooted notation. Match the
        // call shape without being strict about the exact path syntax.
        $this->assertMatchesRegularExpression(
            "/JSON_UNQUOTE\(JSON_EXTRACT\(metadata,\s*'\\\$\\.text'\)\)/",
            $this->db_src,
            'Backfill must extract the text key from metadata to match the regex.'
        );

        // Regex must include at least the canonical primary-CTA verbs
        // — enroll, apply, register, sign-up.
        foreach (['enroll', 'apply', 'register', 'sign'] as $verb) {
            $this->assertStringContainsString(
                $verb,
                $this->db_src,
                "Backfill regex missing primary-CTA verb '{$verb}'."
            );
        }
    }

    /**
     * Backfill must also normalize page_view → pageview in existing data.
     */
    public function test_backfill_normalizes_page_view_event_type(): void
    {
        $this->assertMatchesRegularExpression(
            "/event_type\s*=\s*'pageview'\s+WHERE\s+event_type\s*=\s*'page_view'/i",
            $this->db_src,
            'Backfill must UPDATE existing page_view rows to canonical pageview.'
        );
    }

    /**
     * tracker.js must emit canonical 'pageview' going forward so we
     * don't accumulate more 'page_view' rows after the backfill runs.
     */
    public function test_tracker_js_emits_canonical_pageview(): void
    {
        $this->assertMatchesRegularExpression(
            "/trackEvent\(\s*'pageview'/",
            $this->js_src,
            'tracker.js must emit pageview (canonical) — not page_view.'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/trackEvent\(\s*'page_view'/",
            $this->js_src,
            'tracker.js must not still emit page_view (would split the funnel again).'
        );
    }
}
