import { describe, it, expect } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import { useSettings } from '@/lib/queries/useSettings';
import React from 'react';

function wrapper({ children }: { children: React.ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('useSettings', () => {
  it('returns parsed settings when the response is valid', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/settings', () =>
        HttpResponse.json({ slack_webhook_url: 'https://hooks.slack.com/services/abc' })
      )
    );

    const { result } = renderHook(() => useSettings(), { wrapper });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.slack_webhook_url).toBe('https://hooks.slack.com/services/abc');
  });

  it('returns null slack_webhook_url when not configured', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/settings', () =>
        HttpResponse.json({ slack_webhook_url: null })
      )
    );

    const { result } = renderHook(() => useSettings(), { wrapper });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.slack_webhook_url).toBeNull();
  });

  it('rejects a malformed response via Zod', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/settings', () =>
        HttpResponse.json({ totally_wrong: 'field' })
      )
    );

    const { result } = renderHook(() => useSettings(), { wrapper });
    await waitFor(() => expect(result.current.isError).toBe(true));
  });
});
