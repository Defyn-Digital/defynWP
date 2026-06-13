import { Link } from 'react-router-dom';
import { useJobsCount } from '@/lib/queries/useJobsCount';

/**
 * P2.9 — nav link to /jobs with an active-jobs badge.
 *
 * PLAN-CORRECTION vs spec § 3.2 (trap #26): the SPA has NO sidebar shell
 * (App.tsx renders bare routes). The link renders in the Overview header
 * instead; Jobs.tsx links back. Badge hidden entirely at count 0 — no
 * "(0)" suffix (guardrail #22).
 */
export function JobsNavLink() {
  const { data: activeCount } = useJobsCount();

  return (
    <Link
      to="/jobs"
      className="inline-flex items-center gap-1.5 text-sm text-zinc-600 underline-offset-4 hover:underline"
    >
      Jobs
      {typeof activeCount === 'number' && activeCount > 0 && (
        <span
          data-testid="jobs-badge"
          className="rounded-full bg-blue-100 px-1.5 py-0.5 text-xs font-medium text-blue-700"
        >
          {activeCount}
        </span>
      )}
    </Link>
  );
}
