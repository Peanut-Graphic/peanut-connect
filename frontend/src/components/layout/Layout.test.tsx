import { describe, it, expect } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
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
  it('offers a mobile navigation disclosure that closes on Escape and restores focus', () => {
    renderLayout();
    const toggle = screen.getByRole('button', { name: 'Open navigation' });
    expect(toggle).toHaveAttribute('aria-expanded', 'false');
    fireEvent.click(toggle);
    expect(toggle).toHaveAttribute('aria-expanded', 'true');
    expect(toggle).toHaveAttribute('aria-controls', 'pc-navigation');
    fireEvent.keyDown(screen.getByRole('link', { name: 'Settings' }), { key: 'Escape' });
    expect(toggle).toHaveAttribute('aria-expanded', 'false');
    expect(toggle).toHaveFocus();
  });

  it('closes mobile navigation after choosing a destination without changing the desktop preference', () => {
    localStorage.setItem('pc-sidebar-collapsed', '1');
    renderLayout();
    const toggle = screen.getByRole('button', { name: 'Open navigation' });
    fireEvent.click(toggle);
    expect(screen.getByRole('heading', { name: 'Performance' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('link', { name: 'Settings' }));
    expect(toggle).toHaveAttribute('aria-expanded', 'false');
    expect(toggle).toHaveFocus();
    expect(localStorage.getItem('pc-sidebar-collapsed')).toBe('1');
    localStorage.removeItem('pc-sidebar-collapsed');
  });
  it('returns focus to the new menu button when navigation remounts the page layout', async () => {
    function HomePage() {
      return <Layout title="Home">home</Layout>;
    }
    function SettingsPage() {
      return <Layout title="Settings">settings</Layout>;
    }
    render(
      <MemoryRouter>
        <Routes>
          <Route path="/" element={<HomePage />} />
          <Route path="/settings" element={<SettingsPage />} />
        </Routes>
      </MemoryRouter>,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Open navigation' }));
    fireEvent.click(screen.getByRole('link', { name: 'Settings' }));
    expect(screen.getByRole('heading', { name: 'Settings', level: 1 })).toBeInTheDocument();
    await waitFor(() => expect(screen.getByRole('button', { name: 'Open navigation' })).toHaveFocus());
  });
});
