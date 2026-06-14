import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { z } from 'zod';

const muteResponse = z.object({ site_id: z.number().int(), alerts_muted: z.boolean() });

export function useToggleMuteAlerts(siteId: number) {
  const qc = useQueryClient();
  return useMutation<{ site_id: number; alerts_muted: boolean }, Error, boolean>({
    mutationFn: async (muted: boolean) =>
      muteResponse.parse(await apiClient.post<unknown>(`/sites/${siteId}/alerts/mute`, { muted })),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['sites', siteId] });
    },
  });
}
