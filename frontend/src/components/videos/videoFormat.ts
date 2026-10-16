import type { Video } from '@/api';

export function shortcodeFor(video: Pick<Video, 'slug'>): string {
  return `[peanut_video slug="${video.slug}"]`;
}

// Responsive 16:9 iframe for pasting into any other site. Plays there are
// tracked like plays on this site (Hub records the page they came from).
export function embedCodeFor(video: Pick<Video, 'title' | 'embed_url'>): string {
  const title = video.title.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
  return (
    '<div style="position:relative;width:100%;padding-top:56.25%">' +
    `<iframe src="${video.embed_url}" title="${title}" loading="lazy" ` +
    'allow="fullscreen; encrypted-media" allowfullscreen ' +
    'style="position:absolute;inset:0;width:100%;height:100%;border:0"></iframe></div>'
  );
}

export function formatDuration(seconds: number | null | undefined): string | null {
  if (seconds == null || seconds <= 0) return null;
  const s = Math.round(seconds);
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

export function formatPlays(n: number): string {
  return `${n.toLocaleString()} ${n === 1 ? 'play' : 'plays'}`;
}
