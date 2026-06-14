import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, describe, expect, it } from 'vitest';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { useToggleMuteAlerts } from '@/lib/mutations/useToggleMuteAlerts';
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

describe('useToggleMuteAlerts', () => {
  beforeEach(() => {
    setAccessToken('fake');
  });

  it('posts muted:true to /sites/:id/alerts/mute and returns new state', async () => {
    let capturedBody: unknown = null;
    server.use(
      http.post('*/wp-json/defyn/v1/sites/:id/alerts/mute', async ({ request, params }) => {
        capturedBody = await request.json();
        return HttpResponse.json({ site_id: Number(params.id), alerts_muted: true });
      })
    );

    const wrapper = makeWrapper();
    const { result } = renderHook(() => useToggleMuteAlerts(7), { wrapper });
    result.current.mutate(true);

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(capturedBody).toEqual({ muted: true });
    expect(result.current.data).toEqual({ site_id: 7, alerts_muted: true });
  });

  it('posts muted:false and returns alerts_muted:false', async () => {
    let capturedBody: unknown = null;
    server.use(
      http.post('*/wp-json/defyn/v1/sites/:id/alerts/mute', async ({ request, params }) => {
        capturedBody = await request.json();
        return HttpResponse.json({ site_id: Number(params.id), alerts_muted: false });
      })
    );

    const wrapper = makeWrapper();
    const { result } = renderHook(() => useToggleMuteAlerts(7), { wrapper });
    result.current.mutate(false);

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(capturedBody).toEqual({ muted: false });
    expect(result.current.data).toEqual({ site_id: 7, alerts_muted: false });
  });

  it('invalidates the site query on success', async () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    client.setQueryData(['sites', 7], { id: 7, alerts_muted: false });

    server.use(
      http.post('*/wp-json/defyn/v1/sites/:id/alerts/mute', () =>
        HttpResponse.json({ site_id: 7, alerts_muted: true })
      )
    );

    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    );
    const { result } = renderHook(() => useToggleMuteAlerts(7), { wrapper });
    result.current.mutate(true);

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(client.getQueryState(['sites', 7])?.isInvalidated).toBe(true);
  });

  it('surfaces 429 errors so the caller can show a toast', async () => {
    server.use(
      http.post('*/wp-json/defyn/v1/sites/:id/alerts/mute', () =>
        HttpResponse.json(
          { error: { code: 'sites.rate_limited', message: 'Too many requests.' } },
          { status: 429 }
        )
      )
    );

    const wrapper = makeWrapper();
    const { result } = renderHook(() => useToggleMuteAlerts(7), { wrapper });
    result.current.mutate(true);

    await waitFor(() => expect(result.current.isError).toBe(true));
  });
});
