<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\ReportMailer;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ReportMailerTest extends AbstractSchemaTestCase
{
    public function testSendPassesAttachmentAndReturnsBool(): void
    {
        $captured = [];
        $mailer = new ReportMailer(static function ($to, $subject, $body, $headers, $attachments) use (&$captured): bool {
            $captured = compact('to', 'subject', 'body', 'headers', 'attachments');
            return true;
        });
        $ok = $mailer->send('client@acme.test', 'Subject', 'Body', '/tmp/report.pdf');
        self::assertTrue($ok);
        self::assertSame('client@acme.test', $captured['to']);
        self::assertSame(['/tmp/report.pdf'], $captured['attachments']);
        self::assertContains('Content-Type: text/html; charset=UTF-8', $captured['headers']);
    }
}
