import { useQuery } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient } from '@/lib/apiClient';
import { siteBrokenLinksSchema } from '@/types/api';

const responseSchema = z.object({ data: siteBrokenLinksSchema, error: z.null() });

export function useSiteBrokenLinks(siteId: number, options?: { refetchInterval?: number | false }) {
  return useQuery({
    queryKey: ['siteBrokenLinks', siteId],
    queryFn: async () => {
      const raw = await apiClient.get<unknown>(`/sites/${siteId}/broken-links`);
      return responseSchema.parse(raw).data;
    },
    staleTime: 30_000,
    refetchInterval: options?.refetchInterval ?? false,
  });
}
