import { renderHook, act, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, beforeEach } from 'vitest';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/test/setup';
import { useSaveReportBranding } from '@/lib/mutations/useSaveReportBranding';
import { setAccessToken } from '@/lib/apiClient';

function makeWrapper() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return {
    wrapper: ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    ),
    client,
  };
}

describe('useSaveReportBranding', () => {
  beforeEach(() => {
    setAccessToken('fake');
  });

  it('POSTs to /settings/report-branding and the handler receives the body', async () => {
    let capturedBody: unknown = null;
    server.use(
      http.post('*/wp-json/defyn/v1/settings/report-branding', async ({ request }) => {
        capturedBody = await request.json();
        return HttpResponse.json({
          report_branding: { agency_name: 'Acme Co', accent_color: '#FF0000', logo_url: '' },
        });
      })
    );

    const { wrapper } = makeWrapper();
    const { result } = renderHook(() => useSaveReportBranding(), { wrapper });

    act(() => {
      result.current.save({ agency_name: 'Acme Co' });
    });

    await waitFor(() => expect(result.current.isPending).toBe(false));
    expect(capturedBody).toEqual({ agency_name: 'Acme Co' });
  });

  it('invalidates the settings query on success', async () => {
    server.use(
      http.post('*/wp-json/defyn/v1/settings/report-branding', () =>
        HttpResponse.json({
          report_branding: { agency_name: 'Defyn Digital', accent_color: '#26215C', logo_url: '' },
        })
      )
    );

    const { wrapper, client } = makeWrapper();
    client.setQueryData(['settings'], {
      slack_webhook_url: null,
      report_branding: { agency_name: 'Old Name', accent_color: '#000', logo_url: '' },
    });

    const { result } = renderHook(() => useSaveReportBranding(), { wrapper });

    act(() => {
      result.current.save({ agency_name: 'Defyn Digital' });
    });

    await waitFor(() => expect(result.current.isPending).toBe(false));
    expect(client.getQueryState(['settings'])?.isInvalidated).toBe(true);
  });

  it('surfaces API errors via the error field', async () => {
    server.use(
      http.post('*/wp-json/defyn/v1/settings/report-branding', () =>
        HttpResponse.json(
          { error: { code: 'settings.invalid_branding', message: 'Invalid branding.' } },
          { status: 400 }
        )
      )
    );

    const { wrapper } = makeWrapper();
    const { result } = renderHook(() => useSaveReportBranding(), { wrapper });

    act(() => {
      result.current.save({ agency_name: '' });
    });

    await waitFor(() => expect(result.current.error).not.toBeNull());
  });
});
