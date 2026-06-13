import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { JobRow } from '@/components/jobs/JobRow';
import type { Job } from '@/types/api';

const JOB: Job = {
  id: 42,
  kind: 'plugin_update',
  scheduled_count: 12,
  skipped_count: 0,
  succeeded_count: 9,
  failed_count: 2,
  cancelled_count: 1,
  queued_count: 0,
  started_count: 0,
  state: 'partial',
  started_at: '2026-06-09 21:00:00',
  completed_at: '2026-06-09 21:08:42',
  created_at: '2026-06-09 20:59:15',
};

function renderRow(job: Job) {
  return render(
    <MemoryRouter>
      <ul>
        <JobRow job={job} />
      </ul>
    </MemoryRouter>,
  );
}

describe('JobRow', () => {
  it('renders kind label + scheduled count + state chip', () => {
    renderRow(JOB);
    expect(screen.getByText(/plugin update — 12 scheduled/i)).toBeInTheDocument();
    expect(screen.getByTestId('job-state-chip')).toHaveTextContent('partial');
  });

  it('renders the per-state counts line', () => {
    renderRow(JOB);
    expect(
      screen.getByText(/9 succeeded · 2 failed · 1 cancelled · 0 queued · 0 started/i),
    ).toBeInTheDocument();
  });

  it('links to the job detail route', () => {
    renderRow(JOB);
    expect(screen.getByRole('link')).toHaveAttribute('href', '/jobs/42');
  });
});
