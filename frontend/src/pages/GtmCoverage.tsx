import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { marketingApi, type GtmCapture, type GtmHostRow } from '@/api';
import Layout from '@/components/layout/Layout';
import { Card } from '@/components/common';

/**
 * Plugin-side GTM Container Coverage page — mirrors Hub's /analytics/gtm-beacon
 * but scoped to this site's paired containers (configured in Hub → Sites →
 * Tracked GTM Containers).
 *
 * Source of truth: /marketing/gtm-coverage plugin proxy → Hub
 * /api/v1/marketing/gtm-coverage (ValidateSiteApiKey middleware). If the
 * site has no containers paired, the response carries an explanatory
 * `message` and an empty data set — we render that as the empty state.
 */
export default function GtmCoverage() {
  const [params, setParams] = useSearchParams();
  const [searchDraft, setSearchDraft] = useState(params.get('search') ?? '');

  const search = params.get('search') ?? '';
  const host = params.get('host') ?? '';
  const validOnly = params.get('valid_only') !== '0';
  const page = Number(params.get('page') ?? '1');

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['plugin-gtm-coverage', { search, host, validOnly, page }],
    queryFn: () =>
      marketingApi.gtmCoverage({
        search: search || undefined,
        host: host || undefined,
        valid_only: validOnly,
        page,
        per_page: 50,
      }),
  });

  const setFilter = (key: string, value: string | boolean) => {
    const next = new URLSearchParams(params);
    if (value === '' || value === false) {
      next.delete(key);
    } else {
      next.set(key, String(value));
    }
    if (key !== 'page') next.delete('page');
    setParams(next, { replace: false });
  };

  const clearAll = () => setParams(new URLSearchParams(), { replace: false });

  const onSearch = (e: React.FormEvent) => {
    e.preventDefault();
    setFilter('search', searchDraft.trim());
  };

  const captures = data?.captures ?? [];
  const hosts = data?.hosts ?? [];
  const totals = data?.totals ?? {
    total_captures: 0,
    unique_urls: 0,
    unique_hosts: 0,
    spoofed: 0,
  };
  const containers = data?.containers ?? [];
  const meta = data?.meta ?? { current_page: 1, last_page: 1 };
  const noContainers = !isLoading && !isError && containers.length === 0;

  return (
    <Layout
      title="GTM Coverage"
      description="Every page where a paired GTM container fires its beacon. Answers 'where is this container actually loading?' deterministically — past ad-blockers, past auth walls, past SPA route changes."
    >
      {/* No containers paired — onboarding state */}
      {noContainers && (
        <Card className="mb-4">
          <div className="p-6">
            <h2 className="text-base font-medium text-slate-900 mb-1">
              No GTM containers paired with this site yet
            </h2>
            <p className="text-sm text-slate-600 max-w-2xl">
              {data?.message ??
                'Pair a container in Hub → Sites → this site → Tracked GTM Containers. Then install the Hub beacon Custom HTML tag inside that GTM container (All Pages + History Change triggers) and publish.'}
            </p>
            <div className="mt-3 text-xs text-slate-500">
              See the setup walkthrough at{' '}
              <code className="font-mono bg-slate-100 px-1 py-0.5 rounded">
                ~/Documents/Peanut-meta/gtm-beacon-setup-walkthrough.html
              </code>
              .
            </div>
          </div>
        </Card>
      )}

      {/* Active state */}
      {!noContainers && (
        <>
          {/* Stat cards */}
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <Stat label="Captures" value={totals.total_captures} />
            <Stat label="Unique URLs" value={totals.unique_urls} />
            <Stat label="Unique hosts" value={totals.unique_hosts} />
            <Stat
              label="Spoofed / unsigned"
              value={totals.spoofed}
              warn={totals.spoofed > 0}
            />
          </div>

          {/* Filter bar */}
          <Card className="mb-4">
            <div className="flex flex-wrap items-center gap-2 p-3">
              <form onSubmit={onSearch} className="flex-1 min-w-[200px]">
                <input
                  type="search"
                  value={searchDraft}
                  onChange={(e) => setSearchDraft(e.target.value)}
                  placeholder="Search URL, title, or referrer…"
                  className="w-full border border-slate-300 rounded-lg text-sm py-2 px-3"
                />
              </form>

              <div className="text-xs text-slate-500 px-2">
                Containers:{' '}
                <span className="font-mono">{containers.join(', ')}</span>
              </div>

              {host && (
                <button
                  type="button"
                  onClick={() => setFilter('host', '')}
                  className="inline-flex items-center gap-1.5 px-3 py-2 text-sm border border-amber-300 rounded-lg bg-amber-50 text-amber-800"
                  title="Click to clear host filter"
                >
                  host: {host} ·×
                </button>
              )}

              <button
                type="button"
                onClick={() => setFilter('valid_only', !validOnly ? '' : '0')}
                className={
                  'inline-flex items-center gap-1.5 border rounded-lg text-sm py-2 px-3 transition-colors ' +
                  (validOnly
                    ? 'bg-emerald-100 border-emerald-300 text-emerald-800'
                    : 'border-slate-300 text-slate-700 hover:bg-slate-50')
                }
                title="Hide hits whose HMAC didn't verify"
              >
                Signed only {validOnly && <span aria-hidden="true">·×</span>}
              </button>

              {(search || host || !validOnly) && (
                <button
                  type="button"
                  onClick={clearAll}
                  className="text-sm text-slate-500 hover:text-slate-700 px-2 py-2"
                >
                  Reset
                </button>
              )}
            </div>
          </Card>

          <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
            {/* Hosts sidebar */}
            <Card>
              <div className="px-4 py-3 border-b border-slate-200">
                <h2 className="text-base font-medium text-slate-900">
                  Hosts loading the container
                </h2>
                <p className="text-xs text-slate-500 mt-0.5">
                  Click a host to filter captures.
                </p>
              </div>
              {hosts.length === 0 ? (
                <div className="p-6 text-sm text-slate-500">No host data yet.</div>
              ) : (
                <ul className="divide-y divide-slate-100">
                  {hosts.map((h) => (
                    <HostRow key={h.host} row={h} onClick={() => setFilter('host', h.host)} />
                  ))}
                </ul>
              )}
            </Card>

            {/* Capture table */}
            <Card className="lg:col-span-2">
              <div className="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
                <h2 className="text-base font-medium text-slate-900">
                  Recent captures
                </h2>
                <span className="text-xs text-slate-500 tabular-nums">
                  {(meta.total ?? 0).toLocaleString()} match
                  {meta.total === 1 ? '' : 'es'}
                </span>
              </div>
              {isLoading && (
                <div className="p-6 text-sm text-slate-500">Loading captures…</div>
              )}
              {isError && (
                <div className="p-6 text-sm text-red-600">
                  {(error as Error)?.message || 'Failed to load captures.'}
                </div>
              )}
              {!isLoading && !isError && captures.length === 0 && (
                <div className="p-12 text-center text-sm text-slate-500">
                  No captures yet for the paired containers.
                </div>
              )}
              {!isLoading && captures.length > 0 && (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead className="text-left text-xs uppercase tracking-wide text-slate-500 border-b border-slate-200">
                      <tr>
                        <th className="px-3 py-2">When</th>
                        <th className="px-3 py-2">Container</th>
                        <th className="px-3 py-2">URL</th>
                        <th className="px-3 py-2">Sig</th>
                      </tr>
                    </thead>
                    <tbody>
                      {captures.map((c) => (
                        <CaptureRow
                          key={c.id}
                          row={c}
                          onFilterHost={(h) => setFilter('host', h)}
                        />
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              {meta.last_page && meta.last_page > 1 && (
                <div className="flex items-center justify-between p-3 border-t border-slate-200 text-sm">
                  <div className="text-slate-500">
                    Page {meta.current_page} of {meta.last_page}
                  </div>
                  <div className="flex gap-2">
                    <button
                      type="button"
                      disabled={(meta.current_page ?? 1) <= 1}
                      onClick={() => setFilter('page', String((meta.current_page ?? 1) - 1))}
                      className="px-3 py-1 border border-slate-300 rounded disabled:opacity-50"
                    >
                      ← Prev
                    </button>
                    <button
                      type="button"
                      disabled={(meta.current_page ?? 1) >= (meta.last_page ?? 1)}
                      onClick={() => setFilter('page', String((meta.current_page ?? 1) + 1))}
                      className="px-3 py-1 border border-slate-300 rounded disabled:opacity-50"
                    >
                      Next →
                    </button>
                  </div>
                </div>
              )}
            </Card>
          </div>
        </>
      )}
    </Layout>
  );
}

function Stat({ label, value, warn }: { label: string; value: number; warn?: boolean }) {
  return (
    <div className="bg-white rounded-lg shadow p-3">
      <div className="text-xs uppercase tracking-wide text-slate-500">{label}</div>
      <div className={'text-2xl font-bold mt-0.5 ' + (warn && value > 0 ? 'text-amber-700' : 'text-slate-900')}>
        {value.toLocaleString()}
      </div>
    </div>
  );
}

function HostRow({ row, onClick }: { row: GtmHostRow; onClick: () => void }) {
  return (
    <li
      className="px-4 py-3 hover:bg-slate-50 cursor-pointer"
      onClick={onClick}
    >
      <div className="flex items-center justify-between">
        <span className="font-mono text-sm text-slate-900 truncate">{row.host}</span>
        <span className="text-xs text-slate-500 tabular-nums">
          {row.load_count.toLocaleString()}
        </span>
      </div>
      <div className="text-xs text-slate-500 mt-0.5">
        {row.unique_urls.toLocaleString()} unique URL
        {row.unique_urls === 1 ? '' : 's'} · last{' '}
        {row.last_seen ? new Date(row.last_seen).toLocaleString() : '—'}
      </div>
    </li>
  );
}

function CaptureRow({
  row,
  onFilterHost,
}: {
  row: GtmCapture;
  onFilterHost: (host: string) => void;
}) {
  const ts = new Date(row.captured_at).toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
  let host: string | null = null;
  if (row.page_url) {
    try {
      host = new URL(row.page_url).host;
    } catch {
      host = null;
    }
  }
  return (
    <tr className="border-b border-slate-100 hover:bg-slate-50">
      <td className="px-3 py-2 text-xs text-slate-500 whitespace-nowrap">{ts}</td>
      <td className="px-3 py-2 font-mono text-xs">{row.container_id}</td>
      <td className="px-3 py-2">
        {row.page_title && (
          <div className="text-xs text-slate-500 truncate max-w-md">{row.page_title}</div>
        )}
        <div
          className="text-slate-900 truncate max-w-md text-xs"
          title={row.page_url ?? ''}
        >
          {row.page_url ?? '—'}
        </div>
        {host && (
          <button
            type="button"
            onClick={() => onFilterHost(host as string)}
            className="text-[11px] text-indigo-600 hover:underline mt-0.5"
          >
            filter by {host}
          </button>
        )}
      </td>
      <td className="px-3 py-2">
        {row.signature_valid ? (
          <span className="inline-block px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800">
            ok
          </span>
        ) : (
          <span className="inline-block px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">
            unsigned
          </span>
        )}
      </td>
    </tr>
  );
}
