import { useMemo } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { marketingApi } from '@/api';
import Layout from '@/components/layout/Layout';
import { Card } from '@/components/common';

const PORTAL_STEPS = ['validation', 'login', 'dashboard', 'enrolled'] as const;

/** Human label for a timeline node, derived from the event's URL hash. */
function stepLabel(pageUrl: string | null, index: number): string {
  const url = pageUrl ?? '';
  const hashIdx = url.indexOf('#');
  if (hashIdx >= 0) {
    const hash = url.slice(hashIdx + 1).toLowerCase();
    const known = PORTAL_STEPS.find((s) => hash.startsWith(s));
    if (known) return `#${known}`;
    return `#${hash.split(/[/?&]/)[0]}`;
  }
  return index === 0 ? 'Landed' : 'Entered portal';
}

function fmtDelta(seconds: number): string {
  if (seconds <= 0) return '';
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return m > 0 ? `+${m}m ${s}s` : `+${s}s`;
}

function fmtDuration(seconds: number | null): string {
  if (!seconds || seconds < 1) return '—';
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

export default function DominionJourneyTimeline() {
  const { clickId = '' } = useParams<{ clickId: string }>();

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['dominion-journey-timeline', clickId],
    queryFn: () => marketingApi.journeyDetail(clickId),
    enabled: clickId.length > 0,
  });

  const nodes = useMemo(() => {
    const events = data?.events ?? [];
    return events.map((e, i) => {
      const prev = i === 0 ? null : events[i - 1];
      const deltaSec =
        prev != null
          ? Math.round(
              (new Date(e.event_at).getTime() - new Date(prev.event_at).getTime()) / 1000,
            )
          : 0;
      return {
        id: e.id,
        label: stepLabel(e.page_url, i),
        at: e.event_at,
        delta: fmtDelta(deltaSec),
        url: e.page_url ?? '',
      };
    });
  }, [data]);

  const converted = data?.journey.status === 'converted';

  return (
    <Layout
      title="Journey timeline"
      description="Step-by-step path for a single Dominion journey — where they went, how long each step took, and where it ended."
      action={
        <Link
          to="/analytics/dominion-funnel"
          className="text-sm text-slate-500 hover:text-slate-700"
        >
          ← Dominion Funnel
        </Link>
      }
    >
      {isLoading && <Card className="p-6 text-sm text-slate-500">Loading journey…</Card>}
      {isError && (
        <Card className="p-6 text-sm text-red-600">
          Failed to load journey: {(error as Error)?.message}
        </Card>
      )}

      {data && (
        <>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4 text-sm">
            <Stat label="Campaign" value={data.journey.utm_campaign ?? '—'} />
            <Stat label="Source" value={data.journey.utm_source ?? '—'} />
            <Stat label="Status" value={converted ? 'Enrolled' : (data.journey.status ?? '—')} />
            <Stat label="Total time" value={fmtDuration(data.journey.duration_seconds)} />
          </div>

          <Card className="p-6">
            {nodes.length === 0 ? (
              <div className="text-sm text-slate-500">No events recorded for this journey.</div>
            ) : (
              <ol className="relative">
                {nodes.map((n, i) => {
                  const isPortal = n.label.startsWith('#');
                  return (
                    <li
                      key={n.id}
                      className="flex gap-3 items-start pb-5 border-l-2 border-indigo-100 ml-1.5 pl-4 relative"
                    >
                      <span
                        className={
                          'absolute -left-[7px] top-1 w-3 h-3 rounded-full ' +
                          (isPortal ? 'bg-violet-600' : 'bg-indigo-600')
                        }
                      />
                      <div>
                        <div className="text-sm font-semibold text-slate-800">{n.label}</div>
                        <div className="text-xs text-slate-500">
                          {new Date(n.at).toLocaleString()}
                          {i > 0 && n.delta && (
                            <span className="ml-2 text-slate-400">{n.delta}</span>
                          )}
                        </div>
                        <div className="text-[11px] text-slate-400 break-all mt-0.5">{n.url}</div>
                      </div>
                    </li>
                  );
                })}

                <li className="flex gap-3 items-start ml-1.5 pl-4 relative">
                  <span
                    className={
                      'absolute -left-[8px] top-0.5 w-3.5 h-3.5 rounded-full ' +
                      (converted ? 'bg-emerald-600' : 'bg-red-600')
                    }
                  />
                  <div
                    className={
                      'text-sm font-bold ' + (converted ? 'text-emerald-700' : 'text-red-600')
                    }
                  >
                    {converted ? 'Enrolled ✓' : 'Dropped here — never enrolled'}
                    <div className="text-xs font-normal text-slate-400 mt-0.5">
                      last seen{' '}
                      {data.journey.last_event_at
                        ? new Date(data.journey.last_event_at).toLocaleString()
                        : '—'}
                    </div>
                  </div>
                </li>
              </ol>
            )}
          </Card>

          <p className="text-xs text-slate-400 mt-3">
            "Enrolled" means the portal reached its success screen; not IntelliSource-confirmed.
          </p>
        </>
      )}
    </Layout>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="bg-white border border-slate-200 rounded-lg p-3">
      <div className="text-xs text-slate-500">{label}</div>
      <div className="text-sm font-medium text-slate-800 mt-0.5">{value}</div>
    </div>
  );
}
