import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import Journeys from './Journeys';

vi.mock('@/api', () => ({
  getVersion: () => '0.0.0-test',
  marketingApi: {
    listJourneys: vi
      .fn()
      .mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } }),
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
