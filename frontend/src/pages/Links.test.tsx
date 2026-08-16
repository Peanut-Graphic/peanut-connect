import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { ToastProvider } from '@/components/common/Toast';
import Links from './Links';

const listLinks = vi.fn();
vi.mock('@/api', () => ({
  getVersion: () => '0.0.0-test',
  marketingApi: {
    listLinks: (...a: any[]) => listLinks(...a),
    toggleLink: vi.fn(),
    deleteLink: vi.fn(),
  },
}));

function wrap() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <ToastProvider>
        <MemoryRouter>
          <Links />
        </MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>,
  );
}

const link = (over: Record<string, unknown>) => ({
  id: 1, agency_id: 1, site_id: 1, utm_id: 1, slug: 'x', destination_url: 'https://x.com',
  title: null, description: null, is_active: true, click_count: 0, expires_at: null,
  created_at: '', updated_at: '', ...over,
});

describe('Links — click-to-call badge', () => {
  beforeEach(() => listLinks.mockReset());

  it('shows a Call badge only on click-to-call links', async () => {
    listLinks.mockResolvedValue({
      data: [
        link({ id: 1, slug: 'callme', destination_url: 'tel:+15551234567', is_call: true }),
        link({ id: 2, slug: 'webpage', destination_url: 'https://example.com', is_call: false }),
      ],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 2 },
    });

    wrap();

    await waitFor(() => expect(screen.getByText('/callme')).toBeInTheDocument());
    // Exactly one "Call" badge — on the tel: link, not the web link.
    const badges = screen.getAllByText('Call');
    expect(badges).toHaveLength(1);
  });
});
