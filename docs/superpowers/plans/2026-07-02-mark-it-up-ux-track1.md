# Mark It Up UX Round — Track 1 (peanut-connect 3.20.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship peanut-connect 3.20.0: reviewer edit/delete of own notes, first-run walkthrough, "Handled ✓" status feedback, All-pages view, `?pp_note` deep-link focus, and touch polish — all degrading gracefully against a pre-Track-2 HUB.

**Architecture:** Everything lives in the existing three files (`assets/js/feedback.js` vanilla-JS widget in a shadow root, `assets/css/feedback.css` injected into the shadow root, `includes/class-connect-feedback.php` REST relay). The relay stays a dumb proxy; ownership semantics live in HUB. New reviewer identity = random per-browser `author_key` UUID; ownership UI = note IDs remembered in localStorage (HUB never echoes `author_key`).

**Tech Stack:** WordPress plugin PHP 8 (no framework), vanilla JS (ES2017, single IIFE file), CSS. No build step; `scripts/package.sh` zips.

## Global Constraints

- Widget stays ONE dependency-free file; hard ceiling ~900 lines for `feedback.js` (currently 446).
- No emoji in UI — inline SVG line-art only (Nat's standing rule).
- Never use native browser dialogs for new UI (existing `window.prompt` creation flows stay as-is this round).
- Every HUB-dependent feature must degrade per the spec's Degradation matrix (spec §Degradation).
- All PHP changes pass `/opt/homebrew/bin/php -l`; release built only via `scripts/package.sh` on a clean tree.
- Manual verification happens on https://staging.cenhudpeakperks.com (open-access snippet active; wp-admin session available).
- localStorage keys used across tasks: `ppFeedbackAuthorKey`, `ppFeedbackMyNotes`, `ppFeedbackSeenIntro`, plus existing `ppFeedbackReviewerName`, `ppFeedbackPanel`.

---

### Task 1: Reviewer identity — author_key + my-notes bookkeeping

**Files:**
- Modify: `assets/js/feedback.js` (identity helpers near `reviewerName()` at ~line 18; the three `api('POST', '/feedback', …)` create calls at ~lines 217, 240, 296)

**Interfaces:**
- Produces: `authorKey(): string` (stable per-browser UUID), `rememberMine(id: number)`, `isMine(id: number): boolean` — used by Task 3 (edit/delete UI). Create payloads gain `author_key: authorKey()`.

- [ ] **Step 1: Add identity helpers after `reviewerName()`**

```js
  const AUTHOR_KEY = 'ppFeedbackAuthorKey';
  const MINE_KEY = 'ppFeedbackMyNotes';
  function authorKey() {
    let k = localStorage.getItem(AUTHOR_KEY);
    if (!k) {
      k = (crypto.randomUUID && crypto.randomUUID()) ||
        (Date.now().toString(36) + Math.random().toString(36).slice(2, 12));
      localStorage.setItem(AUTHOR_KEY, k);
    }
    return k;
  }
  function myNoteIds() {
    try { return JSON.parse(localStorage.getItem(MINE_KEY) || '[]'); } catch (e) { return []; }
  }
  function rememberMine(id) {
    const ids = myNoteIds();
    if (ids.indexOf(id) === -1) { ids.push(id); localStorage.setItem(MINE_KEY, JSON.stringify(ids.slice(-500))); }
  }
  function isMine(id) { return myNoteIds().indexOf(id) !== -1; }
```

- [ ] **Step 2: Send `author_key` in all three create payloads and remember returned IDs**

In `createHighlight`, `the point-placement click handler`, and `createDrawing`, add `author_key: authorKey(),` to the POSTed object (next to `author_name`), and inside each `.then((res) => { if (res && res.feedback) { …` add `rememberMine(res.feedback.id);` before `render()`.

- [ ] **Step 3: Verify in browser**

On staging (anonymous window): create one highlight note. In DevTools console on the page:
`localStorage.getItem('ppFeedbackAuthorKey')` → 36-char UUID; `localStorage.getItem('ppFeedbackMyNotes')` → `[<new id>]`. Network tab: POST body contains `author_key`. (Pre-Track-2 HUB ignores the extra field — verify the note still saves: 201.)

- [ ] **Step 4: Commit**

```bash
git add assets/js/feedback.js
git commit -m "feat(feedback): per-browser author_key + own-note bookkeeping"
```

### Task 2: Relay — DELETE and summary pass-throughs + agency flag on writes

**Files:**
- Modify: `includes/class-connect-feedback.php` (`register_routes()` at ~line 263; add `delete_item()` and `summary()` callbacks next to the existing `update()` callback; augment `update()`)

**Interfaces:**
- Consumes: existing `self::relay($method, $path, $body)` and `self::can_review`.
- Produces: REST routes `DELETE /peanut-connect/v1/feedback/{id}` (body: `{author_key}`), `GET /peanut-connect/v1/feedback/summary`. All write relays (PATCH/DELETE) inject `caller_is_agency` (WP-side truth) into the forwarded body — HUB's authorization (Track 2) trusts this flag, never the widget.

- [ ] **Step 1: Register the new routes**

In `register_routes()`, change the `/feedback/(?P<id>\d+)` registration to an array of two methods and add summary BEFORE the `(?P<id>\d+)` route (WP matches in registration order; `summary` must not be captured as an id — the `\d+` pattern already prevents that, but keep registration order tidy):

```php
        register_rest_route($ns, '/feedback/summary', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'summary'],
            'permission_callback' => [self::class, 'can_review'],
        ]);
        register_rest_route($ns, '/feedback/(?P<id>\d+)', [
            ['methods' => 'PATCH',  'callback' => [self::class, 'update'],      'permission_callback' => [self::class, 'can_review']],
            ['methods' => 'DELETE', 'callback' => [self::class, 'delete_item'], 'permission_callback' => [self::class, 'can_review']],
        ]);
```

- [ ] **Step 2: Add the callbacks and inject `caller_is_agency` in update()**

Find `update()`'s relay call; ensure the forwarded body is built like below, and add the two new methods adjacent:

```php
    public static function update(\WP_REST_Request $request) {
        $id   = (int) $request['id'];
        $body = array_intersect_key($request->get_json_params() ?: [], array_flip(['status', 'body', 'author_key']));
        $body['caller_is_agency'] = self::is_agency();
        if (self::is_agency()) {
            $u = wp_get_current_user();
            $body['resolver_name'] = $u && $u->display_name ? $u->display_name : 'Web Team';
        }
        return self::relay('PATCH', '/feedback/' . $id, $body);
    }

    public static function delete_item(\WP_REST_Request $request) {
        $id   = (int) $request['id'];
        $body = array_intersect_key($request->get_json_params() ?: [], array_flip(['author_key']));
        $body['caller_is_agency'] = self::is_agency();
        return self::relay('DELETE', '/feedback/' . $id, $body);
    }

    public static function summary(\WP_REST_Request $request) {
        return self::relay('GET', '/feedback/summary', null);
    }
```

(If the existing `update()` already builds its body differently, preserve its shape and add the `author_key` passthrough + `caller_is_agency` + `resolver_name` keys — do not drop `status`/`body` forwarding.)

- [ ] **Step 3: Lint + smoke**

Run: `/opt/homebrew/bin/php -l includes/class-connect-feedback.php` → "No syntax errors".
On staging after deploying the file (wp-admin plugin editor is blocked by loopback — test locally in the repo only this task; live smoke happens in Task 8's packaged deploy): skip live check here.

- [ ] **Step 4: Commit**

```bash
git add includes/class-connect-feedback.php
git commit -m "feat(feedback): relay DELETE + summary; assert caller_is_agency server-side on writes"
```

### Task 3: Tooltip v2 — Handled ✓ line, edit, delete

**Files:**
- Modify: `assets/js/feedback.js` (`showTip()` at ~line 172), `assets/css/feedback.css` (tooltip additions)

**Interfaces:**
- Consumes: `isMine(id)` (Task 1), relay PATCH/DELETE (Task 2).
- Produces: tooltip states used by Task 6 (deep-link opens the same `showTip`).

- [ ] **Step 1: Replace `showTip` with the stateful version**

```js
  function fmtDate(s) {
    try { const d = new Date(s); return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }); } catch (e) { return ''; }
  }
  const ICON_EDIT = '<svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M11.3 1.7l3 3L5 14l-3.6.6L2 11z"/></svg>';
  const ICON_TRASH = '<svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 4h12M5.5 4V2.5h5V4M4 4l.8 10h6.4L12 4M6.5 7v4M9.5 7v4"/></svg>';
  function showTip(mark, it) {
    tip.innerHTML = '';
    const label = document.createElement('div');
    label.className = 'pp-tip-label';
    label.textContent = (it.author_is_agency ? 'Review' : (it.author_name || 'Note'));
    const body = document.createElement('div');
    body.className = 'pp-tip-body';
    body.textContent = it.body || '';
    tip.append(label, body);
    if (it.status === 'done') {
      const done = document.createElement('div');
      done.className = 'pp-tip-done';
      const who = it.resolved_by_name ? ' · ' + it.resolved_by_name : '';
      const when = it.resolved_at ? ' · ' + fmtDate(it.resolved_at) : '';
      done.textContent = 'Handled ✓' + who + when;
      tip.appendChild(done);
    }
    if (isMine(it.id) && it.status !== 'done') {
      const bar = document.createElement('div');
      bar.className = 'pp-tip-actions';
      const editB = document.createElement('button'); editB.className = 'pp-tip-btn'; editB.innerHTML = ICON_EDIT + ' Edit';
      const delB = document.createElement('button'); delB.className = 'pp-tip-btn'; delB.innerHTML = ICON_TRASH + ' Delete';
      bar.append(editB, delB);
      tip.appendChild(bar);
      editB.addEventListener('click', (e) => { e.stopPropagation(); tipEditMode(it, body, bar); });
      delB.addEventListener('click', (e) => { e.stopPropagation(); tipConfirmDelete(it, bar); });
    }
    tip.style.left = (parseFloat(mark.style.left) || 0) + 'px';
    tip.style.top = ((parseFloat(mark.style.top) || 0) + 22) + 'px';
    tip.hidden = false;
  }
  function tipError(container, msg) {
    let err = tip.querySelector('.pp-tip-err');
    if (!err) { err = document.createElement('div'); err.className = 'pp-tip-err'; container.after(err); }
    err.textContent = msg;
  }
  function tipEditMode(it, bodyEl, bar) {
    bar.hidden = true;
    const ta = document.createElement('textarea');
    ta.className = 'pp-tip-edit'; ta.value = it.body || '';
    const save = document.createElement('button'); save.className = 'pp-tip-btn pp-primary'; save.textContent = 'Save';
    const cancel = document.createElement('button'); cancel.className = 'pp-tip-btn'; cancel.textContent = 'Cancel';
    const row = document.createElement('div'); row.className = 'pp-tip-actions'; row.append(save, cancel);
    bodyEl.replaceWith(ta); bar.before(row); ta.focus();
    cancel.addEventListener('click', (e) => { e.stopPropagation(); ta.replaceWith(bodyEl); row.remove(); bar.hidden = false; });
    save.addEventListener('click', (e) => {
      e.stopPropagation();
      api('PATCH', '/feedback/' + it.id, { body: ta.value, author_key: authorKey() }).then((res) => {
        if (res && res.success) { it.body = ta.value; render(); hideTip(); }
        else { tipError(row, "couldn't save — try again"); }
      }).catch(() => tipError(row, "couldn't save — try again"));
    });
  }
  function tipConfirmDelete(it, bar) {
    bar.hidden = true;
    const row = document.createElement('div'); row.className = 'pp-tip-actions';
    const q = document.createElement('span'); q.className = 'pp-tip-q'; q.textContent = 'Delete this note?';
    const yes = document.createElement('button'); yes.className = 'pp-tip-btn pp-danger'; yes.textContent = 'Delete';
    const no = document.createElement('button'); no.className = 'pp-tip-btn'; no.textContent = 'Keep';
    row.append(q, yes, no); bar.before(row);
    no.addEventListener('click', (e) => { e.stopPropagation(); row.remove(); bar.hidden = false; });
    yes.addEventListener('click', (e) => {
      e.stopPropagation();
      api('DELETE', '/feedback/' + it.id, { author_key: authorKey() }).then((res) => {
        if (res && res.success) { items = items.filter((x) => x.id !== it.id); render(); hideTip(); }
        else { tipError(row, "couldn't delete — try again"); }
      }).catch(() => tipError(row, "couldn't delete — try again"));
    });
  }
```

Note: `api()` already JSON-encodes a body for any method — DELETE with a body works through `fetch`.

- [ ] **Step 2: CSS for the new tooltip parts** (append to `assets/css/feedback.css`)

```css
.pp-tip-done { margin-top: 6px; font-size: 11px; opacity: .75; }
.pp-tip-actions { display: flex; gap: 6px; margin-top: 8px; align-items: center; }
.pp-tip-btn { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; padding: 3px 8px; border: 1px solid rgba(255,255,255,.4); border-radius: 4px; background: transparent; color: inherit; cursor: pointer; }
.pp-tip-btn.pp-primary { background: #2D6CDF; border-color: #2D6CDF; }
.pp-tip-btn.pp-danger { background: #DC2626; border-color: #DC2626; }
.pp-tip-edit { width: 100%; min-height: 56px; margin-top: 6px; font: inherit; }
.pp-tip-err { margin-top: 6px; font-size: 11px; color: #FCA5A5; }
.pp-tip-q { font-size: 11px; }
.pp-mark.pp-done { background: #9CA3AF; opacity: .7; }
```

- [ ] **Step 3: Also surface Handled ✓ in the panel list**

In `renderList()`, after `if (it.status === 'done') txt.classList.add('pp-strike');` add:

```js
      if (it.status === 'done' && (it.resolved_by_name || it.resolved_at)) {
        const meta = document.createElement('span'); meta.className = 'pp-row-meta';
        meta.textContent = 'Handled ✓' + (it.resolved_by_name ? ' · ' + it.resolved_by_name : '') + (it.resolved_at ? ' · ' + fmtDate(it.resolved_at) : '');
        li.appendChild(meta);
      }
```

And CSS: `.pp-row-meta { flex-basis: 100%; font-size: 10px; opacity: .6; padding-left: 24px; }`

- [ ] **Step 4: Verify on staging**

As anonymous reviewer: own note tooltip shows Edit/Delete; edit saves (against pre-Track-2 HUB, body PATCH already works — verify text updates); delete → against pre-Track-2 HUB relay 404s → inline "couldn't delete — try again" appears, note intact (degradation ✓). Someone else's note (create one as admin in another window): no action buttons. Done note (tick checkbox): tooltip shows "Handled ✓" (no name/date until Track 2).

- [ ] **Step 5: Commit**

```bash
git add assets/js/feedback.js assets/css/feedback.css
git commit -m "feat(feedback): tooltip edit/delete for own notes + Handled status line"
```

### Task 4: First-run walkthrough

**Files:**
- Modify: `assets/js/feedback.js` (panel construction ~line 316)

- [ ] **Step 1: Auto-open help once**

After `applyCollapsed();` in the panel setup add:

```js
  const INTRO_KEY = 'ppFeedbackSeenIntro';
  if (!localStorage.getItem(INTRO_KEY)) {
    panelState.open = true; applyCollapsed();
    const help = panel.querySelector('.pp-help');
    help.hidden = false;
    const got = document.createElement('button');
    got.className = 'pp-tip-btn pp-primary pp-gotit'; got.textContent = 'Got it';
    help.appendChild(got);
    got.addEventListener('click', () => { help.hidden = true; got.remove(); localStorage.setItem(INTRO_KEY, '1'); });
  }
```

CSS: `.pp-gotit { margin-top: 8px; }`

- [ ] **Step 2: Verify** — fresh incognito window on staging: panel open with the 4-step how-to + "Got it"; click → collapses help; reload → help stays hidden.

- [ ] **Step 3: Commit** — `git commit -am "feat(feedback): first-run walkthrough (panel introduces itself once)"`

### Task 5: Panel tabs + All-pages view

**Files:**
- Modify: `assets/js/feedback.js` (panel `innerHTML` + `renderList`), `assets/css/feedback.css`

**Interfaces:**
- Consumes: relay `GET /feedback/summary` (Task 2). Response shape (Track 2): `{ success, pages: [{ page_url, page_title, open_count, done_count, notes: [{id, body, author_name, status}] }] }`.

- [ ] **Step 1: Add tabs to panel markup**

In `panel.innerHTML`, after the `.pp-help` div, insert:

```html
<div class="pp-tabs"><button class="pp-tab pp-tab-on" data-tab="page">This page</button><button class="pp-tab" data-tab="site">All pages</button></div>
```

Wrap the existing filter+list as the "page" tab body and add a site body:

```html
<div class="pp-tabbody" data-tab="page"><div class="pp-filter">…existing…</div><ul class="pp-list"></ul></div>
<div class="pp-tabbody" data-tab="site" hidden><div class="pp-sitewide"></div></div>
```

- [ ] **Step 2: Tab switching + site-wide fetch/render**

```js
  let summaryCache = null;
  panel.querySelectorAll('.pp-tab').forEach((b) => b.addEventListener('click', () => {
    panel.querySelectorAll('.pp-tab').forEach((x) => x.classList.toggle('pp-tab-on', x === b));
    panel.querySelectorAll('.pp-tabbody').forEach((body) => { body.hidden = body.getAttribute('data-tab') !== b.getAttribute('data-tab'); });
    if (b.getAttribute('data-tab') === 'site' && !summaryCache) loadSummary();
  }));
  function loadSummary() {
    const box = panel.querySelector('.pp-sitewide');
    box.textContent = 'Loading…';
    api('GET', '/feedback/summary').then((res) => {
      if (!res || !res.pages) { box.textContent = 'Not available yet.'; return; }
      summaryCache = res.pages;
      box.innerHTML = '';
      res.pages.forEach((pg) => {
        const h = document.createElement('div'); h.className = 'pp-sw-page';
        h.textContent = (pg.page_title || pg.page_url) + ' — ' + pg.open_count + ' open, ' + pg.done_count + ' done';
        box.appendChild(h);
        (pg.notes || []).forEach((n) => {
          const a = document.createElement('a');
          a.className = 'pp-sw-note' + (n.status === 'done' ? ' pp-strike' : '');
          a.textContent = (n.author_name ? n.author_name + ': ' : '') + (n.body || '').slice(0, 80);
          a.href = pg.page_url + (pg.page_url.indexOf('?') === -1 ? '?' : '&') + 'pp_note=' + n.id;
          box.appendChild(a);
        });
      });
    }).catch(() => { box.textContent = 'Not available yet.'; });
  }
```

CSS:

```css
.pp-tabs { display: flex; gap: 2px; margin: 4px 0; }
.pp-tab { flex: 1; font-size: 11px; padding: 4px; border: none; background: rgba(0,0,0,.06); cursor: pointer; border-radius: 4px 4px 0 0; }
.pp-tab-on { background: #2D6CDF; color: #fff; }
.pp-sw-page { font-weight: 600; font-size: 11px; margin: 8px 0 2px; }
.pp-sw-note { display: block; font-size: 11px; padding: 2px 0 2px 10px; text-decoration: none; color: inherit; }
.pp-sw-note:hover { text-decoration: underline; }
```

- [ ] **Step 3: Verify** — staging (pre-Track-2 HUB): All pages tab shows "Not available yet." (relay route exists but HUB 404s → `res.pages` missing). This-page tab unchanged.

- [ ] **Step 4: Commit** — `git commit -am "feat(feedback): This page / All pages panel tabs with site-wide summary view"`

### Task 6: Deep-link focus (`?pp_note=<id>`)

**Files:**
- Modify: `assets/js/feedback.js` (`load()` at end of file; small helper)

- [ ] **Step 1: Focus after load**

Replace `load()` with:

```js
  function focusNote(id) {
    const it = items.find((x) => String(x.id) === String(id));
    if (!it) return;
    const mark = Array.from(overlay.querySelectorAll('.pp-mark')).find((m) => m.getAttribute('data-id') === String(it.id));
    if (!mark) return;
    const y = (parseFloat(mark.style.top) || 0) + window.scrollY - window.innerHeight / 2;
    window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
    setTimeout(() => { renderMarkers(); const m2 = Array.from(overlay.querySelectorAll('.pp-mark')).find((m) => m.getAttribute('data-id') === String(it.id)); if (m2) { showTip(m2, it); m2.classList.add('pp-pulse'); setTimeout(() => m2.classList.remove('pp-pulse'), 1600); } }, 450);
  }
  function load() {
    api('GET', '/feedback?page_url=' + encodeURIComponent(pageKey()))
      .then((res) => {
        items = (res && res.feedback) || []; render();
        const m = location.search.match(/[?&]pp_note=(\d+)/);
        if (m) focusNote(m[1]);
      });
  }
```

In `renderMarkers()`, add `mark.setAttribute('data-id', String(it.id));` next to the aria-label line.

CSS:

```css
@keyframes pp-pulse { 0%,100% { box-shadow: 0 0 0 0 rgba(220,38,38,.7); } 50% { box-shadow: 0 0 0 10px rgba(220,38,38,0); } }
.pp-mark.pp-pulse { animation: pp-pulse .8s ease-out 2; }
```

- [ ] **Step 2: Verify** — copy a note id from the panel (or HUB), open `…/residential/free-sensi-thermostat-program/?pp_note=<id>` in a fresh tab: page scrolls to the marker, tooltip opens, marker pulses twice. Bogus id: nothing happens, no console error.

- [ ] **Step 3: Commit** — `git commit -am "feat(feedback): ?pp_note deep-link scrolls to and opens a note"`

### Task 7: Touch polish

**Files:**
- Modify: `assets/js/feedback.js` (panel drag IIFE ~line 352; selectionchange handler ~line 200), `assets/css/feedback.css`

- [ ] **Step 1: Pointer-event panel drag** — replace the `drag()` IIFE's three listeners: `mousedown`→`pointerdown` (plus `head.setPointerCapture(e.pointerId)` in a try/catch), `mousemove`→`pointermove`, `mouseup`→`pointerup` + `pointercancel`; add `touch-action: none;` on `.pp-panel-head` in CSS.

- [ ] **Step 2: Selection-chip settle delay** — wrap the `selectionchange` handler body in a debounce:

```js
  let selTimer = null;
  document.addEventListener('selectionchange', () => {
    if (selTimer) clearTimeout(selTimer);
    selTimer = setTimeout(() => { /* existing handler body unchanged */ }, 250);
  });
```

- [ ] **Step 3: Coarse-pointer tap targets** (CSS):

```css
@media (pointer: coarse) {
  .pp-mark { width: 28px; height: 28px; font-size: 14px; }
  .pp-row input[type=checkbox] { width: 20px; height: 20px; }
  .pp-selchip { padding: 10px 14px; font-size: 14px; }
  .pp-tip-btn { padding: 8px 12px; font-size: 13px; }
}
```

- [ ] **Step 4: Verify** — Chrome DevTools device mode (iPhone + iPad): drag panel by header (moves, page doesn't scroll-drag), select text → chip appears after handles settle, tap targets comfortably large; draw still works with finger emulation.

- [ ] **Step 5: Commit** — `git commit -am "feat(feedback): touch polish — pointer drag, chip settle delay, coarse tap targets"`

### Task 8: Release 3.20.0

**Files:**
- Modify: `peanut-connect.php` (Version header + `PEANUT_CONNECT_VERSION`), `readme.txt` (Stable tag), `CHANGELOG.md`

- [ ] **Step 1: Bump versions** — `3.19.2` → `3.20.0` in the three places (same trio as every release).
- [ ] **Step 2: CHANGELOG entry** — Added: first-run walkthrough; edit/delete own note (author_key); Handled ✓ status line; This page/All pages tabs; `?pp_note` deep-link focus; touch polish. Changed: relay adds DELETE + summary pass-throughs and asserts `caller_is_agency`/`resolver_name` server-side on writes. Note the degradation matrix vs pre-3.20 HUB.
- [ ] **Step 3: Lint + build** — `/opt/homebrew/bin/php -l` both PHP files; commit everything; `bash scripts/package.sh` → `dist/peanut-connect-3.20.0.zip`.
- [ ] **Step 4: Deploy to staging via wp-admin upload-replace flow** (same as 3.19.2: Plugins → Add New → Upload → Replace current) and run the full manual matrix from the spec §Testing on staging: create/edit/delete each note type; deep-link each type; All-pages tab; first-run flag; touch viewports.
- [ ] **Step 5: Commit + tag** — `git commit -am "release: 3.20.0" && git tag v3.20.0` (push + GitHub release on Nat's go, per fleet pipeline).
