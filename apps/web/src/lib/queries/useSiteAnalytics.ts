import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { siteAnalyticsResponseSchema } from '@/types/api';

export function useSiteAnalytics(siteId: number, options?: { refetchInterval?: number | false }) {
  return useQuery({
    queryKey: ['siteAnalytics', siteId],
    queryFn: async () => {
      const raw = await apiClient.get<unknown>(`/sites/${siteId}/analytics`);
      return siteAnalyticsResponseSchema.parse(raw).data;
    },
    staleTime: 30_000,
    refetchInterval: options?.refetchInterval ?? false,
  });
}
