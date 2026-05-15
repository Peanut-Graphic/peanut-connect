# UTMs Tab — Multi-Select, Bulk Actions & Lightweight Groups

**Date:** 2026-05-15
**Repo (primary):** `peanut-connect` (End-to-End plugin) — frontend SPA
**Repo (phase 2):** `peanut-hub` — one migration + minimal API surface
**Status:** Design approved, pending written-spec review

## Problem

Operators create one campaign-builder submission per tracked link. A single
email blast routinely produces 3+ UTMs (e.g. `DOME2620RS1_learn`,
`DOME2620RS1_terms`, `DOME2620RS1_footer`). The UTMs tab lists them as a flat
50-row table with only single-row archive/delete. After a real sending session
the list is long, the related rows are visually disconnected, and cleaning up
mistakes is one-row-at-a-time. Observed pain: 12 campaigns created in a sitting,
no fast way to review, compare, bulk-clean, or mentally group them.

## Goals

- Select multiple UTM rows and act on them at once: **archive, restore, delete**.
- See the comparison data inline (no drill-in): **name, source, medium,
  target URL, campaign** (Phase 1); **shortcode** added in Phase 2 — see
  Data-availability note below.
- **Export selected** rows to a CSV file.
- **Group** related UTMs under an operator-typed label so one email's links
  collapse together.

### Data-availability note (drives phase split)

The Hub `GET /marketing/utms` list response (`UtmController@index`) returns
each UTM with `withCount('links')` only — **no link slug or short_url**. A UTM
has many links (1-to-many). Showing a "Shortcode" column or putting it in the
CSV therefore requires the Hub resource to expose a primary link slug, which
is a Hub change. To keep Phase 1 strictly plugin-only, **Shortcode is deferred
to Phase 2** and bundled with the Hub change (added to the resource next to
`group_label`). Phase 1 columns and CSV do not include shortcode.

## Non-Goals (YAGNI)

- No group-management screen, group colours, notes, or rename/delete-group UI.
- No multi-group membership — a UTM has at most one label.
- No new bulk REST endpoint and no server-side group filtering.
- No groups table / membership join — a single nullable column only.
- No changes to the campaign-builder wizard.

## Delivery Phases

Grouping requires a Hub schema field that does not exist yet. To ship value
immediately without blocking on a Hub deploy, the work splits in two:

| Phase | Repo | Ships | Gated on |
|---|---|---|---|
| **1** | peanut-connect | Multi-select, adjusted columns (no shortcode), bulk archive/restore/delete, CSV export | nothing |
| **2** | peanut-hub → peanut-connect | `group_label` **+ primary link slug** in UTM resource; plugin grouping UI + Shortcode column enabled | Hub deploy |

Phase 1 carries **no grouping UI at all** (no Group column, no group bar
control). Phase 2 adds them. The two phases are independent PRs; Phase 1 does
not reference `group_label`.

---

## Phase 1 — peanut-connect (plugin-only)

### Components

All changes are in the SPA; no PHP/REST changes (existing single-item forward
routes are reused).

1. **`frontend/src/lib/utmCsv.ts` (new, pure)**
   - `buildUtmCsv(utms: Utm[]): string` — RFC-4180 CSV, columns:
     `name, source, medium, target_url, full_url, campaign, clicks`.
     (No `shortcode` in Phase 1 — not in the list payload; added in Phase 2.)
     Values quoted and `"`-escaped. Pure and unit-tested.
   - `downloadCsv(filename: string, csv: string): void` — Blob + object URL
     click. Thin, not unit-tested (DOM glue).

2. **`frontend/src/lib/useRowSelection.ts` (new, pure-ish hook)**
   - State: `Set<number>` of selected UTM ids.
   - API: `toggle(id)`, `toggleMany(ids, on)`, `clear()`, `isSelected(id)`,
     `selectedIds`, `selectedCount`. No network. Unit-tested as a reducer.

3. **`frontend/src/pages/Utms.tsx` (modified)**
   - Leading checkbox column; header checkbox selects/clears **all currently
     visible** rows (respects the archived toggle, not a server-wide select).
   - Column set becomes: ☑ · **Name** · **Source / Medium** · **Target URL** ·
     **Campaign** · Reach · Clicks · Links · (actions). Target URL is truncated
     with title tooltip. (Shortcode column added in Phase 2.)
   - **Bulk action bar** rendered above the table only when
     `selectedCount > 0`: `[n selected] · Archive · Restore · Delete ·
     Export CSV · Clear`. Restore shows only in the archived view; Archive only
     in the active view (mirrors per-row rules).
   - Selection clears on archived-toggle change and on successful bulk mutation.

### Bulk execution model

No bulk endpoint exists. Bulk actions iterate the existing single-item
mutations **sequentially** (not parallel) to stay well under the Hub's
per-site `120/min` marketing limit (raised in peanut-hub PR #331):

- Run calls in series with a tiny yield between; show a progress toast
  (`Archiving 3/12…`).
- On a failed item: stop, surface which ids failed, keep successes, refetch.
  Partial success is reported honestly, not swallowed.
- Delete reuses the existing danger `confirm()` dialog, reworded for `n` items
  and keeping the "also removes short links and click history" warning.

### Testing (Phase 1)

- `utmCsv.test.ts` — header row, field order, quoting/escaping of commas,
  quotes, newlines; empty selection → header only. (TDD: RED first.)
- `useRowSelection.test.ts` — toggle, select-all/clear of a visible set,
  idempotent toggleMany, count correctness.
- Existing `Utms` behavior (single edit/archive/delete) must remain green.

### Phase 1 acceptance

- Selecting rows reveals the bar; archive/restore/delete act on exactly the
  selection, sequentially, with honest partial-failure reporting.
- Export downloads a CSV of the selected rows with the specified columns.
- Target URL is a visible column. (Shortcode intentionally absent until P2.)
- No grouping UI present. No new permission prompts. Bundle rebuilt.

---

## Phase 2 — peanut-hub then peanut-connect (grouping)

### Hub change (small PR + deploy)

- **Migration (new file):** add nullable `group_label VARCHAR(120)` to `utms`.
  New migration only — existing create-table migrations are never edited.
- **Model:** add `group_label` to `Utm` `$fillable` and to the marketing
  endpoint JSON.
- **Resource — primary link slug:** `UtmController@index` currently does
  `withCount('links')` only. Add the primary link's slug/short_url to each
  UTM in the list response (e.g. `->with(['links' => fn ($q) =>
  $q->oldest()->limit(1)->select('id','utm_id','slug')])` exposed as a
  `primary_link_slug`/`short_url` field, or an accessor append). One UTM →
  show its first link's slug; UTMs with no link show blank. This unblocks the
  plugin Shortcode column + CSV without an extra request.
- **Validation:** `UtmController@update` (and campaign builder if it accepts
  passthrough) accepts `group_label` `nullable|string|max:120`.
- **No new endpoint.** Assignment rides the existing
  `PUT /api/v1/marketing/utms/{id}`.
- Tests: extend `MarketingApiTest` — `group_label` round-trips through update
  and appears in list + resource (TDD). Deploy alongside / after PR #331.

### Plugin change (gated on Hub deploy)

- `frontend/src/api/marketing.ts`: add `group_label: string | null` and the
  primary link slug/`short_url` field to `Utm`, and
  `group_label?: string | null` to `UtmUpdateInput`.
- `Utms.tsx`: add the **Shortcode** column (now available) and a **Group**
  column; add `shortcode` to the CSV columns. Render the table as collapsible
  `▾ Label (n)` sections plus an `(ungrouped)` bucket. Collapse state is local
  UI only; grouping/sort is client-side over the existing list response.
- Bulk bar gains **Assign to group ▾**: a text input with a typeahead of
  labels already present in the loaded set; applies via sequential
  `updateUtm({ group_label })` calls (same execution model as Phase 1).
- Clearing a group = assign empty → sent as `null`.

### Phase 2 acceptance

- Shortcode column is now present and populated; included in CSV export.
- UTMs with the same typed label collapse under one header; ungrouped rows in
  their own bucket; counts correct.
- Bulk "Assign to group" writes the label to the selection and the table
  regroups after refetch.
- Editing a single UTM can also set/clear its group.

## Risks & Mitigations

- **Bulk vs rate limit:** sequential execution + the PR #331 per-site 120/min
  limit. A 50-row delete = 50 serial calls, within budget; progress toast sets
  expectations.
- **Partial failure:** explicit per-item outcome, no silent success.
- **Phase ordering:** Phase 1 must not reference `group_label` or the new
  primary-link-slug field so it cannot break if Hub hasn't deployed. Enforced
  by keeping grouping and the Shortcode column entirely out of the Phase 1
  diff.
- **Column width creep:** Target URL truncates with tooltip; table keeps
  `overflow-x-auto`.

## Out of Scope / Future

Group-management screen, colours, multi-group membership, server-side group
filtering/pagination, a real bulk endpoint — revisit only if manual labels
prove insufficient at scale.
