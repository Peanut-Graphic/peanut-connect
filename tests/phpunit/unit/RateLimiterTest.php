<?php
/**
 * Tests for Peanut_Connect_Rate_Limiter
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase {

    /**
     * Reset test state before each test
     */
    protected function setUp(): void {
        peanut_reset_test_state();
    }

    /**
     * Test that first request is allowed
     */
    public function test_first_request_is_allowed(): void {
        $result = Peanut_Connect_Rate_Limiter::check('test-client-1', 'default');

        $this->assertTrue($result);
    }

    /**
     * Test that requests within limit are allowed
     */
    public function test_requests_within_limit_are_allowed(): void {
        $client_id = 'test-client-2';

        // Make several requests within limit
        for ($i = 0; $i < 10; $i++) {
            $result = Peanut_Connect_Rate_Limiter::check($client_id, 'default');
            $this->assertTrue($result, "Request $i should be allowed");
        }
    }

    /**
     * Test that requests over limit are blocked
     */
    public function test_requests_over_limit_are_blocked(): void {
        $client_id = 'test-client-3';

        // Auth endpoints have limit of 10
        for ($i = 0; $i < 10; $i++) {
            Peanut_Connect_Rate_Limiter::check($client_id, 'verify');
        }

        // 11th request should be blocked
        $result = Peanut_Connect_Rate_Limiter::check($client_id, 'verify');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('rate_limit_exceeded', $result->get_error_code());
    }

    /**
     * Test that rate limit includes retry-after information
     */
    public function test_rate_limit_error_includes_retry_after(): void {
        $client_id = 'test-client-4';

        // Exceed limit
        for ($i = 0; $i <= 10; $i++) {
            Peanut_Connect_Rate_Limiter::check($client_id, 'verify');
        }

        $result = Peanut_Connect_Rate_Limiter::check($client_id, 'verify');

        $this->assertInstanceOf(WP_Error::class, $result);
        $data = $result->get_error_data();
        $this->assertArrayHasKey('retry_after', $data);
        $this->assertGreaterThan(0, $data['retry_after']);
    }

    /**
     * Test that different endpoints have different limits
     */
    public function test_different_endpoints_have_different_limits(): void {
        $client_id = 'test-client-5';

        // Verify endpoint has limit of 10, health has 30
        // Exhaust verify limit
        for ($i = 0; $i < 10; $i++) {
            Peanut_Connect_Rate_Limiter::check($client_id, 'verify');
        }

        // Verify should now be blocked
        $verify_result = Peanut_Connect_Rate_Limiter::check($client_id, 'verify');
        $this->assertInstanceOf(WP_Error::class, $verify_result);

        // But health should still work (different endpoint)
        $health_result = Peanut_Connect_Rate_Limiter::check($client_id, 'health');
        $this->assertTrue($health_result);
    }

    /**
     * Test that rate limit headers are returned correctly
     */
    public function test_get_headers_returns_correct_values(): void {
        $client_id = 'test-client-6';

        // Make 5 requests
        for ($i = 0; $i < 5; $i++) {
            Peanut_Connect_Rate_Limiter::check($client_id, 'health');
        }

        $headers = Peanut_Connect_Rate_Limiter::get_headers($client_id, 'health');

        $this->assertArrayHasKey('X-RateLimit-Limit', $headers);
        $this->assertArrayHasKey('X-RateLimit-Remaining', $headers);
        $this->assertArrayHasKey('X-RateLimit-Reset', $headers);

        // Health limit is 30, we made 5 requests
        $this->assertEquals(30, $headers['X-RateLimit-Limit']);
        $this->assertEquals(25, $headers['X-RateLimit-Remaining']);
    }

    /**
     * Test that clear resets the rate limit
     */
    public function test_clear_resets_rate_limit(): void {
        $client_id = 'test-client-7';

        // Exhaust the limit
        for ($i = 0; $i <= 10; $i++) {
            Peanut_Connect_Rate_Limiter::check($client_id, 'verify');
        }

        // Should be blocked
        $result = Peanut_Connect_Rate_Limiter::check($client_id, 'verify');
        $this->assertInstanceOf(WP_Error::class, $result);

        // Clear the limit
        Peanut_Connect_Rate_Limiter::clear($client_id, 'verify');

        // Should work again
        $result = Peanut_Connect_Rate_Limiter::check($client_id, 'verify');
        $this->assertTrue($result);
    }

    /**
     * Test that get_client_identifier extracts IP from request
     */
    public function test_get_client_identifier_uses_ip(): void {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';

        $request = new WP_REST_Request('GET', '/test');

        $identifier = Peanut_Connect_Rate_Limiter::get_client_identifier($request);

        $this->assertStringContainsString('192.168.1.100', $identifier);

        // Clean up
        unset($_SERVER['REMOTE_ADDR']);
    }

    /**
     * Test that get_client_identifier does not include credential material
     */
    public function test_get_client_identifier_ignores_api_key(): void {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.101';

        $request = new WP_REST_Request('GET', '/test');
        $request->set_header('Authorization', 'Bearer test-api-key-12345');

        $identifier = Peanut_Connect_Rate_Limiter::get_client_identifier($request);

        // Credentials must not create fresh rate-limit buckets for each guess.
        $this->assertSame('192.168.1.101', $identifier);

        // Clean up
        unset($_SERVER['REMOTE_ADDR']);
    }
}
