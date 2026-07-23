import { describe, it, expect, vi, beforeEach } from 'vitest';
import api from './client';
import { marketingApi } from './marketing';

vi.mock('./client');

const inner = {
  kpis: { landed: 2263, entered: 56, enrolled: 6, conversion_rate: 0.002652 },
  reaching: [
    { stage: 'landed', label: 'Landed', count: 2263 },
    { stage: 'entered', label: 'Entered portal', count: 56 },
  ],
  inside: [
    { stage: 'entered', label: 'Entered', count: 56 },
    { stage: 'validation', label: 'Validation', count: 32 },
    { stage: 'login', label: 'Login', count: 30 },
    { stage: 'dashboard', label: 'Dashboard', count: 9 },
    { stage: 'enrolled', label: 'Enrolled', count: 6 },
  ],
  campaigns: [{ campaign: 'DOME2620RS1', landed: 1800 }],
  journeys: {
    data: [
      {
        click_id: 'abc123',
        campaign: 'DOME2620RS1',
        entered_at: '2026-07-20T19:12:00Z',
        furthest_step: 'dashboard',
        duration_seconds: 356,
        status: 'in_progress',
      },
    ],
    meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
  },
};
const meta = {
  from: '2026-06-23',
  to: '2026-07-23',
  campaign: null,
  include_test: false,
  attribution_note: 'attr-note',
  enrolled_note: 'enr-note',
  generated_at: '2026-07-23T00:00:00+00:00',
};

describe('marketingApi.dominionFunnel', () => {
  beforeEach(() => vi.clearAllMocks());

  it('GETs the endpoint with params and returns the parsed envelope (flattened runtime shape)', async () => {
    (api.get as any).mockResolvedValue({ data: { ...inner, meta } });

    const out = await marketingApi.dominionFunnel({
      from: '2026-06-23',
      to: '2026-07-23',
      include_test: false,
    });

    expect(api.get).toHaveBeenCalledWith('/marketing/dominion-funnel', {
      params: { from: '2026-06-23', to: '2026-07-23', include_test: false },
    });
    expect(out.kpis.enrolled).toBe(6);
    expect(out.reaching).toHaveLength(2);
    expect(out.inside).toHaveLength(5);
    expect(out.journeys.data[0].furthest_step).toBe('dashboard');
    expect(out.journeys.meta.total).toBe(1);
    expect(out.meta.attribution_note).toBe('attr-note');
  });

  it('preserves top-level meta when the client returns the raw {data,meta} envelope', async () => {
    (api.get as any).mockResolvedValue({ data: { success: true, data: inner, meta } });

    const out = await marketingApi.dominionFunnel({});

    expect(out.meta.enrolled_note).toBe('enr-note');
    expect(out.kpis.landed).toBe(2263);
    expect(out.campaigns[0].campaign).toBe('DOME2620RS1');
  });
});
