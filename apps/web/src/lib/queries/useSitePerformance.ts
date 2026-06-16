import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { sitePerformanceResponseSchema } from '@/types/api';

export function useSitePerformance(siteId: number, options?: { refetchInterval?: number | false }) {
  return useQuery({
    queryKey: ['sitePerformance', siteId],
    queryFn: async () => {
      const raw = await apiClient.get<unknown>(`/sites/${siteId}/performance`);
      return sitePerformanceResponseSchema.parse(raw).data;
    },
    staleTime: 30_000,
    refetchInterval: options?.refetchInterval ?? false,
  });
}
