import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { AnalyticsTrendSparkline } from '@/components/report/AnalyticsTrendSparkline';

const threeMonths = [
  { period_start: '2026-04-01', sessions: 400 },
  { period_start: '2026-05-01', sessions: 500 },
  { period_start: '2026-06-01', sessions: 980 },
];

describe('AnalyticsTrendSparkline', () => {
  it('renders one polyline for a >=2-point sessions history', () => {
    const { container } = render(<AnalyticsTrendSparkline history={threeMonths} />);
    expect(container.querySelectorAll('polyline')).toHaveLength(1);
    expect(container.querySelector('polyline')?.getAttribute('stroke')).toBe('#2563eb');
  });

  it('renders nothing when fewer than 2 non-null sessions', () => {
    const { container } = render(
      <AnalyticsTrendSparkline history={[{ period_start: '2026-06-01', sessions: 980 }]} />,
    );
    expect(container.firstChild).toBeNull();
  });

  it('renders nothing when all sessions are zero (max <= 0)', () => {
    const { container } = render(
      <AnalyticsTrendSparkline
        history={[{ period_start: '2026-05-01', sessions: 0 }, { period_start: '2026-06-01', sessions: 0 }]}
      />,
    );
    expect(container.firstChild).toBeNull();
  });
});
