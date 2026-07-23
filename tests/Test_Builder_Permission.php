<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-connect-marketing.php';

class Test_Builder_Permission extends TestCase {
    protected function setUp(): void {
        $GLOBALS['pp_test_user_caps'] = [];
    }

    public function test_builder_cap_user_passes_the_builder_gate_but_not_the_admin_gate(): void {
        $GLOBALS['pp_test_user_caps'] = ['peanut_connect_build_utms' => true];
        $this->assertTrue(Peanut_Connect_Marketing::check_builder_permission());
        $this->assertFalse(Peanut_Connect_Marketing::check_admin_permission());
    }

    public function test_admin_passes_both_gates(): void {
        $GLOBALS['pp_test_user_caps'] = ['manage_options' => true];
        $this->assertTrue(Peanut_Connect_Marketing::check_builder_permission());
        $this->assertTrue(Peanut_Connect_Marketing::check_admin_permission());
    }

    public function test_a_random_logged_in_user_passes_neither(): void {
        $GLOBALS['pp_test_user_caps'] = ['read' => true];
        $this->assertFalse(Peanut_Connect_Marketing::check_builder_permission());
        $this->assertFalse(Peanut_Connect_Marketing::check_admin_permission());
    }
}
