import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';

export interface PagespeedKeyInput {
  api_key: string;
}

/**
 * POST /settings/pagespeed-key — set (or clear) the team-wide Google PageSpeed
 * Insights API key used by the weekly + on-demand performance scans. Send an
 * empty api_key to clear. The key is never returned by the API.
 */
export function useSavePagespeedKey() {
  const qc = useQueryClient();
  return useMutation<unknown, Error, PagespeedKeyInput>({
    mutationFn: (body) => apiClient.post<unknown>('/settings/pagespeed-key', body),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['settings'] });
    },
  });
}
