# Microscope Remediation — PEANUT-CONNECT · Hub-as-consumer seam

> **Date:** 2026-06-12 · **Audit:** [`2026-06-11-hub-consumer-microscope.md`](./2026-06-11-hub-consumer-microscope.md) · **Release:** 3.12.0
> **Branch:** `fix/connect-hub-seam-hardening` · Status: implemented, full PHP suite + frontend build green.

This is the remediation pass over the first microscope audit. It follows MAX's Sprint A / Sprint B roadmap, with deviations recorded where the prescribed fix turned out to be unsafe to ship unilaterally or technically incompatible with the existing design.

## Principle applied

The standing constraint was **do not break live paired sites and do not require a coordinated Hub-side change to land**. Edge-contained, non-breaking hardening shipped now; anything that would deny a capability existing installs already rely on, or depends on the Hub changing first, is deferred with a written reason. Existing installs preserve their stored permission choices (new defaults apply to fresh installs only).

## Shipped

| Audit item | Fix | Where |
|---|---|---|
| A2 self-updater pre-pairing call | Instantiate only when paired (or `PEANUT_CONNECT_SELF_UPDATE` defined) | `peanut-connect.php` |
| A3 transcript stored XSS | `wp_kses_post()` before write to `post_content` | `class-connect-api.php` `augment_podcast_episode()` |
| A4 tracking opt-out ignored | Shared `tracking_precheck()` honors `is_tracking_enabled()` | `class-connect-api.php` |
| A6 security-plugin teardown | `is_protected_plugin()` exact-slug allowlist | `class-connect-updates.php` |
| A7 publish overwrites any post type | Honor `wp_post_id` only when it is a `post`; else 409 | `class-connect-api.php` `publish_podcast_episode()` |
| A-promoted: `publish_content` un-grantable | Added to canonical defaults, legacy UI, SPA `/permissions` | `class-connect-auth.php`, `peanut-connect.php`, `class-connect-api.php` |
| A8a `perform_updates` default true; 3 divergent defaults | Single `DEFAULT_PERMISSIONS`; high-impact caps default OFF | `class-connect-auth.php` (+ seed, UI, SPA) |
| B1 self-updater integrity | Require HTTP 200; pin package host (HTTPS allowlist); sanitize version | `class-connect-self-updater.php` |
| B2 restore not independently gated | New opt-in `backup_restore` permission on `/restore` (default off) | `class-connect-api.php`, `class-connect-auth.php` |
| B3 api-proxy redirect SSRF | `redirection => 0`; 2 MB response cap | `class-connect-api-proxy.php` |
| B4 `/status` hub_url leak; Settings.tsx hardcoded URL | Drop `hub_url` from `/status`; empty default + hydrate from server | `class-connect-api.php`, `frontend/src/pages/Settings.tsx` |
| A5 (honest part) README key-storage lie | Corrected to describe HMAC signing accurately | `README.md` |

**Tests added (active suite):** `Test_Self_Updater_Trust` (host pinning + version), `Test_Protected_Plugins` (allowlist exactness), `Test_Permission_Defaults` (close-default + merge semantics). Full suite: 89 tests green. Frontend: 390 tests green, `tsc --noEmit` + `vite build` clean.

## Shipped in the second pass (CI ownership + deferred items revisited)

After the first pass, ownership of the peanut-ci runner and both repos was confirmed, so the deferred set was re-examined. Several items turned out to be safe edge-only fixes and were completed:

- **A1 — forms bearer leak: DONE.** Investigation showed the Hub-served `peanut-forms.min.js` returns **404** on both Hub hosts (`hub.peanutgraphic.com`, `www.peanutgraphic.com`) — so no working client depends on the leaked key and there is *no flag-day*. Fixed edge-only: the page no longer localizes the bearer or the Hub URL; a public nonce + rate-limited `POST /forms/submit` endpoint forwards submissions to Hub server-side with the key. The `data-hub-url` attribute (B4 remainder) is also gone.
- **Backup/restore/update activity-log `TypeError`: DONE.** `log()` was called as `log(type, $resultArray)` against a `(type, status, message, meta)` signature — backups/restores/updates were never logged (500 under strict types). Corrected.
- **Signed-request nonce TOCTOU: DONE.** Verify signature first, then claim the nonce atomically via `add_option()` (INSERT-or-fail); expired claims swept on the daily cleanup cron.
- **Banner a11y: DONE.** The HTML allowlist now permits a safe set of `aria-*`/`role`, and the rendered banner is wrapped in a labelled polite live region — screen-reader reachable regardless of Hub content.
- **CI runner: DONE.** The Accessibility workflow was the only one still pointed at the self-hosted `peanut-ci` pool, which never serviced it (queued indefinitely; 3 prior runs cancelled). Moved to `ubuntu-latest` with `--legacy-peer-deps` (matching `tests.yml`), and fixed the real keyboard-inaccessible `GtmCoverage` row the run would have flagged. All five checks now green.

## Still deferred — with reasons

These two remain out, for sound reasons (verified, not punted):

### A5 — encrypt the stored Hub key at rest
The stored `peanut_connect_hub_api_key` **is** the HMAC signing secret (`hash_hmac('sha256', $canonical, $key)`), so it cannot be hashed — it must be recoverable to verify a signature. Encryption-at-rest is viable but is a **broad, high-risk change**: the key is read at **24 sites across 11 files**, every one of which would have to route through a decrypting accessor, and there is no WP-boot integration test in this repo to catch a missed site (a miss = broken auth on the live fleet). Correct path: introduce a single `get_hub_api_key()` accessor + integration tests, then encrypt. Shipped the honest half already (README correction). Tracked: `hub-auth-gate` P0.

### A8b — make `require_signed_requests` default true
De-risked but still an **operational rollout, not a code default-flip**. Verified on the Hub side: `App\Support\PeanutConnectSigner` mirrors the edge canonicalization exactly, and the `Http::peanutConnect($site)` macro (AppServiceProvider) signs **every** request via `withRequestMiddleware` while keeping the Bearer for back-compat — the established convention is that all Hub→edge calls use it. The remaining risk is purely deployment ordering: flipping the edge default rejects unsigned requests, so **every** production Hub must already be signing before any site enforces it, or fleet monitoring breaks. Safe path: confirm the signing macro is deployed fleet-wide, then enable per-site (the option already exists) — not a blind default change in this release. Tracked: `hub-auth-gate` P0, `pairing-lifecycle` P1.

### Other deferred (lower severity, see audit)
DNS-rebind window in the pairing SSRF guard; partial-restore rollback + `import_database` swallowing errors; Nginx error-log exposure (deployment-runbook item); the placeholder Hub URL strings in Settings.tsx (part of the Hub-blind build-time string-substitution track).

## Follow-up recommendation

The remaining bearer-replay exposure is closed operationally by **A8b** (enable signed-required per site once the Hub signer is confirmed fleet-wide). **A5** (encryption-at-rest) is the one true code change left and should be done as a dedicated PR with the centralized accessor + integration tests, given its 24-site blast radius.
