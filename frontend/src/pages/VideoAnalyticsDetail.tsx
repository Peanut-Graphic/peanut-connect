import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { videosApi } from '@/api';
import Layout from '@/components/layout/Layout';
import { Card } from '@/components/common';
import { VideoAnalyticsPanel } from '@/components/videos/VideoAnalyticsPanel';

/**
 * Per-video analytics detail page at /analytics/videos/:id.
 *
 * Wraps the existing VideoAnalyticsPanel (used inline on the Videos
 * management page since 3.7) so this dedicated route doesn't fork the
 * data path or visualization.
 */
export default function VideoAnalyticsDetail() {
  const { id: rawId = '' } = useParams<{ id: string }>();
  const videoId = Number(rawId);

  const videoQuery = useQuery({
    queryKey: ['videos', 'detail', videoId],
    queryFn: () => videosApi.list(),
    select: (videos) => videos.find((v) => v.id === videoId),
    enabled: Number.isFinite(videoId) && videoId > 0,
  });

  const video = videoQuery.data;

  return (
    <Layout
      title={video?.title ?? 'Video detail'}
      description={video?.description ?? 'Per-video analytics.'}
      action={
        <Link
          to="/analytics/videos"
          className="inline-flex items-center px-3 py-1.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50"
        >
          ← Back to videos
        </Link>
      }
    >
      {!Number.isFinite(videoId) && (
        <Card>
          <div className="p-6 text-sm text-red-600">Invalid video id.</div>
        </Card>
      )}
      {videoQuery.isLoading && (
        <Card>
          <div className="p-6 text-sm text-slate-500">Loading video…</div>
        </Card>
      )}
      {videoQuery.isError && (
        <Card>
          <div className="p-6 text-sm text-red-600">
            {(videoQuery.error as Error)?.message || 'Failed to load video.'}
          </div>
        </Card>
      )}
      {!videoQuery.isLoading && !video && Number.isFinite(videoId) && (
        <Card>
          <div className="p-6 text-sm text-slate-500">
            Video not found.{' '}
            <Link to="/analytics/videos" className="text-indigo-600 hover:underline">
              Back to videos →
            </Link>
          </div>
        </Card>
      )}
      {video && (
        <Card>
          <div className="p-4">
            <VideoAnalyticsPanel videoId={video.id} hubEmbedUrl={video.embed_url} />
          </div>
        </Card>
      )}
    </Layout>
  );
}
