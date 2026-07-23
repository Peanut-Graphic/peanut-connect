import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { marketingApi } from '@/api';
import type { DominionFunnelParams } from '@/api';
import Layout from '@/components/layout/Layout';
import { Card } from '@/components/common';
import { Funnel } from '@/components/charts/Funnel';

const RANGES = [
  { label: 'Last 7 days', value: 7 },
  { label: 'Last 30 days', value: 30 },
  { label: 'Last 90 days', value: 90 },
] as const;

const STEP_LABELS: Record<string, string> = {
  landed: 'Landed',
  entered: 'Entered portal',
  validation: 'Validation',
  login: 'Login',
  dashboard: 'Dashboard',
  enrolled: 'Enrolled',
};

function rangeFromDays(days: number): { from: string; to: string } {
  const to = new Date();
  const from = new Date(to.getTime() - days * 24 * 60 * 60 * 1000);
  return { from: from.toISOString().slice(0, 10), to: to.toISOString().slice(0, 10) };
}

function fmtDuration(seconds: number): string {
  if (!seconds || seconds < 1) return '—';
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

export default function DominionFunnel() {
  const [days, setDays] = useState<(typeof RANGES)[number]['value']>(30);
  const [customFrom, setCustomFrom] = useState('');
  const [customTo, setCustomTo] = useState('');
  const [campaign, setCampaign] = useState('');
  const [showTest, setShowTest] = useState(false);
  const [stage, setStage] = useState('');
  const [page, setPage] = useState(1);

  const isCustomRange = customFrom !== '' && customTo !== '';
  const range = isCustomRange ? { from: customFrom, to: customTo } : rangeFromDays(days);

  // Stable dropdown: query without a campaign filter so the option list never
  // collapses to just the selected campaign. per_page:1 keeps the payload small.
  const campaignsQuery = useQuery({
    queryKey: ['dominion-funnel-campaigns', { from: range.from, to: range.to, showTest }],
    queryFn: () =>
      marketingApi.dominionFunnel({
        from: range.from,
        to: range.to,
        include_test: showTest,
        per_page: 1,
      }),
  });
  const campaignOptions = useMemo(
    () => (campaignsQuery.data?.campaigns ?? []).map((c) => c.campaign),
    [campaignsQuery.data],
  );

  const params: DominionFunnelParams = {
    from: range.from,
    to: range.to,
    campaign: campaign || undefined,
    include_test: showTest,
    stage: stage || undefined,
    page,
    per_page: 50,
  };
  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['dominion-funnel', { ...params }],
    queryFn: () => marketingApi.dominionFunnel(params),
  });

  // Any filter change resets pagination to page 1.
  const withPageReset = (fn: () => void) => {
    fn();
    setPage(1);
  };
  const onStageClick = (s: string) =>
    withPageReset(() => setStage((cur) => (cur === s ? '' : s)));

  return (
    <Layout
      title="Dominion Funnel"
      description="Marketing → portal → enrollment funnel for the Dominion Peak Time Rebates program."
      action={
        <Link to="/analytics" className="text-sm text-slate-500 hover:text-slate-700">
          ← Analytics
        </Link>
      }
    >
      <Card className="mb-4">
        <div className="flex flex-wrap items-center gap-2 p-4">
          <div className="flex items-center gap-1">
            {RANGES.map((r) => (
              <button
                key={r.value}
                type="button"
                onClick={() =>
                  withPageReset(() => {
                    setDays(r.value);
                    setCustomFrom('');
                    setCustomTo('');
                  })
                }
                className={
                  !isCustomRange && days === r.value
                    ? 'px-3 py-1.5 text-sm font-medium rounded bg-primary-600 text-white'
                    : 'px-3 py-1.5 text-sm font-medium rounded text-slate-600 hover:bg-slate-100'
                }
              >
                {r.label}
              </button>
            ))}
          </div>
          <div className="flex items-center gap-1 pl-2 border-l border-slate-200">
            <input
              type="date"
              value={customFrom}
              max={customTo || undefined}
              onChange={(e) => withPageReset(() => setCustomFrom(e.target.value))}
              aria-label="Custom range start"
              className="border border-slate-300 rounded text-xs py-1 px-2"
            />
            <span className="text-xs text-slate-400">→</span>
            <input
              type="date"
              value={customTo}
              min={customFrom || undefined}
              onChange={(e) => withPageReset(() => setCustomTo(e.target.value))}
              aria-label="Custom range end"
              className="border border-slate-300 rounded text-xs py-1 px-2"
            />
          </div>
          <select
            value={campaign}
            onChange={(e) => withPageReset(() => setCampaign(e.target.value))}
            className="border border-slate-300 rounded-lg text-sm py-2 pl-3 pr-8 max-w-[14rem]"
            aria-label="Campaign"
          >
            <option value="">All campaigns</option>
            {campaignOptions.map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </select>
          <button
            type="button"
            onClick={() => withPageReset(() => setShowTest((v) => !v))}
            className={
              'inline-flex items-center gap-1.5 border rounded-lg text-sm py-2 px-3 transition-colors ' +
              (showTest
                ? 'bg-amber-100 border-amber-300 text-amber-800'
                : 'border-slate-300 text-slate-700 hover:bg-slate-50')
            }
            title="Include QA / test campaigns in the funnel and campaign list"
          >
            Show test campaigns
          </button>
          {stage && (
            <button
              type="button"
              onClick={() => onStageClick(stage)}
              className="text-sm text-slate-500 hover:text-slate-700 px-2 py-2"
            >
              Clear stage: {STEP_LABELS[stage] ?? stage} ×
            </button>
          )}
        </div>
      </Card>

      {isLoading && <Card className="p-6 text-sm text-slate-500">Loading funnel…</Card>}
      {isError && (
        <Card className="p-6 text-sm text-red-600">
          Failed to load funnel: {(error as Error)?.message}
        </Card>
      )}

      {data && (
        <>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <Kpi label="Landed" value={data.kpis.landed.toLocaleString()} />
            <Kpi label="Entered portal" value={data.kpis.entered.toLocaleString()} />
            <Kpi label="Enrolled" value={data.kpis.enrolled.toLocaleString()} accent />
            <Kpi label="Conversion" value={`${(data.kpis.conversion_rate * 100).toFixed(2)}%`} />
          </div>

          <div className="grid gap-4 lg:grid-cols-2 mb-2">
            <Card className="p-4">
              <h3 className="text-xs font-semibold uppercase text-slate-500 mb-3">
                Reaching the portal
              </h3>
              <Funnel stages={data.reaching} onStageClick={onStageClick} />
            </Card>
            <Card className="p-4">
              <h3 className="text-xs font-semibold uppercase text-slate-500 mb-3">
                Inside the flow
              </h3>
              <Funnel stages={data.inside} onStageClick={onStageClick} />
            </Card>
          </div>

          <div className="text-xs text-slate-400 space-y-1 mb-4">
            <p>{data.meta.attribution_note}</p>
            <p>{data.meta.enrolled_note}</p>
          </div>

          <Card>
            <div className="flex items-center justify-between p-4 border-b border-slate-100">
              <h3 className="text-sm font-semibold text-slate-700">
                Journeys{stage ? ` · ${STEP_LABELS[stage] ?? stage}` : ''}
              </h3>
              <span className="text-xs text-slate-400">Click a funnel stage to filter</span>
            </div>
            {data.journeys.data.length === 0 ? (
              <div className="p-6 text-sm text-slate-500">No journeys in this range.</div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-xs text-slate-500 border-b border-slate-100">
                      <th className="px-4 py-2 font-medium">Campaign</th>
                      <th className="px-4 py-2 font-medium">Furthest step</th>
                      <th className="px-4 py-2 font-medium">Status</th>
                      <th className="px-4 py-2 font-medium">Duration</th>
                      <th className="px-4 py-2 font-medium">Entered</th>
                      <th className="px-4 py-2"></th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.journeys.data.map((j) => (
                      <tr key={j.click_id} className="border-b border-slate-50 hover:bg-slate-50">
                        <td className="px-4 py-2">{j.campaign ?? '—'}</td>
                        <td className="px-4 py-2">
                          {STEP_LABELS[j.furthest_step] ?? j.furthest_step}
                        </td>
                        <td className="px-4 py-2">{j.status}</td>
                        <td className="px-4 py-2">{fmtDuration(j.duration_seconds)}</td>
                        <td className="px-4 py-2 text-slate-500">
                          {j.entered_at ? new Date(j.entered_at).toLocaleString() : '—'}
                        </td>
                        <td className="px-4 py-2 text-right">
                          <Link
                            to={`/analytics/dominion-funnel/journey/${j.click_id}`}
                            className="text-indigo-600 hover:text-indigo-800"
                          >
                            View timeline →
                          </Link>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            {data.journeys.meta.last_page > 1 && (
              <div className="flex items-center justify-between p-4 text-sm">
                <button
                  type="button"
                  disabled={page <= 1}
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  className="px-3 py-1.5 rounded border border-slate-300 disabled:opacity-40"
                >
                  Previous
                </button>
                <span className="text-slate-500">
                  Page {data.journeys.meta.current_page} of {data.journeys.meta.last_page}
                </span>
                <button
                  type="button"
                  disabled={page >= data.journeys.meta.last_page}
                  onClick={() => setPage((p) => p + 1)}
                  className="px-3 py-1.5 rounded border border-slate-300 disabled:opacity-40"
                >
                  Next
                </button>
              </div>
            )}
          </Card>
        </>
      )}
    </Layout>
  );
}

function Kpi({ label, value, accent }: { label: string; value: string; accent?: boolean }) {
  return (
    <div className="bg-white border border-slate-200 rounded-lg p-4">
      <div className={'text-2xl font-bold ' + (accent ? 'text-green-600' : 'text-slate-800')}>
        {value}
      </div>
      <div className="text-xs text-slate-500 mt-1">{label}</div>
    </div>
  );
}
