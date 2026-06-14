# P3.3 — Monitoring: Alerting Expansion & Config Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Slack alert channel + proactive SSL-expiry alerts + operator/per-site config (Slack webhook + per-site mute) to complete the Monitoring phase.

**Architecture:** Slack is a 2nd `Notify\Notifier` impl behind a `MultiNotifier` composite (email always + Slack when the owner has a webhook). SSL alerts run on a new daily fan-out job. The Slack webhook is per-operator `user_meta`; per-site mute + an SSL de-dup stamp are two new `wp_defyn_sites` columns. A new `/settings` SPA page hosts the webhook; a per-site mute toggle mirrors the allow-major toggle. Connector untouched.

**Tech Stack:** PHP 8.1 dashboard plugin (PHPUnit/wp-phpunit, `$wpdb`, Action Scheduler), React 18 + TS + TanStack Query v5 + Zod + Vitest + MSW + Tailwind + shadcn (pnpm, Node 22 via `apps/web/.nvmrc`).

**Spec:** `docs/superpowers/specs/2026-06-14-p3-3-monitoring-alerting-design.md` (commit `8046733`). **Branch:** `p3-3-monitoring-alerting` (off `main` @ the P3.2 merge). Schema **v9 → v10**. Dashboard **v0.11.0 → v0.12.0**. Connector **unchanged**.

**Carry-forward tolerated:** SPA SiteDetail×2 + SiteCoreCard×2; PHP `UninstallTest::testUninstallDropsAllTables` (infra). Full SPA route suite green under Node 22 (render-loop lesson).

**Subagents running SPA tests:** `export FNM_DIR="$HOME/.fnm"; eval "$(fnm env --shell bash)"; fnm use 22` first. Tests live under `apps/web/tests/` (NOT `src/.../__tests__/`). `pkill -9 -f vitest` between runs; no `timeout` on macOS.

---

## Task 1: Schema v10 — `alerts_muted` + `ssl_alert_sent_at` columns

**Files:**
- Modify: `packages/dashboard-plugin/src/Activation.php` (SCHEMA_VERSION 9→10; two guarded ALTER helpers near the existing `addResponseTimeColumn`; two calls in `ensureSchema()`)
- Create: `packages/dashboard-plugin/tests/Integration/Schema/AlertConfigColumnsTest.php`
- Modify (pin 9→10): `tests/Integration/Schema/SchemaVersionMigrationV4Test.php`, `V5Test.php`, `V6Test.php`, `V7Test.php`, `IncidentsSchemaTest.php`, `ResponseTimeColumnTest.php`

- [ ] **Step 1: Write the failing test** — `AlertConfigColumnsTest.php` (mirror `ResponseTimeColumnTest.php` exactly, including its `AbstractSchemaTestCase` + `freshlyActivate('defyn_sites')` + `Activation::ensureSchema()` bootstrap):

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/** P3.3 — schema v10: alerts_muted + ssl_alert_sent_at on wp_defyn_sites. @group integration */
final class AlertConfigColumnsTest extends AbstractSchemaTestCase
{
    public function testSchemaVersionConstantIsTen(): void
    {
        self::assertSame(10, Activation::SCHEMA_VERSION);
    }

    public function testAlertConfigColumnsExistAfterEnsureSchema(): void
    {
        $this->freshlyActivate('defyn_sites');
        Activation::ensureSchema();

        global $wpdb;
        $table = SitesTable::tableName();
        self::assertSame('alerts_muted', $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'alerts_muted')));
        self::assertSame('ssl_alert_sent_at', $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'ssl_alert_sent_at')));
    }

    public function testGuardedAltersAreIdempotent(): void
    {
        $this->freshlyActivate('defyn_sites');
        Activation::ensureSchema();
        Activation::ensureSchema();

        global $wpdb;
        $table = SitesTable::tableName();
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_NAME = %s AND COLUMN_NAME IN ('alerts_muted','ssl_alert_sent_at')",
            $table
        ));
        self::assertSame('2', (string) $count);
    }
}
```

- [ ] **Step 2: Run it — verify it fails.** `cd packages/dashboard-plugin && composer test -- --filter AlertConfigColumnsTest` → FAIL.

- [ ] **Step 3: Bump version + add two guarded ALTERs.** In `src/Activation.php` line 27: `public const SCHEMA_VERSION = 10;`. Add (near `addResponseTimeColumn`):

```php
    private static function addAlertsMutedColumn(\wpdb $wpdb): void
    {
        $table  = SitesTable::tableName();
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'alerts_muted'));
        if ($exists !== null) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL — column DDL cannot be parameterized.
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN alerts_muted TINYINT NOT NULL DEFAULT 0");
    }

    private static function addSslAlertSentAtColumn(\wpdb $wpdb): void
    {
        $table  = SitesTable::tableName();
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'ssl_alert_sent_at'));
        if ($exists !== null) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL — column DDL cannot be parameterized.
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN ssl_alert_sent_at DATETIME NULL");
    }
```

Call both in `ensureSchema()` after `addResponseTimeColumn($wpdb)`:
```php
        // P3.3 — per-site mute + SSL-alert de-dup stamp. Guarded ALTERs.
        self::addAlertsMutedColumn($wpdb);
        self::addSslAlertSentAtColumn($wpdb);
```

- [ ] **Step 4: Update the 6 version-pin assertions 9 → 10** in `SchemaVersionMigrationV4Test.php`, `V5Test.php`, `V6Test.php`, `V7Test.php`, `IncidentsSchemaTest.php`, `ResponseTimeColumnTest.php` (grep each for `SCHEMA_VERSION`; change `assertSame(9,` → `assertSame(10,`).

- [ ] **Step 5: Run — verify green.** `composer test -- --filter "AlertConfigColumnsTest|SchemaVersionMigration|IncidentsSchema|ResponseTimeColumn"` → PASS.

- [ ] **Step 6: Commit.**
```bash
git add packages/dashboard-plugin/src/Activation.php packages/dashboard-plugin/tests/Integration/Schema/
git commit -m "feat(p3-3): schema v10 — alerts_muted + ssl_alert_sent_at columns"
```

---

## Task 2: `Site` DTO — `alertsMuted` + `sslAlertSentAt`

**Files:**
- Modify: `packages/dashboard-plugin/src/Models/Site.php` (2 final constructor params + `fromRow` + `toJson`)
- Test: `packages/dashboard-plugin/tests/Unit/Models/SiteTest.php`

- [ ] **Step 1: Write the failing test:**
```php
public function testFromRowMapsAlertConfigFields(): void
{
    $site = \Defyn\Dashboard\Models\Site::fromRow([
        'id' => 1, 'user_id' => 1, 'url' => 'https://a.test', 'label' => 'A',
        'status' => 'active', 'created_at' => '2026-06-14 00:00:00',
        'alerts_muted' => '1', 'ssl_alert_sent_at' => '2026-06-14 02:00:00',
    ]);
    self::assertTrue($site->alertsMuted);
    self::assertSame('2026-06-14 02:00:00', $site->sslAlertSentAt);
}

public function testFromRowDefaultsAlertConfig(): void
{
    $site = \Defyn\Dashboard\Models\Site::fromRow([
        'id' => 1, 'user_id' => 1, 'url' => 'https://a.test', 'label' => 'A',
        'status' => 'active', 'created_at' => '2026-06-14 00:00:00',
    ]);
    self::assertFalse($site->alertsMuted);
    self::assertNull($site->sslAlertSentAt);
}

public function testToJsonExposesAlertsMuted(): void
{
    $site = \Defyn\Dashboard\Models\Site::fromRow([
        'id' => 1, 'user_id' => 1, 'url' => 'https://a.test', 'label' => 'A',
        'status' => 'active', 'created_at' => '2026-06-14 00:00:00', 'alerts_muted' => '1',
    ]);
    self::assertArrayHasKey('alerts_muted', $site->toJson());
    self::assertTrue($site->toJson()['alerts_muted']);
}
```

- [ ] **Step 2: Run it — verify it fails.** `composer test -- --filter SiteTest` → FAIL.

- [ ] **Step 3: Add fields + mappings.** In `src/Models/Site.php`, add as the **final** constructor params (after `lastResponseTimeMs`):
```php
        // P3.3 — per-site alert mute + SSL-expiry de-dup stamp (internal).
        public readonly bool    $alertsMuted = false,
        public readonly ?string $sslAlertSentAt = null,
```
In `fromRow`, add after `lastResponseTimeMs:`:
```php
        alertsMuted:       (bool) (int) ($row['alerts_muted'] ?? 0),
        sslAlertSentAt:    isset($row['ssl_alert_sent_at']) ? (string) $row['ssl_alert_sent_at'] : null,
```
In `toJson`, add to the returned array (next to the other persisted fields; `ssl_alert_sent_at` stays internal — do NOT add it):
```php
            'alerts_muted' => $this->alertsMuted,
```

- [ ] **Step 4: Run it — verify it passes.** `composer test -- --filter SiteTest` → PASS.

- [ ] **Step 5: Commit.**
```bash
git add packages/dashboard-plugin/src/Models/Site.php packages/dashboard-plugin/tests/Unit/Models/SiteTest.php
git commit -m "feat(p3-3): Site DTO carries alertsMuted + sslAlertSentAt"
```

---

## Task 3: `SitesRepository` — mute + SSL-stamp setters

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/SitesRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryTest.php`

- [ ] **Step 1: Write the failing test:**
```php
public function testSetAlertsMutedAndSslStampHelpers(): void
{
    $repo = new SitesRepository();
    $id = $repo->insertPending(userId: 1, url: 'https://m.test', label: 'M', ourPublicKey: 'pk', ourPrivateKeyEncrypted: 'enc');

    $repo->setAlertsMuted($id, true);
    self::assertTrue($repo->findById($id)->alertsMuted);
    $repo->setAlertsMuted($id, false);
    self::assertFalse($repo->findById($id)->alertsMuted);

    $repo->markSslAlertSent($id, '2026-06-14 02:00:00');
    self::assertSame('2026-06-14 02:00:00', $repo->findById($id)->sslAlertSentAt);
    $repo->clearSslAlertSent($id);
    self::assertNull($repo->findById($id)->sslAlertSentAt);
}
```

- [ ] **Step 2: Run it — verify it fails.** `composer test -- --filter "SitesRepositoryTest::testSetAlertsMutedAndSslStampHelpers"` → FAIL.

- [ ] **Step 3: Implement** (mirror `setCoreAllowMajor`; `clearSslAlertSent` uses an explicit `= NULL` prepared query like `recordResponseTime`'s null branch):
```php
    public function setAlertsMuted(int $siteId, bool $muted): void
    {
        global $wpdb;
        $wpdb->update(SitesTable::tableName(), ['alerts_muted' => $muted ? 1 : 0], ['id' => $siteId], ['%d'], ['%d']);
    }

    public function markSslAlertSent(int $siteId, string $nowUtc): void
    {
        global $wpdb;
        $wpdb->update(SitesTable::tableName(), ['ssl_alert_sent_at' => $nowUtc], ['id' => $siteId], ['%s'], ['%d']);
    }

    public function clearSslAlertSent(int $siteId): void
    {
        global $wpdb;
        $table = SitesTable::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL — table name is a constant.
        $wpdb->query($wpdb->prepare("UPDATE `{$table}` SET ssl_alert_sent_at = NULL WHERE id = %d", $siteId));
    }
```

- [ ] **Step 4: Run it — verify it passes.** `composer test -- --filter "SitesRepositoryTest::testSetAlertsMutedAndSslStampHelpers"` → PASS.

- [ ] **Step 5: Commit.**
```bash
git add packages/dashboard-plugin/src/Services/SitesRepository.php packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryTest.php
git commit -m "feat(p3-3): SitesRepository mute + SSL-stamp setters"
```

---

## Task 4: `Notifier` interface + `EmailNotifier::notifySslExpiring`

**Files:**
- Modify: `packages/dashboard-plugin/src/Notify/Notifier.php` (add 3rd method)
- Modify: `packages/dashboard-plugin/src/Notify/EmailNotifier.php` (implement it)
- Test: `packages/dashboard-plugin/tests/Unit/Notify/EmailNotifierTest.php` (add a method; if the file doesn't exist, create it mirroring the existing notifier test bootstrap — find how `wp_mail` is mocked, likely a `MockFunctions`/filter helper or a `wp_mail` capture)

- [ ] **Step 1: Write the failing test** — assert `EmailNotifier` implements `notifySslExpiring` and composes a subject containing the day count. (Mirror the existing EmailNotifier test's `wp_mail` capture mechanism — read it first; if no test exists, capture via the `wp_mail` short-circuit filter `pre_wp_mail` or a global spy the harness already uses.)
```php
public function testNotifySslExpiringSendsToOwner(): void
{
    // Arrange: a captured-wp_mail harness (mirror existing EmailNotifier test setup).
    $site = $this->makeSiteOwnedBy(self::USER_ID); // helper from existing test, or build a Site
    (new EmailNotifier())->notifySslExpiring($site, '2026-07-01 00:00:00', 14);
    // Assert the last captured mail subject mentions SSL + 14, body has the URL.
    self::assertStringContainsString('SSL', $this->lastMail['subject']);
    self::assertStringContainsString('14', $this->lastMail['subject']);
}
```

- [ ] **Step 2: Run it — verify it fails.** `composer test -- --filter EmailNotifierTest` → FAIL (method undefined).

- [ ] **Step 3: Add the interface method + implement.** In `src/Notify/Notifier.php`:
```php
    public function notifySslExpiring(Site $site, string $expiresAtUtc, int $daysLeft): void;
```
In `src/Notify/EmailNotifier.php` (reuses the private `send`):
```php
    public function notifySslExpiring(Site $site, string $expiresAtUtc, int $daysLeft): void
    {
        $this->send(
            $site,
            '⚠️ ' . $site->label . ' SSL expires in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's'),
            "The SSL certificate for {$site->label} ({$site->url}) expires on {$expiresAtUtc} UTC "
            . "({$daysLeft} day" . ($daysLeft === 1 ? '' : 's') . " from now).\n\nRenew it before it lapses.\n"
        );
    }
```

- [ ] **Step 4: Run it — verify it passes.** `composer test -- --filter EmailNotifierTest` → PASS.

- [ ] **Step 5: Commit.**
```bash
git add packages/dashboard-plugin/src/Notify/Notifier.php packages/dashboard-plugin/src/Notify/EmailNotifier.php packages/dashboard-plugin/tests/Unit/Notify/EmailNotifierTest.php
git commit -m "feat(p3-3): Notifier gains notifySslExpiring + EmailNotifier impl"
```

---

## Task 5: `Notify\SlackNotifier`

**Guardrails 2, 3, 7, 8:** best-effort (never throws); no-op on empty webhook; resolves the **site owner's** user_meta `defyn_slack_webhook_url`.

**Files:**
- Create: `packages/dashboard-plugin/src/Notify/SlackNotifier.php`
- Create: `packages/dashboard-plugin/tests/Integration/Notify/SlackNotifierTest.php` (integration — uses `update_user_meta` + the `pre_http_request` filter to capture/short-circuit `wp_remote_post`)

- [ ] **Step 1: Write the failing test:**
```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Notify;

use Defyn\Dashboard\Models\Incident;
use Defyn\Dashboard\Models\Site;
use Defyn\Dashboard\Notify\SlackNotifier;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/** @group integration */
final class SlackNotifierTest extends AbstractSchemaTestCase
{
    private array $captured = [];

    private function site(int $userId): Site
    {
        return Site::fromRow([
            'id' => 7, 'user_id' => $userId, 'url' => 'https://s.test', 'label' => 'S',
            'status' => 'offline', 'created_at' => '2026-06-14 00:00:00',
        ]);
    }

    private function incident(): Incident
    {
        return new Incident(1, 7, '2026-06-14 01:00:00', null, null, 'boom', null, null, '2026-06-14 01:00:00');
    }

    public function testPostsToOwnerWebhook(): void
    {
        $uid = self::factory()->user->create();
        update_user_meta($uid, 'defyn_slack_webhook_url', 'https://hooks.slack.com/services/T/B/x');

        add_filter('pre_http_request', function ($pre, $args, $url) {
            $this->captured[] = ['url' => $url, 'body' => $args['body'] ?? ''];
            return ['response' => ['code' => 200], 'body' => 'ok'];
        }, 10, 3);

        (new SlackNotifier())->notifyDown($this->site($uid), $this->incident());

        self::assertCount(1, $this->captured);
        self::assertSame('https://hooks.slack.com/services/T/B/x', $this->captured[0]['url']);
        self::assertStringContainsString('down', strtolower($this->captured[0]['body']));
    }

    public function testNoOpWhenWebhookEmpty(): void
    {
        $uid = self::factory()->user->create();
        add_filter('pre_http_request', function ($pre, $args, $url) {
            $this->captured[] = $url;
            return ['response' => ['code' => 200], 'body' => 'ok'];
        }, 10, 3);

        (new SlackNotifier())->notifyDown($this->site($uid), $this->incident());

        self::assertCount(0, $this->captured); // no webhook → no HTTP call
    }

    public function testBestEffortSwallowsFailure(): void
    {
        $uid = self::factory()->user->create();
        update_user_meta($uid, 'defyn_slack_webhook_url', 'https://hooks.slack.com/services/T/B/x');
        add_filter('pre_http_request', fn () => new \WP_Error('http', 'boom'), 10, 3);

        // Must not throw.
        (new SlackNotifier())->notifyRecovered($this->site($uid), $this->incident());
        (new SlackNotifier())->notifySslExpiring($this->site($uid), '2026-07-01 00:00:00', 14);
        self::assertTrue(true);
    }
}
```

- [ ] **Step 2: Run it — verify it fails.** `composer test -- --filter SlackNotifierTest` → FAIL (class missing).

- [ ] **Step 3: Implement:**
```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Notify;

use Defyn\Dashboard\Models\Incident;
use Defyn\Dashboard\Models\Site;
use Throwable;

/**
 * P3.3 — posts monitoring alerts to the site OWNER's Slack incoming webhook
 * (per-operator user_meta `defyn_slack_webhook_url`). No-op when unset.
 * Best-effort: transport / non-2xx failures are logged, never thrown
 * (guardrail 2). Mirrors EmailNotifier's owner-resolution + best-effort shape.
 */
final class SlackNotifier implements Notifier
{
    public function notifyDown(Site $site, Incident $incident): void
    {
        $this->post($site, '🔴 *' . $site->label . '* is down — ' . $site->url
            . "\nDown since {$incident->startedAt} UTC. Error: " . ($incident->lastError ?? 'unknown'));
    }

    public function notifyRecovered(Site $site, Incident $incident): void
    {
        $this->post($site, '✅ *' . $site->label . '* recovered — ' . $site->url
            . "\nDown from {$incident->startedAt} to " . ($incident->endedAt ?? '?') . ' UTC.');
    }

    public function notifySslExpiring(Site $site, string $expiresAtUtc, int $daysLeft): void
    {
        $this->post($site, '⚠️ *' . $site->label . "* SSL expires in {$daysLeft} day"
            . ($daysLeft === 1 ? '' : 's') . ' — ' . $site->url . "\nExpires {$expiresAtUtc} UTC.");
    }

    private function post(Site $site, string $text): void
    {
        $webhook = (string) get_user_meta($site->userId, 'defyn_slack_webhook_url', true);
        if ($webhook === '') {
            return; // guardrail 3 — no webhook configured
        }
        try {
            $res = wp_remote_post($webhook, [
                'timeout' => 5,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => wp_json_encode(['text' => $text]),
            ]);
            if (is_wp_error($res)) {
                error_log('[defyn] SlackNotifier failed: ' . $res->get_error_message());
                return;
            }
            $code = (int) wp_remote_retrieve_response_code($res);
            if ($code < 200 || $code >= 300) {
                error_log('[defyn] SlackNotifier non-2xx: ' . $code);
            }
        } catch (Throwable $e) {
            error_log('[defyn] SlackNotifier threw: ' . $e->getMessage());
        }
    }
}
```

- [ ] **Step 4: Run it — verify it passes.** `composer test -- --filter SlackNotifierTest` → PASS.

- [ ] **Step 5: Commit.**
```bash
git add packages/dashboard-plugin/src/Notify/SlackNotifier.php packages/dashboard-plugin/tests/Integration/Notify/SlackNotifierTest.php
git commit -m "feat(p3-3): SlackNotifier (owner webhook, no-op empty, best-effort)"
```

---

## Task 6: `Notify\MultiNotifier` composite

**Files:**
- Create: `packages/dashboard-plugin/src/Notify/MultiNotifier.php`
- Create: `packages/dashboard-plugin/tests/Unit/Notify/MultiNotifierTest.php`

- [ ] **Step 1: Write the failing test** (uses inline anonymous `Notifier`s — one that records calls, one that throws — to prove fan-out + isolation):
```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Notify;

use Defyn\Dashboard\Models\Incident;
use Defyn\Dashboard\Models\Site;
use Defyn\Dashboard\Notify\MultiNotifier;
use Defyn\Dashboard\Notify\Notifier;
use PHPUnit\Framework\TestCase;

final class MultiNotifierTest extends TestCase
{
    private function site(): Site
    {
        return Site::fromRow(['id' => 1, 'user_id' => 1, 'url' => 'https://a.test', 'label' => 'A', 'status' => 'offline', 'created_at' => '2026-06-14 00:00:00']);
    }
    private function incident(): Incident
    {
        return new Incident(1, 1, '2026-06-14 00:00:00', null, null, 'x', null, null, '2026-06-14 00:00:00');
    }

    public function testFansOutToAllAndIsolatesThrow(): void
    {
        $calls = [];
        $throwing = new class implements Notifier {
            public function notifyDown(Site $s, Incident $i): void { throw new \RuntimeException('boom'); }
            public function notifyRecovered(Site $s, Incident $i): void { throw new \RuntimeException('boom'); }
            public function notifySslExpiring(Site $s, string $e, int $d): void { throw new \RuntimeException('boom'); }
        };
        $recording = new class($calls) implements Notifier {
            public function __construct(public array &$calls) {}
            public function notifyDown(Site $s, Incident $i): void { $this->calls[] = 'down'; }
            public function notifyRecovered(Site $s, Incident $i): void { $this->calls[] = 'recovered'; }
            public function notifySslExpiring(Site $s, string $e, int $d): void { $this->calls[] = 'ssl'; }
        };

        $multi = new MultiNotifier([$throwing, $recording]);
        $multi->notifyDown($this->site(), $this->incident());          // throwing first must not block recording
        $multi->notifyRecovered($this->site(), $this->incident());
        $multi->notifySslExpiring($this->site(), '2026-07-01 00:00:00', 14);

        self::assertSame(['down', 'recovered', 'ssl'], $recording->calls);
    }
}
```

- [ ] **Step 2: Run it — verify it fails.** `composer test -- --filter MultiNotifierTest` → FAIL.

- [ ] **Step 3: Implement:**
```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Notify;

use Defyn\Dashboard\Models\Incident;
use Defyn\Dashboard\Models\Site;
use Throwable;

/**
 * P3.3 — fans out each alert to every inner notifier (email always + Slack).
 * Each call is isolated: one channel throwing never blocks the others
 * (defence in depth; individual notifiers are already best-effort).
 */
final class MultiNotifier implements Notifier
{
    /** @var list<Notifier> */
    private array $notifiers;

    /** @param list<Notifier>|null $notifiers */
    public function __construct(?array $notifiers = null)
    {
        $this->notifiers = $notifiers ?? [new EmailNotifier(), new SlackNotifier()];
    }

    public function notifyDown(Site $site, Incident $incident): void
    {
        $this->each(static fn (Notifier $n) => $n->notifyDown($site, $incident));
    }

    public function notifyRecovered(Site $site, Incident $incident): void
    {
        $this->each(static fn (Notifier $n) => $n->notifyRecovered($site, $incident));
    }

    public function notifySslExpiring(Site $site, string $expiresAtUtc, int $daysLeft): void
    {
        $this->each(static fn (Notifier $n) => $n->notifySslExpiring($site, $expiresAtUtc, $daysLeft));
    }

    private function each(callable $fn): void
    {
        foreach ($this->notifiers as $n) {
            try {
                $fn($n);
            } catch (Throwable $e) {
                error_log('[defyn] MultiNotifier channel failed: ' . $e->getMessage());
            }
        }
    }
}
```

- [ ] **Step 4: Run it — verify it passes.** `composer test -- --filter MultiNotifierTest` → PASS.

- [ ] **Step 5: Commit.**
```bash
git add packages/dashboard-plugin/src/Notify/MultiNotifier.php packages/dashboard-plugin/tests/Unit/Notify/MultiNotifierTest.php
git commit -m "feat(p3-3): MultiNotifier composite (email + Slack fan-out)"
```

---

## Task 7: `IncidentService` — default notifier + mute gate

**Guardrails 1, 4:** swap default notifier to `MultiNotifier`; muted site still records the incident + events but skips the send. Existing P3.1 `IncidentService`/`HealthService` tests MUST stay green.

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/IncidentService.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/IncidentServiceTest.php` (add a mute test; mirror the existing test's spy-notifier + seeding)

- [ ] **Step 1: Write the failing test** (mirror the existing IncidentService test's setup; the notifier is injectable as the 3rd constructor arg — pass a recording spy `Notifier`; seed a muted site):
```php
public function testMutedSiteRecordsIncidentButDoesNotNotify(): void
{
    $sites = new SitesRepository();
    $id = $sites->insertPending(userId: 1, url: 'https://m.test', label: 'M', ourPublicKey: 'pk', ourPrivateKeyEncrypted: 'enc');
    $sites->markActive($id, 'pk');
    $sites->setAlertsMuted($id, true);
    $site = $sites->findById($id);

    $sent = [];
    $spy = new class($sent) implements \Defyn\Dashboard\Notify\Notifier {
        public function __construct(public array &$sent) {}
        public function notifyDown($s, $i): void { $this->sent[] = 'down'; }
        public function notifyRecovered($s, $i): void { $this->sent[] = 'recovered'; }
        public function notifySslExpiring($s, $e, $d): void { $this->sent[] = 'ssl'; }
    };

    $svc = new IncidentService(notifier: $spy);
    $svc->recordFailure($site, 'boom');   // 1st failure — below threshold
    $svc->recordFailure($sites->findById($id), 'boom'); // 2nd — opens incident

    // Incident WAS opened (history kept) but NO notification fired (muted).
    self::assertNotNull((new IncidentsRepository())->findOpenForSite($id));
    self::assertSame([], $spy->sent);
}
```
(Note `IncidentService`'s constructor is `(?IncidentsRepository, ?SitesRepository, ?Notifier, ?ActivityLogger)` — pass the spy via named arg `notifier:`.)

- [ ] **Step 2: Run it — verify it fails.** `composer test -- --filter "IncidentServiceTest::testMutedSiteRecordsIncidentButDoesNotNotify"` → FAIL (notify fires despite mute).

- [ ] **Step 3: Implement.** In `src/Services/IncidentService.php`:
- Change BOTH default-notifier lines (in `recordFailure` and `recordSuccess`) from `new EmailNotifier()` to `new MultiNotifier()`; update the `use` import `Defyn\Dashboard\Notify\EmailNotifier` → `Defyn\Dashboard\Notify\MultiNotifier`.
- In `recordFailure`, wrap the notify+stamp block (the `if ($this->safeNotify(... notifyDown ...))` line) so it only runs when not muted:
```php
        if (!$site->alertsMuted && $this->safeNotify(static fn () => $notifier->notifyDown($site, $incident))) {
            $incidents->markDownAlertSent($id, gmdate('Y-m-d H:i:s'));
        }
```
- In `recordSuccess`, same guard on the recovered block:
```php
            if (!$site->alertsMuted && $this->safeNotify(static fn () => $notifier->notifyRecovered($site, $closed))) {
                $incidents->markUpAlertSent($open->id, gmdate('Y-m-d H:i:s'));
            }
```
(The incident open/close + `ActivityLogger` + counter reset lines are unchanged.)

- [ ] **Step 4: Run the IncidentService + HealthService suites — verify green.** `composer test -- --filter "IncidentService|HealthService"` → PASS (new mute test + all existing P3.1 tests).

- [ ] **Step 5: Commit.**
```bash
git add packages/dashboard-plugin/src/Services/IncidentService.php packages/dashboard-plugin/tests/Integration/Services/IncidentServiceTest.php
git commit -m "feat(p3-3): IncidentService — MultiNotifier default + mute gate"
```

---

## Task 8: `Services\SslAlertService` — fire-once / reset / mute

**Guardrails 4, 5, 6:** fires once within 14d (`ssl_alert_sent_at` guard); clears on renewal/null; stamps even when muted but skips the send; UTC.

**Files:**
- Create: `packages/dashboard-plugin/src/Services/SslAlertService.php`
- Create: `packages/dashboard-plugin/tests/Integration/Services/SslAlertServiceTest.php`

- [ ] **Step 1: Write the failing test:**
```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Notify\Notifier;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\SslAlertService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/** @group integration */
final class SslAlertServiceTest extends AbstractSchemaTestCase
{
    private int $sid = 0;

    private function spy(array &$sent): Notifier
    {
        return new class($sent) implements Notifier {
            public function __construct(public array &$sent) {}
            public function notifyDown($s, $i): void {}
            public function notifyRecovered($s, $i): void {}
            public function notifySslExpiring($s, $e, $d): void { $this->sent[] = $d; }
        };
    }

    private function makeSite(int $n, ?string $expiresAt, ?string $stamp = null, bool $muted = false): void
    {
        $repo = new SitesRepository();
        $sid = $repo->insertPending(userId: 1, url: "https://s{$n}.test", label: "S{$n}", ourPublicKey: 'pk', ourPrivateKeyEncrypted: 'enc');
        global $wpdb;
        $wpdb->update(\Defyn\Dashboard\Schema\SitesTable::tableName(), [
            'ssl_expires_at' => $expiresAt, 'ssl_alert_sent_at' => $stamp, 'alerts_muted' => $muted ? 1 : 0,
        ], ['id' => $sid]);
        $this->sid = $sid;
    }

    public function testFiresOnceWithinThresholdThenStamps(): void
    {
        $this->makeSite(1, gmdate('Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS)); // 5 days left
        $sent = [];
        (new SslAlertService($this->spy($sent)))->evaluate($this->sid);

        self::assertCount(1, $sent);                                   // fired
        self::assertNotNull((new SitesRepository())->findById($this->sid)->sslAlertSentAt); // stamped

        $sent2 = [];
        (new SslAlertService($this->spy($sent2)))->evaluate($this->sid);
        self::assertSame([], $sent2);                                  // idempotent — already stamped
    }

    public function testMutedStampsButDoesNotSend(): void
    {
        $this->makeSite(2, gmdate('Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS), null, true);
        $sent = [];
        (new SslAlertService($this->spy($sent)))->evaluate($this->sid);
        self::assertSame([], $sent);                                   // muted → no send
        self::assertNotNull((new SitesRepository())->findById($this->sid)->sslAlertSentAt); // still stamped
    }

    public function testRenewalClearsStamp(): void
    {
        $this->makeSite(3, gmdate('Y-m-d H:i:s', time() + 90 * DAY_IN_SECONDS), '2026-01-01 00:00:00'); // renewed, stale stamp
        $sent = [];
        (new SslAlertService($this->spy($sent)))->evaluate($this->sid);
        self::assertSame([], $sent);
        self::assertNull((new SitesRepository())->findById($this->sid)->sslAlertSentAt); // cleared
    }
}
```

- [ ] **Step 2: Run it — verify it fails.** `composer test -- --filter SslAlertServiceTest` → FAIL.

- [ ] **Step 3: Implement:**
```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Notify\MultiNotifier;
use Defyn\Dashboard\Notify\Notifier;

/**
 * P3.3 — proactive SSL-expiry alert. Fires ONCE when a cert first drops under
 * THRESHOLD_DAYS (ssl_alert_sent_at guard); clears the stamp on renewal so the
 * next expiry can re-alert. Muted sites still get the stamp but skip the send.
 * Called per-site by the Jobs\SslCheck AS job.
 */
final class SslAlertService
{
    public const THRESHOLD_DAYS = 14;

    public function __construct(
        private readonly ?Notifier $notifier = null,
        private readonly ?SitesRepository $sites = null,
        private readonly ?ActivityLogger $logger = null,
    ) {}

    public function evaluate(int $siteId): void
    {
        $sites = $this->sites ?? new SitesRepository();
        $site  = $sites->findById($siteId);
        if ($site === null) {
            return;
        }

        $now = time();
        $expires = $site->sslExpiresAt !== null ? strtotime($site->sslExpiresAt . ' UTC') : null;
        $withinThreshold = $expires !== null && $expires <= $now + self::THRESHOLD_DAYS * DAY_IN_SECONDS;

        if (!$withinThreshold) {
            // Renewed or no cert — reset the de-dup stamp so a future expiry re-alerts.
            if ($site->sslAlertSentAt !== null) {
                $sites->clearSslAlertSent($siteId);
            }
            return;
        }

        if ($site->sslAlertSentAt !== null) {
            return; // already alerted this expiry episode (idempotent)
        }

        $daysLeft = max(0, (int) ceil(($expires - $now) / DAY_IN_SECONDS));

        if (!$site->alertsMuted) {
            ($this->notifier ?? new MultiNotifier())->notifySslExpiring($site, $site->sslExpiresAt, $daysLeft);
        }

        $sites->markSslAlertSent($siteId, gmdate('Y-m-d H:i:s', $now));
        ($this->logger ?? new ActivityLogger())->log($site->userId, $siteId, 'site.ssl_expiring', [
            'expires_at' => $site->sslExpiresAt, 'days_left' => $daysLeft,
        ]);
    }
}
```

- [ ] **Step 4: Run it — verify it passes.** `composer test -- --filter SslAlertServiceTest` → PASS.

- [ ] **Step 5: Commit.**
```bash
git add packages/dashboard-plugin/src/Services/SslAlertService.php packages/dashboard-plugin/tests/Integration/Services/SslAlertServiceTest.php
git commit -m "feat(p3-3): SslAlertService (fire-once/reset/mute)"
```

---

## Task 9: Daily SSL job — `SslCheckAll` + `SslCheck` + scheduler + boot + self-heal

**Guardrail 10:** mirror `HealthPingAll`→`HealthPing`; register in `Scheduler::SCHEDULES` + `Plugin::boot`; a self-heal guard installs the new recurring schedule on upgrade-without-reactivation.

**Files:**
- Create: `packages/dashboard-plugin/src/Jobs/SslCheckAll.php`, `src/Jobs/SslCheck.php`
- Modify: `src/Jobs/Scheduler.php` (SCHEDULES entry), `src/Plugin.php` (2 `add_action`s + imports), `src/Activation.php` (self-heal ensure-scheduled)
- Create: `packages/dashboard-plugin/tests/Integration/Jobs/SslCheckAllTest.php`

- [ ] **Step 1: Write the failing test** (mirror `HealthPingAllTest` — asserts the fan-out schedules a per-site `SslCheck` for each schedulable site; uses the AS test helper the existing job tests use):
```php
public function testFansOutPerSchedulableSite(): void
{
    $repo = new SitesRepository();
    $a = $repo->insertPending(userId: 1, url: 'https://a.test', label: 'A', ourPublicKey: 'pk', ourPrivateKeyEncrypted: 'enc');
    $repo->markActive($a, 'pk');

    (new SslCheckAll())->handle();

    self::assertNotFalse(as_next_scheduled_action(SslCheck::HOOK, [$a], 'defyn'));
}
```
(Mirror `tests/Integration/Jobs/HealthPingAllTest.php` exactly for setUp + the AS assertion style; adapt if it uses a different helper than `as_next_scheduled_action`.)

- [ ] **Step 2: Run it — verify it fails.** `composer test -- --filter SslCheckAllTest` → FAIL.

- [ ] **Step 3: Create the jobs** (mirror `HealthPingAll`/`HealthPing`):

`src/Jobs/SslCheckAll.php`:
```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\SitesRepository;

final class SslCheckAll
{
    public const HOOK = 'defyn_ssl_check_all';

    public function __construct(private readonly ?SitesRepository $repo = null) {}

    public function handle(): void
    {
        $repo = $this->repo ?? new SitesRepository();
        if (!function_exists('as_schedule_single_action')) {
            return;
        }
        foreach ($repo->findAllSchedulable() as $siteId) {
            as_schedule_single_action(time(), SslCheck::HOOK, [$siteId], 'defyn');
        }
    }
}
```

`src/Jobs/SslCheck.php`:
```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\SslAlertService;

final class SslCheck
{
    public const HOOK = 'defyn_ssl_check';

    public function __construct(private readonly ?SslAlertService $service = null) {}

    public function handle(int $siteId): void
    {
        ($this->service ?? new SslAlertService())->evaluate($siteId);
    }
}
```

- [ ] **Step 4: Wire the scheduler + boot.** In `src/Jobs/Scheduler.php` SCHEDULES (add after CleanupExpiredCodes):
```php
        SslCheckAll::HOOK          => 86400, // 24 hours
```
In `src/Plugin.php` boot (after the `HealthPingAll::HOOK` add_action), add:
```php
        add_action(SslCheckAll::HOOK, static function (): void {
            (new SslCheckAll())->handle();
        });
        add_action(SslCheck::HOOK, static function (int $siteId): void {
            (new SslCheck())->handle($siteId);
        });
```
Add the `use Defyn\Dashboard\Jobs\SslCheckAll;` + `use Defyn\Dashboard\Jobs\SslCheck;` imports at the top of Plugin.php.

In `src/Activation.php` `maybeRunSelfHeal()` (or the `ensureSchema`-adjacent self-heal path), add a guarded one-time install so the new recurring schedule lands on an upgrade-without-reactivation:
```php
        // P3.3 — ensure the daily SSL schedule exists even on a silent upgrade.
        if (function_exists('as_next_scheduled_action')
            && as_next_scheduled_action(\Defyn\Dashboard\Jobs\SslCheckAll::HOOK, [], 'defyn') === false) {
            \Defyn\Dashboard\Jobs\Scheduler::installRecurringSchedules();
        }
```

- [ ] **Step 5: Run — verify green.** `composer test -- --filter "SslCheckAll|Scheduler"` → PASS.

- [ ] **Step 6: Commit.**
```bash
git add packages/dashboard-plugin/src/Jobs/SslCheckAll.php packages/dashboard-plugin/src/Jobs/SslCheck.php packages/dashboard-plugin/src/Jobs/Scheduler.php packages/dashboard-plugin/src/Plugin.php packages/dashboard-plugin/src/Activation.php packages/dashboard-plugin/tests/Integration/Jobs/SslCheckAllTest.php
git commit -m "feat(p3-3): daily SslCheckAll/SslCheck fan-out + scheduler + self-heal"
```

---

## Task 10: `SettingsController` + RateLimit + routes + CORS

**Guardrails 7, 8:** webhook in per-operator user_meta, never logged; `hooks.slack.com` host allowlist on write.

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/SettingsController.php`
- Modify: `src/Rest/Middleware/RateLimit.php` (settings 30/min + settingsWrite 10/hr), `src/Rest/RestRouter.php` (2 routes)
- Create: `packages/dashboard-plugin/tests/Integration/Rest/SettingsControllerTest.php`, `tests/Integration/Rest/SettingsCorsTest.php`

- [ ] **Step 1: Write the failing tests** — GET returns the current operator's webhook; POST accepts `hooks.slack.com`, accepts empty (clears), rejects another host with 400 `settings.invalid_webhook`. CORS test mirrors `MonitoringCorsTest` for `/defyn/v1/settings`.
```php
public function testPostRejectsNonSlackHost(): void
{
    $req = new WP_REST_Request('POST', '/defyn/v1/settings/slack-webhook');
    $req->set_param('_authenticated_user_id', 1);
    $req->set_body(json_encode(['webhook_url' => 'https://evil.example.com/x']));
    $req->set_header('Content-Type', 'application/json');
    $res = (new SettingsController())->handleSet($req);
    self::assertSame(400, $res->get_status());
}

public function testPostAcceptsSlackAndGetReturnsIt(): void
{
    $req = new WP_REST_Request('POST', '/defyn/v1/settings/slack-webhook');
    $req->set_param('_authenticated_user_id', 1);
    $req->set_body(json_encode(['webhook_url' => 'https://hooks.slack.com/services/T/B/x']));
    $req->set_header('Content-Type', 'application/json');
    self::assertSame(200, (new SettingsController())->handleSet($req)->get_status());

    $get = new WP_REST_Request('GET', '/defyn/v1/settings');
    $get->set_param('_authenticated_user_id', 1);
    self::assertSame('https://hooks.slack.com/services/T/B/x', (new SettingsController())->handleGet($get)->get_data()['slack_webhook_url']);
}
```

- [ ] **Step 2: Run — verify fail.** `composer test -- --filter "SettingsControllerTest|SettingsCorsTest"` → FAIL.

- [ ] **Step 3: Implement the controller:**
```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\ActivityLogger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P3.3 — per-operator notification settings. The Slack webhook is stored in
 * user_meta (defyn_slack_webhook_url) — never logged; writes are host-allowlisted.
 */
final class SettingsController
{
    private const META_KEY = 'defyn_slack_webhook_url';

    public function handleGet(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $url = (string) get_user_meta($userId, self::META_KEY, true);
        return new WP_REST_Response(['slack_webhook_url' => $url === '' ? null : $url], 200);
    }

    public function handleSet(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $body = $request->get_json_params() ?: [];
        $url  = isset($body['webhook_url']) ? trim((string) $body['webhook_url']) : '';

        if ($url !== '' && !preg_match('#^https://hooks\.slack\.com/#', $url)) {
            return ErrorResponse::create(400, 'settings.invalid_webhook', 'Webhook must be an https://hooks.slack.com/ URL or empty.');
        }

        if ($url === '') {
            delete_user_meta($userId, self::META_KEY);
        } else {
            update_user_meta($userId, self::META_KEY, $url);
        }

        (new ActivityLogger())->log($userId, null, 'settings.slack_webhook_updated', ['cleared' => $url === '']);

        return new WP_REST_Response(['slack_webhook_url' => $url === '' ? null : $url], 200);
    }
}
```

- [ ] **Step 4: Add RateLimit buckets** (mirror `monitoring` for read, `coreAllowMajor` for write but keyed per-user only):
```php
    public const SETTINGS_LIMIT  = 30;
    public const SETTINGS_WINDOW = MINUTE_IN_SECONDS;
    public const SETTINGS_WRITE_LIMIT  = 10;
    public const SETTINGS_WRITE_WINDOW = HOUR_IN_SECONDS;
```
```php
    public static function settings(WP_REST_Request $request)
    {
        return self::perUserBucket($request, 'defyn_rl_settings_%d', self::SETTINGS_LIMIT, self::SETTINGS_WINDOW, 'settings.rate_limited');
    }
    public static function settingsWrite(WP_REST_Request $request)
    {
        return self::perUserBucket($request, 'defyn_rl_settingsWrite_%d', self::SETTINGS_WRITE_LIMIT, self::SETTINGS_WRITE_WINDOW, 'settings.rate_limited');
    }
```
If no `perUserBucket` helper exists, inline the `monitoring()`-shaped body (RequireAuth::check → transient key → limit → set_transient) for each.

- [ ] **Step 5: Register routes** in `src/Rest/RestRouter.php` (near `/monitoring`):
```php
        register_rest_route(self::NAMESPACE, '/settings', [
            'methods' => 'GET', 'callback' => [new SettingsController(), 'handleGet'],
            'permission_callback' => [RateLimit::class, 'settings'],
        ]);
        register_rest_route(self::NAMESPACE, '/settings/slack-webhook', [
            'methods' => 'POST', 'callback' => [new SettingsController(), 'handleSet'],
            'permission_callback' => [RateLimit::class, 'settingsWrite'],
        ]);
```
Add the `use Defyn\Dashboard\Rest\SettingsController;` import. Write `SettingsCorsTest.php` mirroring `MonitoringCorsTest` for `/defyn/v1/settings`.

- [ ] **Step 6: Run — verify green.** `composer test -- --filter "SettingsControllerTest|SettingsCorsTest"` → PASS.

- [ ] **Step 7: Commit.**
```bash
git add packages/dashboard-plugin/src/Rest/SettingsController.php packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php packages/dashboard-plugin/src/Rest/RestRouter.php packages/dashboard-plugin/tests/Integration/Rest/SettingsControllerTest.php packages/dashboard-plugin/tests/Integration/Rest/SettingsCorsTest.php
git commit -m "feat(p3-3): GET/POST /settings (Slack webhook, host-allowlisted) + buckets + CORS"
```

---

## Task 11: `SitesAlertsMuteController` + RateLimit + route + CORS

**Guardrail 11:** exact mirror of `SitesCoreAllowMajorController`.

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/SitesAlertsMuteController.php`
- Modify: `src/Rest/Middleware/RateLimit.php` (alertsMute 10/hr per user+site), `src/Rest/RestRouter.php` (route)
- Create: `packages/dashboard-plugin/tests/Integration/Rest/SitesAlertsMuteControllerTest.php`, `tests/Integration/Rest/SitesAlertsMuteCorsTest.php`

- [ ] **Step 1: Write the failing test** (mirror the existing core/allow-major controller test: 404 non-owned, 200 toggles, body validation):
```php
public function testMuteTogglePersistsAndIsOwnershipChecked(): void
{
    $sites = new SitesRepository();
    $id = $sites->insertPending(userId: 1, url: 'https://m.test', label: 'M', ourPublicKey: 'pk', ourPrivateKeyEncrypted: 'enc');

    $req = new WP_REST_Request('POST', "/defyn/v1/sites/{$id}/alerts/mute");
    $req->set_param('_authenticated_user_id', 1);
    $req->set_param('id', $id);
    $req->set_body(json_encode(['muted' => true]));
    $req->set_header('Content-Type', 'application/json');
    $res = (new SitesAlertsMuteController())->handle($req);
    self::assertSame(200, $res->get_status());
    self::assertTrue($res->get_data()['alerts_muted']);
    self::assertTrue($sites->findById($id)->alertsMuted);

    $req2 = new WP_REST_Request('POST', "/defyn/v1/sites/{$id}/alerts/mute");
    $req2->set_param('_authenticated_user_id', 999);
    $req2->set_param('id', $id);
    $req2->set_body(json_encode(['muted' => true]));
    $req2->set_header('Content-Type', 'application/json');
    self::assertSame(404, (new SitesAlertsMuteController())->handle($req2)->get_status());
}
```

- [ ] **Step 2: Run — verify fail.** `composer test -- --filter "SitesAlertsMute"` → FAIL.

- [ ] **Step 3: Implement** (clone `SitesCoreAllowMajorController`, swapping `allow`→`muted`, `setCoreAllowMajor`→`setAlertsMuted`, event `site.alerts_muted`/`site.alerts_unmuted`, response key `alerts_muted`):
```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

final class SitesAlertsMuteController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $body = $request->get_json_params() ?: [];
        if (!array_key_exists('muted', $body) || !is_bool($body['muted'])) {
            return ErrorResponse::create(400, 'alerts.invalid_payload', 'Request body must include a boolean "muted" field.');
        }
        $muted = (bool) $body['muted'];

        (new SitesRepository())->setAlertsMuted($siteId, $muted);
        (new ActivityLogger())->log($userId, $siteId, $muted ? 'site.alerts_muted' : 'site.alerts_unmuted', []);

        return new WP_REST_Response(['site_id' => $siteId, 'alerts_muted' => $muted], 200);
    }
}
```

- [ ] **Step 4: Add RateLimit + route** — `RateLimit::alertsMute` (mirror `coreAllowMajor` exactly, key `defyn_rl_alertsMute_%d_%d`, 10/hr). Route in RestRouter:
```php
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/alerts/mute', [
            'methods' => 'POST', 'callback' => [new SitesAlertsMuteController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'alertsMute'],
        ]);
```
Add `use Defyn\Dashboard\Rest\SitesAlertsMuteController;`. Write `SitesAlertsMuteCorsTest.php` mirroring `MonitoringCorsTest` for the route.

- [ ] **Step 5: Run — verify green.** `composer test -- --filter "SitesAlertsMute"` → PASS.

- [ ] **Step 6: Commit.**
```bash
git add packages/dashboard-plugin/src/Rest/SitesAlertsMuteController.php packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php packages/dashboard-plugin/src/Rest/RestRouter.php packages/dashboard-plugin/tests/Integration/Rest/SitesAlertsMute*
git commit -m "feat(p3-3): POST /sites/{id}/alerts/mute (mirror allow-major) + bucket + CORS"
```

---

## Task 12: Uninstaller — webhook user_meta cleanup

**Files:**
- Modify: `packages/dashboard-plugin/src/Uninstaller.php`
- Test: `packages/dashboard-plugin/tests/Integration/UninstallTest.php` (add a meta-cleanup assertion alongside the existing — do NOT rely on the table-drop assertion, which is the tolerated infra carry-forward)

- [ ] **Step 1: Write the failing test:**
```php
public function testUninstallDeletesSlackWebhookMeta(): void
{
    $uid = self::factory()->user->create();
    update_user_meta($uid, 'defyn_slack_webhook_url', 'https://hooks.slack.com/services/T/B/x');
    \Defyn\Dashboard\Uninstaller::uninstall();
    self::assertSame('', (string) get_user_meta($uid, 'defyn_slack_webhook_url', true));
}
```

- [ ] **Step 2: Run — verify fail.** `composer test -- --filter "UninstallTest::testUninstallDeletesSlackWebhookMeta"` → FAIL.

- [ ] **Step 3: Implement.** In `src/Uninstaller.php` `uninstall()`, after the `delete_option` line:
```php
        // P3.3 — clear every operator's Slack webhook (delete_metadata bulk form).
        delete_metadata('user', 0, 'defyn_slack_webhook_url', '', true);
```

- [ ] **Step 4: Run — verify pass.** `composer test -- --filter "UninstallTest::testUninstallDeletesSlackWebhookMeta"` → PASS. (The pre-existing `testUninstallDropsAllTables` remains the tolerated infra failure.)

- [ ] **Step 5: Commit.**
```bash
git add packages/dashboard-plugin/src/Uninstaller.php packages/dashboard-plugin/tests/Integration/UninstallTest.php
git commit -m "feat(p3-3): Uninstaller clears Slack webhook user_meta"
```

---

## Task 13: Dashboard v0.12.0 version bump

**Files:** `defyn-dashboard.php` (Version: + DEFYN_DASHBOARD_VERSION), `composer.json` (version), `readme.txt` (Stable tag + changelog entry).

- [ ] **Step 1: Bump all four `0.11.0` → `0.12.0`** + add a `= 0.12.0 =` changelog entry to `readme.txt` (Slack alerts + SSL-expiry alerts + per-site mute + /settings; schema v9→v10; connector unchanged).

- [ ] **Step 2: Full PHP suite green** (carry-forward = 1 UninstallTest table-drop infra fail). `cd packages/dashboard-plugin && composer test 2>&1 | tail -25`.

- [ ] **Step 3: Commit.**
```bash
git add packages/dashboard-plugin/defyn-dashboard.php packages/dashboard-plugin/composer.json packages/dashboard-plugin/readme.txt
git commit -m "chore(p3-3): bump dashboard to v0.12.0"
```

---

## Task 14: SPA — `settingsSchema` + `siteSchema.alerts_muted` + MSW

**Files:**
- Modify: `apps/web/src/types/api.ts`, `apps/web/src/test/handlers.ts`
- Test: `apps/web/tests/types/settings.test.ts`

- [ ] **Step 1: Write the failing schema test:**
```ts
import { describe, it, expect } from 'vitest';
import { settingsSchema } from '@/types/api';

describe('settingsSchema', () => {
  it('parses a webhook + null', () => {
    expect(settingsSchema.parse({ slack_webhook_url: 'https://hooks.slack.com/x' }).slack_webhook_url).toBe('https://hooks.slack.com/x');
    expect(settingsSchema.parse({ slack_webhook_url: null }).slack_webhook_url).toBeNull();
  });
});
```

- [ ] **Step 2: Run — verify fail (Node 22).** `cd apps/web && pnpm vitest run tests/types/settings.test.ts` → FAIL.

- [ ] **Step 3: Add schemas + field.** In `src/types/api.ts`:
```ts
// P3.3 — operator notification settings.
export const settingsSchema = z.object({ slack_webhook_url: z.string().nullable() });
export type Settings = z.infer<typeof settingsSchema>;
```
Add `alerts_muted: z.boolean()` to `siteSchema` (after `core_allow_major`). Add MSW handlers in `src/test/handlers.ts`: `GET */wp-json/defyn/v1/settings` → `{ slack_webhook_url: null }`; `POST */wp-json/defyn/v1/settings/slack-webhook` → echoes `{ slack_webhook_url: <body.webhook_url || null> }`; `POST */wp-json/defyn/v1/sites/:id/alerts/mute` → `{ site_id: Number(params.id), alerts_muted: true }`. Update the existing site MSW fixture(s) to include `alerts_muted: false`.

- [ ] **Step 4: Run — verify pass.** `pnpm vitest run tests/types/settings.test.ts` → PASS.

- [ ] **Step 5: Commit.**
```bash
git add apps/web/src/types/api.ts apps/web/src/test/handlers.ts apps/web/tests/types/settings.test.ts
git commit -m "feat(p3-3): settingsSchema + siteSchema.alerts_muted + MSW"
```

---

## Task 15: SPA — query/mutation hooks (`useSettings`, `useSaveSlackWebhook`, `useToggleMuteAlerts`)

**Files:**
- Create: `apps/web/src/lib/queries/useSettings.ts`, `apps/web/src/lib/mutations/useSaveSlackWebhook.ts`, `apps/web/src/lib/mutations/useToggleMuteAlerts.ts`
- Test: a hook test per the established location (mirror `useOverview`/`useToggleCoreAllowMajor` test placement)

- [ ] **Step 1: Write failing tests** mirroring the existing `useToggleCoreAllowMajor` test + a `useSettings` query test (use the real `@/test/setup` server helper; verify against MSW handlers from Task 14).

- [ ] **Step 2: Run — verify fail.**

- [ ] **Step 3: Implement.**
`useSettings.ts` (mirror `useOverview` — raw body parsed directly):
```ts
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { settingsSchema } from '@/types/api';

export function useSettings() {
  return useQuery({
    queryKey: ['settings'],
    queryFn: async () => settingsSchema.parse(await apiClient.get<unknown>('/settings')),
  });
}
```
`useSaveSlackWebhook.ts`:
```ts
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { settingsSchema } from '@/types/api';

export function useSaveSlackWebhook() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (webhookUrl: string) =>
      settingsSchema.parse(await apiClient.post<unknown>('/settings/slack-webhook', { webhook_url: webhookUrl })),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['settings'] }); },
  });
}
```
`useToggleMuteAlerts.ts` (mirror `useToggleCoreAllowMajor`):
```ts
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { z } from 'zod';

const muteResponse = z.object({ site_id: z.number().int(), alerts_muted: z.boolean() });

export function useToggleMuteAlerts(siteId: number) {
  const qc = useQueryClient();
  return useMutation<{ site_id: number; alerts_muted: boolean }, Error, boolean>({
    mutationFn: async (muted: boolean) =>
      muteResponse.parse(await apiClient.post<unknown>(`/sites/${siteId}/alerts/mute`, { muted })),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['sites', siteId] }); },
  });
}
```

- [ ] **Step 4: Run — verify pass. Step 5: Commit.**
```bash
git add apps/web/src/lib/queries/useSettings.ts apps/web/src/lib/mutations/useSaveSlackWebhook.ts apps/web/src/lib/mutations/useToggleMuteAlerts.ts apps/web/tests
git commit -m "feat(p3-3): useSettings + useSaveSlackWebhook + useToggleMuteAlerts hooks"
```

---

## Task 16: SPA — `/settings` page + `SettingsNavLink` + routing

**Files:**
- Create: `apps/web/src/routes/Settings.tsx`, `apps/web/src/components/nav/SettingsNavLink.tsx`
- Modify: `apps/web/src/App.tsx` (route), `apps/web/src/routes/Overview.tsx` (mount nav link)
- Test: `apps/web/tests/routes/Settings.test.tsx`, `apps/web/tests/components/nav/SettingsNavLink.test.tsx`

- [ ] **Step 1: Write failing tests** — `Settings` page loads the webhook, saves a valid URL, shows an inline error for a non-`hooks.slack.com` URL (client validation mirrors backend); `SettingsNavLink` links to `/settings`. Mirror the P3.2 `Monitoring`/`MonitoringNavLink` test harness.

- [ ] **Step 2: Run — verify fail.**

- [ ] **Step 3: Implement `Settings.tsx`** (a Notifications card: controlled input seeded from `useSettings`, Save via `useSaveSlackWebhook`, inline validation `value === '' || /^https:\/\/hooks\.slack\.com\//.test(value)`, the "email always on" helper). `SettingsNavLink.tsx` mirrors `MonitoringNavLink` (`<Link to="/settings">Settings</Link>`). Wire `<Route path="/settings" element={<Settings />} />` under `RequireAuth` in `App.tsx`; mount `<SettingsNavLink />` beside `<MonitoringNavLink />` in `Overview.tsx`.

- [ ] **Step 4: Run — verify pass** (+ full suite once: only the 4 carry-forwards). **Step 5: Commit.**
```bash
git add apps/web/src/routes/Settings.tsx apps/web/src/components/nav/SettingsNavLink.tsx apps/web/src/App.tsx apps/web/src/routes/Overview.tsx apps/web/tests
git commit -m "feat(p3-3): /settings page + SettingsNavLink"
```

---

## Task 17: SPA — `SiteMuteAlertsSettingsRow` on Site detail

**Files:**
- Create: `apps/web/src/components/sites/SiteMuteAlertsSettingsRow.tsx`
- Modify: `apps/web/src/routes/SiteDetail.tsx` (render beside `SiteMajorUpdatesSettingsRow`)
- Test: `apps/web/tests/components/sites/SiteMuteAlertsSettingsRow.test.tsx`

- [ ] **Step 1: Write the failing test** (mirror the allow-major settings-row test): renders a Switch bound to `site.alerts_muted`, toggling calls the mutation.

- [ ] **Step 2: Run — verify fail.**

- [ ] **Step 3: Implement** (clone `SiteMajorUpdatesSettingsRow`, swap to `useToggleMuteAlerts` + `site.alerts_muted`, label "Mute alerts for this site", helper "Incidents & SSL are still tracked — no notifications are sent."). Render in `SiteDetail.tsx` right after `<SiteMajorUpdatesSettingsRow site={data} />`:
```tsx
{data && <SiteMuteAlertsSettingsRow site={data} />}
```

- [ ] **Step 4: Run — verify pass** (+ full SPA suite under Node 22: only the 4 carry-forwards, no new failures/hang). **Step 5: Commit.**
```bash
git add apps/web/src/components/sites/SiteMuteAlertsSettingsRow.tsx apps/web/src/routes/SiteDetail.tsx apps/web/tests/components/sites/SiteMuteAlertsSettingsRow.test.tsx
git commit -m "feat(p3-3): SiteMuteAlertsSettingsRow on Site detail"
```

---

## Task 18: Release — build, deploy, smoke, tag, MEMORY

- [ ] **Step 1: Full PHP suite green** (carry-forward = 1 UninstallTest table-drop infra fail). `cd packages/dashboard-plugin && composer test 2>&1 | tail -25`.

- [ ] **Step 2: Full SPA suite green under Node 22** (carry-forward = SiteDetail×2 + SiteCoreCard×2).
```bash
cd apps/web && export FNM_DIR="$HOME/.fnm"; eval "$(fnm env --shell bash)"; fnm use 22
pkill -9 -f vitest 2>/dev/null; pnpm vitest run 2>&1 | tail -30
```
**Any hang = real bug** (P2.10 lesson).

- [ ] **Step 3: Build the dashboard zip v0.12.0** (symfony-preserving; top-level `dashboard-plugin/` folder — the established structure):
```bash
cd packages/dashboard-plugin && composer install --no-dev --classmap-authoritative
cd .. && rm -f ../dist/defyn-dashboard-0.12.0.zip
zip -rq ../dist/defyn-dashboard-0.12.0.zip dashboard-plugin \
  -x 'dashboard-plugin/tests/*' 'dashboard-plugin/*wp-tests-config.php' 'dashboard-plugin/.phpunit.result.cache' \
     'dashboard-plugin/test-output.log' 'dashboard-plugin/phpunit.xml' 'dashboard-plugin/composer.lock' \
     'dashboard-plugin/.github/*' 'dashboard-plugin/.gitignore'
cd dashboard-plugin && composer install
```

- [ ] **Step 4: Verify the zip** (must return 2 + the new code):
```bash
unzip -l ../../dist/defyn-dashboard-0.12.0.zip | grep -E "deprecation-contracts/function\.php|polyfill-php83/bootstrap\.php" | wc -l   # 2
unzip -l ../../dist/defyn-dashboard-0.12.0.zip | grep -Ec "SlackNotifier\.php|SettingsController\.php|SslCheck\.php"                 # 3
```

- [ ] **Step 5: Build SPA.** `cd apps/web && pnpm build 2>&1 | tail -5`.

- [ ] **Step 6: Merge to main + push** (Cloudflare auto-deploys):
```bash
cd "/Users/pradeep/Local Sites/defynWP" && git checkout main && git merge --ff-only p3-3-monitoring-alerting && git push origin main
```

- [ ] **Step 7: Dashboard install (MANUAL USER STEP — flag it).** Install `dist/defyn-dashboard-0.12.0.zip` via WP Admin Replace-current + clear MyKinsta cache. **Note:** the new daily SSL schedule self-heals on first load (Task 9 guard); a deactivate+reactivate also installs it. Wait for confirmation.

- [ ] **Step 8: Production API smoke** (curl; JWT field is **`access_token`**; creds `pradeep@defyn.com.au` / `DefynWP-ifirCh5pXm5bTOj0`):
```bash
TOKEN=$(curl -s -X POST https://defynwp.defyn.agency/wp-json/defyn/v1/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"pradeep@defyn.com.au","password":"DefynWP-ifirCh5pXm5bTOj0"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["access_token"])')
curl -s -o /dev/null -w "settings authed: %{http_code}\n" "https://defynwp.defyn.agency/wp-json/defyn/v1/settings?_=$RANDOM" -H "Authorization: Bearer $TOKEN"
curl -s -o /dev/null -w "settings no-auth: %{http_code}\n" "https://defynwp.defyn.agency/wp-json/defyn/v1/settings?_=$RANDOM"
curl -s -o /dev/null -w "bad webhook: %{http_code}\n" -X POST "https://defynwp.defyn.agency/wp-json/defyn/v1/settings/slack-webhook?_=$RANDOM" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"webhook_url":"https://evil.example.com/x"}'
curl -s -o /dev/null -w "mute 404: %{http_code}\n" -X POST "https://defynwp.defyn.agency/wp-json/defyn/v1/sites/999999/alerts/mute?_=$RANDOM" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"muted":true}'
```
Expected: settings authed 200, no-auth 401, bad webhook 400, mute 404.

- [ ] **Step 9: Verify the deployed SPA.**
```bash
curl -s -o /dev/null -w "/settings route: %{http_code}\n" https://app.defynwp.defyn.agency/settings
B=$(curl -s "https://app.defynwp.defyn.agency/?_=$RANDOM" | grep -oE 'assets/index-[A-Za-z0-9_-]+\.js' | head -1)
curl -s "https://app.defynwp.defyn.agency/$B" -o /tmp/b.js; for s in "Slack" "Mute alerts" "/settings"; do echo "$s: $(grep -c "$s" /tmp/b.js)"; done
```

- [ ] **Step 10: Tag + MEMORY.**
```bash
git tag -a p3-3-monitoring-alerting-complete -m "P3.3 — monitoring alerting expansion & config (Slack + SSL alerts + mute + /settings)"
git push origin p3-3-monitoring-alerting-complete
```
Update `~/.claude/projects/-Users-pradeep-Local-Sites-defynWP/memory/project_defyn_roadmap.md`: P3.3 shipped — schema v10, dashboard v0.12.0, connector unchanged; Slack `MultiNotifier` + SSL daily job + per-site mute + `/settings`; **Monitoring phase COMPLETE** → next subsystem is Security scanning.

---

## Self-Review

**Spec coverage:** §4 notifier+fan-out → Tasks 4,5,6; §5 mute gate → Task 7; §6 schema v10 → Tasks 1,2,3; §7 SSL daily flow → Tasks 8,9; §8 endpoints → Tasks 10,11; §9 SPA → Tasks 14–17; §10 errors → covered in the notifier/SSL/settings tasks; §11 testing → every task TDD; §12 release → Tasks 13,18. All 12 guardrails cited in owning tasks. No gaps.

**Type consistency:** `alerts_muted` (DB/JSON/Zod) ↔ `alertsMuted` (PHP) ↔ `site.alerts_muted` (SPA) consistent; `ssl_alert_sent_at`↔`sslAlertSentAt` (internal, never in toJson/Zod); `Notifier` 3-method shape identical across interface + `EmailNotifier` + `SlackNotifier` + `MultiNotifier` + every test spy; `defyn_slack_webhook_url` meta key identical in `SlackNotifier`/`SettingsController`/`Uninstaller`; `SslAlertService::THRESHOLD_DAYS = 14` matches the spec; the 3 endpoints' response keys (`slack_webhook_url`, `alerts_muted`) match the SPA hooks/schemas.

**Placeholder scan:** every code step shows complete code; commands have expected output; the two places that say "mirror the existing test harness" (EmailNotifier wp_mail capture, IncidentService spy) point at named existing files to copy rather than leaving logic unspecified.
