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
  is_archived: boolean;
  click_count?: number;
  links_count?: number;
  created_at: string;
  updated_at: string;
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
}

export interface CampaignResult {
  name: string;
  utm: Utm;
  link: Link;
  short_url: string;
  full_url: string;
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
}

export interface TrackingSetup {
  connected: boolean;
  hub_url: string;
  tracker_js: string;
  site_key: string;
}

export const marketingApi = {
  buildCampaign: async (input: CampaignBuildInput): Promise<CampaignResult> => {
    const res = await api.post('/marketing/campaigns', input);
    // Hub returns { success, campaign: {...} }
    return res.data.campaign as CampaignResult;
  },

  listUtms: async (params: { archived?: boolean; per_page?: number; page?: number } = {}): Promise<Paginated<Utm>> => {
    const res = await api.get('/marketing/utms', { params });
    return res.data.data as Paginated<Utm>;
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
    return res.data.data as Paginated<Link>;
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

  trackingSetup: async (): Promise<TrackingSetup> => {
    const res = await api.get('/marketing/tracking-setup');
    return res.data as TrackingSetup;
  },
};
