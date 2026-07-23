# End-to-End Navigation & IA Reorg (Phase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the overflowing 13-item top-tab nav with one grouped left sidebar built from a single `nav.ts` source of truth, organized into five job-based groups, with no broken links and clearer labels.

**Architecture:** A new `frontend/src/config/nav.ts` defines the five ordered groups (the single source of truth). `Sidebar.tsx` is rewritten to render that config as a grouped, collapsible in-content column. `Layout.tsx` drops its inline top-tab bar and instead renders the `Sidebar` beside the page content, owning the collapse state. `App.tsx` keeps every route target and adds redirects for the retired duplicate paths. No page internals, PHP, or API code change.

**Tech Stack:** React 18 + TypeScript, react-router-dom 6, Vite, Tailwind, `clsx`, `lucide-react`; Vitest 4 + @testing-library/react.

## Global Constraints

- Phase 1 is **navigation & IA only** — do NOT modify any page's internals, the PHP side, or the `@/api` layer.
- **Single source of truth:** all nav lives in `frontend/src/config/nav.ts`. No component defines its own nav array.
- **Five groups, in this order:** `Overview`, `Performance`, `Tracking setup`, `Health`, `System`.
- **Labels (verbatim):** GTM Coverage → `Tracking Health` (in the Health group, href `/analytics/gtm-coverage`); UTMs → `UTM Builder` (href `/utms`); Tracking → `Tracking Code` (href `/tracking`); Links → `Short Links` (href `/links`); Dashboard → `Overview` (href `/`).
- **No duplicate destinations:** each href appears at most once in `nav.ts`.
- **Eager imports only** in `App.tsx` (the `React.lazy` ban stands).
- The sidebar must be an **in-content column (flex/sticky), NOT `position: fixed` to the viewport** — the plugin renders inside wp-admin and a viewport-fixed sidebar would overlap WordPress's own admin menu.
- **Release constraint:** peanut-connect's signed publish (`BUILD=composer`) packages the **committed** `assets/dist` and does NOT rebuild the SPA. The final task MUST rebuild (`npm run build`) and commit `assets/dist`, or the release ships stale JS.
- Commands run from the repo root unless noted; the frontend lives in `frontend/`.

---

### Task 1: `nav.ts` — the single source of truth

**Files:**
- Create: `frontend/src/config/nav.ts`
- Test: `frontend/src/config/nav.test.ts`

**Interfaces:**
- Produces: `interface NavItem { label: string; href: string; icon: typeof LayoutDashboard }`, `interface NavGroup { group: string; items: NavItem[] }`, and `export const NAV: NavGroup[]`.

- [ ] **Step 1: Write the failing contract test.** Create `frontend/src/config/nav.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { NAV } from './nav';

// Mirrors the route paths declared in App.tsx. Every nav href MUST be one of
// these (i.e. a real, rendered route). Keep this set in sync with App.tsx —
// the guard that prevents another "nav points nowhere / invisible tab" bug.
const KNOWN_ROUTES = new Set([
  '/', '/analytics', '/campaigns', '/analytics/journeys',
  '/analytics/dominion-funnel', '/videos', '/utms', '/links', '/tracking',
  '/health', '/analytics/gtm-coverage', '/errors', '/activity', '/updates',
  '/settings',
]);

describe('nav config', () => {
  it('has the five groups in the intended order', () => {
    expect(NAV.map((g) => g.group)).toEqual([
      'Overview', 'Performance', 'Tracking setup', 'Health', 'System',
    ]);
  });

  it('points every item at a real App.tsx route', () => {
    for (const group of NAV) {
      for (const item of group.items) {
        expect(KNOWN_ROUTES.has(item.href), `${item.label} -> ${item.href}`).toBe(true);
      }
    }
  });

  it('lists each destination at most once', () => {
    const hrefs = NAV.flatMap((g) => g.items.map((i) => i.href));
    expect(new Set(hrefs).size).toBe(hrefs.length);
  });

  it('places Dominion Funnel under Performance and Tracking Health under Health', () => {
    const perf = NAV.find((g) => g.group === 'Performance')!;
    const health = NAV.find((g) => g.group === 'Health')!;
    expect(perf.items.some((i) => i.label === 'Dominion Funnel')).toBe(true);
    expect(
      health.items.some((i) => i.label === 'Tracking Health' && i.href === '/analytics/gtm-coverage'),
    ).toBe(true);
  });
});
```

- [ ] **Step 2: Run the test to verify it fails.**

Run: `cd frontend && npx vitest run src/config/nav.test.ts`
Expected: FAIL — cannot resolve `./nav`.

- [ ] **Step 3: Create `nav.ts`.** Create `frontend/src/config/nav.ts`:

```ts
import {
  LayoutDashboard,
  BarChart3,
  Megaphone,
  Footprints,
  Filter,
  Film,
  Tag,
  LinkIcon,
  Code2,
  Activity,
  ShieldCheck,
  AlertTriangle,
  History,
  Download,
  Settings,
} from 'lucide-react';

export interface NavItem {
  label: string;
  href: string;
  icon: typeof LayoutDashboard;
}

export interface NavGroup {
  group: string;
  items: NavItem[];
}

// The ONE nav definition. Both Sidebar and any route-in-nav logic import this.
export const NAV: NavGroup[] = [
  {
    group: 'Overview',
    items: [{ label: 'Overview', href: '/', icon: LayoutDashboard }],
  },
  {
    group: 'Performance',
    items: [
      { label: 'Analytics', href: '/analytics', icon: BarChart3 },
      { label: 'Campaigns', href: '/campaigns', icon: Megaphone },
      { label: 'Journeys', href: '/analytics/journeys', icon: Footprints },
      { label: 'Dominion Funnel', href: '/analytics/dominion-funnel', icon: Filter },
      { label: 'Videos', href: '/videos', icon: Film },
    ],
  },
  {
    group: 'Tracking setup',
    items: [
      { label: 'UTM Builder', href: '/utms', icon: Tag },
      { label: 'Short Links', href: '/links', icon: LinkIcon },
      { label: 'Tracking Code', href: '/tracking', icon: Code2 },
    ],
  },
  {
    group: 'Health',
    items: [
      { label: 'Health', href: '/health', icon: Activity },
      { label: 'Tracking Health', href: '/analytics/gtm-coverage', icon: ShieldCheck },
      { label: 'Errors', href: '/errors', icon: AlertTriangle },
      { label: 'Activity', href: '/activity', icon: History },
    ],
  },
  {
    group: 'System',
    items: [
      { label: 'Updates', href: '/updates', icon: Download },
      { label: 'Settings', href: '/settings', icon: Settings },
    ],
  },
];
```

- [ ] **Step 4: Run the test to verify it passes.**

Run: `cd frontend && npx vitest run src/config/nav.test.ts`
Expected: PASS (4 tests).

- [ ] **Step 5: Typecheck** (confirms every icon name is a real `lucide-react` export).

Run: `cd frontend && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 6: Commit.**

```bash
git add frontend/src/config/nav.ts frontend/src/config/nav.test.ts
git commit -m "feat(nav): single-source nav config with five job-based groups"
```

---

### Task 2: Rewrite `Sidebar.tsx` to render the grouped nav

**Files:**
- Modify (rewrite): `frontend/src/components/layout/Sidebar.tsx`
- Test: `frontend/src/components/layout/Sidebar.test.tsx` (replace the existing test)

**Interfaces:**
- Consumes: `NAV`, `NavGroup`, `NavItem` from `@/config/nav`; `getVersion` from `@/api`.
- Produces: default-exported `Sidebar` component with props `{ collapsed: boolean; onToggle: () => void }`. It is an in-content column (no viewport `fixed`).

- [ ] **Step 1: Replace the test.** Overwrite `frontend/src/components/layout/Sidebar.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import Sidebar from './Sidebar';

function renderSidebar(collapsed = false) {
  return render(
    <MemoryRouter>
      <Sidebar collapsed={collapsed} onToggle={() => {}} />
    </MemoryRouter>,
  );
}

describe('Sidebar', () => {
  it('renders all five group headings in order', () => {
    renderSidebar();
    const headings = screen.getAllByRole('heading', { level: 3 }).map((h) => h.textContent);
    expect(headings).toEqual(['Overview', 'Performance', 'Tracking setup', 'Health', 'System']);
  });

  it('puts Dominion Funnel under Performance and Tracking Health under Health', () => {
    renderSidebar();
    expect(screen.getByRole('link', { name: /Dominion Funnel/i })).toHaveAttribute(
      'href',
      '/analytics/dominion-funnel',
    );
    expect(screen.getByRole('link', { name: /Tracking Health/i })).toHaveAttribute(
      'href',
      '/analytics/gtm-coverage',
    );
  });

  it('hides group headings when collapsed but keeps the links', () => {
    renderSidebar(true);
    expect(screen.queryByRole('heading', { name: 'Performance' })).toBeNull();
    // Links remain (icon-only); accessible name comes from aria-label.
    expect(screen.getByRole('link', { name: /Dominion Funnel/i })).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run the test to verify it fails.**

Run: `cd frontend && npx vitest run src/components/layout/Sidebar.test.tsx`
Expected: FAIL (old Sidebar renders a flat list, no group headings).

- [ ] **Step 3: Rewrite `Sidebar.tsx`.** Replace the whole file with:

```tsx
import { NavLink } from 'react-router-dom';
import { clsx } from 'clsx';
import { ChevronLeft, ChevronRight, Link2 } from 'lucide-react';
import { NAV } from '@/config/nav';
import { getVersion } from '@/api';

interface SidebarProps {
  collapsed: boolean;
  onToggle: () => void;
}

export default function Sidebar({ collapsed, onToggle }: SidebarProps) {
  return (
    <aside
      className={clsx(
        // In-content column (sticky), NOT viewport-fixed — must not overlap wp-admin's menu.
        'sticky top-0 self-start h-[100dvh] overflow-y-auto flex-none bg-white border-r border-slate-200 transition-all duration-300',
        collapsed ? 'w-16' : 'w-56',
      )}
    >
      {/* Header / collapse toggle */}
      <div className="h-14 flex items-center justify-between px-4 border-b border-slate-200">
        {!collapsed ? (
          <div className="flex items-center gap-2">
            <Link2 className="w-5 h-5 text-primary-600" />
            <span className="text-lg font-bold text-primary-600 leading-tight">End to End</span>
          </div>
        ) : (
          <Link2 className="w-5 h-5 text-primary-600 mx-auto" />
        )}
        <button
          type="button"
          onClick={onToggle}
          aria-label={collapsed ? 'Expand navigation' : 'Collapse navigation'}
          className={clsx(
            'p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors',
            collapsed && 'mx-auto',
          )}
        >
          {collapsed ? <ChevronRight className="w-5 h-5" /> : <ChevronLeft className="w-5 h-5" />}
        </button>
      </div>

      {/* Grouped navigation */}
      <nav className="p-3 space-y-4">
        {NAV.map((group) => (
          <div key={group.group} className="space-y-1">
            {!collapsed && (
              <h3 className="px-3 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                {group.group}
              </h3>
            )}
            {group.items.map((item) => (
              <NavLink
                key={item.href}
                to={item.href}
                end={item.href === '/'}
                aria-label={item.label}
                title={collapsed ? item.label : undefined}
                className={({ isActive }) =>
                  clsx(
                    'flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors',
                    isActive
                      ? 'bg-primary-50 text-primary-700'
                      : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900',
                  )
                }
              >
                <item.icon className="w-5 h-5 flex-shrink-0 text-slate-500" />
                {!collapsed && <span className="flex-1">{item.label}</span>}
              </NavLink>
            ))}
          </div>
        ))}
      </nav>

      {!collapsed && (
        <div className="p-4 border-t border-slate-200">
          <span className="text-xs text-slate-400">End-to-End v{getVersion()}</span>
        </div>
      )}
    </aside>
  );
}
```

Note the `aria-label={item.label}` on every `NavLink` — it keeps each link's accessible name stable even when collapsed (icon-only), which the collapsed-state test relies on.

- [ ] **Step 4: Run the test to verify it passes.**

Run: `cd frontend && npx vitest run src/components/layout/Sidebar.test.tsx`
Expected: PASS (3 tests).

- [ ] **Step 5: Typecheck.**

Run: `cd frontend && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 6: Commit.**

```bash
git add frontend/src/components/layout/Sidebar.tsx frontend/src/components/layout/Sidebar.test.tsx
git commit -m "feat(nav): grouped collapsible sidebar rendering nav.ts"
```

---

### Task 3: Rewrite `Layout.tsx` to use the sidebar (drop the top-tab bar)

**Files:**
- Modify (rewrite): `frontend/src/components/layout/Layout.tsx`
- Test: `frontend/src/components/layout/Layout.test.tsx` (update the existing test)

**Interfaces:**
- Consumes: default `Sidebar` from `./Sidebar`.
- Produces: default-exported `Layout` with unchanged props `{ children: ReactNode; title: string; description?: string; action?: ReactNode }`. Renders the sidebar (left) + header + main (right); owns collapse state persisted to `localStorage` under `pc-sidebar-collapsed`.

- [ ] **Step 1: Update the test.** Overwrite `frontend/src/components/layout/Layout.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import Layout from './Layout';

function renderLayout() {
  return render(
    <MemoryRouter>
      <Layout title="Test Page" description="desc">
        <div>page content</div>
      </Layout>
    </MemoryRouter>,
  );
}

describe('Layout', () => {
  it('renders the page header and content', () => {
    renderLayout();
    expect(screen.getByRole('heading', { name: 'Test Page' })).toBeInTheDocument();
    expect(screen.getByText('page content')).toBeInTheDocument();
  });

  it('renders the grouped sidebar (nav is now the sidebar, not top tabs)', () => {
    renderLayout();
    expect(screen.getByRole('heading', { name: 'Performance' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Dominion Funnel/i })).toHaveAttribute(
      'href',
      '/analytics/dominion-funnel',
    );
  });
});
```

- [ ] **Step 2: Run the test to verify it fails.**

Run: `cd frontend && npx vitest run src/components/layout/Layout.test.tsx`
Expected: FAIL — Layout still renders top tabs, no "Performance" group heading.

- [ ] **Step 3: Rewrite `Layout.tsx`.** Replace the whole file with:

```tsx
import { type ReactNode, useEffect, useState } from 'react';
import Sidebar from './Sidebar';

interface LayoutProps {
  children: ReactNode;
  title: string;
  description?: string;
  action?: ReactNode;
}

const COLLAPSE_KEY = 'pc-sidebar-collapsed';

export default function Layout({ children, title, description, action }: LayoutProps) {
  const [collapsed, setCollapsed] = useState<boolean>(() => {
    try {
      return localStorage.getItem(COLLAPSE_KEY) === '1';
    } catch {
      return false;
    }
  });

  useEffect(() => {
    try {
      localStorage.setItem(COLLAPSE_KEY, collapsed ? '1' : '0');
    } catch {
      /* ignore storage failures */
    }
  }, [collapsed]);

  return (
    <div className="min-h-[100dvh] bg-slate-50 flex">
      <Sidebar collapsed={collapsed} onToggle={() => setCollapsed((c) => !c)} />

      <div className="flex-1 min-w-0 flex flex-col">
        <header className="bg-white border-b border-slate-200">
          <div className="px-4 py-4 sm:px-6">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <h1 className="text-xl font-semibold text-slate-900">{title}</h1>
                {description && <p className="text-sm text-slate-500 mt-0.5">{description}</p>}
              </div>
              {action && <div className="sm:flex-shrink-0">{action}</div>}
            </div>
          </div>
        </header>

        <main id="main-content" tabIndex={-1} className="p-4 sm:p-6 overflow-x-hidden">
          {children}
        </main>
      </div>
    </div>
  );
}
```

This removes the inline `navigation` array, the top-tab `<nav>`, and the now-unused `clsx` / `NavLink` / lucide-icon imports.

- [ ] **Step 4: Run the test to verify it passes.**

Run: `cd frontend && npx vitest run src/components/layout/Layout.test.tsx`
Expected: PASS (2 tests).

- [ ] **Step 5: Typecheck** (catches any leftover unused import).

Run: `cd frontend && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 6: Commit.**

```bash
git add frontend/src/components/layout/Layout.tsx frontend/src/components/layout/Layout.test.tsx
git commit -m "feat(nav): Layout renders the grouped sidebar, drops top-tab bar"
```

---

### Task 4: Redirect the retired duplicate routes in `App.tsx`

**Files:**
- Modify: `frontend/src/App.tsx`
- Test: `frontend/src/App.redirects.test.tsx` (create)

**Interfaces:**
- Consumes: `Navigate`, `useParams` from `react-router-dom` (add to the existing import if not present).
- Produces: `/journeys` → `/analytics/journeys` and `/journeys/:clickId` → `/analytics/journeys/:clickId` (param preserved).

- [ ] **Step 1: Write the failing redirect test.** Create `frontend/src/App.redirects.test.tsx`. It re-implements the two redirect routes in a tiny router so the test does not need the full App + providers; the redirect *logic* is what's under test, and the component used is imported from App to guarantee it's the real one.

First, Task 4 also exports the param-preserving redirect from App so the test can import it. In `App.tsx`, define and export:

```tsx
export function RedirectJourneyDetail() {
  const { clickId } = useParams();
  return <Navigate to={`/analytics/journeys/${clickId}`} replace />;
}
```

Then the test:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route, Navigate } from 'react-router-dom';
import { RedirectJourneyDetail } from './App';

function harness(initialPath: string) {
  return render(
    <MemoryRouter initialEntries={[initialPath]}>
      <Routes>
        <Route path="/journeys" element={<Navigate to="/analytics/journeys" replace />} />
        <Route path="/journeys/:clickId" element={<RedirectJourneyDetail />} />
        <Route path="/analytics/journeys" element={<div>journeys list</div>} />
        <Route path="/analytics/journeys/:clickId" element={<div>journey detail</div>} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('legacy /journeys redirects', () => {
  it('redirects /journeys to the analytics journeys list', () => {
    harness('/journeys');
    expect(screen.getByText('journeys list')).toBeInTheDocument();
  });

  it('redirects /journeys/:clickId preserving the id', () => {
    harness('/journeys/abc123');
    expect(screen.getByText('journey detail')).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run the test to verify it fails.**

Run: `cd frontend && npx vitest run src/App.redirects.test.tsx`
Expected: FAIL — `RedirectJourneyDetail` is not exported from `./App`.

- [ ] **Step 3: Edit `App.tsx`.**
  1. Ensure the router import includes `Navigate` and `useParams`, e.g. `import { Routes, Route, Navigate, useParams } from 'react-router-dom';` (merge into the existing import — do not duplicate).
  2. Add the exported `RedirectJourneyDetail` component (shown in Step 1) near the top of the file, after the imports.
  3. Find the legacy alias routes (currently `<Route path="/journeys" element={<Journeys />} />` and `<Route path="/journeys/:clickId" element={<JourneyDetail />} />`) and replace them with:

```tsx
{/* Legacy aliases now redirect to the canonical /analytics/journeys paths */}
<Route path="/journeys" element={<Navigate to="/analytics/journeys" replace />} />
<Route path="/journeys/:clickId" element={<RedirectJourneyDetail />} />
```

  4. If `Journeys` / `JourneyDetail` are no longer referenced anywhere else in `App.tsx` after this change, leave their imports **only if** still used by the `/analytics/journeys*` routes (they are — do not remove those imports).

- [ ] **Step 4: Run the test to verify it passes.**

Run: `cd frontend && npx vitest run src/App.redirects.test.tsx`
Expected: PASS (2 tests).

- [ ] **Step 5: Typecheck.**

Run: `cd frontend && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 6: Commit.**

```bash
git add frontend/src/App.tsx frontend/src/App.redirects.test.tsx
git commit -m "feat(nav): redirect legacy /journeys paths to canonical analytics routes"
```

---

### Task 5: Full suite, build, and commit the rebuilt bundle

**Files:**
- Modify (generated): `frontend/../assets/dist/*` (built output — committed because the signed publish packages it verbatim)

- [ ] **Step 1: Run the full frontend test suite.**

Run: `cd frontend && npm run test:run`
Expected: all green (includes the new/updated nav, Sidebar, Layout, and redirect tests, and every unchanged page test).

- [ ] **Step 2: Production build.**

Run: `cd frontend && npm run build`  (runs `tsc --noEmit && vite build`)
Expected: builds to `../assets/dist` with no TS errors.

- [ ] **Step 3: Verify the rebuilt bundle contains the new IA** (guards against shipping a stale bundle — the 3.22.0 failure mode).

Run: `grep -c "Tracking setup" assets/dist/js/main.js && grep -c "Tracking Health" assets/dist/js/main.js`
Expected: both ≥ 1 (the new group + label are compiled in).

- [ ] **Step 4: Commit the rebuilt bundle.**

```bash
git add assets/dist/
git commit -m "build(assets): rebuild dist for grouped-sidebar navigation"
```

---

### Final verification (whole-branch, after all tasks)

- [ ] `cd frontend && npm run test:run` — all green.
- [ ] `cd frontend && npm run build` — clean; `assets/dist` committed and contains "Tracking setup" + "Tracking Health".
- [ ] Manual smoke (optional, if a dev WP is available): load the plugin, confirm the left sidebar shows five groups, Dominion Funnel sits under Performance, GTM Coverage now reads "Tracking Health" under Health, and visiting `#/journeys` lands on `#/analytics/journeys`.
- [ ] **Do NOT bump the version or run the release here.** Shipping is a separate signed step: `Peanut-meta/scripts/publish-plugin.sh peanut-connect <minor-version> --ship`. Leave the branch ready for a normal PR.

## Notes / deferred (not this plan)

- Phase 2 — scoped "UTM Builder" WordPress role + builder-only view.
- Phase 3 — Overview/home redesign, retire the Analytics Sankey, fix "Clicked enroll" and other misleading in-page metrics.
- The `KNOWN_ROUTES` set in `nav.test.ts` mirrors `App.tsx`'s declared routes by hand; if a future task adds a new page+nav item, update both.
