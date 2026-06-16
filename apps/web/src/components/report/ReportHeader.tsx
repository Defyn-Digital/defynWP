import type { SiteReport } from '@/types/api';
import { REPORT_AGENCY_NAME, REPORT_ACCENT } from '@/lib/reportBranding';

interface ReportHeaderProps {
  site: SiteReport['site'];
  period: SiteReport['period'];
}

// Accent-coloured branding band that opens the printed report.
export function ReportHeader({ site, period }: ReportHeaderProps) {
  return (
    <header
      className="report-section rounded-md px-6 py-5 text-white"
      style={{ backgroundColor: REPORT_ACCENT }}
    >
      <p className="text-xs uppercase tracking-widest opacity-80">{REPORT_AGENCY_NAME}</p>
      <h1 className="mt-1 text-2xl font-semibold">
        Maintenance report — {site.label || site.url}
      </h1>
      <p className="mt-1 text-sm opacity-90">{site.url}</p>
      <p className="mt-2 text-sm opacity-90">
        Period: {period.from} – {period.to}
      </p>
    </header>
  );
}
