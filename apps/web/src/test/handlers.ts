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
    };
    mockSites.push(site);
    return HttpResponse.json({ site_id: site.id }, { status: 202 });
  }),

  // GET /sites — list.
  http.get('*/wp-json/defyn/v1/sites', () => HttpResponse.json({ sites: mockSites }, { status: 200 })),

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
    return HttpResponse.json(site, { status: 200 });
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
      const themes = mockSiteThemes[siteId] ?? [];
      // Update all themes to have a new last_synced_at (by mutating a marker on first theme)
      // Actually, we need to track last_synced_at separately. For themes, we return it from GET,
      // so we'll store it in a map.
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
);
