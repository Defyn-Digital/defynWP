import { Link, useSearchParams } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { JobRow } from '@/components/jobs/JobRow';
import { useJobsList, type JobsStatusFilter } from '@/lib/queries/useJobsList';

const STATUS_FILTERS: Array<{ key: JobsStatusFilter; label: string }> = [
  { key: 'active', label: 'Active' },
  { key: 'completed', label: 'Completed' },
  { key: 'all', label: 'All' },
];

/**
 * P2.9 — /jobs list page (spec § 3.3). Status filter chips + paginated
 * rows; state lives in the URL (?status=&page=) so refresh/back work.
 */
export default function Jobs() {
  const [searchParams, setSearchParams] = useSearchParams();
  const statusParam = searchParams.get('status');
  const status: JobsStatusFilter =
    statusParam === 'active' || statusParam === 'completed' ? statusParam : 'all';
  const page = Math.max(1, Number(searchParams.get('page') ?? '1') || 1);

  const { data, isLoading, isError, refetch } = useJobsList(status, page);

  const totalPages = data ? Math.max(1, Math.ceil(data.total / data.per_page)) : 1;

  const setStatus = (next: JobsStatusFilter) => {
    setSearchParams({ status: next, page: '1' });
  };
  const setPage = (next: number) => {
    setSearchParams({ status, page: String(next) });
  };

  return (
    <div className="min-h-screen p-8">
      <div className="max-w-3xl mx-auto space-y-4">
        <div className="flex items-center justify-between">
          <div className="flex items-baseline gap-3">
            <h1 className="text-2xl font-semibold">Jobs</h1>
            <Link to="/overview" className="text-sm text-zinc-600 underline-offset-4 hover:underline">
              Back to Overview
            </Link>
          </div>
          <div className="flex gap-1">
            {STATUS_FILTERS.map(({ key, label }) => (
              <Button
                key={key}
                size="sm"
                variant={status === key ? 'default' : 'ghost'}
                onClick={() => setStatus(key)}
              >
                {label}
              </Button>
            ))}
          </div>
        </div>

        {isLoading && <div className="h-24 animate-pulse rounded-md bg-gray-100" />}

        {isError && (
          <div className="rounded-md border border-red-200 bg-red-50 p-4">
            <p className="text-sm text-red-800">Failed to load jobs.</p>
            <button
              onClick={() => refetch()}
              className="mt-2 rounded-md border border-red-200 px-3 py-1 text-sm text-red-800"
            >
              Try again
            </button>
          </div>
        )}

        {data && data.jobs.length === 0 && (
          <p className="text-sm text-zinc-600">
            No jobs yet. Bulk updates you launch from the Overview will appear here.
          </p>
        )}

        {data && data.jobs.length > 0 && (
          <ul className="space-y-2">
            {data.jobs.map((job) => (
              <JobRow key={job.id} job={job} />
            ))}
          </ul>
        )}

        {data && totalPages > 1 && (
          <div className="flex items-center justify-center gap-3 text-sm text-zinc-700">
            <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>
              Prev
            </Button>
            <span>
              Page {page} of {totalPages}
            </span>
            <Button size="sm" variant="outline" disabled={page >= totalPages} onClick={() => setPage(page + 1)}>
              Next
            </Button>
          </div>
        )}
      </div>
    </div>
  );
}
