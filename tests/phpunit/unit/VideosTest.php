<?php
namespace Peanut_Connect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Peanut_Connect_Videos;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class VideosTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        global $peanut_test_options, $mock_user_caps, $mock_remote_response, $peanut_last_http;
        $peanut_test_options = [];
        $mock_user_caps = [];
        $mock_remote_response = null;
        $peanut_last_http = null;
    }

    public function test_admin_permission_blocks_non_admins(): void {
        global $mock_user_caps;
        $mock_user_caps['manage_options'] = false;
        $this->assertFalse(Peanut_Connect_Videos::check_admin_permission());
    }

    public function test_admin_permission_allows_admins(): void {
        global $mock_user_caps;
        $mock_user_caps['manage_options'] = true;
        $this->assertTrue(Peanut_Connect_Videos::check_admin_permission());
    }

    public function test_list_returns_412_when_not_connected(): void {
        $req = new WP_REST_Request('GET', '/videos');
        $res = Peanut_Connect_Videos::list_videos($req);
        $this->assertInstanceOf(WP_Error::class, $res);
        $this->assertSame(412, $res->get_error_data()['status']);
    }
}
