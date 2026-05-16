import { z } from 'zod';

export const siteStatusSchema = z.enum(['pending', 'active', 'error']);
export type SiteStatus = z.infer<typeof siteStatusSchema>;

export const siteSchema = z.object({
  id: z.number().int().positive(),
  url: z.string().url(),
  label: z.string(),
  status: siteStatusSchema,
  last_contact_at: z.string().nullable(),
  last_error: z.string().nullable(),
  created_at: z.string(),
});
export type Site = z.infer<typeof siteSchema>;

export const sitesListSchema = z.object({
  sites: z.array(siteSchema),
});

export const createSiteSchema = z.object({
  url: z.string().url().startsWith('https://', 'URL must start with https://'),
  label: z.string(),
  code: z.string().length(12, 'Code must be 12 characters'),
});
export type CreateSiteInput = z.infer<typeof createSiteSchema>;

export const createSiteResponseSchema = z.object({
  site_id: z.number().int().positive(),
});
