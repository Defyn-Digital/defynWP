import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { MonitoringTable } from '@/components/monitoring/MonitoringTable';
import type { MonitoringSite } from '@/types/api';

const site = (over: Partial<MonitoringSite>): MonitoringSite => ({
  site_id: 1, label: 'Acme', url: 'https://acme.test', status: 'active',
  last_response_time_ms: 247, last_contact_at: '2026-06-14 03:00:00',
  uptime_7d: 100, uptime_30d: 99.82, open_incident_started_at: null, ...over,
});

describe('MonitoringRow', () => {
  it('renders latency and links to site detail', () => {
    render(<MemoryRouter><MonitoringTable sites={[site({})]} /></MemoryRouter>);
    expect(screen.getByText('247ms')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Acme/ })).toHaveAttribute('href', '/sites/1');
  });

  it('shows "down Xm" for an open incident and — for null latency', () => {
    const now = new Date();
    const started = new Date(now.getTime() - 12 * 60000).toISOString().slice(0, 19).replace('T', ' ');
    render(<MemoryRouter><MonitoringTable sites={[site({ status: 'offline', last_response_time_ms: null, open_incident_started_at: started })]} /></MemoryRouter>);
    expect(screen.getByText('—')).toBeInTheDocument();
    expect(screen.getByText(/down 12m/)).toBeInTheDocument();
  });
});
