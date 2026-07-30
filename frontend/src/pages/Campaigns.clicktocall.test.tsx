import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { ToastProvider } from '@/components/common/Toast';
import Campaigns, { toTelUrl, isValidPhone } from './Campaigns';

vi.mock('@/api', () => ({
  getVersion: () => '0.0.0-test',
  marketingApi: {
    trackingSetup: vi.fn().mockResolvedValue({ connected: true }),
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
});

describe('click-to-call helpers', () => {
  it('normalizes a typed number to a tel: URL, preserving a leading +', () => {
    expect(toTelUrl('+1 (555) 123-4567')).toBe('tel:+15551234567');
    expect(toTelUrl('555 123 4567')).toBe('tel:5551234567');
  });

  it('accepts 7–15 digit numbers and rejects the rest', () => {
    expect(isValidPhone('+1 555 123 4567')).toBe(true);
    expect(isValidPhone('123456')).toBe(false); // too short
    expect(isValidPhone('')).toBe(false);
  });
});

describe('Campaigns wizard — click-to-call toggle', () => {
  it('swaps the Destination URL field for a Phone number field when checked', () => {
    (window as any).peanutConnect = { mode: 'builder' };
    wrap();

    // Default: a web destination (matched by its placeholder).
    expect(screen.getByPlaceholderText('https://example.com/landing')).toBeInTheDocument();
    expect(screen.queryByPlaceholderText('+1 555 123 4567')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('checkbox'));

    // Now: a phone number, and the URL field is gone.
    expect(screen.getByPlaceholderText('+1 555 123 4567')).toBeInTheDocument();
    expect(screen.queryByPlaceholderText('https://example.com/landing')).not.toBeInTheDocument();
  });
});
