import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ConfirmUpdateCoreDialog } from '@/components/sites/ConfirmUpdateCoreDialog';

const baseMinorProps = {
  open: true,
  onOpenChange: () => {},
  onConfirm: () => {},
  currentVersion: '7.0',
  targetVersion: '7.0.1',
  isMinorUpdate: true,
  isAutoUpdateEnabled: false,
};

describe('ConfirmUpdateCoreDialog', () => {
  it('renders title with version diff', () => {
    render(<ConfirmUpdateCoreDialog {...baseMinorProps} />);
    expect(screen.getByRole('heading')).toHaveTextContent(/Update WordPress 7\.0/i);
    expect(screen.getByRole('heading')).toHaveTextContent(/7\.0\.1/i);
  });

  it('renders BOTH warning banners (downtime + downgrade)', () => {
    render(<ConfirmUpdateCoreDialog {...baseMinorProps} />);
    expect(screen.getByText(/Site goes briefly offline/i)).toBeInTheDocument();
    expect(screen.getByText(/Downgrades require SFTP/i)).toBeInTheDocument();
  });

  it('renders Auto-updates ON paragraph when isAutoUpdateEnabled === true', () => {
    render(<ConfirmUpdateCoreDialog {...baseMinorProps} isAutoUpdateEnabled />);
    expect(screen.getByText(/install this update automatically/i)).toBeInTheDocument();
  });

  it('OMITS Auto-updates ON paragraph when isAutoUpdateEnabled !== true', () => {
    render(<ConfirmUpdateCoreDialog {...baseMinorProps} isAutoUpdateEnabled={false} />);
    expect(screen.queryByText(/install this update automatically/i)).not.toBeInTheDocument();
  });

  it('renders amber primary button with the exact label', () => {
    render(<ConfirmUpdateCoreDialog {...baseMinorProps} />);
    const btn = screen.getByRole('button', { name: /^Yes, update WordPress core$/ });
    expect(btn).toBeInTheDocument();
    expect(btn.className).toMatch(/bg-amber-600/);
    expect(btn.className).toMatch(/hover:bg-amber-700/);
  });

  it('Cancel has the default focus', () => {
    render(<ConfirmUpdateCoreDialog {...baseMinorProps} />);
    expect(screen.getByRole('button', { name: /^Cancel$/ })).toHaveFocus();
  });

  it('calls onConfirm when amber button clicked', async () => {
    const user = userEvent.setup();
    let confirmed = false;
    render(
      <ConfirmUpdateCoreDialog
        {...baseMinorProps}
        onConfirm={() => {
          confirmed = true;
        }}
      />,
    );
    await user.click(screen.getByRole('button', { name: /Yes, update WordPress core/ }));
    expect(confirmed).toBe(true);
  });

  it('calls onOpenChange(false) when Cancel clicked', async () => {
    const user = userEvent.setup();
    let opened = true;
    render(
      <ConfirmUpdateCoreDialog
        {...baseMinorProps}
        onOpenChange={(o) => {
          opened = o;
        }}
      />,
    );
    await user.click(screen.getByRole('button', { name: /Cancel/ }));
    expect(opened).toBe(false);
  });
});
