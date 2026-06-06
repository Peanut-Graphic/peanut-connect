<?php
/**
 * Tests for the podcast transcript backfill helpers.
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/includes/helpers/transcript-block.php';

class TranscriptBlockTest extends TestCase {

    public function test_appends_block_when_absent(): void {
        $out = pc_apply_transcript_block('<p>Human intro.</p>', '<div>T</div>');

        $this->assertStringContainsString('<p>Human intro.</p>', $out);
        $this->assertStringContainsString('<!-- HB-TRANSCRIPT:start -->', $out);
        $this->assertStringContainsString('<div>T</div>', $out);
        $this->assertStringContainsString('<!-- HB-TRANSCRIPT:end -->', $out);
    }

    public function test_replaces_in_place_without_duplicating(): void {
        $first  = pc_apply_transcript_block('<p>Intro.</p>', '<div>OLD</div>');
        $second = pc_apply_transcript_block($first, '<div>NEW</div>');

        $this->assertSame(1, substr_count($second, '<!-- HB-TRANSCRIPT:start -->'));
        $this->assertStringContainsString('<div>NEW</div>', $second);
        $this->assertStringNotContainsString('OLD', $second);
        $this->assertStringContainsString('<p>Intro.</p>', $second);
    }

    public function test_preserves_content_outside_markers(): void {
        $content = "<p>Before.</p>\n<!-- HB-TRANSCRIPT:start -->\nX\n<!-- HB-TRANSCRIPT:end -->\n<p>After.</p>";
        $out = pc_apply_transcript_block($content, '<div>Y</div>');

        $this->assertStringContainsString('<p>Before.</p>', $out);
        $this->assertStringContainsString('<p>After.</p>', $out);
        $this->assertStringContainsString('<div>Y</div>', $out);
        $this->assertStringNotContainsString('>X<', $out);
    }

    public function test_merge_powerpress_urls_sets_transcript_and_chapters(): void {
        $settings = ['episode_title' => 'Ep', 'pci_transcript' => 0];
        $enclosure = "https://m/a.mp3\n123\naudio/mpeg\n" . serialize($settings);

        $out = pc_merge_powerpress_episode_urls(
            $enclosure,
            'https://hb/transcript.html',
            'https://hb/chapters.json'
        );

        $parts = explode("\n", $out);
        // URL/bytes/mime preserved.
        $this->assertSame('https://m/a.mp3', $parts[0]);
        $this->assertSame('123', $parts[1]);
        $restored = unserialize($parts[3]);
        $this->assertSame('Ep', $restored['episode_title']); // other settings preserved
        $this->assertSame(1, $restored['pci_transcript']);
        $this->assertSame('https://hb/transcript.html', $restored['pci_transcript_url']);
        $this->assertSame(1, $restored['pci_chapters']);
        $this->assertSame('https://hb/chapters.json', $restored['pci_chapters_url']);
    }

    public function test_merge_powerpress_urls_leaves_malformed_enclosure_untouched(): void {
        $this->assertSame('not-an-enclosure', pc_merge_powerpress_episode_urls('not-an-enclosure', 'x', 'y'));
    }

    public function test_merge_powerpress_urls_skips_empty_urls(): void {
        $enclosure = "https://m/a.mp3\n0\naudio/mpeg\n" . serialize(['x' => 1]);
        $out = pc_merge_powerpress_episode_urls($enclosure, '', '');
        $restored = unserialize(explode("\n", $out)[3]);

        $this->assertArrayNotHasKey('pci_transcript_url', $restored);
        $this->assertArrayNotHasKey('pci_chapters_url', $restored);
    }
}
