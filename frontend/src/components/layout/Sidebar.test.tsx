import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import Sidebar from './Sidebar';

describe('Sidebar', () => {
  it('has a Dominion Funnel nav link to /analytics/dominion-funnel', () => {
    render(
      <MemoryRouter>
        <Sidebar collapsed={false} onToggle={() => {}} />
      </MemoryRouter>,
    );
    const link = screen.getByRole('link', { name: /Dominion Funnel/i });
    expect(link).toHaveAttribute('href', '/analytics/dominion-funnel');
  });
});
