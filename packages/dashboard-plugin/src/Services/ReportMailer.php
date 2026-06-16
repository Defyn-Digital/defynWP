<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

final class ReportMailer
{
    /** @var callable(string,string,string,array,array):bool */
    private $sender;

    public function __construct(?callable $sender = null)
    {
        $this->sender = $sender ?? static fn ($to, $subject, $body, $headers, $attachments): bool
            => (bool) wp_mail($to, $subject, $body, $headers, $attachments);
    }

    public function send(string $to, string $subject, string $body, string $attachmentPath): bool
    {
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        return ($this->sender)($to, $subject, $body, $headers, [$attachmentPath]);
    }
}
