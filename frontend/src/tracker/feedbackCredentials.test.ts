import { describe, it, expect, beforeEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

// The REAL feedback widget (assets/js/feedback.js), run in jsdom.
//
// Before this fix the server printed the review token (copied out of the
// HttpOnly pp_review cookie) into peanutConnectFeedback.reviewToken, so a
// page cache that stored a reviewer's page handed the token to everyone.
// The widget must take review credentials from its own URL / the cookie
// (server-side), never from the page config, and must send an approver's
// personal key only for that approver.
const here = dirname(fileURLToPath(import.meta.url));
const feedbackSrc = readFileSync(resolve(here, '../../../assets/js/feedback.js'), 'utf-8');

interface Call { url: string; method: string; headers: Record<string, string>; body: any }
let calls: Call[] = [];

function boot(url: string, cfg: Record<string, unknown> = {}) {
  window.history.replaceState({}, '', url);
  document.body.innerHTML = '<div id="peanut-connect-feedback-root"></div>';
  calls = [];
  (globalThis as any).fetch = (u: string, init: any) => {
    calls.push({ url: u, method: init.method, headers: init.headers, body: init.body ? JSON.parse(init.body) : null });
    return Promise.resolve({ json: () => Promise.resolve({ success: true, items: [], votes: {}, ready: [] }) });
  };
  (window as any).__ppFeedbackCss = '';
  // The shared test setup stubs ResizeObserver with a non-constructible
  // vi.fn(); the widget only uses it to persist panel size.
  (window as any).ResizeObserver = undefined;
  (window as any).peanutConnectFeedback = {
    restUrl: 'https://site.example/wp-json/peanut-connect/v1/feedback',
    nonce: 'n',
    isAgency: false,
    approvers: [
      { id: 'pat', name: 'Pat Owner', initials: 'PO', required: true },
      { id: 'sam', name: 'Sam Legal', initials: 'SL', required: true },
    ],
    // What a cached page used to carry: someone else's token.
    reviewToken: 'CACHED-SOMEONE-ELSES-TOKEN',
    ...cfg,
  };
  // eslint-disable-next-line no-new-func
  new Function(feedbackSrc)();
}

describe('feedback widget review credentials', () => {
  beforeEach(() => {
    localStorage.clear();
  });

  it('never sends a review token taken from the page config', () => {
    boot('/about/');
    expect(calls.length).toBeGreaterThan(0);
    for (const c of calls) {
      expect(c.headers['X-Peanut-Review-Token']).toBeUndefined();
      // Cookie-borne reviewer: the server reads the HttpOnly cookie.
      expect(c.headers['X-Peanut-Review']).toBe('1');
    }
  });

  it('sends the token from its own URL when the reviewer arrived on a review link', () => {
    boot('/about/?pp_review=url-token-123');
    expect(calls.length).toBeGreaterThan(0);
    for (const c of calls) {
      expect(c.headers['X-Peanut-Review-Token']).toBe('url-token-123');
    }
  });

  it('sends the approver key only when voting as that approver', async () => {
    boot('/pricing/?pp_review=t&pp_as=pat&pp_ak=0123456789abcdef0123456789abcdef');
    const shadow = document.getElementById('peanut-connect-feedback-root')!.shadowRoot!;
    await new Promise((r) => setTimeout(r, 0));

    const chips = Array.from(shadow.querySelectorAll('.pp-approve-chips .pp-chip')) as HTMLButtonElement[];
    const pat = chips.find((b) => b.textContent === 'PO')!;
    const sam = chips.find((b) => b.textContent === 'SL')!;

    // Clicking someone else's chip: told to use their own link, no request.
    calls = [];
    sam.click();
    expect(shadow.querySelector('.pp-approve-flow')!.textContent).toMatch(/Only Sam Legal can sign off/);
    expect(calls.filter((c) => c.url.includes('/approvals/vote'))).toHaveLength(0);

    // Own chip: YES sends the personal key.
    pat.click();
    (shadow.querySelector('.pp-approve-yes') as HTMLButtonElement).click();
    const vote = calls.find((c) => c.url.includes('/approvals/vote'))!;
    expect(vote.body.approver_id).toBe('pat');
    expect(vote.headers['X-Peanut-Approver-Key']).toBe('0123456789abcdef0123456789abcdef');
  });

  it('strips the approver key from page keys', () => {
    boot('/pricing/?pp_review=t&pp_as=pat&pp_ak=abc');
    const approvals = calls.find((c) => c.url.includes('/approvals?path='))!;
    expect(decodeURIComponent(approvals.url.split('path=')[1])).toBe('/pricing/');
  });
});
