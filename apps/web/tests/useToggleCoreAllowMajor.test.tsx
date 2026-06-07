import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, describe, expect, it } from 'vitest';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { useToggleCoreAllowMajor } from '@/lib/mutations/useToggleCoreAllowMajor';
import { server } from '@/test/setup';
import { setAccessToken } from '@/lib/apiClient';

function makeWrapper() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
}

describe('useToggleCoreAllowMajor', () => {
  beforeEach(() => {
    setAccessToken('fake');
  });

  it('posts allow:true to /sites/:id/core/allow-major and returns new state', async () => {
    let capturedBody: unknown = null;
    server.use(
      http.post('*/wp-json/defyn/v1/sites/:id/core/allow-major', async ({ request, params }) => {
        capturedBody = await request.json();
        return HttpResponse.json({
          site_id: Number(params.id),
          core_allow_major: true,
        });
      }),
    );

    const wrapper = makeWrapper();
    const { result } = renderHook(() => useToggleCoreAllowMajor(42), { wrapper });
    result.current.mutate(true);

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(capturedBody).toEqual({ allow: true });
    expect(result.current.data).toEqual({ site_id: 42, core_allow_major: true });
  });

  it('posts allow:false and returns core_allow_major:false', async () => {
    let capturedBody: unknown = null;
    server.use(
      http.post('*/wp-json/defyn/v1/sites/:id/core/allow-major', async ({ request, params }) => {
        capturedBody = await request.json();
        return HttpResponse.json({
          site_id: Number(params.id),
          core_allow_major: false,
        });
      }),
    );

    const wrapper = makeWrapper();
    const { result } = renderHook(() => useToggleCoreAllowMajor(42), { wrapper });
    result.current.mutate(false);

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(capturedBody).toEqual({ allow: false });
    expect(result.current.data).toEqual({ site_id: 42, core_allow_major: false });
  });

  it('invalidates the site query on success', async () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    client.setQueryData(['sites', 42], { id: 42, core_allow_major: false });

    server.use(
      http.post('*/wp-json/defyn/v1/sites/:id/core/allow-major', () =>
        HttpResponse.json({ site_id: 42, core_allow_major: true }),
      ),
    );

    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    );
    const { result } = renderHook(() => useToggleCoreAllowMajor(42), { wrapper });
    result.current.mutate(true);

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(client.getQueryState(['sites', 42])?.isInvalidated).toBe(true);
  });

  it('surfaces 429 errors so caller can show a toast', async () => {
    server.use(
      http.post('*/wp-json/defyn/v1/sites/:id/core/allow-major', () =>
        HttpResponse.json(
          { error: { code: 'core.rate_limited', message: 'Too many.' } },
          { status: 429 },
        ),
      ),
    );

    const wrapper = makeWrapper();
    const { result } = renderHook(() => useToggleCoreAllowMajor(42), { wrapper });
    result.current.mutate(true);

    await waitFor(() => expect(result.current.isError).toBe(true));
  });
});
