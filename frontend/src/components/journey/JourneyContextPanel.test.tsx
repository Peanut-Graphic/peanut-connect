import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { JourneyContextPanel } from './JourneyContextPanel';

const events = [
  {
    id: 1, event_type: 'pageview', event_name: null, page_url: 'x', page_title: null,
    event_at: '2026-07-20T10:02:14Z', device_type: 'mobile', browser: 'Safari', os: 'iOS',
    country: 'US', region: 'VA', referrer: 'https://ad.example', event_data: { depth: 40 },
  },
  {
    id: 2, event_type: 'scroll_depth', event_name: 'scroll_depth', page_url: 'x', page_title: null,
    event_at: '2026-07-20T10:03:00Z', event_data: { depth: 80 },
  },
  {
    id: 3, event_type: 'exit_intent', event_name: 'exit_intent', page_url: 'x', page_title: null,
    event_at: '2026-07-20T10:04:00Z',
  },
] as any;

describe('JourneyContextPanel', () => {
  it('surfaces device/geo/referrer/engagement from the events', () => {
    render(<JourneyContextPanel events={events} journey={{ pages_viewed: 4, duration_seconds: 356 }} />);
    expect(screen.getByText('Mobile · Safari · iOS')).toBeInTheDocument();
    expect(screen.getByText('US · VA')).toBeInTheDocument();
    expect(screen.getByText('ad.example')).toBeInTheDocument();
    expect(screen.getByText('80%')).toBeInTheDocument();
    expect(screen.getByText(/Yes — showed exit intent/i)).toBeInTheDocument();
    expect(screen.getByText('5m 56s')).toBeInTheDocument();
  });

  it('renders — for fields absent from the events', () => {
    render(<JourneyContextPanel events={[]} journey={{ pages_viewed: null, duration_seconds: null }} />);
    expect(screen.getAllByText('—').length).toBeGreaterThan(0);
  });

  it('coerces a numeric-string scroll depth (sync path stores depth as a string)', () => {
    const stringDepth = [
      {
        id: 1, event_type: 'event', event_name: null, page_url: 'x', page_title: null,
        event_at: '2026-07-22T12:00:00Z', event_data: { depth: '25' },
      },
    ] as any;
    render(<JourneyContextPanel events={stringDepth} journey={{ pages_viewed: 2, duration_seconds: 262 }} />);
    // Was "—" before the fix because typeof "25" !== 'number'.
    expect(screen.getByText('25%')).toBeInTheDocument();
  });
});
