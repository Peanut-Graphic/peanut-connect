import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import Layout from './Layout';

describe('Layout nav', () => {
  it('exposes the Dominion Funnel tab pointing at /analytics/dominion-funnel', () => {
    render(
      <MemoryRouter>
        <Layout title="Test">
          <div>content</div>
        </Layout>
      </MemoryRouter>,
    );
    // This is the VISIBLE top-tab nav (Layout), not the secondary Sidebar —
    // the Dominion Funnel entry must live here to actually be reachable.
    const link = screen.getByRole('link', { name: /Dominion Funnel/i });
    expect(link).toHaveAttribute('href', '/analytics/dominion-funnel');
  });
});
