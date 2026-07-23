# End-to-End Plugin — Navigation & IA Reorganization (Phase 1)

_Date: 2026-07-23 · Status: approved design, pre-implementation_

## Summary

Reorganize the End-to-End (peanut-connect) wp-admin plugin's navigation so people can actually find things. Replace the overflowing 13-item top-tab bar with a single, grouped **left sidebar** built from **one source of truth**, organized around the two jobs the owner actually does — **see performance** and **check health** — with tracking-setup promoted and the rest tucked away. Fold duplicate/misplaced destinations into one home each, and relabel the jargon. This is **navigation and information architecture only** — no page-level redesigns and no new features.

## Problem

The plugin grew to **20 pages behind 13 flat top-tabs** with a tangled hierarchy the owner (who built it) can no longer navigate:

- **Two out-of-sync nav sources.** The visible nav is `Layout.tsx`'s top-tab bar; a second `Sidebar.tsx` component carries a *different* list. This split is why a newly-added "Dominion Funnel" entry was invisible — it went into the wrong component.
- **Same destination in two places.** "Journeys" is a top-tab (`/analytics?focus=journeys`) *and* two routes (`/analytics/journeys`, `/journeys`). "Videos" is a top-tab (`/videos`) *and* lives under analytics (`/analytics/videos`).
- **Organized by data-type, not by job.** Marketing tools (Campaigns, UTMs, Links), analytics (Analytics, Journeys, GTM Coverage), and system/ops (Health, Errors, Activity, Updates, Settings) all sit flat and equal, with no grouping and no lead.
- **Jargon labels.** "UTMs", "GTM Coverage", "Tracking" vs "Analytics" — unclear what each means or where a task lives.

## Goals

- One **grouped left sidebar**, one nav config — no second nav component to drift.
- Five job-based groups, **leading with Performance and Health** (the owner's stated primary jobs), Tracking-setup first-class, everything else demoted.
- Every current destination reachable from exactly **one** place; kill the duplicates.
- Clearer **labels**; move GTM Coverage where it belongs (Health).
- **No broken links:** existing routes keep working (old paths redirect to canonical ones).

## Non-goals (explicit — deferred to later phases)

- **Phase 2 (separate spec):** the scoped "UTM Builder" WordPress role + a builder-only view.
- **Phase 3 (separate spec):** page-level redesign — a real Overview/home summarizing Performance + Health, **retiring the "jumble" Sankey** on Analytics, and **fixing misleading metrics** ("Clicked enroll" reads 0 while 6 converted). Phase 1 does not touch the internals of any page.
- No visual/brand restyle beyond what the sidebar itself requires.
- No changes to the HUB API or data.

## The new information architecture

Five groups. Every one of the 20 current pages maps to exactly one group. Order in the sidebar is deliberate — the two primary jobs sit at the top.

| Group | Sidebar items (label → current page/route) |
|---|---|
| **Overview** | Overview → `Dashboard` (`/`) |
| **Performance** ⭐ | Analytics → `Analytics` (`/analytics`) · Campaigns → `Campaigns` (`/campaigns`) · Journeys → `Journeys` (`/analytics/journeys`) · Dominion Funnel → `DominionFunnel` (`/analytics/dominion-funnel`) · Videos → `Videos` (`/videos`) |
| **Tracking setup** ⭐ | UTM Builder → `Utms` (`/utms`) · Short Links → `Links` (`/links`) · Tracking Code → `Tracking` (`/tracking`) |
| **Health** ⭐ | Health → `Health` (`/health`) · Tracking Health → `GtmCoverage` (`/analytics/gtm-coverage`) · Errors → `ErrorLog` (`/errors`) · Activity → `Activity` (`/activity`) |
| **System** | Updates → `Updates` (`/updates`) · Settings → `Settings` (`/settings`) |

**Detail pages** (not nav items — reached by drill-down, keep their routes): `CampaignStory` (`/analytics/campaign/:campaign`), `JourneyDetail` (`/analytics/journeys/:clickId`), `DominionJourneyTimeline` (`/analytics/dominion-funnel/journey/:clickId`), `VideoAnalytics` / `VideoAnalyticsDetail` (`/analytics/videos`, `/analytics/videos/:id`).

### Label changes (Phase 1)

| Old | New | Why |
|---|---|---|
| GTM Coverage | **Tracking Health** | It proves the tracking beacons are *firing* — a health signal, not analytics. Moves to the Health group. |
| UTMs | **UTM Builder** | It's where you build UTMs; the name should say the task. |
| Tracking | **Tracking Code** | Distinguish the install-snippet page from "Tracking Health". |
| Errors | **Errors** (unchanged) | Already clear. |

("Clicked enroll" and the Analytics Sankey are **not** touched here — they live inside pages and belong to Phase 3.)

### Duplicates removed

- **Journeys:** canonical route `/analytics/journeys`. The nav entry points there (not `/analytics?focus=journeys`); the legacy top-level `/journeys` and `/journeys/:clickId` **redirect** to the `/analytics/...` equivalents so existing bookmarks keep working.
- **Videos:** one nav entry (`/videos`); the analytics-nested video routes remain reachable by drill-down but are not a second nav destination.

## Architecture & components

Mirrors the plugin's existing React + wp-admin structure — one changed layout, one nav config, no data changes.

```
Layout.tsx (shell)
  └─ Sidebar (grouped, collapsible)  ──reads──▶  nav.ts  (SINGLE source of truth: groups → items)
        each NavLink → an existing route in App.tsx (unchanged targets)
```

- **`frontend/src/config/nav.ts` (new):** the one nav definition — an ordered array of `{ group, items: [{ label, href, icon }] }`. Both the sidebar and any "is this route in the nav" logic import from here. This is the "single source of truth" that prevents the `Layout`/`Sidebar` drift.
- **`Sidebar.tsx` (rewritten):** renders `nav.ts` as grouped sections (group heading + items), collapsible to icons, with the active item derived from the current route. Becomes the plugin's *only* visible primary nav.
- **`Layout.tsx` (changed):** drop the inline top-tab `navigation` array and the top-tab bar; render the shell with the grouped `Sidebar` on the left and page content on the right. Keep the existing `title` / `description` / `action` header props.
- **`App.tsx` (changed):** route *targets* stay the same; add redirects for the retired duplicate paths (`/journeys*` → `/analytics/journeys*`). Eager imports only (the `React.lazy` ban stands).
- **Icons:** reuse the existing `lucide-react` set already imported; each group's items keep sensible icons.

No changes to any page's internals, the API layer, or the PHP side (the plugin's admin page slug `peanut-connect-app` and REST routes are untouched).

## Data flow

Unchanged. The sidebar is presentational; it links to routes that render the same pages calling the same `marketingApi` endpoints as today.

## Error / empty / responsive states

- **Collapsed sidebar:** items show icons only with tooltips; state persists in `localStorage` (mirror any existing collapse behavior).
- **Narrow widths / small wp-admin content area:** the sidebar collapses to icons; below a breakpoint it becomes a top "hamburger" drawer so the plugin stays usable on small screens.
- **Unknown/legacy route:** the redirects cover the known retired paths; anything else falls through to the existing not-found/dashboard behavior (unchanged).

## Testing

- **`nav.ts` is the contract:** a unit test asserts the five groups exist in order and that every nav `href` resolves to a route declared in `App.tsx` (guards against another invisible-tab regression).
- **Sidebar render test:** renders the sidebar under a router and asserts each group heading + a representative item per group are present, the "Dominion Funnel" item lives under **Performance**, and "Tracking Health" under **Health**.
- **Redirect tests:** `/journeys` and `/journeys/:clickId` render the same component as their `/analytics/...` canonical (mounted via `MemoryRouter` at the legacy path).
- **No-duplicate test:** each destination route appears at most once in `nav.ts`.
- Existing page tests continue to pass unchanged (pages themselves are not modified).

## Ship plan

Standard plugin flow: implement Phase 1 on a branch, PR + CI, merge, then a signed release via `Peanut-meta/scripts/publish-plugin.sh peanut-connect <version> --ship`. Because this is a UI-only change with the built bundle committed (peanut-connect's publish packages committed `assets/dist` — it does **not** rebuild the SPA), the branch **must** commit the rebuilt `assets/dist`. Suggested version: a **minor** bump (new navigation, no breaking data change).

## Open follow-ups (tracked, not this spec)

- Phase 2 — scoped UTM Builder role + builder-only view.
- Phase 3 — Overview/home redesign, retire the Sankey, fix "Clicked enroll" and other misleading labels/metrics.
