<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\ReportStorage;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ReportStorageTest extends AbstractSchemaTestCase
{
    public function testStoreReadDeleteRoundTrips(): void
    {
        $s = new ReportStorage();
        $res = $s->store(42, '%PDF-1.7 fake bytes');
        self::assertStringStartsWith('report-42-', $res['file_name']);
        self::assertStringEndsWith('.pdf', $res['file_name']);
        self::assertSame(strlen('%PDF-1.7 fake bytes'), $res['size']);
        self::assertSame('%PDF-1.7 fake bytes', $s->read($res['file_name']));
        self::assertFileExists($s->path($res['file_name']));
        $s->delete($res['file_name']);
        self::assertNull($s->read($res['file_name']));
    }

    public function testRandomNamesDiffer(): void
    {
        $s = new ReportStorage();
        $a = $s->store(1, 'x'); $b = $s->store(1, 'x');
        self::assertNotSame($a['file_name'], $b['file_name']);
        $s->delete($a['file_name']); $s->delete($b['file_name']);
    }
}
