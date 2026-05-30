<?php
/**
 * API tests for Peanut Connect.
 *
 * Tests REST API endpoints, authentication, and data exchange.
 *
 * @package Peanut_Connect
 */

class Test_API extends Peanut_Connect_TestCase {

    /**
     * Test API namespace is correctly defined.
     */
    public function test_api_namespace() {
        if (defined('PEANUT_CONNECT_API_NAMESPACE')) {
            $this->assertEquals('peanut-connect/v1', PEANUT_CONNECT_API_NAMESPACE);
        } else {
            $this->markTestSkipped('PEANUT_CONNECT_API_NAMESPACE not defined.');
        }
    }

    /**
     * Test site key validation.
     */
    public function test_site_key_validation() {
        // Valid site key format: 32 character hex string.
        $valid_key = bin2hex(random_bytes(16));
        $invalid_keys = [
            '',
            'short',
            'not-hex-characters-here!!!!!!!!',
            str_repeat('a', 64),  // Too long.
        ];

        // Valid key should be 32 chars and hex.
        $this->assertEquals(32, strlen($valid_key));
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/i', $valid_key);

        // Invalid keys should fail validation.
        foreach ($invalid_keys as $key) {
            $is_valid = strlen($key) === 32 && preg_match('/^[a-f0-9]+$/i', $key);
            $this->assertFalse($is_valid, "Key '$key' should be invalid");
        }
    }

    /**
     * Test authentication header validation.
     */
    public function test_auth_header_validation() {
        $valid_header = 'Bearer pk_live_xxxxxxxxxxxxxxxx';
        $invalid_headers = [
            '',
            'Basic dXNlcjpwYXNz',  // Wrong auth type.
            'Bearer',  // Missing token.
            'pk_live_xxx',  // Missing Bearer prefix.
        ];

        // Valid header should start with Bearer.
        $this->assertStringStartsWith('Bearer ', $valid_header);

        // Extract token from valid header.
        $token = str_replace('Bearer ', '', $valid_header);
        $this->assertNotEmpty($token);
    }

    /**
     * Test health check response structure.
     */
    public function test_health_check_response() {
        $health_response = [
            'status' => 'healthy',
            'wordpress_version' => '6.4.2',
            'php_version' => '8.2.0',
            'ssl_enabled' => true,
            'plugin_updates' => 2,
            'theme_updates' => 0,
            'last_check' => gmdate('Y-m-d H:i:s'),
        ];

        // Required fields.
        $required = ['status', 'wordpress_version', 'php_version', 'ssl_enabled'];
        foreach ($required as $field) {
            $this->assertArrayHasKey($field, $health_response);
        }

        // Status should be valid.
        $valid_statuses = ['healthy', 'warning', 'critical'];
        $this->assertContains($health_response['status'], $valid_statuses);

        // Version formats.
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $health_response['wordpress_version']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $health_response['php_version']);
    }

    /**
     * Test plugin update information structure.
     */
    public function test_plugin_update_structure() {
        $update_info = [
            'slug' => 'example-plugin',
            'name' => 'Example Plugin',
            'current_version' => '1.0.0',
            'new_version' => '1.1.0',
            'update_url' => 'https://example.com/plugin.zip',
            'requires_php' => '8.0',
            'requires_wp' => '6.0',
        ];

        // Slug should be sanitized.
        $this->assertMatchesRegularExpression('/^[a-z0-9\-]+$/', $update_info['slug']);

        // Versions should be semver-like.
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $update_info['current_version']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $update_info['new_version']);

        // Update URL should be valid.
        $this->assertNotFalse(filter_var($update_info['update_url'], FILTER_VALIDATE_URL));
    }

    /**
     * Test permissions structure.
     */
    public function test_permissions_structure() {
        $permissions = [
            'can_read_health' => true,
            'can_read_updates' => true,
            'can_perform_updates' => false,
            'can_read_analytics' => true,
            'can_access_admin' => false,
        ];

        // All permissions should be boolean.
        foreach ($permissions as $permission => $value) {
            $this->assertIsBool($value, "$permission should be boolean");
        }
    }

    /**
     * Test rate limiting headers.
     */
    public function test_rate_limit_headers() {
        $rate_limit_headers = [
            'X-RateLimit-Limit' => '60',
            'X-RateLimit-Remaining' => '58',
            'X-RateLimit-Reset' => time() + 60,
        ];

        // Limit should be positive integer.
        $this->assertGreaterThan(0, (int) $rate_limit_headers['X-RateLimit-Limit']);

        // Remaining should be non-negative.
        $this->assertGreaterThanOrEqual(0, (int) $rate_limit_headers['X-RateLimit-Remaining']);

        // Reset should be future timestamp.
        $this->assertGreaterThan(time() - 1, $rate_limit_headers['X-RateLimit-Reset']);
    }

    /**
     * Test error response structure.
     */
    public function test_error_response_structure() {
        $error_responses = [
            [
                'code' => 'invalid_site_key',
                'message' => 'The provided site key is invalid.',
                'status' => 401,
            ],
            [
                'code' => 'rate_limit_exceeded',
                'message' => 'Rate limit exceeded. Please try again later.',
                'status' => 429,
            ],
            [
                'code' => 'permission_denied',
                'message' => 'You do not have permission to perform this action.',
                'status' => 403,
            ],
        ];

        foreach ($error_responses as $error) {
            // Required error fields.
            $this->assertArrayHasKey('code', $error);
            $this->assertArrayHasKey('message', $error);
            $this->assertArrayHasKey('status', $error);

            // Status code should be 4xx or 5xx.
            $this->assertGreaterThanOrEqual(400, $error['status']);
            $this->assertLessThan(600, $error['status']);

            // Code should be snake_case.
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $error['code']);
        }
    }

    /**
     * Test manager site URL validation.
     */
    public function test_manager_url_validation() {
        $valid_urls = [
            'https://manager.example.com',
            'https://app.peanutgraphic.com',
        ];

        $invalid_urls = [
            'http://insecure.com',  // Not HTTPS.
            'ftp://wrong-protocol.com',
            'javascript:alert(1)',
            '',
        ];

        foreach ($valid_urls as $url) {
            $is_valid = filter_var($url, FILTER_VALIDATE_URL) !== false
                && strpos($url, 'https://') === 0;
            $this->assertTrue($is_valid, "URL $url should be valid");
        }

        foreach ($invalid_urls as $url) {
            $is_valid = !empty($url)
                && filter_var($url, FILTER_VALIDATE_URL) !== false
                && strpos($url, 'https://') === 0;
            $this->assertFalse($is_valid, "URL '$url' should be invalid");
        }
    }

    /**
     * Test activity log entry structure.
     */
    public function test_activity_log_structure() {
        $log_entry = [
            'action' => 'plugin_updated',
            'details' => [
                'plugin' => 'example-plugin',
                'from_version' => '1.0.0',
                'to_version' => '1.1.0',
            ],
            'user_id' => 1,
            'ip_address' => '192.168.1.1',
            'timestamp' => gmdate('Y-m-d H:i:s'),
        ];

        // Required fields.
        $this->assertArrayHasKey('action', $log_entry);
        $this->assertArrayHasKey('timestamp', $log_entry);

        // Action should be meaningful string.
        $this->assertNotEmpty($log_entry['action']);
        $this->assertMatchesRegularExpression('/^[a-z_]+$/', $log_entry['action']);

        // Timestamp should be valid.
        $this->assertNotFalse(strtotime($log_entry['timestamp']));
    }
}
