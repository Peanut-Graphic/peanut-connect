<?php
/**
 * Tests for the podcast publish endpoint's PowerPress enclosure builder.
 *
 * Verifies the exact PowerPress 11.16.5 `enclosure` postmeta contract:
 * 4 newline-delimited fields, the 4th a PHP-serialized settings array.
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

class PodcastPublishTest extends TestCase {

    private function settings(): array {
        return [
            'duration' => '0:15:00',
            'explicit' => '2',
            'episode_title' => 'Shingles',
            'episode_no' => 471,
            'season' => '3',
            'episode_type' => 'full',
            'pci_transcript' => 1,
            'pci_transcript_url' => 'https://hullabaloo.peanutgraphic.com/podcast/episodes/541/transcript.srt',
            'pci_chapters' => 1,
            'pci_chapters_url' => 'https://hullabaloo.peanutgraphic.com/podcast/episodes/541/chapters.json',
        ];
    }

    public function test_chapters_settings_round_trip_through_powerpress_meta(): void {
        $meta = Peanut_Connect_API::build_powerpress_enclosure_meta([
            'url' => 'https://media.example.com/a.mp3',
            'bytes' => 123,
            'mime' => 'audio/mpeg',
            'settings' => $this->settings(),
        ]);

        $restored = unserialize(explode("\n", $meta)[3]);

        $this->assertSame(1, $restored['pci_chapters']);
        $this->assertSame(
            'https://hullabaloo.peanutgraphic.com/podcast/episodes/541/chapters.json',
            $restored['pci_chapters_url']
        );
    }

    public function test_enclosure_meta_has_four_newline_delimited_fields(): void {
        $meta = Peanut_Connect_API::build_powerpress_enclosure_meta([
            'url' => 'https://media.blubrry.com/bumperpodcast/x.mp3',
            'bytes' => 18002526,
            'mime' => 'audio/mpeg',
            'settings' => $this->settings(),
        ]);

        $parts = explode("\n", $meta);
        $this->assertCount(4, $parts);
        $this->assertSame('https://media.blubrry.com/bumperpodcast/x.mp3', $parts[0]);
        $this->assertSame('18002526', $parts[1]);
        $this->assertSame('audio/mpeg', $parts[2]);
    }

    public function test_fourth_field_round_trips_through_unserialize(): void {
        $meta = Peanut_Connect_API::build_powerpress_enclosure_meta([
            'url' => 'https://media.example.com/a.mp3',
            'bytes' => 123,
            'mime' => 'audio/mpeg',
            'settings' => $this->settings(),
        ]);

        $parts = explode("\n", $meta);
        $restored = unserialize($parts[3]);

        $this->assertIsArray($restored);
        $this->assertSame('Shingles', $restored['episode_title']);
        $this->assertSame(471, $restored['episode_no']);
        $this->assertSame('0:15:00', $restored['duration']);
        $this->assertSame(1, $restored['pci_transcript']);
    }

    public function test_defaults_when_bytes_and_mime_missing(): void {
        $meta = Peanut_Connect_API::build_powerpress_enclosure_meta([
            'url' => 'https://media.example.com/a.mp3',
            'settings' => [],
        ]);

        $parts = explode("\n", $meta);
        $this->assertSame('0', $parts[1]);
        $this->assertSame('audio/mpeg', $parts[2]);
        $this->assertSame([], unserialize($parts[3]));
    }

    // ---- publish_podcast_episode(): episode topics -> post tags ----

    protected function setUp(): void {
        parent::setUp();
        global $mock_pages;
        $mock_pages = [];
    }

    protected function tearDown(): void {
        global $mock_pages;
        $mock_pages = [];
        parent::tearDown();
    }

    private function publish(array $extra = []): WP_REST_Response {
        $request = new WP_REST_Request('POST', '/peanut-connect/v1/podcast/publish');
        foreach (array_merge([
            'guid' => 'hb-episode-541',
            'title' => 'Shingles',
            'enclosure_url' => 'https://media.example.com/541.mp3',
        ], $extra) as $k => $v) {
            $request->set_param($k, $v);
        }
        return (new Peanut_Connect_API())->publish_podcast_episode($request);
    }

    private function tags_of(int $post_id): array {
        global $mock_pages;
        foreach ($mock_pages as $p) {
            if ($p['ID'] === $post_id) {
                return $p['tags'] ?? [];
            }
        }
        $this->fail("post {$post_id} not in mock store");
    }

    private function seed_episode_post(array $tags): int {
        global $mock_pages;
        $mock_pages[] = [
            'ID' => 42,
            'status' => 'publish',
            'post_type' => 'post',
            'meta' => ['peanut_episode_guid' => 'hb-episode-541'],
            'tags' => $tags,
        ];
        return 42;
    }

    public function test_tags_applied_on_create(): void {
        $res = $this->publish(['tags' => ['Comedy', 'Health', '  ', '<b>Shingles</b>', 'Comedy']]);

        $this->assertSame(200, $res->get_status());
        $data = $res->get_data()['data'];
        $this->assertSame('create', $data['action']);
        $this->assertSame(['Comedy', 'Health', 'Shingles'], $this->tags_of($data['wp_post_id']));
    }

    public function test_tags_replaced_on_update(): void {
        $id = $this->seed_episode_post(['Old Topic', 'Stale']);

        $res = $this->publish(['tags' => ['Comedy', 'Health']]);

        $data = $res->get_data()['data'];
        $this->assertSame('update', $data['action']);
        $this->assertSame($id, $data['wp_post_id']);
        $this->assertSame(['Comedy', 'Health'], $this->tags_of($id));
    }

    public function test_absent_tags_leave_existing_tags_untouched(): void {
        $id = $this->seed_episode_post(['Hand Typed']);

        $res = $this->publish();

        $this->assertSame('update', $res->get_data()['data']['action']);
        $this->assertSame(['Hand Typed'], $this->tags_of($id));
    }

    public function test_empty_tags_leave_existing_tags_untouched(): void {
        $id = $this->seed_episode_post(['Hand Typed']);

        $this->publish(['tags' => []]);
        $this->assertSame(['Hand Typed'], $this->tags_of($id));

        // All-blank entries sanitize down to nothing -> also a no-op.
        $this->publish(['tags' => ['', '   ']]);
        $this->assertSame(['Hand Typed'], $this->tags_of($id));
    }

    public function test_non_array_tags_are_ignored(): void {
        $id = $this->seed_episode_post(['Hand Typed']);

        $this->publish(['tags' => 'Comedy, Health']);

        $this->assertSame(['Hand Typed'], $this->tags_of($id));
    }

    public function test_dry_run_returns_sanitized_tags_without_writing(): void {
        $id = $this->seed_episode_post(['Hand Typed']);

        $res = $this->publish(['dry_run' => true, 'tags' => ['Comedy', '<i>Health</i>', '', ['nested']]]);

        $body = $res->get_data();
        $this->assertTrue($body['dry_run']);
        $this->assertSame(['Comedy', 'Health'], $body['data']['tags']);
        $this->assertSame(['Hand Typed'], $this->tags_of($id));
    }

    public function test_dry_run_without_tags_returns_empty_list(): void {
        $res = $this->publish(['dry_run' => true]);

        $this->assertSame([], $res->get_data()['data']['tags']);
    }
}
