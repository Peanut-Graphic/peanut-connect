import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

// Load the REAL tracker script (a raw IIFE at repo-root assets/js/tracker.js)
// and exercise its click handling in jsdom. This proves the click_to_portal
// beacon fires for portal-bound links — including "Customer Portal" wording
// that the old anchored primary-CTA regex missed (the live Dominion bug).
const trackerSrc = readFileSync(
  resolve(dirname(fileURLToPath(import.meta.url)), '../../../assets/js/tracker.js'),
  'utf-8',
);

interface Beacon {
  event_name: string;
  event_type: string;
}
let beacons: Beacon[] = [];

function bootTracker() {
  // Minimal config the IIFE reads as the global `peanutConnectTracker`.
  (globalThis as any).peanutConnectTracker = {
    restUrl: 'https://hub.example/wp-json/peanut-connect/v1',
    nonce: 'test-nonce',
    cookieName: 'pc_vid',
    cookieExpiry: 3600,
    clickIdCookie: 'pc_click',
    clickIdExpiry: 3600,
    visitorId: 'vid-1',
    clickId: 'click-1',
  };
  // Capture every beacon payload instead of sending it.
  (navigator as any).sendBeacon = (_url: string, blob: Blob) => {
    // jsdom Blob doesn't expose text() synchronously in all versions; read the
    // payload we stashed on the blob for the test (see the JSON.parse fallback).
    (blob as any)._text?.().then?.(() => {});
    return true;
  };
  // Simpler + deterministic: override Blob to record the JSON we were given.
  (globalThis as any).Blob = class {
    constructor(parts: string[]) {
      try {
        beacons.push(JSON.parse(parts[0]));
      } catch {
        /* non-JSON blob — ignore */
      }
    }
  };
  // Execute the tracker IIFE against the current jsdom document.
  // eslint-disable-next-line no-new-func
  new Function(trackerSrc)();
}

describe('tracker click_to_portal detection', () => {
  beforeEach(() => {
    beacons = [];
    document.body.innerHTML = '';
    document.title = 'Test';
  });
  afterEach(() => {
    vi.restoreAllMocks();
  });

  function clickLink(text: string, href = 'https://portal.example/app') {
    document.body.innerHTML = `<a id="cta" href="${href}">${text}</a>`;
    bootTracker();
    beacons = []; // drop the boot pageview beacon
    document.getElementById('cta')!.dispatchEvent(new MouseEvent('click', { bubbles: true }));
  }

  it('fires click_to_portal for a "Customer Portal" link (the missed Dominion case)', () => {
    clickLink('Customer Portal');
    expect(beacons.some((b) => b.event_name === 'click_to_portal')).toBe(true);
  });

  it('fires click_to_portal for "online customer portal"', () => {
    clickLink('online customer portal');
    expect(beacons.some((b) => b.event_name === 'click_to_portal')).toBe(true);
  });

  it('still fires for the classic "Enroll Now" primary CTA', () => {
    clickLink('Enroll Now');
    expect(beacons.some((b) => b.event_name === 'click_to_portal')).toBe(true);
  });

  it('fires for an explicitly marked element regardless of text', () => {
    document.body.innerHTML =
      '<div data-peanut-cta="portal"><a id="cta" href="https://x.example/go">Get my rebate</a></div>';
    bootTracker();
    beacons = [];
    document.getElementById('cta')!.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    expect(beacons.some((b) => b.event_name === 'click_to_portal')).toBe(true);
  });

  it('does NOT fire for an unrelated "Privacy Policy" link', () => {
    clickLink('Privacy Policy', 'https://site.example/privacy');
    expect(beacons.some((b) => b.event_name === 'click_to_portal')).toBe(false);
  });
});
