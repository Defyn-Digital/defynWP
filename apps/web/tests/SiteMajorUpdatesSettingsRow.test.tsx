import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import React from 'react';
import { SiteMajorUpdatesSettingsRow } from '@/components/sites/SiteMajorUpdatesSettingsRow';

vi.mock('@/lib/mutations/useToggleCoreAllowMajor', () => ({
  useToggleCoreAllowMajor: vi.fn(),
}));
import { useToggleCoreAllowMajor } from '@/lib/mutations/useToggleCoreAllowMajor';

function withClient(children: React.ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('SiteMajorUpdatesSettingsRow', () => {
  beforeEach(() => {
    (useToggleCoreAllowMajor as ReturnType<typeof vi.fn>).mockReturnValue({
      mutate: vi.fn(),
      isPending: false,
    });
  });

  it('renders switch off when site.core_allow_major is false', () => {
    render(
      withClient(
        <SiteMajorUpdatesSettingsRow site={{ id: 1, core_allow_major: false } as any} />,
      ),
    );
    const sw = screen.getByRole('switch');
    expect(sw).not.toBeChecked();
  });

  it('renders switch on when site.core_allow_major is true', () => {
    render(
      withClient(
        <SiteMajorUpdatesSettingsRow site={{ id: 1, core_allow_major: true } as any} />,
      ),
    );
    const sw = screen.getByRole('switch');
    expect(sw).toBeChecked();
  });

  it('calls toggle mutation with new value when clicked', () => {
    const mockMutate = vi.fn();
    (useToggleCoreAllowMajor as ReturnType<typeof vi.fn>).mockReturnValue({
      mutate: mockMutate,
      isPending: false,
    });
    render(
      withClient(
        <SiteMajorUpdatesSettingsRow site={{ id: 1, core_allow_major: false } as any} />,
      ),
    );
    fireEvent.click(screen.getByRole('switch'));
    expect(mockMutate).toHaveBeenCalledWith(true);
  });
});
