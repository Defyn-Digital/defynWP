# P5.2 — Server-side Branded PDF + White-label Config — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A one-click, server-generated **branded PDF** of the maintenance report (`GET /sites/{id}/report.pdf`) plus per-operator white-label config (agency name, accent, logo) on `/settings`.

**Architecture:** `dompdf` renders an HTML template (built from the existing `ReportService::compose` payload + branding from `user_meta`) → PDF bytes → a binary download endpoint. Branding via a new `BrandingService` over `user_meta`, surfaced through the existing P3.3 `SettingsController`. The SPA adds a "Download PDF" auth'd-blob button + a "Report branding" settings card. Built server-side so P5.3's scheduled email reuses the same `ReportPdfService`.

**Tech Stack:** PHP 8.1 dashboard plugin (PHPUnit/wp-phpunit) + **`dompdf/dompdf` ^3.0** (new) + React 18 / TS / TanStack Query v5 / Zod / Vitest / MSW SPA (pnpm, Node 22). Connector untouched (v0.1.7). Schema v12 unchanged (branding in `user_meta`). Dashboard v0.17.0→v0.18.0.

**Spec:** `docs/superpowers/specs/2026-06-15-p5-2-branded-pdf-design.md`.
**Branch:** `p5-2-branded-pdf` (already created off `main` @ `8bfda7b`).

---

## Verified facts (confirmed against the codebase — do NOT re-derive)

- **`ReportService::compose(int $siteId, int $userId, string $fromUtc, string $toUtc): array`** returns `{site:{id,label,url,wp_version}, period:{from,to}, overview:{updates_applied,uptime_range_percent,open_findings,wp_version}, updates:[{type,slug,component_name,previous_version,new_version,applied_at}], uptime:{range_percent,last_24h_percent,last_7d_percent,last_30d_percent,incidents:[{started_at,ended_at,duration_seconds,reason,ongoing}]}, security:{last_scan_at,open_findings:[…toJson…],severity_counts:{critical,high,medium,low},scans:[{scanned_at,total,critical,high,medium,low}]}}`.
- **`SitesReportController`** (P5.1) holds the date validation to extract: default trailing-30d when both params absent (`to=gmdate('Y-m-d')`, `from=gmdate('Y-m-d', time()-30*86400)`); strict `DateTime::createFromFormat('!Y-m-d', $v, new DateTimeZone('UTC'))` round-trip; `strcmp(from,to)>0` → invalid; span > 366d → too-large; normalize `from.' 00:00:00'` / `to.' 23:59:59'`. Error codes `report.invalid_range` / `report.range_too_large`. Existing test: `tests/Integration/Rest/SitesReportTest.php` (keep green after refactor).
- **`SettingsController`** (P3.3): `handleGet` returns `['slack_webhook_url'=>…]` (`META_KEY='defyn_slack_webhook_url'`); `handleSet` validates+stores; logs via `(new ActivityLogger())->log($userId, null, 'settings.slack_webhook_updated', ['cleared'=>…])`. Routes: `GET /settings` (`handleGet`, `RateLimit::settings` 30/min, key `defyn_rl_settings_%d`) + `POST /settings/slack-webhook` (`handleSet`, `RateLimit::settingsWrite`, key `defyn_rl_settingsWrite_%d`).
- **REST**: `ErrorResponse::create(int $status, string $code, string $msg)`; `SitesRepository::findByIdForUser(int,int):?Site`; RateLimit buckets fully inlined per-method returning `true|WP_Error`; RestRouter callback `[new X(), 'handle']`.
- **SPA `apiClient`**: in-memory `accessToken` (set via `setAccessToken`), `API_BASE = import.meta.env.VITE_API_BASE ?? '/api/defyn/v1'`, attaches `Authorization: Bearer ${accessToken}`. `apiClient.get<T>(path)` returns the parsed JSON body directly (NOT a `{data}` wrapper for `/settings` — `useSettings` does `settingsSchema.parse(await apiClient.get('/settings'))`).
- **SPA settings page**: `apps/web/src/routes/Settings.tsx`; `apps/web/src/lib/queries/useSettings.ts` (`settingsSchema.parse(apiClient.get('/settings'))`); `apps/web/src/lib/mutations/useSaveSlackWebhook.ts`.
- PHP full suite: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit`; tolerate ONLY `UninstallTest`. Baseline after P5.1 = 745/1.
- SPA: Node 22 (`.nvmrc`), `pnpm test`. Carry-forward 4: SiteDetail×2 + SiteCoreCard×2. A RUN-then-hang vitest = a render loop (P2.10), NOT env; keep `useMemo`/lazy state, no `useEffect` on fresh refs.

---

## Task 1: Add dompdf + `ReportPdfService::render` (minimal, constant branding, no logo)

**Files:**
- Modify: `packages/dashboard-plugin/composer.json` (via `composer require`)
- Create: `packages/dashboard-plugin/src/Services/ReportPdfService.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/ReportPdfServiceTest.php`

- [ ] **Step 1: Add the dependency.**
```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin"
composer require dompdf/dompdf:^3.0
```
Confirm `composer.json` `require` now lists `dompdf/dompdf` and `vendor/dompdf/` exists. (dompdf 3.0 requires PHP 8.1+ — matches.)

- [ ] **Step 2: Write the failing test** `tests/Integration/Services/ReportPdfServiceTest.php`:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\ReportPdfService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ReportPdfServiceTest extends AbstractSchemaTestCase
{
    private function sampleReport(): array
    {
        return [
            'site' => ['id'=>1,'label'=>'Acme','url'=>'https://acme.test','wp_version'=>'6.9.4'],
            'period' => ['from'=>'2026-05-16','to'=>'2026-06-15'],
            'overview' => ['updates_applied'=>2,'uptime_range_percent'=>99.7,'open_findings'=>1,'wp_version'=>'6.9.4'],
            'updates' => [
                ['type'=>'plugin','slug'=>'akismet','component_name'=>'Akismet','previous_version'=>'5.3','new_version'=>'5.4','applied_at'=>'2026-05-31 04:12:00'],
            ],
            'uptime' => ['range_percent'=>99.7,'last_24h_percent'=>100.0,'last_7d_percent'=>100.0,'last_30d_percent'=>99.7,
                'incidents'=>[['started_at'=>'2026-05-22 02:01:00','ended_at'=>'2026-05-22 02:08:00','duration_seconds'=>420,'reason'=>'502 Bad Gateway','ongoing'=>false]]],
            'security' => ['last_scan_at'=>'2026-06-14 05:35:00',
                'open_findings'=>[['type'=>'plugin','slug'=>'wp-file-manager','component_name'=>'WP File Manager','installed_version'=>'6.0','severity'=>'high','cvss_score'=>null,'cve'=>null,'fixed_in'=>'6.9','title'=>'x','source_id'=>'s','dismissed'=>false]],
                'severity_counts'=>['critical'=>0,'high'=>1,'medium'=>0,'low'=>0],
                'scans'=>[['scanned_at'=>'2026-06-14 05:35:00','total'=>1,'critical'=>0,'high'=>1,'medium'=>0,'low'=>0]]],
        ];
    }

    private function branding(): array
    {
        return ['agency_name'=>'Defyn Digital','accent_color'=>'#26215C','logo_url'=>''];
    }

    public function testRenderReturnsPdfBytes(): void
    {
        // logo fetcher stubbed to null so no network: render must still succeed.
        $svc = new ReportPdfService(static fn (string $url): ?string => null);
        $pdf = $svc->render($this->sampleReport(), $this->branding());

        self::assertNotEmpty($pdf);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(1000, strlen($pdf));
    }
}
```

- [ ] **Step 3: Run red** — `composer test:integration -- --filter ReportPdfServiceTest` → FAIL (class missing).

- [ ] **Step 4: Create `src/Services/ReportPdfService.php`** (minimal: cover page only; full sections in Task 2). The logo fetcher is injected; default returns null for now (real fetch in Task 3):

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * P5.2 — renders the ReportService::compose payload into a branded PDF via dompdf.
 * dompdf NEVER fetches remote resources (isRemoteEnabled=false); the logo is fetched
 * + validated by us and inlined as a data URI (see $logoFetcher / Task 3).
 */
final class ReportPdfService
{
    /** @var callable(string):?string returns a validated data: URI or null */
    private $logoFetcher;

    public function __construct(?callable $logoFetcher = null)
    {
        $this->logoFetcher = $logoFetcher ?? static fn (string $url): ?string => null; // real fetcher: Task 3
    }

    /**
     * @param array<string,mixed> $report
     * @param array{agency_name:string,accent_color:string,logo_url:string} $branding
     */
    public function render(array $report, array $branding): string
    {
        $logo = ($branding['logo_url'] ?? '') !== ''
            ? ($this->logoFetcher)($branding['logo_url'])
            : null;

        $html = $this->buildHtml($report, $branding, $logo);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** @param array<string,mixed> $report */
    private function buildHtml(array $report, array $branding, ?string $logoDataUri): string
    {
        $accent = $this->safeAccent((string) ($branding['accent_color'] ?? '#26215C'));
        $agency = $this->esc((string) ($branding['agency_name'] ?? 'Defyn Digital'));
        $url    = $this->esc((string) ($report['site']['url'] ?? ''));
        $from   = $this->esc((string) ($report['period']['from'] ?? ''));
        $to     = $this->esc((string) ($report['period']['to'] ?? ''));
        $logoImg = $logoDataUri !== null ? '<img src="' . $logoDataUri . '" style="max-height:60px;max-width:200px">' : '';

        return <<<HTML
<html><head><meta charset="utf-8"><style>
  body { font-family: 'DejaVu Sans', sans-serif; color:#222; font-size:11px; }
  .cover { text-align:center; padding-top:160px; page-break-after: always; }
  .band { background: {$accent}; height:8px; }
</style></head><body>
  <div class="cover">
    {$logoImg}
    <p style="color:{$accent};font-weight:bold;font-size:13px;letter-spacing:2px;">{$agency}</p>
    <p style="text-transform:uppercase;color:#888;letter-spacing:2px;font-size:11px;">Website Maintenance Report</p>
    <p style="font-size:18px;font-weight:bold;">{$url}</p>
    <p style="color:#666;">{$from} &ndash; {$to}</p>
  </div>
  <div class="band"></div>
  <!-- content sections: Task 2 -->
</body></html>
HTML;
    }

    /** Strict #RRGGBB or the default — never interpolate untrusted colour into CSS. */
    private function safeAccent(string $accent): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $accent) === 1 ? $accent : '#26215C';
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
```

- [ ] **Step 5: Run green** — `composer test:integration -- --filter ReportPdfServiceTest` → PASS. Full suite → only `UninstallTest`. **Also confirm the plugin still bootstraps** (dompdf autoload) by running any other integration test green.

- [ ] **Step 6: Commit**
```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/composer.json packages/dashboard-plugin/composer.lock packages/dashboard-plugin/src/Services/ReportPdfService.php packages/dashboard-plugin/tests/Integration/Services/ReportPdfServiceTest.php
git commit -m "feat(p5-2): dompdf + ReportPdfService::render (cover page, %PDF bytes)"
```

---

## Task 2: `ReportPdfService` — full content sections + escaping test

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/ReportPdfService.php` (`buildHtml` + a `debugHtml` seam)
- Test: `packages/dashboard-plugin/tests/Integration/Services/ReportPdfServiceTest.php` (append)

- [ ] **Step 1: Append failing tests**:

```php
    public function testEscapesReportDerivedStrings(): void
    {
        $report = $this->sampleReport();
        $report['updates'][0]['component_name'] = '<script>alert(1)</script>Evil';
        $svc = new ReportPdfService(static fn (string $url): ?string => null);
        $pdf = $svc->render($report, $this->branding());
        self::assertStringStartsWith('%PDF-', $pdf);
    }

    public function testRendersAllFourSectionsInHtml(): void
    {
        $svc = new ReportPdfService(static fn (string $url): ?string => null);
        $html = $svc->debugHtml($this->sampleReport(), $this->branding());
        foreach (['Overview','Updates','Uptime','Security','Akismet','5.3','5.4','502 Bad Gateway','WP File Manager'] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
        // escaped, not raw:
        $report = $this->sampleReport();
        $report['updates'][0]['component_name'] = '<b>x</b>';
        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $svc->debugHtml($report, $this->branding()));
    }
```

- [ ] **Step 2: Expand `buildHtml` + add the `debugHtml` seam.** Add:
```php
    /** Test seam — returns the HTML that would be passed to dompdf (threads the logo). */
    public function debugHtml(array $report, array $branding): string
    {
        $logo = ($branding['logo_url'] ?? '') !== '' ? ($this->logoFetcher)($branding['logo_url']) : null;
        return $this->buildHtml($report, $branding, $logo);
    }
```
Expand `buildHtml` (after the `.band`) to render all four sections — Overview stat row (`updates_applied`, `uptime_range_percent`%, `open_findings`, `wp_version`); Updates table (`component_name` · type · `previous_version → new_version` · `applied_at`; "No updates applied this period." when empty); Uptime block (`range_percent`% + 24h/7d/30d + incident rows `reason · duration · started_at`, "No downtime this period." when empty); Security block (`last_scan_at` + open_findings rows + scan-history table; "No open findings." when empty). **Escape every interpolated report value** with `$this->esc(...)`. If `buildHtml` grows past ~120 lines, extract `overviewHtml`/`updatesHtml`/`uptimeHtml`/`securityHtml` private methods.

- [ ] **Step 3: Run green** → all PASS. Full suite → only `UninstallTest`.

- [ ] **Step 4: Commit**
```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/src/Services/ReportPdfService.php packages/dashboard-plugin/tests/Integration/Services/ReportPdfServiceTest.php
git commit -m "feat(p5-2): ReportPdfService renders all 4 sections + escapes report strings"
```

---

## Task 3: SSRF-safe logo fetch + inline

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/ReportPdfService.php` (pure `validateLogoResponse` + default fetcher)
- Test: `packages/dashboard-plugin/tests/Integration/Services/ReportPdfServiceTest.php` (append)

- [ ] **Step 1: Append failing tests**:

```php
    public function testValidateLogoResponseAcceptsSmallPngOnHttps(): void
    {
        $dataUri = ReportPdfService::validateLogoResponse(200, 'image/png', 'PNGBYTES', 'https://cdn.test/logo.png');
        self::assertNotNull($dataUri);
        self::assertStringStartsWith('data:image/png;base64,', $dataUri);
    }

    public function testValidateLogoResponseRejectsBadInputs(): void
    {
        self::assertNull(ReportPdfService::validateLogoResponse(200, 'image/png', 'x', 'http://cdn.test/logo.png')); // not https
        self::assertNull(ReportPdfService::validateLogoResponse(200, 'text/html', 'x', 'https://cdn.test/logo.png')); // not image
        self::assertNull(ReportPdfService::validateLogoResponse(404, 'image/png', 'x', 'https://cdn.test/logo.png')); // not 200
        self::assertNull(ReportPdfService::validateLogoResponse(200, 'image/png', str_repeat('a', 600*1024), 'https://cdn.test/logo.png')); // oversize
    }

    public function testRenderWithLogoEmbedsDataUri(): void
    {
        $svc = new ReportPdfService(static fn (string $url): ?string => 'data:image/png;base64,AAAA');
        $html = $svc->debugHtml($this->sampleReport(), ['agency_name'=>'A','accent_color'=>'#112233','logo_url'=>'https://cdn.test/logo.png']);
        self::assertStringContainsString('data:image/png;base64,AAAA', $html);
    }
```

- [ ] **Step 2: Add the pure validator + wire the default fetcher.** Add to `ReportPdfService`:
```php
    private const LOGO_MAX_BYTES = 512 * 1024;
    private const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/gif'];

    /** Pure: validate a fetched logo response → data: URI or null. No network. */
    public static function validateLogoResponse(int $status, ?string $contentType, string $body, string $url): ?string
    {
        if ($status !== 200) return null;
        if (stripos($url, 'https://') !== 0) return null;
        $ct = strtolower(trim(explode(';', (string) $contentType)[0]));
        if (!in_array($ct, self::LOGO_TYPES, true)) return null;
        if (strlen($body) === 0 || strlen($body) > self::LOGO_MAX_BYTES) return null;
        return 'data:' . $ct . ';base64,' . base64_encode($body);
    }

    /** Default fetcher — wp_remote_get (no redirects) → validateLogoResponse. Best-effort, never throws. */
    public static function defaultLogoFetcher(string $url): ?string
    {
        try {
            if (stripos($url, 'https://') !== 0) return null;
            $res = wp_remote_get($url, ['redirection' => 0, 'timeout' => 5]);
            if (is_wp_error($res)) return null;
            $status = (int) wp_remote_retrieve_response_code($res);
            $ct     = wp_remote_retrieve_header($res, 'content-type');
            $body   = (string) wp_remote_retrieve_body($res);
            return self::validateLogoResponse($status, is_string($ct) ? $ct : null, $body, $url);
        } catch (\Throwable $e) {
            return null;
        }
    }
```
Change the constructor default to the real fetcher:
```php
        $this->logoFetcher = $logoFetcher ?? [self::class, 'defaultLogoFetcher'];
```

- [ ] **Step 3: Run green** → all PASS. Full suite → only `UninstallTest`.

- [ ] **Step 4: Commit**
```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/src/Services/ReportPdfService.php packages/dashboard-plugin/tests/Integration/Services/ReportPdfServiceTest.php
git commit -m "feat(p5-2): SSRF-safe logo fetch+inline (https/size/content-type, best-effort)"
```

---

## Task 4: Extract a shared `ReportRange` date validator (DRY) + refactor `SitesReportController`

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/Support/ReportRange.php`
- Create: `packages/dashboard-plugin/src/Rest/Support/InvalidReportRange.php`
- Modify: `packages/dashboard-plugin/src/Rest/SitesReportController.php`
- Test: `packages/dashboard-plugin/tests/Unit/Rest/ReportRangeTest.php` (new); existing `SitesReportTest` stays green.

- [ ] **Step 1: Write the failing unit test** `tests/Unit/Rest/ReportRangeTest.php`:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Unit\Rest;

use Defyn\Dashboard\Rest\Support\ReportRange;
use Defyn\Dashboard\Rest\Support\InvalidReportRange;
use PHPUnit\Framework\TestCase;

final class ReportRangeTest extends TestCase
{
    public function testExplicitRangeNormalizesToFullDayBounds(): void
    {
        $r = ReportRange::resolve('2026-05-16', '2026-06-15');
        self::assertSame('2026-05-16 00:00:00', $r['from']);
        self::assertSame('2026-06-15 23:59:59', $r['to']);
        self::assertSame('2026-05-16', $r['from_date']);
        self::assertSame('2026-06-15', $r['to_date']);
    }

    public function testBothAbsentDefaultsToTrailing30d(): void
    {
        $r = ReportRange::resolve(null, null);
        self::assertNotEmpty($r['from']); self::assertNotEmpty($r['to']);
    }

    public function testFromAfterToThrowsInvalid(): void
    {
        try { ReportRange::resolve('2026-06-15', '2026-05-01'); self::fail('expected throw'); }
        catch (InvalidReportRange $e) { self::assertSame('report.invalid_range', $e->code); }
    }

    public function testMalformedThrowsInvalid(): void
    {
        $this->expectException(InvalidReportRange::class);
        ReportRange::resolve('not-a-date', '2026-06-15');
    }

    public function testTooLargeThrowsRangeTooLarge(): void
    {
        try { ReportRange::resolve('2020-01-01', '2026-01-01'); self::fail('expected throw'); }
        catch (InvalidReportRange $e) { self::assertSame('report.range_too_large', $e->code); }
    }
}
```

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Create the exception + helper.** `src/Rest/Support/InvalidReportRange.php`:
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest\Support;

final class InvalidReportRange extends \RuntimeException
{
    public function __construct(public readonly string $code, string $message)
    {
        parent::__construct($message);
    }
}
```
`src/Rest/Support/ReportRange.php`:
```php
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
            'from' => $fromDate . ' 00:00:00', 'to' => $toDate . ' 23:59:59',
            'from_date' => $fromDate, 'to_date' => $toDate,
        ];
    }

    private static function parse(string $value): ?string
    {
        $d = \DateTime::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return ($d !== false && $d->format('Y-m-d') === $value) ? $value : null;
    }
}
```

- [ ] **Step 4: Refactor `SitesReportController::handle`** — replace the inline date validation with the helper (keep the ownership-404 + the envelope return):
```php
        try {
            $range = \Defyn\Dashboard\Rest\Support\ReportRange::resolve(
                is_string($request->get_param('from')) ? $request->get_param('from') : null,
                is_string($request->get_param('to')) ? $request->get_param('to') : null,
            );
        } catch (\Defyn\Dashboard\Rest\Support\InvalidReportRange $e) {
            return ErrorResponse::create(400, $e->code, $e->getMessage());
        }
        $report = (new ReportService())->compose($siteId, $userId, $range['from'], $range['to']);
```
Remove the old `parseDate`/`MAX_SPAN_DAYS` from `SitesReportController`.

- [ ] **Step 5: Run green** — `composer test:integration -- --filter "ReportRange|SitesReport"` → the new unit tests + the EXISTING `SitesReportTest` all PASS. Full suite → only `UninstallTest`.

- [ ] **Step 6: Commit**
```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/src/Rest/Support/ packages/dashboard-plugin/src/Rest/SitesReportController.php packages/dashboard-plugin/tests/Unit/Rest/ReportRangeTest.php
git commit -m "refactor(p5-2): extract shared ReportRange date validator; SitesReportController uses it"
```

---

## Task 5: `SitesReportPdfController` binary download + `RateLimit::siteReportPdf` + route + CORS

**Files:**
- Modify: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php` (`siteReportPdf`)
- Create: `packages/dashboard-plugin/src/Rest/SitesReportPdfController.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php` (route)
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SitesReportPdfTest.php` (new) + `SitesReportPdfCorsTest.php` (copy `SecurityFleetCorsTest.php`)

- [ ] **Step 1: Add the rate-limit bucket** — copy `siteReport` (30/min) exactly; rename `siteReportPdf` with `SITE_REPORT_PDF_LIMIT = 10`, key `defyn_rl_siteReportPdf_%d_%d`, 429 `report.rate_limited`, window `MINUTE_IN_SECONDS`.

- [ ] **Step 2: Write the failing test** `tests/Integration/Rest/SitesReportPdfTest.php` (copy the auth-dispatch + `$wpdb->insert` seeding from `SitesReportTest`; purge in `setUp` per guardrail #15). Cover:
  - `testNonOwnedSiteReturns404`: another user's site → 404 `sites.not_found`.
  - `testFromAfterToReturns400`: `?from=2026-06-15&to=2026-05-01` → 400 `report.invalid_range`.
  - `testRangeTooLargeReturns400`: `?from=2020-01-01&to=2026-01-01` → 400 `report.range_too_large`.
  - `testSuccessEmitsPdf`: a controller SUBCLASS overriding `emit($pdf,$filename)` to STORE them (not exit); build an owned-site `WP_REST_Request` with `_authenticated_user_id` + `id` params set directly; call `$controller->handle($request)`; assert the captured `$pdf` starts `%PDF-` and `$filename` contains the host + dates. (The error-branch tests use `rest_do_request` against the registered route; the success test calls `handle()` on the stub subclass directly.)

- [ ] **Step 3: Run red** → FAIL.

- [ ] **Step 4: Create `src/Rest/SitesReportPdfController.php`**:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Rest\Support\InvalidReportRange;
use Defyn\Dashboard\Rest\Support\ReportRange;
use Defyn\Dashboard\Services\BrandingService;
use Defyn\Dashboard\Services\ReportPdfService;
use Defyn\Dashboard\Services\ReportService;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P5.2 — GET /defyn/v1/sites/{id}/report.pdf?from&to → branded PDF download.
 * Error branches return JSON (testable). Success emits binary via emit() (a seam the
 * tests override; the real emit header()+echo()+exit()s).
 */
final class SitesReportPdfController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        $site = (new SitesRepository())->findByIdForUser($siteId, $userId);
        if ($site === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        try {
            $range = ReportRange::resolve(
                is_string($request->get_param('from')) ? $request->get_param('from') : null,
                is_string($request->get_param('to')) ? $request->get_param('to') : null,
            );
        } catch (InvalidReportRange $e) {
            return ErrorResponse::create(400, $e->code, $e->getMessage());
        }

        $report   = (new ReportService())->compose($siteId, $userId, $range['from'], $range['to']);
        $branding = (new BrandingService())->get($userId);
        $pdf      = (new ReportPdfService())->render($report, $branding);

        $host = preg_replace('/[^a-z0-9.-]+/i', '-', (string) parse_url($site->url, PHP_URL_HOST)) ?: 'site';
        $filename = "maintenance-report-{$host}-{$range['from_date']}-to-{$range['to_date']}.pdf";

        $this->emit($pdf, $filename);   // production: exits; test override: returns
        return new WP_REST_Response(null, 200);
    }

    /** Production: stream the PDF as a download and stop. Overridden in tests. */
    protected function emit(string $pdf, string $filename): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($pdf));
        }
        echo $pdf;
        exit;
    }
}
```

- [ ] **Step 5: Register the route** in `RestRouter` after the `/sites/{id}/report` GET. Escape the dot: `'/sites/(?P<id>\d+)/report\.pdf'`. Add `use Defyn\Dashboard\Rest\SitesReportPdfController;` +:
```php
        // P5.2 — GET /sites/{id}/report.pdf. Branded PDF download.
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/report\.pdf', [
            'methods'             => 'GET',
            'callback'            => [new SitesReportPdfController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'siteReportPdf'],
        ]);
```
**VERIFY the route resolves** — a test hitting `/sites/999999/report.pdf` returns 404 (the controller's error), NOT `rest_no_route`. If the escaped dot won't register, use `'/sites/(?P<id>\d+)/report.pdf'` and confirm which WP matches.

- [ ] **Step 6: CORS test** — copy `SecurityFleetCorsTest.php` → `SitesReportPdfCorsTest.php`, route `/sites/1/report.pdf`, method `GET`.

- [ ] **Step 7: Run green** — `composer test:integration -- --filter "SitesReportPdf"` → PASS. Full suite → only `UninstallTest`.

- [ ] **Step 8: Commit**
```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/src/Rest/ packages/dashboard-plugin/tests/Integration/Rest/
git commit -m "feat(p5-2): GET /sites/{id}/report.pdf binary download + 10/min bucket + CORS"
```

---

## Task 6: `BrandingService` over `user_meta`

**Files:**
- Create: `packages/dashboard-plugin/src/Services/BrandingService.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/BrandingServiceTest.php`

- [ ] **Step 1: Write the failing test**:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\BrandingService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class BrandingServiceTest extends AbstractSchemaTestCase
{
    public function testGetReturnsDefaultsWhenUnset(): void
    {
        $uid = self::factory()->user->create();
        $b = (new BrandingService())->get($uid);
        self::assertSame('Defyn Digital', $b['agency_name']);
        self::assertSame('#26215C', $b['accent_color']);
        self::assertSame('', $b['logo_url']);
    }

    public function testSetThenGetRoundTrips(): void
    {
        $uid = self::factory()->user->create();
        $svc = new BrandingService();
        $svc->set($uid, ['agency_name'=>'Acme Co','accent_color'=>'#112233','logo_url'=>'https://cdn.test/l.png']);
        $b = $svc->get($uid);
        self::assertSame('Acme Co', $b['agency_name']);
        self::assertSame('#112233', $b['accent_color']);
        self::assertSame('https://cdn.test/l.png', $b['logo_url']);
    }

    public function testEmptyValueResetsToDefault(): void
    {
        $uid = self::factory()->user->create();
        $svc = new BrandingService();
        $svc->set($uid, ['agency_name'=>'Acme Co']);
        $svc->set($uid, ['agency_name'=>'']);
        self::assertSame('Defyn Digital', $svc->get($uid)['agency_name']);
    }
}
```
(Confirm `self::factory()->user->create()` matches the harness user factory used elsewhere.)

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Create `src/Services/BrandingService.php`**:
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

/** P5.2 — per-operator report branding stored in user_meta. */
final class BrandingService
{
    private const KEY_AGENCY = 'defyn_report_agency_name';
    private const KEY_ACCENT = 'defyn_report_accent_color';
    private const KEY_LOGO   = 'defyn_report_logo_url';

    public const DEFAULT_AGENCY = 'Defyn Digital';
    public const DEFAULT_ACCENT = '#26215C';

    /** @return array{agency_name:string,accent_color:string,logo_url:string} */
    public function get(int $userId): array
    {
        $agency = (string) get_user_meta($userId, self::KEY_AGENCY, true);
        $accent = (string) get_user_meta($userId, self::KEY_ACCENT, true);
        $logo   = (string) get_user_meta($userId, self::KEY_LOGO, true);
        return [
            'agency_name'  => $agency !== '' ? $agency : self::DEFAULT_AGENCY,
            'accent_color' => $accent !== '' ? $accent : self::DEFAULT_ACCENT,
            'logo_url'     => $logo,
        ];
    }

    /** @param array<string,string> $partial only provided keys are written; '' resets to default. */
    public function set(int $userId, array $partial): void
    {
        $map = ['agency_name' => self::KEY_AGENCY, 'accent_color' => self::KEY_ACCENT, 'logo_url' => self::KEY_LOGO];
        foreach ($map as $field => $key) {
            if (!array_key_exists($field, $partial)) {
                continue;
            }
            $val = trim((string) $partial[$field]);
            if ($val === '') {
                delete_user_meta($userId, $key);
            } else {
                update_user_meta($userId, $key, $val);
            }
        }
    }
}
```

- [ ] **Step 4: Run green** → PASS. Full suite → only `UninstallTest`.

- [ ] **Step 5: Commit**
```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/src/Services/BrandingService.php packages/dashboard-plugin/tests/Integration/Services/BrandingServiceTest.php
git commit -m "feat(p5-2): BrandingService — per-operator report branding in user_meta"
```

---

## Task 7: `SettingsController` — GET includes `report_branding` + `POST /settings/report-branding`

**Files:**
- Modify: `packages/dashboard-plugin/src/Rest/SettingsController.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SettingsBrandingTest.php` (new) + CORS coverage for `/settings/report-branding`.

- [ ] **Step 1: Write the failing test** `tests/Integration/Rest/SettingsBrandingTest.php` (copy the auth harness from the existing settings test). Cover:
  - `testGetIncludesReportBrandingDefaults`: `GET /settings` (auth) → 200; `report_branding.agency_name === 'Defyn Digital'`, accent `#26215C`, logo `''`.
  - `testSetBrandingValidStores`: POST valid → 200 returns the values; a later GET reflects them; `settings.report_branding_updated` logged.
  - `testInvalidHexReturns400`: `accent_color:'red'` → 400 `settings.invalid_branding`.
  - `testNonHttpsLogoReturns400`: `logo_url:'http://x'` → 400 `settings.invalid_branding`.
  - `testOverLongAgencyReturns400`: 101-char `agency_name` → 400 `settings.invalid_branding`.

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Edit `SettingsController`.** Add `use Defyn\Dashboard\Services\BrandingService;`. In `handleGet`:
```php
        return new WP_REST_Response([
            'slack_webhook_url' => $url === '' ? null : $url,
            'report_branding'   => (new BrandingService())->get($userId),
        ], 200);
```
Add `handleSetBranding`:
```php
    public function handleSetBranding(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $body   = $request->get_json_params() ?: [];

        $partial = [];
        if (array_key_exists('agency_name', $body)) {
            $name = sanitize_text_field((string) $body['agency_name']);
            if (mb_strlen($name) > 100) {
                return ErrorResponse::create(400, 'settings.invalid_branding', 'agency_name must be 100 characters or fewer.');
            }
            $partial['agency_name'] = $name;
        }
        if (array_key_exists('accent_color', $body)) {
            $accent = trim((string) $body['accent_color']);
            if ($accent !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $accent) !== 1) {
                return ErrorResponse::create(400, 'settings.invalid_branding', 'accent_color must be a #RRGGBB hex colour.');
            }
            $partial['accent_color'] = $accent;
        }
        if (array_key_exists('logo_url', $body)) {
            $logo = trim((string) $body['logo_url']);
            if ($logo !== '' && (stripos($logo, 'https://') !== 0 || mb_strlen($logo) > 500)) {
                return ErrorResponse::create(400, 'settings.invalid_branding', 'logo_url must be an https URL of 500 characters or fewer.');
            }
            $partial['logo_url'] = $logo;
        }

        $svc = new BrandingService();
        $svc->set($userId, $partial);
        (new ActivityLogger())->log($userId, null, 'settings.report_branding_updated',
            ['cleared_logo' => array_key_exists('logo_url', $partial) && $partial['logo_url'] === '']);

        return new WP_REST_Response(['report_branding' => $svc->get($userId)], 200);
    }
```

- [ ] **Step 4: Register the route** in `RestRouter` after `/settings/slack-webhook`:
```php
        // P5.2 — POST /settings/report-branding. Per-operator report white-label config.
        register_rest_route(self::NAMESPACE, '/settings/report-branding', [
            'methods'             => 'POST',
            'callback'            => [new SettingsController(), 'handleSetBranding'],
            'permission_callback' => [RateLimit::class, 'settingsWrite'],
        ]);
```

- [ ] **Step 5: CORS** — mirror however `/settings/slack-webhook` is CORS-covered (add `/settings/report-branding`).

- [ ] **Step 6: Run green** — `composer test:integration -- --filter "SettingsBranding|Settings"` → PASS (existing settings tests stay green). Full suite → only `UninstallTest`.

- [ ] **Step 7: Commit**
```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/src/Rest/ packages/dashboard-plugin/tests/Integration/Rest/
git commit -m "feat(p5-2): settings GET returns report_branding + POST /settings/report-branding"
```

---

## Task 8: Dashboard v0.18.0 bump

**Files:** Modify `packages/dashboard-plugin/defyn-dashboard.php`.

- [ ] **Step 1:** line 6 `* Version:           0.17.0` → `0.18.0`; line 46 `define('DEFYN_DASHBOARD_VERSION', '0.17.0');` → `'0.18.0'`.
- [ ] **Step 2:** `grep -rn "0\.17\.0" packages/dashboard-plugin/src packages/dashboard-plugin/tests` → no matches; `grep -n "0.18.0" packages/dashboard-plugin/defyn-dashboard.php` → 2 lines.
- [ ] **Step 3: Commit**
```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/defyn-dashboard.php
git commit -m "chore(p5-2): bump dashboard plugin to v0.18.0"
```

---

## Task 9: SPA — settings Zod `report_branding` + `useReportBranding` + `useSaveReportBranding`

**Files:**
- Modify: `apps/web/src/types/api.ts` (`settingsSchema`)
- Modify: the MSW settings handler (the `/settings` fixture + add `POST /settings/report-branding`)
- Create: `apps/web/src/lib/queries/useReportBranding.ts`
- Create: `apps/web/src/lib/mutations/useSaveReportBranding.ts`
- Test: `apps/web/tests/useReportBranding.test.tsx` (new)

- [ ] **Step 1: Extend `settingsSchema`** in `apps/web/src/types/api.ts`:
```ts
export const reportBrandingSchema = z.object({
  agency_name: z.string(),
  accent_color: z.string(),
  logo_url: z.string(),
});
export type ReportBranding = z.infer<typeof reportBrandingSchema>;
// add to the existing settingsSchema object literal:  report_branding: reportBrandingSchema,
```
Update the MSW `/settings` fixture to include `report_branding` so existing settings tests stay green.

- [ ] **Step 2: Create `useReportBranding.ts`**:
```ts
import { useSettings } from '@/lib/queries/useSettings';
import type { ReportBranding } from '@/types/api';
export function useReportBranding(): { data?: ReportBranding; isLoading: boolean } {
  const q = useSettings();
  return { data: q.data?.report_branding, isLoading: q.isLoading };
}
```

- [ ] **Step 3: Create `useSaveReportBranding.ts`** (mirror `useSaveSlackWebhook`'s `apiClient.post` + invalidation):
```ts
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import type { ReportBranding } from '@/types/api';

export function useSaveReportBranding() {
  const qc = useQueryClient();
  const mutation = useMutation({
    mutationFn: (b: Partial<ReportBranding>) =>
      apiClient.post<{ report_branding: unknown }>('/settings/report-branding', b),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['settings'] }),
  });
  return { save: (b: Partial<ReportBranding>) => mutation.mutate(b), isPending: mutation.isPending, error: mutation.error };
}
```

- [ ] **Step 4: Test** `useReportBranding.test.tsx` — MSW returns settings incl. `report_branding`; assert `useReportBranding` resolves it; assert `useSaveReportBranding` POSTs the body + invalidates settings.

- [ ] **Step 5: Run green** — Node 22; `pnpm test -- --run useReportBranding`, then full `pnpm test` → only the 4 carry-forwards.

- [ ] **Step 6: Commit**
```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add apps/web/src/types/api.ts apps/web/src/lib/queries/useReportBranding.ts apps/web/src/lib/mutations/useSaveReportBranding.ts apps/web/tests apps/web/src
git commit -m "feat(p5-2): SPA settings report_branding schema + query + save mutation"
```

---

## Task 10: SPA — "Report branding" settings card

**Files:**
- Modify: `apps/web/src/routes/Settings.tsx`
- Create: `apps/web/src/components/settings/ReportBrandingCard.tsx`
- Test: `apps/web/tests/ReportBrandingCard.test.tsx` (new)

- [ ] **Step 1: Write the failing test** — render `ReportBrandingCard`, mock `useReportBranding` (saved values) + spy `useSaveReportBranding`. Assert: inputs seed from the query; editing + Save calls `save` with the edited values; invalid hex (`accent='red'`) shows a client error + does NOT call save; non-https logo shows a client error. The seed-once `useEffect` keys on PRIMITIVE branding fields (NOT the object) — P2.10 guard.

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Implement** `ReportBrandingCard.tsx` (mirror the Slack-webhook card's structure/styling): agency-name text input, accent colour (`<input type="color">` and/or a hex text field), logo URL text input, client validation mirroring the backend (`/^#[0-9a-fA-F]{6}$/`, `https://`, lengths), a Save button → `save({agency_name, accent_color, logo_url})`, a "Saved" confirmation. Seed via `useEffect(() => { … }, [data?.agency_name, data?.accent_color, data?.logo_url])` (primitive deps). Mount in `Settings.tsx` below the existing notifications/Slack card.

- [ ] **Step 4: Run green** — `pnpm test -- --run ReportBrandingCard` → PASS. Full `pnpm test` → only the 4 carry-forwards (NO hang — if it hangs, the seed effect keys on a fresh object; fix to primitives).

- [ ] **Step 5: Commit**
```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add apps/web/src/routes/Settings.tsx apps/web/src/components/settings/ReportBrandingCard.tsx apps/web/tests/ReportBrandingCard.test.tsx
git commit -m "feat(p5-2): Report branding settings card (agency name, accent, logo)"
```

---

## Task 11: SPA — `downloadReportPdf` + "Download PDF" button

**Files:**
- Modify: `apps/web/src/lib/apiClient.ts` (`getBlob`)
- Create: `apps/web/src/lib/downloadReportPdf.ts`
- Modify: `apps/web/src/pages/SiteReport.tsx`
- Test: `apps/web/tests/downloadReportPdf.test.ts` (new)

- [ ] **Step 1: Add `apiClient.getBlob`** — mirror `get` but return the raw `Blob` (reuse the module `accessToken`, `API_BASE`, `Authorization` header; do NOT JSON-parse). Read the file's actual `request`/`get` + export shape and add `getBlob(path): Promise<Blob>` on the `apiClient` object:
```ts
async getBlob(path: string): Promise<Blob> {
  const headers: Record<string, string> = {};
  if (accessToken) headers['Authorization'] = `Bearer ${accessToken}`;
  const res = await fetch(`${API_BASE}${path}`, { headers });
  if (!res.ok) throw new Error(`Download failed (${res.status})`);
  return res.blob();
},
```

- [ ] **Step 2: Write the failing test** `downloadReportPdf.test.ts` — mock `apiClient.getBlob` → a `Blob`; spy `URL.createObjectURL` (→ `'blob:x'`) + `URL.revokeObjectURL`; mock `document.createElement('a')` to capture `.href`/`.download`/`.click()`. Assert `downloadReportPdf(1,'2026-05-16','2026-06-15')` calls `getBlob('/sites/1/report.pdf?from=2026-05-16&to=2026-06-15')`, clicks an `<a download>` with the object URL, then revokes it. Add a getBlob-rejects case (helper rejects, doesn't crash).

- [ ] **Step 3: Implement** `apps/web/src/lib/downloadReportPdf.ts`:
```ts
import { apiClient } from '@/lib/apiClient';

export async function downloadReportPdf(siteId: number, from: string, to: string): Promise<void> {
  const blob = await apiClient.getBlob(`/sites/${siteId}/report.pdf?from=${from}&to=${to}`);
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `maintenance-report-${siteId}-${from}-to-${to}.pdf`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}
```

- [ ] **Step 4: Add the "Download PDF" button** in `SiteReport.tsx` inside `.report-controls`, next to the `window.print()` button: `<button onClick={() => downloadReportPdf(siteId, range.from, range.to).catch(() => setDownloadError(true))}>Download PDF</button>` (+ a small error state). Keep the Print button.

- [ ] **Step 5: Run green** — `pnpm test -- --run downloadReportPdf` → PASS. Full `pnpm test` → only the 4 carry-forwards.

- [ ] **Step 6: Commit**
```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add apps/web/src/lib/apiClient.ts apps/web/src/lib/downloadReportPdf.ts apps/web/src/pages/SiteReport.tsx apps/web/tests/downloadReportPdf.test.ts
git commit -m "feat(p5-2): SPA Download PDF button + auth'd blob download helper"
```

---

## Task 12: Release — build (dompdf-preserving), ship, smoke, tag, MEMORY

**Files:** build artifacts only.

- [ ] **Step 1: Full PHP suite** → only `UninstallTest`.
- [ ] **Step 2: Full SPA suite** (Node 22) → only the 4 carry-forwards.
- [ ] **Step 3: SPA build** — `cd apps/web && pnpm build`.
- [ ] **Step 4: Build the dashboard zip (symfony + json-machine + DOMPDF preserving):**
```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin"
composer install --no-dev --classmap-authoritative
cd "/Users/pradeep/Local Sites/defynWP/packages"
rm -f "/Users/pradeep/Local Sites/defynWP/dist/defyn-dashboard-0.18.0.zip"
mkdir -p "/Users/pradeep/Local Sites/defynWP/dist"
zip -rq "/Users/pradeep/Local Sites/defynWP/dist/defyn-dashboard-0.18.0.zip" dashboard-plugin \
  -x 'dashboard-plugin/tests/*' '*wp-tests-config.php' 'dashboard-plugin/.phpunit.result.cache' \
     'dashboard-plugin/test-output.log' 'dashboard-plugin/phpunit.xml' 'dashboard-plugin/composer.lock' \
     'dashboard-plugin/.github/*' 'dashboard-plugin/.gitignore'
cd "/Users/pradeep/Local Sites/defynWP"
unzip -l dist/defyn-dashboard-0.18.0.zip | grep -cE "deprecation-contracts/function\.php|polyfill-php83/bootstrap\.php"   # MUST be 2
unzip -l dist/defyn-dashboard-0.18.0.zip | grep -c "json-machine/src/Items\.php"                                          # MUST be >=1
unzip -l dist/defyn-dashboard-0.18.0.zip | grep -c "dompdf/src/Dompdf\.php"                                               # MUST be >=1 (NEW)
unzip -p dist/defyn-dashboard-0.18.0.zip dashboard-plugin/defyn-dashboard.php | grep -m1 DEFYN_DASHBOARD_VERSION
cd packages/dashboard-plugin && composer install   # restore dev autoload
```
- [ ] **Step 5: Merge + push**
```bash
cd "/Users/pradeep/Local Sites/defynWP"
git checkout main && git merge --ff-only p5-2-branded-pdf && git push origin main
```
- [ ] **Step 6: Kinsta install (MANUAL USER STEP — flag it + pause for "installed").** Upload `dist/defyn-dashboard-0.18.0.zip` via WP Admin → "Replace current with uploaded version", clear MyKinsta cache. No schema migration.
- [ ] **Step 7: Production smoke (curl only; login field `access_token`)** — after install:
  - `GET /sites/1/report.pdf` (no-auth) → **401**.
  - `GET /sites/999999/report.pdf` (auth) → **404** `sites.not_found`.
  - `GET /settings` (auth) → **200** with a `report_branding` object (`agency_name:"Defyn Digital"`, accent, logo).
  - `POST /settings/report-branding` (auth) `{"accent_color":"red"}` → **400** `settings.invalid_branding`.
  - `POST /settings/report-branding` (auth) `{"logo_url":"http://x"}` → **400** `settings.invalid_branding`.
  - (Happy PDF download foreclosed by zero-sites prod; covered by green tests.)
- [ ] **Step 8: Tag + push**
```bash
cd "/Users/pradeep/Local Sites/defynWP"
git tag p5-2-branded-pdf-complete && git push origin p5-2-branded-pdf-complete
```
- [ ] **Step 9: Update MEMORY** — append a P5.2-complete entry to `project_defyn_roadmap.md` (v0.18.0, tag, schema v12 unchanged, dompdf dep + zip-preserve check, `ReportPdfService` + SSRF-safe logo, `BrandingService`/user_meta, the `.pdf` binary endpoint + `ReportRange` refactor, settings branding card + Download PDF, smoke results) + refresh `MEMORY.md`. Set **NEXT = P5.3 (scheduled monthly email reusing `ReportPdfService` for the attachment)**.

---

## Self-Review (completed during planning)

- **Spec coverage:** §4 ReportPdfService → Tasks 1–2; §5 SSRF logo → Task 3; §6 BrandingService + settings → Tasks 6–7; §7 binary endpoint + shared ReportRange → Tasks 4–5; §8 SPA → Tasks 9–11; §10 release (dompdf-preserving zip) → Tasks 8 + 12. ✅
- **Type consistency:** `ReportPdfService::render(report, branding)` + injected `logoFetcher: fn(string):?string` + pure `validateLogoResponse` consistent across Tasks 1/2/3. `BrandingService::{get,set}` shape `{agency_name,accent_color,logo_url}` matches the settings payload (Task 7), the Zod schema (Task 9), the card (Task 10), and `ReportPdfService` consumption (Task 1). `ReportRange::resolve` return shape used identically by both controllers (Tasks 4/5). Error codes `report.invalid_range`/`report.range_too_large`/`settings.invalid_branding` consistent. ✅
- **No schema/connector change**; no version-pin bumps. dompdf is the only new dep; the zip verify-grep covers it (Task 12). ✅
- **Binary-endpoint testability** via the overridable `emit()` seam (Task 5) — error branches return JSON + are `rest_do_request`-testable; success captured by a stub subclass. ✅
- **Open verifications flagged** (cheap grep-confirms, not blockers): the `.pdf` route-regex escaping resolves (not `rest_no_route`); the `apiClient` export shape for adding `getBlob`; the settings CORS test location; `self::factory()->user->create()` is the harness user factory; the Slack-webhook card structure to mirror.
