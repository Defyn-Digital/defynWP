import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ConfirmBulkUpdateGateDialog } from '@/components/overview/ConfirmBulkUpdateGateDialog';

describe('ConfirmBulkUpdateGateDialog', () => {
  it('rendersNothingWhenClosed', () => {
    const { container } = render(
      <ConfirmBulkUpdateGateDialog
        open={false}
        resourceLabel="plugin"
        count={5}
        siteCount={3}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(container).toBeEmptyDOMElement();
  });

  it('rendersPluginTitleAndConfirmLabelWithCounts', () => {
    render(
      <ConfirmBulkUpdateGateDialog
        open
        resourceLabel="plugin"
        count={5}
        siteCount={3}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(screen.getByText(/update 5 plugins across 3 sites\?/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^update 5 plugins$/i })).toBeInTheDocument();
  });

  it('rendersThemeCopyWhenResourceLabelIsTheme', () => {
    render(
      <ConfirmBulkUpdateGateDialog
        open
        resourceLabel="theme"
        count={2}
        siteCount={1}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(screen.getByText(/update 2 themes across 1 sites\?/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^update 2 themes$/i })).toBeInTheDocument();
    // Body mentions the theme upgrader, not plugin.
    expect(screen.getByText(/theme upgrader/i)).toBeInTheDocument();
  });

  it('confirmButtonUsesRedDestructiveStyling', () => {
    render(
      <ConfirmBulkUpdateGateDialog
        open
        resourceLabel="plugin"
        count={5}
        siteCount={3}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    const confirm = screen.getByRole('button', { name: /^update 5 plugins$/i });
    expect(confirm.className).toMatch(/bg-red-600/);
  });

  it('cancelHasDefaultFocus', () => {
    render(
      <ConfirmBulkUpdateGateDialog
        open
        resourceLabel="plugin"
        count={5}
        siteCount={3}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(screen.getByRole('button', { name: /^cancel$/i })).toHaveFocus();
  });

  it('cancelAndConfirmCallTheirHandlers', () => {
    const onCancel = vi.fn();
    const onConfirm = vi.fn();
    render(
      <ConfirmBulkUpdateGateDialog
        open
        resourceLabel="plugin"
        count={5}
        siteCount={3}
        onCancel={onCancel}
        onConfirm={onConfirm}
      />,
    );
    fireEvent.click(screen.getByRole('button', { name: /^cancel$/i }));
    expect(onCancel).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByRole('button', { name: /^update 5 plugins$/i }));
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });
});
