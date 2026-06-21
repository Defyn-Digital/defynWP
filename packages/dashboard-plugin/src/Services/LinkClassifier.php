<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

/** P7.1 — single source of truth mapping a link's HTTP result to {severity, reason}. */
final class LinkClassifier
{
    /** @return array{severity:string, reason:string} */
    public static function classify(?int $status, bool $transportError): array
    {
        if ($transportError || $status === null) {
            return ['severity' => 'warning', 'reason' => 'unreachable'];
        }
        if ($status === 404 || $status === 410) {
            return ['severity' => 'broken', 'reason' => 'not_found'];
        }
        if ($status >= 500) {
            return ['severity' => 'warning', 'reason' => 'server_error'];
        }
        if ($status === 403 || $status === 401 || $status === 429) {
            return ['severity' => 'warning', 'reason' => 'blocked'];
        }
        if ($status >= 400) {
            return ['severity' => 'warning', 'reason' => 'client_error'];
        }
        return ['severity' => 'warning', 'reason' => 'client_error'];
    }
}
