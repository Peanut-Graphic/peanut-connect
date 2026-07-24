import { describe, it, expect, vi, afterEach } from 'vitest';
import { render } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { ToastProvider } from '@/components/common/Toast';
import Campaigns from './Campaigns';

const trackingSetup = vi.fn().mockResolvedValue({ connected: true });
vi.mock('@/api', () => ({
  getVersion: () => '0.0.0-test',
  marketingApi: {
    trackingSetup: (...a: any[]) => trackingSetup(...a),
    buildCampaign: vi.fn(),
  },
}));

function wrap() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <ToastProvider>
        <MemoryRouter>
          <Campaigns />
        </MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>,
  );
}

afterEach(() => {
  (window as any).peanutConnect = undefined;
  trackingSetup.mockClear();
});

describe('Campaigns wizard — builder mode', () => {
  it('does NOT call the admin-only tracking-setup endpoint in builder mode', async () => {
    (window as any).peanutConnect = { mode: 'builder' };
    wrap();
    // Give react-query a tick; the query must be disabled, so no call at all.
    await new Promise((r) => setTimeout(r, 0));
    expect(trackingSetup).not.toHaveBeenCalled();
  });

  it('DOES call tracking-setup in full (admin) mode', async () => {
    (window as any).peanutConnect = { mode: 'full' };
    wrap();
    await new Promise((r) => setTimeout(r, 0));
    expect(trackingSetup).toHaveBeenCalled();
  });
});
