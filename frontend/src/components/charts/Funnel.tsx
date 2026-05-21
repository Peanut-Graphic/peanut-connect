import type { FunnelStage } from '@/api';

interface FunnelProps {
  stages: FunnelStage[];
  /** Compact mode: smaller text, no inline drop-off text. For grid/compare layouts. */
  compact?: boolean;
}

/**
 * Per-stage colors. Distinct hues that read as a story:
 * neutral reach → cool engagement → warm intent → green success.
 */
const STAGE_COLORS: Record<string, { bar: string; chip: string; text: string }> = {
  sent: {
    bar: 'bg-slate-400',
    chip: 'bg-slate-400',
    text: 'text-slate-700',
  },
  clicked: {
    bar: 'bg-sky-500',
    chip: 'bg-sky-500',
    text: 'text-sky-700',
  },
  landed: {
    bar: 'bg-indigo-500',
    chip: 'bg-indigo-500',
    text: 'text-indigo-700',
  },
  engaged: {
    bar: 'bg-amber-500',
    chip: 'bg-amber-500',
    text: 'text-amber-700',
  },
  enrolled: {
    bar: 'bg-emerald-500',
    chip: 'bg-emerald-500',
    text: 'text-emerald-700',
  },
};

const FALLBACK = {
  bar: 'bg-slate-300',
  chip: 'bg-slate-300',
  text: 'text-slate-600',
};

function colorFor(stage: string) {
  return STAGE_COLORS[stage] ?? FALLBACK;
}

export function Funnel({ stages, compact = false }: FunnelProps) {
  if (stages.length === 0) {
    return <div className="text-sm text-slate-500">No funnel data.</div>;
  }

  const top = Math.max(stages[0].count, 1);

  return (
    <div className="w-full">
      {!compact && (
        <ul className="flex flex-wrap items-center gap-x-4 gap-y-1 mb-4 text-xs">
          {stages.map((s) => {
            const c = colorFor(s.stage);
            return (
              <li key={s.stage} className="inline-flex items-center gap-1.5">
                <span className={`inline-block w-3 h-3 rounded-sm ${c.chip}`} />
                <span className="text-slate-600">{s.label}</span>
              </li>
            );
          })}
        </ul>
      )}

      <ol className="space-y-3">
        {stages.map((s, i) => {
          const c = colorFor(s.stage);
          // Floor at 2% so a non-zero-but-tiny count still renders a visible
          // sliver of color. Zero stays zero — a stage with no data should
          // show only the empty track, no colored bar.
          const widthPct = s.count === 0 ? 0 : Math.max(2, (s.count / top) * 100);
          const prev = i === 0 ? null : stages[i - 1];
          const dropPct =
            prev && prev.count > 0
              ? Math.round(((prev.count - s.count) / prev.count) * 100)
              : null;

          return (
            <li key={s.stage}>
              <div className="flex items-baseline justify-between mb-1">
                <span
                  className={
                    compact
                      ? `text-[11px] font-medium ${c.text}`
                      : `text-sm font-medium ${c.text}`
                  }
                >
                  {s.label}
                </span>
                <span
                  className={
                    compact
                      ? 'text-[11px] tabular-nums text-slate-500'
                      : 'text-sm tabular-nums text-slate-700 font-medium'
                  }
                >
                  {s.count.toLocaleString()}
                  {!compact && dropPct != null && (
                    <span className="ml-2 text-xs text-slate-400">↓ {dropPct}%</span>
                  )}
                </span>
              </div>
              <div
                className={
                  compact
                    ? 'h-2 rounded bg-slate-100 overflow-hidden'
                    : 'h-4 rounded bg-slate-100 overflow-hidden'
                }
              >
                <div
                  className={`h-full ${c.bar} transition-all duration-500`}
                  style={{ width: `${widthPct}%` }}
                />
              </div>
            </li>
          );
        })}
      </ol>
    </div>
  );
}
