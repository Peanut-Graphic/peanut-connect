# D-12 — Key rotation & revocation for the Hub ↔ Edge pairing

> **Status:** Approved design (brainstorm complete) · **Issue:** #55 · **Target:** cross-repo (peanut-connect + peanut-hub)
> **Source:** `docs/backlog/2026-06-13-hub-seam-hardening-backlog.md` (D-12)

## Problem

The Hub ↔ edge pairing rests on a single site key. Today the only remedy for a stale, leaked, or offboarded key is generate-new-key + **full re-pair** — there is no rotation handshake, no Hub-side revocation, and no way to reduce the value of a leaked key over time. A long-lived static credential is exactly the risk A5 (encrypt-at-rest), HMAC signing (D-10/D-11), and this item are meant to retire.

## Goal / success criteria

- **Rotation-first:** a site key can be rotated **without re-pairing** — proactively, on a schedule, to shrink the value of any leaked key.
- **Never lock a site out.** No rotation can leave a site in a state where neither the old nor the new key works — even with in-flight requests, retries, a crash mid-rotation, or clock skew.
- **Strong:** a rotated-away (old) key is invalidated as soon as the new key is proven working — its post-rotation lifetime is the confirmation latency (seconds), not hours.
- **Revocation (secondary):** the Hub can hard-revoke a site immediately (offboarding/incident); the edge detects it and degrades to un-paired + a re-pair prompt — never a fatal error.
- **Authenticated end-to-end:** the rotation handshake itself is signed (D-11) + anti-replay; an attacker cannot initiate or hijack a rotation.

## Non-goals (YAGNI)

- Short-lived / auto-expiring keys (the configurable rotation interval already "reduces leaked-key value").
- Fleet-wide forced-rotation campaigns (per-site policy is enough to start).
- Changing the at-rest storage model (A5 already encrypts the key; the new key is stored the same way).

## Decisions (locked during brainstorming)

| Decision | Choice | Why |
|---|---|---|
| Driver | **Rotation-first** (proactive), revocation secondary | Reduce the standing value of any leaked key, not just react to incidents. |
| Who triggers | **Either side** — Hub policy (scheduled) + on-demand button on Hub admin **and** edge Settings | Central fleet policy + operator override on either end. |
| Swap mechanism | **Two-phase confirmed swap** | Strongest *and* lockout-safe: old key valid only until the new is proven working, then dropped immediately; fail-safe to old key if anything fails. |

## Architecture — the rotation state machine

Each site is in one of:

- **ACTIVE(key)** — steady state; `key` is the only live credential.
- **PENDING(new_key, expires_at)** — a rotation is in flight. `ACTIVE` key is *still the live credential*; `new_key` is staged but not yet authoritative.
- On **confirmation** (a valid signed call using `new_key`): atomically promote `new_key` → ACTIVE, drop the old key, clear PENDING, write an audit entry.
- On **expiry** (`expires_at` passes with no confirmation): GC drops PENDING; the site stays ACTIVE on the **old** key. No lockout.

This guarantees the invariant: **the old key remains valid until — and only until — the new key is confirmed working.**

### Handshake (signed end-to-end)

1. **Trigger.** Either: Hub policy marks the site due → heartbeat response carries `rotate: true`; or an operator clicks "Rotate key" (Hub admin or edge Settings) → sets the same trigger.
2. **Propose.** Edge generates a new key, `POST /api/v1/sites/rotate` to the Hub **authenticated + signed with the OLD key** (D-11), body carries `new_key_hash`. Hub records `PENDING(new_key_hash, now+TTL)` (TTL e.g. 15 min). The proposal does not touch the active key.
3. **Adopt.** Edge stores the new key locally (A5-encrypted at rest) as its working key. (The old key is retained transiently only to complete step 4 if needed.)
4. **Confirm.** Edge makes a confirming signed call using the **NEW** key (e.g. `POST /api/v1/sites/rotate/confirm`, or simply the next signed heartbeat). On the first valid use of the pending key, the Hub **promotes** new→active, drops old, clears PENDING, and writes an audit row.
5. **Fail-safe.** If steps 3–4 never complete (edge crash, lost response), PENDING expires; the edge — which still has the old key available until it observes a successful new-key call — falls back to the old key. Worst case the rotation simply didn't happen; the site is never stranded.

### Revocation (secondary, incident/offboarding)

A hard **revoke** on the Hub drops the site's key with **no** PENDING/overlap and marks the site `disconnected`. The edge's next signed outbound call (heartbeat) gets `401` → after a confirming re-check (avoid a transient blip) the edge **clears its local key** and shows the re-pair admin notice — reusing A5's `undecryptable → re-pair` UX. An audit row records who revoked and when.

## Components / scope

### Hub (peanut-hub)

- **`Site` model:** add `pending_api_key_hash`, `pending_expires_at`, `key_rotated_at`; a rotation-interval policy field (per-site, default 90d, nullable = no auto-rotation). Reuse existing key-hash/rotate primitives (`Site.php:620–655`).
- **Endpoints (site-authed, `ValidateSiteApiKey` + signed):** `POST /api/v1/sites/rotate` (record PENDING), and promotion-on-first-valid-pending-key-use (either an explicit `/rotate/confirm` or fold into the existing auth path: if a request authenticates with `pending_api_key_hash`, promote). Prefer folding promotion into `ValidateSiteApiKey` to avoid a race.
- **Heartbeat response:** include `rotate: true` when the site is due (policy) or an operator requested it.
- **Admin actions:** "Rotate key" + "Revoke" buttons on the site detail screen; "Revoke" sets `disconnected` + clears key.
- **Audit log:** one row per rotation (proposed/confirmed/expired) and per revocation, with actor + timestamp.
- **GC:** scheduled task to clear expired `PENDING` rows.

### Edge (peanut-connect)

- **Rotation client** (likely in `class-connect-hub-sync.php` or a new `class-connect-key-rotation.php`): generate key → propose (signed with old) → adopt (A5 `set_hub_api_key`) → confirm (signed with new). Idempotent + crash-safe (keep old key recoverable until confirmed).
- **Heartbeat handling:** act on `rotate: true`.
- **Settings "Rotate key" action:** admin-triggered rotation (REST + SPA button).
- **Revocation detection:** outbound `401` (after a single confirming re-check) → `clear_hub_api_key()` + set the re-pair notice flag (the A5 `peanut_connect_hub_key_undecryptable`/re-pair surface).

### Shared

- The rotation handshake reuses the D-11 signing (and D-10 protocol header). No new canonicalization; extend the shared vectors only if a new route needs pinning.

## Error handling / edge cases

- **Proposal lost:** no PENDING recorded; edge retries next cycle. Old key unaffected.
- **Adopt-then-crash:** PENDING expires; edge still has old key → falls back. (Edge keeps the old key until it sees a confirmed new-key success.)
- **Two rapid rotations:** a new proposal replaces an unconfirmed PENDING (last-proposal-wins); only a confirmed key is ever promoted.
- **Clock skew:** PENDING TTL is generous (≥15 min) relative to the signing freshness window (300s).
- **Revoke during a pending rotation:** revoke wins — clears active + pending, site → disconnected, edge re-pairs.

## Testing

- **Hub (unit/feature):** propose records PENDING without disturbing ACTIVE; first valid use of the pending key promotes + drops old; expired PENDING is GC'd and old key still authenticates; revoke invalidates immediately; audit rows written. Authing with the old key during PENDING still works (no lockout).
- **Edge (unit):** rotation client state transitions; new key stored encrypted (A5); old key recoverable until confirmation; `401`-after-recheck clears key + sets re-pair flag; signed-with-old proposal / signed-with-new confirm produce verifiable signatures.
- **Cross-repo:** an edge-produced rotate proposal verifies on the Hub (reuse the D-11 vector/proof pattern).

## Phasing

1. **PR-A (Hub):** `Site` migration + `/rotate` + promote-on-pending-use + GC + audit + admin Rotate/Revoke. Behind the scenes; no behavior change until the edge uses it.
2. **PR-B (edge):** rotation client + heartbeat handling + Settings button + 401→re-pair detection.
3. **PR-C (enablement):** turn on a default rotation interval (Hub policy) once both sides are deployed — the only "flip", staged like A8b.

Deploy order: Hub first (must accept a rotate proposal before any edge sends one), then edge, then enable the policy.

## Risks & mitigations

- **Lockout** — the whole design is built around the no-lockout invariant (old valid until new confirmed; fail-safe to old on any failure).
- **Touching the live auth path** (`ValidateSiteApiKey`) for pending-key promotion — additive (recognize pending hash in addition to active), thoroughly tested, never rejects a valid active key.
- **Deploy ordering** — Hub-before-edge-before-policy; the rotate endpoint is inert until an edge calls it.
