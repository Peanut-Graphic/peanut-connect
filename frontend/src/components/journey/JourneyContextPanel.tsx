import type { JourneyEventRow } from '@/api';
import { Card } from '@/components/common';

interface Props {
  events: JourneyEventRow[];
  journey: { pages_viewed: number | null; duration_seconds: number | null };
}

function firstDefined<T>(
  events: JourneyEventRow[],
  pick: (e: JourneyEventRow) => T | null | undefined,
): T | undefined {
  for (const e of events) {
    const v = pick(e);
    if (v != null && v !== '') return v as T;
  }
  return undefined;
}

function hostOf(url: string | undefined): string | undefined {
  if (!url) return undefined;
  try {
    return new URL(url).host.replace(/^www\./, '');
  } catch {
    return url;
  }
}

function fmtDuration(sec: number | null): string {
  if (!sec || sec < 1) return '—';
  const m = Math.floor(sec / 60);
  const s = sec % 60;
  return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

function cap(s: string | undefined): string | undefined {
  return s ? s.charAt(0).toUpperCase() + s.slice(1) : undefined;
}

export function JourneyContextPanel({ events, journey }: Props) {
  const device = cap(firstDefined(events, (e) => e.device_type));
  const browser = firstDefined(events, (e) => e.browser);
  const os = firstDefined(events, (e) => e.os);
  const country = firstDefined(events, (e) => e.country);
  const region = firstDefined(events, (e) => e.region);
  const referrer = hostOf(firstDefined(events, (e) => e.referrer ?? undefined));

  const maxScroll = events.reduce((max, e) => {
    // depth arrives as a number OR a numeric string (the sync path stores it as a
    // string, e.g. {"depth":"25"}), so coerce before comparing — otherwise a real
    // scroll reading renders "—".
    const raw = (e.event_data as { depth?: number | string } | null | undefined)?.depth;
    const d = typeof raw === 'string' ? Number(raw) : raw;
    return typeof d === 'number' && Number.isFinite(d) && d > max ? d : max;
  }, 0);
  const exitIntent = events.some(
    (e) => e.event_name === 'exit_intent' || e.event_type === 'exit_intent',
  );

  const deviceStr = [device, browser, os].filter(Boolean).join(' · ') || '—';
  const geoStr = [country, region].filter(Boolean).join(' · ') || '—';

  const Row = ({ label, value }: { label: string; value: string }) => (
    <div className="flex justify-between text-sm py-1 border-b border-slate-50 last:border-0">
      <span className="text-slate-500">{label}</span>
      <span className="text-slate-800 text-right">{value}</span>
    </div>
  );

  return (
    <Card className="p-4">
      <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Context</h3>
      <Row label="Device" value={deviceStr} />
      <Row label="Location" value={geoStr} />
      <Row label="Referrer" value={referrer ?? '—'} />
      <Row label="Pages viewed" value={journey.pages_viewed != null ? String(journey.pages_viewed) : '—'} />
      <Row label="Time on site" value={fmtDuration(journey.duration_seconds)} />
      <Row label="Max scroll" value={maxScroll > 0 ? `${maxScroll}%` : '—'} />
      <Row label="Exit intent" value={exitIntent ? 'Yes — showed exit intent' : 'No'} />
    </Card>
  );
}
