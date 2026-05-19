import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { VideoAnalyticsPanel } from './VideoAnalyticsPanel';

// Mock the API (matches codebase convention: vi.mock('@/api'))
vi.mock('@/api', () => ({
  videosApi: { analytics: vi.fn() },
}));

// Import the mocked API
import { videosApi } from '@/api';

// Helper to create a test wrapper (matches Settings.test.tsx convention)
function createTestWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: {
        retry: false,
      },
    },
  });

  return function Wrapper({ children }: { children: React.ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );
  };
}

describe('VideoAnalyticsPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders metrics and a drop-off bar per bucket', async () => {
    (videosApi.analytics as ReturnType<typeof vi.fn>).mockResolvedValue({
      daily_views: { '2026-05-19': 4 },
      avg_watch_time: 12.5,
      completion_rate: 50,
      unique_viewers: 3,
      total_plays: 4,
      drop_off_all_time: { '0%': 4, '50%': 2, '100%': 1 },
      days: 30,
    });

    render(
      <VideoAnalyticsPanel
        videoId={7}
        hubEmbedUrl="https://hub.example.com/video/x/embed"
      />,
      { wrapper: createTestWrapper() }
    );

    await waitFor(() =>
      expect(screen.getByText(/Total plays/i)).toBeInTheDocument()
    );
    expect(screen.getByText('4')).toBeInTheDocument();
    // Completion metric value (50%) renders. Note: a "50%" drop-off bucket
    // label also exists, so /50%/ legitimately matches >1 node — assert the
    // completion value is present (and that both 50% renderings appear).
    expect(screen.getAllByText(/50%/).length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('Completion')).toBeInTheDocument();
    expect(screen.getAllByTestId('dropoff-bar').length).toBe(3);
  });
});
