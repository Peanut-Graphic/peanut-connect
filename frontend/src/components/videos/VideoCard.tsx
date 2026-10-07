import { useState } from 'react';
import { Film, Play } from 'lucide-react';
import type { Video } from '@/api';
import { formatDuration, formatPlays, shortcodeFor } from './videoFormat';

// The admin preview loads Hub's own player so captions and controls match the
// live site. preview=1 tells Hub not to record the play, so watching here never
// inflates the analytics.
export function previewSrc(embedUrl: string): string {
  return `${embedUrl}${embedUrl.includes('?') ? '&' : '?'}preview=1&autoplay=1`;
}

export interface VideoActionHandlers {
  onCopy: () => void;
  onCopyEmbed: () => void;
  onAnalytics: () => void;
  onPage: () => void;
  onRemove: () => void;
}

interface VideoActionsProps extends VideoActionHandlers {
  video: Video;
  analyticsOpen: boolean;
  pageBusy?: boolean;
}

const btn = 'text-xs px-2 py-1 border rounded whitespace-nowrap';

export function pageLabel(video: Video, busy?: boolean): string {
  if (busy) return 'Creating…';
  return video.page ? 'Edit page' : 'Create page';
}

export function VideoActions({ video, analyticsOpen, pageBusy, onCopy, onCopyEmbed, onAnalytics, onPage, onRemove }: VideoActionsProps) {
  return (
    <div className="flex flex-wrap items-center gap-2">
      <button type="button" className={btn} onClick={onCopy} aria-label={`Copy shortcode for ${video.title}`}>
        Shortcode
      </button>
      <button type="button" className={btn} onClick={onCopyEmbed} aria-label={`Copy embed code for ${video.title}`}>
        Embed
      </button>
      <button type="button" className={btn} aria-pressed={analyticsOpen} onClick={onAnalytics}>
        Analytics
      </button>
      <button type="button" className={btn} onClick={onPage} disabled={pageBusy}>
        {pageLabel(video, pageBusy)}
      </button>
      <button type="button" className={`${btn} ml-auto text-red-600`} onClick={onRemove}>
        Remove
      </button>
    </div>
  );
}

interface VideoCardProps extends VideoActionHandlers {
  video: Video;
  analyticsOpen: boolean;
  pageBusy?: boolean;
}

export function VideoCard(props: VideoCardProps) {
  const { video, analyticsOpen } = props;
  const [playing, setPlaying] = useState(false);
  const duration = formatDuration(video.duration_seconds);

  return (
    <div
      className={`flex flex-col overflow-hidden rounded-lg border bg-white ${
        analyticsOpen ? 'ring-2 ring-indigo-500' : ''
      }`}
    >
      <div className="relative aspect-video bg-slate-900">
        {playing ? (
          <iframe
            src={previewSrc(video.embed_url)}
            title={video.title}
            allow="autoplay; fullscreen; encrypted-media"
            allowFullScreen
            className="absolute inset-0 h-full w-full border-0"
          />
        ) : (
          <button
            type="button"
            aria-label={`Play ${video.title}`}
            onClick={() => setPlaying(true)}
            className="group absolute inset-0 flex h-full w-full items-center justify-center"
          >
            {video.poster_url ? (
              <img
                src={video.poster_url}
                alt={video.title}
                loading="lazy"
                className="absolute inset-0 h-full w-full object-cover"
              />
            ) : (
              <Film className="h-10 w-10 text-slate-500" aria-hidden="true" />
            )}
            <span className="relative flex h-12 w-12 items-center justify-center rounded-full bg-black/60 text-white transition group-hover:bg-black/80">
              <Play className="h-5 w-5 translate-x-0.5" aria-hidden="true" />
            </span>
            {duration && (
              <span
                aria-label={`Duration ${duration}`}
                className="absolute bottom-2 left-2 rounded bg-black/70 px-1.5 py-0.5 text-[10px] font-medium text-white"
              >
                {duration}
              </span>
            )}
          </button>
        )}
        {video.caption_url && (
          <span
            aria-label="Has closed captions"
            title="Has closed captions"
            className="pointer-events-none absolute right-2 top-2 rounded bg-black/70 px-1.5 py-0.5 text-[10px] font-semibold text-white"
          >
            CC
          </span>
        )}
      </div>
      <div className="flex flex-1 flex-col gap-2 p-3">
        <div className="flex items-baseline justify-between gap-2">
          <div className="text-sm font-medium">{video.title}</div>
          {video.stats && (
            <div className="whitespace-nowrap text-xs text-slate-500" title="Plays in the last 30 days">
              {formatPlays(video.stats.plays_30d)}
            </div>
          )}
        </div>
        <code className="break-all text-xs text-slate-500">{shortcodeFor(video)}</code>
        {video.page && (
          <a href={video.page.view_url} target="_blank" rel="noreferrer" className="text-xs text-indigo-600 hover:underline">
            {video.page.status === 'publish' ? 'View page' : 'Preview draft page'}
          </a>
        )}
        <div className="mt-auto pt-1">
          <VideoActions {...props} />
        </div>
      </div>
    </div>
  );
}
