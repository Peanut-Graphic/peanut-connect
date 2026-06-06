<?php
/**
 * Helpers for the podcast transcript backfill (augment-existing-post path).
 *
 * @package Peanut_Connect
 */

if (! defined('ABSPATH') && ! defined('PEANUT_CONNECT_TESTING')) {
    exit;
}

if (! function_exists('pc_apply_transcript_block')) {
    /**
     * Append or replace the Hullabaloo transcript block inside post content.
     *
     * The block is delimited by HB-TRANSCRIPT markers so re-runs replace it in
     * place and never duplicate. Content OUTSIDE the markers (the human intro,
     * schema markup, etc.) is never touched.
     *
     * @param string $content   Existing post_content.
     * @param string $block_html The transcript HTML to embed.
     * @return string New post_content.
     */
    function pc_apply_transcript_block(string $content, string $block_html): string {
        $start = '<!-- HB-TRANSCRIPT:start -->';
        $end   = '<!-- HB-TRANSCRIPT:end -->';
        $wrapped = $start . "\n" . $block_html . "\n" . $end;

        if (strpos($content, $start) !== false && strpos($content, $end) !== false) {
            $pattern = '/' . preg_quote($start, '/') . '.*?' . preg_quote($end, '/') . '/s';
            return (string) preg_replace($pattern, $wrapped, $content, 1);
        }

        return rtrim($content) . "\n\n" . $wrapped . "\n";
    }
}

if (! function_exists('pc_merge_powerpress_episode_urls')) {
    /**
     * Pure helper: merge transcript/chapters URLs into a PowerPress `enclosure`
     * meta string (4 newline-delimited fields, the 4th a serialized settings
     * array). Preserves the URL/bytes/mime and every other setting. Returns the
     * new enclosure string, or the original unchanged when it isn't the
     * expected 4-field shape (so we never corrupt an odd enclosure).
     *
     * @param string $enclosure     Existing enclosure postmeta value.
     * @param string $transcript_url Transcript URL ('' = leave as-is).
     * @param string $chapters_url   Chapters URL ('' = leave as-is).
     * @return string
     */
    function pc_merge_powerpress_episode_urls(string $enclosure, string $transcript_url, string $chapters_url): string {
        $parts = explode("\n", $enclosure);
        if (count($parts) < 4) {
            return $enclosure;
        }
        $settings = @unserialize($parts[3]);
        if (! is_array($settings)) {
            return $enclosure;
        }
        if ($transcript_url !== '') {
            $settings['pci_transcript'] = 1;
            $settings['pci_transcript_url'] = $transcript_url;
        }
        if ($chapters_url !== '') {
            $settings['pci_chapters'] = 1;
            $settings['pci_chapters_url'] = $chapters_url;
        }
        $parts[3] = serialize($settings);

        return implode("\n", $parts);
    }
}
