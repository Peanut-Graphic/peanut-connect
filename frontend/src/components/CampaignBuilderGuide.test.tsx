import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import CampaignBuilderGuide from './CampaignBuilderGuide';

describe('CampaignBuilderGuide', () => {
  it('renders the four builder steps and the field reference', () => {
    render(<CampaignBuilderGuide />);
    expect(screen.getByText(/Step 1 — Describe the campaign/i)).toBeInTheDocument();
    expect(screen.getByText(/Step 2 — Get your short link/i)).toBeInTheDocument();
    expect(screen.getByText(/Step 3 — Tracking/i)).toBeInTheDocument();
    expect(screen.getByText(/Step 4 — Grab your link and QR code/i)).toBeInTheDocument();
    // field reference explains the utm codes
    expect(screen.getByText('utm_campaign')).toBeInTheDocument();
    expect(screen.getByText('utm_source')).toBeInTheDocument();
    // QR download options are documented
    expect(screen.getByText(/Download PNG/i)).toBeInTheDocument();
    expect(screen.getByText(/Download SVG/i)).toBeInTheDocument();
  });

  it('documents the click-to-call option', () => {
    render(<CampaignBuilderGuide />);
    expect(screen.getByText(/Making a click-to-call link/i)).toBeInTheDocument();
    expect(screen.getAllByText(/Phone number/i).length).toBeGreaterThan(0);
    expect(screen.getByText(/Taps and scans are still counted/i)).toBeInTheDocument();
  });

  it('points the login step at the dedicated /itron-login entry (not raw /wp-admin)', () => {
    render(<CampaignBuilderGuide />);
    expect(screen.getByText(/www\.dominionenergyptr\.com\/itron-login/)).toBeInTheDocument();
    expect(screen.queryByText(/wp-admin/)).not.toBeInTheDocument();
  });

  it('shows the login steps by default and hides them when showLogin is false', () => {
    const { rerender } = render(<CampaignBuilderGuide />);
    expect(screen.getByText(/Logging in/i)).toBeInTheDocument();
    expect(screen.getByText(/Open the builder/i)).toBeInTheDocument();

    rerender(<CampaignBuilderGuide showLogin={false} />);
    expect(screen.queryByText(/Logging in/i)).not.toBeInTheDocument();
    // the actual builder steps still render
    expect(screen.getByText(/Step 1 — Describe the campaign/i)).toBeInTheDocument();
  });
});
