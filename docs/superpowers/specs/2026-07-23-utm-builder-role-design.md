# End-to-End Plugin — Scoped "UTM Builder" Role (Phase 2)

_Date: 2026-07-23 · Status: design for review · Depends on: Phase 1 nav reorg (feat/ia-reorg-phase1)_

## Summary

Let a coworker log into WordPress with a limited **"UTM Builder"** role that opens **only** the UTM + QR + short-link builder — nothing else in the plugin, and nothing else in wp-admin. The security boundary is enforced **server-side** (a new capability + a scoped REST permission gate on only the builder's endpoints); the builder-only SPA view is the matching UX on top. No changes to how admins use the plugin.

## Problem

The whole plugin is gated on `manage_options` (the admin menu and **every** REST route share one `check_admin_permission` callback). So today the only way to let a coworker build UTMs is to make them a full administrator — which also hands them analytics, settings, the Hub connection/disconnect, updates, and the rest of the WordPress site. There is no middle ground.

## Goals

- A coworker can be given a **normal WP login** that reaches **only** the UTM/QR/short-link builder.
- The limit is a real **server-side** boundary: a UTM-Builder user calling any non-builder REST route gets **403**, and sees no other wp-admin menus beyond what `read` grants.
- Admins are unaffected — same full plugin, same access.
- Least privilege: the builder role can **create and view** UTMs/short links and generate QR codes; it can **not** read analytics/journeys, change settings, touch the Hub connection, run updates, or delete/disable others' links.

## Non-goals

- Not a public/tokenized tool and not a second login system — it uses WordPress's own auth (the approved "limited WP role" model).
- No new UTM/QR *functionality* — it reuses the existing Campaigns wizard (UTM fields + short link + `qrcode` generation). Phase 2 is access-scoping + a focused view, not new builder features.
- Phase 3 items (home redesign, Sankey, "Clicked enroll") are unrelated.

## Design

### 1. Capability + role (PHP)

- New capability string: **`peanut_connect_build_utms`**.
- On plugin **activation** (and on version upgrade to be safe):
  - Create a role **`peanut_utm_builder`** (display name "UTM Builder") with caps `read` + `peanut_connect_build_utms`. `read` lets them reach wp-admin at all; nothing else.
  - Grant `peanut_connect_build_utms` to the **`administrator`** role too, so admins also satisfy the builder gate (they keep full access via `manage_options`).
- On **uninstall** (not deactivate — avoid disrupting a temporarily-disabled plugin): remove the role and strip the cap from administrator. (Deactivate leaves them in place.)

### 2. Two permission tiers (PHP REST)

- Keep the existing `check_admin_permission()` = `current_user_can('manage_options')` on **every route it guards today**, unchanged.
- Add **`check_builder_permission()`** = `current_user_can('manage_options') || current_user_can('peanut_connect_build_utms')`.
- Apply `check_builder_permission` to **only** these builder endpoints (everything else stays admin-only):

| Route | Methods | Why the builder needs it |
|---|---|---|
| `/marketing/campaigns` | POST | The build wizard's "create" (UTM + short link in one call) |
| `/marketing/utms` | GET, POST | List existing UTMs (to reuse/avoid dupes) + create |
| `/marketing/links` | GET, POST | List + create the short link that carries the UTMs |

Explicitly **NOT** builder-accessible (remain `manage_options`): all analytics/journeys/dominion-funnel/gtm-coverage reads, `/marketing/utms/{id}` edit/delete, `/marketing/links/{id}` edit/delete/toggle, archive/restore, tracking-setup, settings, disconnect, updates, health, errors, activity, videos. A builder hitting any of these gets 403.

**This server-side split is the actual security boundary.** The SPA restriction below is defense-in-depth / UX, not the guarantee.

### 3. Menu access (PHP)

- Change `add_menu_page(...)`'s capability from `'manage_options'` to **`'peanut_connect_build_utms'`** — both admins (granted the cap) and builders can open the plugin; no other user can.
- `render_react_app()` currently mounts the SPA container; it stays, but the gate check inside (if any hard `manage_options` die) must accept builders too.

### 4. Mode signalling (PHP → SPA)

Extend `wp_localize_script('...', 'peanutConnect', [...])` with:

```php
'mode' => current_user_can('manage_options') ? 'full' : 'builder',
```

So `window.peanutConnect.mode` is `'full'` for admins, `'builder'` for the UTM-Builder role.

### 5. Builder-only SPA view (React)

- At the app entry (the top-level render), branch on `window.peanutConnect.mode`:
  - `'full'` → the normal app (Phase 1 grouped-sidebar `Layout` + all routes) — **unchanged**.
  - `'builder'` → a standalone **`UtmBuilderApp`**: no sidebar, no groups, no other routes — just a clean header + the builder. It renders the existing Campaigns wizard component (the UTM + short-link + QR flow) inside a minimal shell. Any attempt to navigate elsewhere stays on the builder.
- The builder view reuses the **existing** Campaigns wizard and its `qrcode` QR generation — no duplicated builder logic.

## Security posture (explicit)

- **Server-side is the gate.** Even if a builder user crafted requests to admin-only endpoints, the permission callbacks 403 them. The SPA mode only decides what UI to *render*.
- **Least privilege by default.** The builder gets create+list on exactly three route groups; no edit/delete/toggle, no reads of anyone's analytics or settings.
- **Nonce/auth unchanged.** Builders authenticate with the same `wp_rest` nonce as any logged-in user; the difference is purely capability checks.
- **No cap leakage on uninstall.** The administrator cap grant and the role are cleaned up on uninstall.

## Testing

- **PHP — role/cap lifecycle:** activating creates the `peanut_utm_builder` role with exactly `read` + `peanut_connect_build_utms`; administrator gains the cap; uninstall removes both.
- **PHP — permission matrix (the critical test):** with a user who has only `peanut_connect_build_utms`:
  - `check_builder_permission()` → true; `check_admin_permission()` → false.
  - the three builder routes' permission callbacks resolve to the builder gate; a representative admin-only route (e.g. settings/disconnect, analytics) resolves to the admin gate.
  - Assert a builder-cap user is **allowed** on `/marketing/utms` POST and **denied** on an admin-only route.
- **JS — mode branching:** with `window.peanutConnect.mode = 'builder'`, the app renders the builder shell and **not** the sidebar/groups; with `'full'`, it renders the normal Layout. The builder shell renders the Campaigns wizard.

## Ship plan

Standard plugin flow (branch → PR → CI → signed release). Because it adds a role/capability, it's a **minor** bump. Depends on Phase 1 landing first (the `'full'` path renders Phase 1's Layout); implement stacked on `feat/ia-reorg-phase1` or after it merges. Reminder: commit the rebuilt `assets/dist` (the signed publish does not rebuild the SPA).

## Open decisions for review

1. **Least-privilege scope** — is create+list on campaigns/utms/links the right set, or should the builder also be able to **edit/delete their own** UTMs/links? (Default: create+list only, safest.)
2. **Builder view** — reuse the full **Campaigns wizard** as-is inside the minimal shell (recommended, zero new builder code), or a trimmed single-screen builder? (Default: reuse the wizard.)
3. **Role name** — "UTM Builder" / `peanut_utm_builder` OK, or a different label?
