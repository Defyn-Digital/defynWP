import { useQuery } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient } from '@/lib/apiClient';
import { incidentSchema } from '@/types/api';

// The incidents endpoint uses the { data: { ... }, error: null } envelope
// (distinct from most other endpoints that return the data fields directly).
const responseSchema = z.object({
  data: z.object({
    incidents: z.array(incidentSchema),
  }),
  error: z.null(),
});

export function useSiteIncidents(siteId: number) {
  return useQuery({
    queryKey: ['siteIncidents', siteId],
    queryFn: async () => {
      const raw = await apiClient.get<unknown>(`/sites/${siteId}/incidents`);
      return responseSchema.parse(raw).data.incidents;
    },
    staleTime: 30_000,
  });
}
