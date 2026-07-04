# Mark It Up — UX Round (peanut-connect 3.20.0 + HUB)

**Date:** 2026-07-02
**Status:** Approved design, awaiting implementation plan
**Scope:** Two releases — peanut-connect 3.20.0 (Track 1), then one HUB PR (Track 2)

## Purpose

Flesh out Mark It Up into a stronger review service with thoughtful, modest
improvements for all three participants: the client reviewer on the page, the
team triaging in HUB, and the loop between them. No architectural changes; the
widget stays a single dependency-free vanilla-JS file.

## Decisions made during design

| Question | Decision |
| --- | --- |
| Who to optimize for | All three: reviewer, team, and the loop — evenly |
| New-note notifications | Fold into the existing daily ecosystem digest. No new notification channel. |
| Client-visible replies | **No.** Status-only feedback on the page ("Handled ✓ · name · date"). Replies stay agency-internal. |
| Reviewer identity for edit/delete | Random per-browser `author_key` (UUID in localStorage) sent at creation; required for edit/delete. No accounts, no fake auth. |
| Out of scope | Client-visible replies, context screenshots, bulk actions, new notification channels, reviewer accounts, anchor-format changes. |

## Track 1 — peanut-connect 3.20.0 (widget + relay)

All items live in `assets/js/feedback.js`, `assets/css/feedback.css`, and
`includes/class-connect-feedback.php`. Every HUB-dependent feature degrades
gracefully against an older HUB (see Degradation).

### 1.1 First-run walkthrough
- On first load in review mode per browser (`localStorage` flag
  `ppFeedbackSeenIntro`), the panel opens automatically with the existing help
  content shown inline plus a "Got it" button that collapses it and sets the flag.
- Not a modal, not an overlay tour. Returning visitors see current behavior.

### 1.2 Status feedback ("Handled ✓")
- When a note has `status=done`, the marker tooltip and the panel list row show
  a subdued line: `Handled ✓ · {resolved_by_name} · {resolved_at date}`.
- If the API payload lacks `resolved_by_name`/`resolved_at` (older HUB), show
  just `Handled ✓`.
- Done markers render clearly distinct (grey/faded, existing `.pp-done` class
  strengthened in CSS).

### 1.3 Edit / delete own note
- On first note creation the widget generates `author_key` = random UUID,
  stored in `localStorage` (`ppFeedbackAuthorKey`), sent in the create payload.
- The widget also records IDs of notes it created (`ppFeedbackMyNotes`), since
  `author_key` is never echoed back by the API. Ownership UI = note ID in that
  list.
- Tooltip shows pencil (edit) and trash (delete) controls — line-art SVG, no
  emoji — only for owned notes.
  - **Edit:** inline textarea replacing the body inside the tooltip; Save /
    Cancel. `PATCH /feedback/{id}` with `{ body, author_key }`.
  - **Delete:** inline two-step confirm inside the tooltip ("Delete this note?
    Delete / Keep"). Never a native browser dialog. `DELETE /feedback/{id}`
    with `author_key`.
- On non-2xx: show "couldn't save — try again" inside the tooltip; typed text
  is never lost; note state unchanged.

### 1.4 Site-wide notes view ("All pages" tab)
- Panel gets two small tabs: **This page** (existing list, unchanged behavior)
  and **All pages**.
- All pages = grouped list per page: page title, open/done counts, then note
  rows. Data from new relay endpoint `GET /feedback/summary`.
- Clicking a note on another page navigates to
  `{page_url}?pp_note={id}` (review mode follows via cookie; the deep-link
  param focuses the note on arrival).
- If the summary endpoint is unavailable (older HUB), the tab body shows
  "Not available yet."

### 1.5 Deep-link focus (`?pp_note=<id>`)
- On load, if `pp_note` is present and the note exists on this page: scroll its
  anchor into view (centered), open its tooltip, pulse the marker twice.
- Used by the All-pages tab and by HUB "View on page" links (Track 2).
- Unknown/foreign note id: ignore silently.

### 1.6 Touch polish
- Panel drag moves from mouse events to pointer events (touch-capable).
- Coarse-pointer media query enlarges marker and checkbox tap targets.
- Text-selection chip: settle delay on `selectionchange` so the "+ Note on
  this" chip appears reliably after iOS selection handles settle.
- Draw already uses pointer events; verify on touch and leave as is.

### 1.7 Relay additions (`class-connect-feedback.php`)
- Pass-throughs, all gated by the existing `can_review` (agency or token):
  - `PATCH /feedback/{id}` — forwards `body`, `status`, `author_key`.
  - `DELETE /feedback/{id}` — forwards `author_key`.
  - `GET /feedback/summary` — forwards site-scoped summary request.
- Relay stays a dumb proxy: authorization semantics (author_key matching) live
  in HUB.

## Track 2 — HUB (one PR)

### 2.1 Schema
- `site_feedbacks`: add `author_key` (string 64, nullable, indexed),
  `resolved_by_name` (string, nullable — resolver display name relayed from
  the WP side, since resolvers are WP users with no HUB user id), and
  `deleted_at` (soft deletes). `resolved_by` / `resolved_at` already exist.
- `sites`: add `review_token` (string 64, nullable) — synced from the plugin
  so HUB can compose View-on-page links (2.3).
- Legacy rows have `author_key = null` → never reviewer-editable. Correct.

### 2.2 Connect API
- `PATCH /api/v1/connect/feedback/{id}`: accepts `body` in addition to
  `status`. Reviewer calls (non-agency) require `author_key` matching the row;
  mismatch → **403** with a distinct error code (`author_key_mismatch`) so it
  is distinguishable from token failures in logs. Agency callers unrestricted.
- `DELETE /api/v1/connect/feedback/{id}`: same authorization; **soft delete**
  (row kept with `deleted_at`) so client-written content never silently
  disappears from the record. Soft-deleted notes are excluded from all list,
  summary, and digest output.
- List payloads add `resolved_by_name` (user display name) and `resolved_at`.
- `author_key` is **never** serialized in any response.
- New `GET /api/v1/connect/feedback/summary`:
  `[{ page_url, page_title, open_count, done_count, notes: [...] }]` for the
  requesting site, ordered by most recent activity.

### 2.3 Feedback Index page (`Pages/Feedback/Index.tsx`)
- Filter bar: site, page, status, author, date range. Default sort:
  open-first, newest-first.
- Open-note count badge per site.
- Per-note **"View on page"** button →
  `{site_url}{page_url}?pp_review={site review token}&pp_note={id}` (token
  included so it works from any browser; lands focused via 1.5).

### 2.4 Digest hook
- HUB exposes the data (internal query or small endpoint): notes created in
  the last 24 h grouped by site — count, author names, first-line excerpts,
  View-on-page URLs.
- The ecosystem digest generator (the /digest pipeline) consumes it and renders
  a "Mark It Up" section; the section renders only when there are new notes.
- Split of work: data side lands in the HUB PR; the render side is a small
  change to the digest generator alongside it.

## Degradation matrix (plugin 3.20.0 against pre-Track-2 HUB)

| Feature | Behavior on older HUB |
| --- | --- |
| Handled ✓ line | Shows without name/date |
| Edit/delete | As built: controls remain; failures surface the inline "couldn't save/delete — try again" error (accepted deviation 2026-07-02 — no data loss, no silent failure) |
| All pages tab | "Not available yet" body |
| Deep-link focus | Fully works (client-side only) |
| Walkthrough, touch | Fully work (client-side only) |

## Error handling

- Relay passes HUB status codes through unchanged.
- Widget: any non-2xx → inline "couldn't save — try again"; user-typed text
  preserved; no state mutation on failure.
- 403 `author_key_mismatch` logged distinctly server-side.

## Testing

- **Plugin (manual matrix on staging.cenhudpeakperks.com):** create/edit/delete
  each note type (highlight, point, draw); deep-link focus per type; All-pages
  navigation; first-run flag; touch pass at phone/tablet viewports; `php -l`;
  `scripts/package.sh` clean-tree build.
- **HUB (CI feature tests):** author_key enforcement incl. 403 path; agency
  bypass; soft delete + exclusion from list/summary/digest; summary shape; no
  author_key in any serialization; Index filter behavior; digest section with
  and without new notes.

## Sequencing

1. Ship peanut-connect **3.20.0** via the fleet pipeline (after 3.19.2).
2. Land the HUB PR; on deploy, fleet widgets light up fully — no second plugin
   release.
3. Validate end-to-end on Peak Perks staging (open-access snippet active).

## Success criteria

- A reviewer can fix or remove their own typo'd note.
- A reviewer can see what's been handled, by whom, when — without asking.
- The team learns of new notes in the next morning's digest.
- From HUB, any note is one click from being viewed in place.
- Nothing about the existing flow gets more complicated; `feedback.js` stays
  a single file and under roughly 2× its current size.
