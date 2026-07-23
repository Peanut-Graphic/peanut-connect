import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { ReactNode } from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import Dashboard from './Dashboard';

const dominionFunnel = vi.fn();
const gtmCoverage = vi.fn();
const dashboardGet = vi.fn();
const settingsGet = vi.fn();
const errorCounts = vi.fn();

vi.mock('@/api', () => ({
  getVersion: () => '0.0.0-test',
  marketingApi: {
    dominionFunnel: (...a: any[]) => dominionFunnel(...a),
    gtmCoverage: (...a: any[]) => gtmCoverage(...a),
  },
  dashboardApi: { get: (...a: any[]) => dashboardGet(...a) },
  settingsApi: { get: (...a: any[]) => settingsGet(...a) },
  errorLogApi: { getCounts: (...a: any[]) => errorCounts(...a) },
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
  gtmCoverage.mockResolvedValue({ totals: { total_captures: 12 } });
  errorCounts.mockResolvedValue({ all_time: { critical:0, error:0, warning:0, notice:0, total: 0 }, last_24h: { critical:0, error:0, warning:0, notice:0, total: 0 }, logging_enabled: true });
  dashboardGet.mockResolvedValue({
    hub: { connected: true, last_sync: null, url: null },
    health_summary: { status: 'healthy', issues: [] },
    updates: { plugins: 2, themes: 0, core: null },
    peanut_suite: { installed: false },
  });
  settingsGet.mockResolvedValue({ connected: true });
});

describe('Overview (Dashboard)', () => {
  it('renders the KPI row with a drill link per stage', async () => {
    wrap(<Dashboard />);
    await waitFor(() =>
      expect(screen.getByRole('link', { name: /Landed/i })).toBeInTheDocument(),
    );
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
