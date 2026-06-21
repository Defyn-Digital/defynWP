import { useState } from 'react';
import type { SiteReport } from '@/types/api';
import { REPORT_ACCENT } from '@/lib/reportBranding';
import { useReportBranding } from '@/lib/queries/useReportBranding';

interface ReportHeaderProps {
  site: SiteReport['site'];
  period: SiteReport['period'];
}

// Personalised cover band for the printed report. Leads with the *client
// site's* own logo (monogram fallback) and reads the operator's branding
// (accent colour + optional agency name) from settings.
export function ReportHeader({ site, period }: ReportHeaderProps) {
  const { data: branding } = useReportBranding();
  const accent = branding?.accent_color || REPORT_ACCENT;

  // Fall back to a monogram when there's no logo URL or the image fails to load.
  const [imgFailed, setImgFailed] = useState(false);
  const showMonogram = !site.logo_url || imgFailed;
  const monogram = (site.label || site.url || '?').charAt(0).toUpperCase();

  const preparedOn = new Date().toLocaleDateString();
  const agencyName = branding?.agency_name?.trim();

  return (
    <header
      className="report-section flex items-center gap-4 rounded-md px-6 py-5 text-white"
      style={{ backgroundColor: accent }}
    >
      {showMonogram ? (
        <div
          aria-hidden="true"
          className="flex h-12 w-12 shrink-0 items-center justify-center rounded-md bg-white/15 text-xl font-semibold"
        >
          {monogram}
        </div>
      ) : (
        <img
          src={site.logo_url ?? undefined}
          alt=""
          className="h-12 w-12 shrink-0 rounded-md bg-white/10 object-cover"
          onError={() => setImgFailed(true)}
        />
      )}

      <div className="min-w-0 flex-1">
        <p className="text-xs uppercase tracking-widest opacity-80">Maintenance report</p>
        <h1 className="mt-0.5 truncate text-xl font-semibold">{site.label || site.url}</h1>
        <p className="mt-0.5 truncate text-sm opacity-90">{site.url}</p>

        <div className="mt-3 border-t border-white/20 pt-3 text-sm">
          <div className="flex flex-wrap gap-x-8 gap-y-1">
            <p>
              <span className="opacity-70">Period</span>{' '}
              <span className="font-medium">
                {period.from} – {period.to}
              </span>
            </p>
            <p>
              <span className="opacity-70">Prepared</span>{' '}
              <span className="font-medium">{preparedOn}</span>
            </p>
          </div>
          {agencyName && <p className="mt-1 text-xs opacity-80">Prepared by {agencyName}</p>}
        </div>
      </div>
    </header>
  );
}
