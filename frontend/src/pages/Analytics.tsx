import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Layout } from '@/components/layout';
import { Card, CardHeader, StatCard, Alert } from '@/components/common';
import { marketingApi, type JourneyStats } from '@/api';

const RANGES = [
  { label: 'Last 7 days', value: 7 },
  { label: 'Last 30 days', value: 30 },
  { label: 'Last 90 days', value: 90 },
] as const;

export default function Analytics() {
  const [days, setDays] = useState<(typeof RANGES)[number]['value']>(30);

  const range = rangeFromDays(days);

  const { data, isLoading, error } = useQuery({
    queryKey: ['marketing', 'journeys', 'stats', { from: range.from, to: range.to }],
    queryFn: () => marketingApi.journeyStats({ from: range.from, to: range.to }),
  });

  return (
    <Layout
      title="Campaign Analytics"
      description="Click-through and conversion data from journeys that started with one of your tracked links."
      action={
        <div className="flex items-center gap-1">
          {RANGES.map((r) => (
            <button
              key={r.value}
              type="button"
              onClick={() => setDays(r.value)}
              className={
                days === r.value
                  ? 'px-3 py-1.5 text-sm font-medium rounded bg-primary-600 text-white'
                  : 'px-3 py-1.5 text-sm font-medium rounded text-slate-600 hover:bg-slate-100'
              }
            >
              {r.label}
            </button>
          ))}
        </div>
      }
    >
      {error && (
        <Alert variant="error" className="mb-4">
          {(error as Error).message}
        </Alert>
      )}

      <div className="grid gap-4 sm:grid-cols-3 mb-6">
        <StatCard
          title="Total journeys"
          value={isLoading ? '—' : (data?.total_journeys ?? 0).toLocaleString()}
        />
        <StatCard
          title="Conversions"
          value={isLoading ? '—' : (data?.conversions ?? 0).toLocaleString()}
        />
        <StatCard
          title="Conversion rate"
          value={
            isLoading ? '—' : `${((data?.conversion_rate ?? 0) * 100).toFixed(1)}%`
          }
        />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <ByCampaignCard data={data} loading={isLoading} />
        <ByChannelCard data={data} loading={isLoading} />
      </div>
    </Layout>
  );
}

function ByCampaignCard({ data, loading }: { data: JourneyStats | undefined; loading: boolean }) {
  const rows = (data?.by_campaign ?? []).slice(0, 10);

  return (
    <Card>
      <CardHeader title="Top campaigns" description="Ordered by journeys in the selected window." />
      {loading ? (
        <div className="text-sm text-slate-500">Loading…</div>
      ) : rows.length === 0 ? (
        <div className="text-sm text-slate-500">No campaign activity yet.</div>
      ) : (
        <table className="w-full text-sm">
          <thead className="text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th className="text-left py-2 font-medium">Campaign</th>
              <th className="text-right py-2 font-medium">Journeys</th>
              <th className="text-right py-2 font-medium">Conversions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {rows.map((row) => (
              <tr key={row.utm_campaign}>
                <td className="py-2 text-slate-900">{row.utm_campaign}</td>
                <td className="py-2 text-right tabular-nums">{row.journeys.toLocaleString()}</td>
                <td className="py-2 text-right tabular-nums">{row.conversions.toLocaleString()}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Card>
  );
}

function ByChannelCard({ data, loading }: { data: JourneyStats | undefined; loading: boolean }) {
  const rows = data?.by_channel ?? [];
  const total = rows.reduce((s, r) => s + r.journeys, 0);

  return (
    <Card>
      <CardHeader title="By channel" description="Where the journeys came from." />
      {loading ? (
        <div className="text-sm text-slate-500">Loading…</div>
      ) : rows.length === 0 ? (
        <div className="text-sm text-slate-500">No channel data yet.</div>
      ) : (
        <ul className="space-y-2">
          {rows.map((row) => {
            const pct = total > 0 ? (row.journeys / total) * 100 : 0;
            return (
              <li key={row.channel}>
                <div className="flex items-center justify-between text-sm">
                  <span className="text-slate-700">{row.channel}</span>
                  <span className="text-slate-500 tabular-nums">
                    {row.journeys.toLocaleString()} · {pct.toFixed(0)}%
                  </span>
                </div>
                <div className="mt-1 h-1.5 rounded bg-slate-100 overflow-hidden">
                  <div className="h-full bg-primary-500" style={{ width: `${pct}%` }} />
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </Card>
  );
}

function rangeFromDays(days: number): { from: string; to: string } {
  const to = new Date();
  const from = new Date(to.getTime() - days * 24 * 60 * 60 * 1000);
  return {
    from: from.toISOString().slice(0, 10),
    to: to.toISOString().slice(0, 10),
  };
}
