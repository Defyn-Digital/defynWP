import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ReportAnalytics } from '@/components/report/ReportAnalytics';
import type { ReportAnalyticsData } from '@/types/api';

function readyAnalytics(): ReportAnalyticsData {
  return {
    state: 'ready',
    period: { start: '2026-06-01', end: '2026-06-30' },
    totals: { sessions: 12480, users: 9300, pageviews: 41200, avg_engagement_seconds: 108 },
    top_pages: [{ path: '/', title: 'Home', views: 8000 }],
    channels: [{ channel: 'Organic Search', sessions: 7200 }],
    history: [],
  };
}

function notConnectedAnalytics(): ReportAnalyticsData {
  return { state: 'not_connected', period: null, totals: null, top_pages: [], channels: [], history: [] };
}

function pendingAnalytics(): ReportAnalyticsData {
  return { state: 'pending', period: null, totals: null, top_pages: [], channels: [], history: [] };
}

describe('ReportAnalytics', () => {
  it('renders KPIs, a top page, a channel and formatted engagement when ready', () => {
    render(<ReportAnalytics analytics={readyAnalytics()} />);
    // sessions 12480 → "12,480" via toLocaleString (could appear more than once).
    expect(screen.getAllByText(/12,480|12480/).length).toBeGreaterThan(0);
    expect(screen.getByText('Home')).toBeInTheDocument();
    expect(screen.getByText('Organic Search')).toBeInTheDocument();
    // avg_engagement_seconds 108 → "1m 48s"
    expect(screen.getByText('1m 48s')).toBeInTheDocument();
  });

  it('renders a not-connected empty state', () => {
    render(<ReportAnalytics analytics={notConnectedAnalytics()} />);
    expect(screen.getByText(/Analytics not connected/i)).toBeInTheDocument();
  });

  it('renders a pending (not yet available) state', () => {
    render(<ReportAnalytics analytics={pendingAnalytics()} />);
    expect(screen.getByText(/not yet available/i)).toBeInTheDocument();
  });

  it('renders the sessions sparkline in the ready state with history', () => {
    const analytics: ReportAnalyticsData = {
      ...readyAnalytics(),
      history: [
        { period_start: '2026-05-01', sessions: 500 },
        { period_start: '2026-06-01', sessions: 980 },
      ],
    };
    const { container } = render(<ReportAnalytics analytics={analytics} />);
    expect(container.querySelector('polyline')).not.toBeNull(); // sparkline present
    expect(screen.getByText('Channels')).toBeInTheDocument(); // existing tables still render
  });
});
