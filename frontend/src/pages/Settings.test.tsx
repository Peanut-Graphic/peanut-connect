import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import Settings from './Settings';
import { ToastProvider } from '@/components/common';
import { ThemeProvider } from '@/contexts';

// Mock the API
vi.mock('@/api', () => ({
  getVersion: () => '0.0.0-test', // consumed by the Layout sidebar chrome
  settingsApi: {
    get: vi.fn(),
    generateKey: vi.fn(),
    regenerateKey: vi.fn(),
    disconnect: vi.fn(),
    updatePermissions: vi.fn(),
  },
  errorLogApi: {
    get: vi.fn().mockResolvedValue({ logs: [], total: 0 }),
    getCounts: vi.fn().mockResolvedValue({ total: 0, unresolved: 0 }),
    updateSettings: vi.fn().mockResolvedValue({ success: true }),
  },
  trackingApi: {
    updateTrackLoggedIn: vi.fn().mockResolvedValue({ success: true }),
  },
  updatesApi: {
    get: vi.fn().mockResolvedValue({ plugins: [], themes: [], core: null }),
    update: vi.fn().mockResolvedValue({ success: true }),
    checkForUpdates: vi.fn().mockResolvedValue({
      success: true,
      message: 'Up to date',
      data: { current_version: '3.9.2', latest_version: '3.9.2', update_available: false },
    }),
  },
}));

// Import the mocked API
import { settingsApi } from '@/api';

// Helper to create a test wrapper
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
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <ThemeProvider>
            <ToastProvider>
              {children}
            </ToastProvider>
          </ThemeProvider>
        </MemoryRouter>
      </QueryClientProvider>
    );
  };
}

describe('Settings Page', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows loading skeleton while fetching settings', () => {
    // Mock a pending request
    (settingsApi.get as ReturnType<typeof vi.fn>).mockImplementation(
      () => new Promise(() => {}) // Never resolves
    );

    const { container } = render(<Settings />, { wrapper: createTestWrapper() });

    // The page heading and the sidebar NavLink both render "Settings",
    // so we can't rely on getByText. The loading branch in Settings.tsx
    // wraps a <SettingsSkeleton /> inside <Layout title="Settings" ...>;
    // the skeleton is just a stack of animate-pulse blocks. Confirm a
    // skeleton is rendered (no other render path on this page uses
    // animate-pulse outside of mutation loading states).
    expect(container.querySelector('.animate-pulse')).toBeInTheDocument();
  });

  it('shows error state when settings fetch fails', async () => {
    (settingsApi.get as ReturnType<typeof vi.fn>).mockRejectedValue(
      new Error('Failed to load settings')
    );

    render(<Settings />, { wrapper: createTestWrapper() });

    await waitFor(() => {
      expect(screen.getByText('Failed to load settings')).toBeInTheDocument();
    });

    expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument();
  });

  // A "not connected" Settings payload — every test reuses this shape and
  // overrides only what it needs. Matches the current Settings type:
  //   { hub: HubStatus, peanut_suite: PeanutSuiteInfo | null }
  const notConnectedSettings = {
    hub: {
      connected: false,
      url: '',
      api_key_set: false,
      last_sync: null as string | null,
      mode: 'standard' as const,
      tracking_enabled: false,
      track_logged_in: false,
    },
    peanut_suite: null,
  };

  it('shows not connected state when no hub is connected', async () => {
    (settingsApi.get as ReturnType<typeof vi.fn>).mockResolvedValue(notConnectedSettings);

    render(<Settings />, { wrapper: createTestWrapper() });

    await waitFor(() => {
      expect(screen.getByText('Not Connected')).toBeInTheDocument();
    }, { timeout: 3000 });

    expect(screen.getByText('Not Connected to Hub')).toBeInTheDocument();
  });

  it('shows connected state when connected', async () => {
    (settingsApi.get as ReturnType<typeof vi.fn>).mockResolvedValue({
      ...notConnectedSettings,
      hub: {
        ...notConnectedSettings.hub,
        connected: true,
        url: 'https://hub.example.com',
        api_key_set: true,
        last_sync: new Date().toISOString(),
      },
    });

    render(<Settings />, { wrapper: createTestWrapper() });

    await waitFor(() => {
      expect(screen.getByText('Connected to Hub')).toBeInTheDocument();
    });

    // Status badge in the card header
    expect(screen.getByText('Connected')).toBeInTheDocument();
    // The hub URL is rendered inline under "Connected to Hub"
    expect(screen.getByText('https://hub.example.com')).toBeInTheDocument();
  });

  it('shows Peanut Suite block when installed', async () => {
    (settingsApi.get as ReturnType<typeof vi.fn>).mockResolvedValue({
      ...notConnectedSettings,
      hub: { ...notConnectedSettings.hub, connected: true, url: 'https://hub.example.com', api_key_set: true },
      peanut_suite: {
        installed: true,
        version: '4.2.0',
        modules: ['links', 'contacts', 'utm'],
      },
    });

    render(<Settings />, { wrapper: createTestWrapper() });

    await waitFor(() => {
      // Heading is "Peanut Suite v{version}" — no longer says "Detected"
      expect(screen.getByText(/Peanut Suite v4\.2\.0/)).toBeInTheDocument();
    });

    // Active modules render as Badge components
    expect(screen.getByText('Active Modules')).toBeInTheDocument();
    expect(screen.getByText('links')).toBeInTheDocument();
    expect(screen.getByText('contacts')).toBeInTheDocument();
    expect(screen.getByText('utm')).toBeInTheDocument();
  });

  it('does not render Peanut Suite block when not installed', async () => {
    (settingsApi.get as ReturnType<typeof vi.fn>).mockResolvedValue({
      ...notConnectedSettings,
      hub: { ...notConnectedSettings.hub, connected: true, url: 'https://hub.example.com', api_key_set: true },
      peanut_suite: null,
    });

    render(<Settings />, { wrapper: createTestWrapper() });

    // Wait for the page to settle (Hub card renders)
    await waitFor(() => {
      expect(screen.getByText('Connected to Hub')).toBeInTheDocument();
    });

    // Peanut Suite section is conditionally rendered only when peanut_suite is
    // truthy. With peanut_suite=null, nothing related to Peanut Suite should
    // appear in the connected-hub card. (Note: "Hide Suite Menu" and similar
    // labels exist in the Hub-mode picker, so we look specifically for the
    // detection-block phrasing.)
    expect(screen.queryByText(/Peanut Suite v/)).not.toBeInTheDocument();
    expect(screen.queryByText('Active Modules')).not.toBeInTheDocument();
  });

  it('shows the Disconnect from Hub panel when connected', async () => {
    (settingsApi.get as ReturnType<typeof vi.fn>).mockResolvedValue({
      ...notConnectedSettings,
      hub: { ...notConnectedSettings.hub, connected: true, url: 'https://hub.example.com', api_key_set: true },
    });

    render(<Settings />, { wrapper: createTestWrapper() });

    // Disconnect panel lives inside the Hub Connection card; only renders when connected.
    await waitFor(() => {
      expect(screen.getByText('Disconnect from Hub')).toBeInTheDocument();
    });

    // The Disconnect button itself
    expect(screen.getByRole('button', { name: /^disconnect$/i })).toBeInTheDocument();
  });
});
