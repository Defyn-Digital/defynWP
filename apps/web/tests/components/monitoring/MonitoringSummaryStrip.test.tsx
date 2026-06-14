import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MonitoringSummaryStrip } from '@/components/monitoring/MonitoringSummaryStrip';

describe('MonitoringSummaryStrip', () => {
  it('renders KPIs and dashes nulls', () => {
    render(<MonitoringSummaryStrip summary={{ total: 3, up: 2, down: 0, fleet_uptime_30d: null, slowest_ms: null }} />);
    expect(screen.getByText('Fleet 30d').parentElement).toHaveTextContent('—');
    expect(screen.getByText('Up').parentElement).toHaveTextContent('2');
  });

  it('marks the down tile red when down > 0', () => {
    render(<MonitoringSummaryStrip summary={{ total: 3, up: 2, down: 1, fleet_uptime_30d: 99.7, slowest_ms: 900 }} />);
    expect(screen.getByTestId('kpi-down')).toHaveClass('text-red-600');
  });
});
