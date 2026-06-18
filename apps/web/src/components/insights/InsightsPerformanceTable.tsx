import { Link } from 'react-router-dom';
import type { PerformanceFleetRow } from '@/types/api';
import { psiBand, type PsiBand } from '@/lib/psiBand';
import { rateCwv, type CwvRating } from '@/lib/coreWebVitals';

const BAND_CLASS: Record<PsiBand | CwvRating, string> = {
  good: 'bg-green-50 text-green-700',
  'needs-improvement': 'bg-amber-50 text-amber-700',
  poor: 'bg-red-50 text-red-700',
  unknown: 'bg-zinc-100 text-zinc-500',
};

function ScoreChip({ score }: { score: number | null }) {
  if (score === null) return <span className="text-zinc-400">—</span>;
  return (
    <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold ${BAND_CLASS[psiBand(score)]}`}>
      {score}
    </span>
  );
}

function LcpCell({ ms }: { ms: number | null }) {
  if (ms === null) return <span className="text-zinc-400">—</span>;
  const rating = rateCwv('lcp', ms);
  const tone = rating === 'good' ? 'text-green-700' : rating === 'needs-improvement' ? 'text-amber-700' : 'text-red-700';
  return <span className={tone}>{(ms / 1000).toFixed(1)}s</span>;
}

function Row({ site }: { site: PerformanceFleetRow }) {
  const measured = site.fetched_at !== null;
  return (
    <tr className="border-b border-zinc-100">
      <td className="py-2 pr-3">
        <Link to={`/sites/${site.site_id}`} className="font-medium text-zinc-900 hover:underline">{site.label}</Link>
        <div className="text-xs text-zinc-400">{site.url}</div>
      </td>
      {measured ? (
        <>
          <td className="py-2 pr-3"><ScoreChip score={site.mobile_score} /></td>
          <td className="py-2 pr-3"><ScoreChip score={site.desktop_score} /></td>
          <td className="py-2 pr-3 tabular-nums"><LcpCell ms={site.mobile_lcp_ms} /></td>
          <td className="py-2 pr-1 text-sm text-zinc-500">{site.fetched_at?.slice(0, 10) ?? '—'}</td>
        </>
      ) : (
        <>
          <td className="py-2 pr-3 text-sm italic text-zinc-400" colSpan={3}>Not yet measured</td>
          <td className="py-2 pr-1 text-zinc-400">—</td>
        </>
      )}
    </tr>
  );
}

export function InsightsPerformanceTable({ sites }: { sites: PerformanceFleetRow[] }) {
  return (
    <table className="w-full text-sm">
      <thead>
        <tr className="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-500">
          <th className="py-2 pl-1 pr-3 font-medium">Site</th>
          <th className="py-2 pr-3 font-medium">Mobile</th>
          <th className="py-2 pr-3 font-medium">Desktop</th>
          <th className="py-2 pr-3 font-medium">LCP (m)</th>
          <th className="py-2 pr-1 font-medium">Measured</th>
        </tr>
      </thead>
      <tbody>
        {sites.map((s) => <Row key={s.site_id} site={s} />)}
      </tbody>
    </table>
  );
}
