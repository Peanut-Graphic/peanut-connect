import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Layout } from '@/components/layout';
import { Card, Alert } from '@/components/common';
import { useToast } from '@/components/common/Toast';
import { useConfirm } from '@/hooks/useConfirm';
import { videosApi, type Video, type VideoInput } from '@/api';
import { VideoAnalyticsPanel } from '@/components/videos/VideoAnalyticsPanel';
import { VideoCard, type VideoActionHandlers } from '@/components/videos/VideoCard';
import { VideoList } from '@/components/videos/VideoList';
import { embedCodeFor, shortcodeFor } from '@/components/videos/videoFormat';

type ViewMode = 'grid' | 'list';
const VIEW_KEY = 'peanut-connect.videos.view';

// A per-browser convenience only: storage can be blocked, so fall back quietly.
function readView(): ViewMode {
  try {
    return window.localStorage.getItem(VIEW_KEY) === 'list' ? 'list' : 'grid';
  } catch {
    return 'grid';
  }
}

function saveView(view: ViewMode): void {
  try {
    window.localStorage.setItem(VIEW_KEY, view);
  } catch {
    // ignore
  }
}

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
  const [view, setView] = useState<ViewMode>(readView);

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

  const page = useMutation({
    mutationFn: (id: number) => videosApi.createPage(id),
    onSuccess: (p) => {
      invalidate();
      toast.success(p.created ? 'Draft page created. Opening the editor.' : 'This video already has a page. Opening it.');
      window.open(p.edit_url, '_blank', 'noopener');
    },
    onError: (err: Error) =>
      toast.error(`Could not create the page: ${err.message || 'unknown error'}`),
  });

  const copy = async (text: string, what: string) => {
    try {
      await navigator.clipboard.writeText(text);
      toast.success(`${what} copied.`);
    } catch {
      toast.error(`Could not copy the ${what.toLowerCase()}. Select it and copy manually.`);
    }
  };

  const handlersFor = (v: Video): VideoActionHandlers => ({
    onCopy: () => copy(shortcodeFor(v), 'Shortcode'),
    onCopyEmbed: () => copy(embedCodeFor(v), 'Embed code'),
    onAnalytics: () => setExpanded(expanded === v.id ? null : v.id),
    onPage: () => {
      if (v.page) {
        window.open(v.page.edit_url, '_blank', 'noopener');
      } else {
        page.mutate(v.id);
      }
    },
    onRemove: async () => {
      const ok = await confirm({
        title: 'Remove video?',
        message: 'It will stop rendering and disappear from this list.',
        confirmText: 'Remove',
        variant: 'danger',
      });
      if (ok) remove.mutate(v.id);
    },
  });

  const chooseView = (next: ViewMode) => {
    setView(next);
    saveView(next);
  };

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
        <div className="mt-4 mb-3 flex items-center justify-between">
          <h3 className="text-sm font-semibold">
            {videos.length} {videos.length === 1 ? 'video' : 'videos'}
          </h3>
          <div role="group" aria-label="Layout" className="inline-flex overflow-hidden rounded border bg-white text-xs">
            {(['grid', 'list'] as const).map((mode) => (
              <button
                key={mode}
                type="button"
                aria-pressed={view === mode}
                onClick={() => chooseView(mode)}
                className={`px-3 py-1.5 capitalize ${view === mode ? 'bg-slate-900 text-white' : 'text-slate-700'}`}
              >
                {mode}
              </button>
            ))}
          </div>
        </div>
      )}
      {videos.length > 0 && view === 'grid' && (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {videos.map((v) => (
            <VideoCard
              key={v.id}
              video={v}
              analyticsOpen={expanded === v.id}
              pageBusy={page.isPending && page.variables === v.id}
              {...handlersFor(v)}
            />
          ))}
        </div>
      )}
      {videos.length > 0 && view === 'list' && (
        <VideoList
          videos={videos}
          expanded={expanded}
          pageBusyId={page.isPending ? (page.variables ?? null) : null}
          handlersFor={handlersFor}
        />
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
