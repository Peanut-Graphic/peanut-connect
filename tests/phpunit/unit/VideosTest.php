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
        global $peanut_test_options, $mock_user_caps, $mock_remote_response, $peanut_last_http, $mock_pages;
        $peanut_test_options = [];
        $mock_pages = [];
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

    public function test_shortcode_without_slug_renders_comment(): void {
        $out = Peanut_Connect_Videos::shortcode([]);
        $this->assertStringContainsString('Peanut Video: No slug', $out);
    }

    public function test_shortcode_renders_responsive_hub_iframe(): void {
        global $peanut_test_options;
        $peanut_test_options['peanut_connect_hub_url'] = 'https://hub.example.com/';
        $out = Peanut_Connect_Videos::shortcode(['slug' => 'promo-abc']);
        $this->assertStringContainsString('https://hub.example.com/video/promo-abc/embed', $out);
        $this->assertStringContainsString('<iframe', $out);
        $this->assertStringContainsString('padding-top:56.25%', $out);
    }

    public function test_shortcode_not_connected_renders_nothing_visible(): void {
        $out = Peanut_Connect_Videos::shortcode(['slug' => 'x']);
        $this->assertStringContainsString('not connected', strtolower($out));
        $this->assertStringNotContainsString('<iframe', $out);
    }

    public function test_shortcode_preserves_mixed_case_slug(): void {
        global $peanut_test_options;
        $peanut_test_options['peanut_connect_hub_url'] = 'https://hub.example.com/';
        $out = Peanut_Connect_Videos::shortcode(['slug' => 'dominion-energy-ptr-aB3xZ9']);
        $this->assertStringContainsString('https://hub.example.com/video/dominion-energy-ptr-aB3xZ9/embed', $out);
        $this->assertStringNotContainsString('ab3xz9', $out); // not lowercased
    }

    public function test_block_render_callback_delegates_to_shortcode(): void {
        global $peanut_test_options;
        $peanut_test_options['peanut_connect_hub_url'] = 'https://hub.example.com';
        $html = Peanut_Connect_Videos::render_block(['slug' => 'promo-abc'], '');
        $this->assertStringContainsString('https://hub.example.com/video/promo-abc/embed', $html);
        $this->assertStringContainsString('<iframe', $html);
    }

    public function test_vtt_to_transcript_keeps_only_spoken_text(): void {
        $vtt = "WEBVTT\nKind: captions\n\nNOTE generated by whisper\nsecond note line\n\n1\n00:00.320 --> 00:03.920\nIf you have an electric oven,\n\n00:03.920 --> 00:07.280\n<i>cook dinner</i> with your slow cooker.\n";
        $this->assertSame(
            'If you have an electric oven, cook dinner with your slow cooker.',
            Peanut_Connect_Videos::vtt_to_transcript($vtt)
        );
    }

    public function test_vtt_to_transcript_handles_crlf_and_empty_input(): void {
        $this->assertSame('Hello there.', Peanut_Connect_Videos::vtt_to_transcript("WEBVTT\r\n\r\n00:00.000 --> 00:01.000\r\nHello there.\r\n"));
        $this->assertSame('', Peanut_Connect_Videos::vtt_to_transcript(''));
    }

    public function test_page_content_has_the_video_block_and_escaped_transcript(): void {
        $html = Peanut_Connect_Videos::page_content('ptr-cooking-xbDy62', '', 'Tips & <b>tricks</b>');
        $this->assertStringContainsString('<!-- wp:peanut-connect/video {"slug":"ptr-cooking-xbDy62"} /-->', $html);
        $this->assertStringContainsString('Transcript', $html);
        $this->assertStringContainsString('Tips &amp; &lt;b&gt;tricks&lt;/b&gt;', $html);
    }

    public function test_page_content_without_transcript_is_just_the_video(): void {
        $html = Peanut_Connect_Videos::page_content('abc-DEF123', '', '');
        $this->assertSame('<!-- wp:peanut-connect/video {"slug":"abc-DEF123"} /-->', $html);
    }

    public function test_captions_are_only_fetched_from_the_connected_hub_over_https(): void {
        $hub = 'https://hub.example.com';
        $this->assertTrue(Peanut_Connect_Videos::is_hub_url('https://hub.example.com/video/x/captions.vtt', $hub));
        $this->assertFalse(Peanut_Connect_Videos::is_hub_url('https://evil.example.net/x.vtt', $hub));
        $this->assertFalse(Peanut_Connect_Videos::is_hub_url('http://hub.example.com/x.vtt', $hub));
        $this->assertFalse(Peanut_Connect_Videos::is_hub_url('https://hub.example.com.evil.net/x.vtt', $hub));
        $this->assertFalse(Peanut_Connect_Videos::is_hub_url('https://hub.example.com/x.vtt', ''));
    }

    public function test_list_marks_videos_that_already_have_a_page(): void {
        global $peanut_test_options, $mock_remote_response, $mock_pages;
        $peanut_test_options['peanut_connect_hub_url'] = 'https://hub.example.com';
        $peanut_test_options['peanut_connect_hub_api_key'] = 'k';
        $mock_remote_response = [
            'response' => ['code' => 200],
            'body' => json_encode(['success' => true, 'data' => [
                ['id' => 1, 'slug' => 'has-page-AAAAAA', 'title' => 'A'],
                ['id' => 2, 'slug' => 'no-page-BBBBBB', 'title' => 'B'],
            ]]),
        ];
        $mock_pages = [['ID' => 77, 'status' => 'draft', 'meta' => [Peanut_Connect_Videos::PAGE_META => 'has-page-AAAAAA']]];

        $res = Peanut_Connect_Videos::list_videos(new WP_REST_Request('GET', '/videos'));
        $rows = $res->get_data()['data'];

        $this->assertSame(77, $rows[0]['page']['id']);
        $this->assertSame('draft', $rows[0]['page']['status']);
        $this->assertStringContainsString('preview=true', $rows[0]['page']['view_url']);
        $this->assertNull($rows[1]['page']);
    }

    public function test_create_page_returns_the_existing_page_instead_of_a_duplicate(): void {
        global $peanut_test_options, $mock_remote_response, $mock_pages;
        $peanut_test_options['peanut_connect_hub_url'] = 'https://hub.example.com';
        $peanut_test_options['peanut_connect_hub_api_key'] = 'k';
        $mock_remote_response = [
            'response' => ['code' => 200],
            'body' => json_encode(['success' => true, 'data' => [['id' => 5, 'slug' => 'dup-CCCCCC', 'title' => 'Dup']]]),
        ];
        $mock_pages = [['ID' => 90, 'status' => 'publish', 'meta' => [Peanut_Connect_Videos::PAGE_META => 'dup-CCCCCC']]];

        $req = new WP_REST_Request('POST', '/videos/5/page');
        $req['id'] = 5;
        $res = Peanut_Connect_Videos::create_page($req);

        $this->assertSame(200, $res->get_status());
        $this->assertSame(90, $res->get_data()['data']['id']);
        $this->assertFalse($res->get_data()['data']['created']);
    }

    public function test_create_page_404s_for_a_video_this_site_does_not_own(): void {
        global $peanut_test_options, $mock_remote_response;
        $peanut_test_options['peanut_connect_hub_url'] = 'https://hub.example.com';
        $peanut_test_options['peanut_connect_hub_api_key'] = 'k';
        $mock_remote_response = [
            'response' => ['code' => 200],
            'body' => json_encode(['success' => true, 'data' => [['id' => 5, 'slug' => 'mine-DDDDDD']]]),
        ];

        $req = new WP_REST_Request('POST', '/videos/999/page');
        $req['id'] = 999;
        $res = Peanut_Connect_Videos::create_page($req);

        $this->assertInstanceOf(WP_Error::class, $res);
        $this->assertSame(404, $res->get_error_data()['status']);
    }
}
