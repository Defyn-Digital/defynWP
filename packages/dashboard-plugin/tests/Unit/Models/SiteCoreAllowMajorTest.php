<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\Site;
use PHPUnit\Framework\TestCase;

final class SiteCoreAllowMajorTest extends TestCase
{
    public function testFromRowDefaultsCoreAllowMajorToFalseWhenColumnAbsent(): void
    {
        $site = Site::fromRow($this->baseRow());
        $this->assertFalse($site->coreAllowMajor);
    }

    public function testFromRowHydratesCoreAllowMajorFromOne(): void
    {
        $row = $this->baseRow();
        $row['core_allow_major'] = '1';
        $site = Site::fromRow($row);
        $this->assertTrue($site->coreAllowMajor);
    }

    public function testFromRowHydratesCoreAllowMajorFromZero(): void
    {
        $row = $this->baseRow();
        $row['core_allow_major'] = '0';
        $site = Site::fromRow($row);
        $this->assertFalse($site->coreAllowMajor);
    }

    public function testToJsonExposesCoreAllowMajor(): void
    {
        $row = $this->baseRow();
        $row['core_allow_major'] = '1';
        $json = Site::fromRow($row)->toJson();

        $this->assertArrayHasKey('core_allow_major', $json);
        $this->assertTrue($json['core_allow_major']);
    }

    /** @return array<string, mixed> */
    private function baseRow(): array
    {
        return [
            'id'         => 1,
            'user_id'    => 1,
            'url'        => 'https://example.com',
            'label'      => 'Example',
            'status'     => 'connected',
            'created_at' => '2026-06-07 00:00:00',
        ];
    }
}
