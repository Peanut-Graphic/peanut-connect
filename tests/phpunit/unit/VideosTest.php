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

    public function test_create_video_forwards_post_to_hub_and_passes_envelope(): void {
        global $peanut_test_options, $mock_remote_response, $peanut_last_http;
        $peanut_test_options['peanut_connect_hub_url'] = 'https://hub.example.com';
        $peanut_test_options['peanut_connect_hub_api_key'] = 'k';
        $mock_remote_response = [
            'response' => ['code' => 201],
            'body' => json_encode(['success' => true, 'data' => [
                'id' => 9, 'slug' => 'promo-abc', 'title' => 'Promo',
                'embed_url' => 'https://hub.example.com/video/promo-abc/embed',
            ]]),
        ];

        $req = new WP_REST_Request('POST', '/videos');
        $req->set_body(json_encode(['title' => 'Promo', 'source_url' => 'https://wp.example.com/v.mp4']));
        $req->set_header('Content-Type', 'application/json');
        $res = Peanut_Connect_Videos::create_video($req);

        $this->assertInstanceOf(WP_REST_Response::class, $res);
        $this->assertSame(201, $res->get_status());
        $this->assertTrue($res->get_data()['success']);
        $this->assertSame('https://hub.example.com/api/v1/videos', $peanut_last_http['url']);
        $this->assertSame('POST', $peanut_last_http['args']['method']);
        $this->assertSame('Bearer k', $peanut_last_http['args']['headers']['Authorization']);
        $this->assertStringContainsString('"title":"Promo"', $peanut_last_http['args']['body']);
    }

    public function test_analytics_forwards_days_query(): void {
        global $peanut_test_options, $mock_remote_response, $peanut_last_http;
        $peanut_test_options['peanut_connect_hub_url'] = 'https://hub.example.com';
        $peanut_test_options['peanut_connect_hub_api_key'] = 'k';
        $mock_remote_response = [
            'response' => ['code' => 200],
            'body' => json_encode(['success' => true, 'data' => ['total_plays' => 0, 'drop_off_all_time' => []]]),
        ];
        $req = new WP_REST_Request('GET', '/videos/9/analytics');
        $req->set_query_params(['days' => '30']);
        $req['id'] = 9;
        $res = Peanut_Connect_Videos::video_analytics($req);
        $this->assertSame(200, $res->get_status());
        $this->assertStringContainsString('/api/v1/videos/9/analytics', $peanut_last_http['url']);
        $this->assertStringContainsString('days=30', $peanut_last_http['url']);
        $this->assertSame('Bearer k', $peanut_last_http['args']['headers']['Authorization']);
    }
}
