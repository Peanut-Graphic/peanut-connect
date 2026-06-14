<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-connect-secret.php';
require_once dirname(__DIR__) . '/includes/class-connect-auth.php';
require_once dirname(__DIR__) . '/includes/class-connect-rate-limiter.php';

// Stub classes required by class-connect-hub-sync.php at parse/load time.
if (!class_exists('Peanut_Connect_Health')) {
    class Peanut_Connect_Health {
        public function get_health_data(): array { return []; }
    }
}
if (!class_exists('Peanut_Connect_Activity_Log')) {
    class Peanut_Connect_Activity_Log {
        public static function log(string $type, string $status, int $count, array $ctx = []): void {}
        public static function log_disconnect(string $source): void {}
    }
}
if (!class_exists('Peanut_Connect_Database')) {
    class Peanut_Connect_Database {
        public static function table(string $name): string { return $name; }
    }
}

require_once dirname(__DIR__) . '/includes/class-connect-hub-sync.php';

class Test_Revocation_Detection extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        global $mock_options;
        $mock_options = [];
        $GLOBALS['mock_wp_salt'] = 's';
        Peanut_Connect_Auth::set_hub_api_key('k');
    }

    protected function tearDown(): void {
        unset($GLOBALS['mock_wp_salt']);
        parent::tearDown();
    }

    public function test_single_401_does_not_clear(): void {
        $cleared = Peanut_Connect_Hub_Sync::register_auth_failure();
        $this->assertFalse($cleared);
        $this->assertNotSame('', Peanut_Connect_Auth::get_hub_api_key());
    }

    public function test_two_401s_clear_key_and_flag_repair(): void {
        Peanut_Connect_Hub_Sync::register_auth_failure();
        $cleared = Peanut_Connect_Hub_Sync::register_auth_failure();
        $this->assertTrue($cleared);
        $this->assertSame('', Peanut_Connect_Auth::get_hub_api_key());
        $this->assertNotEmpty(get_option('peanut_connect_hub_key_undecryptable'));
    }

    public function test_reset_clears_counter(): void {
        Peanut_Connect_Hub_Sync::register_auth_failure();
        Peanut_Connect_Hub_Sync::reset_auth_failures();
        $this->assertSame(0, (int) get_option('peanut_connect_auth_fail_count', 0));
    }
}
