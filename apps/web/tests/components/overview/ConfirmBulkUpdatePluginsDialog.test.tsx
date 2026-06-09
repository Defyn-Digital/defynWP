import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ConfirmBulkUpdatePluginsDialog } from '@/components/overview/ConfirmBulkUpdatePluginsDialog';

const ROWS = [
  { site_id: 1, site_label: 'SmartCoding', slug: 'akismet', plugin_name: 'Akismet', current_version: '5.3', target_version: '5.3.1' },
  { site_id: 1, site_label: 'SmartCoding', slug: 'yoast',   plugin_name: 'Yoast',   current_version: '22.5', target_version: '22.6' },
  { site_id: 2, site_label: 'AcmeBlog',    slug: 'jetpack', plugin_name: 'Jetpack', current_version: '13.1', target_version: '13.2' },
];

// P2.7.1 — fixture for skipMajor toggle tests. 4 rows: 3 minor/patch + 1 major.
const ROWS_WITH_MAJOR = [
  { site_id: 1, site_label: 'SmartCoding', slug: 'akismet',   plugin_name: 'Akismet',   current_version: '5.3',    target_version: '5.3.1' },
  { site_id: 1, site_label: 'SmartCoding', slug: 'yoast',     plugin_name: 'Yoast',     current_version: '22.5',   target_version: '22.6' },
  { site_id: 1, site_label: 'SmartCoding', slug: 'elementor', plugin_name: 'Elementor', current_version: '3.18.2', target_version: '4.0.0' }, // MAJOR
  { site_id: 2, site_label: 'AcmeBlog',    slug: 'jetpack',   plugin_name: 'Jetpack',   current_version: '13.1',   target_version: '13.2' },
];

describe('ConfirmBulkUpdatePluginsDialog', () => {
  it('cancelHasDefaultFocus', () => {
    render(
      <ConfirmBulkUpdatePluginsDialog
        open
        rows={ROWS}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(screen.getByRole('button', { name: /^cancel$/i })).toHaveFocus();
  });

  it('primaryButtonUsesDestructiveVariant', () => {
    render(
      <ConfirmBulkUpdatePluginsDialog
        open
        rows={ROWS}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    // Plan-bug trap #9 — Button has no built-in destructive variant in this codebase.
    // We use className override matching P2.4.1's ConfirmUpdateCoreDialog pattern.
    const primary = screen.getByRole('button', { name: /bulk update 3 plugins/i });
    expect(primary.className).toMatch(/bg-red-600/);
  });

  it('primaryButtonDisabledWhenZeroSelected', () => {
    render(
      <ConfirmBulkUpdatePluginsDialog
        open
        rows={ROWS}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );

    // Uncheck all 3 individual checkboxes.
    const plugins = screen.getAllByRole('checkbox', { name: /akismet|yoast|jetpack/i });
    plugins.forEach((cb) => fireEvent.click(cb));

    const primary = screen.getByRole('button', { name: /bulk update 0 plugins/i });
    expect(primary).toBeDisabled();
  });

  it('footerCounterUpdatesLive', () => {
    render(
      <ConfirmBulkUpdatePluginsDialog
        open
        rows={ROWS}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument();

    // Uncheck akismet.
    fireEvent.click(screen.getByRole('checkbox', { name: /akismet/i }));
    expect(screen.getByText(/2 selected of 3 available/i)).toBeInTheDocument();
  });

  it('skipMajorToggleOffShowsAllRows', () => {
    render(
      <ConfirmBulkUpdatePluginsDialog
        open
        rows={ROWS_WITH_MAJOR}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    // Toggle defaults to OFF — all 4 rows visible including Elementor 3.18.2 → 4.0.0.
    expect(screen.getByRole('checkbox', { name: /akismet/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /yoast/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /elementor/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /jetpack/i })).toBeInTheDocument();
    expect(screen.getByText(/4 selected of 4 available/i)).toBeInTheDocument();
  });

  it('skipMajorToggleOnHidesMajorRowsAndUpdatesCounts', () => {
    render(
      <ConfirmBulkUpdatePluginsDialog
        open
        rows={ROWS_WITH_MAJOR}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );

    // Flip the toggle ON.
    fireEvent.click(screen.getByRole('checkbox', { name: /skip major bumps/i }));

    // Elementor (3.18.2 → 4.0.0) is hidden; the other 3 stay.
    expect(screen.queryByRole('checkbox', { name: /elementor/i })).not.toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /akismet/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /yoast/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /jetpack/i })).toBeInTheDocument();

    // Title, footer counter, and primary button label all reflect 3 (not 4).
    expect(screen.getByText(/bulk update 3 plugins across 2 sites\?/i)).toBeInTheDocument();
    expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /bulk update 3 plugins/i })).toBeInTheDocument();
  });

  it('skipMajorToggleResetsCheckedKeysWhenFlipped', () => {
    render(
      <ConfirmBulkUpdatePluginsDialog
        open
        rows={ROWS_WITH_MAJOR}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );

    // Manually uncheck akismet first (toggle still OFF).
    fireEvent.click(screen.getByRole('checkbox', { name: /akismet/i }));
    expect(screen.getByText(/3 selected of 4 available/i)).toBeInTheDocument();

    // Flip the toggle ON — checkedKeys re-seeds to all 3 visible rows
    // (akismet is back to checked because the re-seed includes it).
    fireEvent.click(screen.getByRole('checkbox', { name: /skip major bumps/i }));
    expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument();
  });
});
