# Mark It Up — Per-Site Access Modes (peanut-connect 3.21.0)

**Date:** 2026-07-04
**Status:** Approved design
**Scope:** One release (peanut-connect 3.21.0); no HUB changes.

## Purpose

Site owners don't want the Mark It Up widget appearing for every logged-in
editor on every site. Add a per-site access toggle: turn it off entirely,
limit it to the review link, limit it to specific accounts, or keep today's
behavior.

## Decisions

| Question | Decision |
| --- | --- |
| Modes | All four: `off`, `token` (review link only), `users` (specific accounts + link), `editors` (today's behavior: edit_posts + link) |
| Default after update | `editors` — identical to current behavior; fleet-safe, zero surprise |
| Picking accounts | Checklist of the site's editor+ users on the existing admin page; stored as user IDs |

## Storage

- `peanut_connect_feedback_access` (string option): `'editors'` (default) \|
  `'users'` \| `'token'` \| `'off'`. Unknown/missing value ⇒ treated as
  `'editors'`.
- `peanut_connect_feedback_allowed_users` (array option of ints): WP user IDs;
  only meaningful when mode = `users`.

## Gate semantics (all in `includes/class-connect-feedback.php`)

New private helpers:

- `access_mode(): string` — reads the option, normalizes unknown → `editors`.
- `user_grant(): bool` — the *automatic* grant for the current logged-in user:
  - `off` → false
  - `token` → false
  - `users` → `is_user_logged_in()` && user ID in allowed list
  - `editors` → `is_agency()` (current `is_user_logged_in() && current_user_can('edit_posts')`)

Existing gates change to:

- `review_active()` (controls enqueue + mount point): `off` → false. Otherwise
  `user_grant()` OR the existing URL-token/cookie match. (Token/cookie logic
  unchanged; it works in every mode except `off`.)
- `maybe_set_review_cookie()`: returns immediately when mode = `off`.
- `can_review()` (REST gate for list/create/update/delete/summary): `off` →
  false. Otherwise `user_grant()` OR the existing `X-Peanut-Review-Token`
  header match. Note: in `users` mode an allowed non-editor authenticates via
  the normal `wp_rest` nonce (cookie auth); `caller_is_agency` forwarded to
  HUB remains `is_agency()` — an allowed non-editor is a named reviewer, not
  agency, so ownership (author_key) applies to them. Correct and intended.
- `can_review_agency()` (replies): `is_agency()` AND mode ≠ `off` (unchanged
  otherwise — replies never open to non-agency).
- `enqueue()`: unchanged localize payload (`isAgency` stays `is_agency()`).
- **Widget seam (assets/js/feedback.js, one line):** `api()` currently sends
  `X-WP-Nonce` only when `cfg.isAgency` — an allowed non-editor in `users`
  mode would fail REST cookie-auth. Change to send the nonce whenever
  `cfg.nonce` is present (safe for token reviewers: a logged-out visitor's
  user-0 nonce passes `rest_cookie_check_errors`, and `can_review()` checks
  the token header independently).

Mode interactions worth stating:

- An agency user on a `token`-mode site gets review mode only by opening the
  review link — the mode governs automatic grants, not the token path.
- `off` beats everything, including an injected cookie (the staging
  open-access snippet) and agency login. The admin settings page itself stays
  reachable (it's a wp-admin page, not gated by these functions).
- Legacy/no-option sites behave exactly as today.

## Admin UI (existing `peanut-connect-feedback-review` page)

- Radio group "Who can mark up this site":
  1. **Everyone with edit access + review link** (default)
  2. **Specific users + review link**
  3. **Review link only**
  4. **Off** — Mark It Up disabled on this site
- When "Specific users" is selected: checklist of users with `edit_posts`
  (query capped at 100, ordered by display name), checkboxes persist
  `allowed_users`. Small JS toggle shows/hides the checklist with the radio
  selection (inline `<script>` on the admin page, matching the page's
  existing inline style).
- Saved by the page's existing POST handler alongside the token field, same
  nonce.

## Out of scope (YAGNI)

Role-based selection, HUB-synced central control (possible later evolution),
per-page gating, changes to reply semantics, any HUB-side change.

## Verification

- `php -l`; version bump to 3.21.0 + changelog + readme entries; package.sh.
- Staging manual matrix cycling all four modes: widget presence for (a)
  logged-in admin, (b) anonymous with snippet-injected cookie, (c) anonymous
  clean; plus REST create allow/deny per mode. `off` must kill (a) and (b).
- Fleet release via the standard pipeline after validation.
