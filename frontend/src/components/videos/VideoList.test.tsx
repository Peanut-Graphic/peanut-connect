import { describe, it, expect, vi } from 'vitest';
import { render, screen, within, fireEvent } from '@testing-library/react';
import { VideoList } from './VideoList';
import type { Video } from '@/api';

const v = (over: Partial<Video>): Video => ({
  id: 1,
  title: 'PTR Cooking',
  slug: 'ptr-cooking-xbDy62',
  description: null,
  source_url: null,
  poster_url: 'https://hub.example/c.jpg',
  caption_url: 'https://hub.example/video/x/captions.vtt',
  status: 'active',
  created_at: null,
  embed_url: 'https://hub.example/video/ptr-cooking-xbDy62/embed',
  duration_seconds: 15,
  stats: { plays_30d: 412, total_plays: 900, avg_watch_seconds_30d: 11.7, completion_rate_30d: 78 },
  page: null,
  ...over,
});

describe('VideoList', () => {
  it('renders one row per video with its stats, duration and CC', () => {
    const handlers = { onCopy: vi.fn(), onCopyEmbed: vi.fn(), onAnalytics: vi.fn(), onPage: vi.fn(), onRemove: vi.fn() };
    render(
      <VideoList
        videos={[v({}), v({ id: 2, title: 'PTR Laundry', slug: 'ptr-laundry-TkmrL4', caption_url: null, stats: undefined, duration_seconds: null })]}
        expanded={null}
        pageBusyId={null}
        handlersFor={() => handlers}
      />,
    );
    const rows = screen.getAllByRole('row').slice(1);
    expect(rows).toHaveLength(2);

    const first = within(rows[0]);
    expect(first.getByText('412')).toBeInTheDocument();
    expect(first.getByText('11.7s')).toBeInTheDocument();
    expect(first.getByText('78%')).toBeInTheDocument();
    expect(first.getByText('0:15')).toBeInTheDocument();
    expect(first.getByLabelText('Has closed captions')).toBeInTheDocument();
    expect(first.getByText('None')).toBeInTheDocument();

    // An older Hub without stats shows dashes, not zeros.
    const second = within(rows[1]);
    expect(second.getAllByText('—')).toHaveLength(3);
    expect(second.queryByLabelText('Has closed captions')).not.toBeInTheDocument();

    fireEvent.click(first.getByRole('button', { name: 'Create page' }));
    expect(handlers.onPage).toHaveBeenCalledOnce();
  });

  it('links to an existing page and labels its status', () => {
    render(
      <VideoList
        videos={[v({ page: { id: 9, status: 'publish', edit_url: 'https://s/edit', view_url: 'https://s/cooking/' } })]}
        expanded={null}
        pageBusyId={null}
        handlersFor={() => ({ onCopy: vi.fn(), onCopyEmbed: vi.fn(), onAnalytics: vi.fn(), onPage: vi.fn(), onRemove: vi.fn() })}
      />,
    );
    expect(screen.getByRole('link', { name: 'Published' })).toHaveAttribute('href', 'https://s/cooking/');
    expect(screen.getByRole('button', { name: 'Edit page' })).toBeInTheDocument();
  });

  it('shows the busy label on the row whose page is being created', () => {
    render(
      <VideoList
        videos={[v({})]}
        expanded={null}
        pageBusyId={1}
        handlersFor={() => ({ onCopy: vi.fn(), onCopyEmbed: vi.fn(), onAnalytics: vi.fn(), onPage: vi.fn(), onRemove: vi.fn() })}
      />,
    );
    expect(screen.getByRole('button', { name: 'Creating…' })).toBeDisabled();
  });
});
