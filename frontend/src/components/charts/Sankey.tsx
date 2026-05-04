import { useMemo } from 'react';

export interface SankeyNode {
  id: number;
  name: string;
  category: string;
}

export interface SankeyLink {
  source: number;
  target: number;
  value: number;
}

interface SankeyProps {
  nodes: SankeyNode[];
  links: SankeyLink[];
  height?: number;
}

const CATEGORY_COLOR: Record<string, string> = {
  campaign: '#6366f1', // indigo
  channel: '#0ea5e9', // sky
  status: '#94a3b8',  // slate (overridden below for converted)
  outcome: '#94a3b8',
};

const STATUS_COLOR: Record<string, string> = {
  converted: '#10b981',  // emerald — success
  in_progress: '#f59e0b', // amber — pending
  abandoned: '#ef4444',  // red — lost
};

function colorFor(node: SankeyNode): string {
  if (node.category === 'status' || node.category === 'outcome') {
    return STATUS_COLOR[node.name] ?? CATEGORY_COLOR[node.category] ?? '#94a3b8';
  }
  return CATEGORY_COLOR[node.category] ?? '#94a3b8';
}

/**
 * Hand-rolled 3-column Sankey. Columns are inferred from node category in
 * left→right order: campaign, channel, status. Each node is a vertical bar
 * sized by its total flow; each link is a curved Bezier ribbon between two
 * node bars, opacity fixed for clarity.
 */
export function Sankey({ nodes, links, height: heightProp }: SankeyProps) {
  // Auto-grow the chart so it fits all nodes without clipping. Each node
  // wants ~36 px of vertical room (label + bar + gap).
  const maxColNodes = Math.max(
    nodes.filter((n) => n.category === 'campaign').length,
    nodes.filter((n) => n.category === 'channel').length,
    nodes.filter((n) => n.category === 'status').length,
    1,
  );
  const height = heightProp ?? Math.max(380, maxColNodes * 60);

  const layout = useMemo(() => buildLayout(nodes, links, height), [nodes, links, height]);

  if (!nodes.length || !links.length) {
    return (
      <div className="text-sm text-slate-500">
        No flow data yet — needs campaigns with traffic to render.
      </div>
    );
  }

  const width = 900;

  return (
    <svg
      viewBox={`0 0 ${width} ${height}`}
      preserveAspectRatio="xMidYMid meet"
      className="w-full"
      role="img"
      aria-label="Campaign → channel → outcome flow"
    >
      {/* Links (curved ribbons) — render first so nodes draw on top */}
      {layout.links.map((l, i) => (
        <path
          key={i}
          d={l.d}
          fill={l.color}
          fillOpacity={0.25}
          stroke={l.color}
          strokeOpacity={0.05}
        />
      ))}

      {/* Node bars + labels */}
      {layout.nodes.map((n) => {
        // Channel column (middle) uses an above-the-bar label so it doesn't
        // overlap the incoming ribbons. Campaigns sit on the left, statuses
        // on the right.
        const isMiddle = n.column === 'channel';
        const labelX = isMiddle ? n.x + n.w / 2 : n.x + (n.labelOnRight ? n.w + 6 : -6);
        const labelY = isMiddle ? n.y - 4 : n.y + n.h / 2;
        const anchor: 'start' | 'middle' | 'end' = isMiddle
          ? 'middle'
          : n.labelOnRight
          ? 'start'
          : 'end';
        const baseline = isMiddle ? 'auto' : 'middle';

        return (
          <g key={n.id}>
            <rect
              x={n.x}
              y={n.y}
              width={n.w}
              height={n.h}
              fill={n.color}
              rx={2}
            />
            <text
              x={labelX}
              y={labelY}
              textAnchor={anchor}
              dominantBaseline={baseline}
              className="fill-slate-700 text-[11px]"
              style={{ paintOrder: 'stroke', stroke: 'white', strokeWidth: 3 }}
            >
              {prettyLabel(n.name)}{' '}
              <tspan className="fill-slate-400 tabular-nums">
                {n.total.toLocaleString()}
              </tspan>
            </text>
          </g>
        );
      })}
    </svg>
  );
}

function prettyLabel(name: string): string {
  if (name === 'in_progress') return 'in progress';
  return name;
}

interface PositionedNode {
  id: number;
  x: number;
  y: number;
  w: number;
  h: number;
  total: number;
  color: string;
  labelOnRight: boolean;
  name: string;
  column: 'campaign' | 'channel' | 'status';
}

interface PositionedLink {
  d: string;
  color: string;
}

function buildLayout(
  nodes: SankeyNode[],
  links: SankeyLink[],
  height: number,
): { nodes: PositionedNode[]; links: PositionedLink[] } {
  const width = 900;
  const padX = 130; // room for left/right labels
  const padY = 12;
  const innerW = width - padX * 2;
  const nodeWidth = 14;
  const colGap = 6;

  // Compute each column's gap-space so we can subtract it from the available
  // height before sizing bars. Without this, columns with many small nodes
  // overflow the SVG and the bottom row gets clipped.
  const order = ['campaign', 'channel', 'status'] as const;
  const columnsPre: Record<string, SankeyNode[]> = {
    campaign: [],
    channel: [],
    status: [],
  };
  nodes.forEach((n) => {
    if (columnsPre[n.category]) columnsPre[n.category].push(n);
  });
  const maxNodesInCol = Math.max(
    columnsPre.campaign.length,
    columnsPre.channel.length,
    columnsPre.status.length,
    1,
  );
  const innerH = height - padY * 2 - (maxNodesInCol - 1) * colGap;

  const columns = columnsPre;

  // Total flow per node — sum of incoming or outgoing values, whichever is larger
  const totalByNode = new Map<number, number>();
  links.forEach((l) => {
    totalByNode.set(l.source, (totalByNode.get(l.source) ?? 0) + l.value);
    totalByNode.set(l.target, (totalByNode.get(l.target) ?? 0) + l.value);
  });

  // Per-column totals to size each bar
  const colSum: Record<string, number> = { campaign: 0, channel: 0, status: 0 };
  order.forEach((cat) => {
    columns[cat].forEach((n) => {
      const t = totalByNode.get(n.id) ?? 0;
      // For middle columns each value is counted twice (in + out). Use half.
      const contribution = cat === 'channel' ? t / 2 : t;
      colSum[cat] += contribution;
    });
  });

  const positions = new Map<number, PositionedNode>();
  const nodeXBy: Record<string, number> = {
    campaign: padX,
    channel: padX + innerW / 2 - nodeWidth / 2,
    status: padX + innerW - nodeWidth,
  };

  // For each column: compute heights proportional to traffic, stack vertically,
  // sorted descending so the biggest node is at the top.
  order.forEach((cat) => {
    const sum = colSum[cat] || 1;
    const sorted = [...columns[cat]].sort(
      (a, b) => (totalByNode.get(b.id) ?? 0) - (totalByNode.get(a.id) ?? 0),
    );
    let y = padY;
    sorted.forEach((n) => {
      const total = totalByNode.get(n.id) ?? 0;
      const contribution = cat === 'channel' ? total / 2 : total;
      const h = Math.max(8, (contribution / sum) * innerH);
      positions.set(n.id, {
        id: n.id,
        x: nodeXBy[cat],
        y,
        w: nodeWidth,
        h,
        total: cat === 'channel' ? Math.round(total / 2) : total,
        color: colorFor(n),
        labelOnRight: cat === 'status',
        name: n.name,
        column: cat,
      });
      y += h + colGap;
    });
  });

  // For each node, track how much "in" / "out" stack space we've used so we
  // can attach links to the right vertical offset.
  const inOffset = new Map<number, number>();
  const outOffset = new Map<number, number>();

  // Sort links so larger flows are drawn at the top of each node — looks
  // cleaner and reads better.
  const sortedLinks = [...links].sort((a, b) => b.value - a.value);

  const positionedLinks: PositionedLink[] = sortedLinks
    .map((l): PositionedLink | null => {
      const src = positions.get(l.source);
      const tgt = positions.get(l.target);
      if (!src || !tgt) return null;

      const sNode = nodes[l.source];
      const tNode = nodes[l.target];
      if (!sNode || !tNode) return null;

      const sTotal = totalByNode.get(l.source) ?? 1;
      const tTotalRaw = totalByNode.get(l.target) ?? 1;
      // For middle column nodes the displayed height is half the link sum.
      const sHeightUnit = sNode.category === 'channel' ? src.h / (sTotal / 2) : src.h / sTotal;
      const tHeightUnit = tNode.category === 'channel' ? tgt.h / (tTotalRaw / 2) : tgt.h / tTotalRaw;

      const linkH_src = l.value * sHeightUnit;
      const linkH_tgt = l.value * tHeightUnit;

      const sOff = outOffset.get(l.source) ?? 0;
      const tOff = inOffset.get(l.target) ?? 0;
      outOffset.set(l.source, sOff + linkH_src);
      inOffset.set(l.target, tOff + linkH_tgt);

      const x0 = src.x + src.w;
      const y0a = src.y + sOff;
      const y0b = y0a + linkH_src;
      const x1 = tgt.x;
      const y1a = tgt.y + tOff;
      const y1b = y1a + linkH_tgt;

      const cx = (x0 + x1) / 2;
      const d =
        `M ${x0} ${y0a} ` +
        `C ${cx} ${y0a}, ${cx} ${y1a}, ${x1} ${y1a} ` +
        `L ${x1} ${y1b} ` +
        `C ${cx} ${y1b}, ${cx} ${y0b}, ${x0} ${y0b} Z`;

      // Link colored by destination — outcome ribbons turn green/red/amber,
      // mid-flow ribbons take the channel color.
      const color = colorFor(tNode);

      return { d, color };
    })
    .filter((x): x is PositionedLink => x !== null);

  return {
    nodes: Array.from(positions.values()),
    links: positionedLinks,
  };
}
