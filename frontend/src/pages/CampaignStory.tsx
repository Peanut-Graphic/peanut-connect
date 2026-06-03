import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { marketingApi } from '@/api';
import Layout from '@/components/layout/Layout';
import { Card } from '@/components/common';

/**
 * Plugin-side campaign lifecycle story page. Mirrors Hub's
 * /analytics/campaign/{campaign} via the /marketing/campaign/.../story
 * proxy — same data, same shape, rendered inside wp-admin so the
 * marketing team gets the canonical campaign view without leaving the
 * plugin.
 */
export default function CampaignStoryPage() {
  const { campaign = '' } = useParams<{ campaign: string }>();

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['plugin-campaign-story', campaign],
    queryFn: () => marketingApi.campaignStory(campaign),
    enabled: campaign !== '',
  });

  return (
    <Layout
      title={data?.campaign?.name ?? campaign ?? 'Campaign story'}
      description={
        data
          ? `${new Date(data.window.start).toLocaleDateString()} → ${new Date(data.window.end).toLocaleDateString()} · ${data.campaign.utm_count} UTM${data.campaign.utm_count === 1 ? '' : 's'}`
          : 'Per-campaign lifecycle view — UTMs, sends, clicks, journeys, CTA engagement, conversions.'
      }
      action={
        <Link
          to="/analytics"
          className="inline-flex items-center px-3 py-1.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50"
        >
          ← Analytics
        </Link>
      }
    >
      {isLoading && (
        <Card>
          <div className="p-6 text-sm text-slate-500">Loading campaign…</div>
        </Card>
      )}
      {isError && (
        <Card>
          <div className="p-6 text-sm text-red-600">
            {(error as Error)?.message || 'Failed to load campaign story.'}
          </div>
        </Card>
      )}

      {data && (
        <>
          {/* KPI strip */}
          <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-4">
            <Kpi label="Sends" value={data.campaign.sends.toLocaleString()} sub={data.campaign.sends === 0 ? 'not entered' : undefined} />
            <Kpi label="Journeys" value={data.kpis.journeys.toLocaleString()} />
            <Kpi label="Clicked enroll" value={data.kpis.engaged.toLocaleString()} />
            <Kpi label="Enrolled" value={data.kpis.enrolled.toLocaleString()} accent="emerald" />
            <Kpi label="Conv. rate" value={`${data.kpis.conversion_rate}%`} />
            <Kpi
              label={data.kpis.cpa !== null ? 'CPA' : 'Spend'}
              value={
                data.kpis.cpa !== null
                  ? `$${data.kpis.cpa.toFixed(2)}`
                  : data.campaign.spend > 0
                    ? `$${data.campaign.spend.toFixed(2)}`
                    : '—'
              }
              sub={data.kpis.cpa !== null ? `$${data.campaign.spend.toFixed(2)} spent` : undefined}
            />
          </div>

          {/* Funnel + Time series */}
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
            <Card>
              <div className="p-4">
                <h2 className="text-base font-medium text-slate-900 mb-1">Funnel</h2>
                <p className="text-xs text-slate-500 mb-3">Stage progression for this campaign.</p>
                <FunnelChart stages={data.funnel} />
              </div>
            </Card>
            <Card className="lg:col-span-2">
              <div className="p-4">
                <h2 className="text-base font-medium text-slate-900 mb-1">Daily volume</h2>
                <p className="text-xs text-slate-500 mb-3">Journeys, clicked-enroll, conversions per day.</p>
                <TimeSeriesChart data={data.time_series} />
              </div>
            </Card>
          </div>

          {/* Top links + By channel */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
            <Card>
              <div className="px-4 py-3 border-b border-slate-200">
                <h2 className="text-base font-medium text-slate-900">Top short links</h2>
                <p className="text-xs text-slate-500 mt-0.5">Slugs that drove the most journeys.</p>
              </div>
              {data.top_links.length === 0 ? (
                <div className="p-6 text-sm text-slate-500">No links tied to journeys yet.</div>
              ) : (
                <table className="w-full text-sm">
                  <thead className="text-left text-xs uppercase text-slate-500 border-b border-slate-200">
                    <tr>
                      <th className="px-4 py-2">Slug</th>
                      <th className="px-4 py-2 text-right">Journeys</th>
                      <th className="px-4 py-2 text-right">Conv.</th>
                      <th className="px-4 py-2 text-right">Rate</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.top_links.map((l) => (
                      <tr key={l.link_id} className="border-b border-slate-100">
                        <td className="px-4 py-2 font-mono text-xs text-slate-900 truncate max-w-xs">
                          {l.slug ?? '—'}
                          {l.title && <div className="text-[11px] text-slate-500 font-sans truncate">{l.title}</div>}
                        </td>
                        <td className="px-4 py-2 text-right tabular-nums">{l.journeys.toLocaleString()}</td>
                        <td className="px-4 py-2 text-right tabular-nums text-emerald-700">{l.conversions.toLocaleString()}</td>
                        <td className="px-4 py-2 text-right tabular-nums text-slate-500">{l.conversion_rate}%</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </Card>

            <Card>
              <div className="px-4 py-3 border-b border-slate-200">
                <h2 className="text-base font-medium text-slate-900">By channel</h2>
                <p className="text-xs text-slate-500 mt-0.5">utm_medium breakdown.</p>
              </div>
              {data.by_channel.length === 0 ? (
                <div className="p-6 text-sm text-slate-500">No channel data.</div>
              ) : (
                <ul className="divide-y divide-slate-100">
                  {data.by_channel.map((c) => (
                    <li key={c.medium} className="px-4 py-3 flex items-center justify-between">
                      <span className="font-mono text-sm text-slate-900">{c.medium}</span>
                      <span className="text-sm text-slate-500 tabular-nums">
                        {c.journeys.toLocaleString()}{' '}
                        <span className="text-emerald-700">({c.conversions.toLocaleString()} conv)</span>
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          </div>

          {/* Sample journeys */}
          <Card>
            <div className="px-4 py-3 border-b border-slate-200">
              <h2 className="text-base font-medium text-slate-900">Recent journeys</h2>
              <p className="text-xs text-slate-500 mt-0.5">Last 25 journeys for this campaign.</p>
            </div>
            {data.sample_journeys.length === 0 ? (
              <div className="p-6 text-sm text-slate-500">No journeys in this window.</div>
            ) : (
              <table className="w-full text-sm">
                <thead className="text-left text-xs uppercase text-slate-500 border-b border-slate-200">
                  <tr>
                    <th className="px-4 py-2">Started</th>
                    <th className="px-4 py-2">Click ID</th>
                    <th className="px-4 py-2">Source / medium</th>
                    <th className="px-4 py-2">Status</th>
                    <th className="px-4 py-2 text-right">Action</th>
                  </tr>
                </thead>
                <tbody>
                  {data.sample_journeys.map((j) => (
                    <tr key={j.id} className="border-b border-slate-100 hover:bg-slate-50">
                      <td className="px-4 py-2 text-xs text-slate-500 whitespace-nowrap">
                        {new Date(j.started_at).toLocaleString(undefined, {
                          month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
                        })}
                      </td>
                      <td className="px-4 py-2 font-mono text-xs">{j.click_id.slice(0, 12)}…</td>
                      <td className="px-4 py-2 text-slate-500 text-xs">
                        {j.utm_source ?? '—'} / {j.utm_medium ?? '—'}
                      </td>
                      <td className="px-4 py-2">
                        <span
                          className={
                            'inline-block px-2 py-0.5 rounded text-xs font-medium ' +
                            (j.status === 'converted'
                              ? 'bg-emerald-100 text-emerald-800'
                              : j.status === 'abandoned'
                                ? 'bg-slate-100 text-slate-600'
                                : 'bg-indigo-100 text-indigo-800')
                          }
                        >
                          {j.status.replace('_', ' ')}
                        </span>
                      </td>
                      <td className="px-4 py-2 text-right">
                        <Link to={`/analytics/journeys/${j.click_id}`} className="text-sm text-indigo-600 hover:underline">
                          View timeline →
                        </Link>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </Card>
        </>
      )}
    </Layout>
  );
}

function Kpi({ label, value, sub, accent }: { label: string; value: string; sub?: string; accent?: 'emerald' }) {
  return (
    <div className="bg-white rounded-lg shadow p-3">
      <div className="text-xs uppercase tracking-wide text-slate-500">{label}</div>
      <div className={'text-2xl font-bold mt-0.5 ' + (accent === 'emerald' ? 'text-emerald-700' : 'text-slate-900')}>
        {value}
      </div>
      {sub && <div className="text-[11px] text-slate-500 mt-0.5">{sub}</div>}
    </div>
  );
}

function FunnelChart({ stages }: { stages: Array<{ stage: string; label: string; count: number }> }) {
  const max = Math.max(1, ...stages.map((s) => s.count));
  return (
    <div className="space-y-3">
      {stages.map((s) => {
        const width = Math.round((s.count / max) * 100);
        return (
          <div key={s.stage}>
            <div className="flex justify-between text-sm mb-1">
              <span className="text-slate-700">{s.label}</span>
              <span className="text-slate-900 font-medium tabular-nums">{s.count.toLocaleString()}</span>
            </div>
            <div className="h-3 bg-slate-100 rounded-full overflow-hidden">
              <div className="h-full bg-indigo-500 rounded-full" style={{ width: `${width}%` }} />
            </div>
          </div>
        );
      })}
    </div>
  );
}

function TimeSeriesChart({
  data,
}: {
  data: Array<{ date: string; journeys: number; conversions: number; clicked_enroll: number }>;
}) {
  if (data.length === 0) {
    return <div className="text-sm text-slate-500">No data in window.</div>;
  }
  const max = Math.max(1, ...data.map((p) => Math.max(p.journeys, p.conversions, p.clicked_enroll)));
  return (
    <>
      <div className="flex items-center gap-4 text-xs mb-2">
        <Legend color="#6366f1" label="Journeys" />
        <Legend color="#f59e0b" label="Clicked enroll" />
        <Legend color="#10b981" label="Conversions" />
      </div>
      <svg viewBox="0 0 800 200" className="w-full h-44" preserveAspectRatio="xMidYMid meet">
        {data.map((p, i) => {
          const x = data.length === 1 ? 400 : (i / (data.length - 1)) * 800;
          const next = data[i + 1];
          if (!next) return null;
          const nx = ((i + 1) / (data.length - 1)) * 800;
          const yj1 = 200 - (p.journeys / max) * 200;
          const yj2 = 200 - (next.journeys / max) * 200;
          const ye1 = 200 - (p.clicked_enroll / max) * 200;
          const ye2 = 200 - (next.clicked_enroll / max) * 200;
          const yc1 = 200 - (p.conversions / max) * 200;
          const yc2 = 200 - (next.conversions / max) * 200;
          return (
            <g key={i}>
              <line x1={x} y1={yj1} x2={nx} y2={yj2} stroke="#6366f1" strokeWidth={1.5} />
              <line x1={x} y1={ye1} x2={nx} y2={ye2} stroke="#f59e0b" strokeWidth={1.5} />
              <line x1={x} y1={yc1} x2={nx} y2={yc2} stroke="#10b981" strokeWidth={1.5} />
            </g>
          );
        })}
      </svg>
    </>
  );
}

function Legend({ color, label }: { color: string; label: string }) {
  return (
    <span className="inline-flex items-center gap-1.5">
      <span className="inline-block w-3 h-3 rounded-sm" style={{ backgroundColor: color }} />
      <span className="text-slate-600">{label}</span>
    </span>
  );
}
