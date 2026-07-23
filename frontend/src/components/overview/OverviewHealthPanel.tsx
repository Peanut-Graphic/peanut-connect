import { Link } from 'react-router-dom';
import { Card } from '@/components/common';

interface HealthProps {
  connected: boolean;
  trackingFiring: boolean;
  errorCount: number;
  updatesAvailable: number;
}

function Dot({ ok, warn }: { ok?: boolean; warn?: boolean }) {
  const color = warn ? 'text-amber-500' : ok ? 'text-emerald-500' : 'text-red-500';
  return (
    <span className={color} aria-hidden="true">
      ●
    </span>
  );
}

export function OverviewHealthPanel({
  connected,
  trackingFiring,
  errorCount,
  updatesAvailable,
}: HealthProps) {
  return (
    <Card className="p-4">
      <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-3">Health</h3>
      <ul className="space-y-2 text-sm">
        <li>
          <Link to="/health" className="flex items-center justify-between hover:text-indigo-600">
            <span className="flex items-center gap-2">
              <Dot ok={connected} /> Connection
            </span>
            <span className="text-slate-500">{connected ? 'Connected' : 'Not connected'}</span>
          </Link>
        </li>
        <li>
          <Link
            to="/analytics/gtm-coverage"
            className="flex items-center justify-between hover:text-indigo-600"
          >
            <span className="flex items-center gap-2">
              <Dot ok={trackingFiring} /> Tracking Health
            </span>
            <span className="text-slate-500">{trackingFiring ? 'Firing' : 'No beacons'}</span>
          </Link>
        </li>
        <li>
          <Link to="/errors" className="flex items-center justify-between hover:text-indigo-600">
            <span className="flex items-center gap-2">
              <Dot ok={errorCount === 0} /> Errors
            </span>
            <span className="text-slate-500">{errorCount}</span>
          </Link>
        </li>
        <li>
          <Link to="/updates" className="flex items-center justify-between hover:text-indigo-600">
            <span className="flex items-center gap-2">
              <Dot ok={updatesAvailable === 0} warn={updatesAvailable > 0} /> Updates
            </span>
            <span className="text-slate-500">
              {updatesAvailable > 0 ? `${updatesAvailable} available` : 'Up to date'}
            </span>
          </Link>
        </li>
      </ul>
    </Card>
  );
}
