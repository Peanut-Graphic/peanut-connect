# End-to-End Plugin — Overview + Analytics Redesign (Phase 3)

_Date: 2026-07-23 · Status: design for review · Depends on: Phase 1 (nav) + Phase 2 (UTM Builder role)_

## Summary

The visual/page-level pass of the plugin overhaul. Four parts: (1) rebuild the "Dashboard" into a real **Overview** home that answers the owner's two jobs — Performance and Health — at a glance, with every tile drilling in; (2) **retire the unreadable Sankey** on Analytics and replace it with clickable **campaign outcome bars** that drill segment → journeys → timeline; (3) fix the misleading **"Clicked enroll"** metric by pointing it at the reliable "entered the portal" signal and relabeling it honestly; (4) enrich the **journey-detail timeline** with the device/geo/referrer/engagement data HUB already captures but the UI never showed. Uses existing HUB endpoints — no new API work.

## Problem

- The current **Dashboard** is really a connection/health status page; performance is barely present. The owner — the primary user — opens the plugin to see performance and health, and neither is surfaced well.
- The Analytics **Sankey** ("Campaign → channel → outcome") is a documented "jumble": labels collide and one huge node dominates. The Dominion Funnel now owns the enrollment-flow story, so the Sankey is redundant *and* unreadable.
- **"Clicked enroll"** filters on the `click_to_portal` beacon, which under-fires: all 6 real converters entered the portal and enrolled, yet **none tripped `click_to_portal`**, so the metric reads 0 for people who obviously clicked through. It misleads about the single most important step.
- The journey timeline shows only the hash steps, while HUB captures far more per event (device, browser, OS, country/region, referrer, scroll depth, exit intent, time-on-step) — none of it surfaced.

## Goals

- An **Overview** home with a KPI row + two equal panels (Performance | Health), everything clickable into its full page.
- Analytics without the Sankey: **outcome bars** per campaign (converted / in-progress / abandoned), each segment drilling to the filtered journeys and on to a single timeline.
- A **"Clicked enroll" fix** that reports a truthful number and reads clearly.
- A **richer journey detail** using data already captured.
- Everything reuses existing HUB endpoints and the Phase-1 nav.

## Non-goals (deferred)

- **Phase 4 (separate spec):** breakdown charts by device, geography, referrer, and time-of-day/day-of-week (the data supports them; they're their own build).
- **Tracking-instrumentation fix (separate task, not a UI change):** make the `click_to_portal` beacon actually fire on the real enroll-CTA click so the raw click event becomes trustworthy. This spec fixes the *display* by using the reliable entered-portal signal; the beacon fix is logged alongside Phase 4.
- **New/returning visitor** analytics — not reliably derivable (click_id is a per-click 24h cookie; no persistent visitor id or stored IP). Explicitly out; do not build a metric that can't be truthful.
- No changes to the HUB API, PHP, or the Phase 1/2 nav & role work.

## Data available (verified against HUB, site 4)

Per **journey**: click_id, link_id, utm_source/medium/campaign, status (converted/in_progress/abandoned), events_count, pages_viewed, started_at, last_event_at, converted_at, **duration_seconds**.
Per **journey_event**: event_type, event_name, event_data (mostly scroll `depth`), full UTM set, page_url, page_title, **referrer**, **device_type**, **browser**, **os**, **country**, **region**, event_at. Behavior events include short_link_click, scroll_depth, page_view, click_to_portal, outbound_click, page_exit, exit_intent, download/phone/email_click, gtm_beacon_conversion.

This is the menu Phase 3 draws from; nothing here requires new capture.

## Design

### 1. Overview home (replaces Dashboard body) — approved layout "A: two panels"

Top: a **KPI row** — Landed · Entered · Enrolled · Conversion % — from the Dominion funnel endpoint (last 30 days). **Every KPI tile is a clickable drill-in** to the Dominion Funnel filtered to that stage (the funnel already supports stage filtering from Phase 1): Landed→no filter, Entered→`stage=entered`, Enrolled→`stage=enrolled`.

Below, two equal panels:
- **Performance** — a compact funnel (reaching + inside) + top campaigns; header links to the full Dominion Funnel; each row/campaign is clickable.
- **Health** — connection status, tracking-firing (from GTM/tracking-health), error count, updates-available; each line links to its page (Health / Tracking Health / Errors / Updates).

Every tile and row is a link into its full page. Existing Dashboard concerns that are genuinely status (welcome/connect state when not yet connected, critical-issue alerts) are preserved but moved below or into the Health panel.

### 2. Analytics: retire the Sankey → campaign outcome bars

- **Remove** the Sankey component from the Analytics page.
- Add **`CampaignOutcomeBars`**: one horizontal stacked bar per campaign, segments = converted / in-progress / abandoned, sorted by volume, with a legend. Built from the campaign + status data the analytics/funnel endpoints already return.
- **Drill chain (approved):** clicking a **segment** → the journeys list filtered to that campaign + outcome; clicking a **whole bar** → that campaign, all outcomes; each journey row → the single-journey **timeline**. This reuses the Dominion Funnel's existing journeys-table + timeline drill pattern and routes.
- Keep the readable "Top campaigns" and "By channel" tables already on the page.

### 3. "Clicked enroll" fix

- Repoint the metric/pill from the under-firing `click_to_portal` event to the **reliable "entered the portal" signal** (journeys with ≥1 portal-hash event — the funnel's "Entered" definition, which correctly includes all converters).
- **Relabel** it **"Entered enrollment portal"** (from "Clicked enroll"), so the label matches what it truly measures. Where it appears as a journeys filter, it filters to journeys that entered the portal.
- This makes the number truthful (it will include the 6 converters and the broader set who reached the portal) instead of reading 0.

### 4. Richer journey detail

Extend the single-journey timeline (the existing `DominionJourneyTimeline` / `JourneyDetail` view) with a **context panel** from data already present on the journey's events:
- **Device / browser / OS**, **country / region**, **referrer / how they arrived**, **pages viewed**, **total duration**.
- **Engagement signals** already captured: max **scroll depth**, whether **exit_intent** fired, outbound/download/phone/email clicks.
- Keep the existing step timeline with **time-on-step** deltas and the terminal Enrolled ✓ / Dropped marker.

Pull these from the journey-detail payload the plugin already fetches (`marketingApi.journeyDetail`); if a field isn't in that payload but is on the events, surface it from the events array already returned. No new endpoint.

## Architecture & components

- `frontend/src/pages/Dashboard.tsx` → rebuilt Overview (KPI row + two panels). Extract panels into small components (`OverviewPerformancePanel`, `OverviewHealthPanel`) so the page stays focused.
- `frontend/src/components/charts/CampaignOutcomeBars.tsx` (new) — the stacked outcome bars + segment click handlers.
- `frontend/src/pages/Analytics.tsx` — remove the `Sankey`; mount `CampaignOutcomeBars`; keep existing tables.
- `frontend/src/components/charts/Sankey.tsx` — retire (delete if unused elsewhere; grep first).
- Journey detail — add a `JourneyContextPanel` (device/geo/referrer/engagement) to the existing timeline view.
- The "Clicked enroll" → "Entered enrollment portal" change: label + the predicate that drives the filter (entered-portal, not click_to_portal).
- All reads go through existing `marketingApi` methods (funnel, campaign story/stats, journeys, journeyDetail). No PHP/API changes.

## Error / empty / responsive states

- Overview panels each render their own loading/empty/error state (react-query), so one slow panel doesn't block the other.
- Outcome bars: empty range → "no campaigns in this window" note; a campaign with all-abandoned still renders (grey bar).
- Journey context panel: any missing field shows "—" (device/geo can be null on older events).
- Two-panel layout stacks vertically below a breakpoint.

## Testing

- **Overview:** KPI tiles render from a funnel fixture and each links to the right funnel stage URL; Performance and Health panels render their data and their drill links; not-connected state still shows the connect/welcome path.
- **CampaignOutcomeBars:** renders one bar per campaign with correct segment widths from a fixture; clicking a segment calls the drill handler with `{campaign, outcome}`; empty state renders.
- **"Entered enrollment portal":** the relabeled control shows the new text and, as a filter, uses the entered-portal predicate (a fixture where `click_to_portal` count ≠ entered count proves it counts entered, not the beacon).
- **Journey context panel:** renders device/browser/os/country/region/referrer/duration/scroll from a journey-detail fixture; missing fields render "—".
- Existing page tests stay green; rebuild + commit `assets/dist` (signed publish packages committed dist).

## Ship plan

Stacked on Phase 2 (`feat/utm-builder-role`); implement on `feat/overview-analytics-redesign`. PR → CI → merge in order (Phase 1 → 2 → 3) → signed release (minor bump). Reminder: commit the rebuilt `assets/dist`.

## Logged follow-ups (not this spec)

- **Tracking task:** fix the `click_to_portal` beacon so the raw enroll-CTA click fires reliably (GTM/tracking-code instrumentation).
- **Phase 4:** breakdown charts — device, geography, referrer, time-of-day/day-of-week — plus engagement (scroll-depth, exit-intent drop-off) analytics across journeys.
