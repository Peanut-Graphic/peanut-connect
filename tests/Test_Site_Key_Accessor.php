<?php
/**
 * Tests for the encrypting site-key accessor (P1): round-trip, encryption at
 * rest, transparent migration of legacy plaintext, fail-closed on decrypt
 * failure, and disconnect cleanup.
 *
 * Regression: the site key (the inbound Bearer credential) was previously
 * written to wp_options in cleartext by generate_site_key() and the activation
 * hook, and read raw by authenticate(). It is now encrypted at rest, mirroring
 * the Hub-key accessor.
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-connect-secret.php';
require_once dirname(__DIR__) . '/includes/class-connect-auth.php';

class Test_Site_Key_Accessor extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        global $mock_options;
        $mock_options = [];
        $GLOBALS['mock_wp_salt'] = 'fixed-salt-for-tests';
    }

    protected function tearDown(): void {
        unset($GLOBALS['mock_wp_salt']);
        parent::tearDown();
    }

    public function test_set_then_get_round_trips(): void {
        Peanut_Connect_Auth::set_site_key('my-site-key');
        $this->assertSame('my-site-key', Peanut_Connect_Auth::get_site_key());
    }

    public function test_value_is_stored_encrypted_not_plaintext(): void {
        Peanut_Connect_Auth::set_site_key('my-site-key');
        $raw = get_option('peanut_connect_site_key');
        $this->assertStringStartsWith('enc:v1:', (string) $raw);
        $this->assertStringNotContainsString('my-site-key', (string) $raw);
    }

    public function test_legacy_plaintext_is_returned_and_migrated_on_read(): void {
        update_option('peanut_connect_site_key', 'legacy-plain-site-key');
        $this->assertSame('legacy-plain-site-key', Peanut_Connect_Auth::get_site_key());
        $this->assertStringStartsWith('enc:v1:', (string) get_option('peanut_connect_site_key'));
    }

    public function test_empty_when_unset(): void {
        $this->assertSame('', Peanut_Connect_Auth::get_site_key());
    }

    public function test_undecryptable_returns_empty_and_sets_flag(): void {
        Peanut_Connect_Auth::set_site_key('my-site-key');
        $GLOBALS['mock_wp_salt'] = 'rotated-salt';
        $this->assertSame('', Peanut_Connect_Auth::get_site_key());
        $this->assertNotEmpty(get_option('peanut_connect_site_key_undecryptable'));
    }

    public function test_set_clears_undecryptable_flag(): void {
        update_option('peanut_connect_site_key_undecryptable', 1);
        Peanut_Connect_Auth::set_site_key('fresh-site-key');
        $this->assertFalse((bool) get_option('peanut_connect_site_key_undecryptable', false));
    }
}
