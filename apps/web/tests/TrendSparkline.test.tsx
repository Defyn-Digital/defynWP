import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { TrendSparkline } from '@/components/report/TrendSparkline';

const twoDevice = [
  { fetched_at: '2026-05-19 03:00:00', mobile_score: 48, desktop_score: 88 },
  { fetched_at: '2026-06-16 03:00:00', mobile_score: 58, desktop_score: 91 },
];

describe('TrendSparkline', () => {
  it('renders two polylines for a two-device ≥2-point history', () => {
    const { container } = render(<TrendSparkline history={twoDevice} />);
    expect(container.querySelectorAll('polyline')).toHaveLength(2);
  });

  it('renders nothing when fewer than 2 points', () => {
    const { container } = render(
      <TrendSparkline history={[{ fetched_at: 'x', mobile_score: 58, desktop_score: 91 }]} />,
    );
    expect(container.firstChild).toBeNull();
  });

  it('renders only the populated series when one device is all-null', () => {
    const { container } = render(
      <TrendSparkline
        history={[
          { fetched_at: 'a', mobile_score: 40, desktop_score: null },
          { fetched_at: 'b', mobile_score: 60, desktop_score: null },
        ]}
      />,
    );
    expect(container.querySelectorAll('polyline')).toHaveLength(1);
  });
});
