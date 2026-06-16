import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import type { ReportBranding } from '@/types/api';

export function useSaveReportBranding() {
  const qc = useQueryClient();
  const mutation = useMutation({
    mutationFn: (b: Partial<ReportBranding>) =>
      apiClient.post<{ report_branding: unknown }>('/settings/report-branding', b),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['settings'] }),
  });
  return {
    save: (b: Partial<ReportBranding>) => mutation.mutate(b),
    isPending: mutation.isPending,
    error: mutation.error,
  };
}
