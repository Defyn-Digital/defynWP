# P4.3a — New-finding Security Alerting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a security scan finds a genuinely-new vulnerability on a site, email/Slack the owner a single digest (mute-respecting), reusing the P3.3 notifier machinery — a finding alerts exactly once when it first appears.

**Architecture:** Add a `notifyNewVulnerabilities` method to the `Notify\Notifier` interface + its three implementations. Inside `VulnerabilityScanService::scan`, load the prior snapshot before the wholesale `replaceForSite`, compute new findings by fingerprint `(type|slug|source_id)`, and — if any and the site isn't muted — send one best-effort digest; always record a `site.new_vulnerabilities` activity event.

**Tech Stack:** PHP 8.1 (WP plugin, PHPUnit/wp-phpunit). **Schema unchanged (v11)**. Dashboard **v0.14.0 → v0.15.0**. Connector **unchanged (v0.1.7)**. **No SPA change** (the new activity event surfaces generically — there is no central event-label map to extend).

**Spec:** `docs/superpowers/specs/2026-06-15-p4-3a-security-alerting-design.md` (commit `fad71b6`).

**Branch:** `p4-3a-security-alerting` (off `main` @ `7f199ed`).

---

## Conventions for every task

- **TDD:** failing test → red → minimal impl → green → commit.
- **PHP suite:** from `packages/dashboard-plugin/` — `composer test:unit` / `composer test:integration` / `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit` (composer's 300s wrapper truncates the full run).
- **Carry-forward to TOLERATE:** PHP 1 (`UninstallTest` wp-phpunit infra). Any OTHER failure is a regression.
- **No schema change** — do NOT bump `SCHEMA_VERSION` (stays 11) or touch version-pin tests. No connector change, no new REST endpoint.
- **Test isolation (guardrail #15):** `SiteVulnerabilitiesRepository::replaceForSite` uses an explicit `COMMIT` that escapes `WP_UnitTestCase` rollback → integration tests that seed findings MUST purge in `setUp` (`SET autocommit=1` + `DELETE FROM defyn_site_vulnerabilities` + `DELETE FROM defyn_sites`). There is **no `SitesRepository::create()`** — seed sites via a direct `$wpdb->insert($wpdb->prefix.'defyn_sites', [...])` helper.
- Namespaces: PHP `Defyn\Dashboard\...`. `ActivityLogger` is in `Defyn\Dashboard\Services\` with `log(?int userId, ?int siteId, string eventType, ?array details = null, ?string ip = null)`. `Notify\Notifier` + impls live in `src/Notify/`. `Models\Site` exposes `->id`, `->userId`, `->label`, `->url`, `->alertsMuted`.

---

## Task 1: `notifyNewVulnerabilities` across the Notifier interface + 3 implementations

**Files:**
- Modify: `src/Notify/Notifier.php` (add the 4th interface method)
- Modify: `src/Notify/EmailNotifier.php` (implement)
- Modify: `src/Notify/SlackNotifier.php` (implement)
- Modify: `src/Notify/MultiNotifier.php` (fan-out)
- Modify (compile-ripple): every TEST double that implements `Notifier` — in `tests/Unit/Notify/MultiNotifierTest.php`, `tests/Integration/Services/IncidentServiceTest.php`, `tests/Integration/Services/SslAlertServiceTest.php` (any class there `implements Notifier`)
- Test: `tests/Integration/Notify/EmailNotifierTest.php` (extend), `tests/Integration/Notify/SlackNotifierTest.php` (extend), `tests/Unit/Notify/MultiNotifierTest.php` (extend)

> The interface change is a **compile-time ripple**: the moment you add the method to `Notifier`, every implementer (3 src + N test stubs) must define it or PHP fatals. Add the method to ALL of them in this one task/commit.

- [ ] **Step 1: Write the failing EmailNotifier test.** In `tests/Integration/Notify/EmailNotifierTest.php`, add a test that captures `wp_mail` via the `pre_wp_mail` filter (or whatever the existing tests in that file use — read the file first and mirror its capture mechanism) and asserts the subject + body for `notifyNewVulnerabilities`:

```php
public function testNotifyNewVulnerabilitiesEmailSubjectAndBody(): void
{
    $captured = [];
    add_filter('pre_wp_mail', function ($null, $atts) use (&$captured) {
        $captured = $atts; // ['to'=>..,'subject'=>..,'message'=>..]
        return true;        // short-circuit actual send
    }, 10, 2);

    $site = $this->makeSiteOwnedBy($this->userWithEmail('owner@x.test')); // mirror this file's existing site/user helper
    $new = [
        ['type'=>'plugin','slug'=>'wp-file-manager','component_name'=>'WP File Manager','installed_version'=>'6.0','severity'=>'critical','cve'=>'CVE-2024-1234','fixed_in'=>'6.9'],
        ['type'=>'plugin','slug'=>'elementor','component_name'=>'Elementor','installed_version'=>'3.18.2','severity'=>'high','cve'=>null,'fixed_in'=>'3.18.3'],
    ];
    (new EmailNotifier())->notifyNewVulnerabilities($site, $new, ['critical'=>1,'high'=>1,'medium'=>0,'low'=>0]);

    self::assertStringContainsString('2 new vulnerabilities on', $captured['subject']);
    self::assertStringContainsString($site->label, $captured['subject']);
    self::assertStringContainsString('WP File Manager', $captured['message']);
    self::assertStringContainsString('Elementor', $captured['message']);
    self::assertStringContainsString('CVE-2024-1234', $captured['message']);
    self::assertStringContainsString('Critical: 1', $captured['message']);
    remove_all_filters('pre_wp_mail');
}
```
> Read the existing `EmailNotifierTest.php` first: reuse its real site/owner-user seeding helper (e.g. how `testNotifyDownEmail` builds a `Site` whose `userId` resolves to a WP user with an email). Replace `makeSiteOwnedBy`/`userWithEmail` with the actual helpers in that file. If it uses a different `wp_mail` capture (e.g. a global `$GLOBALS['phpmailer']` or a `wp_mail` mock), use that instead of `pre_wp_mail`.

- [ ] **Step 2: Run red** — `composer test:integration -- --filter EmailNotifierTest` → FATAL/FAIL (method not in interface yet).

- [ ] **Step 3: Add the method to the interface** `src/Notify/Notifier.php`:

```php
/**
 * P4.3a — one digest of newly-found vulnerabilities for a site.
 *
 * @param list<array{type:string,slug:string,component_name:string,installed_version:string,severity:string,cve:?string,fixed_in:?string}> $newVulnerabilities
 * @param array{critical:int,high:int,medium:int,low:int} $severityCounts
 */
public function notifyNewVulnerabilities(Site $site, array $newVulnerabilities, array $severityCounts): void;
```

- [ ] **Step 4: Implement in `src/Notify/EmailNotifier.php`** (reuse the file's existing private `send(Site,$subject,$body)` + `ownerEmail` helpers — read them first; they resolve the owner email + swallow Throwable):

```php
public function notifyNewVulnerabilities(Site $site, array $newVulnerabilities, array $severityCounts): void
{
    $count = count($newVulnerabilities);
    $noun  = $count === 1 ? 'vulnerability' : 'vulnerabilities';
    $subject = '🔒 ' . $count . ' new ' . $noun . ' on ' . $site->label;

    $summary = [];
    foreach (['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $k => $label) {
        if (($severityCounts[$k] ?? 0) > 0) {
            $summary[] = $label . ': ' . (int) $severityCounts[$k];
        }
    }

    $body  = $count . ' new security ' . ($count === 1 ? 'finding' : 'findings')
           . " on {$site->label} ({$site->url}).\n\n";
    if ($summary !== []) {
        $body .= implode(' · ', $summary) . "\n\n";
    }
    foreach ($newVulnerabilities as $v) {
        $line = '[' . $v['severity'] . '] ' . $v['component_name'] . ' (' . $v['type'] . ') ' . $v['installed_version'];
        if (!empty($v['fixed_in'])) { $line .= ' → fix ' . $v['fixed_in']; }
        if (!empty($v['cve']))      { $line .= ' · ' . $v['cve']; }
        $body .= $line . "\n";
    }

    $this->send($site, $subject, $body);
}
```
> NOTE: the digest renders findings in the order received; the caller (`VulnerabilityScanService`, Task 2) passes them severity-sorted. If `EmailNotifier` has no reusable `send`/`ownerEmail` (read the file), inline the same pattern the other methods use (`wp_mail($this->ownerEmail($site->userId), $subject, $body)` wrapped to swallow Throwable).

- [ ] **Step 5: Implement in `src/Notify/SlackNotifier.php`** (reuse its private `post(Site,$text)` helper):

```php
public function notifyNewVulnerabilities(Site $site, array $newVulnerabilities, array $severityCounts): void
{
    $count = count($newVulnerabilities);
    $noun  = $count === 1 ? 'vulnerability' : 'vulnerabilities';
    $text  = '🔒 *' . $count . ' new ' . $noun . '* on *' . $site->label . '* — ' . $site->url;
    foreach ($newVulnerabilities as $v) {
        $line = "\n• [" . $v['severity'] . '] ' . $v['component_name'] . ' (' . $v['type'] . ') ' . $v['installed_version'];
        if (!empty($v['fixed_in'])) { $line .= ' → fix ' . $v['fixed_in']; }
        if (!empty($v['cve']))      { $line .= ' · ' . $v['cve']; }
        $text .= $line;
    }
    $this->post($site, $text);
}
```

- [ ] **Step 6: Implement in `src/Notify/MultiNotifier.php`** (reuse its private `each(callable)`):

```php
public function notifyNewVulnerabilities(Site $site, array $newVulnerabilities, array $severityCounts): void
{
    $this->each(static fn (Notifier $n) => $n->notifyNewVulnerabilities($site, $newVulnerabilities, $severityCounts));
}
```

- [ ] **Step 7: Fix the compile-ripple in test doubles.** Run `composer test:integration 2>&1 | head -40`. Any test class that `implements Notifier` (search: `grep -rln "implements Notifier" tests`) now fatals — add a no-op `public function notifyNewVulnerabilities(Site $site, array $newVulnerabilities, array $severityCounts): void {}` (or, for a recording spy, capture the args) to each. Known suspects: `tests/Unit/Notify/MultiNotifierTest.php` (its inner spy), `tests/Integration/Services/IncidentServiceTest.php`, `tests/Integration/Services/SslAlertServiceTest.php`. Read each, add the method matching its style (recording spies record; plain stubs no-op).

- [ ] **Step 8: Add a SlackNotifier test** in `tests/Integration/Notify/SlackNotifierTest.php` (mirror its existing `pre_http_request` capture): `notifyNewVulnerabilities` posts a message whose body contains the count + each component + the `🔒` marker. And a `MultiNotifier` test in `tests/Unit/Notify/MultiNotifierTest.php`: calling `notifyNewVulnerabilities` invokes each inner notifier once (recording spy), and one inner throwing does not block the other (mirror how the existing MultiNotifier tests assert fan-out + isolation).

- [ ] **Step 9: Run green** — `composer test:integration -- --filter "EmailNotifier|SlackNotifier"` + `composer test:unit -- --filter MultiNotifier` → PASS. Then the full suite once to confirm the ripple is fixed: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit` → tolerate ONLY `UninstallTest`.

- [ ] **Step 10: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/src/Notify/ packages/dashboard-plugin/tests/Unit/Notify/ packages/dashboard-plugin/tests/Integration/Notify/ packages/dashboard-plugin/tests/Integration/Services/IncidentServiceTest.php packages/dashboard-plugin/tests/Integration/Services/SslAlertServiceTest.php
git commit -m "feat(p4-3a): notifyNewVulnerabilities on Notifier interface + Email/Slack/Multi + test-stub ripple"
```

---

## Task 2: `VulnerabilityScanService` — diff + mute-gated alert + activity event

**Files:**
- Modify: `src/Services/VulnerabilityScanService.php`
- Test: `tests/Integration/Services/VulnerabilityScanAlertTest.php` (new — keep the P4.1 `VulnerabilityScanServiceTest` separate)
- Modify (if needed): `tests/Integration/Services/VulnerabilityScanServiceTest.php` (inject a no-op notifier so the P4.1 test doesn't exercise the default `MultiNotifier`)

The diff is snapshot-vs-snapshot: load the prior findings BEFORE `replaceForSite`, fingerprint `(type|slug|source_id)`, and the new set is the current results whose fingerprint wasn't present before.

- [ ] **Step 1: Write the failing test** `tests/Integration/Services/VulnerabilityScanAlertTest.php`. It uses a local recording/throwing `Notifier` double + seeds a site + plugin inventory + vuln rows so `scan()` produces findings. (Read the P4.1 `VulnerabilityScanServiceTest` for the exact seed helpers — `SitePluginsRepository::replaceForSite` row shape, `VulnerabilitiesRepository::upsertForSource`, and the `$wpdb->insert` site seed.)

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Models\Site;
use Defyn\Dashboard\Models\Incident;
use Defyn\Dashboard\Notify\Notifier;
use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SiteVulnerabilitiesRepository;
use Defyn\Dashboard\Services\VulnerabilitiesRepository;
use Defyn\Dashboard\Services\VulnerabilityScanService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class RecordingNotifier implements Notifier
{
    /** @var list<array{site:Site,new:array,counts:array}> */
    public array $calls = [];
    public function notifyDown(Site $site, Incident $incident): void {}
    public function notifyRecovered(Site $site, Incident $incident): void {}
    public function notifySslExpiring(Site $site, string $expiresAtUtc, int $daysLeft): void {}
    public function notifyNewVulnerabilities(Site $site, array $newVulnerabilities, array $severityCounts): void
    {
        $this->calls[] = ['site' => $site, 'new' => $newVulnerabilities, 'counts' => $severityCounts];
    }
}

final class ThrowingNotifier implements Notifier
{
    public function notifyDown(Site $site, Incident $incident): void {}
    public function notifyRecovered(Site $site, Incident $incident): void {}
    public function notifySslExpiring(Site $site, string $expiresAtUtc, int $daysLeft): void {}
    public function notifyNewVulnerabilities(Site $site, array $newVulnerabilities, array $severityCounts): void
    {
        throw new \RuntimeException('boom');
    }
}

final class VulnerabilityScanAlertTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activation::ensureSchema();
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_site_vulnerabilities', 'defyn_site_plugins', 'defyn_sites', 'defyn_vulnerabilities', 'defyn_activity_log'] as $t) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}{$t}");
        }
        // phpcs:enable WordPress.DB.PreparedSQL
    }

    public function testAlertsOnlyOnNewFindingsNotPriorOnes(): void
    {
        $siteId = $this->seedSite(1, 'https://a.test', 'Acme', false);
        // prior snapshot: elementor already known
        (new SiteVulnerabilitiesRepository())->replaceForSite($siteId, [
            $this->finding('plugin', 'elementor', 'src-ele', 'high'),
        ], '2026-06-15 00:00:00');
        // inventory + feed: elementor (known) + wp-file-manager (NEW) both vulnerable
        (new SitePluginsRepository())->replaceForSite($siteId, [
            ['slug'=>'elementor','name'=>'Elementor','version'=>'3.0','update_available'=>false,'update_version'=>null,'tested_up_to'=>null],
            ['slug'=>'wp-file-manager','name'=>'WP File Manager','version'=>'6.0','update_available'=>false,'update_version'=>null,'tested_up_to'=>null],
        ], '2026-06-15 00:00:00');
        $this->seedVuln('plugin', 'elementor', 'src-ele', 'high');
        $this->seedVuln('plugin', 'wp-file-manager', 'src-wfm', 'critical');

        $spy = new RecordingNotifier();
        (new VulnerabilityScanService(notifier: $spy))->scan($siteId);

        self::assertCount(1, $spy->calls, 'one digest');
        $new = $spy->calls[0]['new'];
        self::assertCount(1, $new, 'only the NEW finding (wp-file-manager), not the prior elementor');
        self::assertSame('wp-file-manager', $new[0]['slug']);
        self::assertSame(1, $spy->calls[0]['counts']['critical']);
    }

    public function testMutedSiteSkipsSendButRecordsEvent(): void
    {
        $siteId = $this->seedSite(1, 'https://m.test', 'Muted', true); // alerts_muted = 1
        (new SitePluginsRepository())->replaceForSite($siteId, [
            ['slug'=>'wp-file-manager','name'=>'WP File Manager','version'=>'6.0','update_available'=>false,'update_version'=>null,'tested_up_to'=>null],
        ], '2026-06-15 00:00:00');
        $this->seedVuln('plugin', 'wp-file-manager', 'src-wfm', 'critical');

        $spy = new RecordingNotifier();
        (new VulnerabilityScanService(notifier: $spy))->scan($siteId);

        self::assertCount(0, $spy->calls, 'muted → no send');
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = %s", 'site.new_vulnerabilities'), ARRAY_A);
        self::assertNotNull($row, 'event recorded even when muted');
        $details = json_decode((string) $row['details'], true);
        self::assertFalse($details['alerted']);
        self::assertSame(1, $details['new_count']);
    }

    public function testNotifierThrowDoesNotBreakScan(): void
    {
        $siteId = $this->seedSite(1, 'https://t.test', 'Throws', false);
        (new SitePluginsRepository())->replaceForSite($siteId, [
            ['slug'=>'wp-file-manager','name'=>'WP File Manager','version'=>'6.0','update_available'=>false,'update_version'=>null,'tested_up_to'=>null],
        ], '2026-06-15 00:00:00');
        $this->seedVuln('plugin', 'wp-file-manager', 'src-wfm', 'critical');

        (new VulnerabilityScanService(notifier: new ThrowingNotifier()))->scan($siteId); // must NOT throw

        // scan still completed: snapshot replaced + scan time stamped
        $found = (new SiteVulnerabilitiesRepository())->findForSite($siteId);
        self::assertCount(1, $found);
    }

    public function testNoNewFindingsNoDigestNoEvent(): void
    {
        $siteId = $this->seedSite(1, 'https://n.test', 'NoNew', false);
        (new SiteVulnerabilitiesRepository())->replaceForSite($siteId, [
            $this->finding('plugin', 'wp-file-manager', 'src-wfm', 'critical'),
        ], '2026-06-15 00:00:00');
        (new SitePluginsRepository())->replaceForSite($siteId, [
            ['slug'=>'wp-file-manager','name'=>'WP File Manager','version'=>'6.0','update_available'=>false,'update_version'=>null,'tested_up_to'=>null],
        ], '2026-06-15 00:00:00');
        $this->seedVuln('plugin', 'wp-file-manager', 'src-wfm', 'critical');

        $spy = new RecordingNotifier();
        (new VulnerabilityScanService(notifier: $spy))->scan($siteId);

        self::assertCount(0, $spy->calls);
        global $wpdb;
        self::assertNull($wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = %s", 'site.new_vulnerabilities')));
    }

    // --- helpers ---
    private function finding(string $type, string $slug, string $sourceId, string $severity): array
    {
        return ['type'=>$type,'slug'=>$slug,'component_name'=>ucfirst($slug),'installed_version'=>'6.0',
                'severity'=>$severity,'cvss_score'=>null,'cve'=>null,'fixed_in'=>'9.9','title'=>'x','source_id'=>$sourceId];
    }
    private function seedVuln(string $type, string $slug, string $sourceId, string $severity): void
    {
        (new VulnerabilitiesRepository())->upsertForSource($sourceId, [[
            'type'=>$type,'slug'=>$slug,'title'=>'x','severity'=>$severity,'cvss_score'=>null,'cve'=>null,
            'from_version'=>null,'from_inclusive'=>true,'to_version'=>'9.9','to_inclusive'=>true,'fixed_in'=>'9.9',
            'updated_at'=>'2026-06-15 00:00:00',
        ]]);
    }
    private function seedSite(int $userId, string $url, string $label, bool $muted): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'defyn_sites', [
            'user_id'=>$userId,'url'=>$url,'label'=>$label,'status'=>'active','alerts_muted'=>$muted?1:0,
            'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }
}
```
> Verify the seed-row shapes against the real `SitePluginsRepository::replaceForSite` + `VulnerabilitiesRepository::upsertForSource` signatures (from the P4.1 `VulnerabilityScanServiceTest`) and adjust. The matcher needs the installed version to fall in `[from,to]` — `version '6.0'` with `to_version '9.9' inclusive` matches. Confirm `Site::fromRow` reads `alerts_muted` into `->alertsMuted` (P3.3 — it does). The two test-double class names (`RecordingNotifier`/`ThrowingNotifier`) must NOT collide with any added in Task 1's test files; they live in THIS test file's namespace block, so they're file-local — fine.

- [ ] **Step 2: Run red** — `composer test:integration -- --filter VulnerabilityScanAlertTest` → FAIL (`VulnerabilityScanService` has no `notifier` constructor param yet; no diff/event logic).

- [ ] **Step 3: Edit `src/Services/VulnerabilityScanService.php`.** Add the imports `use Defyn\Dashboard\Notify\MultiNotifier;` and `use Defyn\Dashboard\Notify\Notifier;`. Add the constructor param:

```php
public function __construct(
    private readonly ?SitesRepository $sites = null,
    private readonly ?SitePluginsRepository $plugins = null,
    private readonly ?ThemesRepository $themes = null,
    private readonly ?VulnerabilitiesRepository $vulns = null,
    private readonly ?SiteVulnerabilitiesRepository $findings = null,
    private readonly ?ActivityLogger $activity = null,
    private readonly ?Notifier $notifier = null,
) {}
```

In `scan()`, resolve the notifier near the other deps:
```php
$notifier = $this->notifier ?? new MultiNotifier();
```
**Before** `$findings->replaceForSite(...)`, capture the prior fingerprints:
```php
$priorFingerprints = [];
foreach ($findings->findForSite($siteId) as $prior) {
    $priorFingerprints[$prior->type . '|' . $prior->slug . '|' . $prior->sourceId] = true;
}
```
After `$results` is fully built (it already is, just before `replaceForSite`), compute the new set + its severity counts:
```php
$newFindings = [];
$newCounts   = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
foreach ($results as $r) {
    $fp = $r['type'] . '|' . $r['slug'] . '|' . $r['source_id'];
    if (!isset($priorFingerprints[$fp])) {
        $newFindings[] = $r;
        if (isset($newCounts[$r['severity']])) {
            $newCounts[$r['severity']]++;
        }
    }
}
```
Keep `replaceForSite` + `markSecurityScannedAt` + the existing `site.vulnerabilities_detected` emit exactly as-is. Then, as the LAST block of `scan()`:
```php
if ($newFindings !== []) {
    // Sort the digest severity-desc for readable output.
    $rank = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1, 'unknown' => 0];
    usort($newFindings, static fn (array $a, array $b): int => ($rank[$b['severity']] ?? 0) <=> ($rank[$a['severity']] ?? 0));

    $alerted = false;
    if (!$site->alertsMuted) {
        try {
            $notifier->notifyNewVulnerabilities($site, $newFindings, $newCounts);
            $alerted = true;
        } catch (\Throwable $e) {
            error_log('[defyn] new-vuln notify failed: ' . $e->getMessage());
        }
    }

    $activity->log($site->userId, $siteId, 'site.new_vulnerabilities', array_merge(
        ['new_count' => count($newFindings), 'alerted' => $alerted],
        $newCounts
    ));
}
```
> The notify is the LAST step + fully try/caught — `replaceForSite`/`markSecurityScannedAt`/`vulnerabilities_detected` already ran, so a throwing notifier cannot break the scan (guardrail #2). The event records even when muted (`alerted:false`), guardrail #4.

- [ ] **Step 4: Keep the P4.1 test deterministic.** Open `tests/Integration/Services/VulnerabilityScanServiceTest.php`; for each `new VulnerabilityScanService(...)` construction, pass a no-op notifier so the P4.1 test does not exercise the real `MultiNotifier`/`wp_mail` path. Add a tiny file-local no-op `Notifier` class in that test file (4 empty methods) and pass `notifier: new <ThatClass>()` (named arg). Re-run `composer test:integration -- --filter VulnerabilityScanServiceTest` → still green. (If the P4.1 test was already green WITH the default MultiNotifier because `wp_mail` is harmlessly intercepted by wp-phpunit, this step is belt-and-suspenders but keeps the test deterministic and independent of mail interception.)

- [ ] **Step 5: Run green** — `composer test:integration -- --filter "VulnerabilityScanAlertTest|VulnerabilityScanServiceTest"` → all PASS. Then the FULL suite: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit` → tolerate ONLY `UninstallTest`.

- [ ] **Step 6: Commit**

```bash
git add packages/dashboard-plugin/src/Services/VulnerabilityScanService.php packages/dashboard-plugin/tests/Integration/Services/VulnerabilityScanAlertTest.php packages/dashboard-plugin/tests/Integration/Services/VulnerabilityScanServiceTest.php
git commit -m "feat(p4-3a): scan diffs new findings + mute-gated best-effort alert + site.new_vulnerabilities event"
```

---

## Task 3: Version bump v0.15.0

**Files:**
- Modify: `packages/dashboard-plugin/defyn-dashboard.php`

- [ ] **Step 1: Bump both version lines** in `defyn-dashboard.php`: the header `* Version:           0.15.0` and `define('DEFYN_DASHBOARD_VERSION', '0.15.0');`. Verify: `php -l defyn-dashboard.php` → "No syntax errors detected".

- [ ] **Step 2: Run the FULL PHP suite** once more to confirm green: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit` → tolerate ONLY `UninstallTest`. **No schema-version-pin changes** (schema stays v11).

- [ ] **Step 3: Commit**

```bash
git add packages/dashboard-plugin/defyn-dashboard.php
git commit -m "chore(p4-3a): bump dashboard to v0.15.0"
```

---

## Task 4: Release — build, ship, smoke, tag, MEMORY

**Files:** build artifact only (`dist/defyn-dashboard-0.15.0.zip`).

> **No SPA change in P4.3a** — the SPA bundle is unchanged, so no `pnpm build`/Cloudflare deploy is required (merging to main is still fine; Cloudflare rebuilds from unchanged source harmlessly).

- [ ] **Step 1: Confirm the full PHP suite is green** (from Task 3): `cd packages/dashboard-plugin && COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit` → only `UninstallTest`.

- [ ] **Step 2: Build the dashboard zip** (symfony + json-machine preserving — exclude ONLY tests/dev tooling, NEVER `vendor/*`; top-level folder `dashboard-plugin/`; output to repo-root `dist/`):
```bash
cd packages/dashboard-plugin
composer install --no-dev --classmap-authoritative
cd ..
rm -f ../dist/defyn-dashboard-0.15.0.zip && mkdir -p ../dist
zip -rq ../dist/defyn-dashboard-0.15.0.zip dashboard-plugin \
  -x 'dashboard-plugin/tests/*' '*wp-tests-config.php' 'dashboard-plugin/.phpunit.result.cache' \
     'dashboard-plugin/test-output.log' 'dashboard-plugin/phpunit.xml' 'dashboard-plugin/composer.lock' \
     'dashboard-plugin/.github/*' 'dashboard-plugin/.gitignore'
cd ..
# VERIFY (symfony MUST print 2; json-machine MUST print >=1):
unzip -l dist/defyn-dashboard-0.15.0.zip | grep -cE "deprecation-contracts/function\.php|polyfill-php83/bootstrap\.php"
unzip -l dist/defyn-dashboard-0.15.0.zip | grep -c "json-machine/src/Items\.php"
unzip -p dist/defyn-dashboard-0.15.0.zip dashboard-plugin/defyn-dashboard.php | grep -m1 DEFYN_DASHBOARD_VERSION
cd packages/dashboard-plugin && composer install   # restore dev autoload
```

- [ ] **Step 3: Merge to main + push:**
```bash
cd "/Users/pradeep/Local Sites/defynWP"
git checkout main && git merge --ff-only p4-3a-security-alerting && git push origin main
```

- [ ] **Step 4: Kinsta install (MANUAL USER STEP — flag it).** Operator uploads `dist/defyn-dashboard-0.15.0.zip` via WP Admin → Plugins → "Replace current with uploaded version" on `defynwp.defyn.agency`, then clears the MyKinsta cache. **No schema migration** (stays v11). (The `DEFYN_WORDFENCE_API_KEY` from P4.1 is unchanged.)

- [ ] **Step 5: Production smoke (API curl only; login JWT field is `access_token`).** Alerting is server-side (driven by the scan job), so the smoke is **indirect** — confirm the new code didn't break the existing security surface. After the user confirms install:
  - `POST /auth/login` → `access_token`.
  - `GET /security` (auth) → **200** (fleet endpoint still works).
  - `GET /sites/{ownedId}/vulnerabilities` (auth) → **200**; no-auth → **401**.
  - `POST /sites/999999/security/scan` (auth) → **404** `sites.not_found` (the scan route still registered + the modified `scan` path didn't break controller wiring).
  - (The happy alert path — a real new-finding email/Slack — is foreclosed by the zero-sites + no-API-key prod state; covered by the green PHP tests. End-to-end alerting becomes verifiable only once the operator sets `DEFYN_WORDFENCE_API_KEY` and has sites.)

- [ ] **Step 6: Tag + push:**
```bash
git tag p4-3a-security-alerting-complete && git push origin p4-3a-security-alerting-complete
```

- [ ] **Step 7: Update MEMORY** — append a P4.3a-complete entry to `project_defyn_roadmap.md` (v0.15.0, tag, schema unchanged v11, the snapshot-vs-snapshot fire-once diff, mute reuses alerts_muted, the `site.new_vulnerabilities` event, smoke results, that end-to-end alerting is gated on the operator's API key + sites) + refresh the `MEMORY.md` index line. Set NEXT = **P4.3b** (per-finding dismiss/ignore + read-exclusion + dismiss UI, schema v12) — completes Phase 4; then Reporting.

---

## Self-Review (completed during planning)

- **Spec coverage:** §4 diff → Task 2; §5 notifier method → Task 1; §6 scan changes → Task 2; §7 activity event → Task 2; §9 testing → folded into Tasks 1–2; §10 release → Tasks 3–4. ✅
- **Type consistency:** `notifyNewVulnerabilities(Site, list<array{...}>, array{critical,high,medium,low})` is identical in the interface (Task 1), all three impls (Task 1), the scan-service call site (Task 2), and the test doubles (Task 2). The finding array shape (`type,slug,component_name,installed_version,severity,cve,fixed_in` + `source_id` used only for the fingerprint) matches the `$results` rows built in `scan`. The activity `details` shape `{new_count,critical,high,medium,low,alerted}` is consistent between Task 2's impl + tests. ✅
- **Compile-ripple flagged:** Task 1 Step 7 explicitly fixes every `implements Notifier` test double (the interface's 4th method). ✅
- **No schema/connector/endpoint change:** no task touches `Activation`/schema/version-pin/connector/`RestRouter`. Guardrail #1 holds. ✅
- **Best-effort + mute:** Task 2's notify is the last, fully-try/caught step; the event records regardless of mute with the `alerted` flag. Guardrails #2, #4. ✅
- **No SPA work:** confirmed during planning — no central event-label map exists, so the new activity event surfaces generically; P4.3a is dashboard-plugin-only. ✅
