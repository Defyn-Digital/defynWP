# F9 — Activity Log Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose the activity audit trail end-to-end. Per spec § 11: backend endpoints for global + per-site activity feeds, a `/activity` SPA route with filters, and a per-site activity panel on `SiteDetail`. Backfill the silent writers (`SyncService`, `HealthService`, `DisconnectService`, `AuthLoginController`) so the feed actually has content.

**Architecture:** New `ActivityEvent` model + `ActivityLogRepository` (per repository pattern — repo becomes the only SQL touch-point for `wp_defyn_activity_log`). Existing F5 `ActivityLogger::log()` extended with optional `ipAddress` parameter; REST controllers populate it from `$_SERVER['REMOTE_ADDR']`, AS jobs leave it null. Two new REST endpoints with offset pagination + `event_type` / `site_id` filters. SPA gets a new `/activity` route with event-type chips + site dropdown filter, plus a "Recent activity" panel on `SiteDetail` showing the last 10 events for that site.

**Tech Stack:** PHP 8.1+, libsodium, PHPUnit (backend). React 18 + TypeScript + Vite + TanStack Query v5 + Zod + Tailwind + Vitest + MSW (SPA). No new dependencies.

**Spec source:** `docs/superpowers/specs/2026-04-18-defyn-foundation-design.md` — § 4 (`wp_defyn_activity_log` schema), § 11 (F9 deliverable).

**Branch:** Off main as `f9-activity-log`. Last shipped: F8 merge `2bb2573`.

**Design decisions (locked):**
- **Backfill writes**: Sync/Health/Disconnect/Auth services all log events.
- **Pagination**: offset-based, `?page=1&per_page=50`, max 200.
- **Filtering**: server supports `?event_type=X&site_id=N`. SPA chips group event types (`site.*`, `health.*`, `auth.*`, `sync.*`).
- **IP capture**: REST-originated events capture `$_SERVER['REMOTE_ADDR']`. Background AS-originated events stay null.
- **User scoping**: global feed shows (events for user's sites) UNION (events with that `user_id`). Per-site feed gated by site ownership.

---

### Task 1: `ActivityEvent` model + `ActivityLogRepository`

**Why:** Per repository pattern, the new SQL reads need a single class as the touch-point. The model is the SPA-facing shape (similar to F5 `Site` model).

**Files:**
- Create: `packages/dashboard-plugin/src/Models/ActivityEvent.php`
- Create: `packages/dashboard-plugin/src/Services/ActivityLogRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/ActivityLogRepositoryTest.php` (NEW)

- [ ] **Step 1: Write the failing test**

Create the test file:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Services\ActivityLogRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ActivityLogRepositoryTest extends AbstractSchemaTestCase
{
    private function insertRow(int $userId, ?int $siteId, string $eventType, ?array $details = null, ?string $ip = null, ?string $createdAt = null): int
    {
        global $wpdb;
        $wpdb->insert(
            ActivityLogTable::tableName(),
            [
                'user_id'    => $userId,
                'site_id'    => $siteId,
                'event_type' => $eventType,
                'details'    => $details === null ? null : json_encode($details),
                'ip_address' => $ip,
                'created_at' => $createdAt ?? gmdate('Y-m-d H:i:s'),
            ],
            [
                '%d',
                $siteId === null ? '%s' : '%d',
                '%s',
                '%s',
                '%s',
                '%s',
            ]
        );
        return (int) $wpdb->insert_id;
    }

    public function testPaginateReturnsNewestFirst(): void
    {
        $this->insertRow(1, 5, 'site.connected', ['url' => 'https://a.test'], null, '2026-05-30 00:00:00');
        sleep(1);
        $this->insertRow(1, 5, 'site.synced', ['wp_version' => '6.9.4'], null, '2026-05-31 00:00:00');

        $events = (new ActivityLogRepository())->paginateForUser(1, null, null, 1, 50);

        $this->assertCount(2, $events);
        $this->assertSame('site.synced', $events[0]->eventType);
        $this->assertSame('site.connected', $events[1]->eventType);
        $this->assertSame(['wp_version' => '6.9.4'], $events[0]->details);
    }

    public function testFilterByEventType(): void
    {
        $this->insertRow(1, 5, 'site.connected');
        $this->insertRow(1, 5, 'site.synced');
        $this->insertRow(1, 5, 'site.health_ok');

        $events = (new ActivityLogRepository())->paginateForUser(1, null, 'site.synced', 1, 50);
        $this->assertCount(1, $events);
        $this->assertSame('site.synced', $events[0]->eventType);
    }

    public function testFilterBySite(): void
    {
        $this->insertRow(1, 5, 'site.synced');
        $this->insertRow(1, 7, 'site.synced');
        $this->insertRow(1, null, 'auth.login');

        $events = (new ActivityLogRepository())->paginateForUser(1, 5, null, 1, 50);
        $this->assertCount(1, $events);
        $this->assertSame(5, $events[0]->siteId);
    }

    public function testPaginationOffset(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->insertRow(1, 5, 'site.synced', null, null, '2026-05-' . sprintf('%02d', 20 + ($i % 10)) . ' 00:00:00');
        }

        $page1 = (new ActivityLogRepository())->paginateForUser(1, null, null, 1, 3);
        $page2 = (new ActivityLogRepository())->paginateForUser(1, null, null, 2, 3);

        $this->assertCount(3, $page1);
        $this->assertCount(3, $page2);
        $this->assertNotSame($page1[0]->id, $page2[0]->id);
    }

    public function testCountForUser(): void
    {
        $this->insertRow(1, 5, 'site.synced');
        $this->insertRow(1, 5, 'site.synced');
        $this->insertRow(2, 6, 'site.synced');

        $repo = new ActivityLogRepository();
        $this->assertSame(2, $repo->countForUser(1, null, null));
        $this->assertSame(1, $repo->countForUser(2, null, null));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter ActivityLogRepositoryTest`
Expected: FAIL — neither class exists.

- [ ] **Step 3: Create the `ActivityEvent` model**

`packages/dashboard-plugin/src/Models/ActivityEvent.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Models;

/**
 * Immutable readonly DTO for one row of wp_defyn_activity_log.
 *
 * `details` is the decoded JSON array (or null). `ipAddress` is captured
 * from $_SERVER for REST-originated events; AS-originated events leave it
 * null because background jobs have no request context.
 */
final class ActivityEvent
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $userId,
        public readonly ?int $siteId,
        public readonly string $eventType,
        public readonly ?array $details,
        public readonly ?string $ipAddress,
        public readonly string $createdAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $details = null;
        if (isset($row['details']) && $row['details'] !== '' && $row['details'] !== null) {
            $decoded = json_decode((string) $row['details'], true);
            $details = is_array($decoded) ? $decoded : null;
        }

        return new self(
            id:        (int) $row['id'],
            userId:    isset($row['user_id']) ? (int) $row['user_id'] : null,
            siteId:    isset($row['site_id']) ? (int) $row['site_id'] : null,
            eventType: (string) $row['event_type'],
            details:   $details,
            ipAddress: isset($row['ip_address']) && $row['ip_address'] !== '' ? (string) $row['ip_address'] : null,
            createdAt: (string) $row['created_at'],
        );
    }

    /** @return array<string, mixed> shape the SPA receives over the wire */
    public function toJson(): array
    {
        return [
            'id'         => $this->id,
            'site_id'    => $this->siteId,
            'event_type' => $this->eventType,
            'details'    => $this->details,
            'created_at' => $this->createdAt,
            // user_id + ip_address intentionally hidden from SPA (operator-only)
        ];
    }
}
```

- [ ] **Step 4: Create the `ActivityLogRepository`**

`packages/dashboard-plugin/src/Services/ActivityLogRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\ActivityEvent;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitesTable;

/**
 * Sole SQL touch-point for wp_defyn_activity_log. Writers (ActivityLogger)
 * delegate INSERTs here; controllers issue paginated SELECTs.
 *
 * User-scoped reads: an event is "for" user U if it has user_id=U OR its
 * site_id belongs to a site owned by U. Anti-leak: events for sites owned
 * by other users never surface in U's feed.
 */
final class ActivityLogRepository
{
    public const MAX_PER_PAGE = 200;

    /**
     * Insert a new event row. Returns the inserted id.
     *
     * @param array<string, mixed>|null $details
     */
    public function insert(?int $userId, ?int $siteId, string $eventType, ?array $details = null, ?string $ipAddress = null): int
    {
        global $wpdb;
        $wpdb->insert(
            ActivityLogTable::tableName(),
            [
                'user_id'    => $userId,
                'site_id'    => $siteId,
                'event_type' => $eventType,
                'details'    => $details === null ? null : json_encode($details, JSON_THROW_ON_ERROR),
                'ip_address' => $ipAddress,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ],
            [
                $userId === null ? '%s' : '%d',
                $siteId === null ? '%s' : '%d',
                '%s',
                '%s',
                '%s',
                '%s',
            ]
        );
        return (int) $wpdb->insert_id;
    }

    /**
     * Newest-first user-scoped feed. Optional filters: site_id, event_type.
     *
     * @return list<ActivityEvent>
     */
    public function paginateForUser(int $userId, ?int $siteId, ?string $eventType, int $page, int $perPage): array
    {
        global $wpdb;
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        [$where, $args] = $this->buildWhere($userId, $siteId, $eventType);
        $sql = "SELECT a.* FROM " . ActivityLogTable::tableName() . " a {$where} "
             . "ORDER BY a.created_at DESC, a.id DESC LIMIT %d OFFSET %d";
        $args[] = $perPage;
        $args[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: [];
        return array_map([ActivityEvent::class, 'fromRow'], $rows);
    }

    public function countForUser(int $userId, ?int $siteId, ?string $eventType): int
    {
        global $wpdb;
        [$where, $args] = $this->buildWhere($userId, $siteId, $eventType);
        $sql = "SELECT COUNT(*) FROM " . ActivityLogTable::tableName() . " a {$where}";
        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$args));
    }

    /**
     * @return array{0: string, 1: list<scalar>}
     */
    private function buildWhere(int $userId, ?int $siteId, ?string $eventType): array
    {
        $sitesTable = SitesTable::tableName();
        // An event belongs to user U if user_id=U OR site_id belongs to one of U's sites.
        $clauses = ["(a.user_id = %d OR a.site_id IN (SELECT id FROM {$sitesTable} WHERE user_id = %d))"];
        $args = [$userId, $userId];

        if ($siteId !== null) {
            $clauses[] = "a.site_id = %d";
            $args[] = $siteId;
        }
        if ($eventType !== null) {
            $clauses[] = "a.event_type = %s";
            $args[] = $eventType;
        }

        return ['WHERE ' . implode(' AND ', $clauses), $args];
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter ActivityLogRepositoryTest`
Expected: PASS (all 5 cases).

- [ ] **Step 6: Run full dashboard suite**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit`
Expected: 177 prior + 5 new = 182, all green.

- [ ] **Step 7: Commit**

```bash
git add packages/dashboard-plugin/src/Models/ActivityEvent.php \
        packages/dashboard-plugin/src/Services/ActivityLogRepository.php \
        packages/dashboard-plugin/tests/Integration/Services/ActivityLogRepositoryTest.php
git commit -m "F9: dashboard — ActivityEvent model + ActivityLogRepository (user-scoped paginated reads)"
```

---

### Task 2: Extend `ActivityLogger` with IP capture + delegate to repo

**Why:** F5's `ActivityLogger::log()` writes directly via `$wpdb->insert`. Now that there's a repository, the writer should delegate. Also adds the `?string $ipAddress` parameter so REST controllers can pass it through.

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/ActivityLogger.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/ActivityLoggerIpCaptureTest.php` (NEW)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ActivityLoggerIpCaptureTest extends AbstractSchemaTestCase
{
    public function testLogWithIpStoresIpAddress(): void
    {
        (new ActivityLogger())->log(1, 5, 'site.synced', ['wp_version' => '6.9.4'], '203.0.113.42');

        global $wpdb;
        $row = $wpdb->get_row("SELECT * FROM " . ActivityLogTable::tableName(), ARRAY_A);
        $this->assertSame('203.0.113.42', $row['ip_address']);
        $this->assertSame('site.synced', $row['event_type']);
    }

    public function testLogWithoutIpLeavesItNull(): void
    {
        (new ActivityLogger())->log(1, 5, 'site.health_ok');

        global $wpdb;
        $row = $wpdb->get_row("SELECT * FROM " . ActivityLogTable::tableName(), ARRAY_A);
        $this->assertNull($row['ip_address']);
    }
}
```

- [ ] **Step 2: Run test to verify failure**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter ActivityLoggerIpCaptureTest`
Expected: FAIL — `log()` doesn't accept the IP parameter yet.

- [ ] **Step 3: Modify `ActivityLogger`**

Replace `packages/dashboard-plugin/src/Services/ActivityLogger.php` body:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

/**
 * Thin writer for wp_defyn_activity_log. Delegates the actual SQL to
 * ActivityLogRepository (per repo pattern). F9 added the optional
 * $ipAddress argument — REST controllers populate it from
 * $_SERVER['REMOTE_ADDR']; AS jobs leave it null since background jobs
 * have no request context.
 */
final class ActivityLogger
{
    public function __construct(
        private readonly ?ActivityLogRepository $repo = null,
    ) {}

    public function log(?int $userId, ?int $siteId, string $eventType, ?array $details = null, ?string $ipAddress = null): void
    {
        $repo = $this->repo ?? new ActivityLogRepository();
        $repo->insert($userId, $siteId, $eventType, $details, $ipAddress);
    }
}
```

The OLD signature took 4 params. The new one adds a 5th optional `?string $ipAddress = null` so existing callers (`Connection::complete`) continue to work without modification.

- [ ] **Step 4: Run test to verify pass**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter ActivityLoggerIpCaptureTest`
Expected: PASS.

- [ ] **Step 5: Run full dashboard suite**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit`
Expected: 182 prior + 2 new = 184. All green. Existing F5 `Connection` tests still pass because the 5th param has a default.

- [ ] **Step 6: Commit**

```bash
git add packages/dashboard-plugin/src/Services/ActivityLogger.php \
        packages/dashboard-plugin/tests/Integration/Services/ActivityLoggerIpCaptureTest.php
git commit -m "F9: dashboard — ActivityLogger delegates to repo; accepts optional ipAddress"
```

---

### Task 3: Backfill writes — Sync/Health/Disconnect/Auth services

**Why:** F6 left TODO comments in `SyncService` and `HealthService` for the activity-log integration. F8's `DisconnectService` doesn't log. F3a's `AuthLoginController` doesn't log. Without these writes the activity feed is mostly empty.

**Event types:**
- `SyncService`: `site.synced` (success), `site.sync_failed` (any failure path)
- `HealthService`: `site.health_ok` (success), `site.health_failed` (failure → offline), `site.recovered` (offline → active recovery)
- `DisconnectService`: `site.disconnected` (after successful row delete)
- `AuthLoginController`: `auth.login` (success), `auth.login_failed` (bad creds)

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/SyncService.php`
- Modify: `packages/dashboard-plugin/src/Services/HealthService.php`
- Modify: `packages/dashboard-plugin/src/Services/DisconnectService.php`
- Modify: `packages/dashboard-plugin/src/Rest/AuthLoginController.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/ActivityBackfillTest.php` (NEW)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Services\DisconnectService;
use Defyn\Dashboard\Services\HealthService;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\SyncService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ActivityBackfillTest extends AbstractSchemaTestCase
{
    private function makeSite(int $userId = 1): int
    {
        $repo  = new SitesRepository();
        $vault = new Vault(DEFYN_VAULT_KEY);
        $priv  = base64_encode(random_bytes(64));
        $id    = $repo->insertPending($userId, 'https://site.test', 'Site', base64_encode(random_bytes(32)), $vault->encrypt($priv));
        $repo->markActive($id, base64_encode(random_bytes(32)));
        return $id;
    }

    private function lastEventType(): ?string
    {
        global $wpdb;
        return $wpdb->get_var("SELECT event_type FROM " . ActivityLogTable::tableName() . " ORDER BY id DESC LIMIT 1");
    }

    public function testSyncServiceLogsSiteSyncedOnSuccess(): void
    {
        $siteId = $this->makeSite();
        $mock = new MockHttpClient(fn() => new MockResponse(
            json_encode(['wp_version' => '6.9.4', 'php_version' => '8.2.27', 'active_theme' => null, 'plugin_counts' => null, 'theme_counts' => null, 'ssl_status' => 'enabled', 'ssl_expires_at' => null]),
            ['http_code' => 200]
        ));
        (new SyncService(new SignedHttpClient($mock)))->sync($siteId);
        $this->assertSame('site.synced', $this->lastEventType());
    }

    public function testSyncServiceLogsSiteSyncFailedOnTransportError(): void
    {
        $siteId = $this->makeSite();
        $mock = new MockHttpClient(function ($m, $u, $o) { throw new \Symfony\Component\HttpClient\Exception\TransportException('boom'); });
        (new SyncService(new SignedHttpClient($mock)))->sync($siteId);
        $this->assertSame('site.sync_failed', $this->lastEventType());
    }

    public function testHealthServiceLogsHealthOk(): void
    {
        $siteId = $this->makeSite();
        $mock = new MockHttpClient(fn() => new MockResponse(json_encode(['ok' => true, 'server_time' => time()]), ['http_code' => 200]));
        (new HealthService(new SignedHttpClient($mock)))->ping($siteId);
        $this->assertSame('site.health_ok', $this->lastEventType());
    }

    public function testHealthServiceLogsHealthFailedOnTransport(): void
    {
        $siteId = $this->makeSite();
        $mock = new MockHttpClient(function ($m, $u, $o) { throw new \Symfony\Component\HttpClient\Exception\TransportException('boom'); });
        (new HealthService(new SignedHttpClient($mock)))->ping($siteId);
        $this->assertSame('site.health_failed', $this->lastEventType());
    }

    public function testHealthServiceLogsRecoveredFromOffline(): void
    {
        $siteId = $this->makeSite();
        (new SitesRepository())->markOffline($siteId, 'was offline');
        $mock = new MockHttpClient(fn() => new MockResponse(json_encode(['ok' => true, 'server_time' => time()]), ['http_code' => 200]));
        (new HealthService(new SignedHttpClient($mock)))->ping($siteId);
        $this->assertSame('site.recovered', $this->lastEventType());
    }

    public function testDisconnectServiceLogsSiteDisconnected(): void
    {
        $siteId = $this->makeSite();
        $mock = new MockHttpClient(fn() => new MockResponse('', ['http_code' => 204]));
        (new DisconnectService(new SignedHttpClient($mock)))->disconnect($siteId, 1);
        $this->assertSame('site.disconnected', $this->lastEventType());
    }
}
```

- [ ] **Step 2: Run test to verify failure**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter ActivityBackfillTest`
Expected: FAIL — services don't log yet.

- [ ] **Step 3: Add logging to `SyncService`**

In `packages/dashboard-plugin/src/Services/SyncService.php`, inject `ActivityLogger` via constructor (optional with default like the existing deps), and add log calls:

- On the happy path AFTER `markSynced` -> `$logger->log($site->userId, $siteId, 'site.synced', ['wp_version' => $info['wp_version']])`
- On EACH failure path (transport error, non-2xx, malformed payload, decrypt failure) -> `$logger->log($site->userId, $siteId, 'site.sync_failed', ['error' => $message])`

Constructor signature gains a 3rd optional arg:

```php
public function __construct(
    private readonly SignedHttpClient $httpClient = new SignedHttpClient(),
    private readonly ?SitesRepository $repo = null,
    private readonly ?ActivityLogger $logger = null,
) {}
```

And inside `sync()`:

```php
$logger = $this->logger ?? new ActivityLogger();
// ... existing failure branches each get a $logger->log(...) call before return
// ... happy path: after markSynced, $logger->log(...)
```

- [ ] **Step 4: Add logging to `HealthService`**

Same pattern in `packages/dashboard-plugin/src/Services/HealthService.php`:

- After `markContactAt` -> `site.health_ok`
- After `markRecovered` -> `site.recovered`
- After `markOffline` (any failure branch) -> `site.health_failed`

- [ ] **Step 5: Add logging to `DisconnectService`**

In `packages/dashboard-plugin/src/Services/DisconnectService.php`:

- After `deleteForUser` returns true -> `$logger->log($userId, null, 'site.disconnected', ['url' => $site->url])`. **Note `$siteId` is now null** because the row no longer exists — the event records the disconnect via `details.url`.

- [ ] **Step 6: Add logging to `AuthLoginController`**

In `packages/dashboard-plugin/src/Rest/AuthLoginController.php`:

- On success (after JWT minted) -> `$logger->log($userId, null, 'auth.login', null, $_SERVER['REMOTE_ADDR'] ?? null)`
- On invalid_credentials -> `$logger->log(null, null, 'auth.login_failed', ['email' => $email], $_SERVER['REMOTE_ADDR'] ?? null)`

Don't log `auth.rate_limited` (the rate limit middleware short-circuits before the controller runs anyway).

- [ ] **Step 7: Run the test to verify pass**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter ActivityBackfillTest`
Expected: PASS (all 6 cases).

- [ ] **Step 8: Run full dashboard suite**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit`
Expected: 184 prior + 6 new = 190. All green.

**Watch**: existing `SyncServiceTest`, `HealthServiceTest`, `DisconnectServiceTest`, `ConnectionTest`, `AuthLoginTest` may have assertions on the activity log table being empty. If so, the new log writes will break them — update those fixtures to allow OR assert the expected event rows.

- [ ] **Step 9: Commit**

```bash
git add packages/dashboard-plugin/src/Services/SyncService.php \
        packages/dashboard-plugin/src/Services/HealthService.php \
        packages/dashboard-plugin/src/Services/DisconnectService.php \
        packages/dashboard-plugin/src/Rest/AuthLoginController.php \
        packages/dashboard-plugin/tests/Integration/Services/ActivityBackfillTest.php
# (also any existing test fixture updates)
git commit -m "F9: dashboard — backfill activity log writes (sync/health/disconnect/auth)"
```

---

### Task 4: `GET /defyn/v1/activity` REST endpoint (global feed)

**Why:** SPA's global Activity page reads from this endpoint. Bearer-authenticated. User-scoped via repository's `paginateForUser`. Query params: `page`, `per_page`, `event_type`, `site_id`.

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/ActivityListController.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/ActivityListTest.php` (NEW)

- [ ] **Step 1: Inspect existing REST controller patterns**

Read `packages/dashboard-plugin/src/Rest/SitesListController.php` (F5) — the user-scoped GET pattern is the same.

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Services\ActivityLogRepository;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ActivityListTest extends AbstractSchemaTestCase
{
    // Adapt to the dashboard's actual REST test pattern (rest_do_request +
    // _authenticated_user_id param — see SitesListTest for reference).
    // Required cases:
    //   - testReturnsUserScopedFeedNewestFirst
    //   - testFiltersByEventType
    //   - testFiltersBySiteId
    //   - testPaginationMetadataReturnsTotalAndPage
    //   - testUnauthenticatedReturns401
}
```

Use `SitesListTest.php` as the template for the auth + dispatch helper. Required assertions per case:

- **testReturnsUserScopedFeedNewestFirst**: insert 3 events (2 for user 1's site, 1 for user 2's site). Request as user 1 -> 200 + 2 events in `data.events`, newest first.
- **testFiltersByEventType**: insert events of multiple types for user 1. Request with `?event_type=site.synced` -> only matching events.
- **testFiltersBySiteId**: insert events on multiple sites for user 1. Request with `?site_id=N` -> only that site's events.
- **testPaginationMetadataReturnsTotalAndPage**: insert 10 events. Request `?per_page=3&page=2` -> 3 events + `data.total = 10` + `data.page = 2` + `data.per_page = 3`.
- **testUnauthenticatedReturns401**: no Bearer -> 401.

- [ ] **Step 3: Run test to verify failure**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter ActivityListTest`
Expected: FAIL — route doesn't exist.

- [ ] **Step 4: Create `ActivityListController`**

`packages/dashboard-plugin/src/Rest/ActivityListController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\ActivityLogRepository;
use WP_REST_Request;
use WP_REST_Response;

final class ActivityListController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId    = (int) $request->get_param('_authenticated_user_id');
        $page      = max(1, (int) ($request->get_param('page') ?? 1));
        $perPage   = (int) ($request->get_param('per_page') ?? 50);
        $eventType = $request->get_param('event_type');
        $siteId    = $request->get_param('site_id');

        $eventType = is_string($eventType) && $eventType !== '' ? $eventType : null;
        $siteId    = $siteId !== null && $siteId !== '' ? (int) $siteId : null;

        $repo   = new ActivityLogRepository();
        $events = $repo->paginateForUser($userId, $siteId, $eventType, $page, $perPage);
        $total  = $repo->countForUser($userId, $siteId, $eventType);

        return new WP_REST_Response([
            'events'   => array_map(fn($e) => $e->toJson(), $events),
            'total'    => $total,
            'page'     => $page,
            'per_page' => min($perPage, ActivityLogRepository::MAX_PER_PAGE),
        ], 200);
    }
}
```

- [ ] **Step 5: Register the route in `RestRouter`**

```php
register_rest_route(self::NAMESPACE, '/activity', [
    'methods'             => 'GET',
    'callback'            => [new ActivityListController(), 'handle'],
    'permission_callback' => [RequireAuth::class, 'check'],
]);
```

Mirror the style of `/sites` (GET) registration.

- [ ] **Step 6: Run test to verify pass**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter ActivityListTest`
Expected: PASS.

- [ ] **Step 7: Run full dashboard suite**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/ActivityListController.php \
        packages/dashboard-plugin/src/Rest/RestRouter.php \
        packages/dashboard-plugin/tests/Integration/Rest/ActivityListTest.php
git commit -m "F9: dashboard — GET /defyn/v1/activity (paginated, filterable, user-scoped)"
```

---

### Task 5: `GET /defyn/v1/sites/{id}/activity` (per-site feed)

**Why:** SiteDetail's "Recent activity" panel reads from this endpoint. User-scoped via site ownership (404 if not owner).

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/SitesActivityController.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SitesActivityTest.php` (NEW)

- [ ] **Step 1: Write the failing test**

Cases:
- `testOwnerSeesSiteEvents` — insert site + events; GET `/sites/{id}/activity` -> 200 + events array
- `testNonOwnerGets404` — different user's site -> 404 `sites.not_found`
- `testPagination` — `?per_page=2&page=1` -> 2 events
- `testUnauthenticatedReturns401`

Use `SitesShowTest` as the template for ownership-gate testing.

- [ ] **Step 2: Run test to verify failure**

Expected: FAIL — route doesn't exist.

- [ ] **Step 3: Create `SitesActivityController`**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogRepository;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

final class SitesActivityController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        // Ownership gate
        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $page    = max(1, (int) ($request->get_param('page') ?? 1));
        $perPage = (int) ($request->get_param('per_page') ?? 50);

        $repo   = new ActivityLogRepository();
        $events = $repo->paginateForUser($userId, $siteId, null, $page, $perPage);
        $total  = $repo->countForUser($userId, $siteId, null);

        return new WP_REST_Response([
            'events'   => array_map(fn($e) => $e->toJson(), $events),
            'total'    => $total,
            'page'     => $page,
            'per_page' => min($perPage, ActivityLogRepository::MAX_PER_PAGE),
        ], 200);
    }
}
```

- [ ] **Step 4: Register the route in `RestRouter`**

```php
register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/activity', [
    'methods'             => 'GET',
    'callback'            => [new SitesActivityController(), 'handle'],
    'permission_callback' => [RequireAuth::class, 'check'],
]);
```

- [ ] **Step 5-7: Run tests + commit**

```bash
git add packages/dashboard-plugin/src/Rest/SitesActivityController.php \
        packages/dashboard-plugin/src/Rest/RestRouter.php \
        packages/dashboard-plugin/tests/Integration/Rest/SitesActivityTest.php
git commit -m "F9: dashboard — GET /defyn/v1/sites/{id}/activity (per-site feed, user-scoped)"
```

---

### Task 6: SPA types + MSW for activity

**Why:** SPA's new Activity route + SiteDetail panel need types + mock handlers.

**Files:**
- Modify: `apps/web/src/types/api.ts`
- Modify: `apps/web/src/test/handlers.ts`

- [ ] **Step 1: Add Zod schemas to `apps/web/src/types/api.ts`**

```typescript
export const activityEventSchema = z.object({
  id: z.number().int().positive(),
  site_id: z.number().int().positive().nullable(),
  event_type: z.string(),
  details: z.record(z.unknown()).nullable(),
  created_at: z.string(),
});
export type ActivityEvent = z.infer<typeof activityEventSchema>;

export const activityListResponseSchema = z.object({
  events: z.array(activityEventSchema),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  per_page: z.number().int().positive(),
});
export type ActivityListResponse = z.infer<typeof activityListResponseSchema>;
```

- [ ] **Step 2: Add MSW handlers**

In `apps/web/src/test/handlers.ts`:

```typescript
// Mutable mock activity store (mirrors mockSites pattern)
export const mockActivityEvents: ActivityEvent[] = [];

export function resetMockActivity() {
  mockActivityEvents.length = 0;
}

export function seedMockActivity() {
  mockActivityEvents.push(
    { id: 1, site_id: 1, event_type: 'site.synced',    details: { wp_version: '6.9.4' }, created_at: '2026-05-31T01:00:00Z' },
    { id: 2, site_id: 1, event_type: 'site.health_ok', details: null,                    created_at: '2026-05-31T00:30:00Z' },
    { id: 3, site_id: 2, event_type: 'site.connected', details: { url: 'https://b.test' }, created_at: '2026-05-30T00:00:00Z' },
  );
}

// Handlers:
http.get('*/wp-json/defyn/v1/activity', ({ request }) => {
  const url = new URL(request.url);
  const eventType = url.searchParams.get('event_type');
  const siteId = url.searchParams.get('site_id');
  const page = Number(url.searchParams.get('page') ?? '1');
  const perPage = Number(url.searchParams.get('per_page') ?? '50');

  const filtered = mockActivityEvents.filter((e) =>
    (eventType === null || e.event_type === eventType) &&
    (siteId === null || e.site_id === Number(siteId))
  );
  const start = (page - 1) * perPage;
  const slice = filtered.slice(start, start + perPage);
  return HttpResponse.json({ events: slice, total: filtered.length, page, per_page: perPage });
}),

http.get('*/wp-json/defyn/v1/sites/:id/activity', ({ params }) => {
  const siteId = Number(params.id);
  const filtered = mockActivityEvents.filter((e) => e.site_id === siteId);
  return HttpResponse.json({ events: filtered, total: filtered.length, page: 1, per_page: 50 });
}),
```

- [ ] **Step 3: Run existing SPA suite**

```bash
cd apps/web && pnpm test
```
Expected: 53 prior tests + a few new (if Zod parse smoke is in api.test.ts) all green.

- [ ] **Step 4: Commit**

```bash
git add apps/web/src/types/api.ts apps/web/src/test/handlers.ts
git commit -m "F9: spa — activityEvent schema + MSW handlers for /activity endpoints"
```

---

### Task 7: SPA Activity route + filters

**Why:** New top-level page at `/activity` with event-type chips + site filter.

**Files:**
- Create: `apps/web/src/lib/queries/useActivity.ts`
- Create: `apps/web/src/routes/Activity.tsx`
- Create: `apps/web/src/components/activity/ActivityFilters.tsx`
- Create: `apps/web/src/components/activity/ActivityRow.tsx`
- Modify: `apps/web/src/App.tsx` (add the `/activity` route under `RequireAuth`)
- Modify: navigation (if any sidebar/header has site links)
- Test: `apps/web/tests/Activity.test.tsx` (NEW)

- [ ] **Step 1: Write failing tests**

```tsx
import { describe, it, expect, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { resetMockActivity, seedMockActivity } from '../src/test/handlers';
// import default-exported Activity route + render helper

describe('Activity route', () => {
  beforeEach(() => {
    resetMockActivity();
    seedMockActivity();
  });

  it('renders events newest-first', async () => {
    renderActivity();
    expect(await screen.findByText(/synced/i)).toBeInTheDocument();
    const rows = await screen.findAllByTestId('activity-row');
    expect(rows[0]).toHaveTextContent(/synced/i);
  });

  it('filters by event type chip', async () => {
    const user = userEvent.setup();
    renderActivity();
    await screen.findByText(/synced/i);
    await user.click(screen.getByRole('button', { name: /Health/i }));
    expect(screen.queryByText(/synced/i)).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify failure**

Expected: FAIL — route doesn't exist.

- [ ] **Step 3: Create `useActivity` query hook**

`apps/web/src/lib/queries/useActivity.ts`:

```typescript
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { activityListResponseSchema, type ActivityListResponse } from '@/types/api';

type Params = { page?: number; perPage?: number; eventType?: string | null; siteId?: number | null };

export function useActivity(params: Params = {}) {
  const { page = 1, perPage = 50, eventType = null, siteId = null } = params;
  return useQuery<ActivityListResponse>({
    queryKey: ['activity', page, perPage, eventType, siteId],
    queryFn: async () => {
      const qs = new URLSearchParams();
      qs.set('page', String(page));
      qs.set('per_page', String(perPage));
      if (eventType) qs.set('event_type', eventType);
      if (siteId !== null) qs.set('site_id', String(siteId));
      const data = await apiClient.get<unknown>(`/wp-json/defyn/v1/activity?${qs.toString()}`);
      return activityListResponseSchema.parse(data);
    },
  });
}
```

- [ ] **Step 4: Create components**

`apps/web/src/components/activity/ActivityRow.tsx`:

```tsx
import type { ActivityEvent } from '@/types/api';

type Props = { event: ActivityEvent };

export function ActivityRow({ event }: Props) {
  return (
    <div data-testid="activity-row" className="flex items-start gap-3 py-3 border-b">
      <div className="text-xs text-muted-foreground w-44 shrink-0">{event.created_at}</div>
      <div className="font-mono text-sm w-48 shrink-0">{event.event_type}</div>
      <div className="flex-1 text-sm text-muted-foreground">
        {event.details ? JSON.stringify(event.details) : null}
      </div>
    </div>
  );
}
```

`apps/web/src/components/activity/ActivityFilters.tsx`:

```tsx
import { Button } from '@/components/ui/button';

type EventFilter = 'all' | 'site' | 'health' | 'sync' | 'auth';

type Props = {
  filter: EventFilter;
  setFilter: (f: EventFilter) => void;
};

const FILTERS: Array<{ key: EventFilter; label: string }> = [
  { key: 'all',    label: 'All' },
  { key: 'site',   label: 'Connections' },
  { key: 'sync',   label: 'Syncs' },
  { key: 'health', label: 'Health' },
  { key: 'auth',   label: 'Auth' },
];

export function ActivityFilters({ filter, setFilter }: Props) {
  return (
    <div className="flex flex-wrap gap-2 mb-4">
      {FILTERS.map(({ key, label }) => (
        <Button
          key={key}
          variant={filter === key ? 'default' : 'outline'}
          size="sm"
          onClick={() => setFilter(key)}
        >
          {label}
        </Button>
      ))}
    </div>
  );
}
```

- [ ] **Step 5: Create the route**

`apps/web/src/routes/Activity.tsx`:

```tsx
import { useState, useMemo } from 'react';
import { useActivity } from '@/lib/queries/useActivity';
import { ActivityFilters } from '@/components/activity/ActivityFilters';
import { ActivityRow } from '@/components/activity/ActivityRow';

type EventFilter = 'all' | 'site' | 'health' | 'sync' | 'auth';

export default function Activity() {
  const [filter, setFilter] = useState<EventFilter>('all');
  const { data, isLoading, error } = useActivity({ page: 1, perPage: 100 });

  const filtered = useMemo(() => {
    if (!data) return [];
    if (filter === 'all') return data.events;
    return data.events.filter((e) => {
      if (filter === 'site')   return e.event_type.startsWith('site.connect') || e.event_type === 'site.disconnected';
      if (filter === 'sync')   return e.event_type.startsWith('site.sync');
      if (filter === 'health') return e.event_type.startsWith('site.health') || e.event_type === 'site.recovered';
      if (filter === 'auth')   return e.event_type.startsWith('auth.');
      return true;
    });
  }, [data, filter]);

  if (isLoading) return <p>Loading…</p>;
  if (error) return <p>Failed to load activity.</p>;

  return (
    <div>
      <h1 className="text-xl font-semibold mb-4">Activity</h1>
      <ActivityFilters filter={filter} setFilter={setFilter} />
      {filtered.length === 0 ? (
        <p className="text-sm text-muted-foreground">No events match your filters.</p>
      ) : (
        <div className="border-t">
          {filtered.map((e) => <ActivityRow key={e.id} event={e} />)}
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 6: Wire the route into `App.tsx`**

Add inside the `RequireAuth` section:

```tsx
import Activity from '@/routes/Activity';
// ...
<Route path="/activity" element={<Activity />} />
```

If there's a navigation/sidebar, add an "Activity" link too.

- [ ] **Step 7: Run tests to verify pass**

```bash
cd apps/web && pnpm test
```
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add apps/web/src/lib/queries/useActivity.ts \
        apps/web/src/routes/Activity.tsx \
        apps/web/src/components/activity/ActivityFilters.tsx \
        apps/web/src/components/activity/ActivityRow.tsx \
        apps/web/src/App.tsx \
        apps/web/tests/Activity.test.tsx
git commit -m "F9: spa — /activity route with event-type chips + paginated feed"
```

---

### Task 8: Per-site activity panel on `SiteDetail`

**Why:** SPA's site detail page surfaces the last ~10 events for the site, below the runtime info and action buttons.

**Files:**
- Create: `apps/web/src/lib/queries/useSiteActivity.ts`
- Create: `apps/web/src/components/sites/SiteActivityPanel.tsx`
- Modify: `apps/web/src/routes/SiteDetail.tsx`
- Test: `apps/web/tests/SiteDetail.test.tsx` (extend)

- [ ] **Step 1: Inspect existing `useSite` for the query-key pattern**

Read `apps/web/src/lib/queries/useSite.ts` to mirror the style (queryKey shape, `apiClient.get`, Zod parse).

- [ ] **Step 2: Write failing tests**

```tsx
describe('SiteDetail activity panel', () => {
  beforeEach(() => {
    resetMockActivity();
    seedMockActivity();
    // Also seed a site fixture with id=1
  });

  it('shows recent events for the site', async () => {
    renderSiteDetail('/sites/1');
    expect(await screen.findByText(/Recent activity/i)).toBeInTheDocument();
    expect(await screen.findByText(/synced/i)).toBeInTheDocument();
  });

  it('shows empty state when no events for this site', async () => {
    resetMockActivity();
    renderSiteDetail('/sites/1');
    expect(await screen.findByText(/No activity yet/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 3: Create `useSiteActivity`**

```typescript
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { activityListResponseSchema } from '@/types/api';

export function useSiteActivity(siteId: number) {
  return useQuery({
    queryKey: ['site', siteId, 'activity'],
    queryFn: async () => {
      const data = await apiClient.get<unknown>(`/wp-json/defyn/v1/sites/${siteId}/activity?per_page=10`);
      return activityListResponseSchema.parse(data);
    },
  });
}
```

- [ ] **Step 4: Create `SiteActivityPanel`**

```tsx
import type { Site } from '@/types/api';
import { useSiteActivity } from '@/lib/queries/useSiteActivity';
import { ActivityRow } from '@/components/activity/ActivityRow';

type Props = { site: Site };

export function SiteActivityPanel({ site }: Props) {
  const { data, isLoading } = useSiteActivity(site.id);

  return (
    <section className="mt-8">
      <h2 className="text-lg font-semibold mb-3">Recent activity</h2>
      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : data && data.events.length > 0 ? (
        <div className="border-t">
          {data.events.map((e) => <ActivityRow key={e.id} event={e} />)}
        </div>
      ) : (
        <p className="text-sm text-muted-foreground">No activity yet — events will appear after the first sync or ping.</p>
      )}
    </section>
  );
}
```

- [ ] **Step 5: Wire into `SiteDetail.tsx`**

Render `<SiteActivityPanel site={data} />` below `<SiteActions site={data} />`. Gate by status !== 'pending' (no events for sites that haven't completed handshake).

- [ ] **Step 6: Run tests + commit**

```bash
git add apps/web/src/lib/queries/useSiteActivity.ts \
        apps/web/src/components/sites/SiteActivityPanel.tsx \
        apps/web/src/routes/SiteDetail.tsx \
        apps/web/tests/SiteDetail.test.tsx
git commit -m "F9: spa — SiteDetail recent activity panel"
```

---

### Task 9: README + smoke + merge

- [ ] **Step 1: Update dashboard README**

Add to the REST table:

| GET | `/activity` | (F9) → 200 `{events: [...], total, page, per_page}`. User-scoped. Supports `?event_type=X&site_id=N&page=1&per_page=50` filters; per_page max 200. |
| GET | `/sites/{id}/activity` | (F9) → 200 `{events, total, page, per_page}`. Per-site, user-scoped (404 `sites.not_found` if not owner). |

Document the F9 event types and the `ip_address` capture behavior.

- [ ] **Step 2: Programmatic smoke**

Write `/tmp/f9-smoke.php` that:
1. Bootstraps WP via `wp-load.php`
2. Inserts a fresh site row (since F8 smoke tore down the F5 fixture)
3. Marks it active
4. Invokes `(new SyncService())->sync($siteId)` (will fail because there's no live connector, but should log `site.sync_failed`)
5. Invokes `(new HealthService())->ping($siteId)` (same — logs `site.health_failed` or `site.recovered`)
6. Calls `(new ActivityLogRepository())->paginateForUser(1, null, null, 1, 10)` and asserts the new events appear in the list, newest-first
7. Reports OK

- [ ] **Step 3: Run all three test suites**

```bash
cd packages/dashboard-plugin && vendor/bin/phpunit
cd packages/connector-plugin && vendor/bin/phpunit
cd apps/web && pnpm test
```
Expected: all green.

- [ ] **Step 4: Push + PR + merge + tag**

```bash
git push -u origin f9-activity-log
gh pr create --title "F9: Activity log" --body "..."
gh pr merge --merge --delete-branch
git tag -a f9-activity-log-complete -m "F9: Activity log complete"
git push origin f9-activity-log-complete
```

- [ ] **Step 5: Update memory + clean up /tmp**

---

## Self-Review Checklist

- [ ] Spec § 11 F9 row covered: endpoints + SPA page + per-site activity
- [ ] All four services (Sync/Health/Disconnect/Auth) backfilled
- [ ] No placeholders / TBD anywhere
- [ ] User-scope contract: events for other users' sites NEVER surface in this user's feed
- [ ] Repository pattern preserved: all SQL via ActivityLogRepository
- [ ] Method names consistent (`paginateForUser`, `countForUser`, `useActivity`, `useSiteActivity`)
- [ ] `ip_address` capture documented; AS-originated events leave null
- [ ] `ActivityEvent::toJson()` hides `user_id` and `ip_address` (operator-only)
