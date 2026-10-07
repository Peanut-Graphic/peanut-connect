import { Film } from 'lucide-react';
import type { Video } from '@/api';
import { VideoActions, type VideoActionHandlers } from './VideoCard';
import { formatDuration, shortcodeFor } from './videoFormat';

interface VideoListProps {
  videos: Video[];
  expanded: number | null;
  pageBusyId: number | null;
  handlersFor: (video: Video) => VideoActionHandlers;
}

const th = 'p-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500';

export function VideoList({ videos, expanded, pageBusyId, handlersFor }: VideoListProps) {
  return (
    <div className="overflow-x-auto rounded-lg border bg-white">
      <table className="w-full text-sm">
        <caption className="sr-only">Videos</caption>
        <thead className="bg-slate-50">
          <tr>
            <th scope="col" className={th}>Video</th>
            <th scope="col" className={th}>Title</th>
            <th scope="col" className={`${th} text-right`}>Plays (30d)</th>
            <th scope="col" className={`${th} text-right`}>Avg watch</th>
            <th scope="col" className={`${th} text-right`}>Completion</th>
            <th scope="col" className={th}>Page</th>
            <th scope="col" className={th}><span className="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          {videos.map((v) => {
            const duration = formatDuration(v.duration_seconds);
            return (
              <tr key={v.id} className={`border-t align-middle ${expanded === v.id ? 'bg-indigo-50/50' : ''}`}>
                <td className="p-3">
                  <div className="relative aspect-video w-28 overflow-hidden rounded bg-slate-900">
                    {v.poster_url ? (
                      <img src={v.poster_url} alt="" loading="lazy" className="h-full w-full object-cover" />
                    ) : (
                      <Film className="m-auto mt-4 h-6 w-6 text-slate-500" aria-hidden="true" />
                    )}
                    {duration && (
                      <span className="absolute bottom-1 left-1 rounded bg-black/70 px-1 text-[10px] text-white">{duration}</span>
                    )}
                  </div>
                </td>
                <td className="p-3">
                  <div className="flex items-center gap-2 font-medium">
                    {v.title}
                    {v.caption_url && (
                      <span aria-label="Has closed captions" className="rounded bg-slate-800 px-1.5 py-0.5 text-[10px] font-semibold text-white">
                        CC
                      </span>
                    )}
                  </div>
                  <code className="break-all text-xs text-slate-500">{shortcodeFor(v)}</code>
                </td>
                <td className="p-3 text-right tabular-nums">{v.stats ? v.stats.plays_30d.toLocaleString() : '—'}</td>
                <td className="p-3 text-right tabular-nums">{v.stats ? `${v.stats.avg_watch_seconds_30d}s` : '—'}</td>
                <td className="p-3 text-right tabular-nums">{v.stats ? `${v.stats.completion_rate_30d}%` : '—'}</td>
                <td className="p-3 text-xs">
                  {v.page ? (
                    <a href={v.page.view_url} target="_blank" rel="noreferrer" className="text-indigo-600 hover:underline">
                      {v.page.status === 'publish' ? 'Published' : 'Draft'}
                    </a>
                  ) : (
                    <span className="text-slate-400">None</span>
                  )}
                </td>
                <td className="min-w-[22rem] p-3">
                  <VideoActions video={v} analyticsOpen={expanded === v.id} pageBusy={pageBusyId === v.id} {...handlersFor(v)} />
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
