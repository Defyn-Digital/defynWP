import { z } from 'zod';

export const siteStatusSchema = z.enum(['pending', 'active', 'error', 'offline']);
export type SiteStatus = z.infer<typeof siteStatusSchema>;

export const activeThemeSchema = z.object({
  name: z.string(),
  version: z.string(),
  parent: z.string().nullable(),
}).nullable();
export type ActiveTheme = z.infer<typeof activeThemeSchema>;

export const siteCountsSchema = z.object({
  installed: z.number().int().nonnegative(),
  active: z.number().int().nonnegative(),
}).nullable();
export type SiteCounts = z.infer<typeof siteCountsSchema>;

export const siteSchema = z.object({
  id: z.number().int().positive(),
  url: z.string().url(),
  label: z.string(),
  status: siteStatusSchema,
  last_contact_at: z.string().nullable(),
  last_sync_at: z.string().nullable(),
  last_error: z.string().nullable(),
  created_at: z.string(),
  wp_version: z.string().nullable(),
  php_version: z.string().nullable(),
  active_theme: activeThemeSchema,
  plugin_counts: siteCountsSchema,
  theme_counts: siteCountsSchema,
  ssl_status: z.string().nullable(),
  ssl_expires_at: z.string().nullable(),
});
export type Site = z.infer<typeof siteSchema>;

export const sitesListSchema = z.object({
  sites: z.array(siteSchema),
});
export type SitesList = z.infer<typeof sitesListSchema>;

export const createSiteSchema = z.object({
  url: z.string().url().startsWith('https://', 'URL must start with https://'),
  label: z.string(),
  code: z.string().length(12, 'Code must be 12 characters'),
});
export type CreateSiteInput = z.infer<typeof createSiteSchema>;

export const createSiteResponseSchema = z.object({
  site_id: z.number().int().positive(),
});
export type CreateSiteResponse = z.infer<typeof createSiteResponseSchema>;

export const activityEventSchema = z.object({
  id: z.number().int().positive(),
  site_id: z.number().int().positive().nullable(),
  event_type: z.string(),
  details: z.record(z.string(), z.unknown()).nullable(),
  created_at: z.string(),
});
export type ActivityEvent = z.infer<typeof activityEventSchema>;

export const activityListResponseSchema = z.object({
  events: z.array(activityEventSchema),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  per_page: z.number().int().positive(),
});
export type ActivityListResponse = z.infer<typeof activityListResponseSchema>;
