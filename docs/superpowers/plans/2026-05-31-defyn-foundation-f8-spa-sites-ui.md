# F8 — SPA Sites UI Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Polish the SPA sites pages: list-page filter chips + search, detail-page cached runtime info display, and three action buttons (Refresh, Ping, Disconnect). Close two pre-existing API contract gaps along the way: `Site::toJson()` must now expose the F6/F7 runtime fields, and `siteStatusSchema` must include the `offline` status added in F6.

**Architecture:** Backend gets one new REST endpoint (`DELETE /defyn/v1/sites/{id}` for soft Disconnect — signed connector tear-down + dashboard row delete) and an expanded `Site::toJson()` shape. SPA gains a filter/search component on SitesList, a runtime-info section on SiteDetail, three action buttons with toast feedback, and polling that extends F5's pending-poll pattern to also watch `last_sync_at` advance after a Refresh click.

**Tech Stack:** PHP 8.1+, libsodium, PHPUnit (backend). React 18 + TypeScript + Vite + TanStack Query v5 + React Hook Form + Zod + shadcn/ui + Tailwind + Vitest + Testing Library + MSW (SPA). No new dependencies.

**Spec source:** `docs/superpowers/specs/2026-04-18-defyn-foundation-design.md` — § 11 (F8 deliverable scope: filter + search, cached info display, Refresh/Ping/Disconnect buttons).

**Branch:** Off main as `f8-spa-sites-ui`. Last shipped: F7 merge `75a76b0`.

**Design decisions (locked):**
- **Disconnect = soft disconnect**: sign `POST /disconnect` to connector, then DELETE the dashboard `wp_defyn_sites` row. Bilateral clean break. If the connector call fails (transport / 4xx / 5xx), the dashboard row is STILL deleted so the operator isn't stuck with an unrecoverable state.
- **List cards = minimal**: URL, label, status badge. Runtime info lives on the detail page.
- **Filter UX = status chips with counts + text search input** matching URL OR label substring.
- **Action UX = toast + "Syncing…" indicator + 2s polling**: action click triggers `POST /sync` (or `/ping`); button shows spinner labeled "Syncing…"; `useSite` extends F5's poll-while-pending to also poll until `last_sync_at` exceeds the click timestamp (cap at 60s).

---

### Task 1: Expand `Site::toJson()` + document `offline` status

**Why:** F6/F7 added 8 runtime properties to the `Site` model but `toJson()` (the SPA-facing shape) still returns only the F5 fields. SPA's `siteSchema` Zod also only knows about `pending|active|error` — needs `offline`. F8 detail-page features require all of these on the wire.

**Files:**
- Modify: `packages/dashboard-plugin/src/Models/Site.php` (extend `toJson()`)
- Test: `packages/dashboard-plugin/tests/Unit/Models/SiteToJsonF8Test.php` (NEW)

**Important:** the existing `toJson()` test (F5) asserts that `user_id`, `our_public_key`, `our_private_key` are HIDDEN. F8 must preserve that. Read the existing test first and confirm none of those forbidden fields creep in.

- [ ] **Step 1: Write the failing test**

Create `packages/dashboard-plugin/tests/Unit/Models/SiteToJsonF8Test.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\Site;
use PHPUnit\Framework\TestCase;

final class SiteToJsonF8Test extends TestCase
{
    public function testToJsonExposesAllRuntimeInfo(): void
    {
        $site = Site::fromRow([
            'id'              => 7,
            'user_id'         => 1,
            'url'             => 'https://x.test',
            'label'           => 'X',
            'status'          => 'active',
            'our_public_key'  => 'pub',
            'our_private_key' => 'priv-cipher',
            'site_public_key' => 'sitepub',
            'last_contact_at' => '2026-05-31 00:00:01',
            'last_sync_at'    => '2026-05-31 00:00:02',
            'last_error'      => '',
            'created_at'      => '2026-05-01 00:00:00',
            'updated_at'      => '2026-05-31 00:00:02',
            'wp_version'      => '6.9.4',
            'php_version'     => '8.2.27',
            'active_theme'    => '{"name":"Twenty Twenty-Four","version":"1.0","parent":null}',
            'plugin_counts'   => '{"installed":12,"active":8}',
            'theme_counts'    => '{"installed":3,"active":1}',
            'ssl_status'      => 'enabled',
            'ssl_expires_at'  => '2027-01-01 00:00:00',
        ]);

        $json = $site->toJson();

        // F5 fields preserved
        $this->assertSame(7, $json['id']);
        $this->assertSame('https://x.test', $json['url']);
        $this->assertSame('active', $json['status']);
        $this->assertSame('2026-05-31 00:00:01', $json['last_contact_at']);

        // F8 NEW fields
        $this->assertSame('2026-05-31 00:00:02', $json['last_sync_at']);
        $this->assertSame('6.9.4', $json['wp_version']);
        $this->assertSame('8.2.27', $json['php_version']);
        $this->assertSame(['name' => 'Twenty Twenty-Four', 'version' => '1.0', 'parent' => null], $json['active_theme']);
        $this->assertSame(['installed' => 12, 'active' => 8], $json['plugin_counts']);
        $this->assertSame(['installed' => 3, 'active' => 1], $json['theme_counts']);
        $this->assertSame('enabled', $json['ssl_status']);
        $this->assertSame('2027-01-01 00:00:00', $json['ssl_expires_at']);

        // Sensitive fields STILL hidden
        $this->assertArrayNotHasKey('user_id', $json);
        $this->assertArrayNotHasKey('our_public_key', $json);
        $this->assertArrayNotHasKey('our_private_key', $json);
        $this->assertArrayNotHasKey('site_public_key', $json);
    }

    public function testOfflineStatusPassesThrough(): void
    {
        $site = Site::fromRow([
            'id'              => 8,
            'user_id'         => 1,
            'url'             => 'https://y.test',
            'label'           => 'Y',
            'status'          => 'offline',
            'our_public_key'  => 'pub',
            'our_private_key' => 'priv',
            'site_public_key' => 'sitepub',
            'last_contact_at' => null,
            'last_sync_at'    => null,
            'last_error'      => 'host unreachable',
            'created_at'      => '2026-05-31 00:00:00',
            'updated_at'      => '2026-05-31 00:00:00',
            'wp_version'      => null,
            'php_version'     => null,
            'active_theme'    => null,
            'plugin_counts'   => null,
            'theme_counts'    => null,
            'ssl_status'      => null,
            'ssl_expires_at'  => null,
        ]);

        $this->assertSame('offline', $site->toJson()['status']);
    }
}
```

If `Site::fromRow` differs in column-list expectations, adapt the fixture to match the actual schema columns (read `packages/dashboard-plugin/src/Models/Site.php` first to confirm).

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter SiteToJsonF8Test`
Expected: FAIL — new fields aren't in `toJson()` output.

- [ ] **Step 3: Modify `Site::toJson()`**

In `packages/dashboard-plugin/src/Models/Site.php`, expand `toJson()` to include the new fields:

```php
public function toJson(): array
{
    return [
        'id'              => $this->id,
        'url'             => $this->url,
        'label'           => $this->label,
        'status'          => $this->status,
        'last_contact_at' => $this->lastContactAt,
        'last_sync_at'    => $this->lastSyncAt,
        'last_error'      => $this->lastError,
        'created_at'      => $this->createdAt,
        // F8: expose F6/F7 runtime info to the SPA
        'wp_version'      => $this->wpVersion,
        'php_version'     => $this->phpVersion,
        'active_theme'    => $this->activeTheme,
        'plugin_counts'   => $this->pluginCounts,
        'theme_counts'    => $this->themeCounts,
        'ssl_status'      => $this->sslStatus,
        'ssl_expires_at'  => $this->sslExpiresAt,
    ];
}
```

Update the class-level docblock's "toJson() intentionally still hides..." note to reflect the F8 additions.

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter SiteToJsonF8Test`
Expected: PASS (both cases).

- [ ] **Step 5: Run full dashboard suite to check for regressions**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit`
Expected: 166 prior passing + 2 new = 168 (or close). All green. **Watch for the F5 SitesListTest or SitesShowTest** — if those tests assert the EXACT toJson shape, they will need updates to include the new fields. Update fixtures, not the controller.

- [ ] **Step 6: Commit**

```bash
git add packages/dashboard-plugin/src/Models/Site.php \
        packages/dashboard-plugin/tests/Unit/Models/SiteToJsonF8Test.php
# (also add any existing-test fixtures you updated)
git commit -m "F8: dashboard — expand Site::toJson with F6/F7 runtime fields (wp_version, theme, ssl, last_sync_at)"
```

---

### Task 2: `SitesRepository::deleteForUser`

**Why:** Disconnect needs to physically delete the `wp_defyn_sites` row after the connector tear-down. Per the repository pattern (F1), all SQL writes go through `SitesRepository`. User-scoped guard prevents an attacker with a known ID from deleting someone else's site.

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/SitesRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryDeleteForUserTest.php` (NEW)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitesRepositoryDeleteForUserTest extends AbstractSchemaTestCase
{
    public function testOwnerCanDelete(): void
    {
        $repo = new SitesRepository();
        $id = $repo->insertPending(42, 'https://a.test', 'A', base64_encode(random_bytes(32)), 'cipher');

        $this->assertTrue($repo->deleteForUser($id, 42));
        $this->assertNull($repo->findById($id));
    }

    public function testNonOwnerCannotDelete(): void
    {
        $repo = new SitesRepository();
        $id = $repo->insertPending(42, 'https://a.test', 'A', base64_encode(random_bytes(32)), 'cipher');

        // Different user id — must NOT delete
        $this->assertFalse($repo->deleteForUser($id, 99));
        $this->assertNotNull($repo->findById($id));
    }

    public function testMissingSiteReturnsFalse(): void
    {
        $repo = new SitesRepository();
        $this->assertFalse($repo->deleteForUser(999999, 42));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter SitesRepositoryDeleteForUserTest`
Expected: FAIL — method doesn't exist.

- [ ] **Step 3: Add `deleteForUser` to `SitesRepository`**

In `packages/dashboard-plugin/src/Services/SitesRepository.php`, ADD alongside existing methods:

```php
/**
 * User-scoped delete. Returns true if a row was deleted (caller is the owner),
 * false if not found OR not owned. Caller must NOT echo "deleted" on false —
 * use the same 404 envelope as an unowned-site lookup.
 */
public function deleteForUser(int $id, int $userId): bool
{
    global $wpdb;
    $affected = $wpdb->delete(
        SitesTable::tableName(),
        ['id' => $id, 'user_id' => $userId],
        ['%d', '%d']
    );
    return (int) $affected === 1;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter SitesRepositoryDeleteForUserTest`
Expected: PASS (all 3 cases).

- [ ] **Step 5: Run full dashboard suite**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add packages/dashboard-plugin/src/Services/SitesRepository.php \
        packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryDeleteForUserTest.php
git commit -m "F8: dashboard — SitesRepository::deleteForUser (user-scoped row delete)"
```

---

### Task 3: `DisconnectService`

**Why:** Orchestrates the soft-disconnect: load site → decrypt private key → signed POST to connector's `/disconnect` → delete the dashboard row. Per the locked design call, the row is deleted EVEN IF the connector call fails (so the operator isn't stuck with an unrecoverable state when the managed site is offline / WP plugin disabled).

**Files:**
- Create: `packages/dashboard-plugin/src/Services/DisconnectService.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/DisconnectServiceTest.php` (NEW)

- [ ] **Step 1: Inspect prerequisites**

- Read `packages/dashboard-plugin/src/Services/SyncService.php` for the established orchestration pattern (Vault decrypt → signed call → repo update).
- Read `packages/dashboard-plugin/src/Http/SignedHttpClient.php` — `signedPostJson($url, $body, $privateKeyBase64, $canonicalPath)` is the method that returns `['status' => int, 'body' => array, 'error' => string]`. For the connector's `/disconnect` endpoint, the body is empty `[]`.
- Connector path: `/defyn-connector/v1/disconnect`. Full URL: `rtrim($site->url, '/') . '/wp-json/defyn-connector/v1/disconnect'`.

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Services\DisconnectService;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DisconnectServiceTest extends AbstractSchemaTestCase
{
    private function makeActiveSite(): int
    {
        $repo  = new SitesRepository();
        $vault = new Vault(DEFYN_VAULT_KEY);
        $priv  = base64_encode(random_bytes(64));
        $id    = $repo->insertPending(1, 'https://site.test', 'Site', base64_encode(random_bytes(32)), $vault->encrypt($priv));
        $repo->markActive($id, base64_encode(random_bytes(32)));
        return $id;
    }

    public function testSuccessfulConnectorCallDeletesRow(): void
    {
        $siteId = $this->makeActiveSite();

        $mock = new MockHttpClient(fn() => new MockResponse('', ['http_code' => 204]));
        (new DisconnectService(new SignedHttpClient($mock)))->disconnect($siteId, 1);

        $this->assertNull((new SitesRepository())->findById($siteId));
    }

    public function testConnectorTransportFailureStillDeletesRow(): void
    {
        $siteId = $this->makeActiveSite();

        $mock = new MockHttpClient(function ($method, $url, $options) {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('host unreachable');
        });
        (new DisconnectService(new SignedHttpClient($mock)))->disconnect($siteId, 1);

        // Soft-disconnect design: row deleted regardless of connector outcome.
        $this->assertNull((new SitesRepository())->findById($siteId));
    }

    public function testNonOwnerCannotDisconnect(): void
    {
        $siteId = $this->makeActiveSite();

        $mock = new MockHttpClient(fn() => new MockResponse('', ['http_code' => 204]));
        $deleted = (new DisconnectService(new SignedHttpClient($mock)))->disconnect($siteId, 999);

        $this->assertFalse($deleted);
        $this->assertNotNull((new SitesRepository())->findById($siteId));
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter DisconnectServiceTest`
Expected: FAIL — class doesn't exist.

- [ ] **Step 4: Create `DisconnectService`**

`packages/dashboard-plugin/src/Services/DisconnectService.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;

/**
 * Soft disconnect: sign POST /disconnect to the connector, then DELETE the
 * dashboard wp_defyn_sites row. The row is deleted regardless of connector
 * outcome — an offline/broken connector must not strand the operator.
 *
 * User-scoped: returns false (without touching anything) if the caller is
 * not the owner of $siteId. Returns true on a successful row delete.
 */
final class DisconnectService
{
    public function __construct(
        private readonly SignedHttpClient $httpClient = new SignedHttpClient(),
        private readonly ?SitesRepository $repo = null,
    ) {}

    public function disconnect(int $siteId, int $userId): bool
    {
        $repo = $this->repo ?? new SitesRepository();
        $site = $repo->findByIdForUser($siteId, $userId);
        if ($site === null) {
            return false;  // 404 envelope at the controller layer
        }

        // Best-effort connector tear-down. Failures are NOT fatal — the row
        // gets deleted either way. Operators can recover by re-adding the
        // site with a new code; the connector's stale dashboard_public_key
        // will be replaced on the next handshake.
        try {
            $privateKey = (new Vault(DEFYN_VAULT_KEY))->decrypt($site->ourPrivateKey);
            $this->httpClient->signedPostJson(
                rtrim($site->url, '/') . '/wp-json/defyn-connector/v1/disconnect',
                [],
                $privateKey,
                '/defyn-connector/v1/disconnect'
            );
        } catch (\Throwable $e) {
            // Swallow — soft disconnect proceeds regardless.
        }

        return $repo->deleteForUser($siteId, $userId);
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter DisconnectServiceTest`
Expected: PASS (all 3 cases).

- [ ] **Step 6: Run full dashboard suite**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add packages/dashboard-plugin/src/Services/DisconnectService.php \
        packages/dashboard-plugin/tests/Integration/Services/DisconnectServiceTest.php
git commit -m "F8: dashboard — DisconnectService (signed POST /disconnect to connector + row delete; failure-tolerant)"
```

---

### Task 4: `DELETE /defyn/v1/sites/{id}` REST endpoint

**Why:** SPA-callable endpoint that invokes `DisconnectService::disconnect`. Bearer-authenticated, user-scoped via the service. Returns 204 on success, 404 envelope on not-found/not-owner.

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/SitesDeleteController.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SitesDeleteTest.php` (NEW)

- [ ] **Step 1: Inspect prerequisites**

Read `packages/dashboard-plugin/src/Rest/SitesShowController.php` and `SitesSyncController.php` (F6) for the established controller pattern (extract `_authenticated_user_id` + `id` params; delegate to service; return WP_REST_Response with status code). The REST router registration style mirrors F6's `/sync` and `/ping` routes.

- [ ] **Step 2: Write the failing test**

Adapt to whatever test scaffolding the dashboard's existing REST tests use (likely `AbstractSchemaTestCase` + an inline `do_request` helper since F6 implementer found `AuthenticatedRestTestCase` wasn't present and had to adapt).

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitesDeleteTest extends AbstractSchemaTestCase
{
    public function testOwnerCanDeleteOwnSite(): void
    {
        // [Adapt test fixture to project's actual REST test pattern.]
        // Insert a site owned by the authenticated user; DELETE; assert 204; assert row gone.
    }

    public function testNonOwnerGets404(): void
    {
        // [Insert site owned by a different user; DELETE as authenticated user;
        //  assert 404 + 'sites.not_found' envelope; assert row still present.]
    }
}
```

**Important:** check `SitesSyncTest.php` (F6) for the exact pattern of authenticated REST tests in this project. Copy that fixture style verbatim and just swap the method to DELETE.

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter SitesDeleteTest`
Expected: FAIL — route doesn't exist (404 `rest_no_route`).

- [ ] **Step 4: Create `SitesDeleteController`**

`packages/dashboard-plugin/src/Rest/SitesDeleteController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\DisconnectService;
use WP_REST_Request;
use WP_REST_Response;

final class SitesDeleteController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        $deleted = (new DisconnectService())->disconnect($siteId, $userId);
        if (!$deleted) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        return new WP_REST_Response(null, 204);
    }
}
```

- [ ] **Step 5: Register the route in `RestRouter`**

The existing `GET /sites/(?P<id>\d+)` route (SitesShowController) is already registered at the same path. WP REST allows multiple methods on the same path — use the combined-methods syntax to add DELETE alongside GET:

```php
register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)', [
    [
        'methods'             => 'GET',
        'callback'            => [new SitesShowController(), 'handle'],
        'permission_callback' => [RequireAuth::class, 'check'],
    ],
    [
        'methods'             => 'DELETE',
        'callback'            => [new SitesDeleteController(), 'handle'],
        'permission_callback' => [RequireAuth::class, 'check'],
    ],
]);
```

Replace the existing single-method GET registration with this combined-methods block. If the existing F6 routes (`/sync`, `/ping`) used a different style, mirror that — consistency over the plan's exact code.

- [ ] **Step 6: Run the test to verify it passes**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter SitesDeleteTest`
Expected: PASS (both cases).

- [ ] **Step 7: Run full dashboard suite**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/SitesDeleteController.php \
        packages/dashboard-plugin/src/Rest/RestRouter.php \
        packages/dashboard-plugin/tests/Integration/Rest/SitesDeleteTest.php
git commit -m "F8: dashboard — DELETE /defyn/v1/sites/{id} (soft Disconnect REST endpoint)"
```

---

### Task 5: SPA types update (Zod schema + MSW)

**Why:** SPA's `siteSchema` and `siteStatusSchema` are stale. Need to add `offline` to the status enum + add 7 new optional runtime fields. MSW handlers must return the new shape too (otherwise SPA tests can't exercise the new UI).

**Files:**
- Modify: `apps/web/src/types/api.ts`
- Modify: `apps/web/src/test/handlers.ts` (or wherever the MSW handlers live)

- [ ] **Step 1: Update Zod schemas**

In `apps/web/src/types/api.ts`:

```typescript
export const siteStatusSchema = z.enum(['pending', 'active', 'error', 'offline']);
export type SiteStatus = z.infer<typeof siteStatusSchema>;

export const activeThemeSchema = z.object({
  name: z.string(),
  version: z.string(),
  parent: z.string().nullable(),
}).nullable();

export const siteCountsSchema = z.object({
  installed: z.number().int().nonnegative(),
  active: z.number().int().nonnegative(),
}).nullable();

export const siteSchema = z.object({
  id: z.number().int().positive(),
  url: z.string().url(),
  label: z.string(),
  status: siteStatusSchema,
  last_contact_at: z.string().nullable(),
  last_sync_at: z.string().nullable(),
  last_error: z.string().nullable(),
  created_at: z.string(),
  wp_version: z.string().nullable(),
  php_version: z.string().nullable(),
  active_theme: activeThemeSchema,
  plugin_counts: siteCountsSchema,
  theme_counts: siteCountsSchema,
  ssl_status: z.string().nullable(),
  ssl_expires_at: z.string().nullable(),
});
export type Site = z.infer<typeof siteSchema>;
```

- [ ] **Step 2: Update MSW handlers**

Find `apps/web/src/test/handlers.ts`. Extend the `mockSites` entries to include the new fields:

```typescript
const mockSites: Site[] = [
  {
    id: 1,
    url: 'https://example.test',
    label: 'Example',
    status: 'active',
    last_contact_at: '2026-05-31T00:00:00Z',
    last_sync_at: '2026-05-31T00:00:00Z',
    last_error: null,
    created_at: '2026-05-01T00:00:00Z',
    wp_version: '6.9.4',
    php_version: '8.2.27',
    active_theme: { name: 'Twenty Twenty-Four', version: '1.0', parent: null },
    plugin_counts: { installed: 10, active: 5 },
    theme_counts: { installed: 2, active: 1 },
    ssl_status: 'enabled',
    ssl_expires_at: '2027-01-01T00:00:00Z',
  },
  // Add at least one site of each status (offline, error, pending) so the
  // Task 6 chip-count tests have meaningful fixtures.
];
```

Also add a NEW MSW handler for `DELETE /sites/:id` returning 204. Keep existing handlers intact.

- [ ] **Step 3: Run the existing SPA suite**

Run: `cd apps/web && pnpm test --run` (or whichever runner — check `package.json`).
Expected: 38 (F5 count) prior tests still pass. Some may fail if they asserted the exact `siteSchema` shape — update fixtures.

- [ ] **Step 4: Commit**

```bash
git add apps/web/src/types/api.ts \
        apps/web/src/test/handlers.ts
# (any other test files that needed fixture updates)
git commit -m "F8: spa — expand siteSchema with runtime fields; add 'offline' status; extend MSW handlers"
```

---

### Task 6: SitesList — status filter chips + search input

**Why:** Operators with 10+ sites need to filter quickly. Status pills (with counts) + URL/label substring search.

**Files:**
- Modify: `apps/web/src/routes/SitesList.tsx`
- Create: `apps/web/src/components/sites/SitesListFilters.tsx` (NEW)
- Test: `apps/web/src/routes/SitesList.test.tsx` (extend OR new test file)

- [ ] **Step 1: Inspect existing SitesList**

Read the current `apps/web/src/routes/SitesList.tsx` — note its structure. The F8 changes:
- Lift filter + search state into the route component (`useState` for `statusFilter: SiteStatus | 'all'` and `query: string`)
- Compute counts per status from the full sites array
- Pass filtered+searched sites to the card rendering loop
- Extract filter UI into a new component

- [ ] **Step 2: Write failing tests**

Extend `SitesList.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '../test/render';
import { SitesList } from './SitesList';

describe('SitesList filters', () => {
  it('renders status chips with counts', async () => {
    renderWithProviders(<SitesList />);
    expect(await screen.findByRole('button', { name: /All \(2\)/i })).toBeInTheDocument();
    // Adapt counts to whatever MSW fixtures hold after Task 5
  });

  it('filters by status chip', async () => {
    const user = userEvent.setup();
    renderWithProviders(<SitesList />);
    await screen.findByText(/example/i);
    await user.click(screen.getByRole('button', { name: /Offline/i }));
    expect(screen.queryByText(/example/i)).not.toBeInTheDocument();
  });

  it('filters by URL OR label search', async () => {
    const user = userEvent.setup();
    renderWithProviders(<SitesList />);
    await screen.findByText(/example/i);
    await user.type(screen.getByPlaceholderText(/search/i), 'example');
    expect(screen.getByText(/example/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 3: Run tests to verify failure**

Run: `cd apps/web && pnpm test --run -- SitesList`
Expected: FAIL — filter UI doesn't exist.

- [ ] **Step 4: Create `SitesListFilters` component**

`apps/web/src/components/sites/SitesListFilters.tsx`:

```tsx
import type { SiteStatus, Site } from '@/types/api';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Props = {
  sites: Site[];
  statusFilter: SiteStatus | 'all';
  setStatusFilter: (s: SiteStatus | 'all') => void;
  query: string;
  setQuery: (q: string) => void;
};

const STATUSES: Array<{ key: SiteStatus | 'all'; label: string }> = [
  { key: 'all',     label: 'All' },
  { key: 'active',  label: 'Active' },
  { key: 'offline', label: 'Offline' },
  { key: 'error',   label: 'Error' },
  { key: 'pending', label: 'Pending' },
];

export function SitesListFilters({ sites, statusFilter, setStatusFilter, query, setQuery }: Props) {
  const counts: Record<SiteStatus | 'all', number> = {
    all:     sites.length,
    pending: sites.filter(s => s.status === 'pending').length,
    active:  sites.filter(s => s.status === 'active').length,
    error:   sites.filter(s => s.status === 'error').length,
    offline: sites.filter(s => s.status === 'offline').length,
  };

  return (
    <div className="flex flex-wrap items-center gap-2 mb-4">
      {STATUSES.map(({ key, label }) => (
        <Button
          key={key}
          variant={statusFilter === key ? 'default' : 'outline'}
          size="sm"
          onClick={() => setStatusFilter(key)}
        >
          {label} ({counts[key]})
        </Button>
      ))}
      <div className="ml-auto w-64">
        <Input
          placeholder="Search URL or label..."
          value={query}
          onChange={(e) => setQuery(e.target.value)}
        />
      </div>
    </div>
  );
}
```

- [ ] **Step 5: Wire into SitesList**

In `apps/web/src/routes/SitesList.tsx`, add state + filter:

```tsx
const [statusFilter, setStatusFilter] = useState<SiteStatus | 'all'>('all');
const [query, setQuery] = useState('');

const filtered = useMemo(() => {
  const lower = query.toLowerCase();
  return sites.filter(s =>
    (statusFilter === 'all' || s.status === statusFilter) &&
    (lower === '' || s.url.toLowerCase().includes(lower) || s.label.toLowerCase().includes(lower))
  );
}, [sites, statusFilter, query]);
```

Render `<SitesListFilters sites={sites} ... />` above the card grid, looping `filtered` instead of `sites`. If the existing SitesList has an empty-state for `sites.length === 0`, add a separate empty-state for `filtered.length === 0 && sites.length > 0`.

- [ ] **Step 6: Run tests to verify pass**

Run: `cd apps/web && pnpm test --run -- SitesList`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add apps/web/src/routes/SitesList.tsx \
        apps/web/src/components/sites/SitesListFilters.tsx \
        apps/web/src/routes/SitesList.test.tsx
git commit -m "F8: spa — SitesList status chips + URL/label search"
```

---

### Task 7: SiteDetail — runtime info display

**Why:** The detail page should surface the cached info (wp_version, php_version, theme, plugin counts, ssl, last sync time).

**Files:**
- Modify: `apps/web/src/routes/SiteDetail.tsx`
- Create: `apps/web/src/components/sites/SiteRuntimeInfo.tsx` (NEW)
- Test: `apps/web/src/routes/SiteDetail.test.tsx` (extend)

- [ ] **Step 1: Write failing test**

Extend `SiteDetail.test.tsx`:

```tsx
it('shows runtime info when site is active', async () => {
  renderWithProviders(<SiteDetail />, { initialEntries: ['/sites/1'] });
  expect(await screen.findByText(/WordPress/i)).toBeInTheDocument();
  expect(screen.getByText(/6\.9\.4/)).toBeInTheDocument();
  expect(screen.getByText(/8\.2\.27/)).toBeInTheDocument();
  expect(screen.getByText(/Twenty Twenty-Four/i)).toBeInTheDocument();
  expect(screen.getByText(/10 installed/i)).toBeInTheDocument();
  expect(screen.getByText(/5 active/i)).toBeInTheDocument();
});

it('shows "not yet synced" placeholder when wp_version is null', async () => {
  // Add a site fixture with wp_version=null to MSW
  // assert the placeholder copy appears
});
```

- [ ] **Step 2: Run test to verify failure**

Run: `cd apps/web && pnpm test --run -- SiteDetail`
Expected: FAIL — runtime info not rendered.

- [ ] **Step 3: Create `SiteRuntimeInfo` component**

`apps/web/src/components/sites/SiteRuntimeInfo.tsx`:

```tsx
import type { Site } from '@/types/api';

type Props = { site: Site };

export function SiteRuntimeInfo({ site }: Props) {
  if (!site.wp_version) {
    return <p className="text-sm text-muted-foreground">Not yet synced — runtime info will appear after the first successful sync.</p>;
  }

  return (
    <dl className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
      <dt className="text-muted-foreground">WordPress</dt>
      <dd>{site.wp_version}</dd>

      <dt className="text-muted-foreground">PHP</dt>
      <dd>{site.php_version}</dd>

      {site.active_theme && (
        <>
          <dt className="text-muted-foreground">Active theme</dt>
          <dd>
            {site.active_theme.name} {site.active_theme.version}
            {site.active_theme.parent ? ` (child of ${site.active_theme.parent})` : null}
          </dd>
        </>
      )}

      {site.plugin_counts && (
        <>
          <dt className="text-muted-foreground">Plugins</dt>
          <dd>{site.plugin_counts.installed} installed, {site.plugin_counts.active} active</dd>
        </>
      )}

      {site.theme_counts && (
        <>
          <dt className="text-muted-foreground">Themes</dt>
          <dd>{site.theme_counts.installed} installed, {site.theme_counts.active} active</dd>
        </>
      )}

      {site.ssl_status && (
        <>
          <dt className="text-muted-foreground">SSL</dt>
          <dd>{site.ssl_status}{site.ssl_expires_at ? ` (expires ${site.ssl_expires_at})` : null}</dd>
        </>
      )}

      <dt className="text-muted-foreground">Last sync</dt>
      <dd>{site.last_sync_at ?? 'never'}</dd>

      <dt className="text-muted-foreground">Last contact</dt>
      <dd>{site.last_contact_at ?? 'never'}</dd>
    </dl>
  );
}
```

- [ ] **Step 4: Wire into SiteDetail**

In `apps/web/src/routes/SiteDetail.tsx`, inside the "active" or general site-loaded branch, render `<SiteRuntimeInfo site={site} />` below the status section.

- [ ] **Step 5: Run tests to verify pass**

Run: `cd apps/web && pnpm test --run -- SiteDetail`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/routes/SiteDetail.tsx \
        apps/web/src/components/sites/SiteRuntimeInfo.tsx \
        apps/web/src/routes/SiteDetail.test.tsx
git commit -m "F8: spa — SiteDetail runtime info panel (WP/PHP/theme/plugins/SSL/last sync)"
```

---

### Task 8: SiteDetail — Refresh / Ping / Disconnect action buttons

**Why:** The three F8 action buttons. Refresh and Ping trigger the F6 REST endpoints. Disconnect uses the F8 endpoint from Task 4.

**Files:**
- Modify: `apps/web/src/routes/SiteDetail.tsx`
- Create: `apps/web/src/components/sites/SiteActions.tsx` (NEW)
- Test: `apps/web/src/routes/SiteDetail.test.tsx` (extend)

- [ ] **Step 1: Write failing tests for SiteActions**

Extend `SiteDetail.test.tsx`:

```tsx
it('shows Refresh, Ping, and Disconnect buttons on active site', async () => {
  renderWithProviders(<SiteDetail />, { initialEntries: ['/sites/1'] });
  expect(await screen.findByRole('button', { name: /Refresh/i })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: /Ping/i })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: /Disconnect/i })).toBeInTheDocument();
});

it('clicking Refresh schedules a sync via POST /sync', async () => {
  const user = userEvent.setup();
  renderWithProviders(<SiteDetail />, { initialEntries: ['/sites/1'] });
  await screen.findByRole('button', { name: /Refresh/i });
  await user.click(screen.getByRole('button', { name: /Refresh/i }));
  expect(await screen.findByText(/Sync scheduled/i)).toBeInTheDocument();
});

it('Disconnect shows confirmation dialog then navigates to /sites on confirm', async () => {
  // ... similar but with confirm flow
});
```

- [ ] **Step 2: Run tests to verify failure**

Expected: FAIL — buttons + handlers don't exist.

- [ ] **Step 3: Create `SiteActions` component**

If the project doesn't already have shadcn AlertDialog + Toast, add them first:

```bash
cd apps/web && npx shadcn@latest add alert-dialog toast
```

Then create `apps/web/src/components/sites/SiteActions.tsx`:

```tsx
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Button } from '@/components/ui/button';
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
         AlertDialogDescription, AlertDialogFooter, AlertDialogHeader,
         AlertDialogTitle, AlertDialogTrigger } from '@/components/ui/alert-dialog';
import { toast } from '@/components/ui/use-toast';
import type { Site } from '@/types/api';

type Props = { site: Site };

export function SiteActions({ site }: Props) {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [syncing, setSyncing] = useState(false);
  const [pinging, setPinging] = useState(false);

  const syncMutation = useMutation({
    mutationFn: () => fetch(`/wp-json/defyn/v1/sites/${site.id}/sync`, { method: 'POST', credentials: 'include' }),
    onSuccess: () => {
      toast({ title: 'Sync scheduled' });
      setSyncing(true);
      queryClient.invalidateQueries({ queryKey: ['site', site.id] });
      setTimeout(() => setSyncing(false), 60_000);
    },
  });

  const pingMutation = useMutation({
    mutationFn: () => fetch(`/wp-json/defyn/v1/sites/${site.id}/ping`, { method: 'POST', credentials: 'include' }),
    onSuccess: () => {
      toast({ title: 'Ping scheduled' });
      setPinging(true);
      queryClient.invalidateQueries({ queryKey: ['site', site.id] });
      setTimeout(() => setPinging(false), 60_000);
    },
  });

  const disconnectMutation = useMutation({
    mutationFn: () => fetch(`/wp-json/defyn/v1/sites/${site.id}`, { method: 'DELETE', credentials: 'include' }),
    onSuccess: () => {
      toast({ title: 'Site disconnected' });
      queryClient.invalidateQueries({ queryKey: ['sites'] });
      navigate('/sites');
    },
  });

  return (
    <div className="flex gap-2 mt-6">
      <Button onClick={() => syncMutation.mutate()} disabled={syncing}>
        {syncing ? 'Syncing…' : 'Refresh'}
      </Button>
      <Button variant="outline" onClick={() => pingMutation.mutate()} disabled={pinging}>
        {pinging ? 'Pinging…' : 'Ping'}
      </Button>

      <AlertDialog>
        <AlertDialogTrigger asChild>
          <Button variant="destructive" className="ml-auto">Disconnect</Button>
        </AlertDialogTrigger>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Disconnect {site.label}?</AlertDialogTitle>
            <AlertDialogDescription>
              This will sever the connection to {site.url}. The connector plugin will be reset on
              the managed site, and this row will be removed from your dashboard. You'll need a new
              connection code to reconnect.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={() => disconnectMutation.mutate()}>Disconnect</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
```

- [ ] **Step 4: Wire into SiteDetail**

Render `<SiteActions site={site} />` below `<SiteRuntimeInfo site={site} />` when site is loaded and not in `pending` state.

- [ ] **Step 5: Extend MSW handlers**

In `apps/web/src/test/handlers.ts`, add handlers:

```typescript
http.post('/wp-json/defyn/v1/sites/:id/sync', () => HttpResponse.json({ site_id: 1, scheduled: true }, { status: 202 })),
http.post('/wp-json/defyn/v1/sites/:id/ping', () => HttpResponse.json({ site_id: 1, scheduled: true }, { status: 202 })),
http.delete('/wp-json/defyn/v1/sites/:id', () => new HttpResponse(null, { status: 204 })),
```

- [ ] **Step 6: Run tests to verify pass**

Run: `cd apps/web && pnpm test --run`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add apps/web/src/components/sites/SiteActions.tsx \
        apps/web/src/routes/SiteDetail.tsx \
        apps/web/src/test/handlers.ts \
        apps/web/src/routes/SiteDetail.test.tsx
# (+ shadcn-added files if any)
git commit -m "F8: spa — SiteDetail action buttons (Refresh, Ping, Disconnect with confirm)"
```

---

### Task 9: READMEs + smoke + merge

**Files:**
- Modify: `packages/dashboard-plugin/README.md`
- Modify: `apps/web/README.md` (if exists)

- [ ] **Step 1: Dashboard README**

Add to the REST API table:

| Method | Path | Purpose |
|---|---|---|
| DELETE | `/defyn/v1/sites/{id}` | Soft disconnect — signed connector tear-down + row delete |

Also document the expanded `Site::toJson` shape (new fields). Note `offline` as a valid status enum value.

- [ ] **Step 2: SPA README (if exists)**

Document the new components: `SitesListFilters`, `SiteRuntimeInfo`, `SiteActions`.

- [ ] **Step 3: Run both test suites one last time**

```bash
cd packages/dashboard-plugin && vendor/bin/phpunit
cd packages/connector-plugin && vendor/bin/phpunit
cd apps/web && pnpm test --run
```
Expected: all green.

- [ ] **Step 4: Programmatic smoke against live local stack**

Write `/tmp/f8-smoke.php` that:
1. Bootstraps WP via `wp-load.php`.
2. Reads site id=1's current row (from F6/F7 smokes).
3. Invokes `(new Defyn\Dashboard\Services\DisconnectService())->disconnect(1, 1)`.
4. Asserts: dashboard row gone (`findById(1)` returns null); connector state reset to `unconfigured` (read `wp_options['defyn_connector']` and check `state === 'unconfigured'`).

The smoke is DESTRUCTIVE — it tears down the F6/F7 fixture. Document this clearly in the smoke output. Future F9/F10 smokes will need to re-handshake to set up site id=1 again (or use a fresh site).

Run: `php /tmp/f8-smoke.php`
Expected: assertions green.

- [ ] **Step 5: Push + PR + merge**

```bash
git push -u origin f8-spa-sites-ui
gh pr create --title "F8: SPA sites UI polish" --body "$(cat <<'EOF'
## Summary
- Backend: Site::toJson expanded with F6/F7 runtime fields (wp_version, php_version, active_theme, plugin_counts, theme_counts, ssl_status, ssl_expires_at, last_sync_at). 'offline' status documented in enum.
- Backend: new SitesRepository::deleteForUser + DisconnectService (signed POST /disconnect + row delete; failure-tolerant per soft-disconnect design) + DELETE /defyn/v1/sites/{id} REST endpoint.
- SPA: siteSchema Zod expanded; 'offline' added.
- SPA: SitesList gains status chips with counts + URL/label substring search.
- SPA: SiteDetail gains runtime info panel + Refresh / Ping / Disconnect action buttons with toast feedback.

## Test plan
- [x] All dashboard tests pass
- [x] All connector tests pass (unchanged from F6)
- [x] All SPA tests pass
- [x] Programmatic smoke: DisconnectService signed teardown against live local stack — connector state reset, dashboard row deleted.
EOF
)"
gh pr merge --merge --delete-branch
```

Tag: `git tag -a f8-spa-sites-ui-complete -m "F8: SPA sites UI polish (PR #3)" && git push origin f8-spa-sites-ui-complete`

- [ ] **Step 6: Update memory + clean up /tmp**

---

## Self-Review Checklist

- [ ] Spec § 11 F8 row covered: filter, search, cached info display, Refresh, Ping, Disconnect
- [ ] No placeholders / TBD anywhere
- [ ] Method names consistent (`deleteForUser`, `disconnect`, `SiteActions`, `SiteRuntimeInfo`, `SitesListFilters`)
- [ ] Backend repository pattern preserved: all SQL via repo classes
- [ ] `Site::toJson` still hides `user_id`, `our_public_key`, `our_private_key`, `site_public_key` (F5 contract preserved)
- [ ] Soft-disconnect failure-tolerance documented in `DisconnectService` docblock
