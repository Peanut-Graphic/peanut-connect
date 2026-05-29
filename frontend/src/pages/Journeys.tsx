import { useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { marketingApi, type JourneyRow } from '@/api/marketing';
import Layout from '@/components/layout/Layout';
import { Card } from '@/components/common';

/**
 * Journeys list — mirrors Hub's /journeys page inside the plugin SPA.
 *
 * Pulls via the existing /marketing/journeys plugin proxy which forwards
 * to Hub's /api/journeys. Filters: status, campaign, date range, and the
 * `event_name=click_to_portal` toggle pill introduced alongside the
 * funnel-classification work. Each row links to the in-plugin detail page.
 */
export default function Journeys() {
  const [params, setParams] = useSearchParams();

  const status = params.get('status') ?? '';
  const campaign = params.get('campaign') ?? '';
  const startDate = params.get('start_date') ?? '';
  const endDate = params.get('end_date') ?? '';
  const eventName = params.get('event_name') ?? '';
  const search = params.get('search') ?? '';
  const page = Number(params.get('page') ?? '1');

  const [searchDraft, setSearchDraft] = useState(search);

  // Populate the campaign dropdown from the same sources Analytics uses:
  // configured UTMs (active + archived) UNION journey-observed by_campaign.
  // Picking up archived + orphan campaigns matters so the operator can still
  // drill into journeys for campaigns that were never set up via the UTM
  // builder (see the dominionenergyptr.com 2026-05-21 incident note in
  // Analytics.tsx).
  const utmsActiveQuery = useQuery({
    queryKey: ['marketing', 'utms', 'all', 'active'],
    queryFn: () => marketingApi.listUtms({ archived: false, per_page: 200 }),
  });
  const utmsArchivedQuery = useQuery({
    queryKey: ['marketing', 'utms', 'all', 'archived'],
    queryFn: () => marketingApi.listUtms({ archived: true, per_page: 200 }),
  });
  const statsQuery = useQuery({
    queryKey: ['marketing', 'journeys', 'stats', 'campaigns-pool'],
    queryFn: () => marketingApi.journeyStats({}),
  });

  const campaignOptions = useMemo(() => {
    const names = new Set<string>();
    for (const u of utmsActiveQuery.data?.data ?? []) {
      if (u.utm_campaign) names.add(u.utm_campaign);
    }
    for (const u of utmsArchivedQuery.data?.data ?? []) {
      if (u.utm_campaign) names.add(u.utm_campaign);
    }
    for (const c of statsQuery.data?.by_campaign ?? []) {
      if (c.utm_campaign) names.add(c.utm_campaign);
    }
    return Array.from(names).sort();
  }, [utmsActiveQuery.data, utmsArchivedQuery.data, statsQuery.data]);

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['plugin-journeys', { status, campaign, startDate, endDate, eventName, search, page }],
    queryFn: () =>
      marketingApi.listJourneys({
        status: status || undefined,
        campaign: campaign || undefined,
        start_date: startDate || undefined,
        end_date: endDate || undefined,
        event_name: eventName || undefined,
        search: search || undefined,
        page,
        per_page: 25,
      }),
  });

  const setFilter = (key: string, value: string) => {
    const next = new URLSearchParams(params);
    if (value) {
      next.set(key, value);
    } else {
      next.delete(key);
    }
    if (key !== 'page') {
      next.delete('page');
    }
    setParams(next, { replace: false });
  };

  const clearAll = () => setParams(new URLSearchParams(), { replace: false });

  const toggleEnrollPill = () =>
    setFilter('event_name', eventName === 'click_to_portal' ? '' : 'click_to_portal');

  const onSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setFilter('search', searchDraft.trim());
  };

  const rows = data?.data ?? [];

  return (
    <Layout
      title="Journeys"
      description="Per-visitor journeys from a campaign click through to enroll / convert. Click a row for the full event timeline."
    >
      <Card className="mb-4">
        <div className="flex flex-wrap items-center gap-2 p-4">
          <form onSubmit={onSearchSubmit} className="relative">
            <input
              type="search"
              value={searchDraft}
              onChange={(e) => setSearchDraft(e.target.value)}
              placeholder="Search click_id or campaign…"
              className="border border-slate-300 rounded-lg text-sm py-2 pl-3 pr-8 w-64 focus:ring-indigo-500 focus:border-indigo-500"
            />
          </form>

          <select
            value={status}
            onChange={(e) => setFilter('status', e.target.value)}
            className="border border-slate-300 rounded-lg text-sm py-2 pl-3 pr-8"
          >
            <option value="">All status</option>
            <option value="in_progress">In progress</option>
            <option value="converted">Converted</option>
            <option value="abandoned">Abandoned</option>
          </select>

          <select
            value={campaign}
            onChange={(e) => setFilter('campaign', e.target.value)}
            className="border border-slate-300 rounded-lg text-sm py-2 pl-3 pr-8 max-w-[14rem]"
          >
            <option value="">All campaigns</option>
            {campaignOptions.map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </select>

          <input
            type="date"
            value={startDate}
            onChange={(e) => setFilter('start_date', e.target.value)}
            className="border border-slate-300 rounded-lg text-sm py-2 px-3"
          />
          <input
            type="date"
            value={endDate}
            onChange={(e) => setFilter('end_date', e.target.value)}
            className="border border-slate-300 rounded-lg text-sm py-2 px-3"
          />

          <button
            type="button"
            onClick={toggleEnrollPill}
            className={
              'inline-flex items-center gap-1.5 border rounded-lg text-sm py-2 px-3 transition-colors ' +
              (eventName === 'click_to_portal'
                ? 'bg-amber-100 border-amber-300 text-amber-800'
                : 'border-slate-300 text-slate-700 hover:bg-slate-50')
            }
            title="Show only journeys whose visitor clicked the enroll / primary CTA"
          >
            <span aria-hidden="true">⇥</span>
            Clicked enroll
            {eventName === 'click_to_portal' && <span aria-hidden="true">·×</span>}
          </button>

          {(status || campaign || startDate || endDate || eventName || search) && (
            <button
              type="button"
              onClick={clearAll}
              className="text-sm text-slate-500 hover:text-slate-700 px-2 py-2"
            >
              Clear filters
            </button>
          )}
        </div>
      </Card>

      <Card>
        {isLoading && <div className="p-6 text-sm text-slate-500">Loading journeys…</div>}
        {isError && (
          <div className="p-6 text-sm text-red-600">
            {(error as Error)?.message || 'Failed to load journeys.'}
          </div>
        )}
        {!isLoading && !isError && rows.length === 0 && (
          <div className="p-12 text-center text-sm text-slate-500">
            No journeys match the current filters.
          </div>
        )}
        {!isLoading && !isError && rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-left text-xs uppercase tracking-wide text-slate-500 border-b border-slate-200">
                <tr>
                  <th className="px-4 py-3">Click ID</th>
                  <th className="px-4 py-3">Campaign</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3">Started</th>
                  <th className="px-4 py-3">Duration</th>
                  <th className="px-4 py-3 text-right">Action</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <JourneyRowDisplay key={row.id} row={row} />
                ))}
              </tbody>
            </table>
          </div>
        )}

        {!isLoading && data && (data.last_page ?? 1) > 1 && (
          <div className="flex items-center justify-between p-4 border-t border-slate-200 text-sm">
            <div className="text-slate-500">
              Page {data.current_page ?? 1} of {data.last_page} · {data.total} total
            </div>
            <div className="flex gap-2">
              <button
                type="button"
                disabled={(data.current_page ?? 1) <= 1}
                onClick={() => setFilter('page', String((data.current_page ?? 1) - 1))}
                className="px-3 py-1.5 border border-slate-300 rounded-lg disabled:opacity-50"
              >
                ← Prev
              </button>
              <button
                type="button"
                disabled={(data.current_page ?? 1) >= (data.last_page ?? 1)}
                onClick={() => setFilter('page', String((data.current_page ?? 1) + 1))}
                className="px-3 py-1.5 border border-slate-300 rounded-lg disabled:opacity-50"
              >
                Next →
              </button>
            </div>
          </div>
        )}
      </Card>
    </Layout>
  );
}

function JourneyRowDisplay({ row }: { row: JourneyRow }) {
  const statusColor =
    row.status === 'converted'
      ? 'bg-emerald-100 text-emerald-800'
      : row.status === 'abandoned'
        ? 'bg-slate-100 text-slate-600'
        : 'bg-indigo-100 text-indigo-800';

  const started = new Date(row.started_at);
  const startedLabel = started.toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });

  const duration =
    row.duration_seconds !== null
      ? row.duration_seconds < 60
        ? `${row.duration_seconds}s`
        : row.duration_seconds < 3600
          ? `${Math.floor(row.duration_seconds / 60)}m`
          : `${Math.floor(row.duration_seconds / 3600)}h ${Math.floor((row.duration_seconds % 3600) / 60)}m`
      : '—';

  return (
    <tr className="border-b border-slate-100 hover:bg-slate-50">
      <td className="px-4 py-3 font-mono text-xs text-slate-700">
        <Link to={`/analytics/journeys/${row.click_id}`} className="hover:underline text-indigo-600">
          {row.click_id.slice(0, 12)}…
        </Link>
      </td>
      <td className="px-4 py-3">{row.utm_campaign ?? '—'}</td>
      <td className="px-4 py-3">
        <span className={`inline-block px-2 py-0.5 rounded text-xs font-medium ${statusColor}`}>
          {row.status.replace('_', ' ')}
        </span>
      </td>
      <td className="px-4 py-3 text-slate-600">{startedLabel}</td>
      <td className="px-4 py-3 text-slate-600">{duration}</td>
      <td className="px-4 py-3 text-right">
        <Link
          to={`/analytics/journeys/${row.click_id}`}
          className="text-sm text-indigo-600 hover:underline"
        >
          View Timeline
        </Link>
      </td>
    </tr>
  );
}
