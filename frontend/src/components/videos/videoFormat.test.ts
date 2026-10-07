import { describe, it, expect } from 'vitest';
import { embedCodeFor, formatDuration, formatPlays, shortcodeFor } from './videoFormat';

describe('videoFormat', () => {
  it('builds the shortcode with the case-preserved slug', () => {
    expect(shortcodeFor({ slug: 'ptr-cooking-xbDy62' })).toBe('[peanut_video slug="ptr-cooking-xbDy62"]');
  });

  it('builds a responsive iframe embed pointing at the Hub player', () => {
    const code = embedCodeFor({ title: 'PTR Cooking', embed_url: 'https://hub.example/video/a/embed' });
    expect(code).toContain('src="https://hub.example/video/a/embed"');
    expect(code).toContain('padding-top:56.25%');
    expect(code).toContain('title="PTR Cooking"');
    expect(code).toContain('allowfullscreen');
  });

  it('escapes the title inside the embed attribute', () => {
    const code = embedCodeFor({ title: 'Heating & AC "tips" <b>', embed_url: 'https://h/v/b/embed' });
    expect(code).toContain('title="Heating &amp; AC &quot;tips&quot; &lt;b>"');
  });

  it('formats durations as m:ss and hides unknown ones', () => {
    expect(formatDuration(15)).toBe('0:15');
    expect(formatDuration(75)).toBe('1:15');
    expect(formatDuration(600)).toBe('10:00');
    expect(formatDuration(null)).toBeNull();
    expect(formatDuration(undefined)).toBeNull();
    expect(formatDuration(0)).toBeNull();
  });

  it('pluralizes plays', () => {
    expect(formatPlays(1)).toBe('1 play');
    expect(formatPlays(0)).toBe('0 plays');
    expect(formatPlays(1200)).toBe('1,200 plays');
  });
});
