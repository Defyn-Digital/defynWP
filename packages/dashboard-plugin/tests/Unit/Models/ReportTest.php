<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\Report;
use PHPUnit\Framework\TestCase;

final class ReportTest extends TestCase
{
    private function row(): array
    {
        return [
            'id' => '7', 'site_id' => '3', 'title' => 'Website Maintenance Report',
            'range_from' => '2026-05-01', 'range_to' => '2026-05-31', 'status' => 'ready',
            'file_name' => 'report-7-secrettoken.pdf', 'file_size' => '1120',
            'recipient_email' => null, 'error_message' => null,
            'generated_at' => '2026-06-01 02:00:00', 'sent_at' => null, 'created_at' => '2026-06-01 01:59:00',
        ];
    }

    public function testFromRowTypes(): void
    {
        $r = Report::fromRow($this->row());
        self::assertSame(7, $r->id);
        self::assertSame(3, $r->siteId);
        self::assertSame('ready', $r->status);
        self::assertSame('report-7-secrettoken.pdf', $r->fileName);
        self::assertSame(1120, $r->fileSize);
    }

    public function testToJsonNeverLeaksFileName(): void
    {
        $json = Report::fromRow($this->row())->toJson();
        self::assertArrayNotHasKey('file_name', $json);
        self::assertSame(1120, $json['file_size']);
        self::assertSame('2026-05-01', $json['range_from']);
        self::assertSame('ready', $json['status']);
    }
}
