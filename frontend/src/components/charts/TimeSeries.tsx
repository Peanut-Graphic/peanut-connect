import { useMemo } from 'react';

interface TimeSeriesPoint {
  date: string;
  journeys: number;
  conversions: number;
}

interface TimeSeriesProps {
  data: TimeSeriesPoint[];
  height?: number;
}

/**
 * Two-line area chart for journey + conversion volume over time.
 * Hand-rolled SVG so no chart-lib bloat. Y-axis auto-scales to max value.
 */
export function TimeSeries({ data, height = 220 }: TimeSeriesProps) {
  const { width, padding, journeyPath, conversionPath, journeyArea, ticks, max } = useMemo(() => {
    const w = 800;
    const pad = { top: 10, right: 12, bottom: 28, left: 36 };
    const innerW = w - pad.left - pad.right;
    const innerH = height - pad.top - pad.bottom;

    if (!data.length) {
      return {
        width: w,
        padding: pad,
        journeyPath: '',
        conversionPath: '',
        journeyArea: '',
        ticks: [] as Array<{ x: number; label: string }>,
        max: 1,
      };
    }

    const m = Math.max(1, ...data.map((d) => Math.max(d.journeys, d.conversions)));
    const x = (i: number) =>
      pad.left + (data.length === 1 ? innerW / 2 : (i / (data.length - 1)) * innerW);
    const y = (v: number) => pad.top + innerH - (v / m) * innerH;

    const line = (key: 'journeys' | 'conversions') =>
      data.map((d, i) => `${i === 0 ? 'M' : 'L'} ${x(i)} ${y(d[key])}`).join(' ');

    const area =
      `M ${x(0)} ${pad.top + innerH} ` +
      data.map((d, i) => `L ${x(i)} ${y(d.journeys)}`).join(' ') +
      ` L ${x(data.length - 1)} ${pad.top + innerH} Z`;

    // Pick ~6 evenly-spaced x-axis tick positions and short labels.
    const tickCount = Math.min(6, data.length);
    const stride = Math.max(1, Math.floor((data.length - 1) / Math.max(1, tickCount - 1)));
    const tickList: Array<{ x: number; label: string }> = [];
    for (let i = 0; i < data.length; i += stride) {
      const dt = new Date(data[i].date + 'T00:00:00');
      tickList.push({
        x: x(i),
        label: dt.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }),
      });
    }

    return {
      width: w,
      padding: pad,
      journeyPath: line('journeys'),
      conversionPath: line('conversions'),
      journeyArea: area,
      ticks: tickList,
      max: m,
    };
  }, [data, height]);

  if (!data.length) {
    return <div className="text-sm text-slate-500">No time-series data.</div>;
  }

  const innerH = height - padding.top - padding.bottom;
  const yLabels = [0, 0.5, 1].map((f) => ({
    y: padding.top + innerH - f * innerH,
    label: Math.round(f * max).toLocaleString(),
  }));

  return (
    <div className="w-full">
      <div className="flex items-center gap-4 text-xs mb-2">
        <span className="inline-flex items-center gap-1.5">
          <span className="inline-block w-3 h-3 rounded-sm bg-indigo-500/60" />
          <span className="text-slate-600">Journeys</span>
        </span>
        <span className="inline-flex items-center gap-1.5">
          <span className="inline-block w-3 h-3 rounded-sm bg-emerald-500" />
          <span className="text-slate-600">Conversions</span>
        </span>
      </div>
      <svg
        viewBox={`0 0 ${width} ${height}`}
        preserveAspectRatio="xMidYMid meet"
        className="w-full"
        role="img"
        aria-label="Journeys and conversions over time"
      >
        {/* Y-axis gridlines + labels */}
        {yLabels.map((l, i) => (
          <g key={i}>
            <line
              x1={padding.left}
              x2={width - padding.right}
              y1={l.y}
              y2={l.y}
              stroke="#e2e8f0"
              strokeDasharray="2 3"
            />
            <text
              x={padding.left - 6}
              y={l.y}
              textAnchor="end"
              dominantBaseline="middle"
              className="fill-slate-400 text-[10px] tabular-nums"
            >
              {l.label}
            </text>
          </g>
        ))}

        {/* X-axis ticks */}
        {ticks.map((t, i) => (
          <text
            key={i}
            x={t.x}
            y={height - 8}
            textAnchor="middle"
            className="fill-slate-500 text-[10px]"
          >
            {t.label}
          </text>
        ))}

        {/* Journeys: area + line */}
        <path d={journeyArea} fill="#6366f1" fillOpacity="0.15" />
        <path d={journeyPath} fill="none" stroke="#6366f1" strokeWidth="2" />

        {/* Conversions: line + dots */}
        <path d={conversionPath} fill="none" stroke="#10b981" strokeWidth="2" />
      </svg>
    </div>
  );
}
