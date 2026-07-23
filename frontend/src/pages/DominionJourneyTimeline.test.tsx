import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { ReactNode } from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import DominionJourneyTimeline from './DominionJourneyTimeline';

const droppedDetail = {
  journey: {
    id: 0,
    click_id: 'abc123',
    status: 'in_progress',
    utm_campaign: 'USA_Display',
    utm_source: 'usa',
    utm_medium: 'display',
    started_at: '2026-07-20T10:02:14Z',
    duration_seconds: 356,
    converted_at: null,
    last_event_at: '2026-07-20T10:08:10Z',
    pages_viewed: 4,
    events_count: 4,
  },
  events: [
    { id: 1, event_type: 'pageview', event_name: null, page_url: 'https://ad.example/landing', page_title: null, event_at: '2026-07-20T10:02:14Z' },
    { id: 2, event_type: 'pageview', event_name: null, page_url: 'https://portal.example/app#validation', page_title: null, event_at: '2026-07-20T10:03:20Z' },
    { id: 3, event_type: 'pageview', event_name: null, page_url: 'https://portal.example/app#login', page_title: null, event_at: '2026-07-20T10:05:40Z' },
    { id: 4, event_type: 'pageview', event_name: null, page_url: 'https://portal.example/app#dashboard', page_title: null, event_at: '2026-07-20T10:08:10Z' },
  ],
};

const journeyDetail = vi.fn();
vi.mock('@/api', () => ({
  marketingApi: { journeyDetail: (...a: any[]) => journeyDetail(...a) },
  getVersion: () => '0.0.0-test', // consumed by the Layout sidebar chrome
}));

function wrap(ui: ReactNode, path = '/analytics/dominion-funnel/journey/abc123') {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/analytics/dominion-funnel/journey/:clickId" element={ui} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('DominionJourneyTimeline', () => {
  beforeEach(() => journeyDetail.mockReset());

  it('renders the step-labeled timeline with deltas and a "dropped" terminal marker', async () => {
    journeyDetail.mockResolvedValue(droppedDetail);
    wrap(<DominionJourneyTimeline />);
    await waitFor(() => expect(screen.getByText('#validation')).toBeInTheDocument());
    expect(screen.getByText('Landed')).toBeInTheDocument();
    expect(screen.getByText('#login')).toBeInTheDocument();
    expect(screen.getByText('#dashboard')).toBeInTheDocument();
    // 10:05:40 - 10:03:20 = 2m 20s delta between validation and login.
    expect(screen.getByText(/\+2m 20s/)).toBeInTheDocument();
    expect(screen.getByText(/Dropped here/i)).toBeInTheDocument();
    expect(journeyDetail).toHaveBeenCalledWith('abc123');
  });

  it('shows an "Enrolled" terminal marker when the journey converted', async () => {
    journeyDetail.mockResolvedValue({
      ...droppedDetail,
      journey: { ...droppedDetail.journey, status: 'converted', converted_at: '2026-07-20T10:09:00Z' },
      events: [
        ...droppedDetail.events,
        { id: 5, event_type: 'pageview', event_name: null, page_url: 'https://portal.example/app#enrolled', page_title: null, event_at: '2026-07-20T10:09:00Z' },
      ],
    });
    wrap(<DominionJourneyTimeline />);
    await waitFor(() => expect(screen.getByText(/Enrolled ✓/)).toBeInTheDocument());
    expect(screen.queryByText(/Dropped here/i)).toBeNull();
  });
});
