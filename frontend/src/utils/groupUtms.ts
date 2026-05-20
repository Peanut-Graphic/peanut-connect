import type { Utm } from '@/api/marketing';

export interface UtmGroup {
  /** The group key (label or campaign), or null for the "ungrouped" bucket. */
  label: string | null;
  utms: Utm[];
}

export type GroupKeyOf = (utm: Utm) => string;

const defaultKey: GroupKeyOf = (utm) =>
  typeof utm.group_label === 'string' ? utm.group_label.trim() : '';

// Group UTMs into collapsible buckets. Defaults to `group_label` (legacy);
// pass a custom keyOf to group by, e.g., `utm_campaign`.
// - Empty/whitespace keys are treated as ungrouped (label null).
// - Labelled groups appear in order of first appearance.
// - The ungrouped bucket (if any) always comes last.
// - Row order within a group is preserved.
export function groupUtms(utms: Utm[], keyOf: GroupKeyOf = defaultKey): UtmGroup[] {
  const labelled = new Map<string, Utm[]>();
  const ungrouped: Utm[] = [];

  for (const utm of utms) {
    const label = (keyOf(utm) ?? '').trim();
    if (label === '') {
      ungrouped.push(utm);
      continue;
    }
    const bucket = labelled.get(label);
    if (bucket) {
      bucket.push(utm);
    } else {
      labelled.set(label, [utm]);
    }
  }

  const groups: UtmGroup[] = Array.from(labelled, ([label, list]) => ({
    label,
    utms: list,
  }));
  if (ungrouped.length > 0) {
    groups.push({ label: null, utms: ungrouped });
  }
  return groups;
}
