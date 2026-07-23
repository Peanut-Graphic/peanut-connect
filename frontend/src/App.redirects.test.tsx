import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route, Navigate } from 'react-router-dom';
import { RedirectJourneyDetail } from './App';

function harness(initialPath: string) {
  return render(
    <MemoryRouter initialEntries={[initialPath]}>
      <Routes>
        <Route path="/journeys" element={<Navigate to="/analytics/journeys" replace />} />
        <Route path="/journeys/:clickId" element={<RedirectJourneyDetail />} />
        <Route path="/analytics/journeys" element={<div>journeys list</div>} />
        <Route path="/analytics/journeys/:clickId" element={<div>journey detail</div>} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('legacy /journeys redirects', () => {
  it('redirects /journeys to the analytics journeys list', () => {
    harness('/journeys');
    expect(screen.getByText('journeys list')).toBeInTheDocument();
  });

  it('redirects /journeys/:clickId preserving the id', () => {
    harness('/journeys/abc123');
    expect(screen.getByText('journey detail')).toBeInTheDocument();
  });
});
