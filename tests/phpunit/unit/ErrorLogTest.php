<?php
/**
 * Tests for Peanut_Connect_Error_Log
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

class ErrorLogTest extends TestCase {

    private int $original_error_reporting;

    /**
     * Set up test environment
     */
    protected function setUp(): void {
        peanut_reset_test_state();

        // Enable error logging
        update_option('peanut_connect_error_logging', true);

        // Initialize the error log FIRST (sets up log_file property)
        Peanut_Connect_Error_Log::init();

        // Clear any existing entries
        Peanut_Connect_Error_Log::clear();

        // Save original and set error_reporting to include all errors for testing
        $this->original_error_reporting = error_reporting();
        error_reporting(E_ALL);
    }

    /**
     * Clean up after tests
     */
    protected function tearDown(): void {
        // Clear the log file
        Peanut_Connect_Error_Log::clear();

        // Restore original error_reporting
        error_reporting($this->original_error_reporting);
    }

    // =========================================
    // Error Type Name Tests
    // =========================================

    /**
     * Test error type names are correctly mapped
     *
     * @dataProvider errorTypeProvider
     */
    public function test_error_type_name_mapping(int $errno, string $expected): void {
        // Use reflection to test private method
        $method = new ReflectionMethod(Peanut_Connect_Error_Log::class, 'get_error_type_name');
        $method->setAccessible(true);

        $result = $method->invoke(null, $errno);
        $this->assertEquals($expected, $result);
    }

    public static function errorTypeProvider(): array {
        return [
            'E_ERROR' => [E_ERROR, 'E_ERROR'],
            'E_WARNING' => [E_WARNING, 'E_WARNING'],
            'E_PARSE' => [E_PARSE, 'E_PARSE'],
            'E_NOTICE' => [E_NOTICE, 'E_NOTICE'],
            'E_CORE_ERROR' => [E_CORE_ERROR, 'E_CORE_ERROR'],
            'E_CORE_WARNING' => [E_CORE_WARNING, 'E_CORE_WARNING'],
            'E_COMPILE_ERROR' => [E_COMPILE_ERROR, 'E_COMPILE_ERROR'],
            'E_COMPILE_WARNING' => [E_COMPILE_WARNING, 'E_COMPILE_WARNING'],
            'E_USER_ERROR' => [E_USER_ERROR, 'E_USER_ERROR'],
            'E_USER_WARNING' => [E_USER_WARNING, 'E_USER_WARNING'],
            'E_USER_NOTICE' => [E_USER_NOTICE, 'E_USER_NOTICE'],
            'E_RECOVERABLE_ERROR' => [E_RECOVERABLE_ERROR, 'E_RECOVERABLE_ERROR'],
            'E_DEPRECATED' => [E_DEPRECATED, 'E_DEPRECATED'],
            'E_USER_DEPRECATED' => [E_USER_DEPRECATED, 'E_USER_DEPRECATED'],
        ];
    }

    /**
     * Test unknown error type returns UNKNOWN
     */
    public function test_unknown_error_type_returns_unknown(): void {
        $method = new ReflectionMethod(Peanut_Connect_Error_Log::class, 'get_error_type_name');
        $method->setAccessible(true);

        $result = $method->invoke(null, 99999);
        $this->assertEquals('UNKNOWN', $result);
    }

    // =========================================
    // Error Level Tests
    // =========================================

    /**
     * Test error levels are correctly categorized
     *
     * @dataProvider errorLevelProvider
     */
    public function test_error_level_categorization(int $errno, string $expected_level): void {
        $method = new ReflectionMethod(Peanut_Connect_Error_Log::class, 'get_error_level');
        $method->setAccessible(true);

        $result = $method->invoke(null, $errno);
        $this->assertEquals($expected_level, $result);
    }

    public static function errorLevelProvider(): array {
        return [
            'E_ERROR is critical' => [E_ERROR, 'critical'],
            'E_PARSE is critical' => [E_PARSE, 'critical'],
            'E_CORE_ERROR is critical' => [E_CORE_ERROR, 'critical'],
            'E_COMPILE_ERROR is critical' => [E_COMPILE_ERROR, 'critical'],
            'E_USER_ERROR is critical' => [E_USER_ERROR, 'critical'],
            'E_RECOVERABLE_ERROR is error' => [E_RECOVERABLE_ERROR, 'error'],
            'E_WARNING is warning' => [E_WARNING, 'warning'],
            'E_CORE_WARNING is warning' => [E_CORE_WARNING, 'warning'],
            'E_COMPILE_WARNING is warning' => [E_COMPILE_WARNING, 'warning'],
            'E_USER_WARNING is warning' => [E_USER_WARNING, 'warning'],
            'E_NOTICE is notice' => [E_NOTICE, 'notice'],
            'E_USER_NOTICE is notice' => [E_USER_NOTICE, 'notice'],
            'E_DEPRECATED is notice' => [E_DEPRECATED, 'notice'],
            'E_USER_DEPRECATED is notice' => [E_USER_DEPRECATED, 'notice'],
        ];
    }

    // =========================================
    // Handle Error Tests
    // =========================================

    /**
     * Test handle_error captures E_WARNING
     */
    public function test_handle_error_captures_warning(): void {
        // Simulate error handling
        $result = Peanut_Connect_Error_Log::handle_error(
            E_WARNING,
            'Test warning message',
            '/test/file.php',
            42
        );

        // Should return false to allow normal error handling
        $this->assertFalse($result);

        $entries = Peanut_Connect_Error_Log::get_entries();
        $this->assertCount(1, $entries);
        $this->assertEquals('E_WARNING', $entries[0]['type']);
        $this->assertEquals('warning', $entries[0]['level']);
        $this->assertEquals('Test warning message', $entries[0]['message']);
        $this->assertEquals(42, $entries[0]['line']);
    }

    /**
     * Test handle_error captures E_NOTICE
     */
    public function test_handle_error_captures_notice(): void {
        Peanut_Connect_Error_Log::handle_error(
            E_NOTICE,
            'Undefined variable: test',
            '/test/file.php',
            10
        );

        $entries = Peanut_Connect_Error_Log::get_entries();
        $this->assertCount(1, $entries);
        $this->assertEquals('E_NOTICE', $entries[0]['type']);
        $this->assertEquals('notice', $entries[0]['level']);
    }

    /**
     * Test handle_error captures E_DEPRECATED
     */
    public function test_handle_error_captures_deprecated(): void {
        Peanut_Connect_Error_Log::handle_error(
            E_DEPRECATED,
            'Deprecated function call',
            '/test/old-code.php',
            100
        );

        $entries = Peanut_Connect_Error_Log::get_entries();
        $this->assertCount(1, $entries);
        $this->assertEquals('E_DEPRECATED', $entries[0]['type']);
        $this->assertEquals('notice', $entries[0]['level']);
    }

    /**
     * Test handle_error includes timestamp
     */
    public function test_handle_error_includes_timestamp(): void {
        Peanut_Connect_Error_Log::handle_error(
            E_WARNING,
            'Test message',
            '/test/file.php',
            1
        );

        $entries = Peanut_Connect_Error_Log::get_entries();
        $this->assertArrayHasKey('timestamp', $entries[0]);
        $this->assertNotEmpty($entries[0]['timestamp']);
    }

    /**
     * Test handle_error includes user ID
     */
    public function test_handle_error_includes_user_id(): void {
        // Use E_WARNING instead of E_NOTICE as it's more likely to be in error_reporting
        Peanut_Connect_Error_Log::handle_error(
            E_WARNING,
            'Test message',
            '/test/file.php',
            1
        );

        $entries = Peanut_Connect_Error_Log::get_entries();
        $this->assertNotEmpty($entries, 'Expected error entries to be captured');
        $this->assertArrayHasKey('user_id', $entries[0]);
        $this->assertEquals(1, $entries[0]['user_id']);
    }

    // =========================================
    // Get Entries Tests
    // =========================================

    /**
     * Test get_entries returns empty array when no log exists
     */
    public function test_get_entries_returns_empty_when_no_log(): void {
        Peanut_Connect_Error_Log::clear();
        $entries = Peanut_Connect_Error_Log::get_entries();
        $this->assertIsArray($entries);
        $this->assertCount(0, $entries);
    }

    /**
     * Test get_entries with limit
     */
    public function test_get_entries_with_limit(): void {
        for ($i = 0; $i < 5; $i++) {
            Peanut_Connect_Error_Log::handle_error(
                E_NOTICE,
                "Notice $i",
                '/test/file.php',
                $i
            );
        }

        $entries = Peanut_Connect_Error_Log::get_entries(3);
        $this->assertCount(3, $entries);
    }

    /**
     * Test get_entries with offset
     */
    public function test_get_entries_with_offset(): void {
        for ($i = 1; $i <= 5; $i++) {
            Peanut_Connect_Error_Log::handle_error(
                E_NOTICE,
                "Notice $i",
                '/test/file.php',
                $i
            );
        }

        $entries = Peanut_Connect_Error_Log::get_entries(0, 2);
        $this->assertCount(3, $entries);
        $this->assertEquals('Notice 3', $entries[0]['message']);
    }

    /**
     * Test get_entries_by_level
     */
    public function test_get_entries_by_level(): void {
        Peanut_Connect_Error_Log::handle_error(E_WARNING, 'Warning 1', '/test.php', 1);
        Peanut_Connect_Error_Log::handle_error(E_NOTICE, 'Notice 1', '/test.php', 2);
        Peanut_Connect_Error_Log::handle_error(E_WARNING, 'Warning 2', '/test.php', 3);
        Peanut_Connect_Error_Log::handle_error(E_NOTICE, 'Notice 2', '/test.php', 4);

        $warnings = Peanut_Connect_Error_Log::get_entries_by_level('warning');

        $this->assertCount(2, $warnings);
        foreach ($warnings as $entry) {
            $this->assertEquals('warning', $entry['level']);
        }
    }

    // =========================================
    // Counts Tests
    // =========================================

    /**
     * Test get_counts returns all levels
     */
    public function test_get_counts_returns_all_levels(): void {
        Peanut_Connect_Error_Log::handle_error(E_ERROR, 'Critical', '/test.php', 1);
        Peanut_Connect_Error_Log::handle_error(E_RECOVERABLE_ERROR, 'Error', '/test.php', 2);
        Peanut_Connect_Error_Log::handle_error(E_WARNING, 'Warning', '/test.php', 3);
        Peanut_Connect_Error_Log::handle_error(E_NOTICE, 'Notice', '/test.php', 4);

        $counts = Peanut_Connect_Error_Log::get_counts();

        $this->assertArrayHasKey('critical', $counts);
        $this->assertArrayHasKey('error', $counts);
        $this->assertArrayHasKey('warning', $counts);
        $this->assertArrayHasKey('notice', $counts);
        $this->assertArrayHasKey('total', $counts);
        $this->assertEquals(4, $counts['total']);
    }

    /**
     * Test get_counts with correct values
     */
    public function test_get_counts_values_are_correct(): void {
        Peanut_Connect_Error_Log::handle_error(E_WARNING, 'Warning 1', '/test.php', 1);
        Peanut_Connect_Error_Log::handle_error(E_WARNING, 'Warning 2', '/test.php', 2);
        Peanut_Connect_Error_Log::handle_error(E_NOTICE, 'Notice 1', '/test.php', 3);

        $counts = Peanut_Connect_Error_Log::get_counts();

        $this->assertEquals(2, $counts['warning']);
        $this->assertEquals(1, $counts['notice']);
        $this->assertEquals(0, $counts['critical']);
        $this->assertEquals(0, $counts['error']);
    }

    /**
     * Test get_recent_counts returns last 24 hours
     */
    public function test_get_recent_counts(): void {
        Peanut_Connect_Error_Log::handle_error(E_WARNING, 'Recent warning', '/test.php', 1);
        Peanut_Connect_Error_Log::handle_error(E_NOTICE, 'Recent notice', '/test.php', 2);

        $counts = Peanut_Connect_Error_Log::get_recent_counts();

        $this->assertArrayHasKey('total', $counts);
        $this->assertGreaterThanOrEqual(2, $counts['total']);
    }

    // =========================================
    // Clear Tests
    // =========================================

    /**
     * Test clear removes all entries
     */
    public function test_clear_removes_all_entries(): void {
        Peanut_Connect_Error_Log::handle_error(E_WARNING, 'Warning', '/test.php', 1);
        Peanut_Connect_Error_Log::handle_error(E_NOTICE, 'Notice', '/test.php', 2);

        $entriesBefore = Peanut_Connect_Error_Log::get_entries();
        $this->assertCount(2, $entriesBefore);

        $result = Peanut_Connect_Error_Log::clear();
        $this->assertTrue($result);

        $entriesAfter = Peanut_Connect_Error_Log::get_entries();
        $this->assertCount(0, $entriesAfter);
    }

    /**
     * Test clear returns true when no log exists
     */
    public function test_clear_returns_true_when_no_log(): void {
        Peanut_Connect_Error_Log::clear(); // Ensure no log exists

        $result = Peanut_Connect_Error_Log::clear();
        $this->assertTrue($result);
    }

    // =========================================
    // Path Sanitization Tests
    // =========================================

    /**
     * Test file path is sanitized
     */
    public function test_file_path_is_sanitized(): void {
        // The sanitize_path method removes ABSPATH from paths
        $full_path = ABSPATH . 'wp-content/plugins/test/file.php';

        Peanut_Connect_Error_Log::handle_error(
            E_WARNING,
            'Test warning',
            $full_path,
            10
        );

        $entries = Peanut_Connect_Error_Log::get_entries();

        // Ensure we captured the error
        $this->assertNotEmpty($entries, 'Error log should capture the E_WARNING');
        $this->assertArrayHasKey('file', $entries[0], 'Entry should have file key');
        $this->assertNotNull($entries[0]['file'], 'File should not be null');

        // Path should have ABSPATH replaced with /
        $this->assertStringContainsString('wp-content/plugins/test/file.php', $entries[0]['file']);
        $this->assertStringNotContainsString('/tmp/wordpress', $entries[0]['file']);
    }

    // =========================================
    // CSV Export Tests
    // =========================================

    /**
     * Test export_csv returns valid CSV
     */
    public function test_export_csv_returns_valid_csv(): void {
        Peanut_Connect_Error_Log::handle_error(
            E_WARNING,
            'Test warning',
            '/test/file.php',
            42
        );

        $csv = Peanut_Connect_Error_Log::export_csv();

        $this->assertStringContainsString('Timestamp,Level,Type,Message,File,Line,URL', $csv);
        $this->assertStringContainsString('warning', $csv);
        $this->assertStringContainsString('E_WARNING', $csv);
        $this->assertStringContainsString('Test warning', $csv);
        $this->assertStringContainsString('42', $csv);
    }

    /**
     * Test export_csv escapes quotes
     */
    public function test_export_csv_escapes_quotes(): void {
        Peanut_Connect_Error_Log::handle_error(
            E_WARNING,
            'Error with "quotes" in message',
            '/test/file.php',
            1
        );

        $csv = Peanut_Connect_Error_Log::export_csv();

        // CSV escapes quotes by doubling them
        $this->assertStringContainsString('""quotes""', $csv);
    }

    // =========================================
    // URL Handling Tests
    // =========================================

    /**
     * Test get_current_url returns CLI when not in web context
     */
    public function test_get_current_url_returns_cli(): void {
        // In CLI context, REQUEST_URI is not set
        unset($_SERVER['REQUEST_URI']);

        Peanut_Connect_Error_Log::handle_error(
            E_NOTICE,
            'CLI notice',
            '/test/file.php',
            1
        );

        $entries = Peanut_Connect_Error_Log::get_entries();

        $this->assertEquals('CLI', $entries[0]['url']);
    }

    /**
     * Test get_current_url captures REQUEST_URI
     */
    public function test_get_current_url_captures_request_uri(): void {
        $_SERVER['REQUEST_URI'] = '/test/page?foo=bar';

        Peanut_Connect_Error_Log::handle_error(
            E_NOTICE,
            'Web notice',
            '/test/file.php',
            1
        );

        $entries = Peanut_Connect_Error_Log::get_entries();

        $this->assertEquals('/test/page?foo=bar', $entries[0]['url']);

        unset($_SERVER['REQUEST_URI']);
    }

    // =========================================
    // Entry Order Tests
    // =========================================

    /**
     * Test entries are ordered newest first
     */
    public function test_entries_ordered_newest_first(): void {
        Peanut_Connect_Error_Log::handle_error(E_NOTICE, 'First entry', '/test.php', 1);
        usleep(1000); // Small delay
        Peanut_Connect_Error_Log::handle_error(E_NOTICE, 'Second entry', '/test.php', 2);

        $entries = Peanut_Connect_Error_Log::get_entries();

        $this->assertEquals('Second entry', $entries[0]['message']);
        $this->assertEquals('First entry', $entries[1]['message']);
    }
}
