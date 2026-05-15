import { describe, it, expect } from 'vitest';
import { groupUtms } from './groupUtms';
import type { Utm } from '@/api/marketing';

const u = (id: number, group_label: string | null): Utm =>
  ({ id, group_label, name: `u${id}` } as unknown as Utm);

describe('groupUtms', () => {
  it('returns a single ungrouped bucket (label null) when nothing is labelled', () => {
    const g = groupUtms([u(1, null), u(2, null)]);
    expect(g).toHaveLength(1);
    expect(g[0].label).toBeNull();
    expect(g[0].utms.map((x) => x.id)).toEqual([1, 2]);
  });

  it('groups by group_label, preserving row order within a group', () => {
    const g = groupUtms([u(1, 'Spring'), u(2, 'Fall'), u(3, 'Spring')]);
    const spring = g.find((x) => x.label === 'Spring')!;
    expect(spring.utms.map((x) => x.id)).toEqual([1, 3]);
    expect(g.find((x) => x.label === 'Fall')!.utms.map((x) => x.id)).toEqual([2]);
  });

  it('orders labelled groups by first appearance, ungrouped bucket last', () => {
    const g = groupUtms([u(1, 'B'), u(2, null), u(3, 'A'), u(4, null)]);
    expect(g.map((x) => x.label)).toEqual(['B', 'A', null]);
    expect(g[2].utms.map((x) => x.id)).toEqual([2, 4]);
  });

  it('treats empty-string / whitespace label as ungrouped', () => {
    const g = groupUtms([u(1, ''), u(2, '   '), u(3, 'X')]);
    expect(g.map((x) => x.label)).toEqual(['X', null]);
    expect(g.find((x) => x.label === null)!.utms.map((x) => x.id)).toEqual([1, 2]);
  });
});
