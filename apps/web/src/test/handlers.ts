import { http, HttpResponse } from 'msw';

export const handlers = [
  // Default: login succeeds with a fake access token.
  http.post('*/wp-json/defyn/v1/auth/login', async ({ request }) => {
    const body = (await request.json()) as { email?: string; password?: string };
    if (!body.email || !body.password) {
      return HttpResponse.json(
        { error: { code: 'auth.missing_fields', message: 'Email and password are required.' } },
        { status: 400 },
      );
    }
    if (body.password === 'wrong') {
      return HttpResponse.json(
        { error: { code: 'auth.invalid_credentials', message: 'Invalid email or password.' } },
        { status: 401 },
      );
    }
    return HttpResponse.json({ access_token: 'fake.access.token' }, { status: 200 });
  }),

  // /auth/me returns a fixed user when given any Bearer token.
  http.get('*/wp-json/defyn/v1/auth/me', ({ request }) => {
    const auth = request.headers.get('Authorization') ?? '';
    if (!auth.startsWith('Bearer ')) {
      return HttpResponse.json(
        { error: { code: 'auth.missing_token', message: 'Authorization: Bearer <token> required.' } },
        { status: 401 },
      );
    }
    return HttpResponse.json({ id: 1, email: 'admin@defyn.test', display_name: 'Admin User' }, { status: 200 });
  }),

  // /auth/refresh — generic success.
  http.post('*/wp-json/defyn/v1/auth/refresh', () =>
    HttpResponse.json({ access_token: 'fake.access.token.v2' }, { status: 200 }),
  ),

  // /auth/logout — always 204.
  http.post('*/wp-json/defyn/v1/auth/logout', () => new HttpResponse(null, { status: 204 })),
];

import type { ActivityEvent, Site } from '@/types/api';
import type { Plugin } from '@/types/api/plugins';
import type { Theme } from '@/types/api/themes';

// In-memory site store for MSW — tests can manipulate this directly between requests.
export const mockSites: Site[] = [];
export let nextSiteId = 1;

export function resetMockSites(): void {
  mockSites.length = 0;
  nextSiteId = 1;
}

// In-memory activity event store for MSW — mirrors mockSites pattern.
export const mockActivityEvents: ActivityEvent[] = [];

export function resetMockActivity(): void {
  mockActivityEvents.length = 0;
}

export function seedMockActivity(): void {
  mockActivityEvents.push(
    { id: 1, site_id: 1, event_type: 'site.synced',    details: { wp_version: '6.9.4' },   created_at: '2026-05-31T01:00:00Z' },
    { id: 2, site_id: 1, event_type: 'site.health_ok', details: null,                       created_at: '2026-05-31T00:30:00Z' },
    { id: 3, site_id: 2, event_type: 'site.connected', details: { url: 'https://b.test' }, created_at: '2026-05-30T00:00:00Z' },
  );
}

// POST /sites — create pending site, returns 202.
handlers.push(
  http.post('*/wp-json/defyn/v1/sites', async ({ request }) => {
    const body = (await request.json()) as { url?: string; label?: string; code?: string };
    if (!body.url || !body.code) {
      return HttpResponse.json(
        { error: { code: 'sites.missing_fields', message: 'url and code required' } },
        { status: 400 },
      );
    }
    if (!body.url.startsWith('https://')) {
      return HttpResponse.json(
        { error: { code: 'sites.invalid_url', message: 'URL must use HTTPS' } },
        { status: 400 },
      );
    }
    if (mockSites.some((s) => s.url.toLowerCase() === body.url!.toLowerCase())) {
      return HttpResponse.json(
        { error: { code: 'sites.duplicate_url', message: 'This URL is already managed' } },
        { status: 409 },
      );
    }
    const site: Site = {
      id: nextSiteId++,
      url: body.url,
      label: body.label ?? '',
      status: 'pending',
      last_contact_at: null,
      last_sync_at: null,
      last_error: null,
      created_at: new Date().toISOString().replace('T', ' ').slice(0, 19),
      wp_version: null,
      php_version: null,
      active_theme: null,
      plugin_counts: null,
      theme_counts: null,
      ssl_status: null,
      ssl_expires_at: null,
      core_update_available: false,
      core_update_version: null,
      core_update_state: 'idle',
      last_core_update_error: null,
      last_core_update_attempt_at: null,
      core_allow_major: false,
      alerts_muted: false,
    };
    mockSites.push(site);
    return HttpResponse.json({ site_id: site.id }, { status: 202 });
  }),

  // GET /sites — list.
  http.get('*/wp-json/defyn/v1/sites', () => {
    return HttpResponse.json({ sites: mockSites }, { status: 200 });
  }),

  // GET /activity — global activity feed (paginated, filterable).
  http.get('*/wp-json/defyn/v1/activity', ({ request }) => {
    const url = new URL(request.url);
    const eventType = url.searchParams.get('event_type');
    const siteIdParam = url.searchParams.get('site_id');
    const page = Number(url.searchParams.get('page') ?? '1');
    const perPage = Number(url.searchParams.get('per_page') ?? '50');

    // Newest first (mirrors the server's ORDER BY created_at DESC, id DESC).
    const sorted = [...mockActivityEvents].sort((a, b) => {
      if (a.created_at !== b.created_at) return b.created_at.localeCompare(a.created_at);
      return b.id - a.id;
    });
    const filtered = sorted.filter((e) =>
      (eventType === null || e.event_type === eventType) &&
      (siteIdParam === null || e.site_id === Number(siteIdParam))
    );
    const start = (Math.max(1, page) - 1) * perPage;
    const slice = filtered.slice(start, start + perPage);
    return HttpResponse.json(
      { events: slice, total: filtered.length, page, per_page: perPage },
      { status: 200 },
    );
  }),

  // GET /sites/{id}/activity — per-site activity feed (paginated).
  http.get('*/wp-json/defyn/v1/sites/:id/activity', ({ params, request }) => {
    const siteId = Number(params.id);
    const url = new URL(request.url);
    const page = Number(url.searchParams.get('page') ?? '1');
    const perPage = Number(url.searchParams.get('per_page') ?? '10');

    const sorted = [...mockActivityEvents].sort((a, b) => {
      if (a.created_at !== b.created_at) return b.created_at.localeCompare(a.created_at);
      return b.id - a.id;
    });
    const filtered = sorted.filter((e) => e.site_id === siteId);
    const start = (Math.max(1, page) - 1) * perPage;
    const slice = filtered.slice(start, start + perPage);
    return HttpResponse.json(
      { events: slice, total: filtered.length, page, per_page: perPage },
      { status: 200 },
    );
  }),

  // GET /sites/{id} — show.
  http.get('*/wp-json/defyn/v1/sites/:id', ({ params }) => {
    const id = Number(params.id);
    const site = mockSites.find((s) => s.id === id);
    if (!site) {
      return HttpResponse.json(
        { error: { code: 'sites.not_found', message: 'Site not found' } },
        { status: 404 },
      );
    }
    // P2.4 — merge in core state if present, otherwise use defaults.
    const defaultCore = {
      core_update_available: false,
      core_update_version: null,
      core_update_state: 'idle' as const,
      last_core_update_error: null,
      last_core_update_attempt_at: null,
    };
    const coreState = mockSiteCoreState[id] ?? defaultCore;
    const response = { ...site, ...coreState };
    return HttpResponse.json(response, { status: 200 });
  }),

  // POST /sites/{id}/sync — schedule an immediate sync, returns 202.
  http.post('*/wp-json/defyn/v1/sites/:id/sync', ({ params }) => {
    const id = Number(params.id);
    if (!mockSites.some((s) => s.id === id)) {
      return HttpResponse.json(
        { error: { code: 'sites.not_found', message: 'Site not found' } },
        { status: 404 },
      );
    }
    return HttpResponse.json({ site_id: id, scheduled: true }, { status: 202 });
  }),

  // POST /sites/{id}/ping — schedule an immediate ping, returns 202.
  http.post('*/wp-json/defyn/v1/sites/:id/ping', ({ params }) => {
    const id = Number(params.id);
    if (!mockSites.some((s) => s.id === id)) {
      return HttpResponse.json(
        { error: { code: 'sites.not_found', message: 'Site not found' } },
        { status: 404 },
      );
    }
    return HttpResponse.json({ site_id: id, scheduled: true }, { status: 202 });
  }),

  // DELETE /sites/{id} — soft disconnect, returns 204.
  http.delete('*/wp-json/defyn/v1/sites/:id', ({ params }) => {
    const id = Number(params.id);
    const idx = mockSites.findIndex((s) => s.id === id);
    if (idx === -1) {
      return HttpResponse.json(
        { error: { code: 'sites.not_found', message: 'Site not found' } },
        { status: 404 },
      );
    }
    mockSites.splice(idx, 1);
    return new HttpResponse(null, { status: 204 });
  }),
);

// Helper for tests/dev: seeds mockSites with one site of each status,
// covering active/offline/error/pending. Tests that need the seed call this
// after resetMockSites(). Idempotent against an already-empty store.
export function seedMockSitesAllStatuses(): void {
  const seedTime = '2026-05-31T00:00:00Z';
  const createdAt = '2026-05-01 00:00:00';
  mockSites.push(
    {
      id: nextSiteId++,
      url: 'https://example.test',
      label: 'Example',
      status: 'active',
      last_contact_at: seedTime,
      last_sync_at: seedTime,
      last_error: null,
      created_at: createdAt,
      wp_version: '6.9.4',
      php_version: '8.2.27',
      active_theme: { name: 'Twenty Twenty-Four', version: '1.0', parent: null },
      plugin_counts: { installed: 10, active: 5 },
      theme_counts: { installed: 2, active: 1 },
      ssl_status: 'enabled',
      ssl_expires_at: '2027-01-01T00:00:00Z',
      core_update_available: false,
      core_update_version: null,
      core_update_state: 'idle',
      last_core_update_error: null,
      last_core_update_attempt_at: null,
      core_allow_major: false,
      alerts_muted: false,
    },
    {
      id: nextSiteId++,
      url: 'https://offline.test',
      label: 'Offline Site',
      status: 'offline',
      last_contact_at: '2026-05-30T00:00:00Z',
      last_sync_at: '2026-05-30T00:00:00Z',
      last_error: 'host unreachable',
      created_at: createdAt,
      wp_version: '6.8.0',
      php_version: '8.2.0',
      active_theme: null,
      plugin_counts: null,
      theme_counts: null,
      ssl_status: 'unknown',
      ssl_expires_at: null,
      core_update_available: false,
      core_update_version: null,
      core_update_state: 'idle',
      last_core_update_error: null,
      last_core_update_attempt_at: null,
      core_allow_major: false,
      alerts_muted: false,
    },
    {
      id: nextSiteId++,
      url: 'https://broken.test',
      label: 'Broken Site',
      status: 'error',
      last_contact_at: null,
      last_sync_at: null,
      last_error: 'Challenge signature invalid',
      created_at: createdAt,
      wp_version: null,
      php_version: null,
      active_theme: null,
      plugin_counts: null,
      theme_counts: null,
      ssl_status: null,
      ssl_expires_at: null,
      core_update_available: false,
      core_update_version: null,
      core_update_state: 'idle',
      last_core_update_error: null,
      last_core_update_attempt_at: null,
      core_allow_major: false,
      alerts_muted: false,
    },
    {
      id: nextSiteId++,
      url: 'https://pending.test',
      label: 'Pending Site',
      status: 'pending',
      last_contact_at: null,
      last_sync_at: null,
      last_error: null,
      created_at: createdAt,
      wp_version: null,
      php_version: null,
      active_theme: null,
      plugin_counts: null,
      theme_counts: null,
      ssl_status: null,
      ssl_expires_at: null,
      core_update_available: false,
      core_update_version: null,
      core_update_state: 'idle',
      last_core_update_error: null,
      last_core_update_attempt_at: null,
      core_allow_major: false,
      alerts_muted: false,
    },
  );
}

// === P2.1 plugin inventory ===
// In-memory plugin store, keyed by siteId. Tests can read/write directly.
export const mockSitePlugins: Record<number, { plugins: Plugin[]; last_synced_at: string | null }> = {};

export function resetMockSitePlugins(): void {
  for (const key of Object.keys(mockSitePlugins)) {
    delete mockSitePlugins[Number(key)];
  }
}

// P2.3 — themes mock store
export const mockSiteThemes: Record<number, Theme[]> = {};
export let mockThemesLastSynced: Record<number, string> = {};

export function resetMockSiteThemes(): void {
  for (const k of Object.keys(mockSiteThemes)) {
    delete mockSiteThemes[Number(k)];
  }
  mockThemesLastSynced = {};
}

// P2.4 — core update state mock store
export const mockSiteCoreState: Record<
  number,
  {
    core_update_available: boolean;
    core_update_version: string | null;
    core_update_state: 'idle' | 'queued' | 'updating' | 'failed';
    last_core_update_error: string | null;
    last_core_update_attempt_at: string | null;
    is_minor_update?: boolean;
    is_auto_update_enabled?: boolean;
  }
> = {};

export function resetMockSiteCoreState(): void {
  for (const k of Object.keys(mockSiteCoreState)) {
    delete mockSiteCoreState[Number(k)];
  }
}

// P2.9 — bulk-jobs fixtures shared by the /jobs handlers.
const MOCK_JOB = {
  id: 42,
  kind: 'plugin_update',
  scheduled_count: 3,
  skipped_count: 0,
  succeeded_count: 1,
  failed_count: 1,
  cancelled_count: 0,
  queued_count: 1,
  started_count: 0,
  state: 'in_progress',
  started_at: '2026-06-09 21:00:00',
  completed_at: null,
  created_at: '2026-06-09 20:59:15',
};

const MOCK_JOB_ITEMS = [
  { id: 201, site_id: 1, site_label: 'SmartCoding', resource_slug: 'akismet', resource_name: 'Akismet Anti-Spam', current_version: '5.3', target_version: '5.3.1', state: 'succeeded', error_message: null, started_at: '2026-06-09 21:00:02', completed_at: '2026-06-09 21:00:11', created_at: '2026-06-09 20:59:15' },
  { id: 202, site_id: 1, site_label: 'SmartCoding', resource_slug: 'elementor', resource_name: 'Elementor', current_version: '3.18.2', target_version: '4.0.0', state: 'failed', error_message: 'Could not copy file.', started_at: '2026-06-09 21:00:12', completed_at: '2026-06-09 21:00:40', created_at: '2026-06-09 20:59:15' },
  { id: 203, site_id: 2, site_label: 'AcmeBlog', resource_slug: 'yoast', resource_name: 'Yoast SEO', current_version: '22.5', target_version: '22.6', state: 'queued', error_message: null, started_at: null, completed_at: null, created_at: '2026-06-09 20:59:15' },
];

handlers.push(
  http.get('*/wp-json/defyn/v1/sites/:id/plugins', ({ params }) => {
    const siteId = Number(params.id);
    const bucket = mockSitePlugins[siteId] ?? { plugins: [], last_synced_at: null };
    return HttpResponse.json(
      {
        plugins: bucket.plugins,
        total: bucket.plugins.length,
        last_synced_at: bucket.last_synced_at,
      },
      { status: 200 },
    );
  }),

  http.post('*/wp-json/defyn/v1/sites/:id/plugins/refresh', ({ params }) => {
    const siteId = Number(params.id);
    // Test default: bump last_synced_at on the in-memory store after a short delay,
    // so the useRefreshSitePlugins polling test (Task 15) can observe the advance.
    setTimeout(() => {
      const bucket = mockSitePlugins[siteId] ?? { plugins: [], last_synced_at: null };
      mockSitePlugins[siteId] = { ...bucket, last_synced_at: new Date().toISOString() };
    }, 20);
    return HttpResponse.json({ scheduled: true, site_id: siteId }, { status: 202 });
  }),

  // P2.2 — simulate POST /sites/:id/plugins/:slug/update.
  // Returns 202 immediately and schedules deferred state transitions
  // (queued -> updating @ 50ms -> idle @ 200ms) so the polling tests in
  // Task 18 have real state changes to observe.
  http.post('*/wp-json/defyn/v1/sites/:id/plugins/:slug/update', ({ params }) => {
    const siteId = Number(params.id);
    const slug = String(params.slug);

    const bucket = mockSitePlugins[siteId];
    const idx = bucket?.plugins.findIndex((p) => p.slug === slug);
    if (!bucket || idx === undefined || idx === -1) {
      return HttpResponse.json(
        {
          error: {
            code: 'plugins.not_found_in_inventory',
            message: 'Plugin not in inventory.',
          },
        },
        { status: 404 },
      );
    }

    // Optimistic transition: idle -> queued.
    mockSitePlugins[siteId] = {
      ...bucket,
      plugins: bucket.plugins.map((p, i) =>
        i === idx ? { ...p, update_state: 'queued' as const } : p,
      ),
    };

    // queued -> updating @ 50ms
    setTimeout(() => {
      const current = mockSitePlugins[siteId];
      const target = current?.plugins.find((p) => p.slug === slug);
      if (!current || !target || target.update_state !== 'queued') return;
      mockSitePlugins[siteId] = {
        ...current,
        plugins: current.plugins.map((p) =>
          p.slug === slug ? { ...p, update_state: 'updating' as const } : p,
        ),
      };
    }, 50);

    // updating -> idle (with version bumped + update cleared) @ 200ms
    setTimeout(() => {
      const current = mockSitePlugins[siteId];
      const target = current?.plugins.find((p) => p.slug === slug);
      if (!current || !target || target.update_state !== 'updating') return;
      mockSitePlugins[siteId] = {
        ...current,
        plugins: current.plugins.map((p) =>
          p.slug === slug
            ? {
                ...p,
                update_state: 'idle' as const,
                version: p.update_version ?? p.version,
                update_available: false,
                update_version: null,
                last_update_attempt_at: new Date().toISOString(),
                last_update_error: null,
              }
            : p,
        ),
      };
    }, 200);

    return HttpResponse.json({ scheduled: true, site_id: siteId, slug }, { status: 202 });
  }),

  // P2.3 — GET /sites/:id/themes
  http.get('*/wp-json/defyn/v1/sites/:id/themes', ({ params }) => {
    const siteId = Number(params.id);
    const themes = mockSiteThemes[siteId] ?? [];
    const lastSyncedAt = mockThemesLastSynced?.[siteId] ?? (themes.length > 0 ? '2026-06-06 05:00:00' : null);
    return HttpResponse.json({ themes, last_synced_at: lastSyncedAt });
  }),

  // P2.3 — POST /sites/:id/themes/refresh
  http.post('*/wp-json/defyn/v1/sites/:id/themes/refresh', ({ params }) => {
    const siteId = Number(params.id);
    // Test default: bump last_synced_at on the in-memory store after a short delay,
    // so the useRefreshSiteThemes polling test can observe the advance.
    setTimeout(() => {
      // Track last_synced_at separately in a per-site map; the GET handler reads from it.
      if (!mockThemesLastSynced) mockThemesLastSynced = {};
      mockThemesLastSynced[siteId] = new Date().toISOString();
    }, 20);
    return HttpResponse.json({ scheduled: true, site_id: siteId }, { status: 202 });
  }),

  // P2.3 — POST /sites/:id/themes/:slug/update
  // Returns 202 immediately and schedules deferred state transitions
  // (queued -> updating @ 50ms -> idle @ 200ms) so the polling tests have real state changes to observe.
  http.post('*/wp-json/defyn/v1/sites/:id/themes/:slug/update', ({ params }) => {
    const siteId = Number(params.id);
    const slug = String(params.slug);
    const idx = mockSiteThemes[siteId]?.findIndex((t) => t.slug === slug);
    if (idx === undefined || idx === -1) {
      return HttpResponse.json(
        {
          error: {
            code: 'themes.not_found_in_inventory',
            message: 'Theme not in inventory.',
          },
        },
        { status: 404 },
      );
    }

    // Optimistic transition: idle -> queued.
    mockSiteThemes[siteId] = mockSiteThemes[siteId].map((t, i) =>
      i === idx ? { ...t, update_state: 'queued' as const } : t,
    );

    // queued -> updating @ 50ms
    setTimeout(() => {
      const current = mockSiteThemes[siteId];
      const target = current?.find((t) => t.slug === slug);
      if (!current || !target || target.update_state !== 'queued') return;
      mockSiteThemes[siteId] = current.map((t) =>
        t.slug === slug ? { ...t, update_state: 'updating' as const } : t,
      );
    }, 50);

    // updating -> idle (with version bumped + update cleared) @ 200ms
    setTimeout(() => {
      const current = mockSiteThemes[siteId];
      const target = current?.find((t) => t.slug === slug);
      if (!current || !target || target.update_state !== 'updating') return;
      mockSiteThemes[siteId] = current.map((t) =>
        t.slug === slug
          ? {
              ...t,
              update_state: 'idle' as const,
              version: t.update_version ?? t.version,
              update_available: false,
              update_version: null,
              last_update_attempt_at: new Date().toISOString(),
              last_update_error: null,
            }
          : t,
      );
    }, 200);

    return HttpResponse.json({ scheduled: true, site_id: siteId, slug, update_state: 'queued' }, { status: 202 });
  }),

  // P2.4.1 — POST /sites/:id/core/allow-major
  http.post('*/wp-json/defyn/v1/sites/:id/core/allow-major', async ({ request, params }) => {
    const siteId = Number(params.id);
    const body = (await request.json()) as { allow?: boolean };
    const allow = body.allow === true;
    // Persist the toggle into the site object if it exists in mockSites.
    const site = mockSites.find((s) => s.id === siteId);
    if (site) {
      (site as Record<string, unknown>).core_allow_major = allow;
    }
    return HttpResponse.json({ site_id: siteId, core_allow_major: allow }, { status: 200 });
  }),

  // P2.4 — POST /sites/:id/core/refresh
  http.post('*/wp-json/defyn/v1/sites/:id/core/refresh', ({ params }) => {
    const siteId = Number(params.id);
    return HttpResponse.json({ scheduled: true, site_id: siteId }, { status: 202 });
  }),

  // P2.5 — GET /overview — empty payload by default; tests override via server.use().
  http.get('*/wp-json/defyn/v1/overview', () => {
    return HttpResponse.json({
      pending_updates: {
        plugins: 0,
        themes: 0,
        cores_minor: 0,
        cores_major: 0,
        sites_with_any_update: 0,
      },
      sites_needing_attention: [],
      recent_activity: [],
      total_sites: 0,
      generated_at: '2026-06-07 11:30:00',
      open_incidents: [],
    });
  }),

  // P3.1 — GET /sites/:id/incidents — empty list by default.
  http.get('*/wp-json/defyn/v1/sites/:id/incidents', () => {
    return HttpResponse.json({ data: { incidents: [] }, error: null });
  }),

  // P3.2 — GET /monitoring — empty fleet by default; tests override via server.use().
  http.get('*/wp-json/defyn/v1/monitoring', () => {
    return HttpResponse.json({
      summary: { total: 0, up: 0, down: 0, fleet_uptime_30d: null, slowest_ms: null },
      sites: [],
      generated_at: '2026-06-14 03:35:00',
    });
  }),

  // P2.6 — POST /overview/sync-all — default synthetic 200; tests override via server.use().
  http.post('*/wp-json/defyn/v1/overview/sync-all', () => {
    return HttpResponse.json(
      {
        scheduled_count: 0,
        site_ids: [],
        scheduled_at: '2026-06-08 09:30:42',
      },
      { status: 200 },
    );
  }),

  // P2.7 — GET /overview/pending-plugin-updates default empty list.
  http.get('*/wp-json/defyn/v1/overview/pending-plugin-updates', () => {
    return HttpResponse.json({
      pending_updates: [],
      generated_at: '2026-06-09 23:15:00',
    });
  }),

  // P2.7 — POST /overview/bulk-update-plugins default synthetic 202.
  http.post('*/wp-json/defyn/v1/overview/bulk-update-plugins', async ({ request }) => {
    const body = (await request.json()) as { updates: Array<{ site_id: number; slug: string }> };
    return HttpResponse.json(
      {
        job_id: body.updates.length > 0 ? 42 : null,
        scheduled_count: body.updates.length,
        skipped_count: 0,
        scheduled_pairs: body.updates,
        skipped_pairs: [],
        scheduled_at: '2026-06-09 23:15:42',
      },
      { status: body.updates.length > 0 ? 202 : 200 },
    );
  }),

  // P2.8 — GET /overview/pending-theme-updates default empty list.
  http.get('*/wp-json/defyn/v1/overview/pending-theme-updates', () => {
    return HttpResponse.json({
      pending_updates: [],
      generated_at: '2026-06-09 23:45:00',
    });
  }),

  // P2.8 — POST /overview/bulk-update-themes default synthetic 202.
  http.post('*/wp-json/defyn/v1/overview/bulk-update-themes', async ({ request }) => {
    const body = (await request.json()) as { updates: Array<{ site_id: number; slug: string }> };
    return HttpResponse.json(
      {
        job_id: body.updates.length > 0 ? 42 : null,
        scheduled_count: body.updates.length,
        skipped_count: 0,
        scheduled_pairs: body.updates,
        skipped_pairs: [],
        scheduled_at: '2026-06-09 23:45:42',
      },
      { status: body.updates.length > 0 ? 202 : 200 },
    );
  }),

  // P2.9 — GET /jobs default list (one in_progress job; completed filter empty).
  http.get('*/wp-json/defyn/v1/jobs', ({ request }) => {
    const url = new URL(request.url);
    const status = url.searchParams.get('status') ?? 'all';
    const jobs = status === 'completed' ? [] : [MOCK_JOB];
    return HttpResponse.json({
      jobs,
      total: jobs.length,
      page: Number(url.searchParams.get('page') ?? '1'),
      per_page: Number(url.searchParams.get('per_page') ?? '20'),
      generated_at: '2026-06-09 21:30:00',
    });
  }),

  // P2.9 — GET /jobs/:id default detail.
  http.get('*/wp-json/defyn/v1/jobs/:id', ({ params }) => {
    return HttpResponse.json({
      job: { ...MOCK_JOB, id: Number(params.id) },
      items: MOCK_JOB_ITEMS,
      generated_at: '2026-06-09 21:30:00',
    });
  }),

  // P2.9 — POST /jobs/:id/cancel default synchronous 200.
  http.post('*/wp-json/defyn/v1/jobs/:id/cancel', () => {
    return HttpResponse.json(
      { cancelled_count: 1, still_running_count: 0, cancelled_at: '2026-06-09 21:30:42' },
      { status: 200 },
    );
  }),

  // P2.9 — POST /jobs/:id/items/:itemId/retry default 202.
  http.post('*/wp-json/defyn/v1/jobs/:id/items/:itemId/retry', ({ params }) => {
    return HttpResponse.json(
      { item_id: Number(params.itemId), scheduled_at: '2026-06-09 21:35:00' },
      { status: 202 },
    );
  }),

  // P2.9 — POST /jobs/:id/retry-failed default 202.
  http.post('*/wp-json/defyn/v1/jobs/:id/retry-failed', () => {
    return HttpResponse.json(
      { retried_count: 1, retried_item_ids: [202], scheduled_at: '2026-06-09 21:40:00' },
      { status: 202 },
    );
  }),

  // P2.4 — POST /sites/:id/core/update
  // Returns 202 immediately and schedules deferred state transitions
  // (queued -> updating @ 50ms -> idle @ 200ms) so the polling tests have real state changes to observe.
  http.post('*/wp-json/defyn/v1/sites/:id/core/update', ({ params }) => {
    const siteId = Number(params.id);
    const state = mockSiteCoreState[siteId];
    if (!state || !state.core_update_available) {
      return HttpResponse.json(
        { error: { code: 'core.no_update_available_for_site', message: 'No update available.' } },
        { status: 409 },
      );
    }
    mockSiteCoreState[siteId] = { ...state, core_update_state: 'queued' };
    setTimeout(() => {
      const cur = mockSiteCoreState[siteId];
      if (cur && cur.core_update_state === 'queued') {
        mockSiteCoreState[siteId] = { ...cur, core_update_state: 'updating' };
      }
    }, 50);
    setTimeout(() => {
      const cur = mockSiteCoreState[siteId];
      if (!cur || cur.core_update_state !== 'updating') return;
      mockSiteCoreState[siteId] = {
        ...cur,
        core_update_state: 'idle',
        core_update_available: false,
        core_update_version: null,
      };
    }, 200);
    return HttpResponse.json(
      {
        scheduled: true,
        site_id: siteId,
        core_update_state: 'queued',
      },
      { status: 202 },
    );
  }),

  // P3.3 — GET /settings — returns current notification settings.
  http.get('*/wp-json/defyn/v1/settings', () => {
    return HttpResponse.json({ slack_webhook_url: null });
  }),

  // P3.3 — POST /settings/slack-webhook — update webhook URL.
  http.post('*/wp-json/defyn/v1/settings/slack-webhook', async ({ request }) => {
    const body = (await request.json()) as { webhook_url?: string | null };
    return HttpResponse.json({ slack_webhook_url: body.webhook_url ?? null });
  }),

  // P3.3 — POST /sites/:id/alerts/mute — mute alerts for a site.
  http.post('*/wp-json/defyn/v1/sites/:id/alerts/mute', ({ params }) => {
    return HttpResponse.json({ site_id: Number(params.id), alerts_muted: true });
  }),
);
