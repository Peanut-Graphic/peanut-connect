# Mark It Up Approvals — Round 2 Design (Notifications, Staleness, Links, Ready, Export)

**Date:** 2026-08-03
**Target version:** 3.34.0 (MINOR), stacked on 3.33.0 (PR #98)
**Base spec:** `2026-08-03-mark-it-up-approvals-design.md` — everything there stands; this round is additive.

## Summary

Five upgrades that turn the approval chips into a working sign-off system:
immediate email when a client requests changes or a page goes fully green,
amber "approved before changes" staleness when a page is edited after a vote,
per-approver share links that know who "you" are, an agency-set
"ready for review" flag that gives approvers a work queue, and a printable
sign-off record. Plus one walkthrough line teaching the approve flow.
The HUB standup feed is explicitly deferred to a later HUB round.

## Decisions (locked during brainstorming)

| Question | Decision |
|---|---|
| Scope | Six plugin-side features in one 3.34.0 round; HUB sync/standup feed deferred. |
| Notifications | **Email via `wp_mail`** to a configurable agency address (default `admin_email`): immediate on any NO, immediate on page-reaches-all-green (fresh votes only), optional daily WP-Cron digest of pages still awaiting votes (off by default). |
| Staleness | **Post modified time.** Snapshot `url_to_postid()` + post modified (GMT) at vote time; a later edit renders the vote amber. Non-post URLs never go stale (graceful). |
| Ready flag | **Set from the widget by agency users** ("Request approval" toggle); approvers see a "Needs your sign-off" section in All-pages; admin page lists/unflags. |
| Code layout | New `includes/class-connect-approvals-notify.php` for all email, driven by a `do_action` hook from the vote handler. Everything else extends `class-connect-approvals.php`. Widget stays one file unless it crosses the standing ~900-line ceiling (then split to `assets/js/approvals.js`). |
| Export | Print-friendly admin view (`pca_view=record`); browser print → PDF. No PDF library. |

## Architecture

### Data model (additive, options only)

- Each vote record gains two snapshot fields at vote time:
  `post_id` (int, `url_to_postid( home_url( $path_without_query ) )`, 0 when unresolvable)
  and `post_modified` (string, the post's `post_modified_gmt` at vote time, '' when `post_id` is 0).
- New option `peanut_connect_approvals_ready` — array of normalized paths currently flagged ready for review.
- New option `peanut_connect_approvals_notify` — `['email' => string (default '' meaning use admin_email), 'digest' => bool (default false)]`, sanitized through a pure helper.
- Existing options and shapes are unchanged; 3.33.0 data reads cleanly (missing snapshot fields = never stale).

### Pure helpers (unit-tested, standalone mocks)

- `compute_stale(array $vote, string $current_modified): bool` — true when the vote has a non-empty `post_modified` snapshot and `$current_modified` differs.
- `compute_all_green(array $approvers, array $votes): bool` — `$votes` is the public projection with `stale` already merged in; true when every configured approver id has a vote with `vote === 'yes'` and `stale` falsy. Empty approver list → false (a site with no approvers is never "fully approved").
- `sanitize_notify_settings($raw): array` — coerces to the settings shape; invalid email → ''.
- `sanitize_ready_list($raw): array` — unique normalized paths.
- `build_digest_lines(array $ready_paths, array $pages_votes, array $approvers): array` — "path — awaiting: BH, DD" lines for the digest email; pages already all-green-and-fresh are excluded.

### Staleness (read path)

- Server-side only: the public vote projection gains `stale` (bool) and, when stale, `modified_at` (the post's current modified time). Computed per page at read time by resolving the page's post once and comparing.
- Widget: a stale YES chip renders amber (`pp-chip-stale`), hover: `"<name> — Approved <vote date> · page changed <modified date>"`. A stale NO stays red (a rejection doesn't expire).
- All-green calculations (notification trigger + ready-list auto-drop) require green **and fresh**.

### Notifications (`includes/class-connect-approvals-notify.php`)

- Vote handler fires `do_action('peanut_connect_approvals_vote', string $path, array $approver, string $vote, string $reason, array $public_votes_with_stale, bool $all_green)`.
- The notify class hooks it:
  - NO → immediate mail. Subject: `[<site name>] Changes requested on <path> by <approver name>`. Body: reason, page link, All-pages hint. Plain text.
  - `$all_green === true` and it wasn't all-green before this vote → immediate mail. Subject: `[<site name>] <path> fully approved`.
- Recipient: settings email, falling back to `get_option('admin_email')`.
- Daily digest (only when enabled): WP-Cron event `peanut_connect_approvals_digest` scheduled daily; sends one mail listing `build_digest_lines()` output; sends nothing when there are no lines. Cron is scheduled on settings-save when enabled, unscheduled when disabled and on deactivation.
- `wp_mail` failures are ignored after a `error_log` line — mail must never block or fail a vote. No HTML mail, no emoji.

### Per-approver links

- URL shape: `?pp_review=<token>&pp_as=<approver_id>`.
- The feedback enqueue validates `pp_as` against the configured approver list and localizes `youApproverId` ('' when absent/invalid). Add `pp_as` to both strip lists (JS `pageKey()` and PHP `STRIP_PARAMS`) so it never leaks into page keys — the server reads it from the request once at enqueue time, before any stripping matters.
- The pp_review cookie flow is untouched; `pp_as` re-attachment across pages is not required this round — the "you" affordance simply degrades to the plain chips when absent mid-browse. (The share link lands on the page being reviewed; that's the moment that matters.)
- Widget behavior with `youApproverId`: your chip renders first-focus styling (`pp-chip-you` outline), and clicking a chip that is NOT you inserts one confirm step: "You're voting as <name> — continue?" (Continue / Cancel). With no `youApproverId`, behavior is exactly 3.33.0.
- Admin page: each approver row shows a read-only copy-click input with their personal link (token + pp_as), only when a review token is set.

### Ready for review

- New REST route `POST /approvals/ready` — body `{ path, ready: true|false }`, permission `can_review_agency`. Stores in `peanut_connect_approvals_ready`.
- `GET /approvals` (both shapes) gains `ready: [paths]` at the top level.
- Widget: agency users see a "Request approval" toggle button in the panel (near the Draw button); label flips to "Requested — undo" when set. All-pages view: a "Needs your sign-off" section on top listing ready pages where `youApproverId`'s vote is missing/NO/stale (when no `youApproverId`: ready pages not yet all-green-and-fresh), each linking to the page; then the normal listing.
- Auto-drop: when a vote makes a page all-green-and-fresh, the server removes it from the ready list (the sign-off is done).
- Admin page: "Ready for review" list with per-path unflag buttons.

### Sign-off record (export)

- Admin page link "View sign-off record" → same admin screen with `&pca_view=record` (nonce not needed — read-only, `manage_options` gate as ever).
- The record view renders alone (no admin chrome beyond WP's) with print CSS: site name, generated timestamp, then per recorded page: path, ready/approved status line, approver grid (name, initials, vote, date/time, stale annotation "page changed after approval on <date>"), and the full history table (timestamp, approver, action, reason).
- A "Print / save as PDF" button calls `window.print()`. No PDF generation server-side.

### Walkthrough

- One new `<li>` in the widget help list: "If you're an approver, click your initials at the top to approve the page — or tell us what needs to change."

## Constraints

- Additive only: 3.33.0 REST response fields keep their names/shapes; new fields only.
- No HUB changes. Options only; `update_option(..., false)`.
- Plain-text email only; no emoji anywhere; text domain 'peanut-connect'; all admin output escaped.
- Widget: dependency-free vanilla JS, no native dialogs, ~900-line ceiling with the split escape hatch.
- Never regress the green suite (3.33.0 head: 202 unit tests incl. 1 skip).
- Branch `feat/mark-it-up-approvals-round2-3.34.0`, stacked on `feat/mark-it-up-approvals-3.33.0` (PR #98). Merges after #98; expect partial CI until GitHub retargets the base (standing stacked-PR mechanics).

## Degradation

- No notify email configured → falls back to admin_email; digest off by default.
- Non-post URLs → no staleness, ever; missing snapshot fields (3.33.0 votes) → never stale.
- No `pp_as` → exact 3.33.0 chip behavior.
- No ready flags → All-pages view identical to 3.33.0.
- Mail failure → vote still records; error_log only.

## Testing

- Unit (mocks): `compute_stale` (snapshot missing/equal/different), `compute_all_green` (missing approver, NO vote, stale YES, all fresh), `sanitize_notify_settings`, `sanitize_ready_list`, `build_digest_lines` (awaiting lists, all-green exclusion), ready auto-drop logic, vote projection carries `stale`.
- Manual on staging: NO email arrives with reason; all-green email fires once; edit page → chip goes amber and all-green email does NOT fire on next YES until re-vote; pp_as link highlights "you" + confirm step for others; ready toggle + "Needs your sign-off" queue + auto-drop; sign-off record prints cleanly; digest cron (enable, force-run) sends the awaiting list.
