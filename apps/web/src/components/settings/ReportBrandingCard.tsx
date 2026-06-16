import { useState, useEffect } from 'react';
import { useReportBranding } from '@/lib/queries/useReportBranding';
import { useSaveReportBranding } from '@/lib/mutations/useSaveReportBranding';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';

// Client validation MIRRORS the backend: agency ≤ 100, accent strict #RRGGBB
// (or empty), logo https://-prefixed & ≤ 500 (or empty).
const HEX_RE = /^#[0-9a-fA-F]{6}$/;
const DEFAULT_ACCENT = '#26215C';
const MAX_AGENCY = 100;
const MAX_LOGO = 500;

export function ReportBrandingCard() {
  const { data } = useReportBranding();
  const { save, isPending } = useSaveReportBranding();

  const [agency, setAgency] = useState('');
  const [accent, setAccent] = useState(DEFAULT_ACCENT);
  const [logo, setLogo] = useState('');
  const [saved, setSaved] = useState(false);

  // Seed ONCE per data arrival — keyed on PRIMITIVE fields (P2.10 render-loop guard).
  useEffect(() => {
    if (data) {
      setAgency(data.agency_name ?? '');
      setAccent(data.accent_color ?? DEFAULT_ACCENT);
      setLogo(data.logo_url ?? '');
    }
  }, [data?.agency_name, data?.accent_color, data?.logo_url]); // eslint-disable-line react-hooks/exhaustive-deps

  const agencyValid = agency.length <= MAX_AGENCY;
  const accentValid = accent === '' || HEX_RE.test(accent);
  const logoValid = logo === '' || (/^https:\/\//.test(logo) && logo.length <= MAX_LOGO);
  const allValid = agencyValid && accentValid && logoValid;

  function handleAgencyChange(value: string) {
    setAgency(value);
    setSaved(false);
  }

  function handleAccentChange(value: string) {
    setAccent(value);
    setSaved(false);
  }

  function handleLogoChange(value: string) {
    setLogo(value);
    setSaved(false);
  }

  function handleSave() {
    if (!allValid) return;
    save({ agency_name: agency, accent_color: accent, logo_url: logo });
    setSaved(true);
  }

  // A safe swatch colour for the native colour picker (it rejects non-hex values).
  const swatch = HEX_RE.test(accent) ? accent : DEFAULT_ACCENT;

  return (
    <div className="rounded-lg border border-zinc-200 p-5">
      <h2 className="mb-4 text-base font-medium">Report branding</h2>

      <div className="space-y-4">
        <div>
          <label htmlFor="branding-agency" className="mb-1.5 block text-sm font-medium text-zinc-700">
            Agency name
          </label>
          <Input
            id="branding-agency"
            type="text"
            maxLength={MAX_AGENCY}
            placeholder="Your agency name"
            value={agency}
            onChange={(e) => handleAgencyChange(e.target.value)}
            aria-describedby={!agencyValid ? 'branding-agency-error' : undefined}
          />
          {!agencyValid && (
            <p id="branding-agency-error" className="mt-1.5 text-sm text-red-600">
              Must be 100 characters or fewer.
            </p>
          )}
        </div>

        <div>
          <label htmlFor="branding-accent" className="mb-1.5 block text-sm font-medium text-zinc-700">
            Accent colour (hex)
          </label>
          <div className="flex items-center gap-2">
            <input
              type="color"
              aria-label="Accent colour swatch"
              value={swatch}
              onChange={(e) => handleAccentChange(e.target.value)}
              className="h-9 w-10 cursor-pointer rounded-md border border-border bg-background p-1"
            />
            <Input
              id="branding-accent"
              type="text"
              placeholder="#26215C"
              value={accent}
              onChange={(e) => handleAccentChange(e.target.value)}
              aria-describedby={!accentValid ? 'branding-accent-error' : undefined}
            />
          </div>
          {!accentValid && (
            <p id="branding-accent-error" className="mt-1.5 text-sm text-red-600">
              Must be a hex colour like #RRGGBB (or empty).
            </p>
          )}
        </div>

        <div>
          <label htmlFor="branding-logo" className="mb-1.5 block text-sm font-medium text-zinc-700">
            Logo URL
          </label>
          <Input
            id="branding-logo"
            type="url"
            maxLength={MAX_LOGO}
            placeholder="https://…/logo.png"
            value={logo}
            onChange={(e) => handleLogoChange(e.target.value)}
            aria-describedby={!logoValid ? 'branding-logo-error' : undefined}
          />
          {!logoValid && (
            <p id="branding-logo-error" className="mt-1.5 text-sm text-red-600">
              Must start with https:// and be 500 characters or fewer (or empty).
            </p>
          )}
        </div>

        <p className="text-xs text-zinc-500">
          Used to white-label the PDF reports you generate for clients.
        </p>

        <div className="flex items-center gap-3">
          <Button onClick={handleSave} disabled={!allValid || isPending}>
            {isPending ? 'Saving…' : 'Save'}
          </Button>
          {saved && <span className="text-sm text-green-600">Saved</span>}
        </div>
      </div>
    </div>
  );
}

export default ReportBrandingCard;
