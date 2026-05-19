import api from './client';

export interface Video {
  id: number;
  title: string;
  slug: string;
  description: string | null;
  source_url: string | null;
  poster_url: string | null;
  caption_url: string | null;
  status: string;
  created_at: string | null;
  // Always present — Hub's present() returns url('/video/{slug}/embed') (never null).
  embed_url: string;
}

export interface VideoInput {
  title: string;
  source_url: string;
  poster_url?: string;
  caption_url?: string;
  description?: string;
}

export interface VideoAnalytics {
  daily_views: Record<string, number>;
  avg_watch_time: number;
  completion_rate: number;
  unique_viewers: number;
  total_plays: number;
  drop_off_all_time: Record<string, number>;
  days: number;
}

export const videosApi = {
  list: async (): Promise<Video[]> => {
    const res = await api.get('/videos');
    // Hub returns {success,data:[...]}; the client interceptor keeps an array
    // payload addressable under `.data` (it only spreads plain-object payloads).
    return ((res.data?.data ?? res.data) as Video[]) ?? [];
  },
  create: async (input: VideoInput): Promise<Video> => {
    const res = await api.post('/videos', input);
    return res.data as Video;
  },
  update: async (id: number, input: Partial<VideoInput>): Promise<Video> => {
    const res = await api.patch(`/videos/${id}`, input);
    return res.data as Video;
  },
  remove: async (id: number): Promise<void> => {
    await api.delete(`/videos/${id}`);
  },
  analytics: async (id: number, days: 7 | 30 | 90 = 30): Promise<VideoAnalytics> => {
    const res = await api.get(`/videos/${id}/analytics`, { params: { days } });
    return res.data as VideoAnalytics;
  },
};
