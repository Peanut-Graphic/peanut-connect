import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { HourOfDayChart } from './HourOfDayChart';

const full = Array.from({ length: 24 }, (_, h) => ({ hour: h, journeys: h === 9 ? 40 : h === 18 ? 20 : 0 }));

describe('HourOfDayChart', () => {
  it('renders 24 hour bars', () => {
    render(<HourOfDayChart data={full} />);
    for (let h = 0; h < 24; h++) {
      expect(screen.getByTestId(`hour-${h}`)).toBeInTheDocument();
    }
  });

  it('gives the peak hour the tallest bar and empty hours zero height', () => {
    render(<HourOfDayChart data={full} />);
    expect(screen.getByTestId('hour-9').style.height).toBe('100%');
    expect(screen.getByTestId('hour-0').style.height).toBe('0%');
  });

  it('shows an empty state when there are no journeys', () => {
    render(<HourOfDayChart data={Array.from({ length: 24 }, (_, h) => ({ hour: h, journeys: 0 }))} />);
    expect(screen.getByText('No time-of-day data yet.')).toBeInTheDocument();
  });
});
