import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import { groupUtms, type GroupKeyOf } from './groupUtms';
import type { Utm } from '@/api/marketing';

/**
 * Property-based tests (Net 6) for groupUtms — a PURE function that partitions a
 * list of UTMs into labelled buckets plus a trailing "ungrouped" bucket.
 *
 * Seam: groupUtms takes its input as an argument and returns a new structure
 * with no I/O, so fast-check can hammer it directly. We exercise it with a
 * custom keyOf so the grouping key is controlled and arbitrary (covering empty,
 * whitespace, and duplicate keys), which is exactly where partition bugs hide.
 *
 * These are REAL invariants: grouping drives how operators see their campaigns,
 * so a UTM silently dropped, duplicated, or reordered is a data-integrity bug.
 */

// A minimal Utm factory — only the fields groupUtms touches matter, but we tag
// each row with a unique id so we can assert conservation by identity.
function utmWithKey(id: number, key: string): Utm {
  return {
    id,
    agency_id: 0,
    site_id: null,
    name: `utm-${id}`,
    base_url: 'https://example.com',
    utm_source: 'src',
    utm_medium: 'med',
    utm_campaign: 'camp',
    utm_content: null,
    utm_term: null,
    full_url: 'https://example.com?utm_source=src',
    primary_link_slug: null,
    group_label: key,
    is_archived: false,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
  };
}

// Arbitrary group keys: include empty, whitespace-only, padded, and repeats so
// trimming + ungrouped logic is fully exercised.
const keyArb = fc.oneof(
  fc.constant(''),
  fc.constant('   '),
  fc.constantFrom('alpha', 'beta', 'gamma'),
  fc.constantFrom(' alpha ', '  beta', 'gamma  '),
  fc.string(),
);

// A list of (id, key) pairs with unique, increasing ids.
const utmsArb = fc
  .array(keyArb, { maxLength: 40 })
  .map((keys) => keys.map((key, i) => utmWithKey(i, key)));

const keyOf: GroupKeyOf = (utm) =>
  typeof utm.group_label === 'string' ? utm.group_label : '';

describe('groupUtms — property invariants', () => {
  it('conserves every input UTM exactly once (no drop, no dupe)', () => {
    fc.assert(
      fc.property(utmsArb, (utms) => {
        const groups = groupUtms(utms, keyOf);
        const outIds = groups.flatMap((g) => g.utms.map((u) => u.id)).sort((a, b) => a - b);
        const inIds = utms.map((u) => u.id).sort((a, b) => a - b);
        expect(outIds).toEqual(inIds);
      }),
    );
  });

  it('treats empty/whitespace keys as the single trailing ungrouped bucket', () => {
    fc.assert(
      fc.property(utmsArb, (utms) => {
        const groups = groupUtms(utms, keyOf);
        const ungrouped = groups.filter((g) => g.label === null);
        // At most one ungrouped bucket.
        expect(ungrouped.length).toBeLessThanOrEqual(1);
        if (ungrouped.length === 1) {
          // It is always last.
          expect(groups[groups.length - 1].label).toBeNull();
          // It is never empty.
          expect(ungrouped[0].utms.length).toBeGreaterThan(0);
        }
        // Membership: a UTM is ungrouped iff its trimmed key is empty.
        const ungroupedIds = new Set(ungrouped.flatMap((g) => g.utms.map((u) => u.id)));
        for (const u of utms) {
          const isEmpty = keyOf(u).trim() === '';
          expect(ungroupedIds.has(u.id)).toBe(isEmpty);
        }
      }),
    );
  });

  it('labelled groups are trimmed, non-empty, unique, in first-appearance order', () => {
    fc.assert(
      fc.property(utmsArb, (utms) => {
        const groups = groupUtms(utms, keyOf);
        const labelled = groups.filter((g) => g.label !== null);

        // Each label is the trimmed key, non-empty, and unique across groups.
        const seen = new Set<string>();
        for (const g of labelled) {
          expect(g.label).toBe((g.label as string).trim());
          expect(g.label).not.toBe('');
          expect(seen.has(g.label as string)).toBe(false);
          seen.add(g.label as string);
        }

        // First-appearance order: the sequence of group labels matches the
        // de-duplicated sequence of trimmed non-empty keys in the input.
        const expectedOrder: string[] = [];
        const inSeen = new Set<string>();
        for (const u of utms) {
          const k = keyOf(u).trim();
          if (k !== '' && !inSeen.has(k)) {
            inSeen.add(k);
            expectedOrder.push(k);
          }
        }
        expect(labelled.map((g) => g.label)).toEqual(expectedOrder);
      }),
    );
  });

  it('preserves row order within every group', () => {
    fc.assert(
      fc.property(utmsArb, (utms) => {
        const groups = groupUtms(utms, keyOf);
        for (const g of groups) {
          const wantKey = g.label; // null => ungrouped
          const expectedIds = utms
            .filter((u) => {
              const trimmed = keyOf(u).trim();
              return wantKey === null ? trimmed === '' : trimmed === wantKey;
            })
            .map((u) => u.id);
          expect(g.utms.map((u) => u.id)).toEqual(expectedIds);
        }
      }),
    );
  });
});
