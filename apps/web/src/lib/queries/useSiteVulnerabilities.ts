import { useQuery } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient } from '@/lib/apiClient';
import { siteVulnerabilitiesSchema } from '@/types/api';

const responseSchema = z.object({ data: siteVulnerabilitiesSchema, error: z.null() });

export function useSiteVulnerabilities(siteId: number, options?: { refetchInterval?: number | false }) {
  return useQuery({
    queryKey: ['siteVulnerabilities', siteId],
    queryFn: async () => {
      const raw = await apiClient.get<unknown>(`/sites/${siteId}/vulnerabilities`);
      return responseSchema.parse(raw).data;
    },
    staleTime: 30_000,
    refetchInterval: options?.refetchInterval ?? false,
  });
}
