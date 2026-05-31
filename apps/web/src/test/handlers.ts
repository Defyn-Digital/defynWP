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

import type { Site } from '@/types/api';

// In-memory site store for MSW — tests can manipulate this directly between requests.
export const mockSites: Site[] = [];
export let nextSiteId = 1;

export function resetMockSites(): void {
  mockSites.length = 0;
  nextSiteId = 1;
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
