import { z } from 'zod';

export const pluginSchema = z.object({
  slug: z.string(),
  name: z.string(),
  version: z.string().nullable(),
  update_available: z.boolean(),
  update_version: z.string().nullable(),
  update_state: z.enum(['idle', 'queued', 'updating', 'failed']),
  last_update_error: z.string().nullable(),
  last_update_attempt_at: z.string().nullable(),
  // P2.4.1 — highest WP version the plugin has been tested against; null when unknown.
  tested_up_to: z.string().nullable(),
});

export type Plugin = z.infer<typeof pluginSchema>;

export const sitePluginsListResponseSchema = z.object({
  plugins: z.array(pluginSchema),
  total: z.number().int(),
  last_synced_at: z.string().nullable(),
});

export type SitePluginsListResponse = z.infer<typeof sitePluginsListResponseSchema>;

export const updateSitePluginResponseSchema = z.object({
  scheduled: z.literal(true),
  site_id: z.number(),
  slug: z.string(),
});

export type UpdateSitePluginResponse = z.infer<typeof updateSitePluginResponseSchema>;
