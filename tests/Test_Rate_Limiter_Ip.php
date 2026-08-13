<?php
/**
 * Tests for the rate limiter's trusted-proxy IP matching (anti-spoofing).
 *
 * The forwarded-header bypass fix hinges on only honouring CF/X-Forwarded-For
 * when the real peer (REMOTE_ADDR) is in a configured trusted-proxy list. These
 * tests pin the CIDR/exact matching that gates that.
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-connect-rate-limiter.php';

class Test_Rate_Limiter_Ip extends TestCase {

    private function cidr(string $ip, string $cidr): bool {
        $m = new ReflectionMethod(Peanut_Connect_Rate_Limiter::class, 'ip_in_cidr');
        return $m->invoke(null, $ip, $cidr);
    }

    private function inList(string $ip, array $list): bool {
        $m = new ReflectionMethod(Peanut_Connect_Rate_Limiter::class, 'ip_matches_any');
        return $m->invoke(null, $ip, $list);
    }

    public function test_ipv4_cidr_membership(): void {
        $this->assertTrue($this->cidr('103.21.244.23', '103.21.244.0/22'));   // Cloudflare range
        $this->assertFalse($this->cidr('103.21.248.1', '103.21.244.0/22'));
        $this->assertTrue($this->cidr('10.0.5.7', '10.0.0.0/8'));
        $this->assertFalse($this->cidr('11.0.0.1', '10.0.0.0/8'));
    }

    public function test_host_and_zero_routes(): void {
        $this->assertTrue($this->cidr('192.168.1.1', '192.168.1.1/32'));
        $this->assertFalse($this->cidr('192.168.1.2', '192.168.1.1/32'));
        $this->assertTrue($this->cidr('8.8.8.8', '0.0.0.0/0')); // match-all
    }

    public function test_ipv6_cidr_and_family_mismatch(): void {
        $this->assertTrue($this->cidr('2606:4700::1', '2606:4700::/32'));
        $this->assertFalse($this->cidr('2606:4701::1', '2606:4700:0:0:0:0:0:0/48'));
        // IPv4 address vs IPv6 subnet must not match (no cross-family).
        $this->assertFalse($this->cidr('1.2.3.4', '2606:4700::/32'));
    }

    public function test_invalid_inputs_are_false(): void {
        $this->assertFalse($this->cidr('not-an-ip', '10.0.0.0/8'));
        $this->assertFalse($this->cidr('10.0.0.1', 'garbage'));
        $this->assertFalse($this->cidr('10.0.0.1', '10.0.0.0/999'));
    }

    public function test_matches_any_exact_and_cidr(): void {
        $list = ['203.0.113.7', '103.21.244.0/22'];
        $this->assertTrue($this->inList('203.0.113.7', $list));     // exact
        $this->assertTrue($this->inList('103.21.244.99', $list));   // cidr
        $this->assertFalse($this->inList('198.51.100.1', $list));   // neither
        $this->assertFalse($this->inList('203.0.113.7', []));       // empty list
    }
}
