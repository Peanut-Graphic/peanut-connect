# End-to-End Plugin — Audit Remediation Roadmap

_Date: 2026-07-23 · Source: CAT + MAX fresh-eyes audit of v3.24.0 (all findings verified against code before inclusion)_

Three phases, sequenced by risk and dependency. Phase 1 is pure hardening (verified bugs, plugin-only, ships as a patch). Phase 2 is the highest-leverage opportunity but needs a design pass (touches PII). Phase 3 is reporting depth + de-risking future reuse.

**Standing rule for all phases (per the user): build with eyes wide open for breakage points and weak spots.** Every change: run the full suite, reason about blast radius explicitly (the tracker runs on live client browsers; the rate limiter is the only guard on the unauthenticated write surface; the conversion path will touch email/PII), add a regression test that would catch the failure, and verify the built bundle before any release.

---

## Phase 1 — Hardening (this repo, patch release)

Verified, plugin-only, low-risk-if-careful. The three items:

### 1a. Builder wizard's silent 403 (MAX Medium #1 — verified)
`frontend/src/pages/Campaigns.tsx:134` fires `marketingApi.trackingSetup()` on mount, but `/marketing/tracking-setup` is admin-only (`$perms`, `class-connect-marketing.php:130`). A UTM-Builder-role user 403s on every load; the "not connected" warning and the "reporting to X" confirmation line silently never render.
- **Fix (least-privilege):** disable the `tracking-setup` query in builder mode — `enabled: getAppMode() !== 'builder'` — rather than widen the permission surface. The builder can't act on connection status anyway. Keeps the tight scoping the audit praised.
- **Blast radius:** admin mode unchanged (query still runs). Builder mode loses a status line it couldn't use. Guard: a test asserting the wizard renders in builder mode with no tracking-setup call.

### 1b. Non-atomic rate limiter (MAX Medium #2 — verified)
`class-connect-rate-limiter.php:50→76→96` is `get_transient → count++ → set_transient` — a TOCTOU race. It's the only backstop on the unauthenticated `/track`, `/identify`, `/conversion`, `/gtm-beacon`, `/popup-interaction` write surface; parallel requests beat it.
- **Fix:** atomic increment. Prefer `wp_cache_incr()` when an external object cache is present (`wp_using_ext_object_cache()`), which is atomic; fall back to the current transient path only when it isn't (single-process dev, no worse than today). Keep the exact same public `check()` contract and window semantics.
- **Blast radius: HIGH — this is a security primitive.** Do NOT weaken existing limits. Guard: tests for (a) same behavior under sequential calls (limit enforced at the boundary), (b) the object-cache path increments atomically, (c) fallback still works with no object cache. Note in the PR that Hub's own limiter likely shares this shape (fleet follow-up, not this PR).

### 1c. Boolean-query-string class of bug (MAX Low #4 — verified pattern)
`listUtms({archived})`, `listLinks({active})`, `gtmCoverage({valid_only})` pass JS booleans through axios → serialize to `"true"`/`"false"` — the same shape as the `include_test` 422. Whether each breaks depends on Hub validation (not in this repo), but the class is unaudited.
- **Fix (plugin-side, defensive):** normalize outbound booleans at the proxy boundary so Hub never receives an ambiguous value — coerce to a consistent form the plugin controls, in the `marketingApi` methods (or the shared axios param serializer). Belt-and-suspenders regardless of Hub's rules.
- **Blast radius:** low; changes only the query-string encoding of three optional filters. Guard: a service test asserting the serialized param shape.

**Ship:** Phase 1 → PR → CI → merge → signed patch release (3.24.1). Verify the released bundle.

---

## Phase 2 — Verifiable conversions (needs a design pass FIRST)

The highest-leverage finding (CAT — verified): the plugin already has a complete `conversion()`/`identify()` pipeline (`tracker.js:745,762` → `class-connect-api.php:2000` → `record_conversion()` writing email/name/order + `identify_visitor()`), plus a 1-year `peanut_vid` cookie — **all unused by the enrollment funnel.** Wiring it makes "Enrolled" verifiable (IntelliSource email match), gives new-vs-returning for free, and extends attribution past the 24h `click_id` via the long-lived visitor id.

**This is NOT a straight build — it touches PII and depends on facts we must confirm.** Open questions that a short brainstorm resolves before any code:
- Is the enrollment portal same-origin with `tracker.js` loaded at `#enrolled`? (The hash-step events suggest yes — confirm.)
- Is the customer email actually present in the DOM/JS at `#enrolled` for `conversion({email})` to read, or does it need the portal's cooperation?
- **Privacy/consent:** capturing email is a real policy decision (storage, retention, the honesty-caveat pattern, whether it needs disclosure/consent on the portal). Do not capture PII without deciding this deliberately.
- The `peanut_vid → last-known-campaign` bridge (CAT) is the attribution-window extension — scope alongside, same visitor-id continuity.

**Path:** brainstorm → spec → plan → build. Keep the existing honesty caveats even after this partially closes the gap (CAT's warning: don't let a partial fix become an excuse to overclaim).

---

## Phase 3 — Reporting depth + de-risking reuse (later, own specs)

- **Cost/CPA on the Dominion funnel page** (CAT): `JourneyStats` computes `cost_total`/`cost_per_acquisition`/`ctr` but `DominionFunnelResponse` omits them; the marketer asks "CPA by campaign" on the funnel page, not the general Analytics page. Merge cost into the funnel endpoint's campaign breakdown.
- **"Why do they bail at `#validation`" aggregate** (CAT): we render scroll-depth + exit-intent per journey but never aggregate them. A GROUP BY ("avg scroll / % exit-intent of abandoners vs enrollers, by stage") is far more diagnostic than time-of-day for fixing drop-off.
- **Generalize the funnel vocabulary out of hardcoded "Dominion"** (CAT — sharpest architectural call): `nav.ts`, `DominionFunnel.tsx`, and the hash-step matching hardcode one client's program into a multi-tenant plugin. Before the next utility program forces a copy-paste, make the stage list + hash patterns *data, not code* — a configurable "portal funnel" definition.
- **Portal fires its own structured event** (CAT): the deeper fix behind the `click_to_portal` saga — detection should originate in the portal SPA's routing, with marketing-page CTA-matching as a backstop, not the primary signal.
- **Version-monotonicity in the signed-update gate** (MAX Low #3): closes a narrow downgrade-reinstall window; lives in vendored `formflow-core` (cross-repo — fix at the source, re-vendor; benefits every consumer).

---

## Explicitly NOT doing (audit noise / low value)
- CHANGELOG backfill (MAX #5) and manifest-timeout error wording (MAX #6): real but cosmetic — do opportunistically, not as tracked work.

## What the audit confirmed is already sound (no action)
UTM Builder role scoping (airtight), signed self-updater (fail-closed, correct), `tracker.js` (no XSS — reads DOM, never writes it; click_id percent-encoded), public tracking endpoints (rate-limited/size-capped/bound-param inserts), the QR `dangerouslySetInnerHTML` (locally-generated pixel data, safe).
