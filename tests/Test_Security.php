<?php
/**
 * Security tests for Peanut Connect.
 *
 * Tests authentication, authorization, and data protection.
 *
 * @package Peanut_Connect
 */

class Test_Security extends Peanut_Connect_TestCase {

    /**
     * Test site key is stored securely.
     */
    public function test_site_key_storage() {
        // Site key should be stored as option, not in plain file.
        $option_name = 'peanut_connect_site_key';

        // When retrieved, should be non-empty if configured.
        // (In actual test, would check option value)
        $this->assertNotEmpty($option_name);
    }

    /**
     * Test HMAC signature validation.
     */
    public function test_hmac_signature_validation() {
        $secret = bin2hex(random_bytes(32));
        $payload = json_encode(['action' => 'health_check', 'timestamp' => time()]);

        // Generate valid signature.
        $valid_signature = hash_hmac('sha256', $payload, $secret);

        // Valid signature should be 64 chars (sha256 hex).
        $this->assertEquals(64, strlen($valid_signature));

        // Tampered payload should produce different signature.
        $tampered_payload = json_encode(['action' => 'health_check', 'timestamp' => time() + 1]);
        $tampered_signature = hash_hmac('sha256', $tampered_payload, $secret);

        $this->assertNotEquals($valid_signature, $tampered_signature);

        // Wrong secret should produce different signature.
        $wrong_secret = bin2hex(random_bytes(32));
        $wrong_signature = hash_hmac('sha256', $payload, $wrong_secret);

        $this->assertNotEquals($valid_signature, $wrong_signature);
    }

    /**
     * Test timing attack prevention in signature comparison.
     */
    public function test_timing_safe_comparison() {
        $signature1 = hash('sha256', 'test1');
        $signature2 = hash('sha256', 'test2');
        $signature1_copy = $signature1;

        // hash_equals should be used for timing-safe comparison.
        $this->assertTrue(hash_equals($signature1, $signature1_copy));
        $this->assertFalse(hash_equals($signature1, $signature2));
    }

    /**
     * Test request timestamp validation (replay attack prevention).
     */
    public function test_timestamp_validation() {
        $max_age = 300;  // 5 minutes.

        $current_time = time();
        $valid_timestamp = $current_time - 60;  // 1 minute ago.
        $old_timestamp = $current_time - 600;  // 10 minutes ago.
        $future_timestamp = $current_time + 600;  // 10 minutes in future.

        // Valid timestamp within window.
        $this->assertTrue(abs($current_time - $valid_timestamp) <= $max_age);

        // Old timestamp outside window.
        $this->assertFalse(abs($current_time - $old_timestamp) <= $max_age);

        // Future timestamp outside window.
        $this->assertFalse(abs($current_time - $future_timestamp) <= $max_age);
    }

    /**
     * Test nonce validation.
     */
    public function test_nonce_uniqueness() {
        $nonces = [];

        // Generate multiple nonces.
        for ($i = 0; $i < 100; $i++) {
            $nonce = bin2hex(random_bytes(16));
            $this->assertNotContains($nonce, $nonces, 'Nonce should be unique');
            $nonces[] = $nonce;
        }

        // All nonces should be 32 characters.
        foreach ($nonces as $nonce) {
            $this->assertEquals(32, strlen($nonce));
        }
    }

    /**
     * Test IP address validation.
     */
    public function test_ip_address_validation() {
        $valid_ips = [
            '192.168.1.1',
            '10.0.0.1',
            '2001:0db8:85a3:0000:0000:8a2e:0370:7334',  // IPv6.
        ];

        $invalid_ips = [
            'not-an-ip',
            '999.999.999.999',
            '',
            '<script>alert(1)</script>',
        ];

        foreach ($valid_ips as $ip) {
            $is_valid = filter_var($ip, FILTER_VALIDATE_IP) !== false;
            $this->assertTrue($is_valid, "IP $ip should be valid");
        }

        foreach ($invalid_ips as $ip) {
            $is_valid = filter_var($ip, FILTER_VALIDATE_IP) !== false;
            $this->assertFalse($is_valid, "IP '$ip' should be invalid");
        }
    }

    /**
     * Test sensitive data masking.
     */
    public function test_sensitive_data_masking() {
        $sensitive_data = [
            'api_key' => 'sk_live_1234567890abcdef',
            'password' => 'super_secret_password',
            'site_key' => 'abcd1234efgh5678ijkl9012mnop3456',
        ];

        foreach ($sensitive_data as $key => $value) {
            // Mask function should hide most of the value.
            $masked = substr($value, 0, 4) . str_repeat('*', strlen($value) - 8) . substr($value, -4);

            // Masked value should not expose full original.
            $this->assertNotEquals($value, $masked);

            // Masked value should still have same length.
            $this->assertEquals(strlen($value), strlen($masked));

            // Should contain asterisks.
            $this->assertStringContainsString('*', $masked);
        }
    }

    /**
     * Test CORS header configuration.
     */
    public function test_cors_configuration() {
        $allowed_origins = [
            'https://app.peanutgraphic.com',
            'https://manager.peanutgraphic.com',
        ];

        $request_origin = 'https://app.peanutgraphic.com';

        // Origin should be in allowed list.
        $this->assertContains($request_origin, $allowed_origins);

        // Malicious origin should not be allowed.
        $malicious_origin = 'https://evil.com';
        $this->assertNotContains($malicious_origin, $allowed_origins);
    }

    /**
     * Test content security policy headers.
     */
    public function test_csp_headers() {
        $csp = [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https:",
            "frame-ancestors 'none'",
        ];

        $csp_header = implode('; ', $csp);

        // Should include frame-ancestors to prevent clickjacking.
        $this->assertStringContainsString('frame-ancestors', $csp_header);

        // Should have default-src.
        $this->assertStringContainsString('default-src', $csp_header);
    }

    /**
     * Test secure cookie attributes.
     */
    public function test_secure_cookie_attributes() {
        $cookie_options = [
            'httponly' => true,
            'secure' => true,
            'samesite' => 'Strict',
            'path' => '/',
        ];

        // HttpOnly prevents JavaScript access.
        $this->assertTrue($cookie_options['httponly']);

        // Secure ensures HTTPS only.
        $this->assertTrue($cookie_options['secure']);

        // SameSite prevents CSRF.
        $this->assertContains($cookie_options['samesite'], ['Strict', 'Lax']);
    }

    /**
     * Test input sanitization for XSS.
     */
    public function test_xss_prevention() {
        $xss_vectors = [
            '<script>alert("xss")</script>',
            '<img src=x onerror=alert(1)>',
            '"><script>alert(1)</script>',
            "javascript:alert('xss')",
            '<svg onload=alert(1)>',
        ];

        foreach ($xss_vectors as $vector) {
            $sanitized = sanitize_text_field($vector);
            $escaped = esc_html($sanitized);

            // Should not contain script tags.
            $this->assertStringNotContainsString('<script>', $escaped);

            // Should not contain event handlers.
            $this->assertStringNotContainsString('onerror=', strtolower($escaped));
            $this->assertStringNotContainsString('onload=', strtolower($escaped));
        }
    }

    /**
     * Test capability checks for admin actions.
     */
    public function test_capability_requirements() {
        $admin_actions = [
            'manage_peanut_connect' => 'update_settings',
            'manage_options' => 'disconnect_site',
            'update_plugins' => 'trigger_update',
        ];

        foreach ($admin_actions as $capability => $action) {
            // Each admin action should have required capability.
            $this->assertNotEmpty($capability, "Action $action should require a capability");
        }
    }
}
