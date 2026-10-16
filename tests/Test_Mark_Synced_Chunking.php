<?php
/**
 * The backlog drain must be bounded.
 *
 * mark_non_campaign_data_synced() used four unbounded
 * `UPDATE ... SET synced = 1 WHERE synced = 0` statements. That was harmless
 * only because sync never actually ran: the `fifteen_minutes` recurrence was
 * registered after it was used, so `peanut_connect_hub_sync` was scheduled on
 * 0 of 17 installs. Fixing the schedule points those statements at six months
 * of backlog — 1.4M events and 1.37M visitors on the worst site, where EXPLAIN
 * showed the visitor statement doing a full table scan of 718,213 rows with
 * `key: NULL`.
 *
 * An unbounded UPDATE holds row locks across that whole set while the tracker
 * is still INSERTing into the same tables, which is the exact hazard
 * Database::CLEANUP_CHUNK_SIZE already exists to avoid on the DELETE side.
 *
 * @package Peanut_Connect
 */

/**
 * Minimal $wpdb stand-in: records the SQL it is handed and returns a
 * scripted row count per call so the loop's exit conditions can be driven.
 */
class Peanut_Test_Chunking_Wpdb {
    /** @var string[] */
    public array $queries = [];
    /** @var int[] */
    public array $returns = [];
    public int $calls = 0;

    public function prepare($sql, ...$args) {
        return str_replace('%s', "'" . (string) ($args[0] ?? '') . "'", $sql);
    }

    public function query($sql) {
        $this->queries[] = $sql;
        $i = $this->calls++;
        return $this->returns[$i] ?? 0;
    }
}

class Test_Mark_Synced_Chunking extends PHPUnit\Framework\TestCase {

    private function callMarkChunked(string $sql, string $now): int {
        $m = new ReflectionMethod('Peanut_Connect_Hub_Sync', 'mark_chunked');
        if (PHP_VERSION_ID < 80100) {
            $m->setAccessible(true);
        }
        return (int) $m->invoke(null, $sql, $now);
    }

    private function constant(string $name): int {
        return (int) constant('Peanut_Connect_Hub_Sync::' . $name);
    }

    protected function tearDown(): void {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    /**
     * Every statement must carry a LIMIT. Without it the UPDATE spans the
     * whole backlog in one lock.
     */
    public function test_every_statement_is_limited(): void {
        $db = new Peanut_Test_Chunking_Wpdb();
        $db->returns = [10]; // one short batch, loop ends
        $GLOBALS['wpdb'] = $db;

        $this->callMarkChunked('UPDATE t SET synced = 1, synced_at = %s WHERE synced = 0', '2026-09-07 00:00:00');

        $this->assertNotEmpty($db->queries);
        foreach ($db->queries as $q) {
            $this->assertStringContainsString(
                'LIMIT ' . $this->constant('MARK_CHUNK_SIZE'),
                $q,
                'a marking UPDATE went out without a LIMIT — that is the unbounded lock.'
            );
        }
    }

    /**
     * A short batch means the backlog is drained; stop rather than spin.
     */
    public function test_stops_on_short_batch(): void {
        $chunk = $this->constant('MARK_CHUNK_SIZE');
        $db = new Peanut_Test_Chunking_Wpdb();
        $db->returns = [$chunk, $chunk, 3];
        $GLOBALS['wpdb'] = $db;

        $total = $this->callMarkChunked('UPDATE t SET synced = 1, synced_at = %s WHERE synced = 0', 'now');

        $this->assertSame(3, $db->calls, 'should stop as soon as a batch comes back short');
        $this->assertSame($chunk * 2 + 3, $total);
    }

    /**
     * A huge backlog must not be drained in a single tick — it is deferred to
     * the next run, which is safe because the WHERE clause is self-advancing.
     */
    public function test_caps_work_per_run(): void {
        $chunk = $this->constant('MARK_CHUNK_SIZE');
        $max = $this->constant('MAX_MARK_BATCHES_PER_RUN');

        $db = new Peanut_Test_Chunking_Wpdb();
        // Always a full batch: simulates a backlog far bigger than one run.
        $db->returns = array_fill(0, $max + 25, $chunk);
        $GLOBALS['wpdb'] = $db;

        $total = $this->callMarkChunked('UPDATE t SET synced = 1, synced_at = %s WHERE synced = 0', 'now');

        $this->assertSame($max, $db->calls, 'must stop at MAX_MARK_BATCHES_PER_RUN, not drain everything in one tick');
        $this->assertSame($chunk * $max, $total);
    }

    /**
     * A failing statement returns false (cast to 0) and must end the loop
     * rather than retry forever.
     */
    public function test_stops_when_the_statement_errors(): void {
        $db = new Peanut_Test_Chunking_Wpdb();
        $db->returns = [0];
        $GLOBALS['wpdb'] = $db;

        $total = $this->callMarkChunked('UPDATE t SET synced = 1, synced_at = %s WHERE synced = 0', 'now');

        $this->assertSame(1, $db->calls);
        $this->assertSame(0, $total);
    }

    /**
     * No marking statement may bypass the chunked helper.
     */
    public function test_no_unbounded_marking_update_remains(): void {
        $src = file_get_contents(dirname(__DIR__) . '/includes/class-connect-hub-sync.php');
        $this->assertNotFalse($src);

        // Direct $wpdb->query() calls whose SQL marks rows by `synced = 0`.
        preg_match_all('/\$wpdb->query\((.*?)\n\s*\);/s', $src, $m);
        foreach ($m[1] as $call) {
            if (strpos($call, 'synced = 0') !== false) {
                $this->fail('a marking UPDATE still goes straight to $wpdb->query(); route it through mark_chunked().');
            }
        }
        $this->assertTrue(true);
    }
}
