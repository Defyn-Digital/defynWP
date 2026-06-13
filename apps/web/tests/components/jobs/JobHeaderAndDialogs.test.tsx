import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { JobHeader } from '@/components/jobs/JobHeader';
import { JobItemsGroup } from '@/components/jobs/JobItemsGroup';
import type { Job, JobItem } from '@/types/api';

function makeJob(overrides: Partial<Job> = {}): Job {
  return {
    id: 42,
    kind: 'plugin_update',
    scheduled_count: 12,
    skipped_count: 0,
    succeeded_count: 9,
    failed_count: 2,
    cancelled_count: 0,
    queued_count: 1,
    started_count: 0,
    state: 'in_progress',
    started_at: '2026-06-09 21:00:00',
    completed_at: null,
    created_at: '2026-06-09 20:59:15',
    ...overrides,
  };
}

function makeItem(overrides: Partial<JobItem> = {}): JobItem {
  return {
    id: 201,
    site_id: 1,
    site_label: 'SmartCoding',
    resource_slug: 'akismet',
    resource_name: 'Akismet Anti-Spam',
    current_version: '5.3',
    target_version: '5.3.1',
    state: 'succeeded',
    error_message: null,
    started_at: null,
    completed_at: null,
    created_at: '2026-06-09 20:59:15',
    ...overrides,
  };
}

function renderHeader(job: Job, onCancel = vi.fn(), onRetryFailed = vi.fn()) {
  render(
    <JobHeader
      job={job}
      onCancel={onCancel}
      onRetryFailed={onRetryFailed}
      cancelPending={false}
      retryFailedPending={false}
    />,
  );
  return { onCancel, onRetryFailed };
}

describe('JobHeader', () => {
  it('renders kind, job id, counts and state chip', () => {
    renderHeader(makeJob());
    expect(screen.getByText(/plugin update — job #42/i)).toBeInTheDocument();
    expect(screen.getByText(/12 scheduled · 9 succeeded · 2 failed · 0 cancelled/i)).toBeInTheDocument();
    expect(screen.getByTestId('job-state-chip')).toHaveTextContent('in progress');
  });

  it('Cancel enabled when queued_count > 0; confirm flows through the neutral dialog', () => {
    const { onCancel } = renderHeader(makeJob({ queued_count: 3 }));

    const cancelButton = screen.getByRole('button', { name: /^cancel$/i });
    expect(cancelButton).toBeEnabled();

    fireEvent.click(cancelButton);
    // Dialog copy per spec § 3.8.
    expect(screen.getByText(/cancel 3 queued items\?/i)).toBeInTheDocument();
    expect(
      screen.getByText(/items already in progress can't be cancelled and will continue running/i),
    ).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /cancel 3 queued items/i }));
    expect(onCancel).toHaveBeenCalledTimes(1);
  });

  it('Cancel disabled with tooltip when queued_count === 0', () => {
    renderHeader(makeJob({ queued_count: 0 }));
    const cancelButton = screen.getByRole('button', { name: /^cancel$/i });
    expect(cancelButton).toBeDisabled();
    expect(cancelButton).toHaveAttribute('title', 'All items already started or terminal');
  });

  it('Retry all enabled when failed_count > 0; confirm flows through the neutral dialog', () => {
    const { onRetryFailed } = renderHeader(makeJob({ failed_count: 2 }));

    const retryAll = screen.getByRole('button', { name: /retry all/i });
    expect(retryAll).toBeEnabled();

    fireEvent.click(retryAll);
    expect(screen.getByText(/retry 2 failed items\?/i)).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /retry 2 items/i }));
    expect(onRetryFailed).toHaveBeenCalledTimes(1);
  });

  it('Retry all disabled when failed_count === 0', () => {
    renderHeader(makeJob({ failed_count: 0 }));
    expect(screen.getByRole('button', { name: /retry all/i })).toBeDisabled();
  });
});

describe('JobItemsGroup', () => {
  it('renders site label with item count and toggles collapse', () => {
    render(
      <JobItemsGroup
        siteLabel="SmartCoding"
        items={[makeItem(), makeItem({ id: 202, resource_slug: 'yoast', resource_name: 'Yoast SEO' })]}
        defaultExpanded={true}
        onRetryItem={() => undefined}
        retryPending={false}
      />,
    );
    expect(screen.getByText(/smartcoding \(2 items\)/i)).toBeInTheDocument();
    expect(screen.getByText('Akismet Anti-Spam')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /smartcoding/i }));
    expect(screen.queryByText('Akismet Anti-Spam')).not.toBeInTheDocument();
  });
});
