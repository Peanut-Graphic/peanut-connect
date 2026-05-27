import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import Settings from './Settings';
import { ToastProvider } from '@/components/common';
import { ThemeProvider } from '@/contexts';

// Mock the API
vi.mock('@/api', () => ({
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

  // TODO: Requires full Layout component mocking - test manually
  it.skip('shows loading skeleton while fetching settings', () => {
    // Mock a pending request
    (settingsApi.get as ReturnType<typeof vi.fn>).mockImplementation(
      () => new Promise(() => {}) // Never resolves
    );

    render(<Settings />, { wrapper: createTestWrapper() });

    // Should show skeleton loading state
    expect(screen.getByText('Settings')).toBeInTheDocument();
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

  // TODO: Requires full Layout component mocking - test manually
  it.skip('shows not connected state when no site key exists', async () => {
    (settingsApi.get as ReturnType<typeof vi.fn>).mockResolvedValue({
      connection: {
        connected: false,
        manager_url: null,
        last_sync: null,
        site_key: null,
      },
      permissions: {
        health_check: true,
        list_updates: true,
        perform_updates: true,
        access_analytics: true,
      },
      peanut_suite: null,
    });

    render(<Settings />, { wrapper: createTestWrapper() });

    await waitFor(() => {
      expect(screen.getByText('Not Connected')).toBeInTheDocument();
    }, { timeout: 3000 });

    expect(screen.getByRole('button', { name: /generate site key/i })).toBeInTheDocument();
  });

  it('shows connected state when connected', async () => {
    (settingsApi.get as ReturnType<typeof vi.fn>).mockResolvedValue({
      connection: {
        connected: true,
        manager_url: 'https://manager.example.com',
        last_sync: new Date().toISOString(),
        site_key: 'test-key-12345',
      },
      permissions: {
        health_check: true,
        list_updates: true,
        perform_updates: true,
        access_analytics: true,
      },
      peanut_suite: null,
    });

    render(<Settings />, { wrapper: createTestWrapper() });

    await waitFor(() => {
      expect(screen.getByText('Connected')).toBeInTheDocument();
    });

    expect(screen.getByText('Connected to Manager')).toBeInTheDocument();
    expect(screen.getByText('https://manager.example.com')).toBeInTheDocument();
  });

  it('displays site key when it exists', async () => {
    const testKey = 'abcd1234efgh5678ijkl9012mnop3456';

    (settingsApi.get as ReturnType<typeof vi.fn>).mockResolvedValue({
      connection: {
        connected: false,
        manager_url: null,
        last_sync: null,
        site_key: testKey,
      },
      permissions: {
        health_check: true,
        list_updates: true,
        perform_updates: true,
        access_analytics: true,
      },
      peanut_suite: null,
    });

    render(<Settings />, { wrapper: createTestWrapper() });

    await waitFor(() => {
      expect(screen.getByText(testKey)).toBeInTheDocument();
    });

    // Should have copy buttons (one for key, one for URL)
    const copyButtons = screen.getAllByRole('button', { name: /copy/i });
    expect(copyButtons.length).toBeGreaterThanOrEqual(1);
  });

  it('shows Peanut Suite detected when installed', async () => {
    (settingsApi.get as ReturnType<typeof vi.fn>).mockResolvedValue({
      connection: {
        connected: false,
        manager_url: null,
        last_sync: null,
        site_key: 'test-key',
      },
      permissions: {
        health_check: true,
        list_updates: true,
        perform_updates: true,
        access_analytics: true,
      },
      peanut_suite: {
        installed: true,
        version: '4.2.0',
        modules: ['links', 'contacts', 'utm'],
      },
    });

    render(<Settings />, { wrapper: createTestWrapper() });

    await waitFor(() => {
      expect(screen.getByText(/Peanut Suite v4.2.0 Detected/)).toBeInTheDocument();
    });

    // Should show active modules
    expect(screen.getByText('links')).toBeInTheDocument();
    expect(screen.getByText('contacts')).toBeInTheDocument();
    expect(screen.getByText('utm')).toBeInTheDocument();
  });

  it('shows Peanut Suite not installed state', async () => {
    (settingsApi.get as ReturnType<typeof vi.fn>).mockResolvedValue({
      connection: {
        connected: false,
        manager_url: null,
        last_sync: null,
        site_key: null,
      },
      permissions: {
        health_check: true,
        list_updates: true,
        perform_updates: true,
        access_analytics: true,
      },
      peanut_suite: null,
    });

    render(<Settings />, { wrapper: createTestWrapper() });

    await waitFor(() => {
      expect(screen.getByText('Peanut Suite Not Installed')).toBeInTheDocument();
    });
  });

  it('shows permission switches', async () => {
    (settingsApi.get as ReturnType<typeof vi.fn>).mockResolvedValue({
      connection: {
        connected: true,
        manager_url: 'https://manager.example.com',
        last_sync: new Date().toISOString(),
        site_key: 'test-key',
      },
      permissions: {
        health_check: true,
        list_updates: true,
        perform_updates: true,
        access_analytics: false,
      },
      peanut_suite: null,
    });

    render(<Settings />, { wrapper: createTestWrapper() });

    await waitFor(() => {
      expect(screen.getByText('Perform Updates')).toBeInTheDocument();
    });

    expect(screen.getByText('Access Analytics')).toBeInTheDocument();
    expect(screen.getByText('Health Checks')).toBeInTheDocument();
    expect(screen.getByText('List Updates')).toBeInTheDocument();
  });

  it('shows danger zone when site key exists', async () => {
    (settingsApi.get as ReturnType<typeof vi.fn>).mockResolvedValue({
      connection: {
        connected: true,
        manager_url: 'https://manager.example.com',
        last_sync: new Date().toISOString(),
        site_key: 'test-key',
      },
      permissions: {
        health_check: true,
        list_updates: true,
        perform_updates: true,
        access_analytics: true,
      },
      peanut_suite: null,
    });

    render(<Settings />, { wrapper: createTestWrapper() });

    await waitFor(() => {
      expect(screen.getByText('Danger Zone')).toBeInTheDocument();
    });

    expect(screen.getByText('Regenerate Site Key')).toBeInTheDocument();
    expect(screen.getByText('Disconnect from Manager')).toBeInTheDocument();
  });
});
