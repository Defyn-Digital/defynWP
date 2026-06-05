import { z } from 'zod';

export const pluginSchema = z.object({
  slug: z.string(),
  name: z.string(),
  version: z.string().nullable(),
  update_available: z.boolean(),
  update_version: z.string().nullable(),
});

export type Plugin = z.infer<typeof pluginSchema>;

export const sitePluginsListResponseSchema = z.object({
  plugins: z.array(pluginSchema),
  total: z.number().int(),
  last_synced_at: z.string().nullable(),
});

export type SitePluginsListResponse = z.infer<typeof sitePluginsListResponseSchema>;
