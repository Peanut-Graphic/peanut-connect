<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('PEANUT_CONNECT_PLUGIN_DIR')) {
    define('PEANUT_CONNECT_PLUGIN_DIR', dirname(__DIR__) . '/');
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}

// The export writes a small header from the WordPress environment. Only these
// three are reached before the table walk this test is about.
if (!function_exists('current_time')) {
    function current_time(string $type, int $gmt = 0): string { return '2026-08-24 00:00:00'; }
}
if (!function_exists('get_site_url')) {
    function get_site_url(): string { return 'https://example.test'; }
}
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string { return $text; }
}

require_once dirname(__DIR__) . '/includes/class-connect-backup.php';

/**
 * Both halves of the build must report progress.
 *
 * 3.37.2 heartbeat only while zipping. On a real site the database export is
 * the bigger half — peanutgraphic.com writes a 276 MB .sql before a single
 * file is zipped — so a long export still looked exactly like a dead worker
 * and would be reaped as one. The blind spot had been moved, not closed;
 * observed live on the canary, where progress_at sat frozen at started_at for
 * the whole export.
 */
final class Test_Backup_Progress extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        global $wpdb;
        $wpdb = new class {
            public string $prefix = 'wp_';

            public function esc_like(string $t): string { return $t; }

            public function prepare(string $q, ...$a): string { return $q; }

            /** One table, so the export has something to walk. */
            public function get_col(string $q): array { return ['wp_options']; }

            public function get_row(string $q, $out = null): array {
                return ['wp_options', 'CREATE TABLE `wp_options` (id int)'];
            }

            /** One short batch: fewer than batch_size, so the loop ends. */
            public function get_results(string $q, $out = null): array {
                return [['option_id' => '1', 'option_value' => 'x']];
            }

            public function _real_escape(string $v): string { return $v; }
        };
    }

    public function test_the_database_export_reports_progress(): void {
        $beats = 0;
        $progress = static function (int $files) use (&$beats): void { $beats++; };

        $method = new ReflectionMethod(Peanut_Connect_Backup::class, 'export_database');
        $method->setAccessible(true);

        $path = sys_get_temp_dir() . '/peanut-progress-' . uniqid();
        $result = $method->invoke(null, $path, $progress);

        if (is_string($result) && file_exists($result)) {
            @unlink($result);
        }

        $this->assertGreaterThan(
            0,
            $beats,
            'The database export never reported progress, so a long export is indistinguishable from a dead worker.'
        );
    }

    public function test_the_export_still_works_without_a_progress_callback(): void {
        // The callback is optional: a direct create_backup() caller (CLI,
        // another integration) must not fatal for lack of one.
        $method = new ReflectionMethod(Peanut_Connect_Backup::class, 'export_database');
        $method->setAccessible(true);

        $path = sys_get_temp_dir() . '/peanut-progress-' . uniqid();
        $result = $method->invoke(null, $path, null);

        $this->assertIsString($result);
        $this->assertFileExists($result);
        @unlink($result);
    }
}
