/**
 * Regression tests for the single-flight token-refresh guard in apiClient.ts.
 *
 * Background: the refresh token is ROTATING + single-use. Before the fix, concurrent
 * 401s would each independently POST /auth/refresh; the first rotation revoked the
 * old jti so every subsequent spend returned auth.refresh_revoked → the SPA cleared
 * the session → users were logged out every ~15 min.
 *
 * The fix: a module-level `refreshInFlight` promise means all concurrent 401s share
 * ONE refresh call. These tests prove that guarantee holds.
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { apiClient, setAccessToken, clearAccessToken } from '@/lib/apiClient';
import { server } from '@/test/setup';
import { http, HttpResponse } from 'msw';

describe('apiClient single-flight refresh guard', () => {
  beforeEach(() => {
    clearAccessToken();
  });

  it('shares one /auth/refresh call across concurrent 401s (prevents double-spend of the rotating token)', async () => {
    setAccessToken('expired.token');

    let refreshCalls = 0;

    server.use(
      // Both parallel GETs 401 when they carry the expired token; succeed on retry.
      http.get('*/wp-json/defyn/v1/overview', ({ request }) => {
        const auth = request.headers.get('Authorization') ?? '';
        if (auth.includes('expired.token')) {
          return HttpResponse.json(
            { error: { code: 'auth.token_expired', message: 'Token expired.' } },
            { status: 401 },
          );
        }
        return HttpResponse.json({ ok: true, source: 'overview' }, { status: 200 });
      }),

      http.get('*/wp-json/defyn/v1/sites', ({ request }) => {
        const auth = request.headers.get('Authorization') ?? '';
        if (auth.includes('expired.token')) {
          return HttpResponse.json(
            { error: { code: 'auth.token_expired', message: 'Token expired.' } },
            { status: 401 },
          );
        }
        return HttpResponse.json({ ok: true, source: 'sites' }, { status: 200 });
      }),

      // Count how many times the refresh endpoint is actually called.
      http.post('*/wp-json/defyn/v1/auth/refresh', () => {
        refreshCalls += 1;
        return HttpResponse.json({ access_token: 'fresh.token' }, { status: 200 });
      }),
    );

    // Fire both requests simultaneously — they will both receive 401 before either
    // has a chance to refresh, triggering the race condition the fix prevents.
    const [a, b] = await Promise.all([
      apiClient.get<{ ok: boolean; source: string }>('/overview'),
      apiClient.get<{ ok: boolean; source: string }>('/sites'),
    ]);

    // The rotating refresh token must be spent exactly once, never twice.
    expect(refreshCalls).toBe(1);

    // Both original requests must complete successfully after the single refresh.
    expect(a.ok).toBe(true);
    expect(b.ok).toBe(true);
  });

  it('a single 401 still refreshes once and retries successfully (baseline still works)', async () => {
    setAccessToken('expired.token');

    let refreshCalls = 0;

    server.use(
      http.get('*/wp-json/defyn/v1/overview', ({ request }) => {
        const auth = request.headers.get('Authorization') ?? '';
        if (auth.includes('expired.token')) {
          return HttpResponse.json(
            { error: { code: 'auth.token_expired', message: 'Token expired.' } },
            { status: 401 },
          );
        }
        return HttpResponse.json({ ok: true }, { status: 200 });
      }),

      http.post('*/wp-json/defyn/v1/auth/refresh', () => {
        refreshCalls += 1;
        return HttpResponse.json({ access_token: 'fresh.token' }, { status: 200 });
      }),
    );

    const result = await apiClient.get<{ ok: boolean }>('/overview');

    expect(refreshCalls).toBe(1);
    expect(result.ok).toBe(true);
  });
});
