import { useState, useMemo, useEffect, useRef } from 'react';
import { Link, useSearchParams, useNavigate } from 'react-router-dom';
import { useQuery, useQueries } from '@tanstack/react-query';
import { Layout } from '@/components/layout';
import { Card, CardHeader, StatCard, Alert } from '@/components/common';
import { Funnel } from '@/components/charts/Funnel';
import { TimeSeries } from '@/components/charts/TimeSeries';
import { Donut } from '@/components/charts/Donut';
import {
  CampaignOutcomeBars,
  type CampaignOutcome,
  type Outcome,
} from '@/components/charts/CampaignOutcomeBars';
import { marketingApi, type JourneyStats, type JourneyRow } from '@/api';
import { ChevronDown, X } from 'lucide-react';

const RANGES = [
  { label: 'Last 7 days', value: 7 },
  { label: 'Last 30 days', value: 30 },
  { label: 'Last 90 days', value: 90 },
] as const;

export default function Analytics() {
  const [searchParams] = useSearchParams();
  const [days, setDays] = useState<(typeof RANGES)[number]['value']>(30);
  const [customFrom, setCustomFrom] = useState<string>('');
  const [customTo, setCustomTo] = useState<string>('');
  const [selectedCampaigns, setSelectedCampaigns] = useState<string[]>([]);
  const [journeysPage, setJourneysPage] = useState(1);
  const [showOnlyEnrollClicks, setShowOnlyEnrollClicks] = useState(false);
  const journeysSectionRef = useRef<HTMLDivElement | null>(null);

  // Anchor-scroll into the Journeys section when the top-nav Journeys link
  // is clicked (it sets ?focus=journeys). Keeps the user inside Analytics
  // instead of jumping to a standalone page.
  useEffect(() => {
    if (searchParams.get('focus') === 'journeys') {
      // Defer to next tick so the section is mounted before scroll.
      const t = window.setTimeout(() => {
        journeysSectionRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }, 50);
      return () => window.clearTimeout(t);
    }
  }, [searchParams]);

  // If both custom dates are set, they override the preset window.
  const isCustomRange = customFrom !== '' && customTo !== '';
  const range = isCustomRange
    ? { from: customFrom, to: customTo }
    : rangeFromDays(days);

  // Hub URL — used to deep-link to the funnel editor when a campaign is selected.
  const trackingQuery = useQuery({
    queryKey: ['marketing', 'tracking-setup'],
    queryFn: () => marketingApi.trackingSetup(),
  });
  const hubUrl = trackingQuery.data?.hub_url ?? null;

  // Always fetch the unfiltered view — used for KPI cards (when nothing selected)
  // and for populating the campaign list in the filter dropdown.
  const allQuery = useQuery({
    queryKey: ['marketing', 'journeys', 'stats', 'all', { from: range.from, to: range.to }],
    queryFn: () => marketingApi.journeyStats({ from: range.from, to: range.to }),
  });

  // Source the campaign filter dropdown from BOTH UTMs defined for this site
  // (active + archived) AND journey-observed `by_campaign`. The UTM list
  // catches campaigns the operator has set up but not yet driven traffic to;
  // by_campaign catches "orphan" campaigns — journey rows whose utm_campaign
  // was set via hardcoded URLs (a marketer pasting `?utm_campaign=foo` into
  // an email outside the Hub UTM builder, an ad-platform tag, a deleted UTM).
  // Without the by_campaign source, those journeys appear in the aggregate
  // funnel but can never be drilled into — exactly the bug
  // dominionenergyptr.com hit on 2026-05-21.
  const utmsActiveQuery = useQuery({
    queryKey: ['marketing', 'utms', 'all', 'active'],
    queryFn: () => marketingApi.listUtms({ archived: false, per_page: 200 }),
  });
  const utmsArchivedQuery = useQuery({
    queryKey: ['marketing', 'utms', 'all', 'archived'],
    queryFn: () => marketingApi.listUtms({ archived: true, per_page: 200 }),
  });

  const isCompare = selectedCampaigns.length >= 2;
  const isFiltered = selectedCampaigns.length === 1;

  // Per-campaign queries fired in parallel for compare mode (and for the
  // single-campaign filter case).
  const perCampaignQueries = useQueries({
    queries: selectedCampaigns.map((c) => ({
      queryKey: ['marketing', 'journeys', 'stats', { campaign: c, from: range.from, to: range.to }],
      queryFn: () => marketingApi.journeyStats({ campaign: c, from: range.from, to: range.to }),
    })),
  });

  // The "current view" data for KPI cards = filtered if exactly 1 selected,
  // otherwise the all-campaigns aggregate.
  const currentData: JourneyStats | undefined = isFiltered
    ? perCampaignQueries[0]?.data
    : allQuery.data;
  const isLoading = allQuery.isLoading;
  const error = allQuery.error || perCampaignQueries.find((q) => q.error)?.error;

  const allCampaigns = useMemo(() => {
    const names = new Set<string>();
    for (const u of utmsActiveQuery.data?.data ?? []) {
      if (u.utm_campaign) names.add(u.utm_campaign);
    }
    for (const u of utmsArchivedQuery.data?.data ?? []) {
      if (u.utm_campaign) names.add(u.utm_campaign);
    }
    // Union in journey-observed campaigns so orphan utm_campaign values
    // (no matching UTM record) are still drillable. Hub caps `by_campaign`
    // at 100 entries — sites with more distinct campaigns/window than that
    // need a dedicated /journeys/campaigns endpoint, see peanut-hub#384.
    for (const c of allQuery.data?.by_campaign ?? []) {
      if (c.utm_campaign) names.add(c.utm_campaign);
    }
    return Array.from(names).sort();
  }, [utmsActiveQuery.data, utmsArchivedQuery.data, allQuery.data]);

  // Per-campaign Converted vs Not-converted, from data already on the page
  // (by_campaign carries journeys + conversions; the non-converted remainder
  // is a single bucket — a 3-way in-progress/abandoned split would need a HUB
  // field, tracked separately).
  const outcomeData: CampaignOutcome[] = useMemo(
    () =>
      (allQuery.data?.by_campaign ?? []).map((c) => ({
        campaign: c.utm_campaign,
        converted: c.conversions,
        not_converted: Math.max(c.journeys - c.conversions, 0),
      })),
    [allQuery.data],
  );

  const navigate = useNavigate();
  const goToJourneys = (campaign: string, outcome?: Outcome) => {
    const p = new URLSearchParams({ campaign });
    if (outcome === 'converted') p.set('status', 'converted');
    navigate(`/analytics/journeys?${p.toString()}`);
  };

  function toggleCampaign(c: string) {
    setSelectedCampaigns((prev) =>
      prev.includes(c) ? prev.filter((x) => x !== c) : [...prev, c],
    );
  }

  function clearCampaigns() {
    setSelectedCampaigns([]);
  }

  return (
    <Layout
      title="Campaign Analytics"
      description="Click-through and conversion data from journeys that started with one of your tracked links."
      action={
        <div className="flex flex-wrap items-center gap-2">
          <div className="flex items-center gap-1">
            {RANGES.map((r) => (
              <button
                key={r.value}
                type="button"
                onClick={() => {
                  setDays(r.value);
                  setCustomFrom('');
                  setCustomTo('');
                }}
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
              onChange={(e) => setCustomFrom(e.target.value)}
              aria-label="Custom range start"
              className="border border-slate-300 rounded text-xs py-1 px-2"
            />
            <span className="text-xs text-slate-400">→</span>
            <input
              type="date"
              value={customTo}
              min={customFrom || undefined}
              onChange={(e) => setCustomTo(e.target.value)}
              aria-label="Custom range end"
              className="border border-slate-300 rounded text-xs py-1 px-2"
            />
            {isCustomRange && (
              <button
                type="button"
                onClick={() => {
                  setCustomFrom('');
                  setCustomTo('');
                }}
                className="ml-1 text-xs text-slate-500 hover:text-slate-700"
                aria-label="Clear custom range"
              >
                ×
              </button>
            )}
          </div>
        </div>
      }
    >
      {error && (
        <Alert variant="error" className="mb-4">
          {(error as Error).message}
        </Alert>
      )}

      <CampaignFilter
        all={allCampaigns}
        selected={selectedCampaigns}
        onToggle={toggleCampaign}
        onClear={clearCampaigns}
      />

      {!isCompare && (
        <>
          <div className="grid gap-4 sm:grid-cols-3 lg:grid-cols-5 mb-6">
            <StatCard
              title="Total journeys"
              value={isLoading ? '—' : (currentData?.total_journeys ?? 0).toLocaleString()}
            />
            <StatCard
              title="Conversions"
              value={isLoading ? '—' : (currentData?.conversions ?? 0).toLocaleString()}
            />
            <StatCard
              title="Conversion rate"
              value={isLoading ? '—' : `${(currentData?.conversion_rate ?? 0).toFixed(1)}%`}
            />
            <StatCard
              title="Cost / acquisition"
              value={
                isLoading
                  ? '—'
                  : currentData?.cost_per_acquisition != null
                  ? `$${currentData.cost_per_acquisition.toFixed(2)}`
                  : '—'
              }
            />
            <StatCard
              title="Click-through rate"
              value={
                isLoading
                  ? '—'
                  : currentData?.click_through_rate != null
                  ? `${currentData.click_through_rate.toFixed(2)}%`
                  : '—'
              }
            />
          </div>

          <Card>
            <CardHeader
              title={isFiltered ? `Funnel — ${selectedCampaigns[0]}` : 'Conversion funnel'}
              description="Stages from campaign send to enrollment, with drop-off between each."
              action={
                isFiltered && hubUrl ? (
                  <a
                    href={`${hubUrl.replace(/\/$/, '')}/campaigns/${encodeURIComponent(selectedCampaigns[0])}/funnel/edit`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-sm text-sky-600 hover:underline"
                  >
                    Edit funnel in Hub →
                  </a>
                ) : undefined
              }
            />
            {isLoading ? (
              <div className="text-sm text-slate-500">Loading…</div>
            ) : (
              <Funnel stages={currentData?.funnel ?? []} />
            )}
          </Card>

          <Card className="mt-4">
            <CardHeader
              title="Volume over time"
              description="Daily journeys and conversions across the selected window."
            />
            {isLoading ? (
              <div className="text-sm text-slate-500">Loading…</div>
            ) : (
              <TimeSeries data={currentData?.time_series ?? []} />
            )}
          </Card>

          <div className="grid gap-4 lg:grid-cols-2 mt-4">
            <ByCampaignCard data={currentData} loading={isLoading} />
            <ByChannelCard data={currentData} loading={isLoading} />
          </div>

          <div className="grid gap-4 lg:grid-cols-2 mt-4">
            <DevicesCard data={currentData} loading={isLoading} />
            <RegionsCard data={currentData} loading={isLoading} />
          </div>

          <Card className="mt-4">
            <CardHeader
              title="Campaign outcomes"
              description="Converted vs not-converted per campaign. Click a segment to see those journeys."
            />
            {isLoading ? (
              <div className="text-sm text-slate-500">Loading…</div>
            ) : (
              <CampaignOutcomeBars
                data={outcomeData}
                onSegmentClick={({ campaign, outcome }) => goToJourneys(campaign, outcome)}
                onCampaignClick={(campaign) => goToJourneys(campaign)}
              />
            )}
          </Card>

          {/* Journeys section — inline list scoped to the page's date range
              + (when filtered to one) the selected campaign. The top-nav
              Journeys link scrolls here via ?focus=journeys. */}
          <div ref={journeysSectionRef} id="journeys-section">
            <InlineJourneysCard
              from={range.from}
              to={range.to}
              campaign={isFiltered ? selectedCampaigns[0] : undefined}
              onlyEnrollClicks={showOnlyEnrollClicks}
              onToggleEnrollClicks={() => setShowOnlyEnrollClicks((v) => !v)}
              page={journeysPage}
              onPage={setJourneysPage}
              totalInWindow={currentData?.total_journeys}
              conversions={currentData?.conversions}
            />
          </div>

          {/* Videos section — drill-in to per-video analytics */}
          <Card className="mt-4">
            <CardHeader
              title="Videos"
              description="Plays, watch time, and completion rates across every video on this site. Click a row for the full timeline + funnel."
              action={
                <a
                  href="#/analytics/videos"
                  className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium bg-primary-600 rounded-lg hover:bg-primary-700"
                  style={{ color: '#ffffff' }}
                >
                  Open video analytics →
                </a>
              }
            />
            <p className="text-sm text-slate-500">
              Manage + register videos under{' '}
              <a href="#/videos" className="text-indigo-600 hover:underline">
                Videos
              </a>
              . The analytics live here.
            </p>
          </Card>

          {/* GTM Coverage — where the paired GTM containers are firing */}
          <Card className="mt-4">
            <CardHeader
              title="GTM Coverage"
              description="Every page where the GTM containers paired with this site are firing their Hub beacon. Deterministic proof of where each container is actually deployed."
              action={
                <a
                  href="#/analytics/gtm-coverage"
                  className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium bg-primary-600 rounded-lg hover:bg-primary-700"
                  style={{ color: '#ffffff' }}
                >
                  Open GTM coverage →
                </a>
              }
            />
            <p className="text-sm text-slate-500">
              Pair containers in Hub → Sites → this site → Tracked GTM
              Containers. Then install the beacon tag inside each container
              and publish. Captures land within a minute.
            </p>
          </Card>
        </>
      )}

      {isCompare && (
        <CompareView
          campaigns={selectedCampaigns}
          queries={perCampaignQueries}
        />
      )}
    </Layout>
  );
}

function CampaignFilter({
  all,
  selected,
  onToggle,
  onClear,
}: {
  all: string[];
  selected: string[];
  onToggle: (c: string) => void;
  onClear: () => void;
}) {
  const [open, setOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  // Close on Esc + click outside.
  useEffect(() => {
    if (!open) return;
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    const onPointerDown = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener('keydown', onKeyDown);
    document.addEventListener('mousedown', onPointerDown);
    return () => {
      document.removeEventListener('keydown', onKeyDown);
      document.removeEventListener('mousedown', onPointerDown);
    };
  }, [open]);

  return (
    <div className="mb-4 flex flex-wrap items-center gap-2">
      <div className="relative" ref={containerRef}>
        <button
          type="button"
          onClick={() => setOpen((o) => !o)}
          className="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium rounded border border-slate-300 bg-white hover:bg-slate-50"
          aria-haspopup="listbox"
          aria-expanded={open}
        >
          {selected.length === 0
            ? 'All campaigns'
            : selected.length === 1
            ? `Campaign: ${selected[0]}`
            : `Comparing ${selected.length} campaigns`}
          <ChevronDown className="w-4 h-4 text-slate-400" />
        </button>
        {open && (
          <div
            className="absolute z-10 mt-1 min-w-[18rem] max-h-80 overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg p-2"
            role="listbox"
            aria-multiselectable="true"
          >
            {all.length === 0 ? (
              <div className="px-2 py-2 text-sm text-slate-500">No campaigns yet.</div>
            ) : (
              all.map((c) => {
                const isSel = selected.includes(c);
                return (
                  <label
                    key={c}
                    className="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-slate-50 cursor-pointer text-sm"
                  >
                    <input
                      type="checkbox"
                      checked={isSel}
                      onChange={() => onToggle(c)}
                      className="rounded border-slate-300"
                    />
                    <span className="text-slate-800 truncate">{c}</span>
                  </label>
                );
              })
            )}
          </div>
        )}
      </div>

      {selected.map((c) => (
        <span
          key={c}
          className="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded bg-primary-50 text-primary-700"
        >
          {c}
          <button
            type="button"
            onClick={() => onToggle(c)}
            className="text-primary-500 hover:text-primary-700"
            aria-label={`Remove ${c}`}
          >
            <X className="w-3 h-3" />
          </button>
        </span>
      ))}

      {selected.length > 0 && (
        <button
          type="button"
          onClick={onClear}
          className="text-xs text-slate-500 hover:text-slate-700 underline-offset-2 hover:underline"
        >
          Clear
        </button>
      )}

      <span className="ml-auto text-xs text-slate-500">
        Pick one campaign to filter, two or more to compare.
      </span>
    </div>
  );
}

function CompareView({
  campaigns,
  queries,
}: {
  campaigns: string[];
  queries: Array<{ data?: JourneyStats; isLoading: boolean; error: unknown }>;
}) {
  const cols = Math.min(campaigns.length, 4);
  const colClass =
    cols === 2
      ? 'grid-cols-1 md:grid-cols-2'
      : cols === 3
      ? 'grid-cols-1 md:grid-cols-3'
      : 'grid-cols-1 md:grid-cols-2 lg:grid-cols-4';

  return (
    <Card>
      <CardHeader
        title="Compare campaigns"
        description="Side-by-side funnels and KPIs at the same scale."
      />
      <div className={`grid ${colClass} gap-4`}>
        {campaigns.map((name, i) => {
          const q = queries[i];
          const data = q?.data;
          const stages = data?.funnel ?? [];
          return (
            <div
              key={name}
              className="border border-slate-100 rounded-lg p-3 bg-slate-50/40"
            >
              <div className="text-sm font-semibold text-slate-900 mb-1 truncate">
                {name}
              </div>
              <div className="grid grid-cols-3 gap-2 text-xs text-slate-600 mb-2">
                <div>
                  <div className="text-slate-500">Journeys</div>
                  <div className="font-medium tabular-nums">
                    {data?.total_journeys?.toLocaleString() ?? '—'}
                  </div>
                </div>
                <div>
                  <div className="text-slate-500">Enrollments</div>
                  <div className="font-medium tabular-nums">
                    {data?.conversions?.toLocaleString() ?? '—'}
                  </div>
                </div>
                <div>
                  <div className="text-slate-500">Conv. rate</div>
                  <div className="font-medium tabular-nums">
                    {data?.conversion_rate != null
                      ? `${data.conversion_rate.toFixed(1)}%`
                      : '—'}
                  </div>
                </div>
              </div>
              <Funnel stages={stages} compact />
            </div>
          );
        })}
      </div>
    </Card>
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
              <tr key={row.utm_campaign} className="hover:bg-slate-50">
                <td className="py-2 text-slate-900">
                  <Link
                    to={`/analytics/campaign/${encodeURIComponent(row.utm_campaign)}`}
                    className="hover:underline text-indigo-600"
                  >
                    {row.utm_campaign}
                  </Link>
                </td>
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

const DEVICE_COLORS: Record<string, string> = {
  desktop: '#6366f1',
  mobile: '#10b981',
  tablet: '#f59e0b',
};

function DevicesCard({ data, loading }: { data: JourneyStats | undefined; loading: boolean }) {
  const slices = (data?.devices ?? []).map((d) => ({
    label: d.device_type,
    count: d.count,
    color: DEVICE_COLORS[d.device_type] ?? '#94a3b8',
  }));

  return (
    <Card>
      <CardHeader title="Devices" description="What did people view on?" />
      {loading ? (
        <div className="text-sm text-slate-500">Loading…</div>
      ) : slices.length === 0 ? (
        <div className="text-sm text-slate-500">No device data yet.</div>
      ) : (
        <Donut slices={slices} />
      )}
    </Card>
  );
}

function RegionsCard({ data, loading }: { data: JourneyStats | undefined; loading: boolean }) {
  const rows = data?.regions ?? [];
  const total = rows.reduce((s, r) => s + r.count, 0);

  return (
    <Card>
      <CardHeader title="Top regions" description="Where journeys originated." />
      {loading ? (
        <div className="text-sm text-slate-500">Loading…</div>
      ) : rows.length === 0 ? (
        <div className="text-sm text-slate-500">No region data yet.</div>
      ) : (
        <ul className="space-y-2">
          {rows.map((r, i) => {
            const pct = total > 0 ? (r.count / total) * 100 : 0;
            const label = r.region ? `${r.region}, ${r.country}` : r.country;
            return (
              <li key={i}>
                <div className="flex items-center justify-between text-sm">
                  <span className="text-slate-700">{label}</span>
                  <span className="text-slate-500 tabular-nums">
                    {r.count.toLocaleString()} · {pct.toFixed(0)}%
                  </span>
                </div>
                <div className="mt-1 h-1.5 rounded bg-slate-100 overflow-hidden">
                  <div className="h-full bg-indigo-500" style={{ width: `${pct}%` }} />
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </Card>
  );
}

function InlineJourneysCard({
  from,
  to,
  campaign,
  onlyEnrollClicks,
  onToggleEnrollClicks,
  page,
  onPage,
  totalInWindow,
  conversions,
}: {
  from: string;
  to: string;
  campaign?: string;
  onlyEnrollClicks: boolean;
  onToggleEnrollClicks: () => void;
  page: number;
  onPage: (n: number) => void;
  totalInWindow?: number;
  conversions?: number;
}) {
  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['analytics-inline-journeys', { from, to, campaign, onlyEnrollClicks, page }],
    queryFn: () =>
      marketingApi.listJourneys({
        start_date: from,
        end_date: to,
        campaign: campaign || undefined,
        event_name: onlyEnrollClicks ? 'click_to_portal' : undefined,
        page,
        per_page: 25,
      }),
  });

  const rows = data?.data ?? [];
  const lastPage = data?.last_page ?? 1;
  const totalMatches = data?.total ?? rows.length;

  return (
    <Card className="mt-4">
      <CardHeader
        title="Journeys"
        description="Per-visitor journeys in the selected window. Click a row for the full event timeline."
        action={
          <button
            type="button"
            onClick={onToggleEnrollClicks}
            className={
              'inline-flex items-center gap-1.5 border rounded-lg text-sm py-1.5 px-3 transition-colors ' +
              (onlyEnrollClicks
                ? 'bg-amber-100 border-amber-300 text-amber-800'
                : 'border-slate-300 text-slate-700 hover:bg-slate-50')
            }
            title="Show only journeys whose visitor clicked the enroll / primary CTA"
          >
            <span aria-hidden="true">⇥</span>
            Clicked enroll
            {onlyEnrollClicks && <span aria-hidden="true">·×</span>}
          </button>
        }
      />
      <p className="text-sm text-slate-500 mb-3">
        Total in this window:{' '}
        <span className="font-semibold text-slate-700">
          {(totalInWindow ?? 0).toLocaleString()}
        </span>
        {conversions !== undefined && (
          <>
            {' '}
            · Converted:{' '}
            <span className="font-semibold text-emerald-600">
              {(conversions ?? 0).toLocaleString()}
            </span>
          </>
        )}
        {onlyEnrollClicks && (
          <>
            {' '}
            · Showing{' '}
            <span className="font-semibold text-amber-700">
              {totalMatches.toLocaleString()}
            </span>{' '}
            with Clicked enroll
          </>
        )}
      </p>

      {isLoading && <div className="p-4 text-sm text-slate-500">Loading journeys…</div>}
      {isError && (
        <div className="p-4 text-sm text-red-600">
          {(error as Error)?.message || 'Failed to load journeys.'}
        </div>
      )}
      {!isLoading && !isError && rows.length === 0 && (
        <div className="p-8 text-center text-sm text-slate-500">
          No journeys match the current filters.
        </div>
      )}
      {!isLoading && !isError && rows.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="text-left text-xs uppercase tracking-wide text-slate-500 border-b border-slate-200">
              <tr>
                <th className="px-3 py-2">Click ID</th>
                <th className="px-3 py-2">Campaign</th>
                <th className="px-3 py-2">Status</th>
                <th className="px-3 py-2">Started</th>
                <th className="px-3 py-2 text-right">Action</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <InlineJourneyRow key={row.click_id} row={row} />
              ))}
            </tbody>
          </table>
        </div>
      )}

      {!isLoading && rows.length > 0 && lastPage > 1 && (
        <div className="flex items-center justify-between p-3 border-t border-slate-200 text-sm">
          <div className="text-slate-500">
            Page {page} of {lastPage} · {totalMatches.toLocaleString()} total
          </div>
          <div className="flex gap-2">
            <button
              type="button"
              disabled={page <= 1}
              onClick={() => onPage(page - 1)}
              className="px-3 py-1 border border-slate-300 rounded disabled:opacity-50"
            >
              ← Prev
            </button>
            <button
              type="button"
              disabled={page >= lastPage}
              onClick={() => onPage(page + 1)}
              className="px-3 py-1 border border-slate-300 rounded disabled:opacity-50"
            >
              Next →
            </button>
          </div>
        </div>
      )}
    </Card>
  );
}

function InlineJourneyRow({ row }: { row: JourneyRow }) {
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
  return (
    <tr className="border-b border-slate-100 hover:bg-slate-50">
      <td className="px-3 py-2 font-mono text-xs">
        <Link
          to={`/analytics/journeys/${row.click_id}`}
          className="hover:underline"
          style={{ color: '#4f46e5' }}
        >
          {row.click_id.slice(0, 12)}…
        </Link>
      </td>
      <td className="px-3 py-2">{row.utm_campaign ?? '—'}</td>
      <td className="px-3 py-2">
        <span className={`inline-block px-2 py-0.5 rounded text-xs font-medium ${statusColor}`}>
          {row.status.replace('_', ' ')}
        </span>
      </td>
      <td className="px-3 py-2 text-slate-600">{startedLabel}</td>
      <td className="px-3 py-2 text-right">
        <Link
          to={`/analytics/journeys/${row.click_id}`}
          className="text-sm hover:underline"
          style={{ color: '#4f46e5' }}
        >
          View Timeline
        </Link>
      </td>
    </tr>
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
