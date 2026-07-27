=== Peanut End to End ===
Contributors: peanutgraphic
Tags: campaigns, marketing, utm, popups, monitoring, updates, analytics, forms, tracker
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 8.0
Stable tag: 3.29.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

End-to-end campaign and site platform for WordPress — campaigns, UTM links, popups, forms, tracker, plus health, updates, and backups. Wired to a Peanut Hub.

== Description ==

Peanut End to End runs marketing campaigns and on-site experiences directly from your WordPress install — and pairs that with the site health, update, and backup tooling that used to live in the older "Hub Connect" connector. The plugin slug remains `peanut-connect` for backwards compatibility with existing installs and update flows.

**Campaigns and on-site marketing:**

* Campaign wizard with auto-saved drafts and clickable step navigation
* UTM-tagged short links and QR codes
* Popups (modal, slide-in, bar, toast, fullscreen) and event banners — server-rendered so they survive ad-blockers
* First-party tracker (pageviews, scroll, conversions, form submissions)
* Form capture with proxy to Peanut Hub
* In-plugin analytics: campaigns, journeys, funnels, Sankey flows, devices, regions, time-series
* Demo seeder for onboarding with realistic sample data

**Site health and management:**

* Site health monitoring (WordPress / PHP / MySQL versions, SSL, disk, memory)
* Plugin / theme / core update visibility and remote-trigger
* Backup integration
* ML-flavored anomaly detection on health metrics
* Security hardening (disable XML-RPC, hide WP version, custom login URL, disable comments)
* Local error log and activity log

**Hub link:**

* Marketing API proxy to Peanut Hub (campaigns and analytics live in Hub)
* Site-key authentication (SHA-256 hashed)
* Hub Mode (standard / hide-Suite / disable-Suite) for coexistence with Peanut Suite
* Manual Hub-key onboarding fallback

**How It Works:**

1. Install Peanut End to End on your site
2. Generate a site key from Settings > Peanut End to End
3. Pair the site to your Peanut Hub using the site key
4. Build campaigns, monitor health, and manage updates from the Hub dashboard

== Installation ==

1. Upload the `peanut-connect` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > Hub Connect
4. Generate your site key
5. Copy the key and add this site to your Peanut Monitor dashboard

== Frequently Asked Questions ==

= Is this plugin secure? =

Yes. All communication between your site and the manager requires authentication with a unique 64-character site key. You can regenerate the key at any time if compromised.

= Can I control what the manager can do? =

Yes. You can enable or disable specific permissions:
- Health checks (always allowed)
- View updates (always allowed)
- Perform updates (optional)
- Access analytics (optional)

= Does this work without Peanut Suite? =

Yes. Peanut End to End works on any WordPress site. If Peanut Suite is also installed, the Hub Mode setting controls how the two coexist (standard, hide-Suite, or disable-Suite).

= Will this slow down my site? =

No. The plugin only loads what each page needs — the tracker is a small first-party script, popups and banners are conditional, and admin endpoints only respond to authenticated API requests from your paired Hub.

== Changelog ==

= 3.21.1 =
Security hardening (microscope remediation, no functional changes):
* Enforce signed Hub requests automatically after the first verified request (removes the legacy unsigned-Bearer fallback once the Hub has proven it can sign).
* Fail closed when the Hub key cannot be encrypted at rest — never store it in plaintext.
* Exact-match plugin resolution + version-suffix-aware protected-plugin guard, so a remote update/activate cannot be steered to the wrong plugin.
* Public-endpoint hardening: rate-limit + opt-out on the GTM beacon, size bound on popup-interaction data, IPv4+IPv6 SSRF guard on Hub-host checks, auth rate-limit keyed on client IP, and feedback CRUD raised to Editor capability.

= 3.21.0 =
* New: Mark It Up per-site access modes — Everyone with edit access (default), Specific users, Review link only, or Off.
* New: user checklist on the Mark It Up settings page for the Specific users mode.
* Fix: logged-in reviewers without edit access (Specific users mode) now authenticate their widget requests correctly.

= 3.20.0 =
* Mark It Up: reviewers can now edit or delete a note they authored, scoped per-browser — no login required.
* Added a first-run walkthrough that introduces the review workflow once, then stays quiet on later visits.
* Added a This page / All pages tab switch, so you can see a site-wide notes summary alongside the current page's notes; a `?pp_note=<id>` link now deep-links straight to a note and pulses its marker. Notes marked resolved on the Hub side also show a "Handled ✓ · name · date" line.
* Touch polish: panel drag now works with touch (pointer events), and interactive targets are sized for touch input.

= 3.19.2 =
* Fix: reviewers arriving via the site-wide review cookie could not post notes after the first page — the widget now receives the validated token on every page.

= 3.19.1 =
* Fixed (for real this time): the "Check for updates" admin action still returned a critical error after 3.18.0 — the actual cause was an undefined `PEANUT_CONNECT_FILE` constant referenced in that endpoint, which fatals on PHP 8. It now uses the correct plugin-file path. (3.18.0 fixed a related missing-includes issue but not this one.)

= 3.19.0 =
* Mark It Up review links now **follow the reviewer across the whole site**. Previously a client reviewer only saw the widget on pages whose URL still carried `?pp_review=…`; clicking a plain nav link dropped it. Now, once they open one tokenized link, a cookie keeps review mode on for 30 days as they browse — no need to re-add the token to every page. (Logged-in agency users already got this automatically.) The cookie only holds the same token from the link, so it grants no extra access.

= 3.18.1 =
* Fixed: the on-page widget script (feedback.js) was served with a fixed cache version, so browsers kept the old copy after a plugin update — new Mark It Up features (like drawing) wouldn't appear until a hard refresh. It's now versioned by the plugin version, so each update refreshes it automatically. (The 3.18.0 drawing feature ships properly with this.)

= 3.18.0 =
* Mark It Up now has **freehand drawing**: click **✎ Draw** in the panel, drag to circle or scribble on the page, then add a note — the drawing relays to Hub and re-renders (scaled to the page width) for everyone. Click ✎ Draw again to stop.
* Fixed: the note tooltip could appear far from its marker (up in the header) once you'd scrolled down the page — it now opens right at the marker. The "?" marker also anchors to the start of the highlighted text.
* Fixed: the "Check for updates" admin action returned a critical error (the endpoint called admin-only functions that aren't loaded in a REST request); it now loads them first.

= 3.17.0 =
* Mark It Up now supports **text highlighting**: select text on any page and click "Note on this" to highlight it (yellow) and attach a note — shown as a red "?" marker with a dark note tooltip when you click it. Point-at-a-spot notes still work for images and buttons. Highlights re-anchor after the page reloads.
* Added an **open-count badge** on the "+ Mark it up" button so you can see unresolved feedback at a glance.

= 3.16.0 =
* Renamed the on-page feedback widget to **Mark It Up**, recolored it off dark blue so it stands out against your site, and added an in-widget "?" quick how-to.
* Added: a **Mark It Up** admin page (under the End-to-End menu) to set/generate the per-site review token and copy the client review link — no more editing options by hand. Appears when the site is connected to Hub.

= 3.15.0 =
* Added: On-page visual feedback — in review mode, reviewers can drop pinned notes and checkable to-dos directly on any page, each reviewer color-coded, and everything relays to your paired Hub for triage. Gated behind a Hub connection and a per-site review token, and dormant on sites that don't enable it; the widget UI is isolated in a Shadow DOM so it can't affect your theme.
* Fixed: relay no longer sends a Content-Type header on bodyless GET requests, which Hub was routing to the wrong handler (would have broken the feedback list/replies fetches).

= 3.14.3 =
* Added: podcast publish now sets the WordPress post excerpt, featured image (sideloaded from episode/podcast artwork), and Yoast focus keyphrase — previously only the meta description was applied. Additive and backward-compatible (each field applied only when sent).

= 3.14.2 =
* Fixed: fatal SodiumException (HTTP 500 site-wide) on hosts without the native libsodium PHP extension — encrypt-at-rest (3.13.0) called sodium_memzero() unconditionally, which the WordPress sodium_compat polyfill throws on. The three calls are now guarded with extension_loaded('sodium'). No change to encryption behavior.

= 3.11.6 =
* Security (P2-2): the tracker snippet no longer exposes the Hub bearer — tracking_setup now returns the separate public tracker_key (delivered by Hub on heartbeat) instead of the privileged API key. Also repairs tracking on non-plugin pages, which were silently dropped because the snippet carried the wrong key.

= 3.11.5 =
* Security roll-up (supply-chain incident response + audit remediation): removed a poisoned package-lock.json entry (fabricated axios injecting the plain-crypto-js typosquat) + added .npmrc ignore-scripts; fixed an authenticated /restore RCE (SHA-256 backup allowlist + zip-slip); hardened /banner against site-wide CSS/HTML injection; P1 hardening (no Hub-key logging, SSRF guards on connect flows, spoof-proof rate-limit IP, unguessable backup filenames); and added optional HMAC request signing (anti-replay, key not in transit).

= 3.9.3 =
* Added: tracker auto-emits a `click_to_portal` custom event on primary-CTA clicks (Enroll / Apply / Register / Sign-up / Get-started / etc.). Hub's campaign funnel can now count the "Clicked enroll" stage automatically — no per-site wiring required.

= 3.9.2 =
* Added: "Edit funnel in Hub →" deep-link from Analytics campaign view

= 3.8.0 =
* Add Videos module: register WP-media or external-URL videos with Hub and embed via [peanut_video] shortcode or the Peanut Video block
* View engagement analytics (plays, unique viewers, avg watch, completion, drop-off) in the Connect admin without leaving WordPress

= 3.0.9 =
* Fix toggle button labels for clarity (On/Off instead of confusing Enabled/Disabled)
* Toggle now shows green when security feature is active

= 3.0.8 =
* Fix CSS bleed affecting WordPress admin menu
* Scope Tailwind styles to prevent interference with WP admin

= 3.0.7 =
* Add Security Hardening section to Settings page
* Add Hub Permissions section to control what Hub can access
* Security features: Disable XML-RPC, Hide WP Version, Disable Comments, Custom Login URL
* Hub permissions: Allow/deny remote updates and analytics access
* Add Track Logged-In Users toggle

= 3.0.6 =
* Add Visitor Tracking toggle to Settings page
* Enable/disable pageview and visitor tracking from UI
* Tracking data syncs to Hub for Top Pages and Traffic Sources analytics

= 3.0.5 =
* Add Debug & Logging section to Settings page
* Display error counts and quick access to Error Log
* Toggle error logging on/off from Settings

= 3.0.4 =
* React SPA admin interface improvements
* Bug fixes and performance improvements

= 3.0.0 =
* Complete React SPA admin interface
* Dashboard with health summary, updates, and Hub status
* Health monitoring page with detailed checks
* Updates page with one-click update management
* Activity log for tracking site events
* Error log with filtering and export
* Settings page with Hub connection management
* Hub Mode feature (standard, hide Suite, disable Suite)

= 2.6.5 =
* Fix SSL detection in WP-CLI context
* End-to-end sync verification with Hub

= 2.6.0 =
* Hub Mode feature - control Peanut Suite behavior when connected
* Early filter registration for disable_suite mode

= 2.3.0 =
* NEW: Hub integration for centralized agency management
* NEW: Visitor tracking with cookie-based identification
* NEW: Event tracking (pageviews, scroll depth, form submissions)
* NEW: UTM parameter capture and attribution tracking
* NEW: Conversion tracking API
* NEW: Hub-managed popup system with multiple types (modal, slide-in, bar, toast, fullscreen)
* NEW: Automatic data sync to Peanut Hub (every 15 minutes)
* NEW: Frontend tracking JavaScript
* Database tables for local event queuing

= 1.0.0 =
* Initial release
* Site health monitoring
* Plugin/theme update management
* Peanut Suite analytics integration
* Permission controls

== Upgrade Notice ==

= 2.3.0 =
Major update: Connect to Peanut Hub for centralized agency management with visitor tracking, attribution, and hub-managed popups.

= 1.0.0 =
Initial release of Hub Connect.
