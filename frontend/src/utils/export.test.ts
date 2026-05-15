import { describe, it, expect } from 'vitest';
import { buildUtmCsv } from './export';
import type { Utm } from '@/api/marketing';

const makeUtm = (over: Partial<Utm> = {}): Utm => ({
  id: 1,
  agency_id: 1,
  site_id: 1,
  name: 'Spring Learn',
  base_url: 'https://example.com/learn',
  utm_source: 'dominion-email',
  utm_medium: 'email',
  utm_campaign: 'DOME2620RS1',
  utm_content: null,
  utm_term: null,
  full_url: 'https://example.com/learn?utm_source=dominion-email',
  primary_link_slug: 'DOME2620RS1_learn',
  group_label: null,
  is_archived: false,
  click_count: 7,
  created_at: '2026-05-15T00:00:00Z',
  updated_at: '2026-05-15T00:00:00Z',
  ...over,
});

describe('buildUtmCsv', () => {
  it('emits a header row even for an empty selection', () => {
    const csv = buildUtmCsv([]);
    expect(csv).toBe(
      '"name","source","medium","target_url","full_url","campaign","shortcode","clicks"'
    );
  });

  it('writes one CRLF-terminated row per UTM in column order', () => {
    const csv = buildUtmCsv([makeUtm()]);
    const [header, row] = csv.split('\r\n');
    expect(header).toBe(
      '"name","source","medium","target_url","full_url","campaign","shortcode","clicks"'
    );
    expect(row).toBe(
      '"Spring Learn","dominion-email","email","https://example.com/learn","https://example.com/learn?utm_source=dominion-email","DOME2620RS1","DOME2620RS1_learn","7"'
    );
  });

  it('uses primary_link_slug as the shortcode, empty when null', () => {
    const withSlug = buildUtmCsv([makeUtm()]).split('\r\n')[1];
    expect(withSlug).toContain('"DOME2620RS1","DOME2620RS1_learn","7"');
    const noSlug = buildUtmCsv([makeUtm({ primary_link_slug: null })]).split('\r\n')[1];
    expect(noSlug).toContain('"DOME2620RS1","","7"');
  });

  it('escapes embedded quotes, commas and newlines per RFC 4180', () => {
    const csv = buildUtmCsv([
      makeUtm({ name: 'A,B "quoted"\nnext', utm_campaign: 'c,d' }),
    ]);
    const row = csv.split('\r\n')[1];
    expect(row.startsWith('"A,B ""quoted""\nnext","dominion-email"')).toBe(true);
    expect(row).toContain('"c,d"');
  });

  it('treats a missing click_count as 0', () => {
    const csv = buildUtmCsv([makeUtm({ click_count: undefined })]);
    expect(csv.split('\r\n')[1].endsWith('"0"')).toBe(true);
  });
});
