export interface CampaignOutcome {
  campaign: string;
  converted: number;
  not_converted: number;
}

export type Outcome = 'converted' | 'not_converted';

interface Props {
  data: CampaignOutcome[];
  onSegmentClick?: (sel: { campaign: string; outcome: Outcome }) => void;
  onCampaignClick?: (campaign: string) => void;
}

const SEGMENTS: { key: Outcome; label: string; color: string }[] = [
  { key: 'converted', label: 'Converted', color: 'bg-emerald-500' },
  { key: 'not_converted', label: 'Not converted', color: 'bg-slate-300' },
];

function total(c: CampaignOutcome): number {
  return c.converted + c.not_converted;
}

export function CampaignOutcomeBars({ data, onSegmentClick, onCampaignClick }: Props) {
  if (data.length === 0) {
    return <div className="text-sm text-slate-500">No campaign data in this window.</div>;
  }
  const rows = [...data].sort((a, b) => total(b) - total(a));

  return (
    <div className="space-y-3">
      <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs">
        {SEGMENTS.map((s) => (
          <li key={s.key} className="inline-flex items-center gap-1.5">
            <span className={`inline-block w-3 h-3 rounded-sm ${s.color}`} />
            <span className="text-slate-600">{s.label}</span>
          </li>
        ))}
      </ul>

      {rows.map((row) => {
        const t = Math.max(total(row), 1);
        return (
          <div key={row.campaign}>
            <button
              type="button"
              data-testid={`outcome-bar-campaign-${row.campaign}`}
              onClick={() => onCampaignClick?.(row.campaign)}
              className="text-xs font-medium text-slate-700 hover:text-indigo-600 mb-1"
            >
              <span data-testid="outcome-bar-campaign">{row.campaign}</span>
            </button>
            <div className="flex h-4 rounded overflow-hidden bg-slate-100">
              {SEGMENTS.map((s) => {
                const val = row[s.key];
                if (val === 0) return null;
                const pct = (val / t) * 100;
                return (
                  <button
                    key={s.key}
                    type="button"
                    data-testid={`seg-${row.campaign}-${s.key}`}
                    title={`${row.campaign} · ${s.label}: ${val}`}
                    aria-label={`${row.campaign} ${s.label} ${val}`}
                    onClick={() => onSegmentClick?.({ campaign: row.campaign, outcome: s.key })}
                    className={`${s.color} h-full`}
                    style={{ width: `${pct}%` }}
                  />
                );
              })}
            </div>
          </div>
        );
      })}
    </div>
  );
}
