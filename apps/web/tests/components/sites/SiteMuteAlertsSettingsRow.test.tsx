import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import React from 'react';
import { SiteMuteAlertsSettingsRow } from '@/components/sites/SiteMuteAlertsSettingsRow';

vi.mock('@/lib/mutations/useToggleMuteAlerts', () => ({
  useToggleMuteAlerts: vi.fn(),
}));
import { useToggleMuteAlerts } from '@/lib/mutations/useToggleMuteAlerts';

function withClient(children: React.ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('SiteMuteAlertsSettingsRow', () => {
  beforeEach(() => {
    (useToggleMuteAlerts as ReturnType<typeof vi.fn>).mockReturnValue({
      mutate: vi.fn(),
      isPending: false,
    });
  });

  it('renders switch off when site.alerts_muted is false', () => {
    render(
      withClient(
        <SiteMuteAlertsSettingsRow site={{ id: 1, alerts_muted: false } as any} />,
      ),
    );
    const sw = screen.getByRole('switch');
    expect(sw).not.toBeChecked();
  });

  it('renders switch on when site.alerts_muted is true', () => {
    render(
      withClient(
        <SiteMuteAlertsSettingsRow site={{ id: 1, alerts_muted: true } as any} />,
      ),
    );
    const sw = screen.getByRole('switch');
    expect(sw).toBeChecked();
  });

  it('calls toggle mutation with new value when clicked', () => {
    const mockMutate = vi.fn();
    (useToggleMuteAlerts as ReturnType<typeof vi.fn>).mockReturnValue({
      mutate: mockMutate,
      isPending: false,
    });
    render(
      withClient(
        <SiteMuteAlertsSettingsRow site={{ id: 1, alerts_muted: false } as any} />,
      ),
    );
    fireEvent.click(screen.getByRole('switch'));
    expect(mockMutate).toHaveBeenCalledWith(true);
  });
});
