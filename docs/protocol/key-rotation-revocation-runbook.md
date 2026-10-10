# Key Rotation & Revocation — Operational Runbook & SLA (D-12)

> Cross-repo operational reference for the Hub↔Edge site-key lifecycle: scheduled
> rotation, on-demand rotation, and revocation of a compromised or offboarded
> site. The **mechanism** is implemented and live in both repos; this document is
> the operator-facing SLA and runbook (the documentation half of D-12 / #55).
>
> - **Edge** = the `peanut-connect` plugin (≥ 3.14.0) on a WordPress site.
> - **Hub** = `peanut-hub` (the monitoring backend).

## The site key in one paragraph

Every connected site authenticates to the Hub with a **site API key**: the edge
sends it as a `Bearer` token and also HMAC-signs each request with it (D-10/D-11).
The Hub stores only a **SHA-256 hash** of the active key (`sites.api_key_hash`),
plus an encrypted copy of the key itself; the edge stores the key **encrypted at
rest** (A5, `enc:v1:…`). The key can be **rotated** (replaced with a new one) or
**revoked** (invalidated entirely). Rotation never requires re-pairing; revocation
forces a re-pair.

---

## 1. Scheduled rotation (the default, since Phase 3)

Every site carries `sites.key_rotation_interval_days` (default **90**, set by
`config('peanut-connect.key_rotation_interval_days')` at pairing, or by
`php artisan peanut:enable-key-rotation` for the existing fleet).

- On each heartbeat the Hub computes `Site::isKeyRotationDue()` —
  `true` when `(key_rotated_at ?? created_at) + interval` is in the past — and
  returns `"rotate": true` in the heartbeat response.
- The edge then performs the **two-phase confirmed swap** (see §4). On success the
  Hub stamps `key_rotated_at = now()`, so the clock restarts and the site is due
  again one interval later.

**Cadence SLA:** 90 days per site by default. The first rotation after enablement
lands one full interval out (the *anti-storm* baseline stamps `key_rotated_at =
now()` when it was null), never immediately.

**Tuning / opt-out (per site):**
- Change interval: `php artisan peanut:enable-key-rotation --site=<id> --days=<n>`.
- Opt a site out: set `key_rotation_interval_days` to `null` or `0` — the
  due-check treats empty as "off." (`--days=0` does this.)
- Fleet-wide: `php artisan peanut:enable-key-rotation --all` (idempotent; use
  `--dry-run` to preview).

## 2. On-demand rotation

Use when you want to cycle a key now without waiting for the interval (e.g. a key
may have been exposed in logs but the site is not believed compromised).

- **From the Hub:** the admin **"Rotate key"** action
  (`SiteController::rotateKey`) sets `rotate_key_requested = true`; the next
  heartbeat signals the edge, which runs the two-phase swap.
- **From the edge:** the site's **Settings → "Rotate key"** button triggers the
  same swap directly.

Either path is lossless and lockout-safe (§4).

## 3. Revocation — compromised or offboarded site

Use when a key (or the site) must lose access **immediately**: confirmed
compromise, offboarding, or a decommissioned install.

- **Action:** the Hub admin **"Revoke"** action (`SiteController::revokeKey` →
  `Site::revokeKey()`).
- **Effect (Hub):** clears `api_key_hash` and any pending key, sets the site
  `status = 'disconnected'`. The hash is what authenticates, so this is
  **immediate** — the site's very next request fails authentication.
- **Effect (edge):** after **two consecutive `401`s** from the Hub, the edge
  clears its locally stored key and surfaces the **re-pair admin notice**
  (the site enters a needs-repair state, never a fatal error).

**Revocation SLA:**
- **Effective immediately on the Hub** — access is denied from the next request.
- **Edge self-heals to needs-repair within ~2 heartbeat cycles** (the 2×401
  threshold). This is firmware-independent for the *security* outcome: revocation
  denies access to **any** edge version (old edges simply keep receiving 401s,
  i.e. stay locked out, which is the intent); only the friendly auto-clear +
  re-pair notice requires edge ≥ 3.14.0.
- **Recovery:** re-pair the site (generate a new key and reconnect) via the edge's
  re-pair notice / Settings. There is no "un-revoke" — a revoked key is gone by
  design. With Hub ≥ peanut-hub#1756, re-pairing needs a one-time **pairing
  token**: in Hub, open the site and click **Pair site** (30-minute expiry, single
  use), then paste it into Settings → Hub Connection → Auto-connect → "Pairing
  token from Hub". Without it Hub answers `PAIRING_TOKEN_REQUIRED` (no active
  key) or `ALREADY_CONNECTED` (a different active key); a wrong, expired, used or
  other-site token is `PAIRING_TOKEN_INVALID`. The edge never stores or logs the
  token. Edges older than the pairing-token release cannot send one, so they can
  only re-pair via "Use existing API key" with a key from Hub's **Regenerate API
  key**.

---

## 4. Why rotation can never lock a site out

The two-phase confirmed swap:

1. **Propose** — the edge generates a new key and calls
   `POST /api/v1/sites/rotate`, signed/authed with the **OLD** key. The Hub stores
   the new key's hash as **pending** (`pending_api_key_hash`,
   `pending_key_expires_at = now + 15 min`). The **active key is unchanged.**
2. **Confirm** — the edge calls `POST /api/v1/sites/rotate/confirm`, signed/authed
   with the **NEW** key. The Hub **promotes** the pending key
   (`promotePendingKey()`: `api_key_hash = pending`, clears pending, stamps
   `key_rotated_at`, status → connected). Promotion also happens implicitly the
   first time any request arrives validly signed with the pending key.

Invariants:
- The **old key stays valid until the new one is proven.** If `confirm` never
  *reaches the Hub*, nothing changed — the site keeps working on the old key.
- A **pending window expires after 15 minutes** and is garbage-collected
  **hourly** by `sites:purge-expired-key-rotations` (scheduled in the Hub), which
  only clears the pending slot — it never touches the active key.

**The lost-reply case (edge ≥ the release after 3.40.0).** If `confirm` reaches
the Hub and the Hub promotes, but the edge never sees a 2xx (timeout, dropped
connection, 5xx after commit), the old key is already retired. Edges before this
fix discarded the new key here and locked themselves out (every request 401,
then the 2×401 rule cleared the key → re-pair). The edge now:

1. Stores the new key **encrypted, as pending** (`peanut_connect_hub_pending_key`,
   autoload off) **before** proposing. Never logged, never plaintext.
2. Retries `confirm` with the new key — 3 attempts total, 1 s then 2 s backoff,
   inside a 45 s budget.
3. Then probes `GET /api/v1/popups/active` with the **new** key, then the **old**:
   new key 2xx → adopt it; new key 401 **and** old key 2xx → keep the old key and
   drop the pending one; anything else (network error, 5xx, 429) → keep the
   pending key, show the admin notice, retry on the next heartbeat.
4. On any heartbeat `401` with the active key, tries the pending key once before
   counting a revocation strike.

The pending key is dropped only on proof it is dead, or on unpair/re-pair. A
rotation lock (`peanut_connect_key_rotation_lock`) keeps the admin button, the
heartbeat-triggered rotation and the cron retry from interleaving, and a new
rotation is refused while an earlier one is unresolved.

## 5. Edge firmware requirement

- **Rotation** (scheduled or on-demand) requires **edge ≥ 3.14.0**. Older edges
  ignore the additive `"rotate"` heartbeat field — they keep working on their
  current key and simply never rotate until the plugin is updated. No lockout, no
  error.
- **Revocation** works against **any** edge version (it's Hub-side invalidation).

## 6. Troubleshooting

| Symptom | Cause | Action |
|---|---|---|
| Site shows "re-pair" notice unexpectedly | WP salts rotated → A5 key underivable (`peanut_connect_hub_key_undecryptable`) | Re-pair the site. (Tracked hardening: #56 / D-13 may move derivation off `wp_salt`.) |
| Site never rotates despite being overdue | Edge < 3.14.0 ignores the signal | Update the plugin to ≥ 3.14.0 (verify the auto-updater is wired — uppercase install dirs broke it historically). |
| Rotation "didn't take" | `confirm` didn't reach the Hub within 15 min | Harmless — site stayed on the old key; it will be signaled again next heartbeat. |
| Admin notice: "a Hub key rotation could not be confirmed" | Hub unreachable during/after `confirm`; the new key is held as pending (`peanut_connect_key_rotation_unresolved` set) | None normally — the next heartbeat probes both keys and settles it. If it persists past a day, check Hub reachability from the site, then re-pair. |
| Need to cut access now | Compromise / offboarding | Hub **Revoke** action — immediate. |

## 7. Quick reference

| Operation | Trigger | Effect | Lockout risk |
|---|---|---|---|
| Scheduled rotate | heartbeat, when `isKeyRotationDue()` | two-phase swap | none |
| On-demand rotate | Hub "Rotate key" / edge Settings | two-phase swap | none |
| Revoke | Hub "Revoke" | immediate hash clear → 401 → re-pair | n/a (intended) |
| Re-pair | Hub "Pair site" token → edge Settings (auto-connect) | new key bound with the one-time token | — |

**Endpoints:** `POST /api/v1/sites/rotate` (propose), `POST /api/v1/sites/rotate/confirm` (confirm).
**Default cadence:** 90 days. **Pending TTL:** 15 min, GC'd hourly. **Min edge for rotation:** 3.14.0.
