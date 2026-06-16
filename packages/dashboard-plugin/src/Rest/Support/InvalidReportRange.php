<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest\Support;

/**
 * Thrown by ReportRange::resolve() when the supplied date range is invalid.
 *
 * The machine-readable error code (e.g. 'report.invalid_range') is exposed as the
 * public `$code` property — we widen the inherited (protected, untyped) Exception::$code
 * to public and store the string code there, so consumers read `$e->code` directly.
 */
final class InvalidReportRange extends \RuntimeException
{
    /** Widen the inherited protected Exception::$code to public; stays untyped to match the parent. */
    public $code; // phpcs:ignore

    public function __construct(string $code, string $message)
    {
        parent::__construct($message);
        $this->code = $code;
    }
}
