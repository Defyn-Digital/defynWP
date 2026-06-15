import { useState } from 'react';
import { ShieldCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useScanAllSecurity } from '@/lib/mutations/useScanAllSecurity';
import { ConfirmScanAllSecurityDialog } from '@/components/security/ConfirmScanAllSecurityDialog';

interface ScanAllSitesSecurityButtonProps {
  totalSites: number;
}

/**
 * P4.2 — header button + confirm dialog + mutation invocation.
 *
 * Idle:     [🛡 Scan all sites]  (disabled when totalSites === 0)
 * Pending:  [⏳ Scanning…]       (disabled)
 *
 * Click → neutral confirm dialog → confirm → POST /security/scan-all
 * → mutation.onSuccess invalidates ['security'] so the fleet table
 * reflects updated scan timestamps on the next refetch.
 *
 * Spec: docs/superpowers/specs/2026-06-15-p4-2-security-fleet-design.md § 3
 */
export function ScanAllSitesSecurityButton({ totalSites }: ScanAllSitesSecurityButtonProps) {
  const [confirmOpen, setConfirmOpen] = useState(false);
  const mutation = useScanAllSecurity();

  const handleConfirm = () => {
    setConfirmOpen(false);
    mutation.mutate();
  };

  if (mutation.isPending) {
    return (
      <Button variant="outline" size="sm" disabled>
        <ShieldCheck className="mr-1.5 h-3.5 w-3.5 animate-spin" aria-hidden="true" />
        Scanning…
      </Button>
    );
  }

  return (
    <>
      <Button
        variant="outline"
        size="sm"
        onClick={() => setConfirmOpen(true)}
        disabled={totalSites === 0}
      >
        <ShieldCheck className="mr-1.5 h-3.5 w-3.5" aria-hidden="true" />
        Scan all sites
      </Button>
      <ConfirmScanAllSecurityDialog
        open={confirmOpen}
        totalSites={totalSites}
        onCancel={() => setConfirmOpen(false)}
        onConfirm={handleConfirm}
      />
    </>
  );
}
