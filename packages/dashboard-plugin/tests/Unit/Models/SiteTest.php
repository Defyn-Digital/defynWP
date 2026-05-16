<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\Site;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
final class SiteTest extends TestCase
{
    public function testFromRowMapsAllExpectedFields(): void
    {
        $row = [
            'id'              => '42',
            'user_id'         => '7',
            'url'             => 'https://example.test',
            'label'           => 'Test site',
            'status'          => 'pending',
            'site_public_key' => null,
            'our_public_key'  => 'PUBKEY==',
            'last_contact_at' => null,
            'last_error'      => null,
            'created_at'      => '2026-05-11 00:00:00',
        ];

        $site = Site::fromRow($row);

        self::assertSame(42, $site->id);
        self::assertSame(7, $site->userId);
        self::assertSame('https://example.test', $site->url);
        self::assertSame('Test site', $site->label);
        self::assertSame('pending', $site->status);
        self::assertNull($site->sitePublicKey);
        self::assertSame('PUBKEY==', $site->ourPublicKey);
        self::assertNull($site->lastContactAt);
        self::assertNull($site->lastError);
        self::assertSame('2026-05-11 00:00:00', $site->createdAt);
    }

    public function testToJsonProducesSpaShape(): void
    {
        $site = Site::fromRow([
            'id'              => '1',
            'user_id'         => '1',
            'url'             => 'https://example.test',
            'label'           => '',
            'status'          => 'active',
            'site_public_key' => 'SITEPUB==',
            'our_public_key'  => 'OURPUB==',
            'last_contact_at' => '2026-05-11 00:07:00',
            'last_error'      => null,
            'created_at'      => '2026-05-11 00:00:00',
        ]);

        self::assertSame([
            'id'              => 1,
            'url'             => 'https://example.test',
            'label'           => '',
            'status'          => 'active',
            'last_contact_at' => '2026-05-11 00:07:00',
            'last_error'      => null,
            'created_at'      => '2026-05-11 00:00:00',
        ], $site->toJson());
    }
}
