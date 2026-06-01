import api from './client';

export interface Utm {
  id: number;
  agency_id: number;
  site_id: number | null;
  name: string;
  base_url: string;
  utm_source: string;
  utm_medium: string;
  utm_campaign: string;
  utm_content: string | null;
  utm_term: string | null;
  full_url: string;
  // Phase 2 (Hub >= group_label/primary_link_slug deploy): the UTM's primary
  // (oldest) short link slug, and the operator-typed grouping label. Both
  // null when absent / not yet labelled.
  primary_link_slug: string | null;
  group_label: string | null;
  is_archived: boolean;
  click_count?: number;
  links_count?: number;
  send_count?: number;
  campaign_cost?: number | string | null;
  cost_per_send?: number | string | null;
  notes?: string | null;
  created_at: string;
  updated_at: string;
}

export interface UtmUpdateInput {
  name?: string;
  send_count?: number | null;
  campaign_cost?: number | null;
  notes?: string | null;
  base_url?: string;
  utm_source?: string;
  utm_medium?: string;
  utm_campaign?: string;
  utm_content?: string | null;
  utm_term?: string | null;
  group_label?: string | null;
}

export interface Link {
  id: number;
  agency_id: number;
  site_id: number | null;
  utm_id: number | null;
  slug: string;
  destination_url: string;
  title: string | null;
  description: string | null;
  is_active: boolean;
  click_count: number;
  expires_at: string | null;
  short_url?: string;
  utm?: Pick<Utm, 'id' | 'name' | 'utm_campaign' | 'utm_source' | 'utm_medium'>;
  created_at: string;
  updated_at: string;
}

export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface CampaignBuildInput {
  name: string;
  base_url: string;
  utm_source: string;
  utm_medium: string;
  utm_campaign: string;
  utm_content?: string;
  utm_term?: string;
  custom_slug?: string;
  send_count?: number;
  campaign_cost?: number;
}

export interface CampaignResult {
  name: string;
  utm: Utm;
  link: Link;
  short_url: string;
  full_url: string;
}

export interface FunnelStage {
  stage: string;
  label: string;
  count: number;
}

export interface JourneyStats {
  total_journeys: number;
  conversions: number;
  conversion_rate: number;
  avg_duration_seconds?: number;
  by_campaign?: Array<{
    utm_campaign: string;
    journeys: number;
    conversions: number;
  }>;
  by_channel?: Array<{
    channel: string;
    journeys: number;
    conversions: number;
  }>;
  funnel?: FunnelStage[];
  cost_total?: number;
  cost_per_acquisition?: number | null;
  click_through_rate?: number | null;
  time_series?: Array<{ date: string; journeys: number; conversions: number; clicked_enroll?: number }>;
  devices?: Array<{ device_type: string; count: number }>;
  regions?: Array<{ country: string; region: string | null; count: number }>;
  sankey?: {
    nodes: Array<{ id: number; name: string; category: string }>;
    links: Array<{ source: number; target: number; value: number }>;
  };
}

export interface TrackingSetup {
  connected: boolean;
  hub_url: string;
  tracker_js: string;
  site_key: string;
  site_key_masked?: string;
}

export interface JourneyRow {
  id: number;
  click_id: string;
  status: 'in_progress' | 'converted' | 'abandoned';
  utm_campaign: string | null;
  utm_source: string | null;
  utm_medium: string | null;
  started_at: string;
  duration_seconds: number | null;
  link?: { id: number; slug: string; title: string | null };
  site?: { id: number; name: string };
}

export interface JourneyListResponse {
  data: JourneyRow[];
  current_page?: number;
  last_page?: number;
  per_page?: number;
  total?: number;
}

export interface JourneyEventRow {
  id: number;
  event_type: string;
  event_name: string | null;
  page_url: string | null;
  page_title: string | null;
  event_at: string;
  event_data?: Record<string, unknown> | null;
}

export interface JourneyDetailResponse {
  journey: JourneyRow & {
    converted_at: string | null;
    last_event_at: string | null;
    pages_viewed: number | null;
    events_count: number | null;
  };
  events: JourneyEventRow[];
}

export interface GtmCapture {
  id: number;
  container_id: string;
  page_url: string | null;
  page_title: string | null;
  referrer: string | null;
  gtm_event_id: string | null;
  ua: string | null;
  signature_valid: boolean;
  captured_at: string;
}

export interface GtmHostRow {
  host: string;
  load_count: number;
  unique_urls: number;
  last_seen: string | null;
}

export interface GtmTotals {
  total_captures: number;
  unique_urls: number;
  unique_hosts: number;
  spoofed: number;
}

export interface GtmCoverageResponse {
  captures: GtmCapture[];
  hosts: GtmHostRow[];
  totals: GtmTotals;
  containers: string[];
  meta: { current_page?: number; last_page?: number; per_page?: number; total?: number };
  message?: string;
}

export const marketingApi = {
  buildCampaign: async (input: CampaignBuildInput): Promise<CampaignResult> => {
    const res = await api.post('/marketing/campaigns', input);
    // Hub returns { success, campaign: {...} }
    const campaign = res.data?.campaign as CampaignResult | undefined;
    if (!campaign) {
      throw new Error(
        res.data?.message ||
          'Hub did not return a campaign payload. The link may still have been created — check the Links tab.'
      );
    }
    return campaign;
  },

  listUtms: async (params: { archived?: boolean; per_page?: number; page?: number } = {}): Promise<Paginated<Utm>> => {
    const res = await api.get('/marketing/utms', { params });
    return res.data as Paginated<Utm>;
  },

  updateUtm: async (id: number, input: UtmUpdateInput): Promise<Utm> => {
    const res = await api.put(`/marketing/utms/${id}`, input);
    return res.data.utm as Utm;
  },

  archiveUtm: async (id: number): Promise<Utm> => {
    const res = await api.post(`/marketing/utms/${id}/archive`);
    return res.data.utm as Utm;
  },

  restoreUtm: async (id: number): Promise<Utm> => {
    const res = await api.post(`/marketing/utms/${id}/restore`);
    return res.data.utm as Utm;
  },

  deleteUtm: async (id: number): Promise<void> => {
    await api.delete(`/marketing/utms/${id}`);
  },

  listLinks: async (params: { active?: boolean; per_page?: number; page?: number } = {}): Promise<Paginated<Link>> => {
    const res = await api.get('/marketing/links', { params });
    return res.data as Paginated<Link>;
  },

  toggleLink: async (id: number): Promise<Link> => {
    const res = await api.patch(`/marketing/links/${id}/toggle`);
    return res.data.link as Link;
  },

  deleteLink: async (id: number): Promise<void> => {
    await api.delete(`/marketing/links/${id}`);
  },

  journeyStats: async (params: { from?: string; to?: string; campaign?: string } = {}): Promise<JourneyStats> => {
    const res = await api.get('/marketing/journeys/stats', { params });
    return res.data as JourneyStats;
  },

  listJourneys: async (
    params: {
      page?: number;
      per_page?: number;
      campaign?: string;
      status?: string;
      start_date?: string;
      end_date?: string;
      event_name?: string;
      search?: string;
    } = {},
  ): Promise<JourneyListResponse> => {
    const res = await api.get('/marketing/journeys', { params });
    // Hub API returns { success, journeys: [...], meta: { current_page, last_page, per_page, total } }.
    // Normalize to JourneyListResponse regardless of how the WP client
    // interceptor unwraps the envelope.
    const payload = (res.data?.data ?? res.data) as {
      journeys?: JourneyRow[];
      data?: JourneyRow[];
      meta?: { current_page?: number; last_page?: number; per_page?: number; total?: number };
    };
    return {
      data: payload.journeys ?? payload.data ?? [],
      current_page: payload.meta?.current_page,
      last_page: payload.meta?.last_page,
      per_page: payload.meta?.per_page,
      total: payload.meta?.total,
    };
  },

  journeyDetail: async (clickId: string): Promise<JourneyDetailResponse> => {
    const res = await api.get(`/marketing/journeys/${encodeURIComponent(clickId)}`);
    // Hub API returns { success, journey: { ..., events: [...] } } — events
    // are nested under `journey`, not top-level. Flatten so JourneyDetail's
    // existing render shape (journey + events siblings) keeps working.
    const payload = (res.data?.data ?? res.data) as {
      journey?: Record<string, unknown> & { events?: JourneyEventRow[] };
    };
    const j = (payload.journey ?? {}) as Record<string, unknown>;
    const rawEvents = (payload.journey?.events ?? []) as Array<Omit<JourneyEventRow, 'id'> & { id?: number }>;
    const events: JourneyEventRow[] = rawEvents.map((e, idx) => ({ ...e, id: e.id ?? idx }));
    return {
      journey: {
        id: 0, // Hub API uses click_id; no numeric id surfaced. Used only for legacy props.
        click_id: (j.click_id as string) ?? clickId,
        status:
          (j.status as 'in_progress' | 'converted' | 'abandoned') ?? 'in_progress',
        utm_campaign: (j.utm_campaign as string | null) ?? null,
        utm_source: (j.utm_source as string | null) ?? null,
        utm_medium: (j.utm_medium as string | null) ?? null,
        started_at: (j.started_at as string) ?? '',
        duration_seconds: (j.duration_seconds as number | null) ?? null,
        converted_at: (j.converted_at as string | null) ?? null,
        last_event_at: (j.last_event_at as string | null) ?? null,
        pages_viewed: (j.pages_viewed as number | null) ?? null,
        events_count: (j.events_count as number | null) ?? null,
      },
      events,
    };
  },

  trackingSetup: async (): Promise<TrackingSetup> => {
    const res = await api.get('/marketing/tracking-setup');
    return res.data as TrackingSetup;
  },

  gtmCoverage: async (
    params: {
      page?: number;
      per_page?: number;
      search?: string;
      host?: string;
      valid_only?: boolean;
    } = {},
  ): Promise<GtmCoverageResponse> => {
    const res = await api.get('/marketing/gtm-coverage', { params });
    const payload = (res.data?.data ?? res.data) as {
      data?: {
        captures?: GtmCapture[];
        hosts?: GtmHostRow[];
        totals?: GtmTotals;
        containers?: string[];
      };
      captures?: GtmCapture[];
      hosts?: GtmHostRow[];
      totals?: GtmTotals;
      containers?: string[];
      meta?: { current_page?: number; last_page?: number; per_page?: number; total?: number };
      message?: string;
    };
    const inner = payload.data ?? payload;
    return {
      captures: inner.captures ?? [],
      hosts: inner.hosts ?? [],
      totals: inner.totals ?? {
        total_captures: 0,
        unique_urls: 0,
        unique_hosts: 0,
        spoofed: 0,
      },
      containers: inner.containers ?? [],
      meta: payload.meta ?? { current_page: 1, last_page: 1, per_page: 0, total: 0 },
      message: payload.message,
    };
  },
};
