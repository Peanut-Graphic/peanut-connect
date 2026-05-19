import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { videosApi } from '@/api';

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

  const buckets = Object.entries(data.drop_off_all_time);
  const max = Math.max(1, ...buckets.map(([, n]) => n));

  return (
    <div className="space-y-3">
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
        <div className="text-xs font-medium mb-1">Drop-off (all-time)</div>
        <div role="img" aria-label="Drop-off chart (all-time)" className="flex items-end gap-1 h-24">
          {buckets.map(([pct, n]) => (
            <div
              key={pct}
              className="flex-1 flex flex-col items-center justify-end"
            >
              <div
                data-testid="dropoff-bar"
                aria-label={`${pct}: ${n} viewers`}
                className="w-full bg-amber-500 rounded-t"
                style={{ height: `${(n / max) * 100}%` }}
                title={`${pct}: ${n}`}
              />
              <span className="mt-1 text-[10px] text-gray-500" aria-hidden="true">{pct}</span>
            </div>
          ))}
        </div>
      </div>
      {/* hubEmbedUrl is always '{hubOrigin}/video/{slug}/embed' per API contract; stripping /video/... → Hub analytics root */}
      <a
        href={hubEmbedUrl.replace(/\/video\/.*$/, '')}
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
