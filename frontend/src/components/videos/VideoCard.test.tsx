import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { VideoCard, previewSrc } from './VideoCard';
import type { Video } from '@/api';

const base: Video = {
  id: 7,
  title: 'PTR Cooking',
  slug: 'ptr-cooking-xbDy62',
  description: null,
  source_url: 'https://hub.example/storage/videos/c.mp4',
  poster_url: 'https://hub.example/storage/videos/c.jpg',
  caption_url: null,
  status: 'active',
  created_at: null,
  embed_url: 'https://hub.example/video/ptr-cooking-xbDy62/embed',
};

function renderCard(video: Video = base) {
  const handlers = { onCopy: vi.fn(), onCopyEmbed: vi.fn(), onAnalytics: vi.fn(), onPage: vi.fn(), onRemove: vi.fn() };
  render(<VideoCard video={video} analyticsOpen={false} {...handlers} />);
  return handlers;
}

describe('VideoCard', () => {
  it('shows the poster thumbnail and the shortcode', () => {
    renderCard();
    const img = screen.getByRole('img', { name: 'PTR Cooking' });
    expect(img).toHaveAttribute('src', base.poster_url);
    expect(screen.getByText('[peanut_video slug="ptr-cooking-xbDy62"]')).toBeInTheDocument();
  });

  it('falls back to a placeholder when there is no poster', () => {
    renderCard({ ...base, poster_url: null });
    expect(screen.queryByRole('img', { name: 'PTR Cooking' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Play PTR Cooking' })).toBeInTheDocument();
  });

  it('swaps the thumbnail for the Hub player in preview mode on play', () => {
    renderCard();
    expect(screen.queryByTitle('PTR Cooking')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Play PTR Cooking' }));
    const frame = screen.getByTitle('PTR Cooking');
    expect(frame.tagName).toBe('IFRAME');
    expect(frame).toHaveAttribute('src', `${base.embed_url}?preview=1&autoplay=1`);
  });

  it('marks videos that have captions', () => {
    renderCard({ ...base, caption_url: 'https://hub.example/video/x/captions.vtt' });
    expect(screen.getByLabelText('Has closed captions')).toBeInTheDocument();
  });

  it('does not mark videos without captions', () => {
    renderCard();
    expect(screen.queryByLabelText('Has closed captions')).not.toBeInTheDocument();
  });

  it('wires the action buttons', () => {
    const h = renderCard();
    fireEvent.click(screen.getByRole('button', { name: 'Copy shortcode for PTR Cooking' }));
    fireEvent.click(screen.getByRole('button', { name: 'Copy embed code for PTR Cooking' }));
    fireEvent.click(screen.getByRole('button', { name: 'Analytics' }));
    fireEvent.click(screen.getByRole('button', { name: 'Create page' }));
    fireEvent.click(screen.getByRole('button', { name: 'Remove' }));
    expect(h.onCopy).toHaveBeenCalledOnce();
    expect(h.onCopyEmbed).toHaveBeenCalledOnce();
    expect(h.onAnalytics).toHaveBeenCalledOnce();
    expect(h.onPage).toHaveBeenCalledOnce();
    expect(h.onRemove).toHaveBeenCalledOnce();
  });

  it('shows duration and 30-day plays when Hub sends them', () => {
    renderCard({
      ...base,
      duration_seconds: 75,
      stats: { plays_30d: 412, total_plays: 900, avg_watch_seconds_30d: 11, completion_rate_30d: 78 },
    });
    expect(screen.getByLabelText('Duration 1:15')).toBeInTheDocument();
    expect(screen.getByText('412 plays')).toBeInTheDocument();
  });

  it('omits duration and plays for an older Hub that does not send them', () => {
    renderCard();
    expect(screen.queryByLabelText(/^Duration/)).not.toBeInTheDocument();
    expect(screen.queryByText(/plays?$/)).not.toBeInTheDocument();
  });

  it('offers Edit page and a preview link once a draft page exists', () => {
    renderCard({
      ...base,
      page: { id: 9, status: 'draft', edit_url: 'https://s/wp-admin/post.php?post=9&action=edit', view_url: 'https://s/?page_id=9&preview=true' },
    });
    expect(screen.getByRole('button', { name: 'Edit page' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Preview draft page' })).toHaveAttribute('href', 'https://s/?page_id=9&preview=true');
  });
});

describe('previewSrc', () => {
  it('appends with & when the embed URL already has a query string', () => {
    expect(previewSrc('https://h/video/a/embed?x=1')).toBe('https://h/video/a/embed?x=1&preview=1&autoplay=1');
  });
});
