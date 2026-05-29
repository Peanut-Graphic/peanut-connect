import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { marketingApi, type JourneyEventRow } from '@/api/marketing';
import Layout from '@/components/layout/Layout';
import { Card } from '@/components/common';

/**
 * Per-journey timeline detail. Mirrors Hub's /journeys/{id} inside the
 * plugin SPA — fetches via /marketing/journeys/{click_id} which proxies
 * to Hub. The timeline lists every event in order with its type, name,
 * page_url, and (when present) the metadata payload that classifies it.
 */
export default function JourneyDetail() {
  const { clickId = '' } = useParams<{ clickId: string }>();

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['plugin-journey-detail', clickId],
    queryFn: () => marketingApi.journeyDetail(clickId),
    enabled: clickId.length > 0,
  });

  return (
    <Layout
      title="Journey detail"
      description={clickId ? `Click ID ${clickId}` : 'Click ID —'}
      action={
        <Link
          to="/journeys"
          className="inline-flex items-center px-3 py-1.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50"
        >
          ← Back to journeys
        </Link>
      }
    >
      {isLoading && (
        <Card>
          <div className="p-6 text-sm text-slate-500">Loading journey…</div>
        </Card>
      )}
      {isError && (
        <Card>
          <div className="p-6 text-sm text-red-600">
            {(error as Error)?.message || 'Failed to load journey.'}
          </div>
        </Card>
      )}

      {data && (
        <>
          <Card className="mb-4">
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 p-4 text-sm">
              <Stat label="Status" value={data.journey.status.replace('_', ' ')} />
              <Stat label="Campaign" value={data.journey.utm_campaign ?? '—'} />
              <Stat label="Source" value={data.journey.utm_source ?? '—'} />
              <Stat label="Medium" value={data.journey.utm_medium ?? '—'} />
              <Stat
                label="Started"
                value={new Date(data.journey.started_at).toLocaleString()}
              />
              <Stat
                label="Last event"
                value={
                  data.journey.last_event_at
                    ? new Date(data.journey.last_event_at).toLocaleString()
                    : '—'
                }
              />
              <Stat label="Pages viewed" value={String(data.journey.pages_viewed ?? '—')} />
              <Stat label="Events total" value={String(data.journey.events_count ?? data.events.length)} />
            </div>
          </Card>

          <Card>
            <div className="p-4 border-b border-slate-200">
              <h2 className="text-base font-medium text-slate-900">Event timeline</h2>
              <p className="text-xs text-slate-500 mt-0.5">
                Ordered oldest → newest. Custom events labeled by event_name carry the
                full metadata payload from the tracker (when present).
              </p>
            </div>
            {data.events.length === 0 ? (
              <div className="p-6 text-sm text-slate-500">No events recorded for this journey.</div>
            ) : (
              <ol className="divide-y divide-slate-100">
                {data.events.map((event, i) => (
                  <EventRow key={event.id} event={event} index={i + 1} />
                ))}
              </ol>
            )}
          </Card>
        </>
      )}
    </Layout>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <div className="text-xs uppercase tracking-wide text-slate-500">{label}</div>
      <div className="text-sm font-medium text-slate-900 mt-0.5 truncate">{value}</div>
    </div>
  );
}

function EventRow({ event, index }: { event: JourneyEventRow; index: number }) {
  const ts = new Date(event.event_at).toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    second: '2-digit',
  });

  const typeColor =
    event.event_type === 'pageview'
      ? 'bg-slate-100 text-slate-700'
      : event.event_type === 'conversion'
        ? 'bg-emerald-100 text-emerald-800'
        : event.event_name === 'click_to_portal'
          ? 'bg-amber-100 text-amber-800'
          : 'bg-indigo-100 text-indigo-800';

  return (
    <li className="px-4 py-3 grid grid-cols-12 gap-3 items-start text-sm">
      <div className="col-span-1 text-slate-400 text-xs font-mono pt-0.5">#{index}</div>
      <div className="col-span-2 text-slate-500 text-xs whitespace-nowrap pt-0.5">{ts}</div>
      <div className="col-span-2">
        <span className={`inline-block px-2 py-0.5 rounded text-xs font-medium ${typeColor}`}>
          {event.event_name && event.event_name !== 'custom'
            ? event.event_name
            : event.event_type}
        </span>
      </div>
      <div className="col-span-7">
        {event.page_url && (
          <div className="text-slate-700 truncate" title={event.page_url}>
            {event.page_url}
          </div>
        )}
        {event.event_data && Object.keys(event.event_data).length > 0 && (
          <pre className="mt-1 text-[11px] text-slate-500 bg-slate-50 rounded px-2 py-1 overflow-x-auto whitespace-pre-wrap break-words">
            {JSON.stringify(event.event_data, null, 0)}
          </pre>
        )}
      </div>
    </li>
  );
}
