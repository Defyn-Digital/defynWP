import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { JobItemRow } from '@/components/jobs/JobItemRow';
import type { JobItem } from '@/types/api';

function makeItem(overrides: Partial<JobItem> = {}): JobItem {
  return {
    id: 202,
    site_id: 1,
    site_label: 'SmartCoding',
    resource_slug: 'elementor',
    resource_name: 'Elementor',
    current_version: '3.18.2',
    target_version: '4.0.0',
    state: 'succeeded',
    error_message: null,
    started_at: null,
    completed_at: null,
    created_at: '2026-06-09 20:59:15',
    ...overrides,
  };
}

describe('JobItemRow', () => {
  it('renders resource name + version diff + state chip', () => {
    render(
      <ul>
        <JobItemRow item={makeItem()} onRetry={() => undefined} retryPending={false} />
      </ul>,
    );
    expect(screen.getByText('Elementor')).toBeInTheDocument();
    expect(screen.getByText(/3\.18\.2 → 4\.0\.0/)).toBeInTheDocument();
    expect(screen.getByTestId('job-state-chip')).toHaveTextContent('succeeded');
  });

  it('hides the Retry button for non-failed states', () => {
    render(
      <ul>
        <JobItemRow item={makeItem({ state: 'queued' })} onRetry={() => undefined} retryPending={false} />
      </ul>,
    );
    expect(screen.queryByRole('button', { name: /retry/i })).not.toBeInTheDocument();
  });

  it('shows the error message + one-click Retry for failed items (guardrail #21)', () => {
    const onRetry = vi.fn();
    render(
      <ul>
        <JobItemRow
          item={makeItem({ state: 'failed', error_message: 'Could not copy file.' })}
          onRetry={onRetry}
          retryPending={false}
        />
      </ul>,
    );
    expect(screen.getByText('Could not copy file.')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /retry/i }));
    expect(onRetry).toHaveBeenCalledWith(202); // one click — NO confirm dialog
  });

  it('disables Retry while a retry mutation is pending', () => {
    render(
      <ul>
        <JobItemRow
          item={makeItem({ state: 'failed', error_message: 'boom' })}
          onRetry={() => undefined}
          retryPending={true}
        />
      </ul>,
    );
    expect(screen.getByRole('button', { name: /retry/i })).toBeDisabled();
  });
});
