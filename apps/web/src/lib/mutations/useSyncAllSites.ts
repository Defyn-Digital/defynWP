import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import {
  syncAllSitesResponseSchema,
  type SyncAllSitesResponse,
} from '@/types/api';

/**
 * P2.6 — POSTs to /defyn/v1/overview/sync-all. Server fan-outs the
 * existing SyncSite AS job per owned site and emits ONE fleet-scoped
 * overview.sync_all_requested activity event. On success the
 * `['overview']` query is invalidated so the activity widget shows
 * the new event immediately.
 *
 * Plan-bug trap #11: invalidate ['overview'] only — DO NOT invalidate
 * ['sites']. Per-site state hasn't changed yet (jobs are only queued);
 * each SyncSite execution invalidates its own per-site keys naturally
 * via SyncPluginsService / SyncThemesService / SyncCoreService.
 */
export function useSyncAllSites() {
  const queryClient = useQueryClient();

  return useMutation<SyncAllSitesResponse, Error, void>({
    mutationFn: async () => {
      const data = await apiClient.post<unknown>('/overview/sync-all');
      return syncAllSitesResponseSchema.parse(data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['overview'] });
    },
  });
}
