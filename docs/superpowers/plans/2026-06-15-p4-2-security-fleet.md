# P4.2 — Security Fleet Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A dedicated `/security` fleet page — one cross-fleet view of every managed site's vulnerability posture (KPI strip + site-centric table) plus a fleet "Scan all sites" action — rolled up from the P4.1 per-site findings.

**Architecture:** Pure read/rollup + a fan-out action. A new `SiteVulnerabilitiesRepository::findFleetSummariesForUser` GROUP BY query feeds `SecurityService::compose` (summary + status-sorted sites). `GET /security` clones the P3.2 `/monitoring` read endpoint (direct payload, 30/min); `POST /security/scan-all` clones the P2.6 `/overview/sync-all` fan-out (5/hr) but fans out the P4.1 `SecurityScan` job. The SPA `/security` route mirrors `Monitoring.tsx` + the P2.6 Sync-all button/dialog. **No schema change, no connector change.**

**Tech Stack:** PHP 8.1 (WP plugin, PHPUnit/wp-phpunit), Action Scheduler, React 18 + TS + TanStack Query v5 + Zod + Vitest + MSW. Schema **unchanged (v11)**. Dashboard **v0.13.0 → v0.14.0**. Connector **unchanged (v0.1.7)**.

**Spec:** `docs/superpowers/specs/2026-06-15-p4-2-security-fleet-page-design.md` (commit `d2fe26c`).

**Branch:** `p4-2-security-fleet` (off `main` @ `db53c89`).

---

## Conventions for every task

- **TDD:** failing test → red → minimal impl → green → commit.
- **PHP suite:** from `packages/dashboard-plugin/` — `composer test:unit` / `composer test:integration` / `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit` (composer's 300s wrapper truncates the full run).
- **SPA suite (Node 22 + pnpm):** from `apps/web/` run `export FNM_DIR="$HOME/.fnm"; eval "$(fnm env --shell bash)"; fnm use 22` then `pnpm test` (vitest run); filter with `pnpm test -- tests/path`. `pkill -9 -f vitest` between hung runs (a hang = a real render-loop bug, not env).
- **Carry-forward to TOLERATE:** PHP 1 (`UninstallTest` wp-phpunit infra); SPA 4 (`tests/SiteDetail.test.tsx` ×2 + `tests/components/sites/SiteCoreCard.test.tsx` ×2). Any OTHER failure is a regression.
- **UTC everywhere** (`gmdate('Y-m-d H:i:s')`). **No schema change** — do NOT bump `SCHEMA_VERSION` (stays 11) or touch version-pin tests.
- **Test isolation (guardrail #15):** `SiteVulnerabilitiesRepository::replaceForSite` uses an explicit `COMMIT` that escapes `WP_UnitTestCase` rollback → integration tests that seed findings MUST purge in `setUp` (`SET autocommit=1` + `DELETE FROM` + `freshlyActivate`). There is **no `SitesRepository::create()`** — seed sites via `insertPending(userId,url,label,ourPublicKey,ourPrivateKeyEncrypted): int` (+ `markActive($id, $pk)`), or a direct `$wpdb->insert($wpdb->prefix.'defyn_sites', [...])` helper.
- Namespaces: PHP `Defyn\Dashboard\...`. `ActivityLogger` is in `Defyn\Dashboard\Services\` with `log(?int userId, ?int siteId, string eventType, ?array details = null, ?string ip = null)`.

---

## Task 1: `SiteVulnerabilitiesRepository::findFleetSummariesForUser`

**Files:**
- Modify: `src/Services/SiteVulnerabilitiesRepository.php` (add method + `SitesTable` import)
- Test: `tests/Integration/Services/SiteVulnerabilitiesFleetTest.php`

One GROUP BY query: the user's sites LEFT JOIN their findings → per-site severity counts. Returns ALL the user's sites (clean + never-scanned included); ownership-scoped via `s.user_id`.

- [ ] **Step 1: Write the failing test** `tests/Integration/Services/SiteVulnerabilitiesFleetTest.php` (note the clean-slate `setUp` — guardrail #15; seed sites with a direct insert helper since there is no `create()`):

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Services\SiteVulnerabilitiesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SiteVulnerabilitiesFleetTest extends AbstractSchemaTestCase
{
    private SiteVulnerabilitiesRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        Activation::ensureSchema();
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_site_vulnerabilities', 'defyn_sites'] as $t) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}{$t}");
        }
        // phpcs:enable WordPress.DB.PreparedSQL
        $this->repo = new SiteVulnerabilitiesRepository();
    }

    public function testFleetSummariesCountAndScopeByUser(): void
    {
        $atRisk      = $this->seedSite(1, 'https://a.test', 'Acme', '2026-06-15 02:00:00');
        $clean       = $this->seedSite(1, 'https://b.test', 'Beta', '2026-06-15 02:00:00');
        $neverScan   = $this->seedSite(1, 'https://c.test', 'Gamma', null);
        $otherUsers  = $this->seedSite(2, 'https://x.test', 'NotMine', '2026-06-15 02:00:00');

        // at-risk site: 1 critical + 2 high + 1 low (+ another user's findings, must be excluded)
        $this->repo->replaceForSite($atRisk, [
            $this->finding('wordfence', 'crit', 'critical'),
            $this->finding('elementor', 'high', 'high'),
            $this->finding('wpforms', 'high', 'high'),
            $this->finding('akismet', 'low', 'low'),
        ], '2026-06-15 02:00:00');
        $this->repo->replaceForSite($otherUsers, [$this->finding('x', 'crit', 'critical')], '2026-06-15 02:00:00');

        $rows = $this->repo->findFleetSummariesForUser(1);

        self::assertCount(3, $rows, 'only user 1 sites; other user excluded');
        $by = [];
        foreach ($rows as $r) { $by[$r['site_id']] = $r; }

        self::assertSame(1, $by[$atRisk]['critical']);
        self::assertSame(2, $by[$atRisk]['high']);
        self::assertSame(0, $by[$atRisk]['medium']);
        self::assertSame(1, $by[$atRisk]['low']);
        self::assertSame(4, $by[$atRisk]['total']);
        self::assertSame('Acme', $by[$atRisk]['label']);

        self::assertSame(0, $by[$clean]['total']);
        self::assertNotNull($by[$clean]['last_security_scan_at'], 'clean = scanned');

        self::assertSame(0, $by[$neverScan]['total']);
        self::assertNull($by[$neverScan]['last_security_scan_at'], 'never-scanned = null scan time');
    }

    /** @return array<string,mixed> */
    private function finding(string $slug, string $name, string $severity): array
    {
        return [
            'type' => 'plugin', 'slug' => $slug, 'component_name' => $name, 'installed_version' => '1.0',
            'severity' => $severity, 'cvss_score' => null, 'cve' => null, 'fixed_in' => '2.0',
            'title' => 'x', 'source_id' => 's',
        ];
    }

    private function seedSite(int $userId, string $url, string $label, ?string $lastScan): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'               => $userId,
            'url'                   => $url,
            'label'                 => $label,
            'status'                => 'active',
            'last_security_scan_at' => $lastScan,
            'created_at'            => gmdate('Y-m-d H:i:s'),
            'updated_at'            => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }
}
```

- [ ] **Step 2: Run red** — `composer test:integration -- --filter SiteVulnerabilitiesFleetTest` → FAIL (method missing).

- [ ] **Step 3: Add the import + method to `src/Services/SiteVulnerabilitiesRepository.php`.** Add `use Defyn\Dashboard\Schema\SitesTable;` next to the existing `use Defyn\Dashboard\Schema\SiteVulnerabilitiesTable;`. Then add:

```php
/**
 * P4.2 — fleet rollup: every site owned by $userId LEFT JOIN its findings,
 * one row per site with per-severity counts. Clean (scanned, no findings) and
 * never-scanned sites both come back with zero counts; they are distinguished
 * by `last_security_scan_at` (set vs null). One GROUP BY query — no N+1.
 *
 * @return list<array{site_id:int,label:string,url:string,last_security_scan_at:?string,critical:int,high:int,medium:int,low:int,total:int}>
 */
public function findFleetSummariesForUser(int $userId): array
{
    global $wpdb;
    $sv    = SiteVulnerabilitiesTable::tableName();
    $sites = SitesTable::tableName();

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT s.id AS site_id, s.label AS label, s.url AS url,
                    s.last_security_scan_at AS last_security_scan_at,
                    SUM(CASE WHEN sv.severity = 'critical' THEN 1 ELSE 0 END) AS critical,
                    SUM(CASE WHEN sv.severity = 'high'     THEN 1 ELSE 0 END) AS high,
                    SUM(CASE WHEN sv.severity = 'medium'   THEN 1 ELSE 0 END) AS medium,
                    SUM(CASE WHEN sv.severity = 'low'      THEN 1 ELSE 0 END) AS low,
                    COUNT(sv.id) AS total
             FROM {$sites} s
             LEFT JOIN {$sv} sv ON sv.site_id = s.id
             WHERE s.user_id = %d
             GROUP BY s.id, s.label, s.url, s.last_security_scan_at
             ORDER BY s.id ASC",
            $userId
        ),
        ARRAY_A
    );

    return array_map(static fn (array $r): array => [
        'site_id'               => (int) $r['site_id'],
        'label'                 => (string) $r['label'],
        'url'                   => (string) $r['url'],
        'last_security_scan_at' => $r['last_security_scan_at'] !== null ? (string) $r['last_security_scan_at'] : null,
        'critical'              => (int) $r['critical'],
        'high'                  => (int) $r['high'],
        'medium'                => (int) $r['medium'],
        'low'                   => (int) $r['low'],
        'total'                 => (int) $r['total'],
    ], $rows ?: []);
}
```

- [ ] **Step 4: Run green** → PASS.

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/src/Services/SiteVulnerabilitiesRepository.php packages/dashboard-plugin/tests/Integration/Services/SiteVulnerabilitiesFleetTest.php
git commit -m "feat(p4-2): SiteVulnerabilitiesRepository::findFleetSummariesForUser (fleet GROUP BY rollup)"
```

---

## Task 2: `Services\SecurityService::compose`

**Files:**
- Create: `src/Services/SecurityService.php`
- Test: `tests/Integration/Services/SecurityServiceTest.php`

Composes the fleet payload (summary + status-sorted sites). Clone of `MonitoringService` shape (direct payload `{summary, sites, generated_at}`), constructor-injectable repo with a prod default.

- [ ] **Step 1: Write the failing test** `tests/Integration/Services/SecurityServiceTest.php` (same clean-slate `setUp` + seed helper as Task 1):

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Services\SecurityService;
use Defyn\Dashboard\Services\SiteVulnerabilitiesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SecurityServiceTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activation::ensureSchema();
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_site_vulnerabilities', 'defyn_sites'] as $t) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}{$t}");
        }
        // phpcs:enable WordPress.DB.PreparedSQL
    }

    public function testComposeSummaryAndSortOrder(): void
    {
        $repo = new SiteVulnerabilitiesRepository();
        $crit  = $this->seedSite(1, 'https://c.test', 'CritSite', '2026-06-15 02:00:00');
        $high  = $this->seedSite(1, 'https://h.test', 'HighSite', '2026-06-15 02:00:00');
        $clean = $this->seedSite(1, 'https://b.test', 'CleanSite', '2026-06-15 02:00:00');
        $never = $this->seedSite(1, 'https://n.test', 'NeverSite', null);

        $repo->replaceForSite($crit, [$this->finding('critical')], '2026-06-15 02:00:00');
        $repo->replaceForSite($high, [$this->finding('high')], '2026-06-15 02:00:00');

        $payload = (new SecurityService())->compose(1);

        self::assertSame(4, $payload['summary']['total_sites']);
        self::assertSame(3, $payload['summary']['scanned_sites']); // crit, high, clean scanned; never not
        self::assertSame(2, $payload['summary']['sites_at_risk']);
        self::assertSame(1, $payload['summary']['critical']);
        self::assertSame(1, $payload['summary']['high']);

        // sort order: at-risk (worst first: crit then high) → clean → never-scanned
        $ids = array_map(static fn ($s) => $s['site_id'], $payload['sites']);
        self::assertSame([$crit, $high, $clean, $never], $ids);
        self::assertArrayHasKey('counts', $payload['sites'][0]);
        self::assertSame(1, $payload['sites'][0]['counts']['critical']);
        self::assertArrayHasKey('generated_at', $payload);
    }

    /** @return array<string,mixed> */
    private function finding(string $severity): array
    {
        return ['type'=>'plugin','slug'=>'x','component_name'=>'X','installed_version'=>'1.0',
                'severity'=>$severity,'cvss_score'=>null,'cve'=>null,'fixed_in'=>'2.0','title'=>'x','source_id'=>'s'];
    }

    private function seedSite(int $userId, string $url, string $label, ?string $lastScan): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'=>$userId,'url'=>$url,'label'=>$label,'status'=>'active',
            'last_security_scan_at'=>$lastScan,'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }
}
```

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Create `src/Services/SecurityService.php`:**

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

/**
 * P4.2 — fleet security rollup. Mirrors MonitoringService: a direct payload
 * (no {data} envelope) with a summary + a status-sorted site list.
 */
final class SecurityService
{
    public function __construct(
        private readonly SiteVulnerabilitiesRepository $findings = new SiteVulnerabilitiesRepository(),
    ) {
    }

    public function compose(int $userId): array
    {
        $rows = $this->findings->findFleetSummariesForUser($userId);

        $totalSites   = count($rows);
        $scannedSites = 0;
        $sitesAtRisk  = 0;
        $crit = 0; $high = 0; $med = 0; $low = 0;

        foreach ($rows as $r) {
            if ($r['last_security_scan_at'] !== null) {
                $scannedSites++;
            }
            if ($r['total'] > 0) {
                $sitesAtRisk++;
            }
            $crit += $r['critical'];
            $high += $r['high'];
            $med  += $r['medium'];
            $low  += $r['low'];
        }

        // Sort: (1) at-risk (total>0) worst-severity first; (2) scanned-clean; (3) never-scanned.
        usort($rows, static function (array $a, array $b): int {
            $rank = static fn (array $x): int => $x['total'] > 0 ? 0 : ($x['last_security_scan_at'] !== null ? 1 : 2);
            $ra = $rank($a); $rb = $rank($b);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            if ($ra === 0) {
                $cmp = [$b['critical'], $b['high'], $b['medium'], $b['low'], $b['total']]
                    <=> [$a['critical'], $a['high'], $a['medium'], $a['low'], $a['total']];
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            return strcmp($a['label'], $b['label']);
        });

        $sites = array_map(static fn (array $r): array => [
            'site_id'               => $r['site_id'],
            'label'                 => $r['label'],
            'url'                   => $r['url'],
            'last_security_scan_at' => $r['last_security_scan_at'],
            'counts'                => [
                'critical' => $r['critical'],
                'high'     => $r['high'],
                'medium'   => $r['medium'],
                'low'      => $r['low'],
                'total'    => $r['total'],
            ],
        ], $rows);

        return [
            'summary' => [
                'total_sites'   => $totalSites,
                'scanned_sites' => $scannedSites,
                'sites_at_risk' => $sitesAtRisk,
                'critical'      => $crit,
                'high'          => $high,
                'medium'        => $med,
                'low'           => $low,
            ],
            'sites'        => $sites,
            'generated_at' => gmdate('Y-m-d H:i:s'),
        ];
    }
}
```

- [ ] **Step 4: Run green** → PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Services/SecurityService.php packages/dashboard-plugin/tests/Integration/Services/SecurityServiceTest.php
git commit -m "feat(p4-2): SecurityService::compose — fleet summary + status-sorted sites"
```

---

## Task 3: `GET /security` — `SecurityController` + `RateLimit::security` + route

**Files:**
- Create: `src/Rest/SecurityController.php`
- Modify: `src/Rest/Middleware/RateLimit.php` (add `security` 30/min)
- Modify: `src/Rest/RestRouter.php` (register GET route)
- Test: `tests/Integration/Rest/SecurityFleetTest.php`

- [ ] **Step 1: Write the failing test** `tests/Integration/Rest/SecurityFleetTest.php`. **Read an existing REST test first** (`tests/Integration/Rest/MonitoringTest.php` or `AuthMeTest.php`) and copy its EXACT JWT-mint + auth-header lines (the `TokenService` API). Cover: **200** direct payload (`summary` + `sites` keys; `summary.total_sites` present) for an authenticated user; **401** without auth. Skeleton (adapt the token line to the real one):

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

final class SecurityFleetTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        do_action('rest_api_init');
    }

    public function testAuthenticatedGetReturns200DirectPayload(): void
    {
        $token = $this->mintAccessToken(1); // helper copied from MonitoringTest/AuthMeTest
        $req = new WP_REST_Request('GET', '/defyn/v1/security');
        $req->set_header('Authorization', 'Bearer ' . $token);
        $res = rest_do_request($req);
        self::assertSame(200, $res->get_status());
        $data = $res->get_data();
        self::assertArrayHasKey('summary', $data);
        self::assertArrayHasKey('sites', $data);
        self::assertArrayHasKey('total_sites', $data['summary']);
    }

    public function testNoAuthReturns401(): void
    {
        $res = rest_do_request(new WP_REST_Request('GET', '/defyn/v1/security'));
        self::assertSame(401, $res->get_status());
    }
}
```

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Create `src/Rest/SecurityController.php`** (clone `MonitoringController`):

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\SecurityService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P4.2 — GET /defyn/v1/security. Read-only fleet vulnerability view.
 * Mirrors MonitoringController: direct payload, 30/min bucket.
 */
final class SecurityController
{
    public function __construct(
        private readonly SecurityService $service = new SecurityService(),
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        return new WP_REST_Response($this->service->compose($userId), 200);
    }
}
```

- [ ] **Step 4: Add the `security` bucket to `src/Rest/Middleware/RateLimit.php`** (clone `monitoring()` — 30/min, per-user, distinct prefix):

```php
public const SECURITY_LIMIT  = 30;
public const SECURITY_WINDOW = MINUTE_IN_SECONDS;

/** Permission callback for GET /security. Per-user 30/MINUTE (mirror monitoring()). @return true|\WP_Error */
public static function security(WP_REST_Request $request)
{
    $authResult = RequireAuth::check($request);
    if (is_wp_error($authResult)) {
        return $authResult;
    }
    $userId = (int) $request->get_param('_authenticated_user_id');
    $key    = sprintf('defyn_rl_security_%d', $userId);
    $count  = (int) (get_transient($key) ?: 0);
    if ($count >= self::SECURITY_LIMIT) {
        return new \WP_Error('security.rate_limited', 'Too many requests. Try again shortly.', ['status' => 429]);
    }
    set_transient($key, $count + 1, self::SECURITY_WINDOW);
    return true;
}
```

- [ ] **Step 5: Register the route in `src/Rest/RestRouter.php`** (next to the `/monitoring` registration; add `use Defyn\Dashboard\Rest\SecurityController;` if the file imports controllers — match its style):

```php
register_rest_route(self::NAMESPACE, '/security', [
    'methods'             => 'GET',
    'callback'            => [new SecurityController(), 'handle'],
    'permission_callback' => [RateLimit::class, 'security'],
]);
```

- [ ] **Step 6: Run green** — `composer test:integration -- --filter SecurityFleet` → PASS.

- [ ] **Step 7: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/SecurityController.php packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php packages/dashboard-plugin/src/Rest/RestRouter.php packages/dashboard-plugin/tests/Integration/Rest/SecurityFleetTest.php
git commit -m "feat(p4-2): GET /security fleet endpoint + security 30/min bucket + route"
```

---

## Task 4: `POST /security/scan-all` — fan-out action

**Files:**
- Create: `src/Rest/SecurityScanAllController.php`
- Modify: `src/Rest/Middleware/RateLimit.php` (add `securityScanAll` 5/hr)
- Modify: `src/Rest/RestRouter.php` (register POST route)
- Test: `tests/Integration/Rest/SecurityScanAllTest.php`

> **CRITICAL (corrects spec §6):** scope the fan-out to **`SitesRepository::findAllForUser($userId)`** (the operator's own sites), NOT `findAllSchedulable()`. `findAllSchedulable()` is **unscoped** (whole fleet, every owner — it exists only for the system cron `SecurityScanAll`); using it here would let one operator scan another operator's sites. This matches P2.6 `OverviewSyncAllController` exactly.

Behavior: `VulnFeedService::refreshIfStale()` once (best-effort, no-ops without a key) → fan-out `as_schedule_single_action(time(), SecurityScan::HOOK, [$id], 'defyn')` per owned site → emit ONE `security.scan_all_requested` activity event (`site_id=null`) **only when count > 0** → 202 when sites > 0 / 200 no-op when 0.

- [ ] **Step 1: Write the failing test** `tests/Integration/Rest/SecurityScanAllTest.php` (mirror `OverviewSyncAllControllerTest` — copy its JWT-auth + site-seeding helpers). Assert: **202** + `scheduled_count` + one `SecurityScan` action per owned site (`as_next_scheduled_action(\Defyn\Dashboard\Jobs\SecurityScan::HOOK, [$id], 'defyn')` not false); exactly ONE `security.scan_all_requested` activity row (`site_id` null, `details.scheduled_count`) when sites>0; **200 + ZERO** `security.scan_all_requested` rows when the user has no sites.

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Create `src/Rest/SecurityScanAllController.php`** (clone `OverviewSyncAllController`, swap the job hook + add the feed refresh):

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\SecurityScan;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\VulnFeedService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P4.2 — POST /defyn/v1/security/scan-all. Refreshes the vuln feed once, then
 * fan-outs the P4.1 `defyn_security_scan` AS job for every site owned by the
 * operator. Emits ONE fleet-scoped `security.scan_all_requested` activity event
 * (site_id=null) only when scheduled_count > 0. Mirrors OverviewSyncAllController.
 */
final class SecurityScanAllController
{
    public function __construct(
        private readonly SitesRepository $sites = new SitesRepository(),
        private readonly ActivityLogger $logger = new ActivityLogger(),
        private readonly VulnFeedService $feed = new VulnFeedService(),
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        ob_start();
        try {
            $userId = (int) $request->get_param('_authenticated_user_id');

            // Refresh the global feed ONCE before the fan-out (best-effort, no-ops without a key).
            $this->feed->refreshIfStale();

            $sites = $this->sites->findAllForUser($userId); // user-scoped — NOT findAllSchedulable()
            $ids   = array_map(static fn ($s) => $s->id, $sites);

            if (function_exists('as_schedule_single_action')) {
                foreach ($ids as $id) {
                    as_schedule_single_action(time(), SecurityScan::HOOK, [$id], 'defyn');
                }
            }

            if (count($ids) > 0) {
                $this->logger->log(
                    $userId,
                    null,
                    'security.scan_all_requested',
                    ['scheduled_count' => count($ids), 'site_ids' => array_values($ids)]
                );
            }

            return new WP_REST_Response(
                [
                    'scheduled_count' => count($ids),
                    'site_ids'        => array_values($ids),
                    'scheduled_at'    => gmdate('Y-m-d H:i:s'),
                ],
                count($ids) > 0 ? 202 : 200
            );
        } finally {
            ob_end_clean();
        }
    }
}
```

- [ ] **Step 4: Add the `securityScanAll` bucket to `RateLimit.php`** (clone `overviewSyncAll()` but **5/hr**, distinct prefix):

```php
public const SECURITY_SCAN_ALL_LIMIT  = 5;
public const SECURITY_SCAN_ALL_WINDOW = HOUR_IN_SECONDS;

/** Permission callback for POST /security/scan-all. Per-user 5/HOUR. @return true|\WP_Error */
public static function securityScanAll(WP_REST_Request $request)
{
    $authResult = RequireAuth::check($request);
    if (is_wp_error($authResult)) {
        return $authResult;
    }
    $userId = (int) $request->get_param('_authenticated_user_id');
    $key    = sprintf('defyn_rl_securityScanAll_%d', $userId);
    $count  = (int) (get_transient($key) ?: 0);
    if ($count >= self::SECURITY_SCAN_ALL_LIMIT) {
        return new \WP_Error('security.rate_limited', 'Too many fleet scans. Try again in an hour.', ['status' => 429]);
    }
    set_transient($key, $count + 1, self::SECURITY_SCAN_ALL_WINDOW);
    return true;
}
```

- [ ] **Step 5: Register the route in `RestRouter.php`:**

```php
register_rest_route(self::NAMESPACE, '/security/scan-all', [
    'methods'             => 'POST',
    'callback'            => [new SecurityScanAllController(), 'handle'],
    'permission_callback' => [RateLimit::class, 'securityScanAll'],
]);
```

- [ ] **Step 6: Run green** — `composer test:integration -- --filter SecurityScanAll` → PASS.

- [ ] **Step 7: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/SecurityScanAllController.php packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php packages/dashboard-plugin/src/Rest/RestRouter.php packages/dashboard-plugin/tests/Integration/Rest/SecurityScanAllTest.php
git commit -m "feat(p4-2): POST /security/scan-all (user-scoped fan-out) + securityScanAll 5/hr + route"
```

---

## Task 5: CORS regression + version bump

**Files:**
- Create: `tests/Integration/Rest/SecurityFleetCorsTest.php`
- Modify: `packages/dashboard-plugin/defyn-dashboard.php` (version 0.13.0 → 0.14.0)

- [ ] **Step 1: Write the CORS test** `tests/Integration/Rest/SecurityFleetCorsTest.php` (clone `MonitoringCorsTest`, asserting the `Access-Control-Allow-*` headers for BOTH new routes):

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Rest\Middleware\Cors;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;
use WP_REST_Response;

/** @group integration */
final class SecurityFleetCorsTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_SPA_ORIGIN')) {
            define('DEFYN_SPA_ORIGIN', 'http://localhost:5173');
        }
        do_action('rest_api_init');
    }

    /** @dataProvider routes */
    public function testCorsHeaders(string $method, string $route): void
    {
        $response = new WP_REST_Response(['ok' => true], 200);
        $request  = new WP_REST_Request($method, $route);
        $served   = Cors::apply(false, $response, $request, rest_get_server());

        $headers = $response->get_headers();
        self::assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        self::assertSame(DEFYN_SPA_ORIGIN, $headers['Access-Control-Allow-Origin']);
        self::assertSame('true', $headers['Access-Control-Allow-Credentials']);
        self::assertSame(false, $served);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function routes(): array
    {
        return [
            'GET /security'           => ['GET',  '/defyn/v1/security'],
            'POST /security/scan-all' => ['POST', '/defyn/v1/security/scan-all'],
        ];
    }
}
```

- [ ] **Step 2: Run it** — `composer test:integration -- --filter SecurityFleetCors` → PASS.

- [ ] **Step 3: Bump the dashboard version** in `defyn-dashboard.php`: header `* Version:           0.14.0` and `define('DEFYN_DASHBOARD_VERSION', '0.14.0');`. `php -l defyn-dashboard.php` → no errors.

- [ ] **Step 4: Run the FULL PHP suite** to confirm green: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit` → tolerate ONLY the `UninstallTest` carry-forward. **No** schema-version-pin changes (schema stays v11).

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/tests/Integration/Rest/SecurityFleetCorsTest.php packages/dashboard-plugin/defyn-dashboard.php
git commit -m "feat(p4-2): CORS regression for /security routes + bump dashboard to v0.14.0"
```

---

## Task 6: SPA — Zod schemas + MSW handlers

**Files:**
- Modify: `apps/web/src/types/api.ts` (add `fleetSiteSecuritySchema` + `securitySchema` + `scanAllSecurityResponseSchema`)
- Modify: `apps/web/src/test/handlers.ts` (GET /security empty + POST /security/scan-all 200)
- Test: `apps/web/tests/types/securityFleetSchemas.test.ts`

- [ ] **Step 1: Write the failing schema test** `apps/web/tests/types/securityFleetSchemas.test.ts` — parse a fleet payload (an at-risk site + a clean site + a never-scanned site) via `securitySchema`; parse a scan-all response via `scanAllSecurityResponseSchema`.

- [ ] **Step 2: Run red** (Node 22 + pnpm) → FAIL.

- [ ] **Step 3: Edit `src/types/api.ts`** (match the `monitoringSchema` style):

```typescript
export const fleetSiteSecuritySchema = z.object({
  site_id: z.number().int().positive(),
  label: z.string(),
  url: z.string(),
  last_security_scan_at: z.string().nullable(),
  counts: z.object({
    critical: z.number().int().nonnegative(),
    high: z.number().int().nonnegative(),
    medium: z.number().int().nonnegative(),
    low: z.number().int().nonnegative(),
    total: z.number().int().nonnegative(),
  }),
});
export type FleetSiteSecurity = z.infer<typeof fleetSiteSecuritySchema>;

export const securitySchema = z.object({
  summary: z.object({
    total_sites: z.number().int().nonnegative(),
    scanned_sites: z.number().int().nonnegative(),
    sites_at_risk: z.number().int().nonnegative(),
    critical: z.number().int().nonnegative(),
    high: z.number().int().nonnegative(),
    medium: z.number().int().nonnegative(),
    low: z.number().int().nonnegative(),
  }),
  sites: z.array(fleetSiteSecuritySchema),
  generated_at: z.string(),
});
export type Security = z.infer<typeof securitySchema>;

export const scanAllSecurityResponseSchema = z.object({
  scheduled_count: z.number().int().nonnegative(),
  site_ids: z.array(z.number().int()),
  scheduled_at: z.string(),
});
export type ScanAllSecurityResponse = z.infer<typeof scanAllSecurityResponseSchema>;
```

- [ ] **Step 4: Add MSW handlers to `src/test/handlers.ts`:**

```typescript
// P4.2 — GET /security — empty fleet by default; tests override via server.use().
http.get('*/wp-json/defyn/v1/security', () => {
  return HttpResponse.json({
    summary: { total_sites: 0, scanned_sites: 0, sites_at_risk: 0, critical: 0, high: 0, medium: 0, low: 0 },
    sites: [],
    generated_at: '2026-06-15 03:00:00',
  });
}),
// P4.2 — POST /security/scan-all — default synthetic 200; tests override via server.use().
http.post('*/wp-json/defyn/v1/security/scan-all', () => {
  return HttpResponse.json({ scheduled_count: 0, site_ids: [], scheduled_at: '2026-06-15 03:00:00' }, { status: 200 });
}),
```

- [ ] **Step 5: Run green** → PASS. Then full suite `pnpm test` → only the 4 carry-forwards.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/types/api.ts apps/web/src/test/handlers.ts apps/web/tests/types/securityFleetSchemas.test.ts
git commit -m "feat(p4-2): SPA security fleet Zod schemas + MSW handlers"
```

---

## Task 7: SPA — `useSecurity` query hook

**Files:**
- Create: `apps/web/src/lib/queries/useSecurity.ts`
- Test: `apps/web/tests/useSecurity.test.tsx`

- [ ] **Step 1: Write the failing test** (mirror a `useMonitoring`/`useSiteVulnerabilities` hook test): override GET `/security` to return a one-site fleet; assert `result.current.data.summary.total_sites === 1`.

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Create `src/lib/queries/useSecurity.ts`** (clone `useMonitoring`; `staleTime` instead of an aggressive poll — spec §7: no fleet-wide live poll):

```typescript
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { securitySchema } from '@/types/api';

export function useSecurity() {
  return useQuery({
    queryKey: ['security'],
    queryFn: async () => {
      const data = await apiClient.get<unknown>('/security');
      return securitySchema.parse(data);
    },
    staleTime: 30_000,
  });
}
```
> Confirm the `apiClient` import path matches `useMonitoring.ts` exactly (`@/lib/apiClient`).

- [ ] **Step 4: Run green** → PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/lib/queries/useSecurity.ts apps/web/tests/useSecurity.test.tsx
git commit -m "feat(p4-2): useSecurity query hook"
```

---

## Task 8: SPA — `useScanAllSecurity` mutation hook

**Files:**
- Create: `apps/web/src/lib/mutations/useScanAllSecurity.ts`
- Test: `apps/web/tests/useScanAllSecurity.test.tsx`

- [ ] **Step 1: Write the failing test** (mirror `useSyncAllSites` test): calling `mutate()` POSTs `/security/scan-all` and resolves with `scheduled_count`; on success `['security']` is invalidated.

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Create `src/lib/mutations/useScanAllSecurity.ts`** (clone `useSyncAllSites`, invalidate `['security']`):

```typescript
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { scanAllSecurityResponseSchema, type ScanAllSecurityResponse } from '@/types/api';

/**
 * P4.2 — POSTs /security/scan-all. Server refreshes the feed once + fan-outs a
 * SecurityScan job per owned site. On success invalidate ['security'] so the
 * fleet table reflects updated scan timestamps on the next refetch. No live poll
 * (the scan runs async via AS jobs).
 */
export function useScanAllSecurity() {
  const queryClient = useQueryClient();
  return useMutation<ScanAllSecurityResponse, Error, void>({
    mutationFn: async () => {
      const data = await apiClient.post<unknown>('/security/scan-all');
      return scanAllSecurityResponseSchema.parse(data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['security'] });
    },
  });
}
```
> Match the exact `apiClient.post` signature used by `useSyncAllSites.ts` (no body arg).

- [ ] **Step 4: Run green** → PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/lib/mutations/useScanAllSecurity.ts apps/web/tests/useScanAllSecurity.test.tsx
git commit -m "feat(p4-2): useScanAllSecurity mutation hook"
```

---

## Task 9: SPA — `SecuritySummaryStrip` + `SecurityFleetTable`

**Files:**
- Create: `apps/web/src/components/security/SecuritySummaryStrip.tsx`
- Create: `apps/web/src/components/security/SecurityFleetTable.tsx`
- Test: `apps/web/tests/components/security/SecurityFleetTable.test.tsx`

- [ ] **Step 1: Write the failing render test** `tests/components/security/SecurityFleetTable.test.tsx` (wrap in `MemoryRouter` — rows `<Link>`): given an at-risk row + a clean row + a never-scanned row → renders the component label, a "1 crit" chip for the at-risk row, "clean" for the clean row, "not yet scanned" for the null-scan row.

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Create `SecuritySummaryStrip.tsx`** — KPI strip taking `summary` (Sites at risk · Critical · High · Scanned X/Y). **Read `src/components/monitoring/MonitoringSummaryStrip.tsx` first** and mirror its markup/Tailwind.

- [ ] **Step 4: Create `SecurityFleetTable.tsx`** — props `{ sites: FleetSiteSecurity[] }`. One row per site: `<Link to={`/sites/${s.site_id}`}>` rendering label + muted url; a Findings cell that renders, per non-zero severity, a colored count chip (`{n} crit/high/med/low`); when `counts.total === 0 && last_security_scan_at !== null` → a green `✓ clean` chip; when `last_security_scan_at === null` → a muted `not yet scanned` label. Total column + Scanned column (relative time via `parseUtc` from `@/lib/monitoring`, or a tiny inline formatter). Severity chip colors from `SiteSecurityPanel`/`AttentionReasonChip` (red crit/high, amber medium, slate low). **No `useEffect`** — pure render off props (no render-loop risk).

- [ ] **Step 5: Run green** → PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/components/security/SecuritySummaryStrip.tsx apps/web/src/components/security/SecurityFleetTable.tsx apps/web/tests/components/security/SecurityFleetTable.test.tsx
git commit -m "feat(p4-2): SecuritySummaryStrip + SecurityFleetTable (severity chips, clean/never-scanned states)"
```

---

## Task 10: SPA — Scan-all button + dialog + nav link

**Files:**
- Create: `apps/web/src/components/security/ScanAllSitesSecurityButton.tsx`
- Create: `apps/web/src/components/security/ConfirmScanAllSecurityDialog.tsx`
- Create: `apps/web/src/components/nav/SecurityNavLink.tsx`
- Test: `apps/web/tests/components/security/ScanAllSitesSecurityButton.test.tsx`

- [ ] **Step 1: Write the failing test** — render `ScanAllSitesSecurityButton totalSites={3}`; click it → confirm dialog appears; click confirm → POST `/security/scan-all` fires (MSW default 200) without error; with `totalSites={0}` the button is disabled.

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Create `ConfirmScanAllSecurityDialog.tsx`** (clone P2.6 `ConfirmSyncAllDialog` — **neutral** primary `Button variant="default"`, Cancel default focus via `cancelRef`): title "Scan all {totalSites} sites now?", body "This queues a fresh vulnerability scan for every connected site (it refreshes the Wordfence feed first). Results appear as each site finishes." Confirm label "Scan all {totalSites} sites".

- [ ] **Step 4: Create `ScanAllSitesSecurityButton.tsx`** (clone P2.6 `SyncAllSitesButton`): outline button, `ShieldCheck` (or `RefreshCw`) icon, disabled when `totalSites === 0`, pending state "Scanning…", uses `useScanAllSecurity` + the confirm dialog.

- [ ] **Step 5: Create `SecurityNavLink.tsx`** (clone `MonitoringNavLink`): `<Link to="/security">Security</Link>`.

- [ ] **Step 6: Run green** → PASS.

- [ ] **Step 7: Commit**

```bash
git add apps/web/src/components/security/ScanAllSitesSecurityButton.tsx apps/web/src/components/security/ConfirmScanAllSecurityDialog.tsx apps/web/src/components/nav/SecurityNavLink.tsx apps/web/tests/components/security/ScanAllSitesSecurityButton.test.tsx
git commit -m "feat(p4-2): ScanAllSitesSecurityButton + neutral confirm dialog + SecurityNavLink"
```

---

## Task 11: SPA — `/security` route + router + Overview nav wiring

**Files:**
- Create: `apps/web/src/routes/Security.tsx`
- Modify: `apps/web/src/App.tsx` (register `/security`)
- Modify: `apps/web/src/routes/Overview.tsx` (add `<SecurityNavLink />` to the header)
- Test: `apps/web/tests/routes/Security.test.tsx`

- [ ] **Step 1: Write the failing page test** `tests/routes/Security.test.tsx` (mirror `Monitoring.test.tsx`): (a) empty fleet (`total_sites:0`) → "No sites yet"; (b) all-clear (sites exist, `sites_at_risk:0`) → the all-clear banner + the table still lists the clean site; (c) at-risk fleet → the strip + a row with the at-risk site label.

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Create `src/routes/Security.tsx`** (clone `Monitoring.tsx`): `useSecurity()`; header with `<h1>Security</h1>` + a back-link to `/overview` + `<ScanAllSitesSecurityButton totalSites={data.summary.total_sites} />`. Body: loading / error / empty (`total_sites === 0` → "No sites yet") / else `<SecuritySummaryStrip summary={data.summary} />` + (when `summary.sites_at_risk === 0` an all-clear banner "No known vulnerabilities across the fleet") + `<SecurityFleetTable sites={data.sites} />` (always render the table when sites exist, so clean/never-scanned rows stay visible). `export default Security;` plus a named `export function Security`.

- [ ] **Step 4: Edit `src/App.tsx`** — `import { Security } from './routes/Security'` and add inside the `RequireAuth` outlet: `<Route path="/security" element={<Security />} />` (beside `/monitoring`).

- [ ] **Step 5: Edit `src/routes/Overview.tsx`** — add `<SecurityNavLink />` in the header beside `<MonitoringNavLink />` (read the header block; match the surrounding import + JSX placement).

- [ ] **Step 6: Run green** — `pnpm test -- tests/routes/Security.test.tsx` → PASS. Then the FULL suite `pnpm test` → only the 4 carry-forwards. (If an `Overview` test asserts an exact nav-link set, update it for the additive `Security` link.)

- [ ] **Step 7: Commit**

```bash
git add apps/web/src/routes/Security.tsx apps/web/src/App.tsx apps/web/src/routes/Overview.tsx apps/web/tests/routes/Security.test.tsx
git commit -m "feat(p4-2): /security route + router wiring + SecurityNavLink in Overview header"
```

---

## Task 12: Release — build, ship, smoke, tag, MEMORY

**Files:** build artifacts only (`dist/defyn-dashboard-0.14.0.zip`).

- [ ] **Step 1: Full suites green.**
```bash
cd packages/dashboard-plugin && COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit   # PHP, tolerate UninstallTest only
cd ../../apps/web && export FNM_DIR="$HOME/.fnm"; eval "$(fnm env --shell bash)"; fnm use 22 && pnpm test   # SPA, tolerate the 4 carry-forwards
```

- [ ] **Step 2: Build the dashboard zip** (symfony + json-machine preserving — exclude ONLY tests/dev tooling, NEVER `vendor/*`; top-level folder `dashboard-plugin/`; output to the **repo-root `dist/`**):
```bash
cd packages/dashboard-plugin
composer install --no-dev --classmap-authoritative
cd ..
rm -f ../dist/defyn-dashboard-0.14.0.zip && mkdir -p ../dist
zip -rq ../dist/defyn-dashboard-0.14.0.zip dashboard-plugin \
  -x 'dashboard-plugin/tests/*' '*wp-tests-config.php' 'dashboard-plugin/.phpunit.result.cache' \
     'dashboard-plugin/test-output.log' 'dashboard-plugin/phpunit.xml' 'dashboard-plugin/composer.lock' \
     'dashboard-plugin/.github/*' 'dashboard-plugin/.gitignore'
cd ..
# VERIFY (symfony MUST print 2, json-machine MUST print >=1):
unzip -l dist/defyn-dashboard-0.14.0.zip | grep -cE "deprecation-contracts/function\.php|polyfill-php83/bootstrap\.php"
unzip -l dist/defyn-dashboard-0.14.0.zip | grep -c "json-machine/src/Items\.php"
cd packages/dashboard-plugin && composer install   # restore dev autoload
```
> NOTE: the zip is built from inside `packages/` so the archive's top-level folder is `dashboard-plugin/` and the output lands in the repo-root `dist/` (where all prior release zips live — the P4.1 zip was initially mis-placed in `packages/dist/`; do NOT repeat that).

- [ ] **Step 3: Build the SPA** — `cd apps/web && pnpm build` (Cloudflare auto-deploys from `main`).

- [ ] **Step 4: Merge to main + push:**
```bash
cd "/Users/pradeep/Local Sites/defynWP"
git checkout main && git merge --ff-only p4-2-security-fleet && git push origin main
```

- [ ] **Step 5: Kinsta install (MANUAL USER STEP — flag it).** Operator uploads `dist/defyn-dashboard-0.14.0.zip` via WP Admin → Plugins → "Replace current with uploaded version" on `defynwp.defyn.agency`, then clears the MyKinsta cache. **No schema migration** (stays v11). (The `DEFYN_WORDFENCE_API_KEY` from P4.1 is unchanged.)

- [ ] **Step 6: Production smoke (API curl only; login JWT field is `access_token`).** After the user confirms install:
  - `POST /auth/login` → capture `access_token`.
  - `GET /security` (auth) → **200** direct payload with `summary.total_sites` + `sites:[]` (proves the route + `SecurityService` live). No-auth → **401**.
  - `POST /security/scan-all` (auth) → **200** no-op `{scheduled_count:0,...}` at zero sites. Repeat to trip **429** `security.rate_limited` after 5 calls.
  - SPA: `/security` route 200; deployed bundle contains "Sites at risk" / "Scan all sites" / "No sites yet".
  - (Happy 202 + at-risk table foreclosed by the zero-sites prod state; covered by green PHP/SPA tests.)

- [ ] **Step 7: Tag + push:**
```bash
git tag p4-2-security-fleet-complete && git push origin p4-2-security-fleet-complete
```

- [ ] **Step 8: Update MEMORY** — append a P4.2-complete entry to `project_defyn_roadmap.md` (v0.14.0, tag, schema unchanged v11, the `findAllForUser`-not-`findAllSchedulable` tenant-scope correction, smoke results, what was foreclosed) + refresh the index line in `MEMORY.md`. Set NEXT = **P4.3** (alerting on new findings + per-finding ignore/dismiss + per-site mute).

---

## Self-Review (completed during planning)

- **Spec coverage:** §4 fleet query → Task 1; §5 compose → Task 2; §6 GET /security → Task 3, POST /security/scan-all → Task 4; CORS + version → Task 5; §7 SPA (schemas → 6, query → 7, mutation → 8, table+strip → 9, button+dialog+nav → 10, route+wiring → 11); §9 testing → folded into each task; §10 release → Task 12. ✅
- **Spec correction surfaced:** §6/guardrail said `findAllSchedulable()`; the plan uses **`findAllForUser($userId)`** (Task 4) because `findAllSchedulable` is unscoped (cross-tenant). Flagged in Task 4 + to be recorded in MEMORY. ✅
- **Type consistency:** PHP `compose` payload (`summary{total_sites,scanned_sites,sites_at_risk,critical,high,medium,low}` + `sites[{site_id,label,url,last_security_scan_at,counts{critical,high,medium,low,total}}]` + `generated_at`) matches the Zod `securitySchema`/`fleetSiteSecuritySchema` (Task 6) field-for-field. `findFleetSummariesForUser` row shape (Task 1) is consumed by `compose` (Task 2). The scan-all response (`scheduled_count,site_ids,scheduled_at`) matches `scanAllSecurityResponseSchema` (Task 6) and the controller (Task 4). ✅
- **No placeholders:** every code step has real code; the two REST tests (Tasks 3, 4) reference reading the real `MonitoringTest`/`OverviewSyncAllControllerTest` for the exact JWT-mint + seed helpers (the one place a signature must be matched at implementation time). ✅
- **No schema change:** no task touches `Activation`/schema/version-pin files; guardrail #1 holds. ✅
