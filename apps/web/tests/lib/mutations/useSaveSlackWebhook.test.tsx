import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, describe, expect, it } from 'vitest';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { useSaveSlackWebhook } from '@/lib/mutations/useSaveSlackWebhook';
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

describe('useSaveSlackWebhook', () => {
  beforeEach(() => {
    setAccessToken('fake');
  });

  it('posts webhook_url and returns updated settings', async () => {
    let capturedBody: unknown = null;
    server.use(
      http.post('*/wp-json/defyn/v1/settings/slack-webhook', async ({ request }) => {
        capturedBody = await request.json();
        return HttpResponse.json({ slack_webhook_url: 'https://hooks.slack.com/services/T0/B0/xxx' });
      })
    );

    const wrapper = makeWrapper();
    const { result } = renderHook(() => useSaveSlackWebhook(), { wrapper });
    result.current.mutate('https://hooks.slack.com/services/T0/B0/xxx');

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(capturedBody).toEqual({ webhook_url: 'https://hooks.slack.com/services/T0/B0/xxx' });
    expect(result.current.data?.slack_webhook_url).toBe('https://hooks.slack.com/services/T0/B0/xxx');
  });

  it('can post a null webhook_url to clear the webhook', async () => {
    server.use(
      http.post('*/wp-json/defyn/v1/settings/slack-webhook', async ({ request }) => {
        const body = (await request.json()) as { webhook_url?: string | null };
        return HttpResponse.json({ slack_webhook_url: body.webhook_url ?? null });
      })
    );

    const wrapper = makeWrapper();
    const { result } = renderHook(() => useSaveSlackWebhook(), { wrapper });
    result.current.mutate('');

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.slack_webhook_url).toBe('');
  });

  it('invalidates the settings query on success', async () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    client.setQueryData(['settings'], { slack_webhook_url: null });

    server.use(
      http.post('*/wp-json/defyn/v1/settings/slack-webhook', () =>
        HttpResponse.json({ slack_webhook_url: 'https://hooks.slack.com/services/new' })
      )
    );

    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    );
    const { result } = renderHook(() => useSaveSlackWebhook(), { wrapper });
    result.current.mutate('https://hooks.slack.com/services/new');

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(client.getQueryState(['settings'])?.isInvalidated).toBe(true);
  });

  it('surfaces API errors so the caller can show a toast', async () => {
    server.use(
      http.post('*/wp-json/defyn/v1/settings/slack-webhook', () =>
        HttpResponse.json(
          { error: { code: 'settings.invalid_webhook', message: 'Invalid Slack webhook URL.' } },
          { status: 400 }
        )
      )
    );

    const wrapper = makeWrapper();
    const { result } = renderHook(() => useSaveSlackWebhook(), { wrapper });
    result.current.mutate('not-a-valid-url');

    await waitFor(() => expect(result.current.isError).toBe(true));
  });
});
