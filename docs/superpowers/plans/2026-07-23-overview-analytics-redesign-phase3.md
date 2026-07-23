# Overview + Analytics Redesign (Phase 3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the Dashboard into a two-panel Overview home, replace the Analytics Sankey with clickable campaign outcome bars, fix the misleading "Clicked enroll" control, and enrich the journey timeline with device/geo/referrer/engagement — frontend only, reusing existing HUB endpoints.

**Architecture:** New presentational components (`OverviewPerformancePanel`, `OverviewHealthPanel`, `CampaignOutcomeBars`, `JourneyContextPanel`) each read data already fetched via `marketingApi`. `Dashboard.tsx` and `Analytics.tsx` are rewired to mount them; the "Clicked enroll" pill is repointed. No PHP/API/nav changes.

**Tech Stack:** React 18 + TypeScript, react-router-dom 6, @tanstack/react-query 5, Tailwind, `clsx`, `lucide-react`; Vitest + @testing-library/react.

## Global Constraints

- **Frontend only.** No PHP, no `@/api` endpoint changes, no HUB changes. Reuse existing `marketingApi` methods (`dominionFunnel`, `journeyStats`/campaign data, `listJourneys`, `journeyDetail`) and `dashboardApi`/`settingsApi`.
- **All Overview KPI tiles + panel rows are clickable** links into their full page. KPI drill targets: Landed → `/analytics/dominion-funnel`; Entered → `/analytics/dominion-funnel?stage=entered`; Enrolled → `/analytics/dominion-funnel?stage=enrolled`.
- **Outcome-bar segments** are `converted` / `in_progress` / `abandoned`; a segment click calls `onSegmentClick({ campaign, outcome })`; a bar (non-segment) click calls `onCampaignClick(campaign)`.
- **"Clicked enroll" → "Entered enrollment portal":** the current pill filters `event_name=click_to_portal`, which under-fires (0 of the 6 real converters have it). The `/marketing/journeys` API exposes **no** entered-portal filter, and API changes are out of scope — so the relabeled control becomes a **link to `/analytics/dominion-funnel?stage=entered`** (the reliable entered-portal view), NOT a client-side list filter. The old `click_to_portal` pill is removed.
- **JourneyContextPanel** pulls device/browser/os/country/region/referrer from the journey's **events array** (already in the `journeyDetail` payload) and engagement (max `scroll_depth` from `event_data.depth`, whether an `exit_intent` event fired) by scanning events; missing fields render `"—"`. No new endpoint.
- Page tests that mount `Layout` must include `getVersion: () => '0.0.0-test'` in their `vi.mock('@/api', …)` (Layout renders the Sidebar which calls `getVersion`).
- **Release:** the signed publish (`BUILD=composer`) packages the committed `assets/dist` and does NOT rebuild the SPA — the final task MUST `npm run build` and commit `assets/dist`.
- Eager imports only; commands run from `frontend/` unless noted.

---

### Task 1: `CampaignOutcomeBars` chart component

**Files:**
- Create: `frontend/src/components/charts/CampaignOutcomeBars.tsx`
- Test: `frontend/src/components/charts/CampaignOutcomeBars.test.tsx`

**Interfaces:**
- Produces:
  ```ts
  export interface CampaignOutcome {
    campaign: string;
    converted: number;
    in_progress: number;
    abandoned: number;
  }
  export type Outcome = 'converted' | 'in_progress' | 'abandoned';
  interface Props {
    data: CampaignOutcome[];
    onSegmentClick?: (sel: { campaign: string; outcome: Outcome }) => void;
    onCampaignClick?: (campaign: string) => void;
  }
  export function CampaignOutcomeBars(props: Props): JSX.Element
  ```

- [ ] **Step 1: Write the failing test.** Create `frontend/src/components/charts/CampaignOutcomeBars.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { CampaignOutcomeBars } from './CampaignOutcomeBars';

const data = [
  { campaign: 'DOME2620RS3', converted: 3, in_progress: 8, abandoned: 89 },
  { campaign: 'USA_Display', converted: 0, in_progress: 2, abandoned: 40 },
];

describe('CampaignOutcomeBars', () => {
  it('renders one labeled bar per campaign, sorted by total volume desc', () => {
    render(<CampaignOutcomeBars data={data} />);
    const labels = screen.getAllByTestId('outcome-bar-campaign').map((n) => n.textContent);
    expect(labels).toEqual(['DOME2620RS3', 'USA_Display']);
  });

  it('calls onSegmentClick with the campaign + outcome when a segment is clicked', () => {
    const onSegmentClick = vi.fn();
    render(<CampaignOutcomeBars data={data} onSegmentClick={onSegmentClick} />);
    fireEvent.click(screen.getByTestId('seg-DOME2620RS3-converted'));
    expect(onSegmentClick).toHaveBeenCalledWith({ campaign: 'DOME2620RS3', outcome: 'converted' });
  });

  it('calls onCampaignClick when the bar label is clicked', () => {
    const onCampaignClick = vi.fn();
    render(<CampaignOutcomeBars data={data} onCampaignClick={onCampaignClick} />);
    fireEvent.click(screen.getByTestId('outcome-bar-campaign-USA_Display'));
    expect(onCampaignClick).toHaveBeenCalledWith('USA_Display');
  });

  it('renders an empty state when there are no campaigns', () => {
    render(<CampaignOutcomeBars data={[]} />);
    expect(screen.getByText('No campaign data in this window.')).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run to verify it fails.**

Run: `npx vitest run src/components/charts/CampaignOutcomeBars.test.tsx`
Expected: FAIL — cannot resolve `./CampaignOutcomeBars`.

- [ ] **Step 3: Create the component.** Create `frontend/src/components/charts/CampaignOutcomeBars.tsx`:

```tsx
export interface CampaignOutcome {
  campaign: string;
  converted: number;
  in_progress: number;
  abandoned: number;
}

export type Outcome = 'converted' | 'in_progress' | 'abandoned';

interface Props {
  data: CampaignOutcome[];
  onSegmentClick?: (sel: { campaign: string; outcome: Outcome }) => void;
  onCampaignClick?: (campaign: string) => void;
}

const SEGMENTS: { key: Outcome; label: string; color: string }[] = [
  { key: 'converted', label: 'Converted', color: 'bg-emerald-500' },
  { key: 'in_progress', label: 'In progress', color: 'bg-violet-500' },
  { key: 'abandoned', label: 'Abandoned', color: 'bg-slate-300' },
];

function total(c: CampaignOutcome): number {
  return c.converted + c.in_progress + c.abandoned;
}

export function CampaignOutcomeBars({ data, onSegmentClick, onCampaignClick }: Props) {
  if (data.length === 0) {
    return <div className="text-sm text-slate-500">No campaign data in this window.</div>;
  }
  const rows = [...data].sort((a, b) => total(b) - total(a));

  return (
    <div className="space-y-3">
      <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs">
        {SEGMENTS.map((s) => (
          <li key={s.key} className="inline-flex items-center gap-1.5">
            <span className={`inline-block w-3 h-3 rounded-sm ${s.color}`} />
            <span className="text-slate-600">{s.label}</span>
          </li>
        ))}
      </ul>

      {rows.map((row) => {
        const t = Math.max(total(row), 1);
        return (
          <div key={row.campaign}>
            <button
              type="button"
              data-testid={`outcome-bar-campaign-${row.campaign}`}
              onClick={() => onCampaignClick?.(row.campaign)}
              className="text-xs font-medium text-slate-700 hover:text-indigo-600 mb-1"
            >
              <span data-testid="outcome-bar-campaign">{row.campaign}</span>
            </button>
            <div className="flex h-4 rounded overflow-hidden bg-slate-100">
              {SEGMENTS.map((s) => {
                const val = row[s.key];
                if (val === 0) return null;
                const pct = (val / t) * 100;
                return (
                  <button
                    key={s.key}
                    type="button"
                    data-testid={`seg-${row.campaign}-${s.key}`}
                    title={`${row.campaign} · ${s.label}: ${val}`}
                    aria-label={`${row.campaign} ${s.label} ${val}`}
                    onClick={() => onSegmentClick?.({ campaign: row.campaign, outcome: s.key })}
                    className={`${s.color} h-full`}
                    style={{ width: `${pct}%` }}
                  />
                );
              })}
            </div>
          </div>
        );
      })}
    </div>
  );
}
```

- [ ] **Step 4: Run to verify it passes.**

Run: `npx vitest run src/components/charts/CampaignOutcomeBars.test.tsx`
Expected: PASS (4 tests).

- [ ] **Step 5: Typecheck + commit.**

```bash
npx tsc --noEmit
git add frontend/src/components/charts/CampaignOutcomeBars.tsx frontend/src/components/charts/CampaignOutcomeBars.test.tsx
git commit -m "feat(analytics): CampaignOutcomeBars chart with segment/bar drill handlers"
```

---

### Task 2: Swap the Sankey for outcome bars on Analytics

**Files:**
- Modify: `frontend/src/pages/Analytics.tsx` (remove `Sankey` import + usage; mount `CampaignOutcomeBars`; wire drill navigation)
- Delete: `frontend/src/components/charts/Sankey.tsx` (only used by Analytics — verified by grep)
- Test: extend `frontend/src/pages/Analytics.test.tsx` if present; else a focused mount test is optional (the component is covered by Task 1). At minimum, keep the existing Analytics tests green.

**Interfaces:**
- Consumes: `CampaignOutcomeBars`, `CampaignOutcome`, `Outcome` from Task 1; existing Analytics campaign/status data.

- [ ] **Step 1: Read the Sankey's current data mapping.** In `frontend/src/pages/Analytics.tsx`, locate the `<Sankey … />` usage (around line 293) and note exactly which prop(s) feed it (campaign → channel → outcome). Identify the in-scope source for per-campaign converted/in_progress/abandoned counts. If the analytics payload already exposes per-campaign status counts, map them to `CampaignOutcome[]`; if it only exposes totals, derive the three counts from the campaign rows the page already has (`allCampaigns` / `by_campaign` with status). Do NOT add an API call — use data already fetched on the page.

- [ ] **Step 2: Confirm Sankey is unused elsewhere.**

Run (from repo root): `grep -rl "Sankey" frontend/src | grep -v "Sankey.tsx"`
Expected: only `frontend/src/pages/Analytics.tsx`. (If anything else appears, do NOT delete `Sankey.tsx` — only remove it from Analytics.)

- [ ] **Step 3: Replace the Sankey block.** In `Analytics.tsx`:
  1. Remove `import { Sankey } from '@/components/charts/Sankey';` and add `import { CampaignOutcomeBars, type CampaignOutcome, type Outcome } from '@/components/charts/CampaignOutcomeBars';` plus `import { useNavigate } from 'react-router-dom';` if not already imported.
  2. Build `const outcomeData: CampaignOutcome[] = …` from the page's existing campaign+status data (mapped in Step 1).
  3. Add a navigate handler:
     ```tsx
     const navigate = useNavigate();
     const goToJourneys = (campaign: string, outcome?: Outcome) => {
       const p = new URLSearchParams({ campaign });
       if (outcome) p.set('status', outcome); // /marketing/journeys supports status filter
       navigate(`/analytics/journeys?${p.toString()}`);
     };
     ```
  4. Replace the `<Sankey … />` element (keep its surrounding `<Card>`/`<CardHeader title=…>`, just retitle to "Campaign outcomes") with:
     ```tsx
     <CampaignOutcomeBars
       data={outcomeData}
       onSegmentClick={({ campaign, outcome }) => goToJourneys(campaign, outcome)}
       onCampaignClick={(campaign) => goToJourneys(campaign)}
     />
     ```
  5. Leave the "Top campaigns" and "By channel" tables, the Funnel, and TimeSeries untouched.

- [ ] **Step 4: Delete the retired chart** (only if Step 2 confirmed it's unused elsewhere):

```bash
git rm frontend/src/components/charts/Sankey.tsx
```

If `Sankey` is re-exported from a charts barrel (`frontend/src/components/charts/index.ts`), remove that export line too (grep for `Sankey` there).

- [ ] **Step 5: Typecheck + run the Analytics + charts tests.**

Run: `npx tsc --noEmit && npx vitest run src/pages/Analytics.test.tsx src/components/charts`
Expected: PASS (or, if `Analytics.test.tsx` doesn't exist, charts pass and tsc is clean). If an existing Analytics test asserted on the Sankey, update it to assert on the "Campaign outcomes" heading / a `seg-*` testid instead.

- [ ] **Step 6: Commit.**

```bash
git add frontend/src/pages/Analytics.tsx frontend/src/components/charts/
git commit -m "feat(analytics): replace Sankey with clickable CampaignOutcomeBars"
```

---

### Task 3: Overview home — Performance & Health panels

**Files:**
- Create: `frontend/src/components/overview/OverviewPerformancePanel.tsx`
- Create: `frontend/src/components/overview/OverviewHealthPanel.tsx`
- Test: `frontend/src/components/overview/OverviewPerformancePanel.test.tsx`, `frontend/src/components/overview/OverviewHealthPanel.test.tsx`

**Interfaces:**
- Produces:
  ```ts
  // OverviewPerformancePanel
  interface PerfProps { funnel: DominionFunnelResponse | undefined; loading?: boolean }
  export function OverviewPerformancePanel(p: PerfProps): JSX.Element
  // OverviewHealthPanel
  interface HealthProps {
    connected: boolean;
    trackingFiring: boolean;
    errorCount: number;
    updatesAvailable: number;
  }
  export function OverviewHealthPanel(p: HealthProps): JSX.Element
  ```
  (`DominionFunnelResponse` is the existing type from `@/api`.)

- [ ] **Step 1: Write the failing Performance-panel test.** Create `frontend/src/components/overview/OverviewPerformancePanel.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { OverviewPerformancePanel } from './OverviewPerformancePanel';

const funnel = {
  kpis: { landed: 2263, entered: 56, enrolled: 6, conversion_rate: 0.0027 },
  reaching: [
    { stage: 'landed', label: 'Landed', count: 2263 },
    { stage: 'entered', label: 'Entered portal', count: 56 },
  ],
  inside: [{ stage: 'enrolled', label: 'Enrolled', count: 6 }],
  campaigns: [{ campaign: 'DOME2620RS3', landed: 8756 }],
  journeys: { data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 } },
  meta: {
    from: '', to: '', campaign: null, include_test: false,
    attribution_note: '', enrolled_note: '', generated_at: '',
  },
} as any;

describe('OverviewPerformancePanel', () => {
  it('shows the top campaign and links to the full Dominion Funnel', () => {
    render(
      <MemoryRouter>
        <OverviewPerformancePanel funnel={funnel} />
      </MemoryRouter>,
    );
    expect(screen.getByText('DOME2620RS3')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /View funnel/i })).toHaveAttribute(
      'href',
      '/analytics/dominion-funnel',
    );
  });
});
```

- [ ] **Step 2: Run to verify it fails.**

Run: `npx vitest run src/components/overview/OverviewPerformancePanel.test.tsx`
Expected: FAIL — cannot resolve the module.

- [ ] **Step 3: Create `OverviewPerformancePanel.tsx`.**

```tsx
import { Link } from 'react-router-dom';
import type { DominionFunnelResponse } from '@/api';
import { Card } from '@/components/common';
import { Funnel } from '@/components/charts/Funnel';

interface PerfProps {
  funnel: DominionFunnelResponse | undefined;
  loading?: boolean;
}

export function OverviewPerformancePanel({ funnel, loading }: PerfProps) {
  return (
    <Card className="p-4">
      <div className="flex items-center justify-between mb-3">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Performance</h3>
        <Link to="/analytics/dominion-funnel" className="text-xs text-indigo-600 hover:text-indigo-800">
          View funnel →
        </Link>
      </div>

      {loading && <div className="text-sm text-slate-500">Loading…</div>}

      {funnel && (
        <>
          <Funnel stages={funnel.reaching} compact />
          <div className="mt-3">
            <div className="text-[11px] uppercase text-slate-400 mb-1">Top campaigns</div>
            <ul className="space-y-1">
              {funnel.campaigns.slice(0, 5).map((c) => (
                <li key={c.campaign} className="flex justify-between text-sm">
                  <Link
                    to={`/analytics/dominion-funnel?campaign=${encodeURIComponent(c.campaign)}`}
                    className="text-slate-700 hover:text-indigo-600"
                  >
                    {c.campaign}
                  </Link>
                  <span className="tabular-nums text-slate-500">{c.landed.toLocaleString()}</span>
                </li>
              ))}
            </ul>
          </div>
        </>
      )}

      {!loading && !funnel && <div className="text-sm text-slate-500">No performance data yet.</div>}
    </Card>
  );
}
```

- [ ] **Step 4: Run to verify it passes.**

Run: `npx vitest run src/components/overview/OverviewPerformancePanel.test.tsx`
Expected: PASS.

- [ ] **Step 5: Write the failing Health-panel test.** Create `frontend/src/components/overview/OverviewHealthPanel.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { OverviewHealthPanel } from './OverviewHealthPanel';

describe('OverviewHealthPanel', () => {
  it('renders each status line with a link to its page', () => {
    render(
      <MemoryRouter>
        <OverviewHealthPanel connected trackingFiring errorCount={0} updatesAvailable={2} />
      </MemoryRouter>,
    );
    expect(screen.getByRole('link', { name: /Connection/i })).toHaveAttribute('href', '/health');
    expect(screen.getByRole('link', { name: /Tracking Health/i })).toHaveAttribute(
      'href',
      '/analytics/gtm-coverage',
    );
    expect(screen.getByRole('link', { name: /Errors/i })).toHaveAttribute('href', '/errors');
    expect(screen.getByRole('link', { name: /Updates/i })).toHaveAttribute('href', '/updates');
    expect(screen.getByText('2 available')).toBeInTheDocument();
  });
});
```

- [ ] **Step 6: Run to verify it fails, then create `OverviewHealthPanel.tsx`.**

Run: `npx vitest run src/components/overview/OverviewHealthPanel.test.tsx` → FAIL.

```tsx
import { Link } from 'react-router-dom';
import { Card } from '@/components/common';

interface HealthProps {
  connected: boolean;
  trackingFiring: boolean;
  errorCount: number;
  updatesAvailable: number;
}

function Dot({ ok, warn }: { ok?: boolean; warn?: boolean }) {
  const color = warn ? 'text-amber-500' : ok ? 'text-emerald-500' : 'text-red-500';
  return <span className={color} aria-hidden="true">●</span>;
}

export function OverviewHealthPanel({ connected, trackingFiring, errorCount, updatesAvailable }: HealthProps) {
  return (
    <Card className="p-4">
      <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-3">Health</h3>
      <ul className="space-y-2 text-sm">
        <li>
          <Link to="/health" className="flex items-center justify-between hover:text-indigo-600">
            <span className="flex items-center gap-2"><Dot ok={connected} /> Connection</span>
            <span className="text-slate-500">{connected ? 'Connected' : 'Not connected'}</span>
          </Link>
        </li>
        <li>
          <Link to="/analytics/gtm-coverage" className="flex items-center justify-between hover:text-indigo-600">
            <span className="flex items-center gap-2"><Dot ok={trackingFiring} /> Tracking Health</span>
            <span className="text-slate-500">{trackingFiring ? 'Firing' : 'No beacons'}</span>
          </Link>
        </li>
        <li>
          <Link to="/errors" className="flex items-center justify-between hover:text-indigo-600">
            <span className="flex items-center gap-2"><Dot ok={errorCount === 0} /> Errors</span>
            <span className="text-slate-500">{errorCount}</span>
          </Link>
        </li>
        <li>
          <Link to="/updates" className="flex items-center justify-between hover:text-indigo-600">
            <span className="flex items-center gap-2"><Dot ok={updatesAvailable === 0} warn={updatesAvailable > 0} /> Updates</span>
            <span className="text-slate-500">{updatesAvailable > 0 ? `${updatesAvailable} available` : 'Up to date'}</span>
          </Link>
        </li>
      </ul>
    </Card>
  );
}
```

- [ ] **Step 7: Run both panel tests.**

Run: `npx vitest run src/components/overview/`
Expected: PASS (2 files).

- [ ] **Step 8: Typecheck + commit.**

```bash
npx tsc --noEmit
git add frontend/src/components/overview/
git commit -m "feat(overview): Performance and Health panels with drill links"
```

---

### Task 4: Rebuild `Dashboard.tsx` as the Overview home

**Files:**
- Modify: `frontend/src/pages/Dashboard.tsx`
- Test: `frontend/src/pages/Dashboard.test.tsx` (create or extend)

**Interfaces:**
- Consumes: `OverviewPerformancePanel`, `OverviewHealthPanel` (Task 3); `marketingApi.dominionFunnel`, `dashboardApi.get`, `settingsApi.get`.

- [ ] **Step 1: Read the current Dashboard fully.** Read `frontend/src/pages/Dashboard.tsx`. Note: (a) the `isConnected` / `showWelcome` logic and the welcome/connect `Card`; (b) the "Critical Issues Detected" `Alert`; (c) the four existing `StatCard`s; (d) how it reads `dashboard` (from `dashboardApi.get`) and `settings`. These status behaviors are PRESERVED — only the performance presentation is added and the layout becomes the KPI row + two panels.

- [ ] **Step 2: Write the failing Overview test.** Create `frontend/src/pages/Dashboard.test.tsx`:

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { ReactNode } from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import Dashboard from './Dashboard';

const dominionFunnel = vi.fn();
const dashboardGet = vi.fn();
const settingsGet = vi.fn();

vi.mock('@/api', () => ({
  getVersion: () => '0.0.0-test',
  marketingApi: { dominionFunnel: (...a: any[]) => dominionFunnel(...a) },
  dashboardApi: { get: (...a: any[]) => dashboardGet(...a) },
  settingsApi: { get: (...a: any[]) => settingsGet(...a) },
}));

const funnel = {
  kpis: { landed: 2263, entered: 56, enrolled: 6, conversion_rate: 0.0027 },
  reaching: [
    { stage: 'landed', label: 'Landed', count: 2263 },
    { stage: 'entered', label: 'Entered portal', count: 56 },
  ],
  inside: [{ stage: 'enrolled', label: 'Enrolled', count: 6 }],
  campaigns: [{ campaign: 'DOME2620RS3', landed: 8756 }],
  journeys: { data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 } },
  meta: { from: '', to: '', campaign: null, include_test: false, attribution_note: '', enrolled_note: '', generated_at: '' },
};

function wrap(ui: ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  dominionFunnel.mockResolvedValue(funnel);
  dashboardGet.mockResolvedValue({ connected: true, health: { status: 'ok' }, updates: { total: 2 }, errors: { total: 0 } });
  settingsGet.mockResolvedValue({ connected: true });
});

describe('Overview (Dashboard)', () => {
  it('renders the KPI row with a drill link per stage', async () => {
    wrap(<Dashboard />);
    await waitFor(() => expect(screen.getByText('2,263')).toBeInTheDocument());
    expect(screen.getByRole('link', { name: /Landed/i })).toHaveAttribute('href', '/analytics/dominion-funnel');
    expect(screen.getByRole('link', { name: /Entered/i })).toHaveAttribute('href', '/analytics/dominion-funnel?stage=entered');
    expect(screen.getByRole('link', { name: /Enrolled/i })).toHaveAttribute('href', '/analytics/dominion-funnel?stage=enrolled');
  });

  it('renders both the Performance and Health panels', async () => {
    wrap(<Dashboard />);
    await waitFor(() => expect(screen.getByRole('heading', { name: 'Performance' })).toBeInTheDocument());
    expect(screen.getByRole('heading', { name: 'Health' })).toBeInTheDocument();
  });
});
```

Note: the exact shape read from `dashboardApi.get` (the mock above — `connected`, `health.status`, `updates.total`, `errors.total`) MUST match what the real `Dashboard` reads today. In Step 1 you read the real field names; adjust the mock and the panel-prop derivation to the real shape before finalizing. Do not invent fields.

- [ ] **Step 3: Run to verify it fails.**

Run: `npx vitest run src/pages/Dashboard.test.tsx`
Expected: FAIL — no KPI drill links / no "Performance" heading yet.

- [ ] **Step 4: Rewire `Dashboard.tsx`.**
  1. Add `import { marketingApi } from '@/api';` and a query: `const { data: funnel, isLoading: funnelLoading } = useQuery({ queryKey: ['overview-funnel'], queryFn: () => marketingApi.dominionFunnel({}) });`.
  2. Import the panels: `import { OverviewPerformancePanel } from '@/components/overview/OverviewPerformancePanel'; import { OverviewHealthPanel } from '@/components/overview/OverviewHealthPanel';`.
  3. Change the `<Layout title="Dashboard" …>` to `<Layout title="Overview" description="Performance and health at a glance">`.
  4. **Preserve** the `showWelcome` / not-connected `Card` and the "Critical Issues Detected" `Alert` exactly as they are (render them above the new content when their conditions hold).
  5. Add a **KPI row** — replace the four generic `StatCard`s with four funnel-driven KPI tiles, each wrapped in a `Link`:
     ```tsx
     <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
       <KpiTile to="/analytics/dominion-funnel" label="Landed" value={funnel?.kpis.landed} />
       <KpiTile to="/analytics/dominion-funnel?stage=entered" label="Entered" value={funnel?.kpis.entered} />
       <KpiTile to="/analytics/dominion-funnel?stage=enrolled" label="Enrolled" value={funnel?.kpis.enrolled} accent />
       <KpiTile to="/analytics/dominion-funnel" label="Conversion" value={funnel ? `${(funnel.kpis.conversion_rate * 100).toFixed(2)}%` : undefined} />
     </div>
     ```
     with a local component:
     ```tsx
     function KpiTile({ to, label, value, accent }: { to: string; label: string; value: number | string | undefined; accent?: boolean }) {
       return (
         <Link to={to} className="block bg-white border border-slate-200 rounded-lg p-4 hover:border-indigo-300 transition-colors">
           <div className={'text-2xl font-bold ' + (accent ? 'text-emerald-600' : 'text-slate-800')}>
             {value === undefined ? '—' : typeof value === 'number' ? value.toLocaleString() : value}
           </div>
           <div className="text-xs text-slate-500 mt-1">{label}</div>
         </Link>
       );
     }
     ```
     (`Link` accessible name = the label text, satisfying the test's `getByRole('link', { name: /Landed/i })`.)
  6. Add the two-panel row below the KPIs:
     ```tsx
     <div className="grid gap-4 lg:grid-cols-2">
       <OverviewPerformancePanel funnel={funnel} loading={funnelLoading} />
       <OverviewHealthPanel
         connected={/* derive from dashboard/settings — real fields from Step 1 */}
         trackingFiring={/* derive: any gtm beacons / tracking_ready */}
         errorCount={/* dashboard.errors.total or real field */}
         updatesAvailable={/* dashboard.updates.total or real field */}
       />
     </div>
     ```
     Fill the `derive` expressions from the REAL dashboard/settings shape read in Step 1 (e.g. the existing `isConnected`, the existing `totalUpdates` variable already computed in the file). Reuse variables the file already computes rather than re-deriving.
  7. Remove the now-replaced four generic `StatCard`s (their data folds into the KPI row + Health panel). Keep any other genuinely-status cards below the panels if they convey health not covered by the Health panel.

- [ ] **Step 5: Run to verify it passes.**

Run: `npx vitest run src/pages/Dashboard.test.tsx`
Expected: PASS (2 tests).

- [ ] **Step 6: Typecheck + commit.**

```bash
npx tsc --noEmit
git add frontend/src/pages/Dashboard.tsx frontend/src/pages/Dashboard.test.tsx
git commit -m "feat(overview): rebuild Dashboard as two-panel Overview with drillable KPI row"
```

---

### Task 5: "Clicked enroll" → "Entered enrollment portal"

**Files:**
- Modify: `frontend/src/pages/Journeys.tsx`
- Test: `frontend/src/pages/Journeys.entered-portal.test.tsx` (create)

**Interfaces:**
- The relabeled control links to `/analytics/dominion-funnel?stage=entered` (the reliable entered-portal view). The `click_to_portal` pill logic is removed.

**Rationale (verified):** `/marketing/journeys` accepts only `status`/`event_name`/`campaign`/date filters — there is NO entered-portal filter, and API changes are out of scope. `click_to_portal` under-fires (0 of the 6 converters have it). The truthful entered-portal count is computed only by the Dominion Funnel endpoint. So the control becomes a link to that view rather than a list filter that would keep lying.

- [ ] **Step 1: Write the failing test.** Create `frontend/src/pages/Journeys.entered-portal.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import Journeys from './Journeys';

vi.mock('@/api', () => ({
  getVersion: () => '0.0.0-test',
  marketingApi: {
    listJourneys: vi.fn().mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }),
    listUtms: vi.fn().mockResolvedValue({ data: [] }),
    journeyStats: vi.fn().mockResolvedValue({ by_campaign: [] }),
  },
}));

describe('Journeys "Entered enrollment portal" control', () => {
  it('shows the honest label and links to the funnel entered view, not a click_to_portal filter', () => {
    render(
      <MemoryRouter>
        <Journeys />
      </MemoryRouter>,
    );
    const link = screen.getByRole('link', { name: /Entered enrollment portal/i });
    expect(link).toHaveAttribute('href', '/analytics/dominion-funnel?stage=entered');
    // The misleading control is gone.
    expect(screen.queryByText(/Clicked enroll/i)).toBeNull();
  });
});
```

Note: confirm from `Journeys.tsx` which `@/api` methods it actually calls on mount (the mock must cover them, else the render throws). Add any missing ones as `vi.fn().mockResolvedValue(...)`.

- [ ] **Step 2: Run to verify it fails.**

Run: `npx vitest run src/pages/Journeys.entered-portal.test.tsx`
Expected: FAIL — "Clicked enroll" still present / no funnel link.

- [ ] **Step 3: Replace the pill.** In `frontend/src/pages/Journeys.tsx`:
  1. Remove the `toggleEnrollPill` handler and the `<button … onClick={toggleEnrollPill}>Clicked enroll …</button>` element.
  2. Remove the `event_name === 'click_to_portal'` reads that only drove that pill (leave the generic `event_name` URL param plumbing if other filters use it; grep — if nothing else uses `event_name`, remove it too).
  3. Add, in the same filter-bar row, a `Link` styled like the other controls:
     ```tsx
     <Link
       to="/analytics/dominion-funnel?stage=entered"
       className="inline-flex items-center gap-1.5 border border-slate-300 rounded-lg text-sm py-2 px-3 text-slate-700 hover:bg-slate-50"
       title="See everyone who reached the enrollment portal — the reliable signal (the raw enroll-CTA click beacon under-fires)."
     >
       Entered enrollment portal →
     </Link>
     ```
     Ensure `Link` is imported from `react-router-dom` (it already is in this file).

- [ ] **Step 4: Run to verify it passes.**

Run: `npx vitest run src/pages/Journeys.entered-portal.test.tsx`
Expected: PASS.

- [ ] **Step 5: Typecheck + commit.**

```bash
npx tsc --noEmit
git add frontend/src/pages/Journeys.tsx frontend/src/pages/Journeys.entered-portal.test.tsx
git commit -m "fix(journeys): replace misleading 'Clicked enroll' with honest 'Entered enrollment portal' link"
```

---

### Task 6: `JourneyContextPanel` on the journey timeline

**Files:**
- Create: `frontend/src/components/journey/JourneyContextPanel.tsx`
- Modify: `frontend/src/pages/DominionJourneyTimeline.tsx` (mount the panel)
- Test: `frontend/src/components/journey/JourneyContextPanel.test.tsx`

**Interfaces:**
- Produces:
  ```ts
  interface Props { events: JourneyEventRow[]; journey: { pages_viewed: number | null; duration_seconds: number | null } }
  export function JourneyContextPanel(p: Props): JSX.Element
  ```
  (`JourneyEventRow` from `@/api` already carries `device_type`, `browser`, `os`, `country`, `region`, `referrer`, `event_type`, `event_name`, `event_data`.)

- [ ] **Step 1: Write the failing test.** Create `frontend/src/components/journey/JourneyContextPanel.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { JourneyContextPanel } from './JourneyContextPanel';

const events = [
  { id: 1, event_type: 'pageview', event_name: null, page_url: 'x', page_title: null, event_at: '2026-07-20T10:02:14Z',
    device_type: 'mobile', browser: 'Safari', os: 'iOS', country: 'US', region: 'VA', referrer: 'https://ad.example',
    event_data: { depth: 40 } },
  { id: 2, event_type: 'scroll_depth', event_name: 'scroll_depth', page_url: 'x', page_title: null, event_at: '2026-07-20T10:03:00Z',
    event_data: { depth: 80 } },
  { id: 3, event_type: 'exit_intent', event_name: 'exit_intent', page_url: 'x', page_title: null, event_at: '2026-07-20T10:04:00Z' },
] as any;

describe('JourneyContextPanel', () => {
  it('surfaces device/geo/referrer/engagement from the events', () => {
    render(<JourneyContextPanel events={events} journey={{ pages_viewed: 4, duration_seconds: 356 }} />);
    expect(screen.getByText('Mobile · Safari · iOS')).toBeInTheDocument();
    expect(screen.getByText('US · VA')).toBeInTheDocument();
    expect(screen.getByText('ad.example')).toBeInTheDocument();      // referrer host
    expect(screen.getByText('80%')).toBeInTheDocument();             // max scroll depth
    expect(screen.getByText(/exit intent/i)).toBeInTheDocument();    // exit_intent fired
    expect(screen.getByText('5m 56s')).toBeInTheDocument();          // duration
  });

  it('renders — for fields absent from the events', () => {
    render(<JourneyContextPanel events={[]} journey={{ pages_viewed: null, duration_seconds: null }} />);
    expect(screen.getAllByText('—').length).toBeGreaterThan(0);
  });
});
```

- [ ] **Step 2: Run to verify it fails.**

Run: `npx vitest run src/components/journey/JourneyContextPanel.test.tsx`
Expected: FAIL — module not found.

- [ ] **Step 3: Create `JourneyContextPanel.tsx`.**

```tsx
import type { JourneyEventRow } from '@/api';
import { Card } from '@/components/common';

interface Props {
  events: JourneyEventRow[];
  journey: { pages_viewed: number | null; duration_seconds: number | null };
}

function firstDefined<T>(events: JourneyEventRow[], pick: (e: JourneyEventRow) => T | null | undefined): T | undefined {
  for (const e of events) {
    const v = pick(e);
    if (v != null && v !== '') return v as T;
  }
  return undefined;
}

function hostOf(url: string | undefined): string | undefined {
  if (!url) return undefined;
  try {
    return new URL(url).host.replace(/^www\./, '');
  } catch {
    return url;
  }
}

function fmtDuration(sec: number | null): string {
  if (!sec || sec < 1) return '—';
  const m = Math.floor(sec / 60);
  const s = sec % 60;
  return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

function cap(s: string | undefined): string | undefined {
  return s ? s.charAt(0).toUpperCase() + s.slice(1) : undefined;
}

export function JourneyContextPanel({ events, journey }: Props) {
  const device = cap(firstDefined(events, (e) => e.device_type));
  const browser = firstDefined(events, (e) => e.browser);
  const os = firstDefined(events, (e) => e.os);
  const country = firstDefined(events, (e) => e.country);
  const region = firstDefined(events, (e) => e.region);
  const referrer = hostOf(firstDefined(events, (e) => e.referrer ?? undefined));

  const maxScroll = events.reduce((max, e) => {
    const d = (e.event_data as { depth?: number } | null | undefined)?.depth;
    return typeof d === 'number' && d > max ? d : max;
  }, 0);
  const exitIntent = events.some((e) => e.event_name === 'exit_intent' || e.event_type === 'exit_intent');

  const deviceStr = [device, browser, os].filter(Boolean).join(' · ') || '—';
  const geoStr = [country, region].filter(Boolean).join(' · ') || '—';

  const Row = ({ label, value }: { label: string; value: string }) => (
    <div className="flex justify-between text-sm py-1 border-b border-slate-50 last:border-0">
      <span className="text-slate-500">{label}</span>
      <span className="text-slate-800 text-right">{value}</span>
    </div>
  );

  return (
    <Card className="p-4">
      <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Context</h3>
      <Row label="Device" value={deviceStr} />
      <Row label="Location" value={geoStr} />
      <Row label="Referrer" value={referrer ?? '—'} />
      <Row label="Pages viewed" value={journey.pages_viewed != null ? String(journey.pages_viewed) : '—'} />
      <Row label="Time on site" value={fmtDuration(journey.duration_seconds)} />
      <Row label="Max scroll" value={maxScroll > 0 ? `${maxScroll}%` : '—'} />
      <Row label="Exit intent" value={exitIntent ? 'Yes — showed exit intent' : 'No'} />
    </Card>
  );
}
```

- [ ] **Step 4: Run to verify it passes.**

Run: `npx vitest run src/components/journey/JourneyContextPanel.test.tsx`
Expected: PASS (2 tests).

- [ ] **Step 5: Mount it in the timeline.** In `frontend/src/pages/DominionJourneyTimeline.tsx`, import the panel and render it beside/above the existing timeline, passing the data already loaded (`data.events` and `data.journey`):

```tsx
import { JourneyContextPanel } from '@/components/journey/JourneyContextPanel';
// …inside the render, where `data` is available:
<JourneyContextPanel events={data.events} journey={{ pages_viewed: data.journey.pages_viewed, duration_seconds: data.journey.duration_seconds }} />
```

Place it in the existing layout (e.g. above the timeline `Card` or in a two-column grid with it). Confirm `data.journey` exposes `pages_viewed` and `duration_seconds` (it does per `journeyDetail`).

- [ ] **Step 6: Typecheck + the timeline test.**

Run: `npx tsc --noEmit && npx vitest run src/pages/DominionJourneyTimeline.test.tsx src/components/journey/`
Expected: PASS (update the timeline test only if mounting the panel changed a queried string; the panel adds content, it shouldn't break existing assertions).

- [ ] **Step 7: Commit.**

```bash
git add frontend/src/components/journey/ frontend/src/pages/DominionJourneyTimeline.tsx
git commit -m "feat(journey): context panel — device, geo, referrer, engagement from existing events"
```

---

### Task 7: Full suite, build, commit bundle

- [ ] **Step 1: Full frontend suite.**

Run: `npm run test:run`
Expected: all green (new Overview/panels/outcome-bars/entered-portal/context-panel tests + everything unchanged). If any page test that mounts `Layout` now fails on a missing `getVersion` mock, add `getVersion: () => '0.0.0-test'` to that test's `vi.mock('@/api', …)`.

- [ ] **Step 2: Production build.**

Run: `npm run build`
Expected: clean.

- [ ] **Step 3: Verify the new UI compiled in.**

Run (from repo root): `grep -c "Entered enrollment portal" assets/dist/js/main.js && grep -c "Campaign outcomes" assets/dist/js/main.js`
Expected: both ≥ 1.

- [ ] **Step 4: Commit the rebuilt bundle.**

```bash
git add assets/dist/
git commit -m "build(assets): rebuild dist for Overview + Analytics redesign"
```

---

### Final verification

- [ ] `npm run test:run` green; `npm run build` clean; `assets/dist` committed and contains "Entered enrollment portal" + "Campaign outcomes".
- [ ] `grep -rl "Sankey" frontend/src` returns nothing (fully retired) — or only a deliberate keep, documented.
- [ ] KPI tiles link to the three funnel stages; outcome-bar segments drill to `/analytics/journeys?campaign=…&status=…`; the "Entered enrollment portal" control links to `/analytics/dominion-funnel?stage=entered`; the journey timeline shows the context panel.
- [ ] **Do NOT bump the version or release.** Ships via the signed pipeline as a minor bump, after Phases 1 & 2.

## Notes / deferred

- **Design decision (flagged):** the "Entered enrollment portal" control is a **link to the funnel's entered view**, not a client-side list filter, because `/marketing/journeys` can't filter by entered-portal and API changes are out of scope. If a true in-list "entered" filter is wanted later, it needs a small HUB param — logged with the beacon fix.
- **Tracking task (separate):** fix the `click_to_portal` beacon so the raw enroll-CTA click fires reliably (GTM/tracking-code).
- **Phase 4:** breakdown charts — device, geography, referrer, time-of-day/day-of-week — plus scroll-depth / exit-intent analytics across journeys.
