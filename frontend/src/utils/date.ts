import { formatDistanceToNow } from 'date-fns';

/**
 * Parse a server-provided timestamp into a Date, returning null on any
 * format that JavaScript's Date constructor would reject.
 *
 * Hub sends ISO-8601 ("2026-05-11T12:00:00Z") but legacy or third-party
 * paths may send MySQL-style ("2026-05-11 12:00:00") or empty strings.
 * Naively coercing to Date(...) throws on invalid input when later
 * passed to formatDistanceToNow.
 */
export function safeParseTimestamp(value: string | null | undefined): Date | null {
  if (!value || typeof value !== 'string') return null;
  // Append Z if no timezone offset is present so the value is treated as UTC.
  const hasTz = /[zZ]$|[+-]\d{2}:?\d{2}$/.test(value);
  const normalized = hasTz ? value : value.replace(' ', 'T') + 'Z';
  const d = new Date(normalized);
  return Number.isFinite(d.getTime()) ? d : null;
}

/**
 * Format a timestamp as a relative-time string ("3 minutes ago"), or
 * return a fallback when the input can't be parsed.
 */
export function formatRelative(value: string | null | undefined, fallback = 'Never'): string {
  const d = safeParseTimestamp(value);
  return d ? formatDistanceToNow(d, { addSuffix: true }) : fallback;
}
