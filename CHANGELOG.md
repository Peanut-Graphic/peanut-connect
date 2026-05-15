# Changelog

All notable changes to **Peanut End to End** (slug: `peanut-connect`) will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.7.27] - 2026-05-15

### Changed
- Maintenance release. No functional changes since 3.7.26 — version bump so the update is re-offered to sites already on 3.7.26 (e.g. dominionptr.com) and the auto-updater serves a fresh package. All 3.7.26 UTMs multi-select / bulk archive-restore-delete / CSV export functionality is unchanged.

## [3.7.26] - 2026-05-15

### Added
- **Bulk actions on the UTMs tab.** A checkbox column (with select-all-visible) and a bulk action bar: **Archive**, **Restore**, **Delete**, and **Export CSV** for the selected UTMs. Bulk operations run sequentially (well under the Hub's per-site rate limit), stop at the first failure, and report honest partial success rather than silently swallowing errors. Bulk delete reuses the danger confirmation, reworded for N items. Selection clears when switching Active/Archived and after a completed bulk action. CSV export downloads the selected rows (`name, source, medium, target_url, full_url, campaign, clicks`). _(Phase 1 — grouping and a Shortcode column follow once the Hub side ships.)_

## [3.7.25] - 2026-05-15

### Fixed
- **Campaign builder no longer fails with "Hub did not return a campaign payload."** The SPA's axios response interceptor only forwarded the nested `data` envelope and silently discarded Hub mutation responses, which return the resource at the top level (`{ success, campaign|utm|link: {...} }`). The campaign builder threw the generic payload error even though the short link _was_ created, and `updateUtm` / `archiveUtm` / `restoreUtm` / `updateLink` all came back empty through the same path. The interceptor now preserves both the nested-`data` envelope (list/setup endpoints) and top-level resource keys (mutation endpoints).

## [3.7.24] - 2026-05-11

### Changed
- **Plugin renamed from "Peanut End to End" to "End-to-End"** in user-visible places: WordPress plugin header `Plugin Name`, admin menu labels, sidebar wordmark + version footer, Dashboard welcome panel, Settings tooltips, exported reports, and Suite-detection help copy. Slug, REST namespace, option keys, and update-server endpoints all remain `peanut-connect` for backwards compatibility. References to other ecosystem products ("Peanut Hub", "Peanut Suite", "Peanut Graphic") are unchanged — only this plugin's own name was dropped.

### Fixed
- **"Save & exit" no longer lies.** The button used to be labeled `Save & exit` but never actually navigated anywhere and didn't submit the in-progress campaign — it only wrote a draft to localStorage. Users assumed it had created the UTM and were confused when nothing appeared in the UTMs list. Renamed to **Save draft** and the click now emits a toast: _"Draft saved locally. Your form will restore next time. The campaign isn't submitted yet — finish all 4 steps to create it."_

## [3.7.23] - 2026-05-11 — HOTFIX

### Fixed (regression in 3.7.22)
- **All lazy-loaded route chunks 404'd in production.** 3.7.22's React.lazy code-split emitted chunk imports like `/js/Campaigns-D5yzJRHT.js`, which the browser resolved relative to the page URL (`wp-admin/admin.php/...`) instead of the plugin's assets path. Every code-split route + every grouped icon chunk returned 404, hydration crashed mid-render, and the ErrorBoundary fallback ("Something went wrong") overlaid every page. Reverted `App.tsx` to eager imports so all routes resolve via the single `main.js` bundle again. Bundle is back to ~545 KB; proper code-splitting needs Vite `base` configured against the plugin's URL via WP — deferred until that integration is wired.

## [3.7.22] - 2026-05-11

### Performance
- **Code-split SPA per route.** `App.tsx` now lazy-loads every page via `React.lazy + Suspense`; only Dashboard is eagerly imported for the initial paint. **Main bundle dropped from 543 KB → 314 KB (~42% reduction).** Per-page chunks: Health 51 KB, Campaigns 46 KB, Settings 38 KB, Analytics 24 KB, Activity 21 KB, Updates 17 KB, Utms 11 KB, ErrorLog 9 KB, Links 5 KB, Tracking 5 KB.
- **Composite index on `events(visitor_id, synced)`.** The visitors-sync JOIN previously tablescanned `events` for every batch on installs at scale. New composite key + DB_VERSION bumped to 1.2.0 so the migration runner picks it up automatically.

### Architecture
- **Migration runner is now hooked.** `Peanut_Connect_Database::init()` is called from the main plugin file so `check_db_version` runs on every `plugins_loaded` and applies schema bumps without requiring plugin reactivation. Previously dead code.
- **Sync batch loops deduped.** The 4 `sync_*` methods (events / visitors / popup_interactions / form_submissions) are now thin callers of a single `sync_in_batches()` helper. Same behavior, ~120 fewer lines, easier to extend.
- **Settings.tsx split.** 1,227-line monolith reduced to 809 lines by extracting Security Hardening → `Settings/SecurityCard.tsx` and Hub Permissions → `Settings/PermissionsCard.tsx`. Each card owns its own queries + mutations.

### Data retention
- **`cleanup_old_records` now cleans all synced tables.** Previously only events / touches / popup_interactions. Now also conversions, visitors, and form_submissions. Visitor retention is automatically 50% longer than dependent rows to avoid orphan-insert windows.

### Accessibility
- **Analytics campaign dropdown** now closes on Esc + click-outside, with `aria-haspopup="listbox"` + `aria-expanded` on the trigger and `role="listbox" aria-multiselectable="true"` on the menu.
- **UTM edit modal** migrated from a raw fixed-overlay div to the project's `Modal` component, gaining focus trap, Esc-to-close, body-scroll lock, and proper ARIA labelling.

### i18n
- Wrapped untranslated strings in `__()` / `_n()` across `class-connect-forms.php` and `class-connect-hub-sync.php` ("Hub not configured", "Unknown error", "Synced %d forms from Hub", "Failed to sync %s: %s"). Translation domain: `peanut-connect`.

## [3.7.21] - 2026-05-11

### Security (defense-in-depth)
- **`/restore` endpoint host allowlist.** The bearer-protected restore endpoint accepted `backup_url` from any host, then unzipped + replayed its SQL dump + copied files into `wp-content` — RCE-equivalent if the Hub bearer ever leaked. Now rejects URLs whose host doesn't match the configured `peanut_connect_hub_url`.
- **SSRF guard on Hub URL save.** `update_hub_settings` now rejects non-HTTPS schemes, IP-literal hosts in private ranges, `localhost`/`.local`/`.internal` hostnames, and hostnames that resolve to RFC1918/loopback/link-local addresses. Prevents an admin (or compromised admin session) from repointing the plugin at cloud-metadata services or internal HTTP services that would receive the Hub bearer header.
- **Hide-login Referer bypass.** `class-connect-security.php` previously allowed direct POSTs to `wp-login.php` if `HTTP_REFERER` contained the custom-login slug as a substring. Referer is attacker-controlled, so `Referer: https://evil/<slug>` bypassed the gate. Now verifies the Referer host equals `home_url()`'s host AND the path begins with the slug.

### UX
- **Mutation toasts on UTMs + Links pages.** Archive/restore/delete/toggle/update mutations now emit success and error toasts. Previously failures silently looked like successes.
- **Styled confirm dialogs.** Added `useConfirm()` hook backed by the existing `ConfirmModal`. All four native browser `confirm()` calls (Links delete, UTMs delete, ErrorLog clear, Activity clear) now use the styled dialog — keyboard-accessible, blocks page properly, matches the rest of the SPA.

### Cleanup
- **`disable_suite_loading()` no longer nukes every plugin's `plugins_loaded` hook.** Previously it called `remove_all_actions('plugins_loaded', 10)` which removed every priority-10 handler across the WP install — massive collateral damage. Now removes only Suite's `peanut_run` bootstrap specifically.
- **Added `uninstall.php`.** Plugin removal now drops the 8 tracking tables, clears all scheduled cron events (including the legacy `peanut_connect_sync_to_hub` hook), deletes every `peanut_connect_*` option, and clears the slug transient.

## [3.7.20] - 2026-05-11

### Fixed
- **`peanut_connect_sync_requested` action handler was unhooked.** Heartbeat schedules a single event with this name when Hub returns `sync_now=true`. The handler used to live in `Hub_Sync::init()`, which was dead code as of 3.7.17, so every Hub-requested immediate sync silently no-op'd. Now hooked in `Peanut_Connect::init_hooks()`.
- **HTTP_REFERER sanitization.** Tracker writes (`record_event` pageviews, `record_touch` channel + referrer column) now pass `$_SERVER['HTTP_REFERER']` through `esc_url_raw()` before DB write. Previously raw client-controlled values were stored verbatim.
- **Event banner CSS/HTML render safety.** Hub-provided CSS is now stripped of `</style>` sequences before render so a hostile Hub payload can't break out of the `<style>` block. Hub-provided HTML now passes through `wp_kses_post()` at render to strip script tags and other dangerous markup.
- **FormFlow shortcode escaping bug.** `do_shortcode('[formflow id="' . esc_attr($form['id']) . '"]')` was entity-encoding quotes inside a shortcode string, which the shortcode parser then saw as literal entities. Replaced with `absint()` validation and raw insertion.
- **Safe `last_sync` date parsing.** New `utils/date.ts` exports `formatRelative()` that returns `'Never'` on unparseable input instead of throwing `Invalid time value`. Dashboard and Settings pages now use it.
- **UTM edit no longer zeros `send_count`/`campaign_cost`.** Editing only the Notes field used to send `0` for blank send_count/cost, silently clobbering valid existing values. Now sends `null` when blank.
- **Router transitions instead of hash anchors.** `Campaigns.tsx` Done step's "Open Tracking tab" link and `Dashboard.tsx`'s "View Updates" recommendation now use React Router (`<Link>` / `useNavigate`) instead of `href="#/foo"` / `window.location.hash`, which bypassed router lifecycle and felt janky.
- **Sidebar wordmark.** Changed from `Connect` to `End to End` to match the actual product name. First-time users were confused which product they were in.
- **Header search no longer silently redirects.** Previously, 5 hardcoded keyword branches; anything else routed to Dashboard with no feedback (a "search is broken" trap). Now covers all 11 nav items and surfaces a `No page matches "X"` error on unmatched queries.

### Removed
- Dead code: `register_sync_endpoint()`, `handle_manual_sync()`, `get_sync_status()` (never wired to `rest_api_init`), and `unschedule()` (only cleared a legacy hook).

## [3.7.19] - 2026-05-11

### Fixed
- **Unbounded sync loops.** `sync_campaign_events`, `sync_campaign_visitors`, `sync_popup_interactions`, and `sync_form_submissions` each ran a `while (true)` loop with no batch cap. On a backlog, a single WP-Cron tick could spin through arbitrarily many 200-row batches and hit `max_execution_time` mid-flight, leaving data half-synced and aborting all subsequent batches silently. Added `MAX_BATCHES_PER_RUN = 50` constant (50 × 200 = 10K rows per record type per tick). Remainder defers naturally to the next cron tick.

## [3.7.18] - 2026-05-11

### Added / improved
- **Paginated slug fetcher.** `class-connect-short-links.php` was capped at 100 active links (the first paginator page); sites with >100 would silently 404 beyond that. Now follows Laravel paginator `current_page`/`last_page` through up to 20 pages (2,000 active-link ceiling).
- **Tracker snippet substitutes the real Site Key.** `tracking-setup` returns the unmasked `site_key` (the unmasked value is destined for public HTML in the tracker snippet anyway) AND a separate `site_key_masked` for the UI's "Site Identity" card. Snippets on the Tracking page and in the campaign wizard now embed the real key instead of `<<paste your Site Key from Hub>>`.
- **Nav reordered.** Sidebar puts `Tracking` before `Analytics` so the setup flow reads top-to-bottom (set up tracking → run campaigns → watch analytics).

### Fixed
- **Done card `✓` Unicode replaced with Lucide `Check`** for visual consistency with the rest of the SPA.
- **Paste-URL field type changed from `url` to `text`** so the browser's built-in URL validation doesn't fire before the custom parser has a chance.
- **Draft saved-at timestamp is now persisted in localStorage** so the "Draft saved Xm ago" header reflects actual save time instead of mount time after a page reload.
- **DoneStep next-steps now links to the Tracking tab directly** instead of saying "from Step 3" (which becomes ambiguous once the wizard locks).

## [3.7.17] - 2026-05-11

### Fixed (post-audit cleanup bundle)
- **Sync cron deduplication.** `Peanut_Connect_Hub_Sync::init()` was dead code that would have created a duplicate cron schedule (`peanut_connect_sync_to_hub`) if called. Replaced with a no-op. Added one-time cleanup of any stale legacy schedule from earlier versions. Deactivation hook also clears it.
- **Tracking snippet placeholder.** `Campaigns.tsx` (step 3) and `Tracking.tsx` emitted the literal string `<<paste your Site Key from Hub>>` even when the API already returned the site key. Snippet now uses the real value when available, falls back to a clearer placeholder pointing to where in Hub the key lives.
- **Slug cache TTL.** Reduced from 1 hour to 5 minutes so direct edits in the Hub UI propagate before printed-postcard tests fail.
- **Dashboard "Peanut Suite" stat card** relabeled "Marketing Suite" with copy clarifying it refers to an *optional companion plugin*, not this plugin's own status. First-time users were reading "Not Installed" as a problem with Peanut End to End itself.
- **Removed `alert()` modal** from `saveAndExit` in the campaign wizard. The existing "Draft saved just now" header text already conveys the state without blocking the page.

### Removed
- **Legacy `options-general.php?page=peanut-connect` settings page.** It read pre-Hub option names (`peanut_connect_manager_url` / `peanut_connect_site_key`) and falsely reported "Not connected" on working Hub installs. The React SPA at the top-level menu is the canonical configuration surface now.

## [3.7.16] - 2026-05-11

### Fixed
- **Empty Done step.** When Hub returned a 2xx response without a `campaign` payload, the wizard set `step=3` but `result=null`, so the entire left column on the Done page was blank. `buildCampaign` now throws on missing payload (so `onError` fires and the user sees an inline error). When `step===3 && !result`, a fallback card explains the situation and offers a "Build another" reset + link to the Links tab to confirm the link still got created.
- **Stale Preview copy on Done.** The "Preview" card's description was hardcoded to `Updates as you fill in Step 1.`, which is wrong at every later step. Now per-step: "Campaign details (no result payload returned by Hub)" at step 4, "Last review before submit" at step 3, "Short link + QR details on the next step" at step 2.
- **`Request failed with status code 405` on Campaign Analytics.** The plugin's `journey_stats` proxy was forwarding to Hub as `POST` (an older WAF workaround), but Hub registered `/journeys/stats` as `GET`. Now matches.

## [3.7.15] - 2026-05-11

### Added
- **Short-link redirect handler.** `https://yoursite.com/<slug>` now actually redirects through Hub. New `Peanut_Connect_Short_Links` class hooks `template_redirect` priority 1, catches 404s on single-segment URIs, validates the slug against a cached list of active slugs fetched from Hub (`GET /api/v1/marketing/links?active=1`), and 302-redirects to `{hub_url}/go/{slug}` so Hub handles UTM expansion + click tracking. Active slugs cached for 1 hour (reduced to 5 min in 3.7.17). Cache busted automatically on every link create/update/toggle/delete and on campaign creation.

## [3.7.14] - 2026-05-11

### Added
- **Copy full UTM URL button on Links table.** Each row now has a second copy icon (next to the existing short-link copy) that copies the full UTM-tagged destination URL — useful for systems that don't follow redirects.

## [3.7.13] - 2026-05-11

### Fixed
- **Paste URL now also fills the Campaign name.** Pasting a pre-built UTM URL in the campaign builder pre-fills `Campaign name` from `utm_campaign` (only when the name field is empty), so the Continue button enables in one shot instead of forcing the user to retype the campaign identifier.

## [3.7.12] - 2026-05-11

### Added
- **Paste-URL field in the campaign builder.** New input at the top of the wizard's Step 1 accepts a fully-tagged third-party URL (e.g. `https://example.com/landing?utm_source=usa&utm_medium=banner&utm_campaign=...`) and auto-fills the destination URL + all 5 UTM fields below. Lets users import pre-built UTMs from third-party builders without splitting params by hand.

## [3.7.11] - 2026-05-04

### Internal
- Plumbing release bumped between 3.7.10 and 3.7.12 work. No customer-visible changes.

## [3.7.10] - 2026-05-04

### Changed
- **Rebranded to "Peanut End to End."** Plugin Name in the WordPress header, admin menu, sidebar version footer, dashboard, settings tooltips, exported reports, self-updater plugin info, and remote-deactivate/delete error messages now read "Peanut End to End." Slug, REST namespace, option keys, mu-plugin, and license-server endpoints all remain `peanut-connect` for backwards compatibility with existing installs and the auto-update flow. README's stale 1.x changelog block was retired in favor of this file. WordPress.org `Stable tag` corrected from `3.3.3` to `3.7.10` (had drifted ~6 minor releases). License-server mu-plugin updated so update notifications display the new product name.

### Fixed
- **Phantom-version recovery.** Versions 3.7.5–3.7.9 had been bumped, packaged into `dist/`, and documented in CHANGELOG locally, but the source for them was never committed and the GitHub release flow was never invoked, so customers stayed on 3.7.4. This release is the honest catchup — all 3.7.5–3.7.9 source (campaigns wizard, UTMs, Tracking, Analytics, Sankey/Funnel/Donut/TimeSeries charts, marketing PHP proxy WAF fixes) is now in git and reaches customers via the standard auto-update flow.

### Build pipeline
- **Divergence guards** added so this can't recur:
  - `package.sh` refuses to run on a dirty working tree or when the version constant doesn't match `HEAD`'s committed version. `PEANUT_PACKAGE_FORCE=1` overrides for genuine archival cases.
  - `bump-version.sh` warns loudly when bumping a dirty tree and points the operator at `release.sh` for the end-to-end flow.
  - `release.sh` now runs `npm run build` automatically before the version-bump commit, so the SPA bundle and source ship in the same commit (no force-push amend pattern).
  - Commit-message format aligned with the Conventional Commits hook so `release.sh` runs end-to-end without manual intervention.

### Repo hygiene
- **`composer.phar` (3.3 MB) and `composer-setup.php`** removed from version control; added to `.gitignore`.

## [3.7.9] - 2026-04-29

### Fixed
- **503 when filtering Analytics by a single campaign.** The host's mod_security WAF was rewriting `?campaign=...` GETs from Hub to 406, which then surfaced as 503 to the SPA. Marketing proxy now sends `journey_stats` filters as a POST body instead of a query string, bypassing the WAF entirely. Hub's `/journeys/stats` route now matches both GET and POST. WAF status normalization in `forward()` widened from 4xx to all 4xx/5xx so any future host quirk is also recovered.
- **Sankey overflow.** The chart was clipping the bottom row when many small campaigns shared a column. Inner height now subtracts gap-space before sizing bars, and the SVG height auto-grows with the largest column so all nodes are visible.
- **Sankey channel labels readability.** Middle-column labels ("email", "cpc", "referral") moved above their bars and given a white text-stroke so they no longer get lost behind incoming ribbons.

## [3.7.8] - 2026-04-29

### Added
- **Volume-over-time chart** on the Analytics page — daily journeys (filled indigo area) + conversions (emerald line) across the selected date window. Tells the campaign-launch-spike story.
- **Devices card** — donut showing desktop / mobile / tablet split.
- **Top regions card** — horizontal bars by country/region, derived from journey events.
- **Sankey diagram** — three-column flow (Campaign → Channel → Outcome). Outcome ribbons colored by status: emerald for converted, amber for in-progress, red for abandoned.
- Hub `/journeys/stats` now returns `time_series`, `devices`, `regions`, and `sankey` (nodes/links) alongside the existing aggregates.

### Polish
- **Wizard steps are clickable** — every step in the top bar is a button. Forward navigation is gated on prerequisites; reachable steps highlight on hover, unreachable steps grey out with a tooltip.
- **Save & return later** — wizard auto-saves drafts to localStorage; a "Draft saved Xs ago" indicator and explicit "Save & exit" button live next to the page header. Reopening Campaigns picks up exactly where you left off, with a "Start fresh" link to discard.
- Demo seeder now stamps Virginia / North Carolina / Maryland on journeys so the regions card has Dominion-realistic data.

## [3.7.7] - 2026-04-29

### Added
- **Trapezoidal funnel chart** — replaces the horizontal-bar funnel on the Analytics page with a real narrowing-trapezoid SVG. Each stage's width is proportional to count, so the visual itself communicates drop-off.
- **Campaign filter + compare mode.** Multi-select dropdown at the top of Analytics. Pick one to filter the whole page to that campaign; pick two or more to flip into a compare view that renders side-by-side mini-funnels at the same scale plus per-campaign Journeys / Enrollments / Conv. rate.
- Hub `/journeys/stats` now accepts a `campaigns[]` array param in addition to the existing single `campaign` filter.

## [3.7.6] - 2026-04-29

### Added
- **Reach + Cost on the campaign wizard.** New optional fields in Step 1 — "Reach (sent / impressions)" and "Cost (USD)" — so the funnel and cost-per-acquisition KPIs populate from real numbers when a campaign is built, not just from the seeded demo data.
- **Pencil-edit button on the UTMs page.** Inline modal with: Reach, Cost, Notes (safe-to-edit), plus a collapsed "advanced" section for Source / Medium / Campaign / Destination URL with a warning when the campaign already has clicks (changing those breaks attribution for clicks already in the system).

## [3.7.5] - 2026-04-29

### Added
- **Conversion funnel chart on Analytics page.** Stages: Sent → Clicked → Landed → Clicked Enroll → Enrolled, each rendered as a horizontal bar proportional to the top stage with drop-off % between stages. Plus three new KPI cards: Cost / Acquisition, Click-Through Rate, total cost.
- Hub's `/journeys/stats` now returns a `funnel` array, `cost_total`, `cost_per_acquisition`, and `click_through_rate` derived from `Utm.send_count` / `campaign_cost`, `LinkClick` counts, the `click_to_portal` custom event, and converted journeys.

### Changed
- Tracker bootstrap snippets in the Campaigns wizard and Tracking page now reference `phub` instead of `pnut`, matching the renamed global in `tracker.min.js` (3.7.x). Backward-compat alias preserved.

### Fixed
- Conversion-rate KPI on the Analytics page displayed `1316.0%` instead of `13.2%` because it was multiplying an already-percent value by 100 again.

## [3.7.4] - 2026-04-28

### Fixed
- `find_plugin_file` lookups failed when the plugin folder was upper-cased (e.g. `PEANUT-CONNECT/`) or version-suffixed (e.g. `peanut-connect-3.3.9/`), making Hub-driven self-updates return "Plugin not found" on those installs. Lookup is now case-insensitive across plugin file, dirname, and basename, with a versioned-folder fallback that tolerates a trailing `-<version>` suffix.

### Added
- `/hub/check-updates` endpoint — Hub-authenticated mirror of the existing admin-only `/admin/check-updates`. Lets Hub force a fresh `update_plugins` / `update_themes` transient on a remote site before triggering an update, so updates pick up versions newer than the last cron-driven check rather than whatever the stale transient knew about.

## [3.7.3] - 2026-04-28

### Fixed
- Tracking Setup page rendered "This site isn't connected to a Hub install yet" with empty Hub URL / Site Key / Tracker Script even on connected sites. The `/marketing/tracking-setup` endpoint returned a flat `{success, connected, hub_url, ...}` envelope, but the SPA's axios interceptor expects `{success, data: {...}}` and spreads `data.data` into the response — flat shape meant every field was dropped. Re-shaped the response to nest its payload under `data` so the interceptor unwraps it correctly.

## [3.7.2] - 2026-04-28

### Fixed
- UTMs and Links tabs displayed "No active UTMs / links" even when the Hub proxy returned data. The axios response interceptor in `client.ts` already spreads `data.data` into `response.data` (so `res.data` is the Paginated object directly), but `marketing.ts`'s `listUtms` / `listLinks` were doing `res.data.data` — unwrapping a second time and dropping the pagination wrapper. The page components then read `data?.data` from what they thought was Paginated, got `undefined`, and rendered the empty state. Fixed by removing the redundant `.data` access in both list functions.

## [3.7.1] - 2026-04-28

### Fixed
- Manual "Verify & Connect" failed silently and the wizard kept reporting "site isn't connected to a Hub install" because Hub's host (cPanel / ImunifyAV) rewrites successful 2xx responses to 406 for some endpoints while passing the body through unchanged. Connect was rejecting on status code and ignoring the body's `success` field.
- Both `manual_connect_to_hub` and the marketing proxy's `forward()` now treat a `success: true` JSON body as authoritative and normalize the upstream status to 200 in that case. Falls back to status-code-based handling only when the body is unparseable or doesn't contain `success`.

## [3.7.0] - 2026-04-28

### Added
- **Manual Hub-key mode in Settings.** New "Use existing API key" toggle alongside the auto-connect form. Pastes a Hub URL + 64-char API key, verifies them against Hub's `/sites/verify`, and only saves on a 2xx — used for sites already registered in Hub (where auto-connect refuses to overwrite an active key).
- **QR code on the Campaigns wizard's Done step.** Renders an inline SVG QR for the freshly-created short link with download-as-SVG and download-as-PNG buttons. Generated client-side via the `qrcode` npm package — no external service.

## [3.6.1] - 2026-04-28

### Fixed
- Health page reported "1 plugin updates available" even right after a manual zip install brought the plugin to the latest version. Cause: WordPress's `update_plugins` site transient doesn't always clear stale "update available" entries after a manual upload-install, so the Health module was trusting it blindly. Now defensively compares `new_version` against the actually-installed version with `version_compare` and only counts an entry when there's a real upgrade pending. Same fix applied to themes.

## [3.6.0] - 2026-04-28

### Added
- Campaigns page is now a four-step wizard: (1) Campaign basics — name, destination, source/medium/campaign, optional content/term; (2) Short link — pick a custom slug or auto-generate; (3) Tracking — pre-filled GTM tag snippets ready to paste, with a heads-up if the same site already has a previous campaign's tags installed; (4) Done — short link, full UTM URL, copy + open-in-new-tab, plus an inline next-steps checklist (incognito test → confirm GTM → watch Analytics → use the link).
- Live "Preview" panel on the right that fills in as you type Step 1, then flips to a "Campaign summary" once the campaign is built.

## [3.5.2] - 2026-04-28

### Fixed
- Health page reported `SSL Not Enabled — Critical` on sites that are clearly served over HTTPS. The check relied on opening an outbound `ssl://host:443` socket to introspect the certificate, but many managed hosts block outbound `:443` so the probe failed and the result reported SSL as disabled. The site URL being `https://` is now treated as authoritative for `enabled` / `valid`; the socket probe still runs as best-effort to populate cert details (issuer, days until expiry) when the host allows it.

## [3.5.1] - 2026-04-28

### Fixed
- Release zip was being rejected by hosting WAFs because it bundled the dev-only `vendor/` tree, including `phpcs.bat` / `phpcbf.bat` Windows batch wrappers from PHPCS. The fix is procedural: **always use `bash scripts/package.sh` to build releases** — the script does a whitelisted copy of just runtime files (`peanut-connect.php`, `readme.txt`, `includes/`, `admin/`, `assets/`) and never includes `vendor/`. Earlier zips were built by hand and accidentally swept it in.

## [3.5.0] - 2026-04-28

### Added
- Marketing surface in wp-admin: Campaigns (campaign builder), UTMs, Links, Analytics, Tracking Setup pages.
- `Peanut_Connect_Marketing` server-side proxy at `/peanut-connect/v1/marketing/*` that forwards authenticated requests to the connected Hub install. The Hub API key never leaves the server.
- Tracking Setup page renders the connected Hub URL, masked Site Key, and the two GTM tag snippets (tracker loader + conversion fire) ready to paste.

## [3.4.1] - 2026-03-30

### Fixed
- Security Hardening toggles not working due to double-unwrapped API response in securityApi.get()

## [3.2.2] - 2026-02-02

### Fixed
- SSL detection now actually checks for certificate instead of relying on WordPress URL setting
- Sites with SSL certificates now correctly show as SSL enabled even if WordPress URL is http://

## [2.6.3] - 2026-01-11

### Added
- Rate limiting on tracking endpoints (track, identify, conversion, popup-interaction) to prevent abuse
- Daily cleanup cron job for synced records older than 90 days

### Changed
- Tracking endpoint rate limits: 120/min for events, 60/min for popups, 30/min for identify/conversion

## [2.6.2] - 2026-01-11

### Fixed
- Hub Mode disable_suite now fully disables Suite at file load time (early filter registration)

## [2.6.1] - 2026-01-11

### Fixed
- Hub Mode hide_suite now correctly hides Peanut Suite menu (was using wrong menu slug)

## [2.6.0] - 2026-01-11

### Added
- Hub Mode feature to control Peanut Suite behavior when connected to Hub
  - Standard: Suite works normally alongside Hub
  - Hide Suite Menu: Hides Peanut Suite from admin menu
  - Disable Suite: Fully disables Peanut Suite
- New `/settings/hub/mode` API endpoint

## [Unreleased]

### Added
- Rate limiting for API endpoints to prevent abuse
- Protected error log directory with .htaccess
- PHPUnit test infrastructure with Auth and Rate Limiter tests
- Vitest frontend testing setup
- OpenAPI 3.0 documentation
- React error boundary for graceful error handling
- Server-side activity logging
- Comprehensive inline documentation

### Changed
- Improved SQL query security with parameterized LIKE patterns
- Enhanced error log storage location (now in protected directory)

### Security
- Added rate limiting to bearer token authentication
- Parameterized SQL queries in self-updater cache clearing
- Protected error log files from direct web access

## [2.1.3] - 2024-12-31

### Added
- Release automation scripts (bump, package, release)
- npm scripts for version management
- GitHub release automation

### Fixed
- Version sync across all plugin files
- Duplicate file cleanup from iCloud sync

## [2.1.2] - 2024-12-30

### Changed
- Self-updater uses path parameters instead of query strings
- Improved update check caching

### Fixed
- Self-updater initialization timing for reliable update detection

## [2.1.1] - 2024-12-30

### Added
- React SPA admin interface
- Dashboard with connection status and health summary
- Health monitoring page with detailed system information
- Updates page for managing plugin/theme/core updates
- Error log viewer with filtering and export
- Settings page with permission controls
- Activity log (client-side)

### Changed
- Moved from legacy settings page to modern React interface
- Improved health check data collection

## [2.0.0] - 2024-12-29

### Added
- Error logging system for PHP errors and fatals
- Self-hosted auto-update system via peanutgraphic.com
- Peanut Suite integration for analytics sync

### Changed
- Major refactoring of API structure
- Improved authentication flow

## [1.0.0] - 2024-12-28

### Added
- Initial release
- Site health monitoring
- Plugin/theme update detection
- Remote update capability
- Secure token-based authentication
- Permission controls for manager actions
- Peanut Suite analytics integration

[Unreleased]: https://github.com/peanutgraphic/peanut-connect/compare/v2.1.3...HEAD
[2.1.3]: https://github.com/peanutgraphic/peanut-connect/compare/v2.1.2...v2.1.3
[2.1.2]: https://github.com/peanutgraphic/peanut-connect/compare/v2.1.1...v2.1.2
[2.1.1]: https://github.com/peanutgraphic/peanut-connect/compare/v2.0.0...v2.1.1
[2.0.0]: https://github.com/peanutgraphic/peanut-connect/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/peanutgraphic/peanut-connect/releases/tag/v1.0.0
