<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\Incident;
use PHPUnit\Framework\TestCase;

final class IncidentTest extends TestCase
{
    public function test_from_row_and_to_json_open_incident(): void
    {
        $i = Incident::fromRow([
            'id' => '5', 'site_id' => '2',
            'started_at' => '2026-06-14 10:00:00', 'ended_at' => null,
            'duration_seconds' => null, 'last_error' => 'Connector returned status 500',
            'down_alert_sent_at' => '2026-06-14 10:00:01', 'up_alert_sent_at' => null,
            'created_at' => '2026-06-14 10:00:00',
        ]);
        $this->assertSame(5, $i->id);
        $this->assertSame(2, $i->siteId);
        $this->assertNull($i->endedAt);
        $this->assertNull($i->durationSeconds);
        $json = $i->toJson();
        $this->assertSame(5, $json['id']);
        $this->assertSame('Connector returned status 500', $json['last_error']);
        $this->assertNull($json['ended_at']);
    }

    public function test_from_row_closed_incident_casts_duration(): void
    {
        $i = Incident::fromRow([
            'id' => '5', 'site_id' => '2',
            'started_at' => '2026-06-14 10:00:00', 'ended_at' => '2026-06-14 10:35:00',
            'duration_seconds' => '2100', 'last_error' => 'x',
            'down_alert_sent_at' => null, 'up_alert_sent_at' => null, 'created_at' => '2026-06-14 10:00:00',
        ]);
        $this->assertSame(2100, $i->durationSeconds);
        $this->assertSame('2026-06-14 10:35:00', $i->endedAt);
    }
}
