import { describe, it, expect } from 'vitest';
import { NAV } from './nav';

// Mirrors the route paths declared in App.tsx. Every nav href MUST be one of
// these (i.e. a real, rendered route). Keep this set in sync with App.tsx —
// the guard that prevents another "nav points nowhere / invisible tab" bug.
const KNOWN_ROUTES = new Set([
  '/', '/analytics', '/campaigns', '/analytics/journeys',
  '/analytics/dominion-funnel', '/videos', '/utms', '/links', '/tracking',
  '/health', '/analytics/gtm-coverage', '/errors', '/activity', '/updates',
  '/settings',
]);

describe('nav config', () => {
  it('has the five groups in the intended order', () => {
    expect(NAV.map((g) => g.group)).toEqual([
      'Overview', 'Performance', 'Tracking setup', 'Health', 'System',
    ]);
  });

  it('points every item at a real App.tsx route', () => {
    for (const group of NAV) {
      for (const item of group.items) {
        expect(KNOWN_ROUTES.has(item.href), `${item.label} -> ${item.href}`).toBe(true);
      }
    }
  });

  it('lists each destination at most once', () => {
    const hrefs = NAV.flatMap((g) => g.items.map((i) => i.href));
    expect(new Set(hrefs).size).toBe(hrefs.length);
  });

  it('places Dominion Funnel under Performance and Tracking Health under Health', () => {
    const perf = NAV.find((g) => g.group === 'Performance')!;
    const health = NAV.find((g) => g.group === 'Health')!;
    expect(perf.items.some((i) => i.label === 'Dominion Funnel')).toBe(true);
    expect(
      health.items.some((i) => i.label === 'Tracking Health' && i.href === '/analytics/gtm-coverage'),
    ).toBe(true);
  });
});
