import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, beforeEach } from 'vitest';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/test/setup';
import { useReportBranding } from '@/lib/queries/useReportBranding';
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

describe('useReportBranding', () => {
  beforeEach(() => {
    setAccessToken('fake');
    server.use(
      http.get('*/wp-json/defyn/v1/settings', () =>
        HttpResponse.json({
          slack_webhook_url: null,
          report_branding: {
            agency_name: 'Defyn Digital',
            accent_color: '#26215C',
            logo_url: '',
          },
        })
      )
    );
  });

  it('resolves report_branding from the settings query', async () => {
    const { wrapper } = makeWrapper();
    const { result } = renderHook(() => useReportBranding(), { wrapper });

    await waitFor(() => expect(result.current.isLoading).toBe(false));
    expect(result.current.data).toEqual({
      agency_name: 'Defyn Digital',
      accent_color: '#26215C',
      logo_url: '',
    });
  });

  it('is loading while settings are being fetched', () => {
    // Override to delay the response so loading state is observable.
    server.use(
      http.get('*/wp-json/defyn/v1/settings', async () => {
        await new Promise<void>((r) => setTimeout(r, 200));
        return HttpResponse.json({
          slack_webhook_url: null,
          report_branding: { agency_name: 'Defyn Digital', accent_color: '#26215C', logo_url: '' },
        });
      })
    );
    const { wrapper } = makeWrapper();
    const { result } = renderHook(() => useReportBranding(), { wrapper });
    expect(result.current.isLoading).toBe(true);
  });
});
