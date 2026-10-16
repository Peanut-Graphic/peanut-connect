<?php
use PHPUnit\Framework\TestCase;
if (!defined('PEANUT_CONNECT_VERSION')) { define('PEANUT_CONNECT_VERSION', '3.40.1'); }
require_once dirname(__DIR__) . '/includes/class-connect-health.php';
class Test_Health_Connect_Version extends TestCase {
    public function test_old_cached_health_reports_the_current_runtime_version(): void {
        set_transient('peanut_connect_health', ['wp_version'=>['version'=>'6.8'], '_cached_at'=>'unchanged'], 300);
        $data = Peanut_Connect_Health::get_health_data();
        $this->assertSame(PEANUT_CONNECT_VERSION, $data['connect']['version'] ?? null);
        $this->assertSame('unchanged', $data['_cached_at']);
        $this->assertSame(['version'=>'6.8'], $data['wp_version']);
    }
    public function test_previous_cached_plugin_version_cannot_survive_an_upgrade(): void {
        set_transient('peanut_connect_health', ['connect'=>['version'=>'3.37.8']], 300);
        $this->assertSame(PEANUT_CONNECT_VERSION, Peanut_Connect_Health::get_health_data()['connect']['version']);
    }
    protected function tearDown(): void { delete_transient('peanut_connect_health'); parent::tearDown(); }
}
