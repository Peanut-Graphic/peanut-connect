<?php
/**
 * Tests for at-rest encryption of the Hub key (A5).
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-connect-secret.php';

class Test_Secret extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['mock_wp_salt'] = 'fixed-salt-for-tests';
    }

    protected function tearDown(): void {
        unset($GLOBALS['mock_wp_salt']);
        parent::tearDown();
    }

    public function test_round_trip(): void {
        $plain = 'hub-key-abc123-XYZ';
        $stored = Peanut_Connect_Secret::encrypt($plain);
        $this->assertStringStartsWith('enc:v1:', $stored);
        $this->assertNotSame($plain, $stored);
        $this->assertSame($plain, Peanut_Connect_Secret::decrypt($stored));
    }

    public function test_is_ciphertext(): void {
        $stored = Peanut_Connect_Secret::encrypt('k');
        $this->assertTrue(Peanut_Connect_Secret::is_ciphertext($stored));
        $this->assertFalse(Peanut_Connect_Secret::is_ciphertext('plain-legacy-key'));
        $this->assertFalse(Peanut_Connect_Secret::is_ciphertext(''));
    }

    public function test_decrypt_of_plaintext_returns_null(): void {
        $this->assertNull(Peanut_Connect_Secret::decrypt('not-ciphertext'));
    }

    public function test_decrypt_of_tampered_returns_null(): void {
        $stored = Peanut_Connect_Secret::encrypt('secret');
        $tampered = substr($stored, 0, -4) . 'AAAA';
        $this->assertNull(Peanut_Connect_Secret::decrypt($tampered));
    }

    public function test_different_salt_cannot_decrypt(): void {
        $stored = Peanut_Connect_Secret::encrypt('secret');
        $GLOBALS['mock_wp_salt'] = 'a-different-salt-after-rotation';
        $this->assertNull(Peanut_Connect_Secret::decrypt($stored));
    }
}
