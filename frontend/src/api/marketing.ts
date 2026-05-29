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
    return res.data as JourneyListResponse;
  },

  journeyDetail: async (clickId: string): Promise<JourneyDetailResponse> => {
    const res = await api.get(`/marketing/journeys/${encodeURIComponent(clickId)}`);
    return res.data as JourneyDetailResponse;
  },

  trackingSetup: async (): Promise<TrackingSetup> => {
    const res = await api.get('/marketing/tracking-setup');
    return res.data as TrackingSetup;
  },
};
