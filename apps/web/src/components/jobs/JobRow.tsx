import { Link } from 'react-router-dom';
import { JobStateChip } from '@/components/jobs/JobStateChip';
import { formatRelativeTime } from '@/lib/formatRelativeTime';
import type { Job } from '@/types/api';

const KIND_LABELS: Record<Job['kind'], string> = {
  plugin_update: 'Plugin update',
  theme_update: 'Theme update',
};

interface JobRowProps {
  job: Job;
}

/**
 * P2.9 — single list-row card on /jobs (spec § 3.3). Whole row is a Link
 * to /jobs/{id}.
 */
export function JobRow({ job }: JobRowProps) {
  return (
    <li className="rounded-md border border-zinc-200 bg-white">
      <Link to={`/jobs/${job.id}`} className="block p-3 hover:bg-zinc-50">
        <div className="flex items-center justify-between">
          <span className="text-sm font-semibold text-zinc-900">
            {KIND_LABELS[job.kind]} — {job.scheduled_count} scheduled
          </span>
          <JobStateChip state={job.state} />
        </div>
        <p className="mt-1 text-xs text-zinc-600">
          {job.succeeded_count} succeeded · {job.failed_count} failed · {job.cancelled_count} cancelled
          {' · '}
          {job.queued_count} queued · {job.started_count} started · {formatRelativeTime(job.created_at)}
        </p>
      </Link>
    </li>
  );
}
