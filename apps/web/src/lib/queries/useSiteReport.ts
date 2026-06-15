import { useQuery } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient } from '@/lib/apiClient';
import { reportSchema } from '@/types/api';

const responseSchema = z.object({ data: reportSchema, error: z.null() });

export function useSiteReport(siteId: number, from: string, to: string) {
  return useQuery({
    queryKey: ['siteReport', siteId, from, to],
    queryFn: async () => {
      const raw = await apiClient.get<unknown>(
        `/sites/${siteId}/report?from=${from}&to=${to}`,
      );
      return responseSchema.parse(raw).data;
    },
    enabled: Boolean(siteId) && Boolean(from) && Boolean(to),
  });
}
