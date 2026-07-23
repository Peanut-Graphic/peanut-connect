import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { Funnel } from './Funnel';

const stages = [
  { stage: 'landed', label: 'Landed', count: 100 },
  { stage: 'entered', label: 'Entered', count: 20 },
];

describe('Funnel', () => {
  it('renders stage labels', () => {
    render(<Funnel stages={stages} />);
    // Labels appear in both the legend and the bar list; getAllByText tolerates that.
    expect(screen.getAllByText('Landed').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Entered').length).toBeGreaterThan(0);
  });

  it('renders no buttons when onStageClick is absent', () => {
    render(<Funnel stages={stages} />);
    expect(screen.queryByRole('button')).toBeNull();
  });

  it('calls onStageClick with the stage id when a stage is clicked', () => {
    const onStageClick = vi.fn();
    render(<Funnel stages={stages} onStageClick={onStageClick} />);
    fireEvent.click(screen.getByRole('button', { name: /Entered/i }));
    expect(onStageClick).toHaveBeenCalledWith('entered');
  });

  it('renders the empty state for no stages', () => {
    render(<Funnel stages={[]} />);
    expect(screen.getByText('No funnel data.')).toBeInTheDocument();
  });
});
