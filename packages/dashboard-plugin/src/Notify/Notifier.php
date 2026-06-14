<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Notify;

use Defyn\Dashboard\Models\Incident;
use Defyn\Dashboard\Models\Site;

interface Notifier
{
    public function notifyDown(Site $site, Incident $incident): void;
    public function notifyRecovered(Site $site, Incident $incident): void;
}
