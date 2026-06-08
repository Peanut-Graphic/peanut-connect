<?php
/**
 * tracking_setup must surface the PUBLIC tracker_key, never the Hub bearer.
 *
 * The bearer authorises /restore, /update, /banner, etc. — it must never reach
 * the browser or the copyable tracker snippet. The snippet uses the separate
 * tracker_key Hub delivers on heartbeat.
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

if (!function_exists('trailingslashit')) {
    function trailingslashit($s) {
        return rtrim((string) $s, '/') . '/';
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public $data;
        public $status;
        public function __construct($data = null, $status = 200) {
            $this->data = $data;
            $this->status = $status;
        }
        public function get_data() {
            return $this->data;
        }
    }
}

require_once dirname(__DIR__) . '/includes/class-connect-marketing.php';

class Test_Tracking_Setup_Key extends TestCase {

    protected function setUp(): void {
        global $mock_options;
        $mock_options = [];
    }

    public function test_returns_public_tracker_key_and_never_the_bearer(): void {
        global $mock_options;
        $mock_options['peanut_connect_hub_url']      = 'https://hub.example';
        $mock_options['peanut_connect_hub_api_key']  = 'SECRET-BEARER-zzz'; // privileged
        $mock_options['peanut_connect_tracker_key']  = 'public-tracker-abc';

        $data = Peanut_Connect_Marketing::tracking_setup()->get_data()['data'];

        $this->assertSame('public-tracker-abc', $data['site_key'], 'snippet must use the public tracker key');
        $this->assertTrue($data['tracker_ready']);
        $this->assertTrue($data['connected']);
        // The bearer value must appear NOWHERE in the response.
        $this->assertStringNotContainsString('SECRET-BEARER-zzz', json_encode($data));
    }

    public function test_tracker_not_ready_until_hub_sends_a_tracker_key(): void {
        global $mock_options;
        $mock_options['peanut_connect_hub_url']     = 'https://hub.example';
        $mock_options['peanut_connect_hub_api_key'] = 'SECRET-BEARER'; // connected, but JS tracker off
        // no peanut_connect_tracker_key yet

        $data = Peanut_Connect_Marketing::tracking_setup()->get_data()['data'];

        $this->assertSame('', $data['site_key']);
        $this->assertFalse($data['tracker_ready']);
        $this->assertTrue($data['connected']); // still connected for Hub commands
        $this->assertStringNotContainsString('SECRET-BEARER', json_encode($data));
    }
}
