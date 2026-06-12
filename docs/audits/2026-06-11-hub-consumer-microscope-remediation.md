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

## Deferred — with reasons

These are real audit findings deliberately **not** in 3.12.0. Each would either break the live fleet if shipped alone or requires the Hub side to change first.

### A1 (full) — forms bearer → signed-nonce re-architecture
The form's submit JS is **loaded from the Hub** and handed the Hub bearer (`wp_localize_script` → `PeanutFormsConfig.apiKey`) so the browser posts directly to Hub. Removing the key from the page requires the Hub-hosted `peanut-forms.min.js` to submit through a local edge proxy with a short-lived nonce instead — a coordinated Hub+edge change. Shipping only the edge half (dropping `apiKey`) would break live form submission. **The bearer-in-public-HTML leak remains until the Hub-side JS is updated.** This is the single highest-value remaining item and should be the next coordinated change. Tracked: audit `outbound-sync` P0, `forms` `data-hub-url` (B4 remainder).

### A5 (full) — hash / encrypt the stored Hub key
MAX's "store `sha256(key)` and compare hashes" is **incompatible with the design**: the same `peanut_connect_hub_api_key` is the HMAC shared secret (`hash_hmac('sha256', $canonical, $key)`), so it must be stored recoverable to verify a signature. A real fix is encryption-at-rest plus a separate verification token distinct from the signing secret — a design change, not a one-liner. Shipped the honest half (README correction). Tracked: `hub-auth-gate` P0.

### A8b — make `require_signed_requests` default true
Flipping this on rejects every unsigned Bearer request. If any production Hub path still sends unsigned requests, this **locks out the live fleet**. It must follow confirmation that Hub signs 100% of requests, then ship as a default flip + migration. Tracked: `hub-auth-gate` P0, `pairing-lifecycle` P1.

### Other deferred (lower severity, see audit)
TOCTOU nonce burn; DNS-rebind window in the pairing SSRF guard; partial-restore rollback + `import_database` swallowing errors; backup activity-log `TypeError`; Nginx error-log exposure (deployment-runbook item); banner a11y (`wp_kses` strips `aria-*`); the placeholder Hub URL strings in Settings.tsx (part of the Hub-blind build-time string-substitution track).

## Follow-up recommendation

The next coordinated Hub+edge change should bundle **A1 (forms nonce)** and **A8b (signed-required default)** together, since both are Hub-side-first and both close the remaining bearer-replay / bearer-leak exposure that A5's deferral leaves open.
