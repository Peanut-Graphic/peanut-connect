<?php
/**
 * Tests for the encrypting Hub-key accessor: round-trip, transparent
 * migration of legacy plaintext, and decrypt-failure handling (A5).
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-connect-secret.php';
require_once dirname(__DIR__) . '/includes/class-connect-auth.php';

class Test_Hub_Key_Accessor extends TestCase {

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
        Peanut_Connect_Auth::set_hub_api_key('my-hub-key');
        $this->assertSame('my-hub-key', Peanut_Connect_Auth::get_hub_api_key());
    }

    public function test_value_is_stored_encrypted_not_plaintext(): void {
        Peanut_Connect_Auth::set_hub_api_key('my-hub-key');
        $raw = get_option('peanut_connect_hub_api_key');
        $this->assertStringStartsWith('enc:v1:', (string) $raw);
        $this->assertStringNotContainsString('my-hub-key', (string) $raw);
    }

    public function test_legacy_plaintext_is_returned_and_migrated_on_read(): void {
        update_option('peanut_connect_hub_api_key', 'legacy-plain-key');
        $this->assertSame('legacy-plain-key', Peanut_Connect_Auth::get_hub_api_key());
        $this->assertStringStartsWith('enc:v1:', (string) get_option('peanut_connect_hub_api_key'));
    }

    public function test_empty_when_unset(): void {
        $this->assertSame('', Peanut_Connect_Auth::get_hub_api_key());
    }

    public function test_undecryptable_returns_empty_and_sets_flag(): void {
        Peanut_Connect_Auth::set_hub_api_key('my-hub-key');
        $GLOBALS['mock_wp_salt'] = 'rotated-salt';
        $this->assertSame('', Peanut_Connect_Auth::get_hub_api_key());
        $this->assertNotEmpty(get_option('peanut_connect_hub_key_undecryptable'));
    }

    public function test_set_clears_undecryptable_flag(): void {
        update_option('peanut_connect_hub_key_undecryptable', 1);
        Peanut_Connect_Auth::set_hub_api_key('fresh-key');
        $this->assertFalse((bool) get_option('peanut_connect_hub_key_undecryptable', false));
    }

    public function test_clear_removes_key_and_flag(): void {
        Peanut_Connect_Auth::set_hub_api_key('my-hub-key');
        update_option('peanut_connect_hub_key_undecryptable', 1);
        Peanut_Connect_Auth::clear_hub_api_key();
        $this->assertSame('', Peanut_Connect_Auth::get_hub_api_key());
        $this->assertFalse((bool) get_option('peanut_connect_hub_key_undecryptable', false));
    }
}
