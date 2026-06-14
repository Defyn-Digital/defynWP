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
  // P2.4 — persisted core update state machine fields.
  core_update_available: z.boolean(),
  core_update_version: z.string().nullable(),
  core_update_state: z.enum(['idle', 'queued', 'updating', 'failed']),
  last_core_update_error: z.string().nullable(),
  last_core_update_attempt_at: z.string().nullable(),
  // P2.4 — transient meta from connector /status, NOT persisted.
  is_minor_update: z.boolean().optional(),
  is_auto_update_enabled: z.boolean().optional(),
  // P2.4.1 — operator toggle: allow WP major-version updates for this site.
  core_allow_major: z.boolean(),
});
export type Site = z.infer<typeof siteSchema>;

// P2.4.1 — response from POST /sites/{id}/core/allow-major.
export const coreAllowMajorResponseSchema = z.object({
  site_id: z.number().int(),
  core_allow_major: z.boolean(),
});
export type CoreAllowMajorResponse = z.infer<typeof coreAllowMajorResponseSchema>;

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

// P3.1 — Site monitoring incident schemas.
export const incidentSchema = z.object({
  id: z.number(),
  site_id: z.number(),
  started_at: z.string(),
  ended_at: z.string().nullable(),
  duration_seconds: z.number().nullable(),
  last_error: z.string().nullable(),
  created_at: z.string(),
});
export type Incident = z.infer<typeof incidentSchema>;

export const openIncidentSchema = z.object({
  site_id: z.number(),
  site_label: z.string(),
  started_at: z.string(),
});
export type OpenIncident = z.infer<typeof openIncidentSchema>;

// P2.5 — Overview dashboard schema.
export const overviewAttentionReasonSchema = z.enum([
  'offline',
  'failed_update',
  'ssl_expiring',
  'sync_stale',
]);
export type OverviewAttentionReason = z.infer<typeof overviewAttentionReasonSchema>;

export const overviewSchema = z.object({
  pending_updates: z.object({
    plugins: z.number().int().nonnegative(),
    themes: z.number().int().nonnegative(),
    cores_minor: z.number().int().nonnegative(),
    cores_major: z.number().int().nonnegative(),
    sites_with_any_update: z.number().int().nonnegative(),
  }),
  sites_needing_attention: z.array(z.object({
    site_id: z.number().int(),
    url: z.string(),
    label: z.string(),
    reasons: z.array(overviewAttentionReasonSchema),
    last_contact_at: z.string().nullable(),
    ssl_expires_at: z.string().nullable(),
  })),
  recent_activity: z.array(z.object({
    id: z.number().int(),
    site_id: z.number().int().nullable(),
    site_label: z.string().nullable(),
    event_type: z.string(),
    details: z.record(z.string(), z.unknown()).nullable(),
    created_at: z.string(),
  })),
  total_sites: z.number().int().nonnegative(),
  generated_at: z.string(),
  // Tolerant of an older dashboard that predates P3.1: if the backend omits
  // open_incidents (e.g. during the SPA-deploy → manual-dashboard-install
  // window), default to [] so /overview still parses and the Overview page
  // doesn't break. Once the v0.10.0 dashboard is installed, real data flows.
  open_incidents: z.array(openIncidentSchema).default([]),
});
export type Overview = z.infer<typeof overviewSchema>;

export const syncAllSitesResponseSchema = z.object({
  scheduled_count: z.number().int().nonnegative(),
  site_ids: z.array(z.number().int()),
  scheduled_at: z.string(),
});
export type SyncAllSitesResponse = z.infer<typeof syncAllSitesResponseSchema>;

// P2.7 — GET /defyn/v1/overview/pending-plugin-updates response.
export const pendingPluginUpdateRowSchema = z.object({
  site_id: z.number().int(),
  site_label: z.string(),
  slug: z.string(),
  plugin_name: z.string(),
  current_version: z.string(),
  target_version: z.string().nullable(),
});
export type PendingPluginUpdateRow = z.infer<typeof pendingPluginUpdateRowSchema>;

export const pendingPluginUpdatesSchema = z.object({
  pending_updates: z.array(pendingPluginUpdateRowSchema),
  generated_at: z.string(),
});
export type PendingPluginUpdates = z.infer<typeof pendingPluginUpdatesSchema>;

// P2.7 — POST /defyn/v1/overview/bulk-update-plugins response.
const bulkUpdatePairSchema = z.object({
  site_id: z.number().int(),
  slug: z.string(),
});

export const bulkUpdatePluginsResponseSchema = z.object({
  job_id: z.number().int().nullable(),
  scheduled_count: z.number().int().nonnegative(),
  skipped_count: z.number().int().nonnegative(),
  scheduled_pairs: z.array(bulkUpdatePairSchema),
  skipped_pairs: z.array(bulkUpdatePairSchema.extend({
    reason: z.enum(['site_not_owned', 'plugin_not_found', 'no_update_available']),
  })),
  scheduled_at: z.string(),
});
export type BulkUpdatePluginsResponse = z.infer<typeof bulkUpdatePluginsResponseSchema>;

// P2.8 — GET /defyn/v1/overview/pending-theme-updates response.
export const pendingThemeUpdateRowSchema = z.object({
  site_id: z.number().int(),
  site_label: z.string(),
  slug: z.string(),
  theme_name: z.string(),
  current_version: z.string(),
  target_version: z.string().nullable(),
});
export type PendingThemeUpdateRow = z.infer<typeof pendingThemeUpdateRowSchema>;

export const pendingThemeUpdatesSchema = z.object({
  pending_updates: z.array(pendingThemeUpdateRowSchema),
  generated_at: z.string(),
});
export type PendingThemeUpdates = z.infer<typeof pendingThemeUpdatesSchema>;

// P2.8 — POST /defyn/v1/overview/bulk-update-themes request + response.
export const bulkUpdateThemesRequestSchema = z.object({
  updates: z.array(bulkUpdatePairSchema).min(1),
});
export type BulkUpdateThemesRequest = z.infer<typeof bulkUpdateThemesRequestSchema>;

export const bulkUpdateThemesResponseSchema = z.object({
  job_id: z.number().int().nullable(),
  scheduled_count: z.number().int().nonnegative(),
  skipped_count: z.number().int().nonnegative(),
  scheduled_pairs: z.array(bulkUpdatePairSchema),
  skipped_pairs: z.array(bulkUpdatePairSchema.extend({
    reason: z.enum(['site_not_owned', 'theme_not_found', 'no_update_available']),
  })),
  scheduled_at: z.string(),
});
export type BulkUpdateThemesResponse = z.infer<typeof bulkUpdateThemesResponseSchema>;

// P2.9 — Bulk-jobs entity.
export const jobKindSchema = z.enum(['plugin_update', 'theme_update']);
export type JobKind = z.infer<typeof jobKindSchema>;

export const jobStateSchema = z.enum(['queued', 'in_progress', 'completed', 'partial']);
export type JobState = z.infer<typeof jobStateSchema>;

export const jobItemStateSchema = z.enum(['queued', 'started', 'succeeded', 'failed', 'cancelled']);
export type JobItemState = z.infer<typeof jobItemStateSchema>;

export const jobSchema = z.object({
  id: z.number().int().positive(),
  kind: jobKindSchema,
  scheduled_count: z.number().int().nonnegative(),
  skipped_count: z.number().int().nonnegative(),
  succeeded_count: z.number().int().nonnegative(),
  failed_count: z.number().int().nonnegative(),
  cancelled_count: z.number().int().nonnegative(),
  queued_count: z.number().int().nonnegative(),
  started_count: z.number().int().nonnegative(),
  state: jobStateSchema,
  started_at: z.string().nullable(),
  completed_at: z.string().nullable(),
  created_at: z.string(),
});
export type Job = z.infer<typeof jobSchema>;

export const jobItemSchema = z.object({
  id: z.number().int().positive(),
  site_id: z.number().int(),
  site_label: z.string(),
  resource_slug: z.string(),
  resource_name: z.string(),
  current_version: z.string().nullable(),
  target_version: z.string().nullable(),
  state: jobItemStateSchema,
  error_message: z.string().nullable(),
  started_at: z.string().nullable(),
  completed_at: z.string().nullable(),
  created_at: z.string(),
});
export type JobItem = z.infer<typeof jobItemSchema>;

export const jobsListResponseSchema = z.object({
  jobs: z.array(jobSchema),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  per_page: z.number().int().positive(),
  generated_at: z.string(),
});
export type JobsListResponse = z.infer<typeof jobsListResponseSchema>;

export const jobDetailResponseSchema = z.object({
  job: jobSchema,
  items: z.array(jobItemSchema),
  generated_at: z.string(),
});
export type JobDetailResponse = z.infer<typeof jobDetailResponseSchema>;

export const cancelJobResponseSchema = z.object({
  cancelled_count: z.number().int().nonnegative(),
  still_running_count: z.number().int().nonnegative(),
  cancelled_at: z.string(),
});
export type CancelJobResponse = z.infer<typeof cancelJobResponseSchema>;

export const retryItemResponseSchema = z.object({
  item_id: z.number().int().positive(),
  scheduled_at: z.string(),
});
export type RetryItemResponse = z.infer<typeof retryItemResponseSchema>;

export const retryFailedResponseSchema = z.object({
  retried_count: z.number().int().nonnegative(),
  retried_item_ids: z.array(z.number().int()),
  scheduled_at: z.string(),
});
export type RetryFailedResponse = z.infer<typeof retryFailedResponseSchema>;
