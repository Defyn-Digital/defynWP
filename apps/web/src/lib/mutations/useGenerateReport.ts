import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';

export interface GenerateReportRequest {
  from?: string;
  to?: string;
}

/**
 * P5.3 — POSTs to /sites/{id}/reports to queue a new report (202).
 * On success: invalidate ['siteReports', siteId] so the queue list
 * refetches and adaptive polling picks up the new `generating` row.
 */
export function useGenerateReport(siteId: number) {
  const qc = useQueryClient();
  return useMutation<unknown, Error, GenerateReportRequest>({
    mutationFn: (range) => apiClient.post<unknown>(`/sites/${siteId}/reports`, range),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['siteReports', siteId] });
    },
  });
}
