import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';

export interface SendReportRequest {
  reportId: number;
  recipientEmail: string;
  note?: string;
}

/**
 * P5.3 — POSTs to /sites/{id}/reports/{rid}/send to email a ready report.
 * On success: invalidate ['siteReports', siteId] so the row's status/sent_at
 * refresh to the `sent` state.
 */
export function useSendReport(siteId: number) {
  const qc = useQueryClient();
  return useMutation<unknown, Error, SendReportRequest>({
    mutationFn: ({ reportId, recipientEmail, note }) =>
      apiClient.post<unknown>(`/sites/${siteId}/reports/${reportId}/send`, {
        recipient_email: recipientEmail,
        note,
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['siteReports', siteId] });
    },
  });
}
