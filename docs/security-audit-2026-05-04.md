# Security audit — `class-connect-api.php`

**Date:** 2026-05-04
**Reviewer:** feature-dev:code-reviewer (dispatched by CAT)
**Scope:** `includes/class-connect-api.php` (2,819 lines), plus supporting classes pulled in via the review (`class-connect-auth.php`, `class-connect-api-proxy.php`, `class-connect-rate-limiter.php`, `class-connect-backup.php`).
**Posture:** Read-only — no code changes, no refactor proposals.

## Summary

| Severity | Count |
|---|---|
| Critical | 4 |
| High | 4 |
| Medium | 4 |
| **Total** | **12** |

**Overall confidence in current posture: Low–Medium.** The auth plumbing (`hash_equals`, rate-limiter scaffolding, permission flags) is structurally sound. But three Critical issues are concrete and exploitable, and one is **already breaking production functionality today** (C-1).

### Top 3 — fix these first

1. **C-1 — `/hub/disconnect` callback is a non-existent method.** Outright PHP fatal in production every time Hub tries to disconnect a site. Not theoretical; currently broken.
2. **C-4 — Stored XSS via `/banner/show`.** `html` and `css` fields stored unsanitized. One compromised Hub key → persistent JavaScript execution across every frontend page of every connected site. Massive blast radius.
3. **C-2 — Hub API key stored plaintext in `wp_options`.** Same key serves as both stored secret and transmitted bearer token. Any `wp_options` exposure (SQL injection elsewhere, leaked DB backup, misconfigured export) hands an attacker the live Hub key with no second factor.

---

## Critical

### C-1 · Fatal — `/hub/disconnect` callback method does not exist
**Confidence:** 100
**File:** `class-connect-api.php:669`

The route registered at `/hub/disconnect` specifies `'callback' => [$this, 'disconnect_from_hub']`. No method named `disconnect_from_hub` exists. The actual disconnect method is `disconnect_hub` (line 1149), wired to a *different* route (`/settings/hub/disconnect`, line 70).

Calling `POST /peanut-connect/v1/hub/disconnect` — the route Hub uses on site removal — produces `Call to undefined method` and returns a 500. The site is never actually disconnected on the WordPress side.

**Fix:** Rename the callback reference on line 669 to `disconnect_hub`, or add `disconnect_from_hub` as a wrapper.

### C-2 · Stored plaintext Hub API key leaked in disconnect payload
**Confidence:** 95
**File:** `class-connect-api.php:1162–1164`; cross-reference `class-connect-auth.php:266`

The Hub API key is stored plaintext in `peanut_connect_hub_api_key`. The same option value is:
- placed in the JSON body of the non-blocking outbound `wp_remote_post` to Hub's `/api/v1/sites/disconnect` (line 1162)
- compared directly via `hash_equals` against the incoming `Authorization: Bearer` token in `verify_hub_request` (auth.php:266)

So the same string is both the stored secret AND the transmitted bearer token. Any `wp_options` exposure (SQLi elsewhere, DB backup leak, server directory listing of a `.sql` file) yields a live Hub API key with no additional barrier.

**Attack scenario:** Read `wp_options` → recover `peanut_connect_hub_api_key` → use it as a bearer token to call any Hub-authenticated endpoint, including `/hub/plugin/update`, `/backup`, `/restore`.

**Fix:** Store only `hash('sha256', $api_key)`. Compare incoming tokens via `hash_equals(hash('sha256', $provided), $stored_hash)`. Plaintext exists only at key-generation time, never persisted.

### C-3 · Unvalidated `backup_url` → partial SSRF in `/restore`
**Confidence:** 90
**File:** `class-connect-api.php:795–804` (route), `2764–2780` (callback); `class-connect-backup.php`

`/restore` accepts `backup_url` sanitized with `esc_url_raw`. That normalizes encoding but does **not** enforce scheme or host. The URL passes verbatim to `Peanut_Connect_Backup::restore_backup()`, which presumably fetches the archive — meaning a Hub-authenticated attacker can supply any URL, including internal addresses (`http://169.254.169.254/`, `http://localhost/wp-admin/`, `http://10.0.0.1/`).

The proxy class (`class-connect-api-proxy.php`) has a domain whitelist; the restore endpoint does not.

**Fix:** Validate `backup_url`'s host matches a site-configured allowlist or the stored `peanut_connect_hub_url` host before handing to the backup class.

### C-4 · Stored XSS via `/banner/show` — `html` and `css` unsanitized
**Confidence:** 88
**File:** `class-connect-api.php:719–724` (route args), `2621–2632` (callback)

`html` and `css` parameters have type `string` but no `sanitize_callback`. Both flow into `Peanut_Connect_Event_Banner::set_banner()` without sanitization or escaping, presumably for storage in `wp_options` and rendering on every frontend page.

Endpoint requires Hub auth, so direct exploitation needs a key. But — combined with C-2's plaintext-key exposure — a single compromised key allows an attacker to inject arbitrary JavaScript into every page of every site in the fleet via one API call. Mass stored XSS, fleet-wide.

**Fix:** Apply `wp_kses_post` to `html`, a CSS-specific sanitizer (or `sanitize_textarea_field`) to `css`, ideally as `sanitize_callback` in the route args so the REST framework enforces it before the callback runs.

---

## High

### H-1 · Unparameterized SQL in `get_forms_diagnostics`
**Confidence:** 92
**File:** `class-connect-api.php:2389–2430` (and `2448–2460` for FormFlow tables)

After confirming table existence with a `prepare()`'d `SHOW TABLES LIKE`, data queries use raw string interpolation:

```php
"SELECT COUNT(*) FROM $hub_forms_table"
"SELECT hub_form_id, slug, name, ... FROM $hub_forms_table ..."
"SELECT COUNT(*) FROM $submissions_table WHERE synced = 1"
"SELECT source, COUNT(*) as count FROM $submissions_table GROUP BY source"
```

Table names derive from `$wpdb->prefix . '...'`. Prefix isn't user-controlled in standard installs, so direct injection is unlikely today. But this violates the project's stated `$wpdb->prepare()` standard, and any future refactor that makes the table name dynamic silently introduces SQL injection.

**Fix:** Use the `%i` identifier placeholder (`$wpdb->prepare('SELECT COUNT(*) FROM %i', $table)`, WP 6.2+), or `esc_sql($table)` as an interim.

### H-2 · No `'hub'` rate-limit bucket → falls back to permissive default
**Confidence:** 95
**File:** `class-connect-auth.php:229`; `class-connect-rate-limiter.php:199–260`

`verify_hub_request` always passes `'hub'` as the endpoint identifier to the rate limiter. `get_endpoint_config()` has **no `'hub'` key** — falls through to `default` (60 req/min per client IP).

Hub-authenticated endpoints (the most privileged surface, including plugin updates and restore) get the most permissive limit. An attacker behind the same IP/proxy as the legitimate Hub server gets 60 req/min on the entire Hub-authed surface.

**Fix:** Add a `'hub'` entry with a stricter limit (e.g. 20 req/min). Tighten further on the failed-auth path.

### H-3 · `error_log()` leaks Hub URL and connection diagnostics
**Confidence:** 85
**File:** `class-connect-api.php:983–993`

Six consecutive `error_log()` calls in `auto_connect_to_hub` emit Hub URL, API key length, and option-save status. Looks like leftover WAF-troubleshooting instrumentation. The key isn't logged, but the URL and connection state are exposed to anyone with PHP-error-log access (server admins, hosting support, log aggregators, or any shared-hosting attacker who can read the world-readable error log).

**Fix:** Remove or gate behind `WP_DEBUG` / a custom `PEANUT_CONNECT_DEBUG` constant.

### H-4 · `auto_connect_to_hub` / `manual_connect_to_hub` → SSRF via admin UI
**Confidence:** 82
**File:** `class-connect-api.php:919–946` (auto), `1049` (manual)

`hub_url` validated only with `FILTER_VALIDATE_URL` + `esc_url_raw`. No host allowlist, no scheme restriction beyond what the filter accepts. An admin (or CSRF-driven request against an admin session) submits any URL → WordPress server makes outbound POST with `site_url`, plugin/PHP/WP versions in body. `manual_connect_to_hub` additionally sends the full API key in the `Authorization: Bearer` header.

SSRF reachable by anyone with `manage_options`. Information disclosure to attacker-controlled server, internal-network mapping if internal URLs supplied.

**Fix:** Validate `hub_url` host against a site-level allowlist; at minimum reject `127.0.0.1`, `localhost`, RFC1918 ranges (`10.x`, `172.16–31.x`, `192.168.x`).

---

## Medium

### M-1 · `/backup` and `/restore` lack per-operation rate limiting
**Confidence:** 85
**File:** `class-connect-api.php:777–805`

Hub-authenticated, but routed through the `'hub'` bucket (see H-2) which is effectively the 60/min default. Backup writes a full DB dump + zip of `wp-content`. Restore downloads + unpacks an arbitrary URL. Neither has a per-operation lock or minimum interval. A valid Hub key can fill disk with backup archives or exhaust I/O via repeated restores.

**Fix:** Transient-based lock (`peanut_connect_backup_in_progress`) blocking concurrency + minimum inter-call interval (e.g. 5 min).

### M-2 · `/backup` route — no `enum` constraint on `type` / `storage_driver`
**Confidence:** 83
**File:** `class-connect-api.php:780–791`

Both fields default-stringed with `sanitize_text_field`. Whatever values arrive flow into `create_backup()`. Whether arbitrary values cause issues depends on that class's implementation; defensively, the route should enumerate at registration.

**Fix:** Add `'enum' => ['full', 'db', 'files']` to `type` and `'enum' => ['local', 's3', ...]` (or actual driver list) to `storage_driver`.

### M-3 · `update_hub_settings` overwrites Hub API key without verification
**Confidence:** 80
**File:** `class-connect-api.php:1816–1831`

Admin-only. Silently overwrites the stored Hub API key with any non-empty string. No Hub-side verification — admin can break Hub connectivity by mistake or intent, no activity-log entry, no confirmation.

Stability/correctness flavor more than security, but worth noting given the WAF-fight history this session.

**Fix:** Require new API keys to verify against Hub before persisting. Delegate to the existing `Peanut_Connect_Hub_Sync::verify_hub_connection()` logic used in `test_hub_connection`.

### M-4 · Rate limiter uses unsalted truncated MD5
**Confidence:** 80
**File:** `class-connect-rate-limiter.php:147–149, 272`

Client identifier: `substr(md5($matches[1]), 0, 8)` (8 hex chars, no salt). Cache key: `substr(md5($identifier . $endpoint), 0, 12)`. MD5 collision resistance is broken; truncated MD5 raises collision probability further. Two different API keys sharing an 8-char prefix share a rate-limit bucket — meaning an offline-generated colliding key inherits the legitimate key's "remaining" allowance.

**Fix:** `hash('sha256', $identifier . $endpoint . wp_salt('auth'))`, keep ≥16 hex chars.

---

## What this audit deliberately did NOT cover

- The 2,819-line file's structure / refactor opportunities (deferred per "stability first" decision)
- Hub-side (Laravel) code — only the WP plugin surface
- Frontend (SPA) — no XSS or CSRF review on the React layer beyond what flows through this API
- Build/dependency audit (npm / composer)
- Pen-test-style validation of any of the above (read-only review)

## Recommended next steps

In severity order:

1. **C-1** — fix today. It's already broken in production every time Hub disconnects a site.
2. **C-4** — fix this week. Sanitize `/banner/show` inputs at the route layer.
3. **C-2** — plan for next release. Hashing the stored key requires a key-rotation flow for existing installs.
4. **C-3** — fix in next release. Add a host allowlist on `backup_url`.
5. **H-2** — add the `'hub'` rate-limit bucket. Trivial.
6. **H-3** — remove/gate the debug `error_log()` calls. Trivial.
7. **H-1, H-4** — schedule.
8. **M-series** — schedule, lower urgency.
