import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';

export interface ReportBrandingInput {
  agency_name?: string;
  accent_color?: string;
  logo_url?: string;
}

/**
 * POST /sites/{id}/report-branding — set this site's white-label report
 * overrides ("Prepared by" name, accent, logo). Empty string clears an
 * override (falls back to the global default). Invalidates the site query.
 */
export function useSetReportBranding(siteId: number) {
  const qc = useQueryClient();
  return useMutation<unknown, Error, ReportBrandingInput>({
    mutationFn: (body) => apiClient.post<unknown>(`/sites/${siteId}/report-branding`, body),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['sites', siteId] });
    },
  });
}
