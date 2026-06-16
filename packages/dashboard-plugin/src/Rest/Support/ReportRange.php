<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest\Support;

final class ReportRange
{
    private const MAX_SPAN_DAYS = 366;

    /**
     * @return array{from:string,to:string,from_date:string,to_date:string}
     * @throws InvalidReportRange
     */
    public static function resolve(?string $from, ?string $to): array
    {
        if ($from === null && $to === null) {
            $toDate   = gmdate('Y-m-d');
            $fromDate = gmdate('Y-m-d', time() - 30 * 86400);
        } else {
            $fromDate = self::parse(is_string($from) ? $from : '');
            $toDate   = self::parse(is_string($to) ? $to : '');
            if ($fromDate === null || $toDate === null) {
                throw new InvalidReportRange('report.invalid_range', 'from/to must be valid YYYY-MM-DD dates.');
            }
        }
        if (strcmp($fromDate, $toDate) > 0) {
            throw new InvalidReportRange('report.invalid_range', 'from must be on or before to.');
        }
        $span = (strtotime($toDate . ' UTC') - strtotime($fromDate . ' UTC')) / 86400;
        if ($span > self::MAX_SPAN_DAYS) {
            throw new InvalidReportRange('report.range_too_large', 'Date range exceeds the maximum of 366 days.');
        }
        return [
            'from'      => $fromDate . ' 00:00:00',
            'to'        => $toDate . ' 23:59:59',
            'from_date' => $fromDate,
            'to_date'   => $toDate,
        ];
    }

    private static function parse(string $value): ?string
    {
        $d = \DateTime::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return ($d !== false && $d->format('Y-m-d') === $value) ? $value : null;
    }
}
