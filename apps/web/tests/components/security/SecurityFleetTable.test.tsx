import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { SecurityFleetTable } from '@/components/security/SecurityFleetTable';
import type { FleetSiteSecurity } from '@/types/api';

const sites: FleetSiteSecurity[] = [
  { site_id: 7, label: 'AcmeBlog', url: 'https://acme.test', last_security_scan_at: '2026-06-15 02:00:00',
    counts: { critical: 1, high: 2, medium: 0, low: 0, total: 3 } },
  { site_id: 8, label: 'CleanCo', url: 'https://clean.test', last_security_scan_at: '2026-06-15 02:00:00',
    counts: { critical: 0, high: 0, medium: 0, low: 0, total: 0 } },
  { site_id: 9, label: 'NeverScanned', url: 'https://never.test', last_security_scan_at: null,
    counts: { critical: 0, high: 0, medium: 0, low: 0, total: 0 } },
];

function renderTable() {
  render(<MemoryRouter><SecurityFleetTable sites={sites} /></MemoryRouter>);
}

describe('SecurityFleetTable', () => {
  it('renders the at-risk site with a critical-count chip', () => {
    renderTable();
    expect(screen.getByText('AcmeBlog')).toBeInTheDocument();
    expect(screen.getByText(/1 crit/i)).toBeInTheDocument();
    expect(screen.getByText(/2 high/i)).toBeInTheDocument();
  });

  it('renders a clean chip for a scanned site with zero findings', () => {
    renderTable();
    expect(screen.getByText('CleanCo')).toBeInTheDocument();
    expect(screen.getByText(/no known vulnerabilities/i)).toBeInTheDocument();
  });

  it('renders "not yet scanned" for a never-scanned site', () => {
    renderTable();
    expect(screen.getByText('NeverScanned')).toBeInTheDocument();
    expect(screen.getByText(/not yet scanned/i)).toBeInTheDocument();
  });

  it('links each row to the site detail page', () => {
    renderTable();
    expect(screen.getByRole('link', { name: /AcmeBlog/ })).toHaveAttribute('href', '/sites/7');
  });
});
