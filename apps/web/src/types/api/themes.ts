import { z } from 'zod';

export const themeSchema = z.object({
  slug: z.string(),
  name: z.string(),
  version: z.string().nullable(),
  parent_slug: z.string().nullable(),
  is_active: z.boolean(),
  update_available: z.boolean(),
  update_version: z.string().nullable(),
  update_state: z.enum(['idle', 'queued', 'updating', 'failed']),
  last_update_error: z.string().nullable(),
  last_update_attempt_at: z.string().nullable(),
});

export type Theme = z.infer<typeof themeSchema>;

export const siteThemesListResponseSchema = z.object({
  themes: z.array(themeSchema),
  last_synced_at: z.string().nullable(),
});

export type SiteThemesListResponse = z.infer<typeof siteThemesListResponseSchema>;

export const updateSiteThemeResponseSchema = z.object({
  scheduled: z.literal(true),
  site_id: z.number(),
  slug: z.string(),
  update_state: z.enum(['idle', 'queued', 'updating', 'failed']),
});

export type UpdateSiteThemeResponse = z.infer<typeof updateSiteThemeResponseSchema>;

export const refreshSiteThemesResponseSchema = z.object({
  scheduled: z.literal(true),
  site_id: z.number(),
});

export type RefreshSiteThemesResponse = z.infer<typeof refreshSiteThemesResponseSchema>;
