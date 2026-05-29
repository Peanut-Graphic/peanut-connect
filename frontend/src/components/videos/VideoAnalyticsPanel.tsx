import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { videosApi } from '@/api';

/**
 * In-line per-video analytics panel for the plugin Videos page.
 *
 * Mirrors Hub's /videos/{id} detail page so operators can see the full
 * picture inside wp-admin without bouncing to Hub:
 *   - Headline metrics (plays, viewers, watch time, completion, window)
 *   - Daily views bar chart for the selected window (7 / 30 / 90 days)
 *   - Drop-off curve (all-time, 10% buckets)
 *   - Completion funnel (25 / 50 / 75 / 100% watched cohorts)
 *
 * "Open full analytics in Hub →" stays as the deep-link escape hatch.
 */
export function VideoAnalyticsPanel({
  videoId,
  hubEmbedUrl,
}: {
  videoId: number;
  hubEmbedUrl: string;
}) {
  const [days, setDays] = useState<7 | 30 | 90>(30);
  const { data, isLoading, error } = useQuery({
    queryKey: ['video-analytics', videoId, days],
    queryFn: () => videosApi.analytics(videoId, days),
  });

  if (isLoading)
    return <div className="text-xs text-gray-500">Loading analytics…</div>;
  if (error)
    return (
      <div className="text-xs text-red-600">{(error as Error).message}</div>
    );
  if (!data) return null;

  const dropOffEntries = Object.entries(data.drop_off_all_time);
  const dropOffMax = Math.max(1, ...dropOffEntries.map(([, n]) => n));

  const dailyEntries = Object.entries(data.daily_views).sort(([a], [b]) =>
    a.localeCompare(b),
  );
  const dailyMax = Math.max(1, ...dailyEntries.map(([, n]) => n));
  const totalPlays = data.total_plays || 1;

  return (
    <div className="space-y-4">
      <div role="group" aria-label="Analytics window" className="flex gap-2 text-xs">
        {[7, 30, 90].map((d) => (
          <button
            key={d}
            type="button"
            aria-pressed={days === d}
            aria-label={`Last ${d} days`}
            onClick={() => setDays(d as 7 | 30 | 90)}
            className={`px-2 py-1 border rounded ${days === d ? 'bg-black text-white' : ''}`}
          >
            {d}d
          </button>
        ))}
      </div>

      <div className="grid grid-cols-2 sm:grid-cols-5 gap-2 text-xs">
        <Metric label="Total plays" value={data.total_plays} />
        <Metric label="Unique viewers" value={data.unique_viewers} />
        <Metric label="Avg watch (s)" value={data.avg_watch_time} />
        <Metric label="Completion" value={`${data.completion_rate}%`} />
        <Metric label="Window" value={`${data.days}d`} />
      </div>

      <div>
        <div className="text-xs font-medium mb-1">Daily views ({data.days}d)</div>
        {dailyEntries.length === 0 ? (
          <div className="text-xs text-gray-500">No view data in this window.</div>
        ) : (
          <div
            role="img"
            aria-label={`Daily views over the last ${data.days} days`}
            className="flex items-end gap-1 h-24"
          >
            {dailyEntries.map(([date, count]) => (
              <div
                key={date}
                className="flex-1 flex flex-col items-center justify-end"
              >
                <div
                  className="w-full bg-indigo-500 rounded-t"
                  style={{
                    height: `${(count / dailyMax) * 100}%`,
                    minHeight: count > 0 ? '2px' : '0',
                  }}
                  title={`${date}: ${count} ${count === 1 ? 'view' : 'views'}`}
                  aria-label={`${date}: ${count} ${count === 1 ? 'view' : 'views'}`}
                />
              </div>
            ))}
          </div>
        )}
      </div>

      <div>
        <div className="text-xs font-medium mb-1">Drop-off (all-time)</div>
        <div role="img" aria-label="Drop-off chart (all-time)" className="flex items-end gap-1 h-24">
          {dropOffEntries.map(([pct, n]) => (
            <div
              key={pct}
              className="flex-1 flex flex-col items-center justify-end"
            >
              <div
                data-testid="dropoff-bar"
                aria-label={`${pct}: ${n} viewers`}
                className="w-full bg-amber-500 rounded-t"
                style={{ height: `${(n / dropOffMax) * 100}%` }}
                title={`${pct}: ${n}`}
              />
              <span className="mt-1 text-[10px] text-gray-500" aria-hidden="true">{pct}</span>
            </div>
          ))}
        </div>
      </div>

      <div>
        <div className="text-xs font-medium mb-1">Completion funnel</div>
        <div className="space-y-2">
          {[25, 50, 75, 100].map((pct) => {
            const reached = dropOffEntries
              .filter(([key]) => parseInt(key, 10) >= pct)
              .reduce((sum, [, v]) => sum + v, 0);
            const width = Math.round((reached / totalPlays) * 100);
            return (
              <div key={pct}>
                <div className="flex justify-between text-[11px] mb-0.5">
                  <span className="text-gray-700">{pct}% watched</span>
                  <span className="text-gray-500">
                    {reached} {reached === 1 ? 'viewer' : 'viewers'} ({width}%)
                  </span>
                </div>
                <div className="h-2 bg-gray-100 rounded-full overflow-hidden">
                  <div
                    className="h-full bg-emerald-500 rounded-full"
                    style={{ width: `${Math.min(100, width)}%` }}
                  />
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* hubEmbedUrl is always '{hubOrigin}/video/{slug}/embed' per API contract; strip /video/... and append /videos/{videoId} → Hub per-video analytics page */}
      <a
        href={`${hubEmbedUrl.replace(/\/video\/.*$/, '')}/videos/${videoId}`}
        target="_blank"
        rel="noreferrer"
        className="text-xs text-blue-600 underline"
      >
        Open full analytics in Hub →
      </a>
    </div>
  );
}

function Metric({
  label,
  value,
}: {
  label: string;
  value: number | string;
}) {
  return (
    <div className="bg-white border rounded p-2">
      <div className="text-gray-500">{label}</div>
      <div className="text-sm font-semibold">{value}</div>
    </div>
  );
}
