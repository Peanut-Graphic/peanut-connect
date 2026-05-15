import type { Utm } from '@/api/marketing';

export interface UtmGroup {
  /** The group_label, or null for the "ungrouped" bucket. */
  label: string | null;
  utms: Utm[];
}

// Group UTMs by their group_label for the collapsible table view.
// - Empty/whitespace labels are treated as ungrouped (label null).
// - Labelled groups appear in order of first appearance.
// - The ungrouped bucket (if any) always comes last.
// - Row order within a group is preserved.
export function groupUtms(utms: Utm[]): UtmGroup[] {
  const labelled = new Map<string, Utm[]>();
  const ungrouped: Utm[] = [];

  for (const utm of utms) {
    const raw = utm.group_label;
    const label = typeof raw === 'string' ? raw.trim() : '';
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
