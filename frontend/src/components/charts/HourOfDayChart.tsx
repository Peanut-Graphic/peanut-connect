export interface HourBucket {
  hour: number; // 0..23 (UTC)
  journeys: number;
}

interface Props {
  data: HourBucket[];
}

function label(h: number): string {
  if (h === 0) return '12a';
  if (h === 12) return '12p';
  return h < 12 ? `${h}a` : `${h - 12}p`;
}

// Simple 24-column bar chart of when journeys start. Heights scale to the
// busiest hour; every third hour is labeled to keep the axis readable.
export function HourOfDayChart({ data }: Props) {
  const buckets =
    data.length === 24
      ? data
      : Array.from({ length: 24 }, (_, h) => data.find((d) => d.hour === h) ?? { hour: h, journeys: 0 });

  const peak = Math.max(1, ...buckets.map((b) => b.journeys));
  const totalJourneys = buckets.reduce((s, b) => s + b.journeys, 0);

  if (totalJourneys === 0) {
    return <div className="text-sm text-slate-500">No time-of-day data yet.</div>;
  }

  return (
    <div>
      <div className="flex items-end gap-[2px] h-28" role="img" aria-label="Journeys by hour of day">
        {buckets.map((b) => {
          const pct = (b.journeys / peak) * 100;
          return (
            <div key={b.hour} className="flex-1 flex flex-col justify-end" title={`${label(b.hour)}: ${b.journeys}`}>
              <div
                data-testid={`hour-${b.hour}`}
                className="bg-indigo-500 rounded-t-sm"
                style={{ height: `${b.journeys === 0 ? 0 : Math.max(4, pct)}%` }}
              />
            </div>
          );
        })}
      </div>
      <div className="flex gap-[2px] mt-1">
        {buckets.map((b) => (
          <div key={b.hour} className="flex-1 text-center text-[9px] text-slate-400">
            {b.hour % 3 === 0 ? label(b.hour) : ''}
          </div>
        ))}
      </div>
      <p className="text-[11px] text-slate-400 mt-1">Hour of day (UTC) journeys started.</p>
    </div>
  );
}
