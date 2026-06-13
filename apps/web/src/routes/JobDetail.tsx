import { useMemo } from 'react';
import { Link, useParams } from 'react-router-dom';
import { JobHeader } from '@/components/jobs/JobHeader';
import { JobItemsGroup } from '@/components/jobs/JobItemsGroup';
import { useJobDetail } from '@/lib/queries/useJobDetail';
import { useCancelJob } from '@/lib/mutations/useCancelJob';
import { useRetryFailed } from '@/lib/mutations/useRetryFailed';
import { useRetryItem } from '@/lib/mutations/useRetryItem';
import type { JobItem } from '@/types/api';

/**
 * P2.9 — /jobs/:id detail page (spec § 3.4). Adaptive 5s polling via
 * useJobDetail; items grouped per site with the first 3 sites expanded.
 */
export default function JobDetail() {
  const params = useParams();
  const jobId = Number(params.id);

  const { data, isLoading, isError, refetch } = useJobDetail(jobId);
  const cancelJob = useCancelJob();
  const retryItem = useRetryItem();
  const retryFailed = useRetryFailed();

  const groups = useMemo(() => {
    const map = new Map<string, JobItem[]>();
    for (const item of data?.items ?? []) {
      const existing = map.get(item.site_label) ?? [];
      map.set(item.site_label, [...existing, item]);
    }
    return [...map.entries()];
  }, [data?.items]);

  return (
    <div className="min-h-screen p-8">
      <div className="max-w-3xl mx-auto space-y-4">
        <Link to="/jobs" className="text-sm text-zinc-600 underline-offset-4 hover:underline">
          Back to Jobs
        </Link>

        {isLoading && <div className="h-24 animate-pulse rounded-md bg-gray-100" />}

        {isError && (
          <div className="rounded-md border border-red-200 bg-red-50 p-4">
            <p className="text-sm text-red-800">Failed to load the job.</p>
            <button
              onClick={() => refetch()}
              className="mt-2 rounded-md border border-red-200 px-3 py-1 text-sm text-red-800"
            >
              Try again
            </button>
          </div>
        )}

        {data && (
          <>
            <JobHeader
              job={data.job}
              onCancel={() => cancelJob.mutate(jobId)}
              onRetryFailed={() => retryFailed.mutate(jobId)}
              cancelPending={cancelJob.isPending}
              retryFailedPending={retryFailed.isPending}
            />

            <div className="space-y-3">
              {groups.map(([siteLabel, items], index) => (
                <JobItemsGroup
                  key={siteLabel}
                  siteLabel={siteLabel}
                  items={items}
                  defaultExpanded={index < 3}
                  onRetryItem={(itemId) => retryItem.mutate({ jobId, itemId })}
                  retryPending={retryItem.isPending}
                />
              ))}
            </div>
          </>
        )}
      </div>
    </div>
  );
}
