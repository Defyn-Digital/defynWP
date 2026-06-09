import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ConfirmBulkUpdateThemesDialog } from '@/components/overview/ConfirmBulkUpdateThemesDialog';

const ROWS = [
  { site_id: 1, site_label: 'SmartCoding', slug: 'astra',            theme_name: 'Astra',             current_version: '4.6.3',  target_version: '4.7.0' },
  { site_id: 1, site_label: 'SmartCoding', slug: 'twentytwentyfour', theme_name: 'Twenty TwentyFour', current_version: '1.2',    target_version: '1.3' },
  { site_id: 2, site_label: 'AcmeBlog',    slug: 'blocksy',          theme_name: 'Blocksy',           current_version: '2.0.1',  target_version: '2.0.2' },
];

// P2.8 — fixture for skipMajor toggle tests. 4 rows: 3 minor/patch + 1 major.
const ROWS_WITH_MAJOR = [
  { site_id: 1, site_label: 'SmartCoding', slug: 'astra',            theme_name: 'Astra',             current_version: '4.6.3',  target_version: '4.7.0' },
  { site_id: 1, site_label: 'SmartCoding', slug: 'twentytwentyfour', theme_name: 'Twenty TwentyFour', current_version: '1.2',    target_version: '1.3' },
  { site_id: 1, site_label: 'SmartCoding', slug: 'kadence',          theme_name: 'Kadence',           current_version: '1.1.40', target_version: '2.0.0' }, // MAJOR
  { site_id: 2, site_label: 'AcmeBlog',    slug: 'blocksy',          theme_name: 'Blocksy',           current_version: '2.0.1',  target_version: '2.0.2' },
];

describe('ConfirmBulkUpdateThemesDialog', () => {
  it('opensWithAllRowsPreChecked', () => {
    render(
      <ConfirmBulkUpdateThemesDialog
        open
        rows={ROWS}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /bulk update 3 themes/i })).toBeInTheDocument();
  });

  it('manualUncheckUpdatesFooterCounter', () => {
    render(
      <ConfirmBulkUpdateThemesDialog
        open
        rows={ROWS}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    fireEvent.click(screen.getByRole('checkbox', { name: /astra/i }));
    expect(screen.getByText(/2 selected of 3 available/i)).toBeInTheDocument();
  });

  it('allUncheckedDisablesPrimary', () => {
    render(
      <ConfirmBulkUpdateThemesDialog
        open
        rows={ROWS}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    fireEvent.click(screen.getByRole('checkbox', { name: /astra/i }));
    fireEvent.click(screen.getByRole('checkbox', { name: /twenty twentyfour/i }));
    fireEvent.click(screen.getByRole('checkbox', { name: /blocksy/i }));

    const primary = screen.getByRole('button', { name: /bulk update 0 themes/i });
    expect(primary).toBeDisabled();
  });

  it('cancelCallsOnCancel', () => {
    const onCancel = vi.fn();
    render(
      <ConfirmBulkUpdateThemesDialog
        open
        rows={ROWS}
        onCancel={onCancel}
        onConfirm={vi.fn()}
      />,
    );
    fireEvent.click(screen.getByRole('button', { name: /cancel/i }));
    expect(onCancel).toHaveBeenCalledTimes(1);
  });

  it('skipMajorToggleOffShowsAllRows', () => {
    render(
      <ConfirmBulkUpdateThemesDialog
        open
        rows={ROWS_WITH_MAJOR}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(screen.getByRole('checkbox', { name: /astra/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /twenty twentyfour/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /kadence/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /blocksy/i })).toBeInTheDocument();
    expect(screen.getByText(/4 selected of 4 available/i)).toBeInTheDocument();
  });

  it('skipMajorToggleOnHidesMajorRowsAndUpdatesCounts', () => {
    render(
      <ConfirmBulkUpdateThemesDialog
        open
        rows={ROWS_WITH_MAJOR}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );

    fireEvent.click(screen.getByRole('checkbox', { name: /skip major bumps/i }));

    expect(screen.queryByRole('checkbox', { name: /kadence/i })).not.toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /astra/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /twenty twentyfour/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /blocksy/i })).toBeInTheDocument();

    expect(screen.getByText(/bulk update 3 themes across 2 sites\?/i)).toBeInTheDocument();
    expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /bulk update 3 themes/i })).toBeInTheDocument();
  });

  it('skipMajorToggleResetsCheckedKeysWhenFlipped', () => {
    render(
      <ConfirmBulkUpdateThemesDialog
        open
        rows={ROWS_WITH_MAJOR}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );

    fireEvent.click(screen.getByRole('checkbox', { name: /astra/i }));
    expect(screen.getByText(/3 selected of 4 available/i)).toBeInTheDocument();

    fireEvent.click(screen.getByRole('checkbox', { name: /skip major bumps/i }));
    expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument();
  });
});
