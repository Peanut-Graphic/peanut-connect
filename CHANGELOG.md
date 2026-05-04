# Changelog

All notable changes to **Peanut End to End** (slug: `peanut-connect`) will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
