import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Layout } from '@/components/layout';
import { Card, Alert } from '@/components/common';
import { useToast } from '@/components/common/Toast';
import { useConfirm } from '@/hooks/useConfirm';
import { videosApi, type Video, type VideoInput } from '@/api';
import { VideoAnalyticsPanel } from '@/components/videos/VideoAnalyticsPanel';
import { VideoCard } from '@/components/videos/VideoCard';

declare global {
  interface Window {
    wp?: { media?: any };
  }
}

function pickFromMedia(opts: { title: string; type?: string }): Promise<string | null> {
  return new Promise((resolve) => {
    const wp = window.wp;
    if (!wp || !wp.media) { resolve(null); return; }
    const frame = wp.media({ title: opts.title, multiple: false, library: opts.type ? { type: opts.type } : undefined });
    let settled = false;
    const settle = (value: string | null) => {
      if (settled) return;
      settled = true;
      frame.off('select');
      frame.off('close');
      resolve(value);
    };
    frame.on('select', () => {
      const a = frame.state().get('selection').first().toJSON();
      settle(a?.url ?? null);
    });
    frame.on('close', () => setTimeout(() => settle(null), 0));
    frame.open();
  });
}

export default function Videos() {
  const queryClient = useQueryClient();
  const toast = useToast();
  const { confirm, dialog: confirmDialog } = useConfirm();
  const [form, setForm] = useState<VideoInput>({ title: '', source_url: '' });
  const [poster, setPoster] = useState('');
  const [caption, setCaption] = useState('');
  const [expanded, setExpanded] = useState<number | null>(null);

  const { data, isLoading, error } = useQuery({
    queryKey: ['videos'],
    queryFn: () => videosApi.list(),
  });

  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: ['videos'] });

  const create = useMutation({
    mutationFn: () =>
      videosApi.create({
        ...form,
        poster_url: poster || undefined,
        caption_url: caption || undefined,
      }),
    onSuccess: () => {
      invalidate();
      setForm({ title: '', source_url: '' });
      setPoster('');
      setCaption('');
      toast.success('Video registered.');
    },
    onError: (err: Error) =>
      toast.error(`Could not register video: ${err.message || 'unknown error'}`),
  });

  const remove = useMutation({
    mutationFn: (id: number) => videosApi.remove(id),
    onSuccess: () => {
      invalidate();
      toast.success('Video removed.');
    },
    onError: (err: Error) =>
      toast.error(`Could not remove video: ${err.message || 'unknown error'}`),
  });

  const videos: Video[] = data ?? [];
  const expandedVideo = videos.find((v) => v.id === expanded) ?? null;

  return (
    <Layout
      title="Videos"
      description="Register a video with Hub, insert it anywhere, and track engagement."
    >
      {error && (
        <Alert variant="error" className="mb-4">
          {(error as Error).message}
        </Alert>
      )}

      <Card>
        <h3 className="text-sm font-semibold mb-3">Add a video</h3>
        <div className="grid gap-3 sm:grid-cols-2">
          <input
            aria-label="Video title"
            className="border rounded px-3 py-2 text-sm"
            placeholder="Title"
            value={form.title}
            onChange={(e) => setForm({ ...form, title: e.target.value })}
          />
          <div className="flex gap-2">
            <input
              aria-label="Video file URL"
              className="border rounded px-3 py-2 text-sm flex-1"
              placeholder="Video URL (.mp4) or pick"
              value={form.source_url}
              onChange={(e) => setForm({ ...form, source_url: e.target.value })}
            />
            <button
              type="button"
              className="text-xs px-2 py-1 border rounded"
              onClick={async () => {
                const u = await pickFromMedia({ title: 'Select video', type: 'video' });
                if (u) setForm((f) => ({ ...f, source_url: u }));
              }}
            >
              Media
            </button>
          </div>
          <div className="flex gap-2">
            <input
              aria-label="Poster image URL"
              className="border rounded px-3 py-2 text-sm flex-1"
              placeholder="Poster image URL (optional)"
              value={poster}
              onChange={(e) => setPoster(e.target.value)}
            />
            <button
              type="button"
              className="text-xs px-2 py-1 border rounded"
              onClick={async () => {
                const u = await pickFromMedia({ title: 'Select poster', type: 'image' });
                if (u) setPoster(u);
              }}
            >
              Media
            </button>
          </div>
          <div className="flex gap-2">
            <input
              aria-label="Captions VTT URL"
              className="border rounded px-3 py-2 text-sm flex-1"
              placeholder="Captions .vtt URL (optional)"
              value={caption}
              onChange={(e) => setCaption(e.target.value)}
            />
            <button
              type="button"
              className="text-xs px-2 py-1 border rounded"
              onClick={async () => {
                const u = await pickFromMedia({ title: 'Select captions' });
                if (u) setCaption(u);
              }}
            >
              Media
            </button>
          </div>
        </div>
        <button
          className="mt-3 bg-black text-white text-sm px-4 py-2 rounded disabled:opacity-50"
          disabled={!form.title || !form.source_url || create.isPending}
          onClick={() => create.mutate()}
        >
          {create.isPending ? 'Registering…' : 'Register video'}
        </button>
      </Card>

      {isLoading && <div className="mt-4 text-sm text-slate-500">Loading…</div>}
      {!isLoading && !error && videos.length === 0 && (
        <Card className="mt-4">
          <div className="text-sm text-slate-500">No videos yet.</div>
        </Card>
      )}
      {videos.length > 0 && (
        <div className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {videos.map((v) => (
            <VideoCard
              key={v.id}
              video={v}
              analyticsOpen={expanded === v.id}
              onCopy={() => {
                navigator.clipboard.writeText(`[peanut_video slug="${v.slug}"]`);
                toast.success('Shortcode copied.');
              }}
              onAnalytics={() => setExpanded(expanded === v.id ? null : v.id)}
              onRemove={async () => {
                const ok = await confirm({
                  title: 'Remove video?',
                  message: 'It will stop rendering and disappear from this list.',
                  confirmText: 'Remove',
                  variant: 'danger',
                });
                if (ok) remove.mutate(v.id);
              }}
            />
          ))}
        </div>
      )}
      {expandedVideo && (
        <Card className="mt-4">
          <div className="mb-3 flex items-center justify-between">
            <h3 className="text-sm font-semibold">{expandedVideo.title} analytics</h3>
            <button
              type="button"
              className="text-xs px-2 py-1 border rounded"
              onClick={() => setExpanded(null)}
            >
              Close
            </button>
          </div>
          <VideoAnalyticsPanel videoId={expandedVideo.id} hubEmbedUrl={expandedVideo.embed_url} />
        </Card>
      )}
      {confirmDialog}
    </Layout>
  );
}
