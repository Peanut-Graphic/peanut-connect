interface DonutSlice {
  label: string;
  count: number;
  color: string;
}

interface DonutProps {
  slices: DonutSlice[];
  size?: number;
  centerLabel?: string;
}

/** Simple SVG donut. Each slice rendered as a stroked arc. */
export function Donut({ slices, size = 160, centerLabel }: DonutProps) {
  const total = slices.reduce((s, x) => s + x.count, 0);
  const r = size / 2 - 14;
  const cx = size / 2;
  const cy = size / 2;
  const stroke = 18;

  if (total === 0) {
    return <div className="text-sm text-slate-500">No data.</div>;
  }

  let cumulative = 0;
  const arcs = slices.map((s) => {
    const startA = (cumulative / total) * 2 * Math.PI - Math.PI / 2;
    cumulative += s.count;
    const endA = (cumulative / total) * 2 * Math.PI - Math.PI / 2;
    const x1 = cx + r * Math.cos(startA);
    const y1 = cy + r * Math.sin(startA);
    const x2 = cx + r * Math.cos(endA);
    const y2 = cy + r * Math.sin(endA);
    const largeArc = endA - startA > Math.PI ? 1 : 0;
    const d = `M ${x1} ${y1} A ${r} ${r} 0 ${largeArc} 1 ${x2} ${y2}`;
    return { d, color: s.color };
  });

  return (
    <div className="flex items-center gap-4">
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} aria-hidden>
        <circle cx={cx} cy={cy} r={r} fill="none" stroke="#f1f5f9" strokeWidth={stroke} />
        {arcs.map((a, i) => (
          <path
            key={i}
            d={a.d}
            fill="none"
            stroke={a.color}
            strokeWidth={stroke}
            strokeLinecap="butt"
          />
        ))}
        {centerLabel && (
          <text
            x={cx}
            y={cy}
            textAnchor="middle"
            dominantBaseline="middle"
            className="fill-slate-700 text-xs font-medium"
          >
            {centerLabel}
          </text>
        )}
      </svg>
      <ul className="space-y-1.5 text-sm flex-1">
        {slices.map((s) => {
          const pct = total > 0 ? Math.round((s.count / total) * 100) : 0;
          return (
            <li key={s.label} className="flex items-center justify-between gap-3">
              <span className="inline-flex items-center gap-1.5">
                <span
                  className="inline-block w-3 h-3 rounded-sm"
                  style={{ backgroundColor: s.color }}
                />
                <span className="text-slate-700 capitalize">{s.label}</span>
              </span>
              <span className="text-slate-500 tabular-nums text-xs">
                {s.count.toLocaleString()} · {pct}%
              </span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
