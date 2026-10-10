import { describe, it, expect, beforeEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

// The REAL tracker (assets/js/tracker.js) and popups script, run in jsdom.
//
// Before this fix the server printed the visitor id and click id into the
// page config (peanutConnectTracker.visitorId / .clickId) and the tracker
// trusted that over the browser's own cookie, then wrote it INTO the cookie.
// With a page cache the first visitor's HTML (and ids) is served to everyone
// after them: every later visitor was attached to visitor A's journey, and
// /identify from any of them relabelled A. The ids must come from this
// browser only.
const here = dirname(fileURLToPath(import.meta.url));
const trackerSrc = readFileSync(resolve(here, '../../../assets/js/tracker.js'), 'utf-8');
const popupsSrc = readFileSync(resolve(here, '../../../assets/js/popups.js'), 'utf-8');

const CACHED_VISITOR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const CACHED_CLICK = '11111111-1111-4111-8111-111111111111';
const MY_VISITOR = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
const MY_CLICK = '22222222-2222-4222-8222-222222222222';

interface Sent { visitor_id?: string; click_id?: string; event_type?: string }
let sent: Sent[] = [];

function clearCookies() {
  document.cookie.split(';').forEach((c) => {
    const name = c.split('=')[0].trim();
    if (name) document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
  });
}

function cookie(name: string): string | null {
  const m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
  return m ? m[1] : null;
}

function bootTracker(extraConfig: Record<string, unknown> = {}) {
  // A cached page: config still carries ANOTHER visitor's ids (the shape the
  // old server code printed). The tracker must ignore them.
  (globalThis as any).peanutConnectTracker = {
    restUrl: 'https://site.example/wp-json/peanut-connect/v1',
    nonce: 'n',
    cookieName: 'peanut_vid',
    cookieExpiry: 3600,
    clickIdCookie: 'peanut_click_id',
    clickIdExpiry: 3600,
    visitorId: CACHED_VISITOR,
    clickId: CACHED_CLICK,
    ...extraConfig,
  };
  (navigator as any).sendBeacon = () => true;
  (globalThis as any).Blob = class {
    constructor(parts: string[]) {
      try { sent.push(JSON.parse(parts[0])); } catch { /* ignore */ }
    }
  };
  // eslint-disable-next-line no-new-func
  new Function(trackerSrc)();
}

describe('tracker ids are per-browser, never from the (cacheable) page config', () => {
  beforeEach(() => {
    sent = [];
    clearCookies();
    document.body.innerHTML = '';
    window.history.replaceState({}, '', '/');
  });

  it('keeps the browser\'s own visitor cookie over a cached config id', () => {
    document.cookie = `peanut_vid=${MY_VISITOR}; path=/`;
    bootTracker();

    const pageview = sent.find((e) => e.event_type === 'pageview');
    expect(pageview?.visitor_id).toBe(MY_VISITOR);
    expect(cookie('peanut_vid')).toBe(MY_VISITOR);
  });

  it('mints its own id for a new browser instead of adopting the cached one', () => {
    bootTracker();

    const id = cookie('peanut_vid');
    expect(id).toMatch(/^[a-f0-9]{32}$/);
    expect(id).not.toBe(CACHED_VISITOR);
    expect(sent.find((e) => e.event_type === 'pageview')?.visitor_id).toBe(id);
  });

  it('two browsers served the same cached page get different ids', () => {
    bootTracker();
    const first = cookie('peanut_vid');
    clearCookies();
    sent = [];
    bootTracker();
    expect(cookie('peanut_vid')).not.toBe(first);
  });

  it('never sends a click id it only saw in the page config', () => {
    bootTracker();
    expect(sent.every((e) => e.click_id !== CACHED_CLICK)).toBe(true);
  });

  it('uses the click id from this browser\'s URL or cookie', () => {
    window.history.replaceState({}, '', `/?click_id=${MY_CLICK}`);
    bootTracker();
    expect(sent.find((e) => e.event_type === 'pageview')?.click_id).toBe(MY_CLICK);
  });

  it('ignores a malformed click id in the URL', () => {
    window.history.replaceState({}, '', '/?click_id=------------------------------------');
    bootTracker();
    expect(sent.find((e) => e.event_type === 'pageview')?.click_id).toBeUndefined();
  });
});

describe('popups send this browser\'s visitor id', () => {
  beforeEach(() => {
    clearCookies();
    document.body.innerHTML = '<div id="peanut-connect-popups-container"></div>';
  });

  it('reads the visitor cookie, not a cached config id', async () => {
    document.cookie = `peanut_vid=${MY_VISITOR}; path=/`;
    const bodies: any[] = [];
    (globalThis as any).fetch = (_url: string, init: any) => {
      bodies.push(JSON.parse(init.body));
      return Promise.resolve({ json: () => Promise.resolve({}) });
    };
    (globalThis as any).peanutConnectPopups = {
      restUrl: 'https://site.example/wp-json/peanut-connect/v1',
      nonce: 'n',
      cookieName: 'peanut_vid',
      visitorId: CACHED_VISITOR,
      popups: [{ id: 5, type: 'modal', trigger: 'immediate', title: 'Hi', content: 'x', settings: {} }],
    };
    // eslint-disable-next-line no-new-func
    new Function(popupsSrc)();
    await new Promise((r) => setTimeout(r, 50));

    expect(bodies.length).toBeGreaterThan(0);
    for (const b of bodies) {
      expect(b.visitor_id).not.toBe(CACHED_VISITOR);
      expect(b.visitor_id).toBe(MY_VISITOR);
    }
  });
});
