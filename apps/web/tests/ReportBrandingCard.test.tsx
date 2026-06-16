import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ReportBrandingCard } from '@/components/settings/ReportBrandingCard';
import { useReportBranding } from '@/lib/queries/useReportBranding';
import { useSaveReportBranding } from '@/lib/mutations/useSaveReportBranding';

// Drive the card with deterministic fixtures by mocking both hooks.
vi.mock('@/lib/queries/useReportBranding');
vi.mock('@/lib/mutations/useSaveReportBranding');

const mockedUseReportBranding = vi.mocked(useReportBranding);
const mockedUseSaveReportBranding = vi.mocked(useSaveReportBranding);

type BrandingReturn = ReturnType<typeof useReportBranding>;

const SAVED_BRANDING = {
  agency_name: 'Acme Co',
  accent_color: '#112233',
  logo_url: 'https://cdn.test/l.png',
};

function mockBranding(opts: Partial<BrandingReturn> = {}) {
  mockedUseReportBranding.mockReturnValue({
    data: SAVED_BRANDING,
    isLoading: false,
    ...opts,
  } as BrandingReturn);
}

let save: ReturnType<typeof vi.fn>;

function mockSave() {
  save = vi.fn();
  mockedUseSaveReportBranding.mockReturnValue({
    save,
    isPending: false,
    error: null,
  } as unknown as ReturnType<typeof useSaveReportBranding>);
}

describe('ReportBrandingCard', () => {
  beforeEach(() => {
    mockedUseReportBranding.mockReset();
    mockedUseSaveReportBranding.mockReset();
    mockBranding();
    mockSave();
  });

  it('seeds the three inputs from the saved branding query', () => {
    render(<ReportBrandingCard />);
    expect(screen.getByLabelText(/Agency name/i)).toHaveValue('Acme Co');
    expect(screen.getByLabelText(/Accent colour \(hex\)/i)).toHaveValue('#112233');
    expect(screen.getByLabelText(/Logo URL/i)).toHaveValue('https://cdn.test/l.png');
  });

  it('calls save with the edited fields when Save is clicked', async () => {
    const user = userEvent.setup();
    render(<ReportBrandingCard />);

    const agencyInput = screen.getByLabelText(/Agency name/i);
    await user.clear(agencyInput);
    await user.type(agencyInput, 'New Agency');

    await user.click(screen.getByRole('button', { name: /Save/i }));

    expect(save).toHaveBeenCalledTimes(1);
    expect(save).toHaveBeenCalledWith({
      agency_name: 'New Agency',
      accent_color: '#112233',
      logo_url: 'https://cdn.test/l.png',
    });
  });

  it('blocks save and shows an error for an invalid hex accent colour', async () => {
    const user = userEvent.setup();
    render(<ReportBrandingCard />);

    const accentInput = screen.getByLabelText(/Accent colour \(hex\)/i);
    await user.clear(accentInput);
    await user.type(accentInput, 'red');

    expect(screen.getByText(/#RRGGBB/i)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /Save/i }));
    expect(save).not.toHaveBeenCalled();
  });

  it('blocks save and shows an error for a non-https logo URL', async () => {
    const user = userEvent.setup();
    render(<ReportBrandingCard />);

    const logoInput = screen.getByLabelText(/Logo URL/i);
    await user.clear(logoInput);
    await user.type(logoInput, 'http://x');

    expect(screen.getByText(/must start with https:\/\//i)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /Save/i }));
    expect(save).not.toHaveBeenCalled();
  });
});
