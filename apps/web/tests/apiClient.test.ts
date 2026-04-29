import { describe, it, expect, beforeEach } from 'vitest';
import { apiClient, setAccessToken, clearAccessToken } from '@/lib/apiClient';
import { server } from '@/test/setup';
import { http, HttpResponse } from 'msw';

describe('apiClient', () => {
  beforeEach(() => {
    clearAccessToken();
  });

  it('GET returns parsed JSON on 2xx', async () => {
    setAccessToken('fake.access.token');
    const res = await apiClient.get<{ id: number; email: string; display_name: string }>('/auth/me');
    expect(res.id).toBe(1);
    expect(res.email).toBe('admin@defyn.test');
  });

  it('attaches Authorization: Bearer header when access token is set', async () => {
    setAccessToken('fake.access.token');
    let captured: string | null = null;
    server.use(
      http.get('*/wp-json/defyn/v1/auth/me', ({ request }) => {
        captured = request.headers.get('Authorization');
        return HttpResponse.json({ id: 1, email: 'x@x.test', display_name: 'X' }, { status: 200 });
      }),
    );
    await apiClient.get('/auth/me');
    expect(captured).toBe('Bearer fake.access.token');
  });

  it('omits Authorization header when no access token is set', async () => {
    let captured: string | null = null;
    server.use(
      http.post('*/wp-json/defyn/v1/auth/login', async ({ request }) => {
        captured = request.headers.get('Authorization');
        return HttpResponse.json({ access_token: 'x' }, { status: 200 });
      }),
    );
    await apiClient.post('/auth/login', { email: 'a@b', password: 'p' });
    expect(captured).toBeNull();
  });

  it('throws ApiError with status + envelope on 4xx', async () => {
    server.use(
      http.post('*/wp-json/defyn/v1/auth/login', () =>
        HttpResponse.json({ error: { code: 'auth.invalid_credentials', message: 'Invalid email or password.' } }, { status: 401 }),
      ),
    );
    await expect(apiClient.post('/auth/login', { email: 'a@b', password: 'wrong' })).rejects.toMatchObject({
      status: 401,
      code: 'auth.invalid_credentials',
      message: 'Invalid email or password.',
    });
  });

  it('sends credentials: include on every request', async () => {
    let captured: RequestCredentials | null = null;
    server.use(
      http.get('*/wp-json/defyn/v1/auth/me', ({ request }) => {
        captured = request.credentials;
        return HttpResponse.json({ id: 1, email: 'x@x.test', display_name: 'X' }, { status: 200 });
      }),
    );
    setAccessToken('t');
    await apiClient.get('/auth/me');
    expect(captured).toBe('include');
  });

  it('on 401, attempts refresh and retries the original request once', async () => {
    setAccessToken('expired.access.token');
    let attempt = 0;
    server.use(
      http.get('*/wp-json/defyn/v1/auth/me', ({ request }) => {
        attempt += 1;
        const auth = request.headers.get('Authorization');
        if (attempt === 1) {
          return HttpResponse.json(
            { error: { code: 'auth.invalid_token', message: 'Token is invalid or expired.' } },
            { status: 401 },
          );
        }
        // After refresh, attempt 2 should carry the new token.
        if (auth === 'Bearer fresh.access.token') {
          return HttpResponse.json({ id: 1, email: 'x@x.test', display_name: 'X' }, { status: 200 });
        }
        return HttpResponse.json({ error: { code: 'auth.invalid_token', message: '' } }, { status: 401 });
      }),
      http.post('*/wp-json/defyn/v1/auth/refresh', () =>
        HttpResponse.json({ access_token: 'fresh.access.token' }, { status: 200 }),
      ),
    );
    const res = await apiClient.get<{ id: number }>('/auth/me');
    expect(res.id).toBe(1);
    expect(attempt).toBe(2);
  });

  it('if refresh itself fails with 401, the original error propagates', async () => {
    setAccessToken('expired.access.token');
    server.use(
      http.get('*/wp-json/defyn/v1/auth/me', () =>
        HttpResponse.json({ error: { code: 'auth.invalid_token', message: 'expired' } }, { status: 401 }),
      ),
      http.post('*/wp-json/defyn/v1/auth/refresh', () =>
        HttpResponse.json({ error: { code: 'auth.refresh_revoked', message: 'gone' } }, { status: 401 }),
      ),
    );
    await expect(apiClient.get('/auth/me')).rejects.toMatchObject({ status: 401 });
  });

  it('does not infinite-loop on persistent 401 (refresh once, then give up)', async () => {
    setAccessToken('always.expired');
    let meAttempts = 0;
    server.use(
      http.get('*/wp-json/defyn/v1/auth/me', () => {
        meAttempts += 1;
        return HttpResponse.json({ error: { code: 'auth.invalid_token', message: 'expired' } }, { status: 401 });
      }),
      http.post('*/wp-json/defyn/v1/auth/refresh', () =>
        HttpResponse.json({ access_token: 'still.bad.token' }, { status: 200 }),
      ),
    );
    await expect(apiClient.get('/auth/me')).rejects.toMatchObject({ status: 401 });
    expect(meAttempts).toBeLessThanOrEqual(2);
  });
});
