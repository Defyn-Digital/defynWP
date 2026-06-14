import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { SiteCoreCard } from '@/components/sites/SiteCoreCard';
import {
  mockSites,
  mockSiteCoreState,
  resetMockSites,
  resetMockSiteCoreState,
} from '@/test/handlers';
import { setAccessToken } from '@/lib/apiClient';
import { server } from '@/test/setup';

function renderCard(siteId = 1) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <SiteCoreCard siteId={siteId} />
    </QueryClientProvider>,
  );
}

const baseSite = {
  id: 1,
  url: 'https://example.test',
  label: 'Example',
  status: 'active' as const,
  last_contact_at: '2026-06-07T00:00:00Z',
  last_sync_at: '2026-06-07T00:00:00Z',
  last_error: null,
  created_at: '2026-05-01 00:00:00',
  wp_version: '6.9.4',
  php_version: '8.2.27',
  active_theme: null,
  plugin_counts: null,
  theme_counts: null,
  ssl_status: null,
  ssl_expires_at: null,
  core_update_available: false,
  core_update_version: null,
  core_update_state: 'idle' as const,
  last_core_update_error: null,
  last_core_update_attempt_at: null,
  core_allow_major: false,
  alerts_muted: false,
};

describe('SiteCoreCard existing states', () => {
  beforeEach(() => {
    resetMockSites();
    resetMockSiteCoreState();
    setAccessToken('fake');
    mockSites.push({ ...baseSite });
  });

  it('renders the WordPress version when no update is available', async () => {
    renderCard();
    expect(await screen.findByText(/WordPress 6\.9\.4/)).toBeInTheDocument();
  });

  it('renders amber Update button when minor update is available', async () => {
    mockSiteCoreState[1] = {
      core_update_available: true,
      core_update_version: '6.9.5',
      core_update_state: 'idle',
      last_core_update_error: null,
      last_core_update_attempt_at: null,
      is_minor_update: true,
      is_auto_update_enabled: false,
    };
    renderCard();
    const btn = await screen.findByRole('button', { name: /Update to 6\.9\.5/i });
    expect(btn).toHaveClass('bg-amber-600');
  });

  it('renders updating spinner when state is updating', async () => {
    mockSiteCoreState[1] = {
      core_update_available: true,
      core_update_version: '6.9.5',
      core_update_state: 'updating',
      last_core_update_error: null,
      last_core_update_attempt_at: null,
      is_minor_update: true,
      is_auto_update_enabled: false,
    };
    renderCard();
    expect(await screen.findByText(/Upgrading WordPress/i)).toBeInTheDocument();
  });

  it('renders failed banner when state is failed', async () => {
    mockSiteCoreState[1] = {
      core_update_available: false,
      core_update_version: null,
      core_update_state: 'failed',
      last_core_update_error: 'Disk full',
      last_core_update_attempt_at: '2026-06-07T00:00:00Z',
      is_minor_update: false,
      is_auto_update_enabled: false,
    };
    renderCard();
    expect(await screen.findByText(/Last update attempt failed/i)).toBeInTheDocument();
    expect(await screen.findByText(/Disk full/i)).toBeInTheDocument();
  });
});

describe('SiteCoreCard major-update states', () => {
  const baseMajorCoreState = {
    core_update_available: true,
    core_update_version: '8.0',
    core_update_state: 'idle' as const,
    last_core_update_error: null,
    last_core_update_attempt_at: null,
    is_minor_update: false,
    is_auto_update_enabled: false,
  };

  beforeEach(() => {
    resetMockSites();
    resetMockSiteCoreState();
    setAccessToken('fake');
    mockSites.push({ ...baseSite, wp_version: '7.4' });
    mockSiteCoreState[1] = { ...baseMajorCoreState };
  });

  it('renders blocked-major-available state when flag is off and update is major', async () => {
    // core_allow_major defaults to false in baseSite — flag is off.
    renderCard();
    expect(await screen.findByText(/Major update available/i)).toBeInTheDocument();
    expect(await screen.findByText(/disabled for this site/i)).toBeInTheDocument();
    expect(await screen.findByRole('button', { name: /Manage settings/i })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^Update/i })).not.toBeInTheDocument();
  });

  it('renders allowed-major-available state when flag is on and update is major', async () => {
    // Override the GET /sites/1 handler to return core_allow_major: true.
    server.use(
      http.get('*/wp-json/defyn/v1/sites/:id', ({ params }) => {
        const id = Number(params.id);
        if (id !== 1) return undefined;
        return HttpResponse.json(
          {
            ...baseSite,
            wp_version: '7.4',
            core_allow_major: true,
            ...baseMajorCoreState,
          },
          { status: 200 },
        );
      }),
    );

    renderCard();
    expect(await screen.findByText(/Major update available/i)).toBeInTheDocument();
    const updateBtn = await screen.findByRole('button', { name: /^Update/i });
    expect(updateBtn).toHaveClass('bg-red-600');
  });
});
