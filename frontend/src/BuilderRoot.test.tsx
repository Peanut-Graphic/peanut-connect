import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import BuilderRoot from './BuilderRoot';

vi.mock('@/api', () => ({
  getVersion: () => '0.0.0-test',
  marketingApi: {
    trackingSetup: vi.fn().mockResolvedValue({ ready: false }),
    buildCampaign: vi.fn(),
  },
}));

afterEach(() => {
  (window as any).peanutConnect = undefined;
});

function renderBuilder() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <BuilderRoot />
    </QueryClientProvider>,
  );
}

describe('BuilderRoot', () => {
  it('renders the campaign/UTM wizard and no sidebar in builder mode', () => {
    (window as any).peanutConnect = { mode: 'builder' };
    renderBuilder();
    // Campaigns wizard heading (its Layout title) is present…
    expect(screen.getByRole('heading', { name: 'Build a Campaign' })).toBeInTheDocument();
    // …and the grouped sidebar is not (Layout hides it in builder mode).
    expect(screen.queryByRole('heading', { name: 'Performance' })).toBeNull();
  });
});
