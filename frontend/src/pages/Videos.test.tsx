import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { ToastProvider } from '@/components/common/Toast';
import Videos from './Videos';

const list = vi.fn();
const createPage = vi.fn();
vi.mock('@/api', () => ({
  getVersion: () => '0.0.0-test',
  videosApi: {
    list: (...a: unknown[]) => list(...a),
    createPage: (...a: unknown[]) => createPage(...a),
    create: vi.fn(),
    remove: vi.fn(),
    analytics: vi.fn().mockResolvedValue({}),
  },
}));

const video = {
  id: 3,
  title: 'PTR Cooking',
  slug: 'ptr-cooking-xbDy62',
  description: null,
  source_url: null,
  poster_url: 'https://hub.example/c.jpg',
  caption_url: null,
  status: 'active',
  created_at: null,
  embed_url: 'https://hub.example/video/ptr-cooking-xbDy62/embed',
  duration_seconds: 15,
  stats: { plays_30d: 4, total_plays: 4, avg_watch_seconds_30d: 10, completion_rate_30d: 50 },
  page: null,
};

function wrap() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <ToastProvider>
        <MemoryRouter>
          <Videos />
        </MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  list.mockReset().mockResolvedValue([video]);
  createPage.mockReset();
  window.localStorage.clear();
});

describe('Videos page', () => {
  it('starts in grid view and switches to list, remembering the choice', async () => {
    wrap();
    await screen.findByRole('button', { name: 'Play PTR Cooking' });
    expect(screen.getByRole('button', { name: 'grid' })).toHaveAttribute('aria-pressed', 'true');

    fireEvent.click(screen.getByRole('button', { name: 'list' }));

    expect(await screen.findByRole('table')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Play PTR Cooking' })).not.toBeInTheDocument();
    expect(window.localStorage.getItem('peanut-connect.videos.view')).toBe('list');
  });

  it('opens in the remembered view', async () => {
    window.localStorage.setItem('peanut-connect.videos.view', 'list');
    wrap();
    expect(await screen.findByRole('table')).toBeInTheDocument();
  });

  it('creates a page and opens its editor in a new tab', async () => {
    const open = vi.spyOn(window, 'open').mockReturnValue(null);
    createPage.mockResolvedValue({ id: 50, status: 'draft', edit_url: 'https://s/wp-admin/post.php?post=50&action=edit', view_url: 'https://s/?page_id=50', created: true });
    wrap();

    fireEvent.click(await screen.findByRole('button', { name: 'Create page' }));

    await waitFor(() => expect(createPage).toHaveBeenCalledWith(3));
    await waitFor(() => expect(open).toHaveBeenCalledWith('https://s/wp-admin/post.php?post=50&action=edit', '_blank', 'noopener'));
    open.mockRestore();
  });

  it('opens the existing page instead of creating another', async () => {
    const open = vi.spyOn(window, 'open').mockReturnValue(null);
    list.mockResolvedValue([{ ...video, page: { id: 9, status: 'publish', edit_url: 'https://s/edit-9', view_url: 'https://s/cooking/' } }]);
    wrap();

    fireEvent.click(await screen.findByRole('button', { name: 'Edit page' }));

    expect(open).toHaveBeenCalledWith('https://s/edit-9', '_blank', 'noopener');
    expect(createPage).not.toHaveBeenCalled();
    open.mockRestore();
  });
});
