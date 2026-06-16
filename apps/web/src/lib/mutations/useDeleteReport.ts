import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';

/**
 * P5.3 — DELETEs /sites/{id}/reports/{rid} to remove a report record.
 * On success: invalidate ['siteReports', siteId] so the deleted row drops
 * out of the queue list.
 */
export function useDeleteReport(siteId: number) {
  const qc = useQueryClient();
  return useMutation<unknown, Error, number>({
    mutationFn: (reportId) => apiClient.delete<unknown>(`/sites/${siteId}/reports/${reportId}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['siteReports', siteId] });
    },
  });
}
