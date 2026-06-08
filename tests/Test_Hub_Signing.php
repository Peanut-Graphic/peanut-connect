<?php
/**
 * Tests for Hub request signing (anti-replay + key-not-in-transit).
 *
 * Pins the canonicalisation + constant-time verification that Hub and the site
 * must agree on. The replay/timestamp/nonce wrapper is integration-tested.
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-connect-auth.php';

class Test_Hub_Signing extends TestCase {

    private const KEY = 'shared-hub-key-abc123';

    private function sign(string $body, string $method = 'POST', string $route = '/peanut-connect/v1/restore', string $ts = '1700000000', string $nonce = 'n1'): string {
        return Peanut_Connect_Auth::compute_request_signature(self::KEY, $method, $route, $ts, $nonce, $body);
    }

    public function test_signature_is_deterministic_and_hex(): void {
        $a = $this->sign('{"backup_url":"x"}');
        $b = $this->sign('{"backup_url":"x"}');
        $this->assertSame($a, $b);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
    }

    public function test_valid_signature_verifies(): void {
        $body = '{"backup_url":"https://hub/x.zip"}';
        $sig = $this->sign($body);
        $this->assertTrue(
            Peanut_Connect_Auth::verify_signature(self::KEY, 'POST', '/peanut-connect/v1/restore', '1700000000', 'n1', $body, $sig)
        );
        // Case-insensitive on the provided hex.
        $this->assertTrue(
            Peanut_Connect_Auth::verify_signature(self::KEY, 'POST', '/peanut-connect/v1/restore', '1700000000', 'n1', $body, strtoupper($sig))
        );
    }

    public function test_tampered_body_fails(): void {
        $sig = $this->sign('{"backup_url":"https://hub/good.zip"}');
        $this->assertFalse(
            Peanut_Connect_Auth::verify_signature(self::KEY, 'POST', '/peanut-connect/v1/restore', '1700000000', 'n1', '{"backup_url":"https://evil/shell.zip"}', $sig)
        );
    }

    public function test_wrong_key_fails(): void {
        $body = '{"x":1}';
        $sig = $this->sign($body);
        $this->assertFalse(
            Peanut_Connect_Auth::verify_signature('different-key', 'POST', '/peanut-connect/v1/restore', '1700000000', 'n1', $body, $sig)
        );
    }

    public function test_binding_to_method_route_ts_nonce(): void {
        $body = '{"x":1}';
        $sig = $this->sign($body);
        // Each bound field, when changed, must invalidate the signature.
        $this->assertFalse(Peanut_Connect_Auth::verify_signature(self::KEY, 'GET', '/peanut-connect/v1/restore', '1700000000', 'n1', $body, $sig), 'method');
        $this->assertFalse(Peanut_Connect_Auth::verify_signature(self::KEY, 'POST', '/peanut-connect/v1/update', '1700000000', 'n1', $body, $sig), 'route');
        $this->assertFalse(Peanut_Connect_Auth::verify_signature(self::KEY, 'POST', '/peanut-connect/v1/restore', '1700000099', 'n1', $body, $sig), 'timestamp');
        $this->assertFalse(Peanut_Connect_Auth::verify_signature(self::KEY, 'POST', '/peanut-connect/v1/restore', '1700000000', 'n2', $body, $sig), 'nonce');
    }

    public function test_empty_inputs_fail(): void {
        $this->assertFalse(Peanut_Connect_Auth::verify_signature('', 'POST', '/r', '1', 'n', 'b', 'sig'));
        $this->assertFalse(Peanut_Connect_Auth::verify_signature(self::KEY, 'POST', '/r', '1', 'n', 'b', ''));
    }
}
