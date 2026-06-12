<?php
/**
 * Tests for the close-default Hub permission model. High-impact capabilities
 * must default OFF, the always-allowed read flags must stay on, a stored option
 * that predates a flag must still resolve it (merge over defaults), and an
 * owner's explicit stored choice must be preserved.
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-connect-auth.php';

class Test_Permission_Defaults extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        global $mock_options;
        $mock_options = [];
    }

    public function test_high_impact_capabilities_default_off(): void {
        // Nothing stored → canonical defaults.
        foreach (['perform_updates', 'publish_content', 'backup_restore', 'api_proxy'] as $cap) {
            $this->assertFalse(Peanut_Connect_Auth::has_permission($cap), "$cap must default off");
        }
    }

    public function test_read_capabilities_default_on(): void {
        $this->assertTrue(Peanut_Connect_Auth::has_permission('health_check'));
        $this->assertTrue(Peanut_Connect_Auth::has_permission('list_updates'));
        $this->assertTrue(Peanut_Connect_Auth::has_permission('access_analytics'));
    }

    public function test_get_permissions_returns_defaults_when_unset(): void {
        $this->assertSame(Peanut_Connect_Auth::DEFAULT_PERMISSIONS, Peanut_Connect_Auth::get_permissions());
    }

    public function test_stored_option_missing_a_newer_flag_still_resolves_it(): void {
        // A site paired before publish_content/backup_restore existed.
        update_option('peanut_connect_permissions', [
            'health_check' => true,
            'list_updates' => true,
            'perform_updates' => true,   // owner had enabled this
            'access_analytics' => true,
        ]);

        // Newer flags merge in as off...
        $this->assertFalse(Peanut_Connect_Auth::has_permission('publish_content'));
        $this->assertFalse(Peanut_Connect_Auth::has_permission('backup_restore'));
        // ...while the owner's explicit choice is preserved.
        $this->assertTrue(Peanut_Connect_Auth::has_permission('perform_updates'));
    }

    public function test_always_allowed_flags_ignore_stored_false(): void {
        update_option('peanut_connect_permissions', ['health_check' => false, 'list_updates' => false]);
        $this->assertTrue(Peanut_Connect_Auth::has_permission('health_check'));
        $this->assertTrue(Peanut_Connect_Auth::has_permission('list_updates'));
    }
}
