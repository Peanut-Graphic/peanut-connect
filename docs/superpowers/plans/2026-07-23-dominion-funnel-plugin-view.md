# Dominion Funnel — End-to-End Plugin View Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Dominion Funnel" analytics view to the End-to-End (peanut-connect) WordPress plugin that renders the marketing→portal→enrollment funnel served by HUB's new `GET /api/v1/marketing/dominion-funnel` endpoint.

**Architecture:** HUB is the source of truth and does all aggregation. The plugin adds (1) a PHP REST proxy route that forwards to HUB via the existing signed `forward()` proxy — no new auth; (2) a typed axios service method + response types; (3) a React page (`DominionFunnel.tsx`) cloned from `CampaignStory.tsx` that renders a filter bar, a KPI row, a two-part funnel (via the shared `Funnel` chart), and a stacked journeys table whose rows drill into the existing `/analytics/journeys/:clickId` timeline; (4) an App route + Sidebar entry.

**Tech Stack:** React 18.3 + TypeScript 5.7, @tanstack/react-query 5.62, axios 1.7, react-router-dom 6.28 (HashRouter), Tailwind v4, lucide-react. Vitest 4.1 + @testing-library/react 16 + jsdom (frontend). PHPUnit 9.6 (PHP). Plugin peanut-connect v3.21.2.

## Global Constraints

- REST namespace is `peanut-connect/v1`; the plugin's browser base path is `/wp-json/peanut-connect/v1`; HUB paths passed to `forward()` are under `/api/v1` and MUST start with `/` (e.g. `/marketing/dominion-funnel`).
- **No new auth.** Reuse `Peanut_Connect_Marketing::forward()` exactly as `gtm_coverage` does. `forward('GET', $path, null, $request->get_query_params())`.
- **Eager imports only** in `App.tsx` — `React.lazy` was reverted (chunks 404 under the wp-admin base path). See the header comment in `frontend/src/App.tsx`.
- **Reuse, don't duplicate:** use the existing `FunnelStage` type (`{ stage; label; count }`) from `@/api` and the shared `Funnel` component at `frontend/src/components/charts/Funnel.tsx`. Drill-down reuses the existing `/analytics/journeys/:clickId` route + `JourneyDetail` page — do NOT build a new journey-detail page.
- **HUB response contract** (post-`flattenApiResponse`, one level; see Task 2): `{ kpis:{landed,entered,enrolled,conversion_rate}, reaching:FunnelStage[], inside:FunnelStage[], campaigns:{campaign,landed}[], journeys:{ data:{click_id,campaign,entered_at,furthest_step,duration_seconds,status}[], meta:{current_page,last_page,per_page,total} }, meta:{from,to,campaign,include_test,attribution_note,enrolled_note,generated_at} }`.
- Endpoint query params: `from`, `to` (ISO dates), `campaign` (string), `include_test` (bool), `stage` (one of `landed|entered|validation|login|dashboard|enrolled`), `page`, `per_page` (max 200).
- The two honesty footnotes shown under the funnel come from `meta.attribution_note` and `meta.enrolled_note` — render them from the response, do NOT hardcode.
- Canonical in-portal step order was resolved HUB-side in the endpoint (`INSIDE_STEPS = [validation, login, dashboard, enrolled]`). The plugin renders `reaching`/`inside` in the order HUB returns them — do NOT re-derive or re-sort.
- **Do NOT bump the plugin version or run any release/publish step.** The plugin ships via its signed release pipeline (Nat's action). This plan ends at a green branch + build.

---

### Task 1: PHP proxy route + `dominion_funnel()` method

**Files:**
- Modify: `includes/class-connect-marketing.php` (add one `register_rest_route` block in `register_routes()`, and one `dominion_funnel()` static method next to `gtm_coverage()`)
- Test: `tests/Test_Dominion_Funnel.php` (create)

**Interfaces:**
- Consumes: `Peanut_Connect_Marketing::forward(string $method, string $path, ?array $body, ?array $query)` — returns `WP_REST_Response` on success or `WP_Error` (status 412 when Hub URL/key not configured, before any HTTP call).
- Produces: REST route `GET peanut-connect/v1/marketing/dominion-funnel` and static method `Peanut_Connect_Marketing::dominion_funnel(WP_REST_Request $request)`.

- [ ] **Step 1: Read the clone source and the test harness.** Read `includes/class-connect-marketing.php` — locate the `gtm_coverage` route registration inside `register_routes()` (registered with `$ns = PEANUT_CONNECT_API_NAMESPACE;` and `$perms = [self::class, 'check_admin_permission'];`) and the `gtm_coverage()` method. Read `tests/Test_Tracking_Setup_Key.php` and `tests/bootstrap.php` to learn the exact stub setup this suite uses (how `WP_Error`, `WP_REST_Request`, and `get_option()`/`$mock_options` are made available; some stubs are defined in the test file with `if (!class_exists(...))` / `if (!function_exists(...))` guards). Mirror that harness pattern in the new test.

- [ ] **Step 2: Write the failing test.** Create `tests/Test_Dominion_Funnel.php`. It asserts that with no Hub configured (empty options), `dominion_funnel()` returns a `WP_Error` carrying `['status' => 412]` — this exercises the delegation to `forward()` on its network-free disconnected path (`forward()` returns the 412 `WP_Error` before calling `wp_remote_request` when `hub_url === '' || api_key === ''`).

```php
<?php
// tests/Test_Dominion_Funnel.php
// Mirror the stub/bootstrap pattern used by Test_Tracking_Setup_Key.php.
// If WP_Error / WP_REST_Request / get_option are not already provided by
// tests/bootstrap.php, define guarded stubs here exactly as that test does.

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-connect-marketing.php';

class Test_Dominion_Funnel extends TestCase {
    protected function setUp(): void {
        global $mock_options;
        // Disconnected: no Hub URL, no API key -> forward() short-circuits to 412.
        $mock_options = [];
    }

    public function test_dominion_funnel_delegates_to_forward_and_reports_not_connected(): void {
        $request = new WP_REST_Request('GET', '/peanut-connect/v1/marketing/dominion-funnel');
        $result  = Peanut_Connect_Marketing::dominion_funnel($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $data = $result->get_error_data();
        $this->assertSame(412, is_array($data) ? ($data['status'] ?? null) : $data);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails.**

Run: `composer test -- --filter Test_Dominion_Funnel`
Expected: FAIL — `dominion_funnel()` does not exist yet (`Error: Call to undefined method`).

- [ ] **Step 4: Add the route registration.** In `register_routes()`, immediately after the `gtm_coverage` `register_rest_route(...)` block, add:

```php
register_rest_route($ns, '/marketing/dominion-funnel', [
    'methods'             => 'GET',
    'callback'            => [self::class, 'dominion_funnel'],
    'permission_callback' => $perms,
]);
```

- [ ] **Step 5: Add the callback method.** Next to `gtm_coverage()`, add (a verbatim clone of the `gtm_coverage` one-liner, different HUB path):

```php
public static function dominion_funnel(WP_REST_Request $request) {
    return self::forward('GET', '/marketing/dominion-funnel', null, $request->get_query_params());
}
```

- [ ] **Step 6: Run the test to verify it passes.**

Run: `composer test -- --filter Test_Dominion_Funnel`
Expected: PASS (1 test).

- [ ] **Step 7: Run the full PHP suite to confirm no regression.**

Run: `composer test`
Expected: all green.

- [ ] **Step 8: Commit.**

```bash
git add includes/class-connect-marketing.php tests/Test_Dominion_Funnel.php
git commit -m "feat(marketing): proxy route for Dominion funnel endpoint"
```

---

### Task 2: Frontend API service method + response types

**Files:**
- Modify: `frontend/src/api/marketing.ts` (add types + `dominionFunnel` method on the `marketingApi` object)
- Modify: `frontend/src/api/index.ts` (re-export the new types if the barrel uses explicit exports rather than `export *`)
- Test: `frontend/src/api/marketing.dominion-funnel.test.ts` (create)

**Interfaces:**
- Consumes: the shared axios client `api` (default export of `./client`) whose response interceptor runs `flattenApiResponse` (spreads the inner `data` object AND top-level siblings like `meta` up one level). The existing `FunnelStage` interface (`{ stage: string; label: string; count: number }`) already in `marketing.ts`.
- Produces: `marketingApi.dominionFunnel(params: DominionFunnelParams): Promise<DominionFunnelResponse>` and the exported types `DominionFunnelParams`, `DominionFunnelResponse`, `DominionFunnelKpis`, `DominionCampaignCount`, `DominionJourneyRow`, `DominionJourneysPage`, `DominionFunnelMeta`.

- [ ] **Step 1: Write the failing test.** Create `frontend/src/api/marketing.dominion-funnel.test.ts`. It mocks `./client`, resolves `api.get` with (a) the flattened runtime shape and (b) the raw `{success,data,meta}` shape, and asserts the method calls `/marketing/dominion-funnel` with params and returns a normalized object whose `meta` (honesty notes) survives in both cases.

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import api from './client';
import { marketingApi } from './marketing';

vi.mock('./client');

const inner = {
  kpis: { landed: 2263, entered: 56, enrolled: 6, conversion_rate: 0.002652 },
  reaching: [
    { stage: 'landed', label: 'Landed', count: 2263 },
    { stage: 'entered', label: 'Entered portal', count: 56 },
  ],
  inside: [
    { stage: 'entered', label: 'Entered', count: 56 },
    { stage: 'validation', label: 'Validation', count: 32 },
    { stage: 'login', label: 'Login', count: 30 },
    { stage: 'dashboard', label: 'Dashboard', count: 9 },
    { stage: 'enrolled', label: 'Enrolled', count: 6 },
  ],
  campaigns: [{ campaign: 'DOME2620RS1', landed: 1800 }],
  journeys: {
    data: [
      {
        click_id: 'abc123',
        campaign: 'DOME2620RS1',
        entered_at: '2026-07-20T19:12:00Z',
        furthest_step: 'dashboard',
        duration_seconds: 356,
        status: 'in_progress',
      },
    ],
    meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
  },
};
const meta = {
  from: '2026-06-23',
  to: '2026-07-23',
  campaign: null,
  include_test: false,
  attribution_note: 'attr-note',
  enrolled_note: 'enr-note',
  generated_at: '2026-07-23T00:00:00+00:00',
};

describe('marketingApi.dominionFunnel', () => {
  beforeEach(() => vi.clearAllMocks());

  it('GETs the endpoint with params and returns the parsed envelope (flattened runtime shape)', async () => {
    (api.get as any).mockResolvedValue({ data: { ...inner, meta } });
    const out = await marketingApi.dominionFunnel({
      from: '2026-06-23',
      to: '2026-07-23',
      include_test: false,
    });
    expect(api.get).toHaveBeenCalledWith('/marketing/dominion-funnel', {
      params: { from: '2026-06-23', to: '2026-07-23', include_test: false },
    });
    expect(out.kpis.enrolled).toBe(6);
    expect(out.reaching).toHaveLength(2);
    expect(out.inside).toHaveLength(5);
    expect(out.journeys.data[0].furthest_step).toBe('dashboard');
    expect(out.journeys.meta.total).toBe(1);
    expect(out.meta.attribution_note).toBe('attr-note');
  });

  it('preserves top-level meta when the client returns the raw {data,meta} envelope', async () => {
    (api.get as any).mockResolvedValue({ data: { success: true, data: inner, meta } });
    const out = await marketingApi.dominionFunnel({});
    expect(out.meta.enrolled_note).toBe('enr-note');
    expect(out.kpis.landed).toBe(2263);
    expect(out.campaigns[0].campaign).toBe('DOME2620RS1');
  });
});
```

- [ ] **Step 2: Run the test to verify it fails.**

Run: `cd frontend && npx vitest run src/api/marketing.dominion-funnel.test.ts`
Expected: FAIL — `marketingApi.dominionFunnel is not a function`.

- [ ] **Step 3: Add the types.** In `frontend/src/api/marketing.ts`, near the existing `FunnelStage`/`GtmCoverageResponse` interfaces, add (reuse the existing `FunnelStage` — do NOT redefine it):

```ts
export interface DominionFunnelKpis {
  landed: number;
  entered: number;
  enrolled: number;
  conversion_rate: number;
}

export interface DominionCampaignCount {
  campaign: string;
  landed: number;
}

export interface DominionJourneyRow {
  click_id: string;
  campaign: string | null;
  entered_at: string | null;
  furthest_step: string;
  duration_seconds: number;
  status: string;
}

export interface DominionJourneysPage {
  data: DominionJourneyRow[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

export interface DominionFunnelMeta {
  from: string;
  to: string;
  campaign: string | null;
  include_test: boolean;
  attribution_note: string;
  enrolled_note: string;
  generated_at: string;
}

export interface DominionFunnelResponse {
  kpis: DominionFunnelKpis;
  reaching: FunnelStage[];
  inside: FunnelStage[];
  campaigns: DominionCampaignCount[];
  journeys: DominionJourneysPage;
  meta: DominionFunnelMeta;
}

export interface DominionFunnelParams {
  from?: string;
  to?: string;
  campaign?: string;
  include_test?: boolean;
  stage?: string;
  page?: number;
  per_page?: number;
}
```

- [ ] **Step 4: Add the service method.** Inside the `export const marketingApi = { ... }` object (mirror the defensive unwrap used by `gtmCoverage`/`campaignStory`), add:

```ts
  dominionFunnel: async (
    params: DominionFunnelParams = {},
  ): Promise<DominionFunnelResponse> => {
    const res = await api.get('/marketing/dominion-funnel', { params });
    const body = (res.data ?? {}) as Record<string, any>;
    // Flattened runtime: body already has kpis/…/meta at top level (body.data undefined).
    // Raw (e.g. mocked client, interceptor not run): inner under body.data, meta sibling.
    const d = (body.data ?? body) as Record<string, any>;
    const metaObj = (body.meta ?? d.meta ?? {}) as DominionFunnelMeta;
    return {
      kpis: d.kpis ?? { landed: 0, entered: 0, enrolled: 0, conversion_rate: 0 },
      reaching: d.reaching ?? [],
      inside: d.inside ?? [],
      campaigns: d.campaigns ?? [],
      journeys: d.journeys ?? {
        data: [],
        meta: { current_page: 1, last_page: 1, per_page: 0, total: 0 },
      },
      meta: metaObj,
    };
  },
```

- [ ] **Step 5: Re-export the types from the barrel.** Open `frontend/src/api/index.ts`. If it uses `export * from './marketing'`, no change is needed (verify). If it lists types explicitly, add `DominionFunnelResponse`, `DominionFunnelParams`, `DominionFunnelKpis`, `DominionCampaignCount`, `DominionJourneyRow`, `DominionJourneysPage`, and `DominionFunnelMeta` to the exported list so pages can `import type { DominionFunnelParams } from '@/api'`.

- [ ] **Step 6: Run the test to verify it passes.**

Run: `cd frontend && npx vitest run src/api/marketing.dominion-funnel.test.ts`
Expected: PASS (2 tests).

- [ ] **Step 7: Typecheck.**

Run: `cd frontend && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 8: Commit.**

```bash
git add frontend/src/api/marketing.ts frontend/src/api/index.ts frontend/src/api/marketing.dominion-funnel.test.ts
git commit -m "feat(marketing): dominionFunnel API service + response types"
```

---

### Task 3: Make the shared `Funnel` chart stages clickable (additive)

**Files:**
- Modify: `frontend/src/components/charts/Funnel.tsx` (add optional `onStageClick` prop; when provided, each stage becomes a `<button>`)
- Test: `frontend/src/components/charts/Funnel.test.tsx` (create, or extend if it already exists)

**Interfaces:**
- Consumes: existing `FunnelProps` (`{ stages: FunnelStage[]; compact?: boolean }`).
- Produces: extended `FunnelProps` with `onStageClick?: (stage: string) => void`. When omitted, rendering is byte-for-behavior identical to today (no buttons) so the existing `Analytics.tsx` consumer is unaffected.

- [ ] **Step 1: Read `Funnel.tsx`.** Note the named export `Funnel`, the `STAGE_COLORS`/`colorFor` helpers, the empty state (`No funnel data.`), and the `<ol>` of stages with per-stage width `Math.max(2, (count/top)*100)`.

- [ ] **Step 2: Write the failing test.** Create `frontend/src/components/charts/Funnel.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { Funnel } from './Funnel';

const stages = [
  { stage: 'landed', label: 'Landed', count: 100 },
  { stage: 'entered', label: 'Entered', count: 20 },
];

describe('Funnel', () => {
  it('renders stage labels', () => {
    render(<Funnel stages={stages} />);
    expect(screen.getByText('Landed')).toBeInTheDocument();
    expect(screen.getByText('Entered')).toBeInTheDocument();
  });

  it('renders no buttons when onStageClick is absent', () => {
    render(<Funnel stages={stages} />);
    expect(screen.queryByRole('button')).toBeNull();
  });

  it('calls onStageClick with the stage id when a stage is clicked', () => {
    const onStageClick = vi.fn();
    render(<Funnel stages={stages} onStageClick={onStageClick} />);
    fireEvent.click(screen.getByRole('button', { name: /Entered/i }));
    expect(onStageClick).toHaveBeenCalledWith('entered');
  });

  it('renders the empty state for no stages', () => {
    render(<Funnel stages={[]} />);
    expect(screen.getByText('No funnel data.')).toBeInTheDocument();
  });
});
```

- [ ] **Step 3: Run the test to verify it fails.**

Run: `cd frontend && npx vitest run src/components/charts/Funnel.test.tsx`
Expected: FAIL on the onStageClick case (no button rendered).

- [ ] **Step 4: Add the prop and make stages clickable.** Edit `Funnel.tsx`:
  1. Extend the props interface: add `onStageClick?: (stage: string) => void;` and destructure it in the component signature (`export function Funnel({ stages, compact = false, onStageClick }: FunnelProps)`).
  2. In the per-stage `<li>` render, when `onStageClick` is defined, wrap the stage's clickable content in a `<button type="button" onClick={() => onStageClick(s.stage)} className="w-full text-left cursor-pointer" aria-label={s.label}>...</button>`; when it is undefined, render exactly as before (no button wrapper). Keep the bar/label/count markup identical inside; only the interactive wrapper is conditional. Preserve the accessible name (`aria-label={s.label}`) so `getByRole('button', { name: /Entered/i })` resolves.

- [ ] **Step 5: Run the test to verify it passes.**

Run: `cd frontend && npx vitest run src/components/charts/Funnel.test.tsx`
Expected: PASS (4 tests).

- [ ] **Step 6: Run the existing charts/Analytics tests to confirm no regression** (the `Analytics.tsx` consumer must be unaffected).

Run: `cd frontend && npx vitest run src/components/charts src/pages/Analytics.test.tsx 2>/dev/null || cd frontend && npx vitest run src/components/charts`
Expected: PASS (run whichever exist).

- [ ] **Step 7: Commit.**

```bash
git add frontend/src/components/charts/Funnel.tsx frontend/src/components/charts/Funnel.test.tsx
git commit -m "feat(charts): optional clickable stages on Funnel (onStageClick)"
```

---

### Task 4: `DominionFunnel.tsx` page

**Files:**
- Create: `frontend/src/pages/DominionFunnel.tsx`
- Test: `frontend/src/pages/DominionFunnel.test.tsx`

**Interfaces:**
- Consumes: `marketingApi.dominionFunnel` + `DominionFunnelParams` from `@/api` (Task 2); the extended `Funnel` with `onStageClick` (Task 3); `Layout` (default export `@/components/layout/Layout`); `Card` from `@/components/common`.
- Produces: default-exported `DominionFunnel` React component (consumed by Task 5's route).

- [ ] **Step 1: Write the failing render test.** Create `frontend/src/pages/DominionFunnel.test.tsx` (mirror the wrapper style of `frontend/src/pages/Settings.test.tsx`):

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { ReactNode } from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import DominionFunnel from './DominionFunnel';

const fixture = {
  kpis: { landed: 2263, entered: 56, enrolled: 6, conversion_rate: 0.002652 },
  reaching: [
    { stage: 'landed', label: 'Landed', count: 2263 },
    { stage: 'entered', label: 'Entered portal', count: 56 },
  ],
  inside: [
    { stage: 'entered', label: 'Entered', count: 56 },
    { stage: 'validation', label: 'Validation', count: 32 },
    { stage: 'login', label: 'Login', count: 30 },
    { stage: 'dashboard', label: 'Dashboard', count: 9 },
    { stage: 'enrolled', label: 'Enrolled', count: 6 },
  ],
  campaigns: [{ campaign: 'DOME2620RS1', landed: 1800 }],
  journeys: {
    data: [
      {
        click_id: 'abc123',
        campaign: 'DOME2620RS1',
        entered_at: '2026-07-20T19:12:00Z',
        furthest_step: 'dashboard',
        duration_seconds: 356,
        status: 'in_progress',
      },
    ],
    meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
  },
  meta: {
    from: '2026-06-23',
    to: '2026-07-23',
    campaign: null,
    include_test: false,
    attribution_note: 'Attribution can undercount.',
    enrolled_note: 'Enrolled = portal success, not IS-confirmed.',
    generated_at: '2026-07-23T00:00:00+00:00',
  },
};

const dominionFunnel = vi.fn();
vi.mock('@/api', () => ({
  marketingApi: { dominionFunnel: (...a: any[]) => dominionFunnel(...a) },
}));

function wrap(ui: ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('DominionFunnel', () => {
  beforeEach(() => {
    dominionFunnel.mockReset();
    dominionFunnel.mockResolvedValue(fixture);
  });

  it('renders KPIs, both funnels, footnotes, and the journeys table', async () => {
    wrap(<DominionFunnel />);
    await waitFor(() => expect(screen.getByText('Reaching the portal')).toBeInTheDocument());
    expect(screen.getByText('Inside the flow')).toBeInTheDocument();
    expect(screen.getByText('2,263')).toBeInTheDocument(); // Landed KPI
    expect(screen.getByText('Attribution can undercount.')).toBeInTheDocument();
    expect(screen.getByText('View timeline →')).toBeInTheDocument();
  });

  it('re-queries with a stage filter when a funnel stage is clicked', async () => {
    wrap(<DominionFunnel />);
    await waitFor(() =>
      expect(screen.getAllByRole('button', { name: /Dashboard/i }).length).toBeGreaterThan(0),
    );
    fireEvent.click(screen.getAllByRole('button', { name: /Dashboard/i })[0]);
    await waitFor(() =>
      expect(dominionFunnel).toHaveBeenCalledWith(expect.objectContaining({ stage: 'dashboard' })),
    );
  });

  it('shows the empty state when there are no journeys', async () => {
    dominionFunnel.mockResolvedValue({
      ...fixture,
      journeys: { data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 } },
    });
    wrap(<DominionFunnel />);
    await waitFor(() => expect(screen.getByText('No journeys in this range.')).toBeInTheDocument());
  });
});
```

- [ ] **Step 2: Run the test to verify it fails.**

Run: `cd frontend && npx vitest run src/pages/DominionFunnel.test.tsx`
Expected: FAIL — cannot resolve `./DominionFunnel`.

- [ ] **Step 3: Create the page.** Create `frontend/src/pages/DominionFunnel.tsx`:

```tsx
import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { marketingApi } from '@/api';
import type { DominionFunnelParams } from '@/api';
import Layout from '@/components/layout/Layout';
import { Card } from '@/components/common';
import { Funnel } from '@/components/charts/Funnel';

const RANGES = [
  { label: 'Last 7 days', value: 7 },
  { label: 'Last 30 days', value: 30 },
  { label: 'Last 90 days', value: 90 },
] as const;

const STEP_LABELS: Record<string, string> = {
  landed: 'Landed',
  entered: 'Entered portal',
  validation: 'Validation',
  login: 'Login',
  dashboard: 'Dashboard',
  enrolled: 'Enrolled',
};

function rangeFromDays(days: number): { from: string; to: string } {
  const to = new Date();
  const from = new Date(to.getTime() - days * 24 * 60 * 60 * 1000);
  return { from: from.toISOString().slice(0, 10), to: to.toISOString().slice(0, 10) };
}

function fmtDuration(seconds: number): string {
  if (!seconds || seconds < 1) return '—';
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

export default function DominionFunnel() {
  const [days, setDays] = useState<(typeof RANGES)[number]['value']>(30);
  const [customFrom, setCustomFrom] = useState('');
  const [customTo, setCustomTo] = useState('');
  const [campaign, setCampaign] = useState('');
  const [showTest, setShowTest] = useState(false);
  const [stage, setStage] = useState('');
  const [page, setPage] = useState(1);

  const isCustomRange = customFrom !== '' && customTo !== '';
  const range = isCustomRange ? { from: customFrom, to: customTo } : rangeFromDays(days);

  // Stable dropdown: query without a campaign filter so the option list never
  // collapses to just the selected campaign. per_page:1 keeps the payload small.
  const campaignsQuery = useQuery({
    queryKey: ['dominion-funnel-campaigns', { from: range.from, to: range.to, showTest }],
    queryFn: () =>
      marketingApi.dominionFunnel({
        from: range.from,
        to: range.to,
        include_test: showTest,
        per_page: 1,
      }),
  });
  const campaignOptions = useMemo(
    () => (campaignsQuery.data?.campaigns ?? []).map((c) => c.campaign),
    [campaignsQuery.data],
  );

  const params: DominionFunnelParams = {
    from: range.from,
    to: range.to,
    campaign: campaign || undefined,
    include_test: showTest,
    stage: stage || undefined,
    page,
    per_page: 50,
  };
  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['dominion-funnel', { ...params }],
    queryFn: () => marketingApi.dominionFunnel(params),
  });

  // Any filter change resets pagination to page 1.
  const withPageReset = (fn: () => void) => {
    fn();
    setPage(1);
  };
  const onStageClick = (s: string) =>
    withPageReset(() => setStage((cur) => (cur === s ? '' : s)));

  return (
    <Layout
      title="Dominion Funnel"
      description="Marketing → portal → enrollment funnel for the Dominion Peak Time Rebates program."
      action={
        <Link to="/analytics" className="text-sm text-slate-500 hover:text-slate-700">
          ← Analytics
        </Link>
      }
    >
      <Card className="mb-4">
        <div className="flex flex-wrap items-center gap-2 p-4">
          <div className="flex items-center gap-1">
            {RANGES.map((r) => (
              <button
                key={r.value}
                type="button"
                onClick={() =>
                  withPageReset(() => {
                    setDays(r.value);
                    setCustomFrom('');
                    setCustomTo('');
                  })
                }
                className={
                  !isCustomRange && days === r.value
                    ? 'px-3 py-1.5 text-sm font-medium rounded bg-primary-600 text-white'
                    : 'px-3 py-1.5 text-sm font-medium rounded text-slate-600 hover:bg-slate-100'
                }
              >
                {r.label}
              </button>
            ))}
          </div>
          <div className="flex items-center gap-1 pl-2 border-l border-slate-200">
            <input
              type="date"
              value={customFrom}
              max={customTo || undefined}
              onChange={(e) => withPageReset(() => setCustomFrom(e.target.value))}
              aria-label="Custom range start"
              className="border border-slate-300 rounded text-xs py-1 px-2"
            />
            <span className="text-xs text-slate-400">→</span>
            <input
              type="date"
              value={customTo}
              min={customFrom || undefined}
              onChange={(e) => withPageReset(() => setCustomTo(e.target.value))}
              aria-label="Custom range end"
              className="border border-slate-300 rounded text-xs py-1 px-2"
            />
          </div>
          <select
            value={campaign}
            onChange={(e) => withPageReset(() => setCampaign(e.target.value))}
            className="border border-slate-300 rounded-lg text-sm py-2 pl-3 pr-8 max-w-[14rem]"
          >
            <option value="">All campaigns</option>
            {campaignOptions.map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </select>
          <button
            type="button"
            onClick={() => withPageReset(() => setShowTest((v) => !v))}
            className={
              'inline-flex items-center gap-1.5 border rounded-lg text-sm py-2 px-3 transition-colors ' +
              (showTest
                ? 'bg-amber-100 border-amber-300 text-amber-800'
                : 'border-slate-300 text-slate-700 hover:bg-slate-50')
            }
            title="Include QA / test campaigns in the funnel and campaign list"
          >
            Show test campaigns
          </button>
          {stage && (
            <button
              type="button"
              onClick={() => onStageClick(stage)}
              className="text-sm text-slate-500 hover:text-slate-700 px-2 py-2"
            >
              Clear stage: {STEP_LABELS[stage] ?? stage} ×
            </button>
          )}
        </div>
      </Card>

      {isLoading && <Card className="p-6 text-sm text-slate-500">Loading funnel…</Card>}
      {isError && (
        <Card className="p-6 text-sm text-red-600">
          Failed to load funnel: {(error as Error)?.message}
        </Card>
      )}

      {data && (
        <>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <Kpi label="Landed" value={data.kpis.landed.toLocaleString()} />
            <Kpi label="Entered portal" value={data.kpis.entered.toLocaleString()} />
            <Kpi label="Enrolled" value={data.kpis.enrolled.toLocaleString()} accent />
            <Kpi label="Conversion" value={`${(data.kpis.conversion_rate * 100).toFixed(2)}%`} />
          </div>

          <div className="grid gap-4 lg:grid-cols-2 mb-2">
            <Card className="p-4">
              <h3 className="text-xs font-semibold uppercase text-slate-500 mb-3">
                Reaching the portal
              </h3>
              <Funnel stages={data.reaching} onStageClick={onStageClick} />
            </Card>
            <Card className="p-4">
              <h3 className="text-xs font-semibold uppercase text-slate-500 mb-3">
                Inside the flow
              </h3>
              <Funnel stages={data.inside} onStageClick={onStageClick} />
            </Card>
          </div>

          <div className="text-xs text-slate-400 space-y-1 mb-4">
            <p>{data.meta.attribution_note}</p>
            <p>{data.meta.enrolled_note}</p>
          </div>

          <Card>
            <div className="flex items-center justify-between p-4 border-b border-slate-100">
              <h3 className="text-sm font-semibold text-slate-700">
                Journeys{stage ? ` · ${STEP_LABELS[stage] ?? stage}` : ''}
              </h3>
              <span className="text-xs text-slate-400">Click a funnel stage to filter</span>
            </div>
            {data.journeys.data.length === 0 ? (
              <div className="p-6 text-sm text-slate-500">No journeys in this range.</div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-xs text-slate-500 border-b border-slate-100">
                      <th className="px-4 py-2 font-medium">Campaign</th>
                      <th className="px-4 py-2 font-medium">Furthest step</th>
                      <th className="px-4 py-2 font-medium">Status</th>
                      <th className="px-4 py-2 font-medium">Duration</th>
                      <th className="px-4 py-2 font-medium">Entered</th>
                      <th className="px-4 py-2"></th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.journeys.data.map((j) => (
                      <tr key={j.click_id} className="border-b border-slate-50 hover:bg-slate-50">
                        <td className="px-4 py-2">{j.campaign ?? '—'}</td>
                        <td className="px-4 py-2">{STEP_LABELS[j.furthest_step] ?? j.furthest_step}</td>
                        <td className="px-4 py-2">{j.status}</td>
                        <td className="px-4 py-2">{fmtDuration(j.duration_seconds)}</td>
                        <td className="px-4 py-2 text-slate-500">
                          {j.entered_at ? new Date(j.entered_at).toLocaleString() : '—'}
                        </td>
                        <td className="px-4 py-2 text-right">
                          <Link
                            to={`/analytics/journeys/${j.click_id}`}
                            className="text-indigo-600 hover:text-indigo-800"
                          >
                            View timeline →
                          </Link>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            {data.journeys.meta.last_page > 1 && (
              <div className="flex items-center justify-between p-4 text-sm">
                <button
                  type="button"
                  disabled={page <= 1}
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  className="px-3 py-1.5 rounded border border-slate-300 disabled:opacity-40"
                >
                  Previous
                </button>
                <span className="text-slate-500">
                  Page {data.journeys.meta.current_page} of {data.journeys.meta.last_page}
                </span>
                <button
                  type="button"
                  disabled={page >= data.journeys.meta.last_page}
                  onClick={() => setPage((p) => p + 1)}
                  className="px-3 py-1.5 rounded border border-slate-300 disabled:opacity-40"
                >
                  Next
                </button>
              </div>
            )}
          </Card>
        </>
      )}
    </Layout>
  );
}

function Kpi({ label, value, accent }: { label: string; value: string; accent?: boolean }) {
  return (
    <div className="bg-white border border-slate-200 rounded-lg p-4">
      <div className={'text-2xl font-bold ' + (accent ? 'text-green-600' : 'text-slate-800')}>
        {value}
      </div>
      <div className="text-xs text-slate-500 mt-1">{label}</div>
    </div>
  );
}
```

- [ ] **Step 4: Verify `Layout` and `Card` import shapes.** Confirm `frontend/src/components/layout/Layout.tsx` is a default export accepting `title`/`description`/`action` props (as `CampaignStory.tsx` uses it) and that `Card` is a named export of `@/components/common`. If `Layout`'s prop names differ, align the JSX to the real interface (do NOT invent props). If `@/components/common` does not export `Card`, import it from its actual path (match `CampaignStory.tsx`'s imports exactly).

- [ ] **Step 5: Run the test to verify it passes.**

Run: `cd frontend && npx vitest run src/pages/DominionFunnel.test.tsx`
Expected: PASS (3 tests).

- [ ] **Step 6: Typecheck.**

Run: `cd frontend && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 7: Commit.**

```bash
git add frontend/src/pages/DominionFunnel.tsx frontend/src/pages/DominionFunnel.test.tsx
git commit -m "feat(analytics): Dominion Funnel page (filters, two-part funnel, journeys table)"
```

---

### Task 5: Wire the route + Sidebar entry

**Files:**
- Modify: `frontend/src/App.tsx` (eager import + route)
- Modify: `frontend/src/components/layout/Sidebar.tsx` (nav entry + icon import)
- Test: `frontend/src/components/layout/Sidebar.test.tsx` (create, or extend if it exists)

**Interfaces:**
- Consumes: the default-exported `DominionFunnel` page (Task 4).
- Produces: route `/analytics/dominion-funnel` and a Sidebar nav link to it.

- [ ] **Step 1: Write the failing Sidebar test.** Create `frontend/src/components/layout/Sidebar.test.tsx` (if one already exists, add the `it` block to it). Match the render-wrapper conventions of existing component tests (wrap in `MemoryRouter`; if `Sidebar` consumes any context/provider, wrap it the way the existing layout tests do — inspect a sibling test first).

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import Sidebar from './Sidebar';

describe('Sidebar', () => {
  it('has a Dominion Funnel nav link to /analytics/dominion-funnel', () => {
    render(
      <MemoryRouter>
        <Sidebar />
      </MemoryRouter>,
    );
    const link = screen.getByRole('link', { name: /Dominion Funnel/i });
    expect(link).toHaveAttribute('href', '#/analytics/dominion-funnel');
  });
});
```

Note: under HashRouter the resolved `href` is hash-prefixed (`#/analytics/...`). If `Sidebar` is normally rendered under HashRouter, either wrap the test in the same router the app uses or assert with `expect(link.getAttribute('href')).toContain('/analytics/dominion-funnel')` — inspect how sibling tests handle router hrefs and mirror that. Adjust the assertion to the real behavior rather than forcing `#/`.

- [ ] **Step 2: Run the test to verify it fails.**

Run: `cd frontend && npx vitest run src/components/layout/Sidebar.test.tsx`
Expected: FAIL — no "Dominion Funnel" link.

- [ ] **Step 3: Add the Sidebar entry.** In `frontend/src/components/layout/Sidebar.tsx`, import an icon from `lucide-react` (e.g. `TrendingDown`) at the top, and add to the `navigation` array (after the `Journeys`/`Analytics` entries):

```ts
{ name: 'Dominion Funnel', href: '/analytics/dominion-funnel', icon: TrendingDown },
```

- [ ] **Step 4: Add the route.** In `frontend/src/App.tsx`, add the eager import near the other page imports:

```tsx
import DominionFunnel from './pages/DominionFunnel';
```

and add the route inside the analytics block (next to the `gtm-coverage`/`campaign` routes):

```tsx
<Route path="/analytics/dominion-funnel" element={<DominionFunnel />} />
```

- [ ] **Step 5: Run the Sidebar test to verify it passes.**

Run: `cd frontend && npx vitest run src/components/layout/Sidebar.test.tsx`
Expected: PASS.

- [ ] **Step 6: Commit.**

```bash
git add frontend/src/App.tsx frontend/src/components/layout/Sidebar.tsx frontend/src/components/layout/Sidebar.test.tsx
git commit -m "feat(analytics): route + sidebar entry for Dominion Funnel"
```

---

### Final verification (whole-branch, after all tasks)

- [ ] **Full frontend test suite:**

Run: `cd frontend && npm run test:run`
Expected: all green (includes the 4 new/extended test files).

- [ ] **Production build (typecheck + Vite):**

Run: `cd frontend && npm run build`  (runs `tsc --noEmit && vite build`)
Expected: builds to `../assets/dist` with no TS errors.

- [ ] **Full PHP suite:**

Run: `composer test`
Expected: all green.

- [ ] **Do NOT bump version, tag, or run any release/publish step** — the signed release pipeline is Nat's action. Leave the branch ready for a normal PR.

---

## Notes / deferred (not in this plan)

- **Step-labeled vertical timeline polish:** drill-down reuses the existing `JourneyDetail` (already a vertical event timeline). A Dominion-specific timeline that maps events to step labels with time-since-previous + a terminal "Dropped here / Enrolled ✓" marker (the approved mock "A") is a fast-follow if the generic one proves insufficient — it needs no HUB change (reuses `/journeys/{clickId}`).
- **Funnel stage colors:** the shared `Funnel`'s `STAGE_COLORS` lacks keys for `entered`/`validation`/`login`/`dashboard`; they render via `FALLBACK`. Extending the palette is optional cosmetic polish (additive keys), not required for function.
- **Shareable gated client page ("b later"):** a separate spec — the public/gated Dominion-facing version builds on this internal view.
