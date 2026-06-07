import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ConfirmUpdateCoreDialog } from '@/components/sites/ConfirmUpdateCoreDialog';

// ─── Minor variant (existing P2.4 behaviour) ────────────────────────────────

describe('ConfirmUpdateCoreDialog minor variant', () => {
  const minorProps = {
    open: true,
    onOpenChange: vi.fn(),
    onConfirm: vi.fn(),
    currentVersion: '6.9.4',
    targetVersion: '6.9.5',
    isMinorUpdate: true,
    isAutoUpdateEnabled: false,
  };

  it('shows the version diff in the title', () => {
    render(<ConfirmUpdateCoreDialog {...minorProps} />);
    expect(screen.getByRole('alertdialog')).toHaveTextContent(/6\.9\.4/);
    expect(screen.getByRole('alertdialog')).toHaveTextContent(/6\.9\.5/);
  });

  it('renders amber confirm button labelled "Yes, update WordPress core"', () => {
    render(<ConfirmUpdateCoreDialog {...minorProps} />);
    const btn = screen.getByRole('button', { name: /Yes, update WordPress core/i });
    expect(btn).toHaveClass('bg-amber-600');
  });

  it('calls onConfirm when amber button clicked', async () => {
    const user = userEvent.setup();
    const onConfirm = vi.fn();
    render(<ConfirmUpdateCoreDialog {...minorProps} onConfirm={onConfirm} />);
    await user.click(screen.getByRole('button', { name: /Yes, update WordPress core/i }));
    expect(onConfirm).toHaveBeenCalledOnce();
  });

  it('calls onOpenChange(false) when Cancel clicked', async () => {
    const user = userEvent.setup();
    const onOpenChange = vi.fn();
    render(<ConfirmUpdateCoreDialog {...minorProps} onOpenChange={onOpenChange} />);
    await user.click(screen.getByRole('button', { name: /Cancel/i }));
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });

  it('shows auto-update paragraph when isAutoUpdateEnabled is true', () => {
    render(<ConfirmUpdateCoreDialog {...minorProps} isAutoUpdateEnabled />);
    expect(screen.getByText(/Auto-updates ON/i)).toBeInTheDocument();
  });

  it('hides auto-update paragraph when isAutoUpdateEnabled is false', () => {
    render(<ConfirmUpdateCoreDialog {...minorProps} isAutoUpdateEnabled={false} />);
    expect(screen.queryByText(/Auto-updates ON/i)).not.toBeInTheDocument();
  });

  it('returns null when open is false', () => {
    const { container } = render(
      <ConfirmUpdateCoreDialog {...minorProps} open={false} />,
    );
    expect(container.firstChild).toBeNull();
  });
});

// ─── Major variant (P2.4.1) ─────────────────────────────────────────────────

describe('ConfirmUpdateCoreDialog major variant', () => {
  const baseProps = {
    open: true,
    onConfirm: vi.fn(),
    onOpenChange: vi.fn(),
    currentVersion: '7.4',
    targetVersion: '8.0',
    isMinorUpdate: false,
    isAutoUpdateEnabled: false,
    plugins: [
      { name: 'Akismet', tested_up_to: '7.4' },
      { name: 'Yoast SEO', tested_up_to: '8.0' },
      { name: 'WP Old Plugin', tested_up_to: null },
    ],
    themes: [],
  };

  it('renders stop-sign emoji and red button in major variant', () => {
    render(<ConfirmUpdateCoreDialog {...baseProps} />);
    expect(screen.getByText(/🛑/)).toBeInTheDocument();
    const btn = screen.getByRole('button', { name: /Yes, run MAJOR upgrade 7\.4 → 8\.0/i });
    expect(btn).toHaveClass('bg-red-600');
  });

  it('shows compat list with plugins below target', () => {
    render(<ConfirmUpdateCoreDialog {...baseProps} />);
    expect(screen.getByText(/Akismet/)).toBeInTheDocument();
    expect(screen.getByText(/WP Old Plugin/)).toBeInTheDocument();
  });

  it('shows soft success line when all compatible', () => {
    const compatProps = {
      ...baseProps,
      plugins: [{ name: 'Yoast SEO', tested_up_to: '8.0' }],
      themes: [{ name: 'Twenty Twenty-Four', tested_up_to: '8.0' }],
    };
    render(<ConfirmUpdateCoreDialog {...compatProps} />);
    expect(
      screen.getByText(/All installed plugins & themes report compatibility/i),
    ).toBeInTheDocument();
  });

  it('requires typing the target version to enable confirm', () => {
    render(<ConfirmUpdateCoreDialog {...baseProps} />);
    const btn = screen.getByRole('button', { name: /Yes, run MAJOR upgrade/i });
    expect(btn).toBeDisabled();

    const input = screen.getByPlaceholderText(/e\.g\./);
    fireEvent.change(input, { target: { value: '8.0' } });
    expect(btn).not.toBeDisabled();
  });

  it('rejects type-the-version input with trailing whitespace', () => {
    render(<ConfirmUpdateCoreDialog {...baseProps} />);
    const input = screen.getByPlaceholderText(/e\.g\./);
    fireEvent.change(input, { target: { value: '8.0 ' } });
    expect(screen.getByRole('button', { name: /Yes, run MAJOR upgrade/i })).toBeDisabled();
  });

  it('button label includes from and target versions', () => {
    render(<ConfirmUpdateCoreDialog {...baseProps} />);
    expect(
      screen.getByRole('button', { name: /Yes, run MAJOR upgrade 7\.4 → 8\.0/ }),
    ).toBeInTheDocument();
  });

  it('does not show auto-updates paragraph in major variant', () => {
    render(<ConfirmUpdateCoreDialog {...baseProps} isAutoUpdateEnabled />);
    expect(screen.queryByText(/Auto-updates ON/i)).not.toBeInTheDocument();
  });
});
