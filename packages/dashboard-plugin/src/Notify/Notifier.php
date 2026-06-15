<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Notify;

use Defyn\Dashboard\Models\Incident;
use Defyn\Dashboard\Models\Site;

interface Notifier
{
    public function notifyDown(Site $site, Incident $incident): void;
    public function notifyRecovered(Site $site, Incident $incident): void;
    public function notifySslExpiring(Site $site, string $expiresAtUtc, int $daysLeft): void;

    /**
     * P4.3a — one digest of newly-found vulnerabilities for a site.
     *
     * @param list<array{type:string,slug:string,component_name:string,installed_version:string,severity:string,cve:?string,fixed_in:?string}> $newVulnerabilities
     * @param array{critical:int,high:int,medium:int,low:int} $severityCounts
     */
    public function notifyNewVulnerabilities(Site $site, array $newVulnerabilities, array $severityCounts): void;
}
