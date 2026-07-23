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
