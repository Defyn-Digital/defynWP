import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';

/**
 * P5.4 — POSTs to /sites/{id}/auto-send to toggle the per-site auto-send opt-in.
 * On success: invalidate ['site', siteId] (the flag lives on the site record)
 * AND ['siteReports', siteId] (so the reports panel re-reads).
 */
export function useSetAutoSend(siteId: number) {
  const qc = useQueryClient();
  return useMutation<unknown, Error, boolean>({
    mutationFn: (autoSend) =>
      apiClient.post<unknown>(`/sites/${siteId}/auto-send`, { auto_send: autoSend }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['site', siteId] });
      qc.invalidateQueries({ queryKey: ['siteReports', siteId] });
    },
  });
}
