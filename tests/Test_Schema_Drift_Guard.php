<?php
/**
 * Test_Schema_Drift_Guard — Fix D (2026-06-16 reliability audit).
 *
 * Formalizes, as a CI gate, the protection the runtime self-heal provides:
 * every column written via $wpdb->insert()/->update() to a peanut_connect
 * custom table MUST exist in that table's dbDelta CREATE schema.
 *
 * This is the static analogue of dominionenergyptr.com's runtime drift
 * incident — except it fails the build instead of failing INSERTs in prod.
 *
 * Connect has no current drift, so this test PASSES today; it exists to
 * catch a future "wrote a column we never added to the schema" mistake.
 */

class Test_Schema_Drift_Guard extends Peanut_Connect_TestCase {

    /** @var array<string,string[]> table-suffix => declared columns */
    private $schema = [];

    /** @var string[] absolute paths of include files to scan */
    private $sources = [];

    protected function setUp(): void {
        parent::setUp();
        $plugin_dir = dirname(__DIR__);
        $db_src = file_get_contents($plugin_dir . '/includes/class-connect-database.php');
        $this->schema = $this->parse_schema($db_src);
        $this->sources = glob($plugin_dir . '/includes/*.php');
    }

    /** Sanity: we actually parsed the known tables out of the schema. */
    public function test_schema_parsed(): void {
        foreach (['visitors', 'events', 'touches', 'conversions', 'popup_interactions', 'hub_forms', 'form_submissions'] as $t) {
            $this->assertArrayHasKey($t, $this->schema, "Failed to parse schema for table '{$t}'.");
            $this->assertNotEmpty($this->schema[$t], "No columns parsed for table '{$t}'.");
        }
    }

    /**
     * Every literal column key written via insert()/update() to a known
     * peanut_connect table must exist in that table's CREATE schema.
     */
    public function test_no_write_targets_undeclared_column(): void {
        $write_sites = $this->collect_write_sites();
        $this->assertNotEmpty($write_sites, 'Expected to find at least one insert/update write site.');

        foreach ($write_sites as $site) {
            $table = $site['table'];
            if (!isset($this->schema[$table])) {
                // Not a peanut_connect custom table we track — skip.
                continue;
            }
            foreach ($site['columns'] as $col) {
                $this->assertContains(
                    $col,
                    $this->schema[$table],
                    sprintf(
                        "Drift: column '%s' is written to table '%s' (%s) but is NOT declared in its dbDelta CREATE schema.",
                        $col,
                        $table,
                        $site['file']
                    )
                );
            }
        }
    }

    // ---------------- helpers ----------------

    /**
     * Parse `CREATE TABLE $table_xxx ( ... ) $charset_collate;` blocks into
     * a map of table-suffix => [column names].
     */
    private function parse_schema(string $src): array {
        $schema = [];
        if (!preg_match_all(
            '/CREATE TABLE \$table_(\w+) \((.+?)\) \$charset_collate;/s',
            $src,
            $blocks,
            PREG_SET_ORDER
        )) {
            return $schema;
        }
        foreach ($blocks as $b) {
            $suffix = $b[1];
            $body = $b[2];
            $cols = [];
            foreach (explode("\n", $body) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                // Skip key/constraint declarations.
                if (preg_match('/^(PRIMARY KEY|UNIQUE KEY|KEY|INDEX|CONSTRAINT|FULLTEXT)\b/i', $line)) {
                    continue;
                }
                // A column line starts with an identifier followed by a type.
                if (preg_match('/^([a-z_][a-z0-9_]*)\s+[a-z]/i', $line, $m)) {
                    $cols[] = $m[1];
                }
            }
            $schema[$suffix] = $cols;
        }
        return $schema;
    }

    /**
     * Scan source files for insert/update write sites and the literal
     * column keys they write. Resolves the target table by tracking the
     * nearest preceding `$table = ...::table('suffix');` assignment, and
     * resolves the column array whether it's inline or a `$data = [...]`
     * variable used in the call.
     *
     * @return array<int,array{file:string,table:string,columns:string[]}>
     */
    private function collect_write_sites(): array {
        $sites = [];

        foreach ($this->sources as $file) {
            $src = file_get_contents($file);

            // Map of variable-name => array of literal column keys, for
            // `$data = [ 'col' => ... ];` style payloads.
            $named_arrays = [];
            if (preg_match_all(
                '/\$(\w+)\s*=\s*\[(.*?)\];/s',
                $src,
                $arrs,
                PREG_SET_ORDER
            )) {
                foreach ($arrs as $a) {
                    $named_arrays['$' . $a[1]] = $this->extract_keys($a[2]);
                }
            }

            // Walk insert/update calls. Capture the first argument (table
            // expression) and the second argument up to a literal array or
            // a variable.
            if (!preg_match_all(
                '/\$wpdb->(?:insert|update)\(\s*([^,]+?)\s*,\s*(\[.*?\]|\$\w+)/s',
                $src,
                $calls,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE
            )) {
                continue;
            }

            foreach ($calls as $c) {
                $table_expr = trim($c[1][0]);
                $payload = trim($c[2][0]);
                $offset = $c[0][1];

                $table = $this->resolve_table($table_expr, $src, $offset);
                if ($table === null) {
                    continue;
                }

                if ($payload[0] === '[') {
                    $columns = $this->extract_keys(substr($payload, 1, -1));
                } else {
                    $columns = $named_arrays[$payload] ?? [];
                }

                if (empty($columns)) {
                    continue;
                }

                $sites[] = [
                    'file' => basename($file),
                    'table' => $table,
                    'columns' => $columns,
                ];
            }
        }

        return $sites;
    }

    /**
     * Resolve a table expression to a schema suffix. Handles a direct
     * `Peanut_Connect_Database::table('suffix')` call and a `$table`
     * variable assigned from one earlier in the same file (nearest
     * preceding assignment before $offset).
     */
    private function resolve_table(string $expr, string $src, int $offset): ?string {
        if (preg_match("/::table\(\s*'(\w+)'\s*\)/", $expr, $m)) {
            return $m[1];
        }
        if (preg_match('/^\$(\w+)$/', $expr, $vm)) {
            $var = $vm[1];
            $before = substr($src, 0, $offset);
            if (preg_match_all(
                '/\$' . preg_quote($var, '/') . '\s*=\s*Peanut_Connect_Database::table\(\s*\'(\w+)\'\s*\)/',
                $before,
                $assigns
            )) {
                return end($assigns[1]);
            }
        }
        return null;
    }

    /** Extract `'key' =>` literal keys from an array body. */
    private function extract_keys(string $body): array {
        $keys = [];
        if (preg_match_all("/'([a-z_][a-z0-9_]*)'\s*=>/i", $body, $m)) {
            $keys = $m[1];
        }
        return $keys;
    }
}
