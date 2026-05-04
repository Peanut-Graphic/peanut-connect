# Hub ↔ Edge Contract

> **Purpose:** A litmus test for every new feature in Peanut End to End. Read this before designing anything that touches the seam between the on-site plugin (the **edge**) and the central Peanut Hub.
>
> **Status:** Living doc — update when the rules genuinely change, not when implementation details shift.

---

## Why this exists

The ecosystem evolved messily. The original story was a family of WordPress plugins:

- **FormFlow** — the seed
- → split into **FormFlow Lite + Pro**, plus **Suite** (marketing toolkit), **Connect** (remote monitoring), and the **License Server**
- → eventually most of that functionality folded into **Hub** (a Laravel app), with **Suite** retained as the WP-side hooks layer and **Connect** retained as the on-site agent

The result is a codebase where the boundary between "what runs on the customer's WordPress site" and "what runs centrally on Hub" is genuinely unclear in places — historically, decisions were made one feature at a time without a written rule.

This doc is the rule. From here forward, every feature decision answers to it.

---

## The contract (four rules)

### Rule 1 — Edge does execution. Always.

Anything that needs to *happen* on a page render, a form submit, a tracker event, or a popup display **runs on the WordPress plugin**, not Hub. No synchronous Hub calls in a request path that the visitor sees. Period.

This is the moat — the plugin can do things Hub literally can't (server-side rendering, ad-blocker resilience, file system access, runtime hook registration). Don't throw that away by routing execution through Hub.

### Rule 2 — Hub does authoring and aggregation. Optional from the edge's perspective.

Hub is where:
- Campaigns get *authored* (richer authoring than the in-plugin builder)
- Cross-site data gets *aggregated* (rollups, multi-site dashboards)
- Multi-site management lives (only relevant when there are multi-sites)

The plugin **must run with sane local defaults if Hub is absent.** If a feature degrades to "broken" instead of "less rich" when Hub is unavailable, the feature is wrongly designed.

### Rule 3 — Hub's existence is invisible until explicitly paired.

The plugin must not leak Hub's existence to a customer who hasn't deliberately paired a site. That means:

- No outbound calls to `*.peanutgraphic.com` or other Hub-related hosts unless explicitly configured
- No "Hub" terminology in default user-facing strings (settings labels, error messages, README)
- No leaked URLs in `error_log()`, response headers, or admin notices
- No Hub-related option keys in `wp_options` until pairing happens

(Today this is **violated** — see "Current violations" below. The violations are tracked; new code must not add to the list.)

### Rule 4 — No synchronous Hub calls in a render path.

If a feature wants real-time decisioning from Hub ("show this popup if Hub says so"), redesign it. Pre-fetch the rules, cache them at the edge, refresh on a schedule. Synchronous Hub calls in the request path are a WAF time bomb — three releases in 48 hours fighting `mod_security` mangling proves it. Don't add more.

---

## Deployment modes

### Hub-paired (default)

Agencies and single-site customers who want centralized authoring + cross-site rollups. License-server auto-update, Hub-managed campaigns, full SPA admin UI with Hub status visible.

### Hub-blind / standalone

For sensitive enterprise clients where **Hub must not be discoverable**. Specifically: **Itron and any future client of similar profile.**

Itron is massive — multiple programs, multiple sites — and represents a large-scale deployment. Itron must believe Peanut End to End is the entire product. Discovery of a separate "Hub" backend creates a non-trivial risk that they reproduce it in-house and end the relationship.

Constraints for Hub-blind builds:

- **No outbound calls** to any peanut-controlled domain. Auto-updates either disabled or pointed at GitHub releases directly (license-server proxy bypassed).
- **No "Hub" string** in any rendered admin UI, settings label, error message, exported report footer, or update-notification name.
- **No Hub-related option keys** persisted in `wp_options`.
- **No `error_log()` calls** that emit Hub URLs or related diagnostics (see C-2 in the security audit — that exact issue would break Itron blindness).
- **All marketing features run on local config only.** Tracker, popups, forms, analytics — sane defaults bundled or admin-configurable in-plugin.

The simplest implementation path is a build-time flag (`PEANUT_HUB_BLIND` or similar) that strips Hub-aware code paths and Hub-aware UI strings at compile time. Decision deferred — this doc captures the requirement, not the mechanism.

---

## What lives where (canonical reference)

### At the edge — always

These run on the WordPress plugin and never block on Hub:

- `tracker.js` — page event capture, scroll depth, form submission events
- Popup display logic — modal/slide-in/bar/toast/fullscreen
- Event banner display
- Form capture and queueing
- Health monitoring (WP/PHP/MySQL versions, SSL, disk, memory)
- Plugin/theme/core update visibility
- Backup operations
- Local activity log + error log
- Site key authentication
- Local settings UI (the SPA admin)
- All input sanitization, all output escaping

### In Hub — when paired

These exist on Hub and the plugin only consumes them:

- Campaign authoring (richer than the on-site builder)
- Cross-site analytics rollups (aggregate Sankey, fleet-wide funnels)
- Multi-site management (deploy a campaign to N sites)
- Centralized form template library
- License management
- Update-version metadata (the mu-plugin endpoint)

### Synthesis tier — runs at edge, can be enriched by Hub

These work locally with sane defaults; Hub can override or enrich:

- Conversion rule definitions (default rules bundled in plugin; Hub can push richer rules)
- Popup display rules (default rules at edge; Hub can override per campaign)
- Funnel definitions (default at edge; Hub can author custom funnels)
- Tracker config (defaults at edge; Hub can supply tagging conventions)

For any synthesis-tier feature: **answer "what does this do with no Hub?" first.** If the answer is "nothing" or "broken," it's not synthesis tier — it's a pure-Hub feature in disguise and shouldn't ship to Hub-blind installs.

---

## The litmus test for new features

Before designing any new feature, answer all three:

1. **Does it work with no Hub?** If "no," redesign or scope to Hub-paired only.
2. **Does it leak Hub's existence to a Hub-blind install?** If "yes," redesign.
3. **Does it require a synchronous Hub call in a request-render path?** If "yes," it's a WAF time bomb — pre-fetch + cache instead.

If you can't answer "yes / no / no" cleanly, the feature isn't ready to design.

---

## Current violations of the contract

These are already in the codebase. They don't have to be fixed today, but new code must not add to the list, and Itron deployments must address them before shipping:

| Violation | File | Audit reference |
|---|---|---|
| `error_log()` emits Hub URL during connection | `class-connect-api.php:983–993` | H-3 |
| Plaintext Hub API key in `wp_options` (key name reveals Hub) | `class-connect-api.php:1162` + `class-connect-auth.php:266` | C-2 |
| "Hub Connection" / "Hub Mode" UI labels visible by default | `frontend/src/pages/Settings.tsx`, `Dashboard.tsx` | — |
| "Manager URL" / "Hub URL" option keys persist before pairing | settings registration | — |
| Self-updater calls peanutgraphic.com on schedule | `class-connect-self-updater.php` | — |

The first two are already in the security-audit roadmap. The remaining three need a Hub-blind build mode to fix cleanly.

---

## Implications for the strategic answers

Re-reading the CAT decision tree with the Itron context:

- **Q1 — standalone OS:** Validated, with a stronger backbone. At least one major client (Itron) requires the standalone-shaped product as a hard contract. Q2's "talk to 5 prospects first" is still useful for breadth, but the standalone framing is no longer purely speculative.
- **Q3 — Suite:** The history (FormFlow → split → Hub absorption, with Suite retained as WP-backend hooks) makes the Suite/End-to-End boundary a real conversation, not a deferred one. Worth a CAT-style dive on what Suite uniquely owns post-Hub-absorption.
- **Q5 — surface:** "Config in Hub, execution at edge" stands, with a tightened rule: **execution at edge must work *without* config from Hub**, not just with Hub-supplied config. This is the only interpretation that survives Itron.

---

## Open questions

These don't block writing the contract; they affect implementation when Itron's build is being prepared:

- Build-time flag (`PEANUT_HUB_BLIND=1` strips Hub paths at compile) vs. runtime detection (one binary, runtime mode)? **Recommendation: build-time** — runtime detection means Hub-aware code ships to Itron, increasing leak risk via reverse engineering.
- License/update flow for Itron — manual updates by Peanut team, or a separate update endpoint Itron owns? **Recommendation:** start manual; build a minimal updater later if the relationship requires it.
- Should the SPA bundle have separate builds (`peanut-end-to-end.js` vs. `peanut-end-to-end.itron.js`)? Or strip strings at build via env vars? **Recommendation:** env-var string substitution at Vite build time — single SPA codebase, two outputs.
- What's the contractual / legal layer? If Itron explicitly asks "is there a separate backend?", what's the answer? Worth a real conversation with whoever owns the Itron relationship before the technical layer matters.
