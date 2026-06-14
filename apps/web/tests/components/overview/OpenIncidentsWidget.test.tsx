import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { OpenIncidentsWidget } from '@/components/overview/OpenIncidentsWidget';

describe('OpenIncidentsWidget', () => {
  it('renders a red rollup listing each open incident', () => {
    render(<OpenIncidentsWidget openIncidents={[{ site_id: 2, site_label: 'AcmeBlog', started_at: '2026-06-14 10:00:00' }]} />);
    expect(screen.getByText(/1 site down/i)).toBeInTheDocument();
    expect(screen.getByText(/AcmeBlog/)).toBeInTheDocument();
  });
  it('renders TWO sites with plural wording', () => {
    render(<OpenIncidentsWidget openIncidents={[
      { site_id: 2, site_label: 'AcmeBlog', started_at: '2026-06-14 10:00:00' },
      { site_id: 3, site_label: 'SmartCoding', started_at: '2026-06-14 10:05:00' },
    ]} />);
    expect(screen.getByText(/2 sites down/i)).toBeInTheDocument();
  });
  it('renders nothing when there are no open incidents (guardrail 8)', () => {
    const { container } = render(<OpenIncidentsWidget openIncidents={[]} />);
    expect(container).toBeEmptyDOMElement();
  });
});
