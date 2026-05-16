// Single source of truth for the GTM/tracker snippets shown on the Tracking
// page and in the campaign-builder Step 3. Pure string builders so they can
// be unit-tested and stay identical across both surfaces.

const HUB_FALLBACK = 'https://hub.peanutgraphic.com';
const KEY_FALLBACK = '<<your Site Key from Hub → Sites → Tracking>>';

// Tag 1 — loads the async tracker + fires a pageview. All pages, priority 100.
export function buildTrackerSnippet(
  hubUrl?: string | null,
  siteKey?: string | null
): string {
  const hub = hubUrl || HUB_FALLBACK;
  const key = siteKey && siteKey !== '—' ? siteKey : KEY_FALLBACK;
  return [
    '<script>',
    "(function(w,d,s,k,h){w.phub=w.phub||function(){(w.phub.q=w.phub.q||[]).push(arguments)};",
    'w.phub.k=k;w.phub.h=h;var f=d.getElementsByTagName(s)[0],j=d.createElement(s);',
    "j.async=true;j.src=h+'/js/tracker.min.js';f.parentNode.insertBefore(j,f);",
    `})(window,document,'script','${key}','${hub}');`,
    "phub('pageview');",
    '</script>',
  ].join('\n');
}

// Tag 2 — auto-instruments the enrollment form so step/field drop-off is
// captured (form_start / form_field / form_abandon). Fires on the enrollment
// page (DOM Ready). Tighten the 'form' selector to your form's id if needed.
export function buildFormTrackSnippet(): string {
  return [
    '<script>',
    '(function pnf() {',
    "  if (typeof phub !== 'function') { return setTimeout(pnf, 200); }",
    "  phub('form', 'track', 'form', { name: 'Enrollment', steps: 1 });",
    '})();',
    '</script>',
  ].join('\n');
}

// Tag 3 — enrollment conversion WITH visitor identity. `enroll` records the
// conversion, identifies the visitor by email (lights up per-account
// timelines), and auto-stitches form duration/steps from Tag 2. Wire the
// {{Form Email}} / {{Form Name}} GTM variables to your form fields.
export function buildConversionSnippet(): string {
  return [
    '<script>',
    '(function checkPnut() {',
    "  if (typeof phub === 'function') {",
    "    phub('enroll', { email: {{Form Email}}, name: {{Form Name}}, value: 0 });",
    '  } else { setTimeout(checkPnut, 100); }',
    '})();',
    '</script>',
  ].join('\n');
}
