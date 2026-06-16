import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { SiteReport as Report } from '@/types/api';
import SiteReport from '@/pages/SiteReport';
import { useSiteReport } from '@/lib/queries/useSiteReport';

// Mock the data hook so the page can be driven with deterministic fixtures.
vi.mock('@/lib/queries/useSiteReport');

const mockedUseSiteReport = vi.mocked(useSiteReport);

function populatedReport(): Report {
  return {
    site: { id: 1, label: 'Acme Co', url: 'https://acme.test', wp_version: '6.9.4' },
    period: { from: '2026-05-17', to: '2026-06-16' },
    overview: {
      updates_applied: 1,
      uptime_range_percent: 99.95,
      open_findings: 1,
      wp_version: '6.9.4',
    },
    updates: [
      {
        type: 'plugin',
        slug: 'wordpress-seo',
        component_name: 'Yoast SEO',
        previous_version: '27.1.1',
        new_version: '27.5',
        applied_at: '2026-06-01 09:00:00',
      },
    ],
    uptime: {
      range_percent: 99.95,
      last_24h_percent: 100,
      last_7d_percent: 99.9,
      last_30d_percent: 99.95,
      incidents: [
        {
          started_at: '2026-06-02 02:00:00',
          ended_at: '2026-06-02 02:30:00',
          duration_seconds: 1800,
          reason: 'Connection timeout',
          ongoing: false,
        },
      ],
    },
    security: {
      last_scan_at: '2026-06-15 03:00:00',
      open_findings: [
        {
          type: 'plugin',
          slug: 'wp-file-manager',
          component_name: 'WP File Manager',
          installed_version: '6.0',
          severity: 'critical',
          cvss_score: 9.8,
          cve: 'CVE-2024-1234',
          fixed_in: '6.9',
          title: 'RCE',
          source_id: 'src-wfm',
          dismissed: false,
        },
      ],
      severity_counts: { critical: 1, high: 0, medium: 0, low: 0 },
      scans: [
        { scanned_at: '2026-06-15 03:00:00', total: 1, critical: 1, high: 0, medium: 0, low: 0 },
      ],
    },
  };
}

function emptyReport(): Report {
  const base = populatedReport();
  return {
    ...base,
    overview: { ...base.overview, updates_applied: 0, open_findings: 0 },
    updates: [],
    uptime: { ...base.uptime, range_percent: 100, incidents: [] },
    security: { ...base.security, last_scan_at: null, open_findings: [], scans: [] },
  };
}

type HookReturn = ReturnType<typeof useSiteReport>;

function mockReport(report: Report | undefined, opts: Partial<HookReturn> = {}) {
  mockedUseSiteReport.mockReturnValue({
    data: report,
    isLoading: false,
    isError: false,
    ...opts,
  } as HookReturn);
}

function renderReport(id = 1) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[`/sites/${id}/report`]}>
        <Routes>
          <Route path="/sites/:id/report" element={<SiteReport />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('SiteReport', () => {
  beforeEach(() => {
    mockedUseSiteReport.mockReset();
  });

  it('renders the four section headings', () => {
    mockReport(populatedReport());
    renderReport();
    expect(screen.getByRole('heading', { name: /Overview/i })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /Updates/i })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /Uptime/i })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /Security/i })).toBeInTheDocument();
  });

  it('shows the updates table with component name and version transition', () => {
    mockReport(populatedReport());
    renderReport();
    expect(screen.getByText('Yoast SEO')).toBeInTheDocument();
    expect(screen.getByText(/27\.1\.1/)).toBeInTheDocument();
    expect(screen.getByText(/27\.5/)).toBeInTheDocument();
  });

  it('shows the security finding component name', () => {
    mockReport(populatedReport());
    renderReport();
    expect(screen.getByText('WP File Manager')).toBeInTheDocument();
  });

  it('shows the reporting period', () => {
    mockReport(populatedReport());
    renderReport();
    expect(screen.getByText(/2026-05-17/)).toBeInTheDocument();
    expect(screen.getByText(/2026-06-16/)).toBeInTheDocument();
  });

  it('calls window.print when the Print / Save as PDF button is clicked', async () => {
    const printSpy = vi.spyOn(window, 'print').mockImplementation(() => {});
    mockReport(populatedReport());
    renderReport();
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /Print \/ Save as PDF/i }));
    expect(printSpy).toHaveBeenCalledTimes(1);
    printSpy.mockRestore();
  });

  it('renders empty states when arrays are empty', () => {
    mockReport(emptyReport());
    renderReport();
    expect(screen.getByText(/No updates applied this period/i)).toBeInTheDocument();
    expect(screen.getByText(/No downtime this period/i)).toBeInTheDocument();
    expect(screen.getByText(/No open findings/i)).toBeInTheDocument();
  });

  it('shows a loading state', () => {
    mockReport(undefined, { isLoading: true });
    renderReport();
    expect(screen.getByText(/Loading/i)).toBeInTheDocument();
  });

  it('shows an error state', () => {
    mockReport(undefined, { isError: true });
    renderReport();
    expect(screen.getByText(/Failed to load/i)).toBeInTheDocument();
  });
});
