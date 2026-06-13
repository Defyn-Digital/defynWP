import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { CancelJobDialog } from '@/components/jobs/CancelJobDialog';
import { JobStateChip } from '@/components/jobs/JobStateChip';
import { RetryFailedDialog } from '@/components/jobs/RetryFailedDialog';
import { formatRelativeTime } from '@/lib/formatRelativeTime';
import type { Job } from '@/types/api';

const KIND_LABELS: Record<Job['kind'], string> = {
  plugin_update: 'Plugin update',
  theme_update: 'Theme update',
};

interface JobHeaderProps {
  job: Job;
  onCancel: () => void;
  onRetryFailed: () => void;
  cancelPending: boolean;
  retryFailedPending: boolean;
}

/**
 * P2.9 — detail-view header (spec § 3.4 + § 3.8). Cancel enabled iff
 * queued_count > 0 (disabled tooltip otherwise); Retry-all enabled iff
 * failed_count > 0. Both flow through neutral default-styled dialogs.
 */
export function JobHeader({ job, onCancel, onRetryFailed, cancelPending, retryFailedPending }: JobHeaderProps) {
  const [cancelOpen, setCancelOpen] = useState(false);
  const [retryOpen, setRetryOpen] = useState(false);

  const cancelDisabled = job.queued_count === 0 || cancelPending;

  return (
    <div className="rounded-md border border-zinc-200 bg-white p-4">
      <div className="flex items-start justify-between">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-xl font-semibold text-zinc-900">
              {KIND_LABELS[job.kind]} — Job #{job.id}
            </h1>
            <JobStateChip state={job.state} />
          </div>
          <p className="mt-1 text-sm text-zinc-600">
            {job.scheduled_count} scheduled · {job.succeeded_count} succeeded · {job.failed_count} failed
            {' · '}
            {job.cancelled_count} cancelled · {formatRelativeTime(job.created_at)}
          </p>
        </div>
        <div className="flex gap-2">
          <Button
            variant="outline"
            size="sm"
            disabled={cancelDisabled}
            title={job.queued_count === 0 ? 'All items already started or terminal' : undefined}
            onClick={() => setCancelOpen(true)}
          >
            Cancel
          </Button>
          <Button
            variant="default"
            size="sm"
            disabled={job.failed_count === 0 || retryFailedPending}
            onClick={() => setRetryOpen(true)}
          >
            Retry all
          </Button>
        </div>
      </div>

      <CancelJobDialog
        open={cancelOpen}
        queuedCount={job.queued_count}
        onClose={() => setCancelOpen(false)}
        onConfirm={() => {
          setCancelOpen(false);
          onCancel();
        }}
      />
      <RetryFailedDialog
        open={retryOpen}
        failedCount={job.failed_count}
        onClose={() => setRetryOpen(false)}
        onConfirm={() => {
          setRetryOpen(false);
          onRetryFailed();
        }}
      />
    </div>
  );
}
