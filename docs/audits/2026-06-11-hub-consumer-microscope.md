# Microscope Audit — PEANUT-CONNECT · Persona: **The Hub (machine consumer)**

> **Date:** 2026-06-11 · **Method:** `/microscope` (persona → bite → 8-lens, adversarially verified, CAT + MAX synthesis)
> **Scope:** `origin/main` @ `1a204ab` · **Type:** AUDIT ONLY — no code changed. This is the first microscope run on this repo.
> **Coverage:** 11 bites · 22 agents (audit + adversarial verify) · **97 verified findings** (1 rejected on verify).

---

## Why this persona

PEANUT-CONNECT is a WordPress plugin that acts as the **on-site edge agent** for a central Laravel **Hub**. Per `README.md` and `docs/HUB-EDGE-CONTRACT.md`, the plugin is "the on-site executor"; Hub does authoring + aggregation and **calls into the plugin's REST API** (namespace `peanut-connect/v1`, Bearer site-key auth, optional HMAC) to monitor, update, back up, publish content to, and remotely control the customer's production WordPress site.

That makes **the Hub as a machine consumer** the primary persona, and the **Hub↔edge auth seam the primary attack surface** — which is exactly where the recent git history shows security fixes being *live-merged*: restore-RCE (`14742ef`), banner site-wide XSS (`6b4b195`), HMAC request signing (`9f499f1`), SSRF/hardening batch (`7f1c66e`), and the tracker-key fix (`8026c34`). The bite list and the heaviest lens weighting follow that seam.

> **A note on the live-merged-fixes pattern.** That seam has now absorbed *five* emergency security fixes in short order. The correct inference is **not** "the seam is now hardened" — it's "a new attacker keeps finding a new way in." This audit confirms it: the trust model was built feature-by-feature with patches bolted on, never threat-modeled as a whole. The 97 findings below are downstream of that.

---

## Persona health — the headline

**Severity rollup (verified):**

| Severity | Count |
|---|---|
| **P0** | **11** |
| P1 | 43 |
| P2 | 41 |
| P3 | 2 |
| **Total** | **97** |

**By lens:** security **56** · correctness 15 · states 10 · copy 5 · ux 5 · performance 4 · accessibility 2.

**Bottom line (MAX):** **Not shippable to a security-sensitive client (Itron) today.** Three issues each independently block:

1. **The live Hub bearer token is printed into every public page** that renders a `[peanut_form]` shortcode (`class-connect-forms.php:343`) — any visitor, crawler, or client employee lifts the long-lived RCE-equivalent credential straight from the DOM.
2. **The self-updater phones `peanutgraphic.com` on every admin page load before pairing is even checked** (`class-connect-self-updater.php:18` + `peanut-connect.php:128`) — a direct, detectable violation of the Hub-blind contract that is a *named Itron requirement*.
3. **Stored XSS via `transcript_html`** written to `post_content` unsanitized (`class-connect-api.php:2304`) — any compromised/leaked Hub key injects arbitrary script into the site's posts.

The code underneath is generally sound — the auth scaffolding, backup-integrity model, and permission system are well-designed. These are overwhelmingly **incomplete-enforcement** issues (a control that exists but defaults off, or guards one case and misses the sibling), not architectural rot. MAX scopes the fix at **~2 days to defensible (Sprint A), ~1.5 more to close the P1 cluster (Sprint B).**

---

## CAT's read — cross-cutting roots & what's missing

CAT's reframing: the per-bite agents found 97 issues by looking at each class in isolation; the deeper question is *what kind of system is this, and does its design permit the security properties it claims?* The answer is uncomfortable.

### The four root causes (fix one, kill five)

- **Root A — A single long-lived plaintext bearer with flat authorization.** `peanut_connect_hub_api_key` is simultaneously the stored secret (plaintext in `wp_options`, despite the README claiming SHA-256), the wire credential (sent on every request), and an audit artifact (logged, sent in the disconnect body, leaked to the page). There is no layering: **have the key, have everything** — plugin installs, DB restore, code execution, HTML injection into every page. The permission flags live in the same `wp_options` Hub can write, so *Hub can grant itself any permission it lacks.* That is not a permission system. The HMAC layer that would fix this is **opt-in and defaults off**.
- **Root B — No allowlist on destructive operations.** `/restore` fetches any URL Hub supplies; `/hub/plugin/update` + `apply_update` accept any slug; security plugins can be deactivated/deleted. The trust model is "Hub = God," which holds right up until the key leaks (Root A) or Hub is compromised.
- **Root C — Permission defaults are open, not closed.** `perform_updates` and `publish_content` default **true**. A freshly paired "monitoring only" site is immediately exploitable for remote update + content injection. The Hub-blind rule (no Hub options until pairing) paradoxically *worsens* this: the option may not exist, and the absent-default is "yes."
- **Root D — `200-on-failure` as a systemic pattern.** api-proxy returns 200 on upstream failure; `marketing forward()` promotes a 4xx/5xx to 200 when the body says `success:true`; `bulk_update` returns 200 on total failure; `import_database` returns `true` on a fully failed restore. Hub's retry/audit/alerting are blind to a whole category of real errors.

### What nobody audited but should worry us

- **`/gtm-beacon` is an unvalidated anonymous→Hub amplifier.** Any anonymous visitor POSTs an arbitrary body that Connect forwards verbatim to Hub under the site's trust context. If Hub's high-volume beacon ingest is less hardened than its main API, this is an auth bypass *into Hub*. (Hub side is out of scope here — but the channel exists and is unexamined.)
- **No key rotation exists.** No rotate endpoint, no expiry, no incident-response path for a credential that grants RCE-equivalent control. If the key leaks (error log, DB backup, the disconnect body, the public page), the exposure window is **unbounded**.
- **`X-Peanut-Manager` is persisted without verification** — any authenticated caller (or an HTTP MITM) can overwrite `peanut_connect_manager_url` on every request, persistently redirecting outbound sync.
- **`uninstall.php` does not notify Hub.** Hub keeps a "connected" record with a now-dead key → domain-reuse / orphaned-record reconnect risk after a site move or deletion.

### The surprising connection: Hub-blind ⇄ security are the *same* fix

The Hub-blind build strips UI labels and option *display* — but the bearer is still stored as `wp_options['peanut_connect_hub_api_key']`, a plaintext value whose **key name is itself the most direct evidence of the Hub relationship**. Hub-blind and plaintext-key-in-options cannot coexist. **Hashing the stored key (Root A) is the Itron prerequisite, not merely security hardening.**

### Is the design sound, or does the second floor come down?

CAT's call: the trust *direction* is fine, but **the legacy bare-Bearer path should be condemned, not refactored.** The HMAC infrastructure is already built and architecturally correct; it's simply not the default and the plaintext fallback is still live on every install. Mature remote-execution models (Stripe webhooks, GitHub Apps, AWS SigV4) share two properties this lacks: **(1) signed-request-only, key never transmitted; (2) least-authority scoped credentials, not a master key.** The migration path is concrete: enforce signatures for a version cycle, then drop the fallback.

---

## MAX's call — the prioritized "do these first"

> AUDIT-ONLY: this is the recommended roadmap, not work performed. Effort: S/M/L. Findings sharing a fix are collapsed.

### Sprint A — pre-ship blockers (~2 days)

| # | Fix | Where | Why | Effort |
|---|---|---|---|---|
| **A1** | Stop emitting the Hub bearer into page HTML; move form auth to a signed short-lived nonce endpoint | `class-connect-forms.php:343` | Highest-impact line in the repo — live RCE credential readable from any public page DOM | **M** |
| **A2** | Gate self-updater instantiation on being paired (or a disable constant) | `class-connect-self-updater.php:18`, `peanut-connect.php:128` | Hub-blind violation detectable in network logs — named Itron blocker | **S** |
| **A3** | `wp_kses_post()` the transcript before writing `post_content` | `class-connect-api.php:2304` | Stored XSS through the Hub API | **S** |
| **A4** | Guard the four public tracker routes on `peanut_connect_tracking_enabled` | `class-connect-api.php:609,646,665,684` | Admin GDPR opt-out is currently ignored — legal/contractual risk | **S** |
| **A5** | Hash the stored Hub key (`sha256` at pair time; `hash_equals` on verify) | `class-connect-api.php:1060` / `class-connect-auth.php` | Plaintext key + README lies about it; also the Itron prerequisite | **M** |
| **A6** | Add a `PROTECTED_PLUGINS` allowlist (Wordfence/Sucuri/iThemes…) before deactivate/delete | `class-connect-updates.php:472,503` | Hub can currently collapse the site's security perimeter in one call | **S** |
| **A7** | Reject `wp_post_id` whose `post_type !== 'post'` before overwrite | `class-connect-api.php:2146,2213` | **MAX confirmed by reading code:** publish forces `post_type=>'post'`, silently converting a page / WooCommerce product / checkout page | **S** |
| **A8** | Flip `peanut_connect_require_signed_requests` to default **true** + migration; default `perform_updates` to **false** | `class-connect-auth.php:263,137` | Kills the replayable-bearer + open-update-surface class (Roots A & C). *Sequencing: Hub must send HMAC before the edge enforces it* | **M** |

### Sprint B — high-value P1 cluster (~1.5 days)

| # | Fix | Where | Why | Effort |
|---|---|---|---|---|
| **B1** | Check HTTP 200 before trusting update JSON; verify a `sha256` on the downloaded zip before install | `class-connect-self-updater.php:152,74` | Update-server compromise = RCE on every paired site | **M** |
| **B2** | Add `backup_restore` permission, default OFF, gate `/restore` | `class-connect-api.php:841,858` | Restore = DB overwrite + file replace (RCE-equivalent) with no independent gate | **S** |
| **B3** | Set `redirection => 0` on the proxy request | `class-connect-api-proxy.php:93` | Redirect-following SSRF to `169.254.169.254` / RFC1918 via an allowlisted domain | **S** |
| **B4** | Strip `hub_url` from `/status`; Settings.tsx reads hub_url from the API not a hardcoded constant; drop `data-hub-url` from public HTML | `class-connect-api.php:2793`, `frontend/src/pages/Settings.tsx:46`, `class-connect-forms.php:308` | Three Hub-blind leaks | **S–M** |
| **B5** | Cap `visitor_id` length; allowlist `event_type`; cap `metadata` size | `class-connect-api.php:614,619,1815` | Unauthenticated multi-MB DB writes + Hub-sync amplification + analytic poisoning | **S** |

### Severity sanity-check (MAX)

- **Demote:** `render_banner` hooks `wp_head` → **P1** (real, but inert until Hub sets a banner). `maybe_register_existing_backups` seeding bypass → effectively **P2** (requires pre-existing write access to `wp-content/peanut-backups/` = already-RCE).
- **Promote:** **`publish_content` permission can never be granted → P0 functional** — the entire podcast surface is dead-on-arrival (one-line fix: add it to the activation list). **`perform_updates` defaults true → treat as Sprint-A**, explicit at `class-connect-auth.php:137`.

### Defer (post-ship) / won't-fix

- **Defer (Sprint C):** TOCTOU nonce burn; DNS-rebind window in the SSRF guard; partial-restore rollback; `import_database` swallows query errors; backup activity-log `TypeError`; Nginx error-log exposure (deployment-runbook note, not code); banner a11y (wp_kses strips `aria-*`/`role`).
- **Noise / won't-fix now:** MD5-truncated rate-limit key collision; `0.0.0` version fallback always showing an update; `set_banner` 500-on-idempotent-retry; `bulk_update` 200-on-total-failure; `post_author=0` on publish.

---

## Per-bite findings

Each card lists the bite's flow, verified-finding count, and every confirmed finding (severity · lens · location · impact · fix · verifier note). Findings are ordered by severity.

### `hub-auth-gate` — Hub auth gate: bearer site-key validation, HMAC request signing/anti-replay, rate limiting, permission flags

**Flow.** Hub authenticates to the edge via two parallel paths in Peanut_Connect_Auth. The Hub path (verify_hub_request) prefers HMAC-signed requests (X-Peanut-Signature + X-Peanut-Timestamp + X-Peanut-Nonce; verify_signed_hub_request burns the nonce via transient) and falls back to plain Bearer when the option peanut_connect_require_signed_requests is false (the default). Rate limiting via Peanut_Connect_Rate_Limiter runs before auth. Hub-facing REST routes (namespace peanut-connect/v1, prefix /hub/*) are registered in Peanut_Connect_API::register_routes() and use hub_permission_callback or hub_permission_callback_for($permission), which also calls has_permission() against peanut_connect_permissions in wp_options. A legacy site-key path (verify_request, peanut_connect_site_key) exists alongside but is dead — no route currently passes through it. Key files: includes/class-connect-auth.php, includes/class-connect-rate-limiter.php, includes/class-connect-api.php, includes/class-connect-api-proxy.php, peanut-connect.php (activation hook + generate_site_key).

**Verified findings:** 9 (P0×2 · P1×3 · P2×4)

- **P0 · security** — Hub API key stored plain-text in wp_options despite README claiming SHA-256 hashing
  - `includes/class-connect-api.php:1060`
  - **Impact:** Any WordPress user with DB access (direct SQL, wp-cli, a compromised plugin, cPanel phpMyAdmin) obtains the live long-lived Hub Bearer token. Combined with the unsigned-legacy-Bearer default (finding 2), a leaked key allows unauthenticated Hub-level control of the site indefinitely.
  - **Fix:** Either (a) store the key hashed and compare hashes — but this breaks the HMAC path since HMAC needs the raw key, so raw key must be stored separately with autoload=false; or (b) correct the README to accurately state the key is stored plain, add autoload=false flag, and lean on the HMAC-enforce flag as the mitigation. At minimum correct the README which actively misleads on security posture.
  - *Verifier:* Independently confirmed. class-connect-api.php:1060 calls update_option('peanut_connect_hub_api_key', $api_key) with the raw key. class-connect-auth.php:241 retrieves it raw with get_option. README.md line 31 states 'Site key auth (SHA-256 hashed, never plain-text)' and line 70 states 'Keys are stored as SHA-256 hashes, never in plain text'. Neither claim is true — no hashing occurs anywhere in the storage or retrieval path. The peanut_connect_site_key (manager-facing) is also stored raw (peanut-connect.php:709). Both keys are plain-text in wp_options.
- **P0 · security** — HMAC signing is opt-in only and defaults off — all Hub requests fall back to replayable Bearer token on every fresh install
  - `includes/class-connect-auth.php:263`
  - **Impact:** The HMAC anti-replay infrastructure is effectively bypassed by default. A network attacker or anyone who reads the DB key can replay Hub commands (plugin updates, content publishing, backup triggers) without a valid timestamp or nonce.
  - **Fix:** Set peanut_connect_require_signed_requests = true in the activation hook for all new installs. Provide a one-time migration notice for existing sites. The CHANGELOG already describes the flag — it just needs to be the default.
  - *Verifier:* Independently confirmed. class-connect-auth.php:263: get_option('peanut_connect_require_signed_requests', false) has hard-coded false default. The activation hook at peanut-connect.php:705-720 never sets this option. The signed path at line 254-257 is only taken when X-Peanut-Signature header is present, which an attacker simply omits. The unsigned Bearer path at lines 271-294 is always reachable on every fresh install.
- **P1 · correctness** — Three divergent permission defaults: activation hook, has_permission(), and get_permissions() disagree — publish_content missing from activation, api_proxy missing from auth class defaults
  - `includes/class-connect-auth.php:120`
  - **Impact:** Fresh-install sites silently reject Hub publish and api-proxy commands with a 403. The SPA may display permissions as enabled via get_permissions() while has_permission() rejects them because the stored array lacks publish_content.
  - **Fix:** Define a single DEFAULTS constant in the Auth class, use it in has_permission(), get_permissions(), and the activation hook. Add 'publish_content' and 'api_proxy' to the defaults. Add a DB migration that merges missing keys into existing installs.
  - *Verifier:* Independently confirmed. Activation hook at peanut-connect.php:714-719 seeds ['health_check','list_updates','perform_updates','access_analytics'] — no 'publish_content'. Auth class get_permissions() at line 134 defaults to include 'publish_content'. has_permission() at line 120 uses get_option('peanut_connect_permissions', []) with empty-array default. Routes at class-connect-api.php:120,132,141 require hub_permission_callback_for('publish_content'); on fresh install stored array lacks 'publish_content' so has_permission returns false. api_proxy is absent from auth's get_permissions() defaults but handled in class-connect-api.php:2668-2679 with false default.
- **P1 · security** — TOCTOU race in nonce burn: concurrent requests with the same nonce can both pass the anti-replay check
  - `includes/class-connect-auth.php:321`
  - **Impact:** An attacker who captures a valid signed Hub request within the 300s window can replay it once. On PHP-FPM or Apache prefork with two simultaneous requests, both reach the get_transient check before either executes set_transient.
  - **Fix:** Replace get_transient/set_transient with an atomic operation: wp_cache_add() returns false if the key already existed (add is atomic in Memcached/Redis). Alternatively, set_transient before the signature check and reject if set_transient returns false (key already existed). For DB-only transients, use $wpdb->query with INSERT IGNORE.
  - *Verifier:* Independently confirmed. Lines 321-339: get_transient($nonce_key) !== false check at line 321 is followed by verify_signature() at lines 325-333, then set_transient at line 339. No atomicity between the check and the burn. Two concurrent requests with the same nonce both see 'false' (unused) at line 321, both pass verify_signature, both return true before either burns the nonce.
- **P1 · security** — Hub auth endpoint uses default rate limit (60/min) instead of a stricter auth limit
  - `includes/class-connect-rate-limiter.php:249`
  - **Impact:** An attacker can make 60 auth-or-failed requests per minute against the Hub auth gate before being throttled. Hub auth joins the cheap-to-spam default bucket alongside unrelated endpoints.
  - **Fix:** Add a 'hub' entry to get_endpoint_config() with limit => 10, window => 60 (matching AUTH_LIMIT/AUTH_WINDOW) to align with 'verify' and 'disconnect' endpoints.
  - *Verifier:* Independently confirmed. get_endpoint_config() at line 249 has entries for 'verify' (10/min), 'disconnect' (10/min), and various others — but no 'hub' key. The $configs[$endpoint] ?? $configs['default'] fallback at line 310 returns 60/min for the 'hub' endpoint used in verify_hub_request() at class-connect-auth.php:235.
- **P2 · copy** — Error messages in Hub auth path expose 'Hub' terminology, violating the Hub-blind contract for Itron deployments
  - `includes/class-connect-auth.php:243`
  - **Impact:** On Hub-blind deployments (Itron), an unauthenticated probe of any /hub/* endpoint that is misconfigured returns 403 with 'Hub API key' in the body, defeating the blindness constraint documented in HUB-EDGE-CONTRACT.md.
  - **Fix:** For Hub-blind builds, use a generic message ('Not authorized.' or 'Endpoint not available.') gated on a PEANUT_HUB_BLIND constant. For standard builds, the messages are fine as machine-readable API responses.
  - *Verifier:* Independently confirmed. class-connect-auth.php:243-246 returns 'Hub API key not configured. Please connect to Hub first.' HUB-EDGE-CONTRACT.md Rule 3 (line 40-48) states 'No Hub terminology in default user-facing strings (settings labels, error messages, README)' and the Hub-blind section (line 65-77) explicitly names Itron and states 'No Hub string in any rendered admin UI, settings label, error message'. The error at line 243 is machine-consumed but traverses a public REST endpoint. The auditor cited 'Rule 3' correctly. The severity P2 is appropriate — this requires a Hub-blind build path to exploit and no such build currently exists.
- **P2 · correctness** — verify_request / permission_callback / permission_callback_for (site-key path) are dead code — no route calls them
  - `includes/class-connect-auth.php:33`
  - **Impact:** Dead code maintenance hazard. The side-effect (X-Peanut-Manager URL overwrite on line 84) would be a stored-redirect risk if a route were ever added. Two parallel auth stacks create confusion about which is canonical.
  - **Fix:** Remove verify_request(), permission_callback(), permission_callback_for(), and the peanut_connect_site_key generation path if they serve no active route. If intentionally reserved for a future manager flow, add a TODO comment linking to the open spec.
  - *Verifier:* Independently confirmed. Searched all PHP files for Peanut_Connect_Auth::permission_callback, Peanut_Connect_Auth::verify_request, and permission_callback_for (excluding hub variants and the auth class itself). Only callers are tests/phpunit/unit/AuthTest.php — no register_rest_route() in class-connect-api.php or any other file references these methods. The site-key path is unreachable at runtime via REST.
- **P2 · security** — Rate limiter cache key uses 12-char truncated MD5 — birthday collision risk can conflate two callers' rate-limit buckets
  - `includes/class-connect-rate-limiter.php:322`
  - **Impact:** An adversary can craft an IP+key combination whose MD5 prefix collides with a known target's bucket, causing incorrect rate limiting (DoS) or shared quota. Not trivially exploitable in practice.
  - **Fix:** Use the full MD5 (32 chars) or hash('sha256', $identifier . $endpoint) truncated to 16+ chars. 'peanut_rl_' + 32 chars = 42 chars, well within WordPress transient key limits.
  - *Verifier:* Independently confirmed. class-connect-rate-limiter.php:322: substr(md5($identifier . $endpoint), 0, 12) — 12 hex chars = 48 bits. The birthday attack math is correct: ~2^24 expected collisions. Severity P2 is appropriate given that exploitation requires crafting a colliding identifier, which is non-trivial but not impossible.
- **P2 · states** — No rate limit config for 'hub' endpoint — hub auth shares the 'default' bucket, making per-endpoint headers misleading and Hub unable to distinguish its own quota headroom
  - `includes/class-connect-rate-limiter.php:310`
  - **Impact:** Hub cannot distinguish between its own rate limit headroom and unrelated traffic consuming the same bucket. A spike in default-bucket traffic can exhaust Hub's apparent quota. Rate limit response headers are semantically incorrect for the hub endpoint.
  - **Fix:** Add 'hub' to get_endpoint_config() with a distinct limit (10 or 30/min), and add it to the 'all' list in clear().
  - *Verifier:* Independently confirmed. This is the states/observability framing of the same gap confirmed in finding 4. The 'hub' key is absent from get_endpoint_config(), the fallback to 'default' (60/min) applies, and the clear() method at line 334 also omits 'hub' from its 'all' endpoint list, so clearing all rate limits for a client would not clear the hub bucket. Both findings 4 and 8 are legitimate separate concerns (security severity vs. state/observability correctness).

> **Coverage:** Fully traced: verify_hub_request → verify_signed_hub_request → nonce transient check → verify_signature (HMAC); legacy verify_request; rate limiter check + client identifier + IP resolution; all /hub/* route registrations and permission callbacks; api-proxy SSRF guard; has_permission + get_permissions defaults; activation hook permission seeding; generate_site_key storage. Not traced: Hub-side HMAC signing (Laravel, out of scope); class-connect-hub-sync.php heartbeat path; backup/restore RCE (separate bite); error-log/activity-log handler bodies.

### `pairing-lifecycle` — Pairing lifecycle: connect, manual-connect, test, disconnect, sync, hub-mode (admin-initiated establishment of Hub trust)

**Flow.** The pairing lifecycle runs across three layers. (1) Admin SPA (Settings.tsx) initiates auto-connect or manual-connect via wp-json REST calls that are guarded by `admin_permission_check` (WordPress `manage_options`). (2) `class-connect-api.php::auto_connect_to_hub` generates a 64-char key, POSTs it to Hub's `/api/v1/sites/connect`, and on success writes `peanut_connect_hub_url` + `peanut_connect_hub_api_key` to wp_options. `manual_connect_to_hub` verifies a caller-supplied key against Hub's `/api/v1/sites/verify` and stores on success. (3) `disconnect_hub` fires a best-effort non-blocking POST to Hub's `/api/v1/sites/disconnect` then deletes the local options. (4) Inbound Hub requests are authenticated in `class-connect-auth.php::verify_hub_request`: HMAC-signed path preferred, with a legacy Bearer-only fallback that is always active (the opt-in flag to disable it is never set). The 64-char site key (used by Hub to call the edge) is stored plaintext in wp_options and compared timing-safely; the hub_api_key (used by the plugin to call Hub) is also stored plaintext. An SSRF guard (`is_safe_hub_host`) runs a DNS resolution check on the supplied Hub URL at connect time. Sync is handled by `class-connect-hub-sync.php` over cron; trigger_hub_sync drives it on-demand.

**Verified findings:** 9 (P1×4 · P2×5)

- **P1 · security** — HMAC signing enforcement is always opt-in, never automatically activated — legacy bearer path permanently open
  - `includes/class-connect-auth.php:261-268`
  - **Impact:** The anti-replay HMAC path (commit 9f499f1) is dead code on all existing installs. A captured bearer token is valid indefinitely against all Hub-facing endpoints.
  - **Fix:** Call `update_option('peanut_connect_require_signed_requests', true)` at the end of both auto_connect_to_hub and manual_connect_to_hub success paths. Existing sites need a migration or Settings UI toggle.
  - *Verifier:* Confirmed: grep across the entire worktree finds `peanut_connect_require_signed_requests` only in class-connect-auth.php (lines 261-263 read it, never write it) and in CHANGELOG.md as documentation. The option is never set to true anywhere in the codebase. The legacy Bearer fallback is therefore always reachable.
- **P1 · security** — disconnect_hub sends the live API key in plaintext request body with no Authorization header
  - `includes/class-connect-api.php:1237-1248`
  - **Impact:** An observer of the outbound disconnect request obtains the live hub_api_key at exactly the moment the admin believes they have revoked access. The fire-and-forget request also means no confirmation of receipt.
  - **Fix:** Send the key as `Authorization: Bearer` header (matching every other Hub call) instead of in the JSON body. The body can identify the site by URL alone.
  - *Verifier:* Confirmed: lines 1238-1245 show headers contain only Content-Type and Accept; the `api_key` is in the JSON body at line 1244. No Authorization header present. `blocking: false` (line 1247) means there is no response check even if Hub rejects the body-key pattern.
- **P1 · security** — DNS-rebinding window in SSRF guard: gethostbyname resolves at validation time, wp_remote_post fires separately
  - `includes/class-connect-api.php:2021-2028`
  - **Impact:** A DNS record with a very short TTL can resolve to a public IP at `is_safe_hub_host` validation time and an RFC1918 or loopback IP at `wp_remote_post` connect time, bypassing the SSRF guard entirely.
  - **Fix:** Resolve the hostname once, pin the IP, and pass it to `wp_remote_post` via a custom curl resolver or `CURLOPT_RESOLVE`, enforcing SNI separately. Alternatively, restrict Hub URL to an admin-configured allowlist.
  - *Verifier:* Confirmed: `is_safe_hub_host` calls `gethostbyname($host)` at line 2021 and validates the resolved IP, then returns. The subsequent `wp_remote_post` at lines 1006 (auto_connect) and 1129 (manual_connect) re-resolves independently with no pinned IP. The classic TOCTOU DNS rebinding window exists. The SSRF guard (`is_safe_hub_host`) is called from both entry points (lines 991 and 1115), making both vulnerable.
- **P1 · security** — disconnect_hub does not authenticate to Hub's revocation endpoint — Hub may never revoke the key
  - `includes/class-connect-api.php:1235-1248`
  - **Impact:** If Hub requires authentication on `/api/v1/sites/disconnect` (the normal pattern), Hub silently rejects the unauthenticated fire-and-forget POST. The old key remains valid on Hub indefinitely after the admin disconnects locally.
  - **Fix:** Add `Authorization: Bearer <api_key>` to the disconnect request headers. Make the request blocking with a short timeout. Log or surface failure rather than silently succeeding.
  - *Verifier:* Confirmed as distinct from finding 2: the `headers` array at lines 1238-1241 contains only Content-Type and Accept — no Authorization header. Combined with `blocking: false` (line 1247), there is no way to know if Hub accepted or rejected the revocation request. This is a separate impact from key leakage: it specifically means Hub's server-side record of the key is never cleaned up.
- **P2 · correctness** — Duplicate REST routes for /hub/disconnect and /settings/hub/disconnect both call the same handler — silent conflict
  - `includes/class-connect-api.php:70-74, 727-731`
  - **Impact:** The /hub/ namespace section is labeled 'Hub Settings endpoints (admin)' but uses admin_permission_check, not hub_permission_callback, creating a misleading pattern. The duplicate route creates future maintenance risk and ambiguity about the intended caller of /hub/disconnect.
  - **Fix:** Remove the duplicate `/hub/disconnect` route at lines 727-731. The `/settings/hub/disconnect` route is what the SPA uses. If a Hub-bearer-authenticated disconnect alias is desired in the future, it should use `hub_permission_callback`, not `admin_permission_check`.
  - *Verifier:* Confirmed: two separate `register_rest_route` calls register the same handler. Line 70-74: `POST /settings/hub/disconnect` → `[$this, 'disconnect_hub']` with `admin_permission_check`. Line 727-731: `POST /hub/disconnect` → same callback, same permission. The SPA uses only the /settings/ path (confirmed by searching Settings.tsx). The /hub/ namespace section at line 701-703 is labeled 'Hub Settings endpoints (admin)' but all its routes use admin_permission_check rather than hub_permission_callback.
- **P2 · security** — Both keys stored plaintext in wp_options — single database read gives full Hub access
  - `includes/class-connect-auth.php:62, 241`
  - **Impact:** SQL injection elsewhere in WordPress, a compromised plugin, or phpMyAdmin on shared hosting exposes both the site_key and hub_api_key with no additional barrier. Both are long-lived with no rotation mechanism.
  - **Fix:** For the hub_api_key (used only for outbound calls), consider envelope encryption or at minimum a wp_hash-based check for inbound verification. For site_key, verify Hub stores only a hash and remove or encrypt the plaintext edge copy.
  - *Verifier:* Confirmed: `get_option('peanut_connect_site_key')` at line 62 and `get_option('peanut_connect_hub_api_key', '')` at line 241 both return raw plaintext strings. No hashing or encryption wrappers are present anywhere in the auth file. The auditor's fix suggestion about hashing hub_api_key is partially correct but would require storing plaintext for outbound calls — the real fix is envelope encryption or at minimum separate read/write key stores.
- **P2 · security** — auto_connect_to_hub allows re-pairing over an existing live connection without local pre-check
  - `includes/class-connect-api.php:968-1088`
  - **Impact:** An XSS payload or a logged-in admin on a malicious page can silently replace the legitimate Hub key by targeting a different Hub instance (which won't return ALREADY_CONNECTED). The old Hub loses visibility; the attacker gains full Hub→edge control.
  - **Fix:** Check `get_option('peanut_connect_hub_api_key')` before generating a new key. If non-empty, return a 409 ALREADY_CONNECTED error directing the admin to disconnect first.
  - *Verifier:* Confirmed: `auto_connect_to_hub` (line 968) does no local pre-check for an existing hub_api_key. It generates a new key immediately (line 1000), POSTs it to the supplied Hub URL, and only relies on Hub returning `ALREADY_CONNECTED` (line 1042) to block re-pairing. If a different Hub URL is supplied, Hub has no record of the old connection and will accept the new key, silently overwriting lines 1059-1060.
- **P2 · states** — Half-paired state after disconnect_hub: local cleared but Hub key not revoked — no recovery path surfaced to admin
  - `includes/class-connect-api.php:1247-1254`
  - **Impact:** After a network failure during disconnect, the admin SPA shows not connected but Hub still shows the site as active. Reconnect via auto_connect silently fails (Hub returns ALREADY_CONNECTED). The admin has no indication of the asymmetry.
  - **Fix:** Make the disconnect request blocking with a short timeout (5s). Return a 503 with an explicit message if Hub cannot be notified, and do not delete local options until Hub confirms. If Hub is unreachable, leave local options intact and surface a warning.
  - *Verifier:* Confirmed: `blocking: false` at line 1247 means the plugin deletes local options at lines 1252-1254 and returns 200 (line 1259) before knowing whether the Hub request was even sent by the TCP stack. The `timeout: 10` at line 1246 has no effect in non-blocking mode in WordPress/cURL. There is no error-state branch for a failed Hub notification.
- **P2 · ux** — Settings.tsx hardcodes Hub URL default to hub.peanutgraphic.com — breaks Hub-blind installs and multi-Hub deployments
  - `frontend/src/pages/Settings.tsx:46`
  - **Impact:** Leaks Hub's existence and branded URL to Hub-blind installs (contract violation). Agencies on a self-hosted Hub must manually clear the field every time the settings page loads, as there is no useEffect to replace the hardcoded default from loaded settings data.
  - **Fix:** Change `useState('https://hub.peanutgraphic.com')` to `useState('')`. Pre-fill via a `useEffect` that runs when `settings.hub.url` is available from the query.
  - *Verifier:* Confirmed: line 46 initializes `hubUrl` to the hardcoded string. Grepping the entire Settings.tsx file reveals no `useEffect` and no `setHubUrl(...)` call that reads from `settings.hub.url` (the only `settings.hub.url` reference at line 266 is a display-only read in a different UI section). The form inputs at lines 620-641 bind directly to the unmodified `hubUrl` state.

> **Coverage:** Traced: register_rest_route declarations (lines 30–107, 706–737), admin_permission_check (line 915), auto_connect_to_hub (968–1088), manual_connect_to_hub (1099–1190), test_hub_connection (1195–1224), disconnect_hub (1229–1263), trigger_hub_sync (1317–1374), update_hub_mode (1384–1403), is_safe_hub_host (1997–2031), class-connect-auth.php fully, peanut-connect.php generate_site_key/disconnect/deactivation hook, uninstall.php, Settings.tsx. NOT traced in depth: class-connect-hub-sync.php sync batching internals beyond line ~100, SecurityCard.tsx / PermissionsCard.tsx, rate-limiter internals.

### `hub-read-surface` — Hub read surface: /hub/plugins, /hub/themes, /hub/health, /hub/error-log, /hub/check-updates, /status, /forms/diagnostics, /forms/test-sync

**Flow.** The Hub read surface is registered in includes/class-connect-api.php (lines 463–756) with hub_permission_callback delegating to Peanut_Connect_Auth::verify_hub_request (class-connect-auth.php). Auth has two paths: HMAC-signed (X-Peanut-Signature header) with timestamp+nonce anti-replay, and a legacy Bearer-token fallback that is opt-out via the peanut_connect_require_signed_requests option (off by default). Handlers call into: Peanut_Connect_Health::get_health_data (class-connect-health.php, 30s transient cache), Peanut_Connect_Error_Log (class-connect-error-log.php, JSON file at wp-content/peanut-logs/error-log.json protected only by .htaccess), Peanut_Connect_Updates for plugin/theme lists, and direct $wpdb queries for forms diagnostics. The /status and /forms/diagnostics endpoints return operational metadata to any authenticated Hub caller.

**Verified findings:** 8 (P1×4 · P2×4)

- **P1 · performance** — force_check_updates makes two blocking synchronous outbound HTTP calls in the request path
  - `includes/class-connect-api.php:1574–1582`
  - **Impact:** Hub's /hub/check-updates call blocks for combined latency of wordpress.org plus peanutgraphic.com checks. On slow networks or with host-level HTTP egress restrictions this will timeout. Violates Hub-Edge Contract Rule 4 (no synchronous remote calls in the render path).
  - **Fix:** Return immediately after clearing caches; schedule the remote check via wp_schedule_single_event() and let Hub poll again after a brief interval.
  - *Verifier:* Confirmed. Line 1575 calls wp_update_plugins() (blocking HTTP to api.wordpress.org). Line 1582 calls $updater->force_update_check(), which (class-connect-self-updater.php:205-211) calls get_remote_update_info(), which checks a transient (lines 133-138) but falls through to a synchronous wp_remote_get() to peanutgraphic.com (line 145) when the transient is absent — which it always is after clear_update_cache() just cleared it (line 206). Both blocking calls confirmed.
- **P1 · security** — Legacy Bearer-token auth is opt-out, not opt-in — unsigned requests accepted by default
  - `includes/class-connect-auth.php:263`
  - **Impact:** An attacker who obtains the site key can replay it indefinitely against all hub endpoints on sites that have not toggled the option. Confirmed that `get_option('peanut_connect_require_signed_requests', false)` defaults to false at line 263, and the code falls through to the plain Bearer comparison at lines 271-293 without any signature check.
  - **Fix:** Flip the default to true. Announce a deprecation window for unsigned requests, give Hub a config flag to emit signatures, then hard-require signatures after a release cycle.
  - *Verifier:* Confirmed exactly as described. Line 263 reads `get_option('peanut_connect_require_signed_requests', false)` — false means the option check returns falsy and execution falls through to the Bearer comparison block at 271-293. No change to severity.
- **P1 · security** — Log file at wp-content/peanut-logs/error-log.json is unprotected on Nginx hosts
  - `includes/class-connect-error-log.php:99–118`
  - **Impact:** Full disclosure of up to 500 PHP error entries to unauthenticated visitors on all Nginx-hosted WordPress installs. Entries include sanitized file paths, error messages, user_id values, and request URLs.
  - **Fix:** Store the log file outside the webroot, or add a WP authentication gate on the JSON endpoint. At minimum, document that Nginx operators must add a location block denying direct access to the peanut-logs directory.
  - *Verifier:* Confirmed. Lines 99-111 create only an Apache .htaccess; lines 114-117 create an index.php fallback. The log is stored at `WP_CONTENT_DIR . '/peanut-logs/error-log.json'` (line 70), inside the webroot. Nginx ignores .htaccess and the index.php guard only prevents directory listing — not direct file GETs. No Nginx-specific protection exists.
- **P1 · security** — Error log entries expose WordPress user IDs to the Hub
  - `includes/class-connect-error-log.php:143,165`
  - **Impact:** PII leakage: WP user IDs are stored in log entries and returned verbatim via /hub/error-log. User IDs are not anonymised and can be cross-referenced with /wp-json/wp/v2/users to identify individual administrators.
  - **Fix:** Either omit user_id from log entries entirely, or redact it before returning via /hub/error-log (replace with a boolean `during_authenticated_session`).
  - *Verifier:* Confirmed. `get_current_user_id()` is stored in entries at line 143 (handle_error) and line 165 (handle_shutdown). get_error_log_entries at line 2741 returns `$entries` verbatim from Peanut_Connect_Error_Log::get_entries(), which includes raw user_id values with no redaction.
- **P2 · copy** — /status response returns hub_url verbatim — violates Hub-blind contract Rule 3
  - `includes/class-connect-api.php:2793`
  - **Impact:** On a Hub-blind deployment, the Hub's base URL is echoed back in the /status response, undermining the confidentiality requirement of Rule 3. However, the endpoint is Hub-authenticated (hub_permission_callback at line 741), so only callers who already possess valid credentials can retrieve this — limiting the practical attack surface to network interception.
  - **Fix:** Replace `'hub_url' => $hub_url` with `'hub_url_configured' => !empty($hub_url)`. Hub already knows its own URL; the edge should not echo it back.
  - *Verifier:* Confirmed the code at line 2793, and confirmed /status uses hub_permission_callback (line 741) — so it is NOT publicly accessible. The auditor rated this P1 and cited 'any party who inspects REST traffic,' which requires MITM on an authenticated channel. Severity downgraded to P2: real rule violation but not exploitable without credential interception. Contract doc (docs/HUB-EDGE-CONTRACT.md:141-147) lists the hub_url leak class as a tracked violation category.
- **P2 · performance** — /hub/error-log has no maximum on the limit parameter
  - `includes/class-connect-api.php:553–558`
  - **Impact:** A Hub caller can request all 500 entries, forcing a full file_get_contents + json_decode on every poll. On a busy site this can be several MB decoded repeatedly at Hub's polling interval.
  - **Fix:** Add `'maximum' => 500` and `'minimum' => 1` to the arg definition. For immediate relief, cap limit at 100 server-side in the handler.
  - *Verifier:* Confirmed. Lines 553-558 register the limit arg with `default: 50` and `sanitize_callback: absint` but no `maximum` key. Line 2730 passes the raw limit directly to get_entries(). get_entries() at class-connect-error-log.php:200-224 does a full file_get_contents + json_decode before slicing — confirmed at lines 206-211. The MAX_ENTRIES constant caps the file size at 500 entries, so maximum actual exposure is bounded, but the finding is accurate.
- **P2 · security** — get_error_log_entries calls Peanut_Connect_Error_Log::init() on every Hub request, stacking error handlers
  - `includes/class-connect-api.php:2733`
  - **Impact:** Error counts reported to Hub are unreliable (inflated). Stacked set_error_handler() frames on every /hub/error-log request cause errors to be logged multiple times per occurrence, distorting the counts sent to Hub.
  - **Fix:** Add a static `$initialized` flag in the init() method; if already initialised, return early without re-registering handlers.
  - *Verifier:* Confirmed. Line 2733 calls `Peanut_Connect_Error_Log::init()` unconditionally on every request. The init() method at lines 67-83 calls `set_error_handler()` and `register_shutdown_function()` every time with no guard flag. PHP stacks error handlers on repeated set_error_handler() calls. Severity matches — P2 is appropriate since this is a reliability/data-quality issue, not a direct security exposure.
- **P2 · states** — SSL probe in get_health_data uses verify_peer=false, making the cert validity result unreliable
  - `includes/class-connect-health.php:161–166`
  - **Impact:** A site with an expired certificate replaced by a self-signed fallback at the CDN/proxy level would show as 'valid' to Hub once the socket probe succeeds, suppressing alerts. Operators make remediation decisions based on this data.
  - **Fix:** Enable verify_peer and verify_peer_name. Mark the probe result as best-effort (`probe_succeeded: bool`) distinct from the HTTPS-URL check. Never report `valid: true` from an unverified probe.
  - *Verifier:* Confirmed. Lines 164-165 set verify_peer and verify_peer_name to false. The initial `valid` at line 148 is set conservatively from `$wp_url_is_https`. However, line 191 overwrites `valid` with `$days_remaining > 0` from an unverified cert — meaning a spoofed/self-signed cert presented by a misconfigured CDN would override the conservative default and report valid=true with a plausible expiry. Severity P2 is appropriate: requires a specific CDN/proxy misconfiguration to trigger, but the health signal is genuinely unreliable.

> **Coverage:** Traced: register_rest_route declarations (lines 463–756), hub_permission_callback and verify_hub_request (class-connect-auth.php full), all eight handler callbacks (get_all_plugins:2094, get_all_themes:2454, get_hub_health:1516, get_error_log_entries:2729, force_check_updates:1563, get_public_status:2762, get_forms_diagnostics:2815, test_form_sync:2942), Peanut_Connect_Error_Log (full), Peanut_Connect_Health (full), class-connect-self-updater.php (get_remote_update_info:131+force_update_check:205). NOT traced: Peanut_Connect_Updates::get_all_plugins/get_all_themes internals, class-connect-database.php, class-connect-forms.php, the admin SPA frontend (out of scope for this pure-API bite).

### `hub-mutation-surface` — Hub mutation surface: remote plugin/theme update, activate, deactivate, bulk-update, /update (perform_updates permission)

**Flow.** Hub calls Bearer-or-HMAC-authenticated REST endpoints under peanut-connect/v1/hub/: POST /hub/plugin/update and /hub/theme/update both route to `perform_update()` (class-connect-api.php ~891), which delegates to `Peanut_Connect_Updates::perform_update()` (class-connect-updates.php ~245). POST /hub/plugin/activate and /hub/plugin/deactivate call `activate_plugin()` / `deactivate_plugin()` (~421/455). POST /hub/plugins/bulk-update calls `bulk_update_plugins()` (~636). A newer unified endpoint POST /update calls `apply_update()` (~3271). Auth is via `hub_permission_callback_for('perform_updates')` which calls `verify_hub_request()` → HMAC or legacy Bearer check. The only security-plugin protection is `strpos($plugin_file, 'peanut-connect')` in deactivate/delete. Admin-facing duplicates (/plugin/activate, /plugin/deactivate, /admin/update) share the same handler methods but use `admin_permission_check()` (manage_options).

**Verified findings:** 9 (P0×1 · P1×4 · P2×4)

- **P0 · security** — Hub can deactivate or delete any security plugin — no allowlist protection
  - `includes/class-connect-updates.php:472,503`
  - **Impact:** A compromised Hub key can deactivate or delete Wordfence, Sucuri, or any other security plugin via a single API call, collapsing the site's security perimeter silently.
  - **Fix:** Introduce a protected-plugin allowlist (filterable via peanut_connect_protected_plugins) checked in deactivate_plugin() and delete_plugin() before the peanut-connect self-guard. Reject with WP_Error('protected_plugin') if matched.
  - *Verifier:* Confirmed. Lines 472 and 503 in class-connect-updates.php only block plugins matching 'peanut-connect' via strpos. No check exists for any security plugin. The deactivate and delete paths are fully open to any Hub-issued call for any plugin slug not matching 'peanut-connect'.
- **P1 · correctness** — Hub-facing perform_update() has zero activity logging; all mutation audibility is lost for /hub/plugin/update and /hub/theme/update
  - `includes/class-connect-api.php:891-906`
  - **Impact:** Site owners reviewing the activity log after an incident have no record of which plugins or themes were updated by Hub via these two routes and when. The audit trail that the activity log exists to provide is absent for these specific update paths.
  - **Fix:** Add an Activity_Log::log('plugin_updated'/'theme_updated', 'success', $result) call in the perform_update() callback after a successful result, mirroring the pattern used in activate_plugin() at line 2355-2358.
  - *Verifier:* Confirmed. The perform_update() callback at lines 891-906 calls Peanut_Connect_Updates::perform_update() and returns directly with no Activity_Log call. In contrast, activate_plugin() (2355-2358), deactivate_plugin() (2383-2387), delete_plugin() (2411-2414), and bulk_update_plugins() (2430-2438) all log. Note: the /update endpoint (apply_update at line 3290) DOES log via 'update_applied', so this gap is specific to /hub/plugin/update and /hub/theme/update.
- **P1 · security** — Type confusion: /hub/plugin/update accepts type=core, triggering an unannounced WP core upgrade
  - `includes/class-connect-api.php:482-485`
  - **Impact:** A Hub caller (or attacker with a valid key) can send type=core to /hub/plugin/update, bypassing the intended plugin-only scope and triggering a full WordPress core upgrade regardless of site owner intent.
  - **Fix:** Add 'enum' => ['plugin'] to the type arg on /hub/plugin/update, 'enum' => ['theme'] on /hub/theme/update, and 'enum' => ['plugin', 'theme', 'core'] on the /update endpoint's component_type arg. WP REST API enforces enums before the callback fires.
  - *Verifier:* Confirmed. The /hub/plugin/update route at line 482 declares type with 'default' => 'plugin' and 'type' => 'string' but no enum constraint. perform_update() at line 891 passes $type directly to Peanut_Connect_Updates::perform_update(), which has an explicit 'case core: return self::update_core()' branch at line 258-259. The /hub/theme/update route has the identical gap. The /update endpoint also has no enum on component_type.
- **P1 · security** — Hub mutation endpoints bypass the stricter update rate-limit bucket — 60 req/min instead of 10
  - `includes/class-connect-auth.php:235,388-406`
  - **Impact:** The 'hub' rate-limit bucket falls through to DEFAULT_LIMIT=60. The 'update' bucket (limit=10/min) is only applied via permission_callback_for(), not hub_permission_callback_for(). Bulk-update can be hammered 60x/min, each call attempting all installed plugin updates.
  - **Fix:** In hub_permission_callback_for(), after verify_hub_request() succeeds, perform a second rate-limit check using the permission-mapped bucket (reuse the endpoint_map from permission_callback_for()) before returning true.
  - *Verifier:* Confirmed. verify_hub_request() at line 235 calls Peanut_Connect_Rate_Limiter::check($client_id, 'hub'). The 'hub' key is absent from get_endpoint_config() in class-connect-rate-limiter.php, so it falls to DEFAULT_LIMIT=60. hub_permission_callback_for() at lines 388-406 only calls verify_hub_request() with no secondary rate-limit check. In contrast, the admin permission_callback_for() at line 168-193 maps 'perform_updates' to the 'update' bucket (limit=10).
- **P1 · security** — perform_updates permission defaults to TRUE — update surface is open immediately on install
  - `includes/class-connect-auth.php:137`
  - **Impact:** Site owners who pair for monitoring only silently inherit full remote-update authority. If Hub is misconfigured or compromised, every paired site with default settings accepts remote mutation immediately.
  - **Fix:** Default perform_updates to false. Force the site owner to explicitly grant mutation permissions via the admin panel, with a clear risk explanation in the pairing flow.
  - *Verifier:* Confirmed. get_permissions() at line 133-141 returns the option with a hardcoded default array that includes 'perform_updates' => true. A freshly paired site that has never touched permissions will pass the has_permission('perform_updates') check without any explicit operator consent.
- **P2 · correctness** — /update endpoint advertises a version param that is silently ignored
  - `includes/class-connect-api.php:881-884`
  - **Impact:** Hub or callers that send version=X.Y.Z expecting a pinned update get the latest available version instead, with no error or warning. Silent contract breach that could cause unexpected version jumps.
  - **Fix:** Either remove version from the declared args (if intentionally unsupported), or implement version-pinned updates by filtering the update transient before calling the upgrader. Document the decision in openapi.yaml.
  - *Verifier:* Confirmed. Line 881-883 declares version as a registered arg. apply_update() at lines 3272-3276 reads only component_type and slug, passing them to perform_update() which has no version parameter at all. The version value is never read anywhere in the call chain.
- **P2 · security** — Self-protection string check uses strpos — fragile and creates false positives/bypass risk
  - `includes/class-connect-updates.php:472,503`
  - **Impact:** Operators cannot deactivate legitimately named third-party plugins whose folder contains 'peanut-connect'; the primitive is also semantically wrong for an exact-match guard.
  - **Fix:** Replace strpos with an exact match against plugin_basename(PEANUT_CONNECT_FILE), using === not strpos.
  - *Verifier:* Confirmed. Lines 472 and 503 both use strpos($plugin_file, 'peanut-connect') !== false as the guard. This is case-sensitive strpos and would block any plugin whose file path contains the substring 'peanut-connect', including unrelated third-party plugins. The canonical self-check should compare against plugin_basename(PEANUT_CONNECT_FILE) with strict equality.
- **P2 · states** — bulk_update_plugins always returns HTTP 200 even when every update fails
  - `includes/class-connect-api.php:2441-2444`
  - **Impact:** Hub cannot distinguish a partial or total failure from a full success using HTTP semantics. Silent update failures could leave vulnerable plugin versions in place while Hub believes the update was applied.
  - **Fix:** Return HTTP 207 (Multi-Status) when $results['failed'] is non-empty, or at minimum set 'success' => false when all updates failed. Include a machine-readable partial_failure flag for the mixed case.
  - *Verifier:* Confirmed. Lines 2441-2444 always return new WP_REST_Response(['success' => true, 'data' => $results], 200) regardless of $results['failed'] content. The activity log at line 2435 does use 'warning' status when failed_count > 0, but the HTTP response to Hub is always 200/success.
- **P2 · ux** — activate/deactivate endpoints accept raw plugin file paths but /hub/plugin/update accepts only slugs — inconsistent Hub contract
  - `includes/class-connect-api.php:519,532 vs 486-490`
  - **Impact:** Hub callers must know two different identifier schemes for the same plugin object. A Hub that passes a slug to activate, or a file path to update, will get a confusing error with no indication of which format is expected.
  - **Fix:** Normalize to a single canonical identifier across all plugin mutation endpoints — preferably the full plugin file path with find_plugin_file() applied as a slug fallback. Update openapi.yaml to reflect the canonical form.
  - *Verifier:* Confirmed. /hub/plugin/activate at line 518 and /hub/plugin/deactivate at line ~530 take 'plugin' (the full file path like 'akismet/akismet.php'). /hub/plugin/update at line 486 takes 'slug' (e.g. 'akismet') and resolves via find_plugin_file(). Two distinct identifier conventions exist for operations on the same resource.

> **Coverage:** Traced: class-connect-api.php (route registration lines 162–545, 868–885, handlers 891–906, 2342–2444, 3271–3297), class-connect-updates.php (full: update_plugin 109–156, update_theme 161–205, update_core 210–240, perform_update 245–264, find_plugin_file 269–310, activate_plugin 421–450, deactivate_plugin 455–485, delete_plugin 490–527, bulk_update_plugins 636–659), class-connect-auth.php (verify_hub_request 232–295, verify_signed_hub_request 306–341, hub_permission_callback_for 388–410, has_permission 119–128, get_permissions 133–141), class-connect-rate-limiter.php (config 249–306). NOT traced: class-connect-backup.php health_check (called in apply_update but not in this bite's mutation path), frontend SPA update UI (admin-side only), API proxy endpoint.

### `backup-restore` — Backup + restore: /backup, /restore — remote-triggered backup creation and restore (recent auth-RCE fix 14742ef "verify backup authenticity before /restore")

**Flow.** Hub POSTs to /peanut-connect/v1/backup (hub_permission_callback auth) → create_backup() handler in class-connect-api.php:3198 → Peanut_Connect_Backup::create_backup() in class-connect-backup.php → export_database() + create_zip() → SHA-256 registered in peanut_connect_known_backups option → response includes storage_path, backup_name, sha256. Restore: Hub POSTs to /restore with backup_url → restore_backup() handler at api.php:3226 → host-only check against peanut_connect_hub_url option → Peanut_Connect_Backup::restore_backup() → maybe_register_existing_backups() seeding → is_known_backup() SHA-256 allowlist check → download_url() fetch → unzip_file() extract to restore-{time()} dir → import_database() SQL execution → copy_directory() file copy with zip-slip guard → cache flush. Auth is bare hub_permission_callback (Bearer token or HMAC) with no backup-specific permission gate.

**Verified findings:** 10 (P1×6 · P2×4)

- **P1 · correctness** — Activity log calls for backup_created and backup_restored pass wrong argument types — PHP TypeError
  - `includes/class-connect-api.php:3214, 3256`
  - **Impact:** Backup and restore operations are never logged in the activity log, defeating audit trail. In strict-type PHP environments this is a fatal TypeError surfacing as a 500 to Hub.
  - **Fix:** Fix both callsites: Peanut_Connect_Activity_Log::log('backup_created', 'success', 'Backup created', $result) and log('backup_restored', 'success', 'Backup restored', $result).
  - *Verifier:* Confirmed. log() signature at class-connect-activity-log.php:101-105 is log(string $type, string $status, string $message, array $meta = []). Line 3214 calls log('backup_created', $result) where $result is an array — second positional arg expects string $status. Line 3256 is identical. Both will throw TypeError on PHP strict type enforcement.
- **P1 · correctness** — import_database silently ignores all query errors — a failed DB restore returns true
  - `includes/class-connect-backup.php:330`
  - **Impact:** Hub receives success:true on a completely failed database restore. The site is left with dropped tables (DROP TABLE IF EXISTS ran in the export) but no re-inserted data — site is broken with no error reported.
  - **Fix:** Check $wpdb->last_error after each query. Collect failures and return a WP_Error with count and last error message if any statements fail. At minimum abort on the first DDL failure.
  - *Verifier:* Confirmed. Line 330: $wpdb->query($statement) return value is discarded. The phpcs:ignore comment addresses unprepared SQL only, not the missing error check. The function unconditionally returns true at line 332 regardless of how many statements failed.
- **P1 · security** — restore_backup host-check ignores URL scheme — MITM-able over HTTP even when Hub is HTTPS
  - `includes/class-connect-api.php:3236-3238`
  - **Impact:** A network-position attacker supplying http://hub.example.com/evil.zip passes the host check; download_url() fetches over cleartext, allowing MITM delivery of a malicious archive. The SHA-256 allowlist is the backstop but the scheme gap undermines the layered defence.
  - **Fix:** Extract PHP_URL_SCHEME from both $hub_url and $backup_url and assert they match and equal 'https'. Return 400 if either is empty or non-HTTPS.
  - *Verifier:* Confirmed. Lines 3236-3237 parse only PHP_URL_HOST from both URLs; PHP_URL_SCHEME is never extracted or compared anywhere in the host-check block (3235-3243). esc_url_raw does not strip http://. Evidence matches exactly.
- **P1 · security** — Seeding bypass: maybe_register_existing_backups() auto-registers ALL zips in peanut-backups before the allowlist check runs
  - `includes/class-connect-backup.php:256-257, 458-472`
  - **Impact:** Any attacker who can write a malicious .zip into wp-content/peanut-backups before the seeded flag is set will have that archive's hash registered, making is_known_backup() return true for it. The seeded flag prevents re-entry on subsequent calls, so the attack window is first-ever /restore call on an upgraded site — still a real and exploitable race.
  - **Fix:** Move seeding to a plugin upgrade hook (register_activation_hook or an upgrade routine keyed on version), not lazily inside restore_backup(). Once the seeded flag is set at upgrade time the restore path never touches the backup directory for registration again.
  - *Verifier:* Confirmed. Line 256 calls maybe_register_existing_backups() unconditionally before line 257 is_known_backup(). Lines 458-471 glob *.zip and register every hash found. The seeded flag (line 459) limits re-entry to one run, so the window exists only before first restore on a freshly upgraded install — severity is accurately P1 but slightly more constrained than the finding implies.
- **P1 · security** — No backup/restore permission gate — any authenticated Hub request can trigger destructive restore
  - `includes/class-connect-api.php:841, 858`
  - **Impact:** A site admin cannot independently disable remote restore access. Restore is the highest-impact endpoint (full DB overwrite + file replacement = RCE-equivalent) and has no independent permission gate, unlike /update which gates on 'perform_updates'.
  - **Fix:** Add 'perform_backups' and 'perform_restore' to get_permissions() defaults (true for backward compat) and gate /backup on hub_permission_callback_for('perform_backups') and /restore on hub_permission_callback_for('perform_restore').
  - *Verifier:* Confirmed. Line 841 (/backup) and 858 (/restore) both use bare hub_permission_callback. Line 871 (/update) uses hub_permission_callback_for('perform_updates'). The permissions store (class-connect-auth.php:134-140) has no backup or restore key. The asymmetry is real.
- **P1 · states** — Partial restore has no rollback — DB is overwritten even if file copy subsequently fails, leaving site in split state
  - `includes/class-connect-backup.php:279-293`
  - **Impact:** A restore interrupted after import_database() succeeds but before copy_directory() completes leaves the site with a restored DB but original wp-content files — mismatched state that typically breaks WordPress. copy_directory() returns void so the caller cannot detect failure at all.
  - **Fix:** Create a pre-restore DB snapshot (or at minimum capture current credentials), make copy_directory return bool/WP_Error and check it, and document the atomicity limitations. Wrapping import_database in a transaction is recommended for the DB phase.
  - *Verifier:* Confirmed. Line 282 calls import_database() (full DB overwrite via DROP TABLE + INSERT per export_database). Line 292 calls copy_directory() which is declared void (line 341) and returns nothing. No transaction or rollback path exists. The caller at line 292 has no way to detect file-copy failure.
- **P2 · correctness** — type parameter accepted by /backup API but completely ignored in create_backup()
  - `includes/class-connect-api.php:843-845; includes/class-connect-backup.php:22`
  - **Impact:** API contract mismatch: Hub cannot trigger a database-only backup. Calling POST /backup with type=db returns a full backup silently — no error, no indication that the parameter was ignored.
  - **Fix:** Either implement type-based selective backup (db-only skips create_zip, files-only skips export_database), or remove the 'type' arg from the route registration and document that only full backups are supported.
  - *Verifier:* Confirmed. Line 843-845 registers 'type' arg with default 'full'. create_backup() at line 22 receives $params but $params['type'] is never read anywhere in the method body — always runs both export_database() and create_zip() regardless.
- **P2 · security** — backup_url accepts non-HTTPS schemes via esc_url_raw; no validate_callback rejects file:// or ftp://
  - `includes/class-connect-api.php:860-863`
  - **Impact:** Defense-in-depth gap: if the host-check logic is ever refactored, file:// or ftp:// URIs could reach download_url(). The current host-check blocks file:// only incidentally (empty host), which is non-obvious and fragile.
  - **Fix:** Add validate_callback to the backup_url arg that calls filter_var($value, FILTER_VALIDATE_URL) and checks parse_url($value, PHP_URL_SCHEME) === 'https'. Reject anything else with a 400.
  - *Verifier:* Confirmed. Lines 860-863 register backup_url with only sanitize_callback: 'esc_url_raw' and no validate_callback. esc_url_raw() preserves file:// and ftp://. The existing host-check at 3238 would catch file:// (empty host) but this is an indirect and non-obvious defence. Severity P2 is appropriate — mitigated but not explicitly guarded.
- **P2 · security** — Backup archive contains wp_options with Hub API key and site secrets — stored in web-accessible location on nginx/LiteSpeed
  - `includes/class-connect-backup.php:22-88`
  - **Impact:** The unguessable token (wp_generate_password(20,false,false)) is the only protection on non-Apache servers. Any path traversal or directory listing vulnerability exposes the full secret store including Hub API keys.
  - **Fix:** Exclude peanut_connect_hub_api_key and peanut_connect_site_key rows from the SQL export, or document clearly that the backup archive must be treated as a secret at the API level.
  - *Verifier:* Confirmed. Lines 97-111 export all tables with SHOW TABLES LIKE prefix% — no row-level exclusions for secrets. Lines 34-37 acknowledge .htaccess is ignored on nginx/LiteSpeed/Caddy. The token (line 38) is the real protection. Finding is accurate; severity P2 is appropriate (token entropy is ~119 bits which is high, so exploitation requires an additional vulnerability).
- **P2 · ux** — restore response does not report what was restored or whether DB and file phases both succeeded
  - `includes/class-connect-backup.php:304-308`
  - **Impact:** Hub cannot reliably verify a restore succeeded. A corrupt or incomplete archive (no database.sql, no wp-content dir) returns success:true with no diagnostic data, making silent partial restores indistinguishable from full restores.
  - **Fix:** Return structured metadata: db_restored (bool), files_restored (bool), files_count (int), and any warnings encountered during the operation.
  - *Verifier:* Confirmed. Lines 304-308 return only {success: true, message: '...', restored_at: '...'}. Lines 281-292 show both the DB restore (line 281: if file_exists sql_file) and file copy (line 291: if is_dir wp_content_backup) are conditional — a backup with neither returns the identical success response. No phase-completion flags are set.

> **Coverage:** Traced end-to-end: class-connect-api.php route registration (lines 838–865, 3198–3259), class-connect-backup.php (all 535 lines), class-connect-auth.php (full file), tests/Test_Backup_Integrity.php, docs/HUB-EDGE-CONTRACT.md. Git history for commits 14742ef, 7f1c66e, 9f499f1 inspected. Did not trace cloud-upload path (upload_to_cloud is a stub returning local path). Did not audit unzip_file() WordPress core internals. Activity log class inspected at log() signature lines 101–139.

### `banner-remote` — Event banner remote control: /banner/show, /banner/hide, /banner/status, /banner/diagnostics + visitor-facing render (recent site-wide XSS fix 6b4b195)

**Flow.** Hub POSTs to /banner/show with html/css/position/show_at/hide_at params (Bearer + HMAC auth via hub_permission_callback). Peanut_Connect_API::show_event_banner() extracts params and calls Peanut_Connect_Event_Banner::set_banner(), which sanitizes html via wp_kses() with a tight allowlist, sanitizes css via a chained preg_replace strip, stores the result as a WP option, and fires an async wp_remote_post acknowledgment back to Hub. Visitor render: render_banner() is hooked to wp_head (priority 1); it re-sanitizes at render (defense-in-depth), echoes the CSS in a style block, echoes the banner HTML, then echoes an inline script that calls document.body.classList.add(). Assets (event-banner.css, event-banner.js) are enqueued via wp_enqueue_scripts conditional on an active banner. /banner/hide calls clear_banner() (delete_option). /banner/status returns metadata only (no html/css). /banner/diagnostics returns the full stored option including sanitized html/css. Relevant files: includes/class-connect-api.php lines 763-837 and 3074-3185; includes/class-connect-event-banner.php (full); assets/js/event-banner.js; assets/css/event-banner.css; tests/Test_Banner_Sanitization.php.

**Verified findings:** 9 (P0×1 · P1×4 · P2×4)

- **P0 · correctness** — render_banner() hooks to wp_head — banner div and inline script are emitted inside HTML <head>
  - `includes/class-connect-event-banner.php:27`
  - **Impact:** The banner DIV and inline script land inside <head> on every public pageview. Browser error-recovery behavior differs across engines; the element may not render at the expected DOM position, body-class offsetting via CSS (--peanut-banner-height) will mismatch, and the inline script fires before the body element is reliably available.
  - **Fix:** Change the add_action hook from 'wp_head' to 'wp_body_open' (WordPress 5.2+) with a fallback to 'wp_footer'. Move only the <style> block emission to a separate wp_head hook if inline CSS must precede page paint.
  - *Verifier:* Confirmed. Line 27: add_action('wp_head', [__CLASS__, 'render_banner'], 1). render_banner() at lines 149-163 emits <style>, then the banner <div> HTML (via sanitize_banner_html), then an inline <script>document.body.classList.add(...);</script>. All three are emitted inside <head>. block-level HTML inside <head> is invalid per HTML5 spec.
- **P1 · accessibility** — wp_kses allowlist strips ALL aria-* and role attributes — Hub cannot deliver an accessible banner even if it tries
  - `includes/class-connect-event-banner.php:289-298`
  - **Impact:** The banner is invisible to screen readers: no landmark role, no live-region announcement, and any close button supplied by Hub has no accessible label after sanitization. Every client site running a banner fails WCAG 2.1 AA §4.1.3 and §2.4.6.
  - **Fix:** Add aria-*, role, and tabindex to the wp_kses allowlist for relevant elements: 'div' => ['class' => true, 'role' => true, 'aria-live' => true, 'aria-label' => true, 'aria-describedby' => true, 'tabindex' => true], and similarly for button, a, span.
  - *Verifier:* Confirmed. Lines 289-298: the allowed array for all elements lists only class/href/type/rel/target/alt/width/height — no aria-* or role attributes anywhere. wp_kses with an explicit allowlist strips every unlisted attribute. The auditor labeled this lens 'security' which is a mislabel; this is an accessibility finding. Severity P1 is correct.
- **P1 · accessibility** — Close button has no enforced accessible label and banner wrapper has no landmark role or live-region announcement
  - `includes/class-connect-event-banner.php:295 / assets/js/event-banner.js:43-48`
  - **Impact:** Screen reader users cannot identify the close button purpose, cannot navigate to the banner as a landmark, and are not notified when the banner appears (no aria-live='polite'). Compounds the allowlist omission in the previous finding.
  - **Fix:** In render_banner(), inject defaults onto the wrapper and close button after outputting Hub HTML: add role='region' and aria-label='Event announcement' to the outermost banner wrapper, and aria-label='Close announcement' to any close button lacking one. Alternatively add these as required fields in Hub's template contract.
  - *Verifier:* Confirmed. Line 295: 'button' => ['class' => true, 'type' => true] — aria-label not in allowlist so even if Hub supplies it, it is stripped. render_banner() (lines 126-164) never injects a landmark role or aria-live attribute on the wrapper. event-banner.js lines 43-48: close button wired by class selector only, no accessible label enforcement. Partially overlaps finding 4 but tests the render output rather than the sanitizer allowlist.
- **P1 · correctness** — set_banner() returns false and the API returns HTTP 500 on idempotent Hub retry (same banner content, update_option unchanged-value behavior)
  - `includes/class-connect-event-banner.php:74,94 / includes/class-connect-api.php:3104-3108`
  - **Impact:** Hub receives 500 on a legitimate idempotent retry. The banner IS correctly active and stored; the 500 is a false alarm that may trigger Hub error recovery, operator alerts, or unnecessary hide+re-show cycles.
  - **Fix:** After update_option() returns false, check whether the option is already set to the intended deployment_id before treating as failure: $existing = get_option(self::OPTION_KEY); if ($existing && $existing['deployment_id'] === $banner['deployment_id']) return true;
  - *Verifier:* Confirmed. Line 74: $result = update_option(self::OPTION_KEY, $banner, false). WordPress documents that update_option() returns false both on DB failure AND when the stored value equals the new value. Line 76: only logs/acks on truthy $result. Line 94: returns $result (false). API at line 3104-3108: returns 500 if false.
- **P1 · security** — sanitize_position() whitelist is ['top','bottom'] but REST args declare enum ['top','bottom','fixed-top','fixed-bottom'] — fixed positions silently coerced to 'top'
  - `includes/class-connect-event-banner.php:273 vs includes/class-connect-api.php:789`
  - **Impact:** Fixed-position banners requested by Hub always render as relative-top banners. The content-offset body padding (--peanut-banner-height) is never applied for fixed banners, causing them to overlap page content. No error is returned to Hub; it believes fixed positioning was honored.
  - **Fix:** Expand sanitize_position to include 'fixed-top' and 'fixed-bottom': in_array($position, ['top', 'bottom', 'fixed-top', 'fixed-bottom'], true). Update the inline script at line 163 to use the full position value for the body class. Update Test_Banner_Sanitization.php to cover the new values.
  - *Verifier:* Confirmed. Line 273: in_array($position, ['top', 'bottom'], true). API args at line 789: 'enum' => ['top', 'bottom', 'fixed-top', 'fixed-bottom']. Sending position='fixed-top' passes REST validation but is coerced to 'top' by sanitize_position. The CSS file has rules for .peanut-event-banner--fixed-top (confirmed by context). Lens labeled 'security' but this is primarily a correctness/contract bug; severity P1 appropriate.
- **P2 · performance** — check_banner_expiry() runs on 'init' hook on every pageload including admin — unnecessary option read when no banner is active
  - `includes/class-connect-event-banner.php:31,205-218`
  - **Impact:** On sites without a persistent object cache, every pageload (including admin screens, AJAX, and REST API calls) issues a SELECT on wp_options for peanut_connect_event_banner even when no banner is active. get_active_banner() already checks expiry on the frontend render path, making this hook redundant.
  - **Fix:** Guard with if (is_admin()) return; at the top of check_banner_expiry(). Better: remove the init hook entirely and rely on the expiry check already present in get_active_banner(). If background expiry is needed independently of frontend renders, schedule a wp_cron event at banner activation time.
  - *Verifier:* Confirmed. Line 31: add_action('init', [__CLASS__, 'check_banner_expiry']). Lines 205-218: check_banner_expiry() calls get_option() with no admin guard. Lines 45-50: get_active_banner() already performs the identical expiry check and calls clear_banner() before any render. The init hook is redundant for the render path and fires on every request type.
- **P2 · security** — sanitize_banner_css() url() callback allows data:image/svg+xml — SVG data URIs can carry embedded event handlers
  - `includes/class-connect-event-banner.php:319-321`
  - **Impact:** In edge browser versions or future regressions, an SVG data URI in CSS background could execute embedded script or carry tracking pixels that bypass the url() exfiltration blocker rationale. The sanitizer intent is to block all exfiltration/execution paths but SVG is a meaningful exception.
  - **Fix:** Restrict to #^\s*data:image/(?:png|jpe?g|gif|webp|avif|ico|bmp)#i — explicitly exclude svg+xml and svg. Add a test case in Test_Banner_Sanitization.php for data:image/svg+xml.
  - *Verifier:* Confirmed. Line 320: preg_match('#^\s*data:image/#i', ...) allows any data:image/* MIME type including data:image/svg+xml. No exclusion for SVG.
- **P2 · security** — Anchor target=_blank allowed without rel=noopener enforcement — enables reverse tabnapping on all Hub-supplied links
  - `includes/class-connect-event-banner.php:290`
  - **Impact:** A compromised Hub bearer or Hub configuration error could push a banner with target=_blank links that expose every visitor clicking those links to reverse tabnapping phishing. rel is allowed as an attribute but not required or enforced to include 'noopener noreferrer'.
  - **Fix:** In sanitize_banner_html(), after wp_kses(), post-process anchor tags to force rel='noopener noreferrer' on any <a target='_blank'>. Alternatively strip the target attribute from the allowlist entirely and document that Hub must not rely on _blank banners.
  - *Verifier:* Confirmed. Line 290: 'a' => ['href' => true, 'title' => true, 'class' => true, 'rel' => true, 'target' => true]. Both rel and target are optional; no post-processing enforces noopener on _blank anchors. sanitize_banner_html() returns wp_kses result directly with no further DOM traversal.
- **P2 · ux** — Hub machine consumer gets no error when show_at/hide_at is an invalid datetime string — silently stored as banner with no expiry
  - `includes/class-connect-event-banner.php:67-68`
  - **Impact:** Hub could push a banner with a malformed hide_at and receive HTTP 200 with no indication the date was invalid. The banner then runs indefinitely on the public site since strtotime(invalid) returns false and the expiry check at line 47 uses if ($hide_at && ...) which treats false as no expiry.
  - **Fix:** Add a validate_callback to show_at/hide_at args in route registration that calls strtotime() and returns WP_Error if it returns false. Return HTTP 422 with a descriptive message identifying the invalid value.
  - *Verifier:* Confirmed. Lines 67-68: sanitize_text_field() only — no format validation. Line 47: $hide_at = strtotime($banner['hide_at']); if ($hide_at && time() > $hide_at) — false (invalid date) is treated as no expiry. API route args (line 793-796) have sanitize_callback but no validate_callback for show_at/hide_at.

> **Coverage:** Traced: route registration -> permission_callback (hub_permission_callback, HMAC verified) -> all four handler callbacks -> set_banner/clear_banner/get_status/get_diagnostics -> sanitize_banner_html/sanitize_banner_css/sanitize_position -> render_banner (wp_head hook) -> enqueue_assets (wp_enqueue_scripts) -> check_banner_expiry (init hook) -> send_acknowledgment (async outbound). Also read the full JS and CSS assets and the banner sanitization test suite. Not reached: class-connect-auth.php internals (HMAC/nonce verified in a prior audit per context); the Hub-side /api/ptr/banner/acknowledge receiver; any theme-layer template hooks that might duplicate or suppress banner output.

### `api-proxy` — Hub API proxy: /hub/api-proxy (api_proxy permission) — Hub-driven proxied requests (SSRF hardening in 7f1c66e)

**Flow.** Hub POSTs to /peanut-connect/v1/hub/api-proxy. Permission gate: Peanut_Connect_Auth::hub_permission_callback_for('api_proxy') (class-connect-auth.php:388) verifies the Hub API key via verify_hub_request (Bearer token or HMAC-signed), then checks has_permission('api_proxy'). Handler is Peanut_Connect_API_Proxy::handle_request (class-connect-api-proxy.php:41): validates URL with filter_var(FILTER_VALIDATE_URL), checks scheme is HTTPS, checks host against a hard-coded ALLOWED_DOMAINS = ['ph.powerportal.com'] allowlist, then calls wp_remote_get or wp_remote_post and returns the raw body, status code, and content-type to Hub. The admin-SPA marketing proxy (class-connect-marketing.php, routes /marketing/*) is a separate flow that forwards to the stored Hub URL using admin session auth — it does not share this proxy class.

**Verified findings:** 9 (P1×3 · P2×5 · P3×1)

- **P1 · security** — Redirect-following enables SSRF to private/metadata IPs via allowlisted domain
  - `includes/class-connect-api-proxy.php:93-106`
  - **Impact:** A compromised ph.powerportal.com endpoint could redirect to 169.254.169.254 (AWS IMDS) or any RFC1918 address. WP HTTP follows up to 5 redirects by default; the destination is never re-checked against ALLOWED_DOMAINS. Response body returned verbatim to Hub.
  - **Fix:** Add 'redirection' => 0 to $args so the proxy never follows redirects. If redirect-following is needed, inspect the Location header and validate it against ALLOWED_DOMAINS and is_safe_hub_host() before proceeding.
  - *Verifier:* Confirmed. $args at line 93 contains only 'timeout' and 'sslverify'. No 'redirection' key is present anywhere in handle_request(). WP HTTP API default is 5 redirects. The ALLOWED_DOMAINS check at line 69 runs only on the initial URL.
- **P1 · security** — HMAC signing is opt-in (default off) — proxy endpoint accepts static Bearer for all existing installations
  - `includes/class-connect-auth.php:263`
  - **Impact:** Every deployed site uses the legacy Bearer path unless peanut_connect_require_signed_requests is manually set. A leaked Bearer token can be replayed indefinitely with no time-bounding or replay protection. The 'hub' rate-limit bucket itself falls to the default 60/min, which does not meaningfully slow automated attacks.
  - **Fix:** Set peanut_connect_require_signed_requests to true by default on new installs, or enforce it automatically during the pairing/connect flow. Provide an admin notice for existing sites that have not yet migrated.
  - *Verifier:* Confirmed. Line 263: get_option('peanut_connect_require_signed_requests', false). A grep of the codebase finds no call that writes this option. All Hub endpoints that call verify_hub_request() will fall through to the Bearer path on every existing site.
- **P1 · security** — No response body size cap — large upstream response causes PHP memory exhaustion
  - `includes/class-connect-api-proxy.php:121-127`
  - **Impact:** wp_remote_retrieve_body() returns the entire response with no limit. The string is then embedded in a WP_REST_Response array and JSON-encoded, doubling peak memory usage. A Hub operator or a compromised ph.powerportal.com endpoint can trigger repeated OOM crashes.
  - **Fix:** Add 'limit_response_size' => 1048576 to $args (line 93). If the response is truncated (wp_remote_retrieve_response_code returns 0 or body is shorter than Content-Length), return a 502 with a clear error message.
  - *Verifier:* Confirmed. Line 93-96: $args contains only 'timeout' and 'sslverify'. No limit_response_size is set. Line 121 calls wp_remote_retrieve_body() unconditionally with no size check before or after.
- **P2 · correctness** — marketing forward() silently promotes any 4xx Hub error to HTTP 200 if body contains success:true
  - `includes/class-connect-marketing.php:341-343`
  - **Impact:** A genuine Hub 401 or 403 that includes a body with success:true is silently promoted to 200, hiding the auth failure from the admin SPA. An expired or rotated Hub API key would appear functional for all marketing routes that return a success-like body.
  - **Fix:** Narrow the condition to only the known WAF-rewrite codes (405, 406, 503) rather than any status >= 400. Add an error_log() notice when the rewrite fires so operators can detect WAF interference.
  - *Verifier:* Confirmed. Lines 341-343: the condition is $status >= 400 with no restriction to specific WAF codes. The comment only mentions mod_security but the code would also promote genuine 401/403/422 responses if their body has success:true.
- **P2 · security** — ALLOWED_DOMAINS is a hard-coded compile-time constant — no extensibility or per-site configuration
  - `includes/class-connect-api-proxy.php:21-23`
  - **Impact:** Any future need for a second proxy target requires a code change and release cycle. Pressure to extend the allowlist in-code increases the risk of a broad or under-reviewed addition.
  - **Fix:** Expose a wp_options-backed allowlist (manage_options only), seeded with the current hard-coded entry. Validate each stored entry through is_safe_hub_host() or equivalent before persisting.
  - *Verifier:* Confirmed. Line 21-23: private const ALLOWED_DOMAINS = ['ph.powerportal.com']. No wp_options read, no filter hook, no admin UI. This is a real inflexibility and a maintenance risk, though not an immediate exploit.
- **P2 · security** — api_proxy rate-limit bucket is dead code — all Hub endpoints share the 'default' bucket (60/min)
  - `includes/class-connect-rate-limiter.php:279-283, includes/class-connect-auth.php:235`
  - **Impact:** Hub can make 60 proxied outbound calls per minute per client rather than the intended 30. The 'api_proxy' config entry is never referenced by any live code path, making the limit misleading.
  - **Fix:** In hub_permission_callback_for(), pass the permission name to verify_hub_request() as an endpoint parameter, and map 'api_proxy' to the existing 'api_proxy' config entry. Alternatively, have verify_hub_request() accept an optional endpoint argument.
  - *Verifier:* Confirmed. verify_hub_request() at line 235 hardcodes the bucket name as 'hub', which does not appear in get_endpoint_config()'s $configs array (lines 250-308), so it falls to 'default' (60/min). The 'api_proxy' entry at line 280 is never passed to check() by any code path.
- **P2 · security** — POST body forwarded as form-encoded with no Content-Type header — API contract mismatch
  - `includes/class-connect-api-proxy.php:104-105`
  - **Impact:** POST requests to ph.powerportal.com will have application/x-www-form-urlencoded bodies instead of JSON. Nested structures are corrupted or dropped. The proxy returns HTTP 200 from WordPress even when the upstream call fails due to the malformed body.
  - **Fix:** Add 'headers' => ['Content-Type' => 'application/json'] to $args and json_encode $params before assigning to $args['body'] for POST requests.
  - *Verifier:* Confirmed. Line 104: $args['body'] = $params (an array). No 'headers' key is set in $args at any point in handle_request(). WP HTTP encodes arrays as application/x-www-form-urlencoded by default.
- **P2 · states** — Proxy returns no structured error code when upstream times out — Hub cannot distinguish timeout from application error
  - `includes/class-connect-api-proxy.php:110-117`
  - **Impact:** Hub's retry logic cannot distinguish a timeout (retry with backoff) from a DNS failure (abandon) or TLS error (alert). The raw curl error string leaks implementation details and is not a stable API contract.
  - **Fix:** Map WP_Error codes (http_request_failed, etc.) to a stable machine-readable error_code field ('timeout', 'dns_failure', 'tls_error', 'connection_refused'). Return it alongside the human-readable message.
  - *Verifier:* Confirmed. Lines 110-117: the only error signal is success:false, status_code:0, and a raw $response->get_error_message() string. No error_code field is returned.
- **P3 · copy** — domain_not_allowed error leaks the concept of a proxy whitelist to Hub's logs
  - `includes/class-connect-api-proxy.php:70-78`
  - **Impact:** Minor. The message 'Domain "%s" is not in the proxy whitelist.' is returned over the REST API to Hub. It exposes internal architecture framing to any Hub log scraping or error forwarding that might reach site owners.
  - **Fix:** Replace with a shorter machine-targeted message: 'Proxy target domain is not permitted.' (omit 'whitelist' framing).
  - *Verifier:* Confirmed. Lines 72-76: the i18n string explicitly uses the word 'whitelist'. This is cosmetic and low-impact since Hub is a trusted machine consumer, but the string is factually present as reported.

> **Coverage:** Traced: class-connect-api.php line 577+ (route registration), class-connect-auth.php (all auth/permission code), class-connect-api-proxy.php (full handler), class-connect-rate-limiter.php (bucket config), class-connect-marketing.php (marketing proxy for comparison), docs/HUB-EDGE-CONTRACT.md, git log including 7f1c66e SSRF fix commit. Did NOT trace: frontend SPA code, backup/update/restore endpoints (out of bite scope), the gtm-beacon proxy (different class).

### `podcast-publish` — Podcast publish surface: /podcast/publish, /podcast/episodes-index, /podcast/augment (publish_content permission)

**Flow.** Hub sends authenticated POST (Bearer site-key or HMAC-signed) to three REST routes registered in includes/class-connect-api.php (~line 117-145). All three use Peanut_Connect_Auth::hub_permission_callback_for('publish_content'), which calls verify_hub_request then has_permission('publish_content'). /podcast/publish upserts a WP post (type=post, wp_kses_post on body) and writes PowerPress enclosure postmeta. /podcast/episodes-index returns all published posts that carry an enclosure meta (unbounded, N+1 queries). /podcast/augment rewrites the HB-TRANSCRIPT marker block inside an existing post's post_content and updates Yoast + PowerPress meta. Transcript HTML is assembled by pc_apply_transcript_block in includes/helpers/transcript-block.php. Auth class is includes/class-connect-auth.php; rate limiter is includes/class-connect-rate-limiter.php.

**Verified findings:** 8 (P0×2 · P1×3 · P2×3) · rejected 1

- **P0 · security** — transcript_html written to post_content with no sanitization — stored XSS via augment
  - `includes/class-connect-api.php:2304`
  - **Impact:** Stored XSS visible to every visitor of the episode page. Any actor controlling the Hub→Edge channel (MITM, compromised Hub, rotated key) can inject arbitrary HTML/JS into episode post_content.
  - **Fix:** Apply wp_kses_post() to $p['transcript_html'] before passing it to pc_apply_transcript_block, or sanitize $new_content before the wp_update_post call. Mirror the pattern at line 2209 where post_content already uses wp_kses_post.
  - *Verifier:* Confirmed. pc_apply_transcript_block (includes/helpers/transcript-block.php:24) receives the raw string and wraps it verbatim between HTML comment markers, then wp_update_post stores it directly. No sanitization anywhere in the chain. augment checks post_type at line 2298 but that is unrelated to the XSS path. The contrast with line 2209 where publish correctly uses wp_kses_post is exact.
- **P0 · security** — publish overwrite targets any post type — Hub can overwrite pages, CPTs, WooCommerce products
  - `includes/class-connect-api.php:2146`
  - **Impact:** Hub (or any actor with a valid bearer key) can silently overwrite any post on the site — including the home page, checkout page, or any CPT — by supplying its ID as wp_post_id.
  - **Fix:** After resolving the post via get_post(), assert $resolved_post->post_type === 'post' before proceeding. Return 400 if the type mismatches. augment already does this correctly at line 2298.
  - *Verifier:* Confirmed. Line 2146: `if (! empty($p['wp_post_id']) && get_post((int) $p['wp_post_id']))` — get_post() succeeds for any post type. There is no post_type guard in the publish path before wp_update_post at line 2215. The augment path at line 2298 has the correct `$post->post_type !== 'post'` check, which publish lacks.
- **P1 · correctness** — publish_content permission can never be granted — UI and pairing flow both omit it
  - `includes/class-connect-auth.php:119-128 and includes/class-connect-api.php:2685-2704`
  - **Impact:** The entire podcast publish surface is permanently non-functional on any real installation unless an operator manually inserts publish_content=true into wp_options out-of-band.
  - **Fix:** Add 'publish_content' to the update_permissions handler at lines 2692-2703. Store it with a default during pairing or expose a toggle in the admin UI. The get_permissions() default at line 134-140 should also be reflected in the has_permission() fallback.
  - *Verifier:* Confirmed. has_permission() (line 119-128) uses get_option('peanut_connect_permissions', []) — empty array default — and returns !empty($permissions[$permission]). get_permissions() (line 134-140) returns a different default with publish_content=>true, but this is only used for the GET API response, never stored and never read by has_permission(). update_permissions (lines 2692-2703) handles perform_updates, access_analytics, api_proxy only — publish_content is absent. On any site that hasn't manually set the option, all three podcast routes return 403.
- **P1 · performance** — episodes-index fires N+1 queries for enclosure and slug — O(n) DB hits for ~500 posts
  - `includes/class-connect-api.php:2258-2281`
  - **Impact:** A 500-episode call issues 1000+ additional DB queries. On shared hosting this can trigger max_execution_time, max_user_connections, or lock contention visible to site visitors.
  - **Fix:** Remove `'fields' => 'ids'` to return full post objects (pre-loaded), or add `'update_post_meta_cache' => true, 'update_post_term_cache' => false`. Alternatively add a 'page' param and cap at 200 posts per call.
  - *Verifier:* Confirmed. Line 2264: `'fields' => 'ids'` causes get_posts to return only IDs with no meta cache preloaded. The loop at lines 2268-2279 calls get_post_meta($pid, 'enclosure', true) and get_post_field('post_name', $pid) per iteration — both hit the DB on cache miss. The docblock at line 2256 explicitly states 'The archive is ~500 posts — no cap.' This is a textbook N+1 pattern.
- **P1 · security** — enclosure_url stored raw — no esc_url_raw, no scheme allowlist
  - `includes/class-connect-api.php:2190`
  - **Impact:** Malformed or javascript: scheme URLs in enclosure postmeta can break RSS feeds; if PowerPress echoes the raw URL into markup without escaping this is also an XSS vector in RSS clients.
  - **Fix:** Apply esc_url_raw() to $p['enclosure_url'] before passing it to build_powerpress_enclosure_meta. Optionally enforce an https:/http: scheme allowlist.
  - *Verifier:* Confirmed. Line 2190: `'url' => $p['enclosure_url']` is passed verbatim. Lines 2175, 2184, 2186 all apply esc_url_raw to itunes_image, pci_transcript_url, and pci_chapters_url. The route args for enclosure_url (line 124) have type:'string' but no sanitize_callback.
- **P2 · correctness** — publish endpoint does not set post_author — every Hub-created post is owned by user ID 0
  - `includes/class-connect-api.php:2207-2212`
  - **Impact:** Episode posts have no valid WP author, which can cause display bugs, incorrect Yoast structured data, and may prevent posts appearing in author archives.
  - **Fix:** Accept a wp_user_id param from Hub and set 'post_author'=>(int)$p['wp_user_id'] with existence validation, or fall back to a configurable default author option.
  - *Verifier:* Confirmed. Lines 2207-2212: $postarr sets post_title, post_content, post_status, post_type but no post_author. Machine requests have no WP session, so get_current_user_id() returns 0. The $p['author'] field is used only as a copyright string in the PowerPress settings array (line 2173), not mapped to a WP user ID.
- **P2 · states** — augment silently no-ops when Content-Type is not application/json — returns 404 instead of 400
  - `includes/class-connect-api.php:2293-2299`
  - **Impact:** Silent failure with a misleading error code. A Hub library change that alters Content-Type would break all transcript backfills with a 404 that looks like a data problem.
  - **Fix:** Use `$p = $request->get_json_params() ?: $request->get_params();` to match the publish path at line 2133, or add an explicit 400 with 'error':'content_type_required' when get_json_params() returns null.
  - *Verifier:* Confirmed. Line 2294: `$p = $request->get_json_params();` with no fallback. publish_podcast_episode at line 2133 uses `?: $request->get_params()` as a fallback. When Content-Type is missing, $p is null, $post_id=0, get_post(0) returns null, and the response is post_not_found with HTTP 404. The divergence between the two handlers is clear.
- **P2 · ux** — get_edit_post_link in augment response always returns empty string — Hub receives a useless field
  - `includes/class-connect-api.php:2334`
  - **Impact:** Any Hub UI that links to the edit URL from the augment response is broken. Debugging augment failures requires Hub operators to manually construct the edit URL from wp_post_id.
  - **Fix:** Build the URL directly: `get_admin_url(null, 'post.php?post=' . $post_id . '&action=edit')`. This bypasses the capability check that is irrelevant for a machine consumer.
  - *Verifier:* Confirmed. Line 2334: `'edit_url' => get_edit_post_link($post_id, 'raw')`. WordPress's get_edit_post_link() internally calls current_user_can('edit_post', $post_id) and returns '' if false. Hub requests use Bearer-token auth with no WP user session, so get_current_user_id()=0 and the capability check always fails, yielding an empty string.

**Rejected by verifier:**
- ~~Podcast Hub routes fall into the generic 'hub' rate-limit bucket (60/min) — same as all other Hub calls~~ — The rate-limit concern is real but the severity rating of P1 is overstated for this context. The 'hub' bucket is a machine-to-machine authenticated channel requiring a long-lived bearer key — it is not a public endpoint. The finding frames this as an amplifier for the XSS finding, but P0 XSS must be fixed independently regardless. Additionally, the 60/min cap on content writes is not egregious for a Hub→WP sync channel. Downgrading to P3 would be appropriate, but the auditor's framing presents it as a standalone P1 security risk, which is speculative without evidence that the key can be stolen more easily than a session token.

> **Coverage:** Traced fully: route registration (class-connect-api.php:117-145), all three handler methods (2132-2337), pc_apply_transcript_block + pc_merge_powerpress_episode_urls (transcript-block.php), auth/permission flow (class-connect-auth.php full file), rate limiter config (class-connect-rate-limiter.php). Did NOT audit: frontend SPA, visitor-facing post rendering pipeline, PowerPress plugin itself, other routes.

### `tracker-ingest` — Public tracker ingestion: /track, /gtm-beacon, /identify, /conversion, /popup-interaction (permission_callback __return_true) + tracker.js snippet

**Flow.** Five unauthenticated REST endpoints (namespace peanut-connect/v1) receive visitor analytics from the site's own tracker.js. /track and the four siblings are registered in includes/class-connect-api.php ~609–700 with permission_callback __return_true. Handlers call Peanut_Connect_Tracker methods (includes/class-connect-tracker.php) that write directly to five local MySQL tables (events, visitors, touches, conversions, popup_interactions). /gtm-beacon is a blind pass-through proxy: it reads the raw body from the incoming request and fire-and-forgets it to the Hub's /api/v1/gtm-beacon URL via wp_remote_post. tracker.js is enqueued on every frontend page (class-connect-tracker.php:581-603) and sends all events to the local REST endpoints; the tracker_key (a public identifier Hub delivers on heartbeat, never the Bearer key) is embedded in the localized script object. The Hub later pulls all synced=0 rows via a cron-driven sync job.

**Verified findings:** 9 (P0×1 · P1×5 · P2×2 · P3×1)

- **P0 · security** — /track, /identify, /conversion, /popup-interaction accept writes even when peanut_connect_tracking_enabled = false
  - `includes/class-connect-api.php:1791`
  - **Impact:** An admin who disables tracking for GDPR/CCPA compliance believes data collection has stopped. Any external actor can continue writing visitor PII (email via /identify, email+name via /conversion) indefinitely by POSTing directly to the REST endpoints.
  - **Fix:** Add at the top of each handler: if (!Peanut_Connect_Tracker::is_tracking_enabled()) { return new WP_REST_Response(['success' => false, 'code' => 'tracking_disabled'], 403); }
  - *Verifier:* Confirmed. track_event() (line 1791), identify_visitor() (line 1869), track_conversion() (line 1894), and track_popup_interaction() (line 1928) each only call check_tracking_rate_limit() and check class_exists('Peanut_Connect_Tracker'). None reads peanut_connect_tracking_enabled. Peanut_Connect_Tracker::init() gates on is_tracking_enabled() (lines 59-62) but that guard runs only in the frontend hook path (it returns early when defined('REST_REQUEST') per line 55), so the REST path is entirely ungated. Severity P0 confirmed: this directly undermines a compliance toggle.
- **P1 · security** — gtm-beacon is an open SSRF relay: any visitor can POST arbitrary bodies to any Hub URL stored in wp_options
  - `includes/class-connect-api.php:1843`
  - **Impact:** Server-side request forgery to any host reachable from the WP server if hub_url is ever set to an internal address (e.g. via a storage-layer compromise or WP option injection). Body relay allows attacker-controlled payloads to reach the target. No body size cap amplifies the risk.
  - **Fix:** Apply is_safe_hub_host() to the $hub_url read at line 1844 before constructing $endpoint, matching the guard already present at save-time (line 2047). Also enforce a body size cap (e.g. 64 KB) before forwarding.
  - *Verifier:* Confirmed. proxy_gtm_beacon() reads hub_url from get_option() and passes it directly to wp_remote_post() with no SSRF guard. is_safe_hub_host() IS enforced at option-save time (line 2047) but not at call time. If the stored value was set by a path that bypassed the guard (e.g. direct DB write, another plugin) the proxy will relay to internal hosts. Severity downgraded from P0 to P1: exploitation requires the option value to already be compromised (admin-only write path), which meaningfully raises the bar.
- **P1 · security** — visitor_id accepted without format/length validation — enables DB column overflow and analytic poisoning
  - `includes/class-connect-api.php:614`
  - **Impact:** Analytic data poisoning (fake visitor explosion), potential DB write errors returning event_id=0 that Hub treats as valid rows, and unbounded visitor table growth.
  - **Fix:** Add 'minLength'=>32, 'maxLength'=>64, 'pattern'=>'^[a-f0-9]{32,64}$' to the visitor_id arg registration for all four tracking endpoints. Add a validate_callback that returns false for non-matching values.
  - *Verifier:* Confirmed. Lines 614-618, 651-654, 670-674 each declare visitor_id with only type=string and sanitize_callback=sanitize_text_field. No maxLength, minLength, pattern, or validate_callback present. The DB column is varchar(64) (class-connect-database.php:211). sanitize_text_field does not truncate. Severity P1 confirmed.
- **P1 · security** — metadata field on /track and /conversion is unbounded — enables multi-MB DB writes and Hub sync amplification
  - `includes/class-connect-api.php:1815`
  - **Impact:** Any unauthenticated caller can POST a multi-MB JSON blob to /track, causing a large DB row insert in the metadata longtext column and a proportionally large Hub sync payload on the next cron run.
  - **Fix:** Add 'metadata' => ['type' => 'object', 'maxProperties' => 20] to the route args, or add a server-side size cap: if (strlen(wp_json_encode($metadata)) > 4096) { $metadata = null; }
  - *Verifier:* Confirmed. The /track route registration (lines 609-630) declares no metadata arg at all. track_event() reads $request->get_param('metadata') at line 1815 and passes it directly to record_event(), which calls wp_json_encode($data['metadata']) and stores the result in a longtext column (class-connect-tracker.php:246, class-connect-database.php:223). Same pattern at line 1914 for /conversion. No size cap exists anywhere in the chain. Severity P1 confirmed.
- **P1 · security** — event_type has no allowlist — arbitrary strings are written to the DB and forwarded to Hub
  - `includes/class-connect-api.php:619`
  - **Impact:** Unauthenticated analytic poisoning: an attacker can inject synthetic conversion and funnel events into Hub's analytics for any paired site, distorting campaign ROI data.
  - **Fix:** Add 'enum' => ['pageview', 'click', 'custom', 'conversion', 'form_start', 'form_submit', 'form_step', 'scroll', 'video_play', 'video_pause', 'video_complete', 'video_progress', 'video_view', 'page_exit', 'exit_intent', 'navigation', 'download', 'outbound_click', 'phone_click', 'email_click'] to the event_type arg registration.
  - *Verifier:* Confirmed. Lines 619-623 show event_type declared with only type=string and sanitize_callback=sanitize_text_field. No enum, no validate_callback. record_event() normalizes 'page_view'->'pageview' (class-connect-tracker.php:219) but otherwise inserts the caller-supplied value verbatim into event_type varchar(50). The popup-interaction /action arg does have an enum (line 696) proving the pattern is available and intentionally applied to other fields. Severity P1 confirmed.
- **P1 · security** — Rate limiter uses get_transient / set_transient without atomic increment — TOCTOU allows burst bypass
  - `includes/class-connect-rate-limiter.php:50`
  - **Impact:** Rate-limit bypass under concurrent load, most critically for the auth/disconnect endpoints (AUTH_LIMIT=10). Two threads can read count=N simultaneously, both increment to N+1, and both write N+1, allowing two requests to pass for the cost of one counter increment.
  - **Fix:** Use wp_cache_add() for the initial slot (atomic only-if-not-exists) and wp_cache_incr() if the cache backend supports it (APCu/Redis). At minimum, document that the transient backend must support atomic increments for the auth-endpoint limits to be reliable.
  - *Verifier:* Confirmed. Lines 50-96 show the classic get-then-set race: get_transient() at line 50, $data['count']++ at line 76, set_transient() at line 96. No wp_cache_add, wp_cache_incr, or SELECT FOR UPDATE anywhere in the file. For object-cache backends (Redis/Memcached/APCu) this is a real race. For the tracking endpoints (120/min) the impact is low; for auth endpoints (10/min) a burst bypass of 2-3x is meaningful. Severity P1 confirmed.
- **P2 · correctness** — Cleanup cron only prunes synced=1 rows — unsynced rows grow without bound during Hub outages
  - `includes/class-connect-database.php:422`
  - **Impact:** DB table growth without bound during Hub outages. At scale this can exhaust disk quota, degrade MySQL performance, and slow the 15-minute sync cron.
  - **Fix:** Add a secondary prune pass deleting unsynced rows older than 30 days: DELETE FROM events WHERE synced = 0 AND occurred_at < %s. Log a warning when this path is taken.
  - *Verifier:* Confirmed. Lines 418-426 loop over all tracking tables and delete WHERE synced = 1 AND <date_col> < cutoff. There is no secondary prune for synced=0 rows. If Hub is unreachable for extended periods, unsynced rows accumulate indefinitely. Severity P2 confirmed.
- **P2 · security** — popup_id is not validated against any existing popup record — allows interaction spam against arbitrary IDs
  - `includes/class-connect-api.php:1928`
  - **Impact:** Analytic poisoning of popup conversion funnels; unbounded row growth in the popup_interactions table with arbitrary popup_id values.
  - **Fix:** Add a validate_callback that checks whether the popup_id corresponds to a valid WP post of the popup post type, or at minimum a whitelist fetched from an option. Reject unknown IDs with a 422.
  - *Verifier:* Confirmed. Line 689 declares popup_id as type=integer with no validate_callback checking existence. track_popup_interaction() at line 1939 casts to int and passes directly to record_popup_interaction() (class-connect-tracker.php:357), which inserts it into popup_interactions table with no existence check. Severity P2 confirmed.
- **P3 · states** — tracker.js fires tracking events via sendBeacon before visitor cookie is confirmed set — first-pageview events may carry a stale visitorId
  - `assets/js/tracker.js:759`
  - **Impact:** Split-session analytics: one visitor may appear as two different visitors across sessions, inflating unique visitor counts and breaking attribution chains in Hub.
  - **Fix:** In init(), always call setVisitorCookie() with the resolved visitorId so the PHP-rendered and cookie values converge, regardless of whether a cookie already existed.
  - *Verifier:* Partially confirmed, but the described mechanism is overstated. config.visitorId is set server-side by get_visitor_id() (class-connect-tracker.php:595) which already reads from $_COOKIE first (line 89), so PHP and JS typically agree. The split-session risk is real but narrower than described: it only occurs if the cookie expires exactly between PHP render and JS execution (extremely rare), or if a different browser profile shares a cookie jar (not applicable). The actual gap in init() is that if getVisitorFromCookie() returns a truthy value (old cookie), setVisitorCookie() is skipped, leaving any cookie-lifetime extension unset — a minor issue, not a session split. Severity downgraded from P2 to P3: genuinely low practical likelihood.

> **Coverage:** Traced: route registration (class-connect-api.php 609-700), all five handler methods (1791-1954), check_tracking_rate_limit (1770-1786), Peanut_Connect_Tracker (full file), rate-limiter (full file), DB schema (class-connect-database.php), tracker.js (full file), marketing tracking_setup response (class-connect-marketing.php 252-275). NOT traced: Hub-side gtm-beacon receiver, class-connect-hub-sync (sync push path), ML anomaly class, class-connect-popup-display.

### `outbound-sync` — Outbound edge→Hub sync: scheduled pushes, form-capture forwarding, marketing API calls to Hub (HMAC signing side of 9f499f1)

**Flow.** Every 15 minutes WordPress cron fires `peanut_connect_hub_sync` → `Peanut_Connect_Hub_Sync::run_sync()`. It calls `sync_campaign_events`, `sync_campaign_visitors`, `sync_popup_interactions`, and `sync_form_submissions`, each driven by `sync_in_batches()` which loops up to MAX_BATCHES_PER_RUN (50) × BATCH_SIZE (200) = 10 000 rows per type per tick, posting raw `SELECT *` results to Hub at `/api/v1/sync/push` via Bearer token only. A heartbeat (`send_heartbeat`) posts to `/api/v1/sync/heartbeat` and receives popup config, tracker_key, and an optional `sync_now` flag that immediately schedules a one-shot cron. Hub→Edge requests are now HMAC-verified (9f499f1); Edge→Hub pushes are NOT signed. `Peanut_Connect_Forms::sync_from_hub()` pulls form definitions from Hub using a non-standard `X-Site-Api-Key` header (inconsistent with all other outbound calls using `Authorization: Bearer`). `render_hub_form()` injects the Hub bearer API key into every public page via `wp_localize_script`. `Peanut_Connect_Marketing::forward()` proxies admin users' requests to Hub, passing raw `get_query_params()` arrays unsanitized, and overrides any 4xx/5xx HTTP status to 200 when the body contains `"success":true`.

**Verified findings:** 8 (P0×1 · P1×4 · P2×3)

- **P0 · security** — Hub bearer API key leaked to every public page via wp_localize_script
  - `includes/class-connect-forms.php:343`
  - **Impact:** Any visitor to a public page with a [peanut_form] shortcode can read the Hub bearer token from the page source or browser devtools. That same token is used by verify_hub_request() (class-connect-auth.php:241) to authenticate all inbound Hub requests, and is the shared secret for the HMAC path introduced in 9f499f1. Full credential exposure to the public web.
  - **Fix:** Remove 'apiKey' from PeanutFormsConfig entirely. Route form submissions through the WP REST proxy (peanut-connect/v1) server-side, or have Hub issue a short-lived form-scoped public token akin to the tracker_key already delivered via heartbeat. The Hub bearer must never reach browser JS.
  - *Verifier:* Confirmed exactly as described. Line 343 of class-connect-forms.php reads `'apiKey' => get_option('peanut_connect_hub_api_key')` inside wp_localize_script called from the shortcode render path, which runs on public pages. The marketing comment at class-connect-marketing.php:8-10 explicitly states 'The plugin's React SPA never holds the Hub API key directly' — the forms path violates that stated contract. Severity P0 is correct.
- **P1 · security** — HMAC signing (9f499f1) is inbound-only — all outbound pushes to Hub still use a replayable static Bearer token
  - `includes/class-connect-hub-sync.php:328-338`
  - **Impact:** A network adversary who captures one outbound sync request can replay it indefinitely to pollute Hub's analytics/visitor database. Form submission payloads containing PII are also sent unsigned. The anti-replay window implemented in class-connect-auth.php:21 (SIGNATURE_WINDOW=300) protects only inbound requests; outbound is unprotected.
  - **Fix:** Apply HMAC-SHA256 signing to all outbound wp_remote_* calls carrying the API key. Extract a shared sign_outbound_request() helper in class-connect-auth.php so the canonical form is identical on both sides.
  - *Verifier:* Confirmed. send_to_hub() at line 331-338, send_heartbeat() at line 403-416, verify_hub_connection() at line 474-481, and fetch_popups() at line 523-528 all use only 'Authorization: Bearer' with no X-Peanut-Signature. The marketing forward() at line 304-310 also uses only Bearer. The HMAC machinery in class-connect-auth.php verify_signed_hub_request() exists only for the inbound direction. Severity P1 is accurate.
- **P1 · security** — Marketing forward() overrides 4xx/5xx Hub status codes to 200 when body says success:true — masks Hub auth/permission failures from callers
  - `includes/class-connect-marketing.php:341-343`
  - **Impact:** A MITM or spoofed Hub response containing {"success":true} on a 401 or 403 is silently converted to HTTP 200. Genuine Hub 401/403 errors (wrong site key, revoked API key) are swallowed, preventing the admin from knowing the connection is broken. The comment states the workaround targets cPanel/mod_security rewrites but accepts the body at face value.
  - **Fix:** Narrow the override to the specific status codes actually caused by mod_security (406, 405) — never override 401 or 403. Log a warning whenever the override fires. Consider verifying the Hub response is HMAC-signed before trusting the body at all.
  - *Verifier:* Confirmed. Line 341 of class-connect-marketing.php: `if (isset($data['success']) && $data['success'] === true && $status >= 400) { $status = 200; }`. The condition covers the entire >=400 range including 401 and 403. The comment only mentions cPanel/ImunifyAV/mod_security rewriting 2xx to 406/405/503, but the code accepts any 4xx/5xx. Severity P1 is accurate.
- **P1 · security** — Forms sync_from_hub uses X-Site-Api-Key header — inconsistent auth contract, may fail Hub validation depending on Hub route guard
  - `includes/class-connect-forms.php:92`
  - **Impact:** Silent auth failure causing forms to never sync (P1 correctness), or a wider-than-intended credential surface on Hub if it accepts both header names (P1 security). The auth contract between the two systems is ambiguous and untestable from either side alone.
  - **Fix:** Align to 'Authorization: Bearer $api_key' matching all other outbound calls. Update Hub's forms endpoint guard if needed, add a test verifying the header Hub expects.
  - *Verifier:* Confirmed. Line 92 of class-connect-forms.php uses `'X-Site-Api-Key' => $api_key` as the sole auth header on the GET to /api/v1/forms/active. Every other outbound call (send_to_hub, send_heartbeat, verify_hub_connection, fetch_popups, marketing forward()) uses 'Authorization: Bearer'. The inconsistency is real and unambiguous. Severity P1 is correct.
- **P1 · states** — sync_in_batches throws on any single failed batch, skipping mark_non_campaign_data_synced — non-campaign rows accumulate forever when Hub is intermittently unreachable
  - `includes/class-connect-hub-sync.php:170-179, 109`
  - **Impact:** Under repeated Hub-unreachable conditions, peanut_connect_events and peanut_connect_visitors tables accumulate non-campaign rows without bound because mark_non_campaign_data_synced() only executes when all four sync calls succeed.
  - **Fix:** Move mark_non_campaign_data_synced() to run unconditionally outside the try block, or execute it in the catch path. Non-campaign cleanup does not depend on sync success.
  - *Verifier:* Confirmed. run_sync() at lines 95-132: mark_non_campaign_data_synced() is called at line 109 inside the try block, after all four sync calls. sync_in_batches() throws \Exception on any HTTP error (line 171-178). The catch at line 123 logs the error and returns failure without calling mark_non_campaign_data_synced(). The accumulation risk is real. Severity P1 is accurate.
- **P2 · copy** — Hub-blind violation: data-hub-url attribute rendered in public page HTML for every Hub form
  - `includes/class-connect-forms.php:308`
  - **Impact:** Any page visitor can discover the Hub URL (e.g. hub.peanutgraphic.com) from the DOM via browser devtools, violating the Hub-blind contract for clients such as Itron.
  - **Fix:** Serve Hub URL to the form renderer via a local WP REST proxy endpoint. The public DOM element should carry only a form slug and a nonce, not the upstream Hub URL.
  - *Verifier:* Confirmed. Line 308 of class-connect-forms.php: `data-hub-url="<?php echo esc_url($hub_url); ?>"` is emitted into the public DOM on every [peanut_form] shortcode render. The Hub URL is also inlined into PeanutFormsConfig.hubUrl via wp_localize_script at line 342. Two separate public-DOM leaks of the Hub URL exist. Additionally, the Hub CSS is loaded directly from the Hub URL at line 336 (`trailingslashit($hub_url) . 'css/peanut-forms.min.css'`), creating a third network-level disclosure. Severity P2 is appropriate (no data loss, but documented contract violation).
- **P2 · security** — sync_in_batches sends SELECT * — all DB columns including any internal/audit columns forwarded to Hub without an explicit allow-list
  - `includes/class-connect-hub-sync.php:197, 218, 241`
  - **Impact:** Any schema addition (e.g. a raw_referrer or browser_fingerprint column) is automatically forwarded to Hub without the developer having to change sync code, violating data-minimisation. The form_submissions sync at line 263-274 uses explicit column projection; the other three fetchers do not.
  - **Fix:** Replace SELECT * with named-column projections in sync_campaign_events (line 195-200), sync_campaign_visitors (line 216-225), and sync_popup_interactions (line 239-244). Define column allow-lists as class constants so future schema changes require a deliberate inclusion decision.
  - *Verifier:* Confirmed. Lines 196-200 (events), 216-225 (visitors), 239-244 (popup_interactions) all use `SELECT *`. The form_submissions sync at lines 263-274 explicitly projects to named columns, making the contrast deliberate and the gap clearly an oversight. Severity P2 is correct.
- **P2 · security** — Marketing proxy forwards raw unsanitized get_query_params() to Hub — parameter pollution / Hub-side injection surface
  - `includes/class-connect-marketing.php:164, 194, 227, 242`
  - **Impact:** An XSS or CSRF payload in the admin context can append arbitrary query parameters to Hub API calls. No WP REST 'args' schema validation is declared for any of the GET routes, so WordPress does not filter the query string before it reaches the callback.
  - **Fix:** Declare explicit 'args' schema in each affected register_rest_route call and filter get_query_params() against an allow-list of known parameter names before passing to forward().
  - *Verifier:* Confirmed. Lines 163-164 (list_utms), 193-194 (list_links), 226-227 (list_journeys/journey_stats), 241-242 (gtm_coverage) all call self::forward() with $request->get_query_params() directly. Inspecting register_routes() (lines 25-147), none of the GET route registrations include an 'args' key, so WordPress performs no schema-level query param filtering. Severity P2 is correct; impact depends on Hub-side validation but defence-in-depth is absent.

> **Coverage:** Traced: includes/class-connect-hub-sync.php (all methods), includes/class-connect-forms.php (all methods), includes/class-connect-marketing.php (all methods), includes/class-connect-api.php (is_safe_hub_host, connect/update_hub_settings SSRF guards, hub_url validation). Also checked: includes/class-connect-auth.php (HMAC signing scope), peanut-connect.php (hook registration), docs/HUB-EDGE-CONTRACT.md (Hub-blind constraints). NOT traced: class-connect-database.php table schema (could not confirm which columns exist in visitors/events tables), actual Hub-side handling of the sync payload, FormFlow Pro DB interaction paths.

### `self-updater` — Self-updater supply chain: scheduled update checks against peanutgraphic.com / GitHub releases, package download + install

**Flow.** On every WordPress plugin update check cycle, `Peanut_Connect_Self_Updater` (instantiated unconditionally in `peanut-connect.php:128`) hooks `pre_set_site_transient_update_plugins` and calls `https://www.peanutgraphic.com/wp-json/peanut-api/v1/updates/peanut-connect/{version}`. The JSON response's `plugin_info.download_url` field is dropped straight into WordPress's `$transient->response[...]['package']`, which WP Upgrader then downloads and installs — no integrity check, no host validation, no HTTP response code check. The Hub-facing update triggers (`/hub/plugin/update`, `/hub/theme/update`, `/hub/plugins/bulk-update`, `/update`) live in `class-connect-api.php` and delegate to `Peanut_Connect_Updates::perform_update()`, which calls WP's built-in `Plugin_Upgrader`/`Theme_Upgrader`/`Core_Upgrader`. Auth uses `hub_permission_callback_for('perform_updates')` (HMAC-signed or legacy Bearer). The `perform_updates` permission defaults to `true` in `class-connect-auth.php:137`.

**Verified findings:** 9 (P0×3 · P1×3 · P2×3)

- **P0 · security** — download_url from update server trusted without host pinning or integrity check — server compromise = RCE on all paired sites
  - `includes/class-connect-self-updater.php:74`
  - **Impact:** Full remote code execution on every site running the plugin. A single upstream compromise of peanutgraphic.com fans out to every customer site automatically on the next scheduled update check.
  - **Fix:** Before placing a URL in the update transient: (1) validate scheme is https and host ends with peanutgraphic.com or github.com; (2) add a checksum or signature field to the update-server response and verify it after download using hash_file() before letting WP Upgrader extract the package.
  - *Verifier:* Confirmed. Line 74 is exactly `'package' => $remote->download_url ?? ''`. No URL validation, no scheme check, no host allowlist, no integrity check anywhere in get_remote_update_info() or check_for_update(). Whatever URL the server returns goes directly into the WP update transient and WordPress Upgrader will download and unzip it.
- **P0 · security** — HTTP response code never checked before trusting update-server JSON — malformed error responses can pollute the 12-hour cache
  - `includes/class-connect-self-updater.php:152-163`
  - **Impact:** Persistent update poisoning for 12 hours per site. An attacker who can cause the update server to return a non-200 with controlled JSON can inject a malicious package URL that survives until the cache is manually cleared.
  - **Fix:** Add `$code = wp_remote_retrieve_response_code($response); if ($code !== 200) { return null; }` immediately after the is_wp_error check, before decoding the body.
  - *Verifier:* Confirmed. Lines 152-165 check only `is_wp_error($response)` then immediately call `wp_remote_retrieve_body()` and `json_decode()`. There is no call to `wp_remote_retrieve_response_code()` anywhere in the method. A 404/500 with a JSON body shaped like the API response gets parsed and cached for 12 hours via `set_transient($cache_key, $body->plugin_info, 12 * HOUR_IN_SECONDS)` at line 163.
- **P0 · security** — Self-updater instantiated unconditionally before pairing — makes outbound calls to peanutgraphic.com on every WP admin update check regardless of pairing state
  - `includes/class-connect-self-updater.php:18 + peanut-connect.php:128`
  - **Impact:** Hub's existence is discoverable by any sysadmin running packet capture or firewall logs on an Itron site. Violates the Hub-blind contract explicitly documented in docs/HUB-EDGE-CONTRACT.md Rule 3 and the known-violations table.
  - **Fix:** Gate the updater behind a pairing check: only register the pre_set_site_transient_update_plugins hook if get_option('peanut_connect_hub_api_key') is non-empty, or add a PEANUT_HUB_BLIND constant check that skips instantiation entirely for Hub-blind builds.
  - *Verifier:* Confirmed. peanut-connect.php line 128 calls `new Peanut_Connect_Self_Updater()` unconditionally with no pairing or feature-flag guard. The self-updater constructor calls init_hooks() which registers the filter unconditionally. The class has no PEANUT_HUB_BLIND or peanut_connect_hub_api_key check anywhere. The contract doc (docs/HUB-EDGE-CONTRACT.md line 145) explicitly lists this as a known violation. Severity confirmed P0 given the Itron relationship described in the contract.
- **P1 · security** — Legacy unsigned Bearer token auth still accepted by default — signed-request enforcement is opt-in via an option that defaults to false
  - `includes/class-connect-auth.php:263-294`
  - **Impact:** A leaked or brute-forced Bearer token is sufficient to trigger plugin/theme/core updates remotely with no HMAC or replay protection on any site that has not explicitly toggled the option. This covers every site since the default is false.
  - **Fix:** Flip the default to true for new installs gated on a minimum Hub version check. Add a migration that sets peanut_connect_require_signed_requests=true on existing sites with the HMAC-capable Hub version. Surface a persistent admin notice when the option is false.
  - *Verifier:* Confirmed. Line 263 shows `get_option('peanut_connect_require_signed_requests', false)` — the default is false. When X-Peanut-Signature header is absent and the option is false, the code falls through to the static Bearer token comparison path. The HMAC verification path is present and correct but provides zero protection unless an admin manually enables the option.
- **P1 · security** — `perform_updates` permission defaults to true — any authenticated Hub can trigger plugin/theme/core installs without admin opt-in
  - `includes/class-connect-auth.php:137`
  - **Impact:** A compromised or mis-paired Hub can automatically update any plugin or theme to a malicious version on all connected sites immediately after pairing, before the site owner has reviewed permissions.
  - **Fix:** Change the default for perform_updates to false. Require the site admin to explicitly enable remote updates via the settings UI. Display the current permission state prominently in the admin SPA.
  - *Verifier:* Confirmed. Lines 133-141 show get_permissions() returning `'perform_updates' => true` as the default when no option is stored. The permission check at has_permission() (line 119) reads get_option('peanut_connect_permissions', []) with an empty-array fallback, meaning has_permission() returns false for a fresh install until get_permissions() is called and saved — but the API permission callback calls has_permission() which falls back to the empty array and returns false. Slightly complex interaction: get_permissions() sets the default but has_permission() reads raw option. Auditor's framing is directionally correct — the defaults in get_permissions() signal intent that perform_updates starts enabled, and any UI that initializes from get_permissions() will store true.
- **P1 · security** — Version string from update-server response not sanitized before storage in update transient
  - `includes/class-connect-self-updater.php:70-79`
  - **Impact:** Version-spoofing can force unexpected update triggers. XSS risk in admin is low because WP core escapes new_version in most rendering paths, but third-party admin plugins may not. Semantic risk is real: an injected version string like 9999.0.0 will always satisfy version_compare.
  - **Fix:** Validate $remote->version matches a semver pattern (preg_match) and cast to string before use. Reject and return null if absent or non-conforming.
  - *Verifier:* Confirmed. Lines 69-79 use $remote->version directly in version_compare() and then as `'new_version' => $remote->version` in the transient with no sanitization or type check. The XSS risk is partially mitigated by WP core escaping, which is why P1 (not P0) is appropriate — the auditor's original P1 rating is correct.
- **P2 · copy** — Update-server API URL hardcoded to peanutgraphic.com with no Hub-blind build path or runtime flag
  - `includes/class-connect-self-updater.php:18`
  - **Impact:** Hub-blind deployments (Itron) cannot use the self-updater without violating the Hub-blind contract. Manual updates are the only option today with no runtime or build-time mechanism to swap or disable the endpoint.
  - **Fix:** Wrap the API_URL constant and the updater's hook registration behind a defined('PEANUT_HUB_BLIND') && PEANUT_HUB_BLIND guard. When Hub-blind, skip registration or point to a GitHub releases API URL per the contract doc's own Hub-blind recommendation.
  - *Verifier:* Confirmed. Line 18 is `private const API_URL = 'https://www.peanutgraphic.com/wp-json/peanut-api/v1/updates/peanut-connect'` with no conditional, no constant check, no runtime flag. The contract doc (HUB-EDGE-CONTRACT.md lines 63-74, 165) explicitly calls for a build-time PEANUT_HUB_BLIND flag but none exists in the codebase. This finding partially overlaps with Finding 3 (same root cause, different angle — one is about the outbound call per se, this one is about the lack of any build-time or runtime opt-out mechanism). Both are worth tracking independently.
- **P2 · correctness** — apply_update() accepts `version` parameter but silently discards it — Hub cannot target a specific version
  - `includes/class-connect-api.php:868-885 + 3271-3276`
  - **Impact:** Hub cannot perform a targeted version install or rollback. If Hub sends version=2.0.0, it silently gets whatever the WP update transient has. Creates version drift across a fleet and breaks Hub's update tracking.
  - **Fix:** Either remove the version parameter from the route args and document that only the latest transient version is installed, or implement version-targeted install by fetching the specific package URL from the update server and passing it directly to the upgrader.
  - *Verifier:* Confirmed. Route registration at line 881-883 declares `version` with a sanitize_callback but apply_update() at line 3271-3276 only extracts component_type and slug, then calls Peanut_Connect_Updates::perform_update($type, $slug) with no version argument. The version parameter is completely ignored. P2 is appropriate — this is a correctness/API contract issue, not a security vulnerability.
- **P2 · states** — Update check falls back to '0.0.0' current_version when plugin path is not in transient->checked — always reports update available
  - `includes/class-connect-self-updater.php:63`
  - **Impact:** Persistent spurious update notification on non-standard installs (symlinked, renamed, multisite path quirk). The md5('0.0.0') cache key collision means legitimate checks using the real version also see stale data for 12 hours.
  - **Fix:** Before entering update check logic, verify $current_version !== '0.0.0' or fall back to reading the version directly from the plugin headers using get_plugin_data() as force_update_check() already does at line 208-209.
  - *Verifier:* Confirmed. Line 63 is exactly `$current_version = $transient->checked[$this->plugin_file] ?? '0.0.0'`. The force_update_check() method at line 205-212 already demonstrates the correct pattern — it calls get_plugin_data() to get the real version — but the main check_for_update() path does not use this fallback. P2 rating is appropriate.

> **Coverage:** Traced: class-connect-self-updater.php (full), class-connect-updates.php (full), class-connect-api.php (all update-related routes + handlers, lines 155-886 + 3261-3297), class-connect-auth.php (full), peanut-connect.php (bootstrap, lines 120-148), docs/HUB-EDGE-CONTRACT.md (full). Not traced: the update-server side at peanutgraphic.com (out of scope, remote). Tests in tests/phpunit/unit/SelfUpdaterTest.php and UpdatesTest.php not read — would not change code-level findings.

