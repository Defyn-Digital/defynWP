import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { coreAllowMajorResponseSchema, type CoreAllowMajorResponse } from '@/types/api';

/**
 * Toggles the core_allow_major flag for a site by POSTing to
 * /sites/{id}/core/allow-major with { allow: boolean }.
 *
 * On success the ['sites', siteId] query is invalidated so any
 * component holding a useSite() subscription re-fetches the new state.
 */
export function useToggleCoreAllowMajor(siteId: number) {
  const queryClient = useQueryClient();

  return useMutation<CoreAllowMajorResponse, Error, boolean>({
    mutationFn: async (allow: boolean) => {
      const data = await apiClient.post<unknown>(`/sites/${siteId}/core/allow-major`, { allow });
      return coreAllowMajorResponseSchema.parse(data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sites', siteId] });
    },
  });
}
