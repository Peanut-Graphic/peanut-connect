<?php
if (!defined('ARRAY_N')) { define('ARRAY_N', 'ARRAY_N'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
use PHPUnit\Framework\TestCase;
if (!function_exists('get_site_url')) { function get_site_url() { return 'https://example.com'; } }
require_once dirname(__DIR__) . '/includes/class-connect-backup.php';
class Test_Backup_Export_Roundtrip extends TestCase {
    public function test_dump_removes_wp_placeholders_and_declares_connection_charset(): void {
        global $wpdb;
        $old = $wpdb;
        $wpdb = new class {
            public $charset = 'utf8mb4';
            public $prefix = 'wp_';
            public function esc_like($value) { return $value; }
            public function get_col($sql) { return ['wp_options']; }
            public function get_row($sql, $format) { return ['wp_options', 'CREATE TABLE `wp_options` (`option_value` text) DEFAULT CHARSET=utf8mb4']; }
            public function prepare($sql, ...$args) { return vsprintf(str_replace('%d', '%d', $sql), $args); }
            public function get_results($sql, $format) { return str_contains($sql, 'OFFSET 0') ? [['option_value' => "https://example.com/a%20b?emoji=😀&quote='yes'"]] : []; }
            public function _real_escape($value) { return str_replace('%', '{'.str_repeat('a', 64).'}', addslashes($value)); }
            public function remove_placeholder_escape($value) { return str_replace('{'.str_repeat('a', 64).'}', '%', $value); }
        };
        $path = tempnam(sys_get_temp_dir(), 'pc-export-');
        try {
            $m = new ReflectionMethod(Peanut_Connect_Backup::class, 'export_database');
            if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); }
            $file = $m->invoke(null, $path);
            $dump = file_get_contents($file);
            $this->assertStringContainsString('SET NAMES utf8mb4;', $dump);
            $this->assertStringContainsString('a%20b', $dump);
            $this->assertStringNotContainsString('{'.str_repeat('a', 64).'}', $dump);
            $this->assertStringContainsString("\\'yes\\'", $dump);
            $this->assertStringContainsString('😀', $dump);
        } finally { $wpdb = $old; @unlink($path); @unlink($path.'.sql'); }
    }
}
