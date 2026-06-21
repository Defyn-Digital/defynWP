import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ReportBrokenLinks } from '@/components/report/ReportBrokenLinks';
import type { ReportBrokenLinks as ReportBrokenLinksType } from '@/types/api';

function issuesProp(): ReportBrokenLinksType {
  return {
    state: 'issues',
    last_scanned: '2026-06-15 03:00:00',
    counts: { broken: 1, warning: 1, total: 2, internal: 0, external: 2 },
    items: [
      {
        url: 'https://external.test/dead',
        status_code: 404,
        severity: 'broken',
        reason: 'not_found',
        link_type: 'external',
        source_url: 'https://mysite.test/contact',
      },
      {
        url: 'https://api.test/flaky',
        status_code: 503,
        severity: 'warning',
        reason: 'server_error',
        link_type: 'external',
        source_url: 'https://mysite.test/home',
      },
    ],
  };
}

function notCheckedProp(): ReportBrokenLinksType {
  return {
    state: 'not_checked',
    last_scanned: null,
    counts: { broken: 0, warning: 0, total: 0, internal: 0, external: 0 },
    items: [],
  };
}

function cleanProp(): ReportBrokenLinksType {
  return {
    state: 'clean',
    last_scanned: '2026-06-15 03:00:00',
    counts: { broken: 0, warning: 0, total: 0, internal: 0, external: 0 },
    items: [],
  };
}

describe('ReportBrokenLinks', () => {
  it('renders the items table with url for an issues prop', () => {
    render(<ReportBrokenLinks broken_links={issuesProp()} />);
    // Heading
    expect(screen.getByRole('heading', { name: /broken links/i })).toBeInTheDocument();
    // Both item URLs appear in the table.
    expect(screen.getByText('https://external.test/dead')).toBeInTheDocument();
    expect(screen.getByText('https://api.test/flaky')).toBeInTheDocument();
    // Status codes.
    expect(screen.getByText('404')).toBeInTheDocument();
    expect(screen.getByText('503')).toBeInTheDocument();
    // Severity cells.
    expect(screen.getByText('broken')).toBeInTheDocument();
    expect(screen.getByText('warning')).toBeInTheDocument();
  });

  it('renders the "Not checked yet" line for a not_checked prop', () => {
    render(<ReportBrokenLinks broken_links={notCheckedProp()} />);
    expect(screen.getByText(/not checked yet/i)).toBeInTheDocument();
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
  });

  it('renders the "No broken links found" message for a clean prop', () => {
    render(<ReportBrokenLinks broken_links={cleanProp()} />);
    expect(screen.getByText(/no broken links found/i)).toBeInTheDocument();
  });
});
