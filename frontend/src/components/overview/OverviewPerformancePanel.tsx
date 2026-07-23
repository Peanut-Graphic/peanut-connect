import { Link } from 'react-router-dom';
import type { DominionFunnelResponse } from '@/api';
import { Card } from '@/components/common';
import { Funnel } from '@/components/charts/Funnel';

interface PerfProps {
  funnel: DominionFunnelResponse | undefined;
  loading?: boolean;
}

export function OverviewPerformancePanel({ funnel, loading }: PerfProps) {
  return (
    <Card className="p-4">
      <div className="flex items-center justify-between mb-3">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Performance</h3>
        <Link to="/analytics/dominion-funnel" className="text-xs text-indigo-600 hover:text-indigo-800">
          View funnel →
        </Link>
      </div>

      {loading && <div className="text-sm text-slate-500">Loading…</div>}

      {funnel && (
        <>
          <Funnel stages={funnel.reaching} compact />
          <div className="mt-3">
            <div className="text-[11px] uppercase text-slate-400 mb-1">Top campaigns</div>
            <ul className="space-y-1">
              {funnel.campaigns.slice(0, 5).map((c) => (
                <li key={c.campaign} className="flex justify-between text-sm">
                  <Link
                    to={`/analytics/dominion-funnel?campaign=${encodeURIComponent(c.campaign)}`}
                    className="text-slate-700 hover:text-indigo-600"
                  >
                    {c.campaign}
                  </Link>
                  <span className="tabular-nums text-slate-500">{c.landed.toLocaleString()}</span>
                </li>
              ))}
            </ul>
          </div>
        </>
      )}

      {!loading && !funnel && <div className="text-sm text-slate-500">No performance data yet.</div>}
    </Card>
  );
}
