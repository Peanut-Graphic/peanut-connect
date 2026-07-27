<?php
use PHPUnit\Framework\TestCase;

if (!function_exists('add_role')) {
    function add_role($role, $name, $caps) {
        $GLOBALS['pp_roles'][$role] = ['name' => $name, 'caps' => $caps];
    }
}
if (!function_exists('remove_role')) {
    function remove_role($role) { unset($GLOBALS['pp_roles'][$role]); }
}
if (!function_exists('add_filter')) {
    function add_filter($h, $cb) { $GLOBALS['pp_filters'][$h] = $cb; }
}
if (!function_exists('add_action')) {
    function add_action($h, $cb) { $GLOBALS['pp_actions'][$h] = $cb; }
}
if (!function_exists('admin_url')) {
    function admin_url($path = '') { return 'https://example.com/wp-admin/' . ltrim($path, '/'); }
}
if (!class_exists('WP_User')) {
    class WP_User { public array $caps = []; }
}
if (!function_exists('user_can')) {
    function user_can($user, $cap) { return !empty($user->caps[$cap]); }
}

require_once dirname(__DIR__) . '/includes/class-connect-roles.php';

class Test_Roles extends TestCase {
    protected function setUp(): void {
        global $mock_options, $pp_roles;
        $mock_options = [];
        $pp_roles = [];
    }

    public function test_install_creates_the_builder_role_with_exactly_read_and_the_cap(): void {
        Peanut_Connect_Roles::install();
        $role = $GLOBALS['pp_roles']['peanut_utm_builder'] ?? null;
        $this->assertNotNull($role);
        $this->assertSame('UTM Builder', $role['name']);
        $this->assertSame(
            ['read' => true, 'peanut_connect_build_utms' => true],
            $role['caps'],
        );
    }

    public function test_uninstall_removes_the_role(): void {
        Peanut_Connect_Roles::install();
        Peanut_Connect_Roles::uninstall();
        $this->assertArrayNotHasKey('peanut_utm_builder', $GLOBALS['pp_roles']);
    }

    public function test_admins_get_the_builder_cap_at_runtime(): void {
        $granted = Peanut_Connect_Roles::grant_builder_cap_to_admins(['manage_options' => true]);
        $this->assertTrue($granted['peanut_connect_build_utms']);

        $notAdmin = Peanut_Connect_Roles::grant_builder_cap_to_admins(['read' => true]);
        $this->assertArrayNotHasKey('peanut_connect_build_utms', $notAdmin);
    }

    public function test_is_builder_confined_only_for_cap_holders_who_are_not_admins(): void {
        // Holds the builder cap but isn't a full admin -> confined to the wizard.
        $this->assertTrue(Peanut_Connect_Roles::is_builder_confined(true, false));
        // Admins keep full access, never confined.
        $this->assertFalse(Peanut_Connect_Roles::is_builder_confined(true, true));
        // No builder cap -> not our concern.
        $this->assertFalse(Peanut_Connect_Roles::is_builder_confined(false, false));
    }

    public function test_builder_login_redirect_sends_confined_users_to_the_wizard(): void {
        $builder = new WP_User();
        $builder->caps = ['peanut_connect_build_utms' => true]; // cap, not admin
        $this->assertStringContainsString(
            'admin.php?page=peanut-connect-app',
            Peanut_Connect_Roles::builder_login_redirect('/wp-admin/', '', $builder),
        );

        // An admin who also has the cap is NOT redirected — keeps the default.
        $admin = new WP_User();
        $admin->caps = ['peanut_connect_build_utms' => true, 'manage_options' => true];
        $this->assertSame(
            '/wp-admin/',
            Peanut_Connect_Roles::builder_login_redirect('/wp-admin/', '', $admin),
        );
    }

    public function test_maybe_install_is_idempotent_by_version(): void {
        Peanut_Connect_Roles::install();          // sets version option
        $GLOBALS['pp_roles'] = [];                 // simulate role gone
        Peanut_Connect_Roles::maybe_install();     // version matches -> no-op
        $this->assertArrayNotHasKey('peanut_utm_builder', $GLOBALS['pp_roles']);
    }
}
