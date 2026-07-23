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
