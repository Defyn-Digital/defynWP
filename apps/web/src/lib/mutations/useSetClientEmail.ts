import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';

/**
 * P5.3 — POSTs to /sites/{id}/client-email to save the site's client email.
 * On success: invalidate ['siteReports', siteId] AND ['site', siteId] — the
 * client email lives on the site record and gates the send/schedule flow.
 */
export function useSetClientEmail(siteId: number) {
  const qc = useQueryClient();
  return useMutation<unknown, Error, string>({
    mutationFn: (clientEmail) =>
      apiClient.post<unknown>(`/sites/${siteId}/client-email`, { client_email: clientEmail }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['siteReports', siteId] });
      qc.invalidateQueries({ queryKey: ['site', siteId] });
    },
  });
}
