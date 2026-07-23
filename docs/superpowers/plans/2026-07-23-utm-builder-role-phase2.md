# Scoped "UTM Builder" Role (Phase 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a limited "UTM Builder" WordPress role whose users can reach only the UTM/QR/short-link builder — enforced server-side by a scoped capability + REST permission gate, with a matching builder-only SPA view.

**Architecture:** A new `Peanut_Connect_Roles` PHP class owns a `peanut_connect_build_utms` capability and a `peanut_utm_builder` role; admins get the cap at runtime via a `user_has_cap` filter (update-safe, no stored grant). A second permission callback `check_builder_permission()` guards only the three builder REST endpoints; everything else stays `manage_options`. The SPA receives a `mode` flag and, in `builder` mode, renders only the existing Campaigns wizard inside a sidebar-less Layout.

**Tech Stack:** WordPress plugin PHP (PHPUnit 9.6 + `tests/mocks/wordpress-mocks.php`); React 18 + TS + Vite (Vitest + @testing-library/react).

## Global Constraints

- Capability string: **`peanut_connect_build_utms`**. Role: **`peanut_utm_builder`** (display "UTM Builder"), caps exactly `read` + the builder cap.
- **Admins satisfy the builder cap via a runtime `user_has_cap` filter** — NOT a stored role grant (so a plugin update can never lock admins out of the menu).
- **Least privilege:** the builder gate guards ONLY `/marketing/campaigns` POST, `/marketing/utms` GET+POST, `/marketing/links` GET+POST. Every other route stays `manage_options`. No edit/delete/toggle for builders.
- **Server-side is the security boundary;** the SPA `mode` only chooses what UI to render.
- Reuse the existing `Campaigns` wizard verbatim; no new builder logic.
- Release constraint: commit the rebuilt `assets/dist` (signed publish does not rebuild the SPA).
- PHP tests run via `composer test`; the mock `current_user_can($cap)` returns `$GLOBALS['pp_test_user_caps'][$cap] ?? false`.

---

### Task 1: `Peanut_Connect_Roles` — capability, role, update-safe install

**Files:**
- Create: `includes/class-connect-roles.php`
- Modify: `peanut-connect.php` (require the class; call `boot()` on load; call `install()` in the activation closure)
- Modify: `uninstall.php` (call `Peanut_Connect_Roles::uninstall()`)
- Test: `tests/Test_Roles.php`

**Interfaces:**
- Produces: `Peanut_Connect_Roles::BUILDER_CAP` (`'peanut_connect_build_utms'`), `::BUILDER_ROLE` (`'peanut_utm_builder'`), and static methods `boot()`, `install()`, `maybe_install()`, `uninstall()`, `grant_builder_cap_to_admins(array $allcaps): array`.

- [ ] **Step 1: Write the failing test.** Create `tests/Test_Roles.php`. It defines guarded WP role stubs that record into globals, then asserts install/uninstall and the admin-cap filter.

```php
<?php
use PHPUnit\Framework\TestCase;

if (!function_exists('add_role')) {
    function add_role($role, $name, $caps) {
        $GLOBALS['pp_roles'][$role] = ['name' => $name, 'caps' => $caps];
    }
}
if (!function_exists('remove_role')) {
    function remove_role($role) { unset($GLOBALS['pp_roles'][$role]); }
}
if (!function_exists('add_filter')) {
    function add_filter($h, $cb) { $GLOBALS['pp_filters'][$h] = $cb; }
}
if (!function_exists('add_action')) {
    function add_action($h, $cb) { $GLOBALS['pp_actions'][$h] = $cb; }
}

require_once dirname(__DIR__) . '/includes/class-connect-roles.php';

class Test_Roles extends TestCase {
    protected function setUp(): void {
        global $mock_options, $pp_roles;
        $mock_options = [];
        $pp_roles = [];
    }

    public function test_install_creates_the_builder_role_with_exactly_read_and_the_cap(): void {
        Peanut_Connect_Roles::install();
        $role = $GLOBALS['pp_roles']['peanut_utm_builder'] ?? null;
        $this->assertNotNull($role);
        $this->assertSame('UTM Builder', $role['name']);
        $this->assertSame(
            ['read' => true, 'peanut_connect_build_utms' => true],
            $role['caps'],
        );
    }

    public function test_uninstall_removes_the_role(): void {
        Peanut_Connect_Roles::install();
        Peanut_Connect_Roles::uninstall();
        $this->assertArrayNotHasKey('peanut_utm_builder', $GLOBALS['pp_roles']);
    }

    public function test_admins_get_the_builder_cap_at_runtime(): void {
        $granted = Peanut_Connect_Roles::grant_builder_cap_to_admins(['manage_options' => true]);
        $this->assertTrue($granted['peanut_connect_build_utms']);

        $notAdmin = Peanut_Connect_Roles::grant_builder_cap_to_admins(['read' => true]);
        $this->assertArrayNotHasKey('peanut_connect_build_utms', $notAdmin);
    }

    public function test_maybe_install_is_idempotent_by_version(): void {
        Peanut_Connect_Roles::install();          // sets version option
        $GLOBALS['pp_roles'] = [];                 // simulate role gone
        Peanut_Connect_Roles::maybe_install();     // version matches -> no-op
        $this->assertArrayNotHasKey('peanut_utm_builder', $GLOBALS['pp_roles']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails.**

Run: `composer test -- --filter Test_Roles`
Expected: FAIL — `class-connect-roles.php` does not exist.

- [ ] **Step 3: Create the class.** Create `includes/class-connect-roles.php`:

```php
<?php
/**
 * The scoped "UTM Builder" role + capability.
 *
 * @package Peanut_Connect
 */

class Peanut_Connect_Roles {
    const BUILDER_CAP = 'peanut_connect_build_utms';
    const BUILDER_ROLE = 'peanut_utm_builder';
    const VERSION_OPTION = 'peanut_connect_roles_version';
    const VERSION = 1;

    /** Wire runtime hooks. Called once during plugin load. */
    public static function boot(): void {
        // Admins satisfy the builder cap at RUNTIME — never a stored grant, so a
        // plugin update can't leave admins without the menu capability.
        add_filter('user_has_cap', [self::class, 'grant_builder_cap_to_admins']);
        // Keep the role in sync on upgrade (activation does not fire on update).
        add_action('admin_init', [self::class, 'maybe_install']);
    }

    /** @param array<string,bool> $allcaps */
    public static function grant_builder_cap_to_admins($allcaps) {
        if (!empty($allcaps['manage_options'])) {
            $allcaps[self::BUILDER_CAP] = true;
        }
        return $allcaps;
    }

    /** Idempotent: (re)create the role with exactly read + the builder cap. */
    public static function install(): void {
        remove_role(self::BUILDER_ROLE);
        add_role(self::BUILDER_ROLE, 'UTM Builder', [
            'read' => true,
            self::BUILDER_CAP => true,
        ]);
        update_option(self::VERSION_OPTION, self::VERSION);
    }

    public static function maybe_install(): void {
        if ((int) get_option(self::VERSION_OPTION) !== self::VERSION) {
            self::install();
        }
    }

    public static function uninstall(): void {
        remove_role(self::BUILDER_ROLE);
        delete_option(self::VERSION_OPTION);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes.**

Run: `composer test -- --filter Test_Roles`
Expected: PASS (4 tests).

- [ ] **Step 5: Wire it into the plugin.**
  1. In `peanut-connect.php`, near the other `require_once` includes, add `require_once plugin_dir_path(__FILE__) . 'includes/class-connect-roles.php';`.
  2. Register the boot hook alongside the plugin's other top-level `add_action` calls: `add_action('plugins_loaded', ['Peanut_Connect_Roles', 'boot']);`.
  3. Inside the existing `register_activation_hook(__FILE__, function() { ... })` closure, add (after the DB table creation): `require_once plugin_dir_path(__FILE__) . 'includes/class-connect-roles.php'; Peanut_Connect_Roles::install();`.
  4. In `uninstall.php` (after the existing cleanup), add: `require_once plugin_dir_path(__FILE__) . 'includes/class-connect-roles.php'; if (class_exists('Peanut_Connect_Roles')) { Peanut_Connect_Roles::uninstall(); }`.

- [ ] **Step 6: Run the full PHP suite** (confirm the wiring didn't break loading).

Run: `composer test`
Expected: all green.

- [ ] **Step 7: Commit.**

```bash
git add includes/class-connect-roles.php peanut-connect.php uninstall.php tests/Test_Roles.php
git commit -m "feat(roles): scoped UTM Builder role + capability (update-safe)"
```

---

### Task 2: Scoped builder permission gate + menu + mode flag

**Files:**
- Modify: `includes/class-connect-marketing.php` (add `check_builder_permission`; switch the three builder routes to it)
- Modify: `peanut-connect.php` (menu capability → the builder cap; add `mode` to `wp_localize_script`)
- Test: `tests/Test_Builder_Permission.php`

**Interfaces:**
- Consumes: `Peanut_Connect_Roles::BUILDER_CAP`.
- Produces: `Peanut_Connect_Marketing::check_builder_permission(): bool` = `current_user_can('manage_options') || current_user_can('peanut_connect_build_utms')`.

- [ ] **Step 1: Write the failing permission-matrix test.** Create `tests/Test_Builder_Permission.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-connect-marketing.php';

class Test_Builder_Permission extends TestCase {
    protected function setUp(): void {
        $GLOBALS['pp_test_user_caps'] = [];
    }

    public function test_builder_cap_user_passes_the_builder_gate_but_not_the_admin_gate(): void {
        $GLOBALS['pp_test_user_caps'] = ['peanut_connect_build_utms' => true];
        $this->assertTrue(Peanut_Connect_Marketing::check_builder_permission());
        $this->assertFalse(Peanut_Connect_Marketing::check_admin_permission());
    }

    public function test_admin_passes_both_gates(): void {
        $GLOBALS['pp_test_user_caps'] = ['manage_options' => true];
        $this->assertTrue(Peanut_Connect_Marketing::check_builder_permission());
        $this->assertTrue(Peanut_Connect_Marketing::check_admin_permission());
    }

    public function test_a_random_logged_in_user_passes_neither(): void {
        $GLOBALS['pp_test_user_caps'] = ['read' => true];
        $this->assertFalse(Peanut_Connect_Marketing::check_builder_permission());
        $this->assertFalse(Peanut_Connect_Marketing::check_admin_permission());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails.**

Run: `composer test -- --filter Test_Builder_Permission`
Expected: FAIL — `check_builder_permission` undefined.

- [ ] **Step 3: Add the permission method.** In `includes/class-connect-marketing.php`, next to `check_admin_permission()`, add:

```php
    /**
     * Builder gate: admins (via manage_options, also granted the cap at runtime)
     * OR the scoped UTM Builder capability. Guards ONLY the builder endpoints.
     */
    public static function check_builder_permission(): bool {
        return current_user_can('manage_options')
            || current_user_can('peanut_connect_build_utms');
    }
```

- [ ] **Step 4: Run the test to verify it passes.**

Run: `composer test -- --filter Test_Builder_Permission`
Expected: PASS (3 tests).

- [ ] **Step 5: Point the three builder routes at the builder gate.** In `register_routes()`, after `$perms = [self::class, 'check_admin_permission'];`, add `$builderPerms = [self::class, 'check_builder_permission'];`. Then change the `permission_callback` from `$perms` to `$builderPerms` on EXACTLY these route registrations (and no others):
  - `/marketing/campaigns` (the POST registration)
  - `/marketing/utms` (both the GET and POST method entries)
  - `/marketing/links` (both the GET and POST method entries)

  Leave `/marketing/utms/(?P<id>\d+)`, `/marketing/links/(?P<id>\d+)`, archive, restore, toggle, and every non-marketing route on `$perms` (admin-only).

- [ ] **Step 6: Open the menu + emit the mode flag.** In `peanut-connect.php`:
  1. Change the `add_menu_page(...)` capability argument from `'manage_options'` to `Peanut_Connect_Roles::BUILDER_CAP` (admins have it via the runtime filter; builders via the role).
  2. In the `wp_localize_script('peanut-connect-react', 'peanutConnect', [ ... ])` array, add: `'mode' => current_user_can('manage_options') ? 'full' : 'builder',`.

- [ ] **Step 7: Run the full PHP suite.**

Run: `composer test`
Expected: all green.

- [ ] **Step 8: Commit.**

```bash
git add includes/class-connect-marketing.php peanut-connect.php tests/Test_Builder_Permission.php
git commit -m "feat(roles): scoped builder REST gate on utm/link/campaign create+list; menu + mode"
```

---

### Task 3: `getAppMode` helper + sidebar-less Layout in builder mode

**Files:**
- Create: `frontend/src/config/appMode.ts`
- Modify: `frontend/src/components/layout/Layout.tsx` (hide the sidebar in builder mode)
- Test: `frontend/src/config/appMode.test.ts`, and extend `frontend/src/components/layout/Layout.test.tsx`

**Interfaces:**
- Produces: `getAppMode(): 'full' | 'builder'` reading `window.peanutConnect?.mode`.

- [ ] **Step 1: Write the failing helper test.** Create `frontend/src/config/appMode.test.ts`:

```ts
import { describe, it, expect, afterEach } from 'vitest';
import { getAppMode } from './appMode';

afterEach(() => {
  delete (window as any).peanutConnect;
});

describe('getAppMode', () => {
  it('returns builder when the localized mode is builder', () => {
    (window as any).peanutConnect = { mode: 'builder' };
    expect(getAppMode()).toBe('builder');
  });

  it('defaults to full when mode is absent or anything else', () => {
    expect(getAppMode()).toBe('full');
    (window as any).peanutConnect = { mode: 'full' };
    expect(getAppMode()).toBe('full');
  });
});
```

- [ ] **Step 2: Run it to verify it fails.**

Run: `cd frontend && npx vitest run src/config/appMode.test.ts`
Expected: FAIL — cannot resolve `./appMode`.

- [ ] **Step 3: Create the helper.** Create `frontend/src/config/appMode.ts`:

```ts
export type AppMode = 'full' | 'builder';

export function getAppMode(): AppMode {
  const w = window as unknown as { peanutConnect?: { mode?: string } };
  return w.peanutConnect?.mode === 'builder' ? 'builder' : 'full';
}
```

- [ ] **Step 4: Run it to verify it passes.**

Run: `cd frontend && npx vitest run src/config/appMode.test.ts`
Expected: PASS (2 tests).

- [ ] **Step 5: Add the builder-mode Layout branch to the test.** Append to `frontend/src/components/layout/Layout.test.tsx`:

```tsx
it('hides the sidebar in builder mode', () => {
  (window as any).peanutConnect = { mode: 'builder' };
  render(
    <MemoryRouter>
      <Layout title="Build">
        <div>content</div>
      </Layout>
    </MemoryRouter>,
  );
  expect(screen.queryByRole('heading', { name: 'Performance' })).toBeNull();
  expect(screen.getByRole('heading', { name: 'Build' })).toBeInTheDocument();
  delete (window as any).peanutConnect;
});
```

- [ ] **Step 6: Run it to verify it fails.**

Run: `cd frontend && npx vitest run src/components/layout/Layout.test.tsx`
Expected: FAIL — the sidebar (with "Performance") still renders in builder mode.

- [ ] **Step 7: Make Layout mode-aware.** In `frontend/src/components/layout/Layout.tsx`, import the helper and skip the sidebar in builder mode:

```tsx
import { getAppMode } from '@/config/appMode';
```

Then in the returned JSX, render the `<Sidebar ... />` only when not in builder mode:

```tsx
      {getAppMode() !== 'builder' && (
        <Sidebar collapsed={collapsed} onToggle={() => setCollapsed((c) => !c)} />
      )}
```

(Leave the rest of Layout — header + main — unchanged; the collapse state can stay, it's just unused in builder mode.)

- [ ] **Step 8: Run the Layout + appMode tests to verify they pass.**

Run: `cd frontend && npx vitest run src/components/layout/Layout.test.tsx src/config/appMode.test.ts`
Expected: PASS.

- [ ] **Step 9: Typecheck + commit.**

```bash
cd frontend && npx tsc --noEmit && cd ..
git add frontend/src/config/appMode.ts frontend/src/config/appMode.test.ts frontend/src/components/layout/Layout.tsx frontend/src/components/layout/Layout.test.tsx
git commit -m "feat(roles): app mode helper; Layout drops sidebar in builder mode"
```

---

### Task 4: Builder root + entry branch

**Files:**
- Create: `frontend/src/BuilderRoot.tsx`
- Modify: `frontend/src/main.tsx` (branch on `getAppMode()`)
- Test: `frontend/src/BuilderRoot.test.tsx`

**Interfaces:**
- Consumes: `Campaigns` (default export), `getAppMode`.
- Produces: default-exported `BuilderRoot` — the builder-only app (just the Campaigns wizard, no other routes).

- [ ] **Step 1: Write the failing test.** Create `frontend/src/BuilderRoot.test.tsx`. It mocks `@/api` (the wizard + Layout chrome use it) and asserts the wizard renders without the sidebar.

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import BuilderRoot from './BuilderRoot';

vi.mock('@/api', () => ({
  getVersion: () => '0.0.0-test',
  marketingApi: {
    listUtms: vi.fn().mockResolvedValue({ data: [] }),
    listLinks: vi.fn().mockResolvedValue({ data: [] }),
  },
}));

describe('BuilderRoot', () => {
  it('renders the campaign/UTM wizard and no sidebar', () => {
    (window as any).peanutConnect = { mode: 'builder' };
    render(<BuilderRoot />);
    // The Campaigns wizard renders inside a sidebar-less Layout.
    expect(screen.queryByRole('heading', { name: 'Performance' })).toBeNull();
    // A stable wizard control — the short-link slug field label from the wizard.
    expect(screen.getByText(/Short link/i)).toBeInTheDocument();
    delete (window as any).peanutConnect;
  });
});
```

Note: if `Campaigns` calls additional `marketingApi` methods on mount, add them to the mock (all as `vi.fn().mockResolvedValue(...)`); confirm by reading `frontend/src/pages/Campaigns.tsx` imports before finalizing the mock. The assertion on `/Short link/i` corresponds to the wizard's short-link field description — adjust to a stable string actually present in the wizard if needed.

- [ ] **Step 2: Run it to verify it fails.**

Run: `cd frontend && npx vitest run src/BuilderRoot.test.tsx`
Expected: FAIL — cannot resolve `./BuilderRoot`.

- [ ] **Step 3: Create `BuilderRoot.tsx`.**

```tsx
import { HashRouter } from 'react-router-dom';
import Campaigns from './pages/Campaigns';

/**
 * Builder-only app for the scoped "UTM Builder" role: just the Campaigns
 * wizard (UTM + short link + QR), rendered inside a sidebar-less Layout
 * (Layout hides its sidebar in builder mode). No other routes exist here —
 * the server-side permission gate is the real boundary.
 */
export default function BuilderRoot() {
  return (
    <HashRouter>
      <Campaigns />
    </HashRouter>
  );
}
```

- [ ] **Step 4: Run it to verify it passes.**

Run: `cd frontend && npx vitest run src/BuilderRoot.test.tsx`
Expected: PASS.

- [ ] **Step 5: Branch the entry point.** In `frontend/src/main.tsx`, import `getAppMode` and `BuilderRoot`, and render the builder app in builder mode. Replace the existing `<App />` render so it becomes:

```tsx
import { getAppMode } from './config/appMode';
import BuilderRoot from './BuilderRoot';
// ...existing imports (ReactDOM, App, HashRouter, etc.)...

const rootElement = document.getElementById('peanut-connect-app');
if (rootElement) {
  const isBuilder = getAppMode() === 'builder';
  ReactDOM.createRoot(rootElement).render(
    <React.StrictMode>
      {isBuilder ? (
        <BuilderRoot />
      ) : (
        <HashRouter>
          <App />
        </HashRouter>
      )}
    </React.StrictMode>,
  );
}
```

Keep whatever providers already wrap `<App />` in the current `main.tsx` (e.g. QueryClientProvider, ThemeProvider, ToastProvider) wrapping BOTH branches — move them outside the ternary so the builder app gets the same providers. Read the current `main.tsx` and preserve its exact provider nesting; only add the mode branch.

- [ ] **Step 6: Typecheck + full suite + commit.**

Run: `cd frontend && npx tsc --noEmit && npm run test:run`
Expected: all green.

```bash
git add frontend/src/BuilderRoot.tsx frontend/src/BuilderRoot.test.tsx frontend/src/main.tsx
git commit -m "feat(roles): builder-only app entry (UTM wizard, no other routes)"
```

---

### Task 5: Build and commit the bundle

- [ ] **Step 1: Full suites.**

Run: `composer test && cd frontend && npm run test:run`
Expected: all green (PHP role/permission tests + JS mode/builder tests + everything unchanged).

- [ ] **Step 2: Production build.**

Run: `cd frontend && npm run build`
Expected: clean.

- [ ] **Step 3: Commit the rebuilt bundle.**

```bash
git add assets/dist/
git commit -m "build(assets): rebuild dist for builder-mode entry"
```

---

### Final verification

- [ ] `composer test` — green (incl. `Test_Roles`, `Test_Builder_Permission`).
- [ ] `cd frontend && npm run test:run` — green; `npm run build` clean; `assets/dist` committed.
- [ ] Confirm no route other than the three builder endpoints was switched off `check_admin_permission` (grep `check_builder_permission` in `class-connect-marketing.php` — it should appear on exactly the campaigns POST + utms GET/POST + links GET/POST).
- [ ] **Do NOT bump the version or release.** Ships via the signed pipeline as a minor bump, after (or stacked on) Phase 1.

## Notes / deferred

- Phase 3 — Overview/home redesign, retire the Sankey, fix "Clicked enroll".
- If the builder later needs to edit/delete their own UTMs, add those routes to the builder gate in a follow-up (deliberately excluded now for least privilege).
