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
  const handlers = { onCopy: vi.fn(), onAnalytics: vi.fn(), onRemove: vi.fn() };
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
    fireEvent.click(screen.getByRole('button', { name: 'Copy' }));
    fireEvent.click(screen.getByRole('button', { name: 'Analytics' }));
    fireEvent.click(screen.getByRole('button', { name: 'Remove' }));
    expect(h.onCopy).toHaveBeenCalledOnce();
    expect(h.onAnalytics).toHaveBeenCalledOnce();
    expect(h.onRemove).toHaveBeenCalledOnce();
  });
});

describe('previewSrc', () => {
  it('appends with & when the embed URL already has a query string', () => {
    expect(previewSrc('https://h/video/a/embed?x=1')).toBe('https://h/video/a/embed?x=1&preview=1&autoplay=1');
  });
});
