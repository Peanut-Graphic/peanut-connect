import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import Layout from './Layout';

function renderLayout() {
  return render(
    <MemoryRouter>
      <Layout title="Test Page" description="desc">
        <div>page content</div>
      </Layout>
    </MemoryRouter>,
  );
}

describe('Layout', () => {
  it('renders the page header and content', () => {
    renderLayout();
    expect(screen.getByRole('heading', { name: 'Test Page' })).toBeInTheDocument();
    expect(screen.getByText('page content')).toBeInTheDocument();
  });

  it('renders the grouped sidebar (nav is now the sidebar, not top tabs)', () => {
    renderLayout();
    expect(screen.getByRole('heading', { name: 'Performance' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Dominion Funnel/i })).toHaveAttribute(
      'href',
      '/analytics/dominion-funnel',
    );
  });

  it('hides the sidebar in builder mode', () => {
    (window as any).peanutConnect = { mode: 'builder' };
    render(
      <MemoryRouter>
        <Layout title="Build">
          <div>content</div>
        </Layout>
      </MemoryRouter>,
    );
    expect(screen.queryByRole('heading', { name: 'Performance' })).toBeNull();
    expect(screen.getByRole('heading', { name: 'Build' })).toBeInTheDocument();
    (window as any).peanutConnect = undefined;
  });
});
