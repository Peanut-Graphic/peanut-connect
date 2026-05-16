import { describe, it, expect } from 'vitest';
import {
  buildTrackerSnippet,
  buildFormTrackSnippet,
  buildConversionSnippet,
} from './trackingSnippets';

describe('buildTrackerSnippet', () => {
  it('embeds hub url + site key and fires a pageview', () => {
    const s = buildTrackerSnippet('https://hub.example.com', 'KEY123');
    expect(s).toContain("'KEY123','https://hub.example.com'");
    expect(s).toContain('/js/tracker.min.js');
    expect(s).toContain("phub('pageview');");
  });

  it('falls back to placeholders when hub/key absent or em-dash', () => {
    const s = buildTrackerSnippet(null, '—');
    expect(s).toContain('hub.peanutgraphic.com');
    expect(s).toContain('<<your Site Key');
  });
});

describe('buildFormTrackSnippet', () => {
  it('auto-instruments the enrollment form via phub form track, guarded until phub loads', () => {
    const s = buildFormTrackSnippet();
    expect(s).toContain("phub('form', 'track'");
    expect(s).toContain("name: 'Enrollment'");
    // must wait for the async loader rather than assume phub exists
    expect(s).toMatch(/typeof phub|setTimeout/);
  });
});

describe('buildConversionSnippet', () => {
  it('uses enroll with an email identity placeholder (not a bare conversion)', () => {
    const s = buildConversionSnippet();
    expect(s).toContain("phub('enroll'");
    expect(s).toContain('{{Form Email}}');
    // the old identity-less form must be gone
    expect(s).not.toContain("phub('conversion', 'enrollment', 0)");
    expect(s).toContain('checkPnut'); // keeps the load guard
  });
});
