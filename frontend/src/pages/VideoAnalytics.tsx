import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery, useQueries } from '@tanstack/react-query';
import { videosApi, type Video, type VideoAnalytics } from '@/api';
import Layout from '@/components/layout/Layout';
import { Card } from '@/components/common';

/**
 * Aggregate per-video analytics under /analytics/videos.
 *
 * Lists every video on the site with its headline metrics for a 7 / 30 / 90
 * day window. Each row links to the per-video detail page
 * (/analytics/videos/:id) which shows the full daily-views chart, drop-off
 * curve, and completion funnel. Pairs with the same Hub data the inline
 * analytics expand on the Videos management page uses — this just surfaces
 * it as a flat list, scoped to a window.
 *
 * Mirrors the IA we set up for Journeys in 3.9.8 — analytics drilldowns
 * live under /analytics, management lives under their own top-level.
 */
export default function VideoAnalyticsPage() {
  const [days, setDays] = useState<7 | 30 | 90>(30);

  const videosQuery = useQuery({
    queryKey: ['videos', 'list-for-analytics'],
    queryFn: () => videosApi.list(),
  });

  const videos = videosQuery.data ?? [];

  // Per-video analytics fired in parallel. React Query dedupes if the same
  // (videoId, days) tuple is rendered elsewhere on the same screen.
  const analyticsQueries = useQueries({
    queries: videos.map((v) => ({
      queryKey: ['video-analytics', v.id, days],
      queryFn: () => videosApi.analytics(v.id, days),
    })),
  });

  return (
    <Layout
      title="Video analytics"
      description="Per-video plays, completion, and drop-off across every video on this site. Click a row for the full timeline."
      action={
        <div className="flex items-center gap-1">
          {([7, 30, 90] as const).map((d) => (
            <button
              key={d}
              type="button"
              aria-pressed={days === d}
              onClick={() => setDays(d)}
              className={
                days === d
                  ? 'px-3 py-1.5 text-sm font-medium rounded bg-primary-600 text-white'
                  : 'px-3 py-1.5 text-sm font-medium rounded text-slate-600 hover:bg-slate-100'
              }
            >
              Last {d}d
            </button>
          ))}
        </div>
      }
    >
      <Card>
        {videosQuery.isLoading && (
          <div className="p-6 text-sm text-slate-500">Loading videos…</div>
        )}
        {videosQuery.isError && (
          <div className="p-6 text-sm text-red-600">
            {(videosQuery.error as Error)?.message || 'Failed to load videos.'}
          </div>
        )}
        {!videosQuery.isLoading && videos.length === 0 && (
          <div className="p-12 text-center text-sm text-slate-500">
            No videos registered yet.{' '}
            <Link to="/videos" className="text-indigo-600 hover:underline">
              Register a video →
            </Link>
          </div>
        )}
        {videos.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-left text-xs uppercase tracking-wide text-slate-500 border-b border-slate-200">
                <tr>
                  <th className="px-4 py-3">Video</th>
                  <th className="px-4 py-3 text-right">Total plays</th>
                  <th className="px-4 py-3 text-right">Unique viewers</th>
                  <th className="px-4 py-3 text-right">Avg watch (s)</th>
                  <th className="px-4 py-3 text-right">Completion</th>
                  <th className="px-4 py-3 text-right">Action</th>
                </tr>
              </thead>
              <tbody>
                {videos.map((video, i) => (
                  <VideoRow
                    key={video.id}
                    video={video}
                    analytics={analyticsQueries[i]?.data}
                    isLoading={analyticsQueries[i]?.isLoading ?? true}
                  />
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </Layout>
  );
}

function VideoRow({
  video,
  analytics,
  isLoading,
}: {
  video: Video;
  analytics: VideoAnalytics | undefined;
  isLoading: boolean;
}) {
  return (
    <tr className="border-b border-slate-100 hover:bg-slate-50">
      <td className="px-4 py-3">
        <div className="font-medium text-slate-900">{video.title}</div>
        {video.description && (
          <div className="text-xs text-slate-500 mt-0.5 line-clamp-1">
            {video.description}
          </div>
        )}
      </td>
      <td className="px-4 py-3 text-right tabular-nums">
        {isLoading ? '—' : analytics?.total_plays.toLocaleString()}
      </td>
      <td className="px-4 py-3 text-right tabular-nums">
        {isLoading ? '—' : analytics?.unique_viewers.toLocaleString()}
      </td>
      <td className="px-4 py-3 text-right tabular-nums">
        {isLoading ? '—' : analytics?.avg_watch_time}
      </td>
      <td className="px-4 py-3 text-right tabular-nums">
        {isLoading ? '—' : `${analytics?.completion_rate ?? 0}%`}
      </td>
      <td className="px-4 py-3 text-right">
        <Link
          to={`/analytics/videos/${video.id}`}
          className="text-sm text-indigo-600 hover:underline"
        >
          View detail
        </Link>
      </td>
    </tr>
  );
}
