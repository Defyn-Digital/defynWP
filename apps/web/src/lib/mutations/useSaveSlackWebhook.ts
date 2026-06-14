import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { settingsSchema } from '@/types/api';

export function useSaveSlackWebhook() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (webhookUrl: string) =>
      settingsSchema.parse(await apiClient.post<unknown>('/settings/slack-webhook', { webhook_url: webhookUrl })),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['settings'] });
    },
  });
}
