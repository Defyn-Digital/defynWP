import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ReportPerformance } from '@/components/report/ReportPerformance';
import type { ReportPerformanceData } from '@/types/api';

function populatedPerformance(): ReportPerformanceData {
  return {
    latest: {
      fetched_at: '2026-06-15 03:00:00',
      mobile: { score: 82, lcp_ms: 2100, cls: 0.14, inp_ms: 180 },
      desktop: { score: 96, lcp_ms: 1200, cls: 0.02, inp_ms: 90 },
    },
    history: [
      { fetched_at: '2026-06-08 03:00:00', mobile_score: 78, desktop_score: 94 },
      { fetched_at: '2026-06-15 03:00:00', mobile_score: 82, desktop_score: 96 },
    ],
  };
}

function emptyPerformance(): ReportPerformanceData {
  return { latest: null, history: [] };
}

describe('ReportPerformance', () => {
  it('renders mobile + desktop scores and a CWV rating label', () => {
    render(<ReportPerformance performance={populatedPerformance()} />);
    // 82 / 96 appear in both the score blocks and the trend table — assert at least one each.
    expect(screen.getAllByText('82').length).toBeGreaterThan(0);
    expect(screen.getAllByText('96').length).toBeGreaterThan(0);
    // cls 0.14 → needs-improvement → "Needs work"
    expect(screen.getByText(/Needs/i)).toBeInTheDocument();
  });

  it('renders a not-yet-measured empty state', () => {
    render(<ReportPerformance performance={emptyPerformance()} />);
    expect(screen.getByText(/Not yet measured/i)).toBeInTheDocument();
  });

  it('renders the trend sparkline above the weekly history table', () => {
    const performance = {
      latest: {
        fetched_at: '2026-06-16 03:00:00',
        mobile: { score: 58, lcp_ms: 4600, cls: 0.1, inp_ms: 250 },
        desktop: { score: 91, lcp_ms: 2400, cls: 0.05, inp_ms: 120 },
      },
      history: [
        { fetched_at: '2026-05-19 03:00:00', mobile_score: 48, desktop_score: 88 },
        { fetched_at: '2026-06-16 03:00:00', mobile_score: 58, desktop_score: 91 },
      ],
    };
    const { container, getByText } = render(<ReportPerformance performance={performance} />);
    expect(container.querySelector('svg')).not.toBeNull(); // sparkline present
    expect(container.querySelectorAll('polyline')).toHaveLength(2);
    expect(getByText('Trend')).toBeInTheDocument(); // existing table heading still there
  });
});
