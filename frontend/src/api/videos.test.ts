import { describe, it, expect, vi, beforeEach } from 'vitest';
import { videosApi } from './videos';
import api from './client';

vi.mock('./client');

describe('videosApi', () => {
  beforeEach(() => vi.resetAllMocks());

  it('list() GETs /videos and returns the unwrapped array', async () => {
    (api.get as any).mockResolvedValue({ data: { data: [{ id: 1, slug: 's', title: 'T' }], success: true } });
    const out = await videosApi.list();
    expect(api.get).toHaveBeenCalledWith('/videos');
    expect(out).toEqual([{ id: 1, slug: 's', title: 'T' }]);
  });

  it('create() POSTs /videos with the payload', async () => {
    (api.post as any).mockResolvedValue({ data: { id: 2, slug: 'p-x', embed_url: 'u' } });
    const out = await videosApi.create({ title: 'P', source_url: 'https://e/v.mp4' });
    expect(api.post).toHaveBeenCalledWith('/videos', { title: 'P', source_url: 'https://e/v.mp4' });
    expect(out.slug).toBe('p-x');
  });

  it('update() PATCHes /videos/{id}', async () => {
    (api.patch as any).mockResolvedValue({ data: { id: 3, slug: 's3' } });
    const out = await videosApi.update(3, { title: 'New' });
    expect(api.patch).toHaveBeenCalledWith('/videos/3', { title: 'New' });
    expect(out.id).toBe(3);
  });

  it('analytics() GETs /videos/{id}/analytics with days param', async () => {
    (api.get as any).mockResolvedValue({ data: { total_plays: 3, drop_off_all_time: { '0%': 3 }, days: 30 } });
    const out = await videosApi.analytics(7, 30);
    expect(api.get).toHaveBeenCalledWith('/videos/7/analytics', { params: { days: 30 } });
    expect(out.drop_off_all_time['0%']).toBe(3);
  });

  it('remove() DELETEs /videos/{id}', async () => {
    (api.delete as any).mockResolvedValue({ data: { success: true } });
    await videosApi.remove(5);
    expect(api.delete).toHaveBeenCalledWith('/videos/5');
  });
});
