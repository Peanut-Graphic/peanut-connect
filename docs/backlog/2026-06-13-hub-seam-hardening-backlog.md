# Hub ↔ Edge hardening backlog (scoped)

> Source: CAT's forward read in the 2026-06-13 closure sweep (`docs/closure/2026-06-13-squeaky.md`).
> These are **not** blockers — the security arc (microscope → 3.12.0 → A5 3.13.0) is shipped and live. This doc scopes the next-tier hardening so it's actionable later, not lost. Each item: problem · approach · scope · effort · risk · priority.
>
> Cross-cutting theme: the edge plugin and the Hub are **two independently-deployed repos implementing one protocol**. The shared signing test-vectors (`docs/protocol/`, shipped both sides) are the first automated referee; the items below extend that posture.

---

## D-10 · Protocol version header `X-Peanut-Protocol`

**Problem.** A future change to the signing canonicalization (or any wire format) surfaces only as a cryptic `invalid_signature` 401, indistinguishable from a wrong key or a clock-skew failure. There's no way for either side to say "I speak v2, you sent v1."

**Approach.** Hub sends `X-Peanut-Protocol: 1` on every signed request. The edge reads it in `verify_hub_request()`: unknown/missing version → an explicit, distinct error (`unsupported_protocol`, 400) instead of a signature failure. Bump to `2` only in lockstep with a canonicalization change. Pairs naturally with the shared test-vector fixture (add a `protocol` field per vector).

**Scope.** Edge: `includes/class-connect-auth.php` (`verify_hub_request` / `verify_signed_hub_request`). Hub: `App\Support\PeanutConnectSigner::headers()` (add the header). Fixture: `docs/protocol/hub-signing-vectors.json` (+`protocol` field) + both contract tests.

**Effort.** S–M (both repos, but small surface). **Risk.** Low — additive; v1 is the implicit current state, so default-to-1 is backward-compatible. **Needs Hub coordination:** yes (header must be emitted before the edge can require it — same rollout discipline as A8b). **Priority.** Medium — cheap insurance that pays off at the *next* breaking protocol change; do it alongside A8b.

---

## D-11 · Sign the outbound edge → Hub direction

**Problem.** Inbound (Hub → edge) is HMAC-signed (key off the wire, anti-replay). The reverse direction is still a static `Authorization: Bearer <site_key>` in the header on every outbound call — `class-connect-hub-sync.php` (sync/heartbeat), `class-connect-forms.php` (the new `/forms/submit` proxy + forms sync), `class-connect-marketing.php`, `class-connect-short-links.php`, `class-connect-videos.php`, `class-connect-popup-display.php`. A transport-layer capture of an outbound request replays indefinitely. It's a structural asymmetry, lower-risk than inbound (these go out over HTTPS to a known host) but it will eventually look strange.

**Approach.** Mirror the inbound scheme outbound: the edge signs its requests with the same `compute_request_signature` (it already has the function and the key), Hub verifies with `PeanutConnectSigner`. Centralize outbound HTTP through one signed client on the edge (today the `wp_remote_*` calls are scattered) — that consolidation is half the value.

**Scope.** Edge: a shared signed-request helper + migrate the ~6 outbound call sites. Hub: verify signatures on the Connect-facing ingest routes (`/api/v1/*` consumed from sites). **Effort.** L (touches both repos + a scattered-call-site consolidation). **Risk.** Medium — must not break live sync; stage as accept-signed-OR-bearer on Hub first, then require. **Needs Hub coordination:** yes. **Priority.** Medium-low — real asymmetry, but HTTPS already covers the realistic threat; do after D-10/A8b.

---

## D-12 · Key rotation / revocation story

**Problem.** If a site's key is suspected compromised, or an admin with DB/wp-config access is offboarded, the only remedy is generate-new-key + re-pair. There's no Hub-side revocation endpoint, no rotation UI, and no defined SLA between "revoke" and "old key stops working." Matters most for **Itron** (Hub-blind, enterprise, multi-site).

**Approach.** (1) Hub-side `revoke` that invalidates a site's key immediately and marks the site needs-repair. (2) Edge: a "rotate key" admin action that coordinates a new key with Hub without a full re-pair. (3) Define + document the revocation SLA. Consider short-lived keys with scheduled rotation as the longer-term shape (the A5 encryption already makes at-rest rotation cheap).

**Scope.** Mostly Hub (revoke endpoint + site state + admin UI) + an edge rotate action + the re-pair UX already built for A5's undecryptable path. **Effort.** L. **Risk.** Medium — touches the live pairing/auth lifecycle. **Needs Hub coordination:** yes (Hub-led). **Priority.** Medium — elevate to High if Itron contractually requires offboarding/revocation guarantees.

---

## D-13 · A5 follow-ons (encryption-at-rest edges)

**Problem / sub-items.**
- **Salt-rotation = forced re-pair.** The at-rest key is derived from `wp_salt('secure_auth')`; rotating WP salts makes the stored key undecryptable. Handled gracefully today (un-paired + admin notice), but it's a known operational sharp edge an admin can trip by following standard WP "rotate your salts" advice.
- **Verification token distinct from the signing secret.** Explicitly out of scope for A5 (encryption-at-rest with decrypt-on-use met the goal). A separate verification token would let the stored secret be hashed rather than reversibly encrypted — a deeper credential redesign.

**Approach.** For salt-rotation: optionally derive from a dedicated, rotation-stable secret (a generated key in `wp-config.php` or a protected file) instead of `wp_salt` — the alternatives weighed in the A5 spec (`docs/superpowers/specs/2026-06-13-a5-…-design.md`); revisit only if salt-rotation re-pairs become a real support burden. For the verification token: a future credential-model redesign, only if requirements demand a non-recoverable stored secret.

**Scope.** Edge-only for the salt-derivation alternative (`Peanut_Connect_Secret::derive_key`); cross-repo for a token redesign. **Effort.** S (salt alternative) / L (token redesign). **Risk.** Low / High respectively. **Needs Hub coordination:** no / yes. **Priority.** Low — both are "only if it bites" items; A5 as shipped meets the security goal.

---

## Suggested sequencing

1. **A8b** (already-scoped operational rollout) + **D-10** (protocol header) together — same coordinated-deploy discipline, and the header makes A8b's failures legible.
2. **D-12** (revocation) — promote if Itron requires it.
3. **D-11** (outbound signing) — the structural-completeness item.
4. **D-13** — only if salt-rotation re-pairs or a non-recoverable-secret requirement actually arise.
