<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Notify;

use Defyn\Dashboard\Models\Incident;
use Defyn\Dashboard\Models\Site;
use Defyn\Dashboard\Notify\MultiNotifier;
use Defyn\Dashboard\Notify\Notifier;
use PHPUnit\Framework\TestCase;

final class MultiNotifierTest extends TestCase
{
    private function site(): Site
    {
        return Site::fromRow(['id' => 1, 'user_id' => 1, 'url' => 'https://a.test', 'label' => 'A', 'status' => 'offline', 'created_at' => '2026-06-14 00:00:00']);
    }
    private function incident(): Incident
    {
        return new Incident(1, 1, '2026-06-14 00:00:00', null, null, 'x', null, null, '2026-06-14 00:00:00');
    }

    public function testFansOutToAllAndIsolatesThrow(): void
    {
        $calls = [];
        $throwing = new class implements Notifier {
            public function notifyDown(Site $s, Incident $i): void { throw new \RuntimeException('boom'); }
            public function notifyRecovered(Site $s, Incident $i): void { throw new \RuntimeException('boom'); }
            public function notifySslExpiring(Site $s, string $e, int $d): void { throw new \RuntimeException('boom'); }
        };
        $recording = new class($calls) implements Notifier {
            public function __construct(public array &$calls) {}
            public function notifyDown(Site $s, Incident $i): void { $this->calls[] = 'down'; }
            public function notifyRecovered(Site $s, Incident $i): void { $this->calls[] = 'recovered'; }
            public function notifySslExpiring(Site $s, string $e, int $d): void { $this->calls[] = 'ssl'; }
        };

        $multi = new MultiNotifier([$throwing, $recording]);
        $multi->notifyDown($this->site(), $this->incident());          // throwing first must not block recording
        $multi->notifyRecovered($this->site(), $this->incident());
        $multi->notifySslExpiring($this->site(), '2026-07-01 00:00:00', 14);

        self::assertSame(['down', 'recovered', 'ssl'], $recording->calls);
    }
}
