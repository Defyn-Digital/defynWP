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
  // P3.3 — operator mute-alerts toggle for this site.
  alerts_muted: z.boolean(),
  // P5.3 — optional client email for scheduled report delivery.
  client_email: z.string().nullable().optional(),
  // P5.4 — per-site auto-send opt-in (backend always sends it; NOT NULL DEFAULT 0).
  auto_send_reports: z.boolean(),
  // v0.30.3 — per-site pending update counts (default 0 to tolerate an older backend).
  plugin_updates: z.number().int().nonnegative().optional(),
  theme_updates: z.number().int().nonnegative().optional(),
  // v0.3.0 — connector self-update: reported by the connector /status snapshot.
  connector_version: z.string().nullable().optional(),
  is_wpengine: z.boolean().optional(),
  // white-label per-site report branding overrides (blank => use global default).
  report_agency_name: z.string().nullable().optional(),
  report_accent_color: z.string().nullable().optional(),
  report_logo_url: z.string().nullable().optional(),
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

// P4.1 — Security scanning schemas.
export const vulnerabilitySchema = z.object({
  type: z.enum(['plugin', 'theme', 'core']),
  slug: z.string(),
  component_name: z.string(),
  installed_version: z.string(),
  severity: z.enum(['critical', 'high', 'medium', 'low', 'unknown']),
  cvss_score: z.number().nullable(),
  cve: z.string().nullable(),
  fixed_in: z.string().nullable(),
  title: z.string().nullable(),
  source_id: z.string(),
  dismissed: z.boolean(),
});
export type Vulnerability = z.infer<typeof vulnerabilitySchema>;

export const siteVulnerabilitiesSchema = z.object({
  scanned_at: z.string().nullable(),
  vulnerabilities: z.array(vulnerabilitySchema),
});
export type SiteVulnerabilities = z.infer<typeof siteVulnerabilitiesSchema>;

// P5.1 — Client maintenance report schemas (site report data shape).
export const reportUpdateSchema = z.object({
  type: z.enum(['plugin', 'theme', 'core']),
  slug: z.string(),
  component_name: z.string(),
  previous_version: z.string(),
  new_version: z.string(),
  applied_at: z.string(),
});
export const reportIncidentSchema = z.object({
  started_at: z.string(),
  ended_at: z.string().nullable(),
  duration_seconds: z.number().nullable(),
  reason: z.string().nullable(),
  ongoing: z.boolean(),
});
export const reportScanSchema = z.object({
  scanned_at: z.string(),
  total: z.number(),
  critical: z.number(),
  high: z.number(),
  medium: z.number(),
  low: z.number(),
});
// P6.1 — PageSpeed/Core-Web-Vitals performance block on the maintenance report.
export const reportPerformanceSchema = z.object({
  latest: z
    .object({
      fetched_at: z.string(),
      mobile: z.object({
        score: z.number().nullable(),
        lcp_ms: z.number().nullable(),
        cls: z.number().nullable(),
        inp_ms: z.number().nullable(),
      }),
      desktop: z.object({
        score: z.number().nullable(),
        lcp_ms: z.number().nullable(),
        cls: z.number().nullable(),
        inp_ms: z.number().nullable(),
      }),
    })
    .nullable(),
  history: z.array(
    z.object({
      fetched_at: z.string(),
      mobile_score: z.number().nullable(),
      desktop_score: z.number().nullable(),
    }),
  ),
});
export type ReportPerformanceData = z.infer<typeof reportPerformanceSchema>;

// P6.2 — GA4 analytics block on the maintenance report.
export const reportAnalyticsSchema = z.object({
  state: z.enum(['not_connected', 'pending', 'ready']),
  period: z.object({ start: z.string(), end: z.string() }).nullable(),
  totals: z.object({
    sessions: z.number().nullable(),
    users: z.number().nullable(),
    pageviews: z.number().nullable(),
    avg_engagement_seconds: z.number().nullable(),
  }).nullable(),
  top_pages: z.array(z.object({ path: z.string(), title: z.string(), views: z.number() })),
  channels: z.array(z.object({ channel: z.string(), sessions: z.number() })),
  history: z.array(z.object({ period_start: z.string(), sessions: z.number().nullable() })).default([]),
});
export type ReportAnalyticsData = z.infer<typeof reportAnalyticsSchema>;

// P7.1 — Broken-link monitoring schemas.
export const brokenLinkRowSchema = z.object({
  url: z.string(),
  status_code: z.number().int().nullable(),
  severity: z.enum(['broken', 'warning']),
  reason: z.enum(['not_found', 'server_error', 'blocked', 'client_error', 'unreachable']),
  link_type: z.enum(['internal', 'external']),
  source_url: z.string(),
  source_title: z.string().nullable(),
  anchor_text: z.string().nullable(),
  first_detected_at: z.string(),
  last_detected_at: z.string(),
});
export const brokenLinkCountsSchema = z.object({
  broken: z.number(), warning: z.number(), total: z.number(), internal: z.number(), external: z.number(),
});
export const siteBrokenLinksSchema = z.object({
  last_link_scan_at: z.string().nullable(),
  counts: brokenLinkCountsSchema,
  links: z.array(brokenLinkRowSchema),
});
export type SiteBrokenLinks = z.infer<typeof siteBrokenLinksSchema>;

export const reportBrokenLinksSchema = z.object({
  state: z.enum(['not_checked', 'clean', 'issues']),
  last_scanned: z.string().nullable(),
  counts: brokenLinkCountsSchema,
  items: z.array(z.object({
    url: z.string(), status_code: z.number().int().nullable(),
    severity: z.enum(['broken', 'warning']),
    reason: z.enum(['not_found', 'server_error', 'blocked', 'client_error', 'unreachable']),
    link_type: z.enum(['internal', 'external']), source_url: z.string(),
  })),
});
export type ReportBrokenLinks = z.infer<typeof reportBrokenLinksSchema>;

export const siteReportSchema = z.object({
  site: z.object({ id: z.number(), label: z.string(), url: z.string(), wp_version: z.string(), logo_url: z.string().nullable().optional() }),
  period: z.object({ from: z.string(), to: z.string() }),
  overview: z.object({
    updates_applied: z.number(),
    uptime_range_percent: z.number(),
    open_findings: z.number(),
    wp_version: z.string(),
  }),
  updates: z.array(reportUpdateSchema),
  uptime: z.object({
    range_percent: z.number(),
    last_24h_percent: z.number(),
    last_7d_percent: z.number(),
    last_30d_percent: z.number(),
    incidents: z.array(reportIncidentSchema),
  }),
  security: z.object({
    last_scan_at: z.string().nullable(),
    open_findings: z.array(vulnerabilitySchema),
    severity_counts: z.object({ critical: z.number(), high: z.number(), medium: z.number(), low: z.number() }),
    scans: z.array(reportScanSchema),
  }),
  performance: reportPerformanceSchema,
  analytics: reportAnalyticsSchema,
  broken_links: reportBrokenLinksSchema,
});
export type SiteReport = z.infer<typeof siteReportSchema>;

// P5.3 — Report queue entity schemas (per-site queued/ready/failed/sent report records).
export const reportStatusSchema = z.enum(['generating', 'ready', 'failed', 'sent']);
export const reportSchema = z.object({
  id: z.number(),
  site_id: z.number(),
  title: z.string(),
  range_from: z.string(),
  range_to: z.string(),
  status: reportStatusSchema,
  file_size: z.number().nullable(),
  recipient_email: z.string().nullable(),
  generated_at: z.string().nullable(),
  sent_at: z.string().nullable(),
  // P5.4 — how the report was delivered (e.g. 'manual', 'auto'); null until sent.
  sent_method: z.string().nullable(),
  created_at: z.string(),
});
export type Report = z.infer<typeof reportSchema>;
export const reportsResponseSchema = z.object({
  data: z.object({
    reports: z.array(reportSchema),
    total: z.number(),
    page: z.number(),
    per_page: z.number(),
  }),
  error: z.null(),
});

// P2.5 — Overview dashboard schema.
export const overviewAttentionReasonSchema = z.enum([
  'offline',
  'failed_update',
  'ssl_expiring',
  'sync_stale',
  'has_vulnerabilities',
  'has_broken_links',
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

// P3.2 — Monitoring fleet page.
export const monitoringSiteSchema = z.object({
  site_id: z.number().int(),
  label: z.string(),
  url: z.string(),
  status: siteStatusSchema,
  last_response_time_ms: z.number().int().nullable(),
  last_contact_at: z.string().nullable(),
  uptime_7d: z.number(),
  uptime_30d: z.number(),
  open_incident_started_at: z.string().nullable(),
});
export type MonitoringSite = z.infer<typeof monitoringSiteSchema>;

export const monitoringSchema = z.object({
  summary: z.object({
    total: z.number().int().nonnegative(),
    up: z.number().int().nonnegative(),
    down: z.number().int().nonnegative(),
    fleet_uptime_30d: z.number().nullable(),
    slowest_ms: z.number().int().nullable(),
  }),
  sites: z.array(monitoringSiteSchema),
  generated_at: z.string(),
});
export type Monitoring = z.infer<typeof monitoringSchema>;

// P3.3 — operator notification settings.
// P5.2 — report branding sub-schema.
export const reportBrandingSchema = z.object({
  agency_name: z.string(),
  accent_color: z.string(),
  logo_url: z.string(),
});
export type ReportBranding = z.infer<typeof reportBrandingSchema>;

export const settingsSchema = z.object({
  slack_webhook_url: z.string().nullable(),
  report_branding: reportBrandingSchema,
});
export type Settings = z.infer<typeof settingsSchema>;

// P4.2 — Security fleet page schemas.
export const fleetSiteSecuritySchema = z.object({
  site_id: z.number().int().positive(),
  label: z.string(),
  url: z.string(),
  last_security_scan_at: z.string().nullable(),
  counts: z.object({
    critical: z.number().int().nonnegative(),
    high: z.number().int().nonnegative(),
    medium: z.number().int().nonnegative(),
    low: z.number().int().nonnegative(),
    total: z.number().int().nonnegative(),
  }),
});
export type FleetSiteSecurity = z.infer<typeof fleetSiteSecuritySchema>;

export const securitySchema = z.object({
  summary: z.object({
    total_sites: z.number().int().nonnegative(),
    scanned_sites: z.number().int().nonnegative(),
    sites_at_risk: z.number().int().nonnegative(),
    critical: z.number().int().nonnegative(),
    high: z.number().int().nonnegative(),
    medium: z.number().int().nonnegative(),
    low: z.number().int().nonnegative(),
  }),
  sites: z.array(fleetSiteSecuritySchema),
  generated_at: z.string(),
});
export type Security = z.infer<typeof securitySchema>;

export const scanAllSecurityResponseSchema = z.object({
  scheduled_count: z.number().int().nonnegative(),
  site_ids: z.array(z.number().int()),
  scheduled_at: z.string(),
});
export type ScanAllSecurityResponse = z.infer<typeof scanAllSecurityResponseSchema>;

// P6.1 — Site-detail latest PageSpeed snapshot (from SitePerformance::toJson).
// Distinct from reportPerformanceSchema, which is the nested block on the
// maintenance report; this is the standalone snapshot row.
export const sitePerformanceSchema = z.object({
  id: z.number(),
  site_id: z.number(),
  mobile_score: z.number().nullable(),
  mobile_lcp_ms: z.number().nullable(),
  mobile_cls: z.number().nullable(),
  mobile_inp_ms: z.number().nullable(),
  desktop_score: z.number().nullable(),
  desktop_lcp_ms: z.number().nullable(),
  desktop_cls: z.number().nullable(),
  desktop_inp_ms: z.number().nullable(),
  fetched_at: z.string(),
  created_at: z.string(),
});
export type SitePerformance = z.infer<typeof sitePerformanceSchema>;

export const sitePerformanceResponseSchema = z.object({
  data: z.object({ latest: sitePerformanceSchema.nullable() }),
  error: z.null(),
});

// P6.2 — Site-detail latest GA4 analytics snapshot (from SiteAnalytics::toJson).
// Distinct from reportAnalyticsSchema, which is the nested block on the
// maintenance report; this is the standalone snapshot row.
export const siteAnalyticsSnapshotSchema = z.object({
  id: z.number(),
  site_id: z.number(),
  period_start: z.string(),
  period_end: z.string(),
  sessions: z.number().nullable(),
  total_users: z.number().nullable(),
  screen_page_views: z.number().nullable(),
  avg_session_duration: z.number().nullable(),
  top_pages: z.array(z.object({ path: z.string(), title: z.string(), views: z.number() })),
  channels: z.array(z.object({ channel: z.string(), sessions: z.number() })),
  fetched_at: z.string(),
});
export type SiteAnalyticsSnapshot = z.infer<typeof siteAnalyticsSnapshotSchema>;

export const siteAnalyticsResponseSchema = z.object({
  data: z.object({
    latest: siteAnalyticsSnapshotSchema.nullable(),
    ga4_property_id: z.string().nullable(),
  }),
  error: z.null(),
});
export type SiteAnalyticsResponse = z.infer<typeof siteAnalyticsResponseSchema>;

// P6.3 — Fleet Insights (/insights) schemas. Read-only rollup of the latest
// per-site performance + analytics snapshots.
export const performanceFleetRowSchema = z.object({
  site_id: z.number().int().positive(),
  label: z.string(),
  url: z.string(),
  mobile_score: z.number().int().nullable(),
  desktop_score: z.number().int().nullable(),
  mobile_lcp_ms: z.number().int().nullable(),
  fetched_at: z.string().nullable(),
});
export type PerformanceFleetRow = z.infer<typeof performanceFleetRowSchema>;

export const analyticsFleetRowSchema = z.object({
  site_id: z.number().int().positive(),
  label: z.string(),
  url: z.string(),
  ga4_property_id: z.string().nullable(),
  sessions: z.number().int().nullable(),
  total_users: z.number().int().nullable(),
  screen_page_views: z.number().int().nullable(),
  avg_session_duration: z.number().nullable(),
  period_start: z.string().nullable(),
  period_end: z.string().nullable(),
  fetched_at: z.string().nullable(),
});
export type AnalyticsFleetRow = z.infer<typeof analyticsFleetRowSchema>;

export const insightsSchema = z.object({
  performance: z.object({
    summary: z.object({
      total_sites: z.number().int().nonnegative(),
      measured: z.number().int().nonnegative(),
      avg_mobile: z.number().int().nullable(),
      avg_desktop: z.number().int().nullable(),
      slow_sites: z.number().int().nonnegative(),
    }),
    sites: z.array(performanceFleetRowSchema),
  }),
  analytics: z.object({
    summary: z.object({
      total_sites: z.number().int().nonnegative(),
      connected: z.number().int().nonnegative(),
      total_sessions: z.number().int().nonnegative(),
      total_users: z.number().int().nonnegative(),
    }),
    sites: z.array(analyticsFleetRowSchema),
  }),
  generated_at: z.string(),
});
export type Insights = z.infer<typeof insightsSchema>;

// v0.31.0 — connector self-update.
export const connectorLatestReleaseSchema = z.object({
  version: z.string(),
  package_url: z.string(),
  sha256: z.string(),
});
export type ConnectorLatestRelease = z.infer<typeof connectorLatestReleaseSchema>;

export const connectorUpdateResponseSchema = z.object({
  scheduled: z.boolean(),
  site_id: z.number().int().positive(),
  target_version: z.string(),
});
export type ConnectorUpdateResponse = z.infer<typeof connectorUpdateResponseSchema>;

export const updateAllConnectorsResponseSchema = z.object({
  scheduled_count: z.number().int().nonnegative(),
  target_version: z.string(),
});
export type UpdateAllConnectorsResponse = z.infer<typeof updateAllConnectorsResponseSchema>;
