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
  continuity: {
    new: 40,
    returning: 12,
    unknown: 4,
    bridged: { count: 3, by_campaign: [{ campaign: 'DOME2620RS1', count: 3 }] },
  },
  meta: {
    from: '2026-06-23',
    to: '2026-07-23',
    campaign: null,
    include_test: false,
    attribution_note: 'Attribution can undercount.',
    enrolled_note: 'Enrolled = portal success, not IS-confirmed.',
    continuity_note: 'Anonymous visitor id; bridged shown separately.',
    generated_at: '2026-07-23T00:00:00+00:00',
  },
};

const dominionFunnel = vi.fn();
vi.mock('@/api', () => ({
  marketingApi: { dominionFunnel: (...a: any[]) => dominionFunnel(...a) },
  getVersion: () => '0.0.0-test', // consumed by the Layout sidebar chrome
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
    // "2,263" is the Landed count — it appears in both the KPI and the funnel bar.
    expect(screen.getAllByText('2,263').length).toBeGreaterThan(0);
    expect(screen.getByText('0.27%')).toBeInTheDocument(); // conversion KPI
    expect(screen.getByText('Attribution can undercount.')).toBeInTheDocument();
    expect(screen.getByText('View timeline →')).toBeInTheDocument();
  });

  it('shows the new-vs-returning breakdown and a labeled bridged figure', async () => {
    wrap(<DominionFunnel />);
    await waitFor(() => expect(screen.getByText('New vs returning')).toBeInTheDocument());
    expect(screen.getByText('40')).toBeInTheDocument(); // new
    expect(screen.getByText('12')).toBeInTheDocument(); // returning
    // Bridged is its own labeled figure, not merged into Enrolled.
    expect(screen.getByText(/3 bridged/i)).toBeInTheDocument();
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
