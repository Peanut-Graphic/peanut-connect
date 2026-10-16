import { describe, it, expect } from 'vitest';
import { preferredShortUrl } from './marketing';

describe('preferredShortUrl', () => {
  it('prefers the site-branded URL the plugin computed', () => {
    expect(
      preferredShortUrl({
        slug: 'ptm-october-postcard',
        short_url: 'https://hub.peanutgraphic.com/go/ptm-october-postcard',
        branded_url: 'https://pnmpowersaver.com/ptm-october-postcard',
      })
    ).toBe('https://pnmpowersaver.com/ptm-october-postcard');
  });

  it('falls back to Hub\'s short URL when the slug cannot be branded', () => {
    expect(
      preferredShortUrl({ slug: 'about', short_url: 'https://hub.example.test/go/about', branded_url: null })
    ).toBe('https://hub.example.test/go/about');
  });

  it('falls back to a relative slug when Hub sent no short URL', () => {
    expect(preferredShortUrl({ slug: 'x' })).toBe('/x');
  });
});
