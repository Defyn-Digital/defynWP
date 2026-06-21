/**
 * Thin fetch wrapper that:
 *   - prepends the API base URL (`/api` in dev → vite proxy → wp-json; configurable for prod)
 *   - attaches Authorization: Bearer header when an access token is set in memory
 *   - sets credentials: 'include' so the refresh cookie travels
 *   - throws ApiError on non-2xx with the spec envelope's code + message exposed
 *
 * Auto-refresh-on-401 logic is added in Task 6.
 */

const API_BASE = import.meta.env.VITE_API_BASE ?? '/api/defyn/v1';

// Kinsta's server page-cache caches token-authenticated REST GETs (no WP login
// cookie → it treats them as anonymous), serving stale list/overview data after
// any change. The app already sends `Cache-Control: no-store`, but Kinsta ignores
// it for these routes — yet it bypasses its cache for URLs carrying a query
// string. So append a unique cache-buster to every GET. The seed makes URLs
// unique across page loads; the counter makes them unique within a session — so a
// stale cache entry can never be reused even if Kinsta keys on the full query.
const CACHE_BUSTER_PARAM = '_cb';
const CACHE_BUSTER_SEED = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
let cacheBusterSeq = 0;

function withCacheBuster(path: string): string {
  const sep = path.includes('?') ? '&' : '?';
  return `${path}${sep}${CACHE_BUSTER_PARAM}=${CACHE_BUSTER_SEED}${(++cacheBusterSeq).toString(36)}`;
}

let accessToken: string | null = null;

export function setAccessToken(token: string | null): void {
  accessToken = token;
}

export function clearAccessToken(): void {
  accessToken = null;
}

export function getAccessToken(): string | null {
  return accessToken;
}

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

interface RequestOptions {
  method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
}

async function request<T>(path: string, opts: RequestOptions, isRetry = false): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
  };
  if (opts.body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }
  if (accessToken) {
    headers['Authorization'] = `Bearer ${accessToken}`;
  }

  // GETs get a cache-buster so Kinsta's page-cache never serves them stale; POST/
  // PUT/PATCH/DELETE are never page-cached, so they go through untouched.
  const url = opts.method === 'GET' ? withCacheBuster(path) : path;
  const response = await fetch(`${API_BASE}${url}`, {
    method: opts.method,
    headers,
    body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
    credentials: 'include',
  });

  if (response.status === 204) {
    return undefined as T;
  }

  const contentType = response.headers.get('Content-Type') ?? '';
  const data = contentType.includes('application/json') ? await response.json() : null;

  // Auto-refresh-on-401 — only attempt once, and never on /auth/refresh itself.
  if (response.status === 401 && !isRetry && path !== '/auth/refresh') {
    const refreshed = await tryRefresh();
    if (refreshed) {
      return request<T>(path, opts, /*isRetry*/ true);
    }
    // Refresh failed; fall through to throw the original 401.
  }

  if (!response.ok) {
    const code = data?.error?.code ?? 'unknown';
    const message = data?.error?.message ?? `Request failed with status ${response.status}`;
    throw new ApiError(response.status, code, message);
  }

  return data as T;
}

/** Try to refresh the access token. Returns true on success, false on failure. */
async function tryRefresh(): Promise<boolean> {
  try {
    const data = await request<{ access_token: string }>('/auth/refresh', { method: 'POST' });
    setAccessToken(data.access_token);
    return true;
  } catch {
    clearAccessToken();
    return false;
  }
}

export const apiClient = {
  get: <T>(path: string) => request<T>(path, { method: 'GET' }),
  post: <T>(path: string, body?: unknown) => request<T>(path, { method: 'POST', body }),
  delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
  /**
   * Fetch a binary response (e.g. the branded report PDF) as a raw Blob.
   * Mirrors `request`'s auth + credentials handling but skips the JSON parse.
   */
  async getBlob(path: string): Promise<Blob> {
    const headers: Record<string, string> = {};
    if (accessToken) headers['Authorization'] = `Bearer ${accessToken}`;
    const res = await fetch(`${API_BASE}${withCacheBuster(path)}`, { headers, credentials: 'include' });
    if (!res.ok) throw new Error(`Download failed (${res.status})`);
    return res.blob();
  },
};
