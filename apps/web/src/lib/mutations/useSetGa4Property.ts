import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';

/**
 * P6.2 — POSTs to /sites/{id}/ga4-property to save the GA4 property ID.
 * On success: invalidate ['siteAnalytics', siteId] so the panel re-reads the
 * connected state (and any newly-allowed on-demand refresh).
 */
export function useSetGa4Property(siteId: number) {
  const qc = useQueryClient();
  return useMutation<unknown, Error, string>({
    mutationFn: (propertyId) =>
      apiClient.post<unknown>(`/sites/${siteId}/ga4-property`, { ga4_property_id: propertyId }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['siteAnalytics', siteId] });
    },
  });
}
