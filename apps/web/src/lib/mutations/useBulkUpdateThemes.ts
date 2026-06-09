import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import {
  bulkUpdateThemesResponseSchema,
  type BulkUpdateThemesResponse,
} from '@/types/api';

export interface BulkUpdateThemesRequest {
  updates: Array<{ site_id: number; slug: string }>;
}

/**
 * P2.8 — TanStack mutation hook for POST /overview/bulk-update-themes.
 *
 * On success, invalidates ['overview'] (so the new themes count drops to
 * reflect scheduled jobs) and ['pendingThemeUpdates'] (so the next dialog
 * open shows fewer pairs). Does NOT invalidate ['sites'] — per-site theme
 * state hasn't changed yet, only AS jobs queued. Same reasoning as P2.7's
 * useBulkUpdatePlugins.
 */
export function useBulkUpdateThemes() {
  const queryClient = useQueryClient();

  return useMutation<BulkUpdateThemesResponse, Error, BulkUpdateThemesRequest>({
    mutationFn: async ({ updates }) => {
      const data = await apiClient.post<unknown>('/overview/bulk-update-themes', { updates });
      return bulkUpdateThemesResponseSchema.parse(data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['overview'] });
      queryClient.invalidateQueries({ queryKey: ['pendingThemeUpdates'] });
    },
  });
}
