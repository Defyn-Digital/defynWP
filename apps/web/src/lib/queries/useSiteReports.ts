import { useQuery } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient } from '@/lib/apiClient';
import { reportsResponseSchema } from '@/types/api';

/** The inner `.data` payload of the report-queue list response. */
type SiteReportsData = z.infer<typeof reportsResponseSchema>['data'];

/**
 * P5.3 — pure poll-interval decision, exported for direct unit testing
 * (mirrors P2.9 jobsListPollInterval): 5s while ANY report is still
 * `generating`, otherwise stop polling.
 */
export function siteReportsPollInterval(data: SiteReportsData | undefined): number | false {
  return data?.reports.some((r) => r.status === 'generating') ? 5_000 : false;
}

/**
 * P5.3 — per-site report queue with adaptive polling. `apiClient.get`
 * returns the raw `{ data, error }` envelope; we parse and surface the
 * inner `.data`. TanStack v5: refetchInterval receives the Query object,
 * so we read `query.state.data` (cast for tsc, mirroring useJobsList).
 */
export function useSiteReports(siteId: number) {
  return useQuery({
    queryKey: ['siteReports', siteId],
    queryFn: async () =>
      reportsResponseSchema.parse(await apiClient.get<unknown>(`/sites/${siteId}/reports`)).data,
    refetchInterval: (query) =>
      siteReportsPollInterval(query.state.data as SiteReportsData | undefined),
    refetchIntervalInBackground: false,
  });
}
