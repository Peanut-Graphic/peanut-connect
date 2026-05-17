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
        ];
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
}
