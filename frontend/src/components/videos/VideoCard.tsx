import { useState } from 'react';
import { Film, Play } from 'lucide-react';
import type { Video } from '@/api';

// The admin preview loads Hub's own player so captions and controls match the
// live site. preview=1 tells Hub not to record the play, so watching here never
// inflates the analytics.
export function previewSrc(embedUrl: string): string {
  return `${embedUrl}${embedUrl.includes('?') ? '&' : '?'}preview=1&autoplay=1`;
}

interface VideoCardProps {
  video: Video;
  analyticsOpen: boolean;
  onCopy: () => void;
  onAnalytics: () => void;
  onRemove: () => void;
}

export function VideoCard({ video, analyticsOpen, onCopy, onAnalytics, onRemove }: VideoCardProps) {
  const [playing, setPlaying] = useState(false);
  const shortcode = `[peanut_video slug="${video.slug}"]`;

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
        <div className="text-sm font-medium">{video.title}</div>
        <code className="break-all text-xs text-slate-500">{shortcode}</code>
        <div className="mt-auto flex items-center gap-2 pt-1">
          <button type="button" className="text-xs px-2 py-1 border rounded" onClick={onCopy}>
            Copy
          </button>
          <button
            type="button"
            className="text-xs px-2 py-1 border rounded"
            aria-pressed={analyticsOpen}
            onClick={onAnalytics}
          >
            Analytics
          </button>
          <button
            type="button"
            className="ml-auto text-xs px-2 py-1 border rounded text-red-600"
            onClick={onRemove}
          >
            Remove
          </button>
        </div>
      </div>
    </div>
  );
}
