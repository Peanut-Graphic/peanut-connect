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
