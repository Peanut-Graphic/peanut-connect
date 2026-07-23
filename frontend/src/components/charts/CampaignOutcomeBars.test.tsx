import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { CampaignOutcomeBars } from './CampaignOutcomeBars';

const data = [
  { campaign: 'DOME2620RS3', converted: 3, in_progress: 8, abandoned: 89 },
  { campaign: 'USA_Display', converted: 0, in_progress: 2, abandoned: 40 },
];

describe('CampaignOutcomeBars', () => {
  it('renders one labeled bar per campaign, sorted by total volume desc', () => {
    render(<CampaignOutcomeBars data={data} />);
    const labels = screen.getAllByTestId('outcome-bar-campaign').map((n) => n.textContent);
    expect(labels).toEqual(['DOME2620RS3', 'USA_Display']);
  });

  it('renders all three outcome segments', () => {
    render(<CampaignOutcomeBars data={data} />);
    expect(screen.getByTestId('seg-DOME2620RS3-converted')).toBeInTheDocument();
    expect(screen.getByTestId('seg-DOME2620RS3-in_progress')).toBeInTheDocument();
    expect(screen.getByTestId('seg-DOME2620RS3-abandoned')).toBeInTheDocument();
  });

  it('calls onSegmentClick with the campaign + outcome when a segment is clicked', () => {
    const onSegmentClick = vi.fn();
    render(<CampaignOutcomeBars data={data} onSegmentClick={onSegmentClick} />);
    fireEvent.click(screen.getByTestId('seg-DOME2620RS3-in_progress'));
    expect(onSegmentClick).toHaveBeenCalledWith({ campaign: 'DOME2620RS3', outcome: 'in_progress' });
  });

  it('calls onCampaignClick when the bar label is clicked', () => {
    const onCampaignClick = vi.fn();
    render(<CampaignOutcomeBars data={data} onCampaignClick={onCampaignClick} />);
    fireEvent.click(screen.getByTestId('outcome-bar-campaign-USA_Display'));
    expect(onCampaignClick).toHaveBeenCalledWith('USA_Display');
  });

  it('renders an empty state when there are no campaigns', () => {
    render(<CampaignOutcomeBars data={[]} />);
    expect(screen.getByText('No campaign data in this window.')).toBeInTheDocument();
  });
});
