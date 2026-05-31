# F10 — Deploy + Harden Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Land all HIGH + MEDIUM hardening carry-forwards from F5–F9, write deploy runbooks for Kinsta (backend) and Cloudflare Pages (SPA), and verify foundation soundness via a programmatic E2E. **Foundation will be code-complete after this PR** — actual live deploy is a separate operator action whenever the user is ready.

**Architecture (no new components):** Pure refactor + docs + tests. No new tables, no new endpoints, no new SPA routes.

**Scope locks (per user brainstorm):**
- **In:** HIGH (signedPostJson body fix, UrlValidator IPv6) + MEDIUM (test base class consolidation, WP REST 404/405 envelope, AS admin submenu hide) hardening + deploy runbooks + programmatic E2E
- **Out (deferred to post-foundation):** sonner install, activity log retention TTL, UrlValidator class-per-file split, composer.lock policy mismatch
- **Out (operator action, NOT this PR):** actual live Kinsta deploy, actual live Cloudflare Pages deploy, real DNS cutover

**Tech Stack:** PHP 8.1+, libsodium, PHPUnit (backend). React 18 + Vitest + MSW (SPA). No new dependencies.

**Spec source:** `docs/superpowers/specs/2026-04-18-defyn-foundation-design.md` — § 11 (F10 deliverable scope).

**Branch:** Off main as `f10-deploy-harden`. Last shipped: F9 merge `e8a8db6`.

---

### Task 1: Fix `SignedHttpClient::signedPostJson` body-hashing mismatch

**Why:** F8 smoke proved this is broken. The dashboard signs over `json_encode([])` which is `"[]"` (2 bytes). On the connector side, `VerifySignatureMiddleware` reads `$request->get_body()` which **may not be the same bytes** — WP REST may normalize empty-body POSTs to `""` (0 bytes), or the Content-Type / Symfony serialization may inject characters. The result: `sha256("[]") !== sha256("")` → signature_invalid → connector rejects → soft-disconnect absorbs it silently. Any future signed POST flow (not just disconnect) will hit the same bug.

**Reproduction:** F8's `DisconnectService` smoke against `http://localhost:10139/` showed connector state stayed `connected` — the signed POST was rejected. F9 didn't add any new signed POST callers, so the bug stayed hidden.

**Root-cause hypothesis (verify in Step 0):** `json_encode([], JSON_UNESCAPED_SLASHES)` returns `"[]"`. Connector's `$request->get_body()` returns the raw request body bytes — for a POST with Symfony http-client sending `body: '[]'` and `Content-Type: application/json`, the connector should receive `"[]"` and compute the same sha256. But: if Symfony elides the body for an empty array, or if WP REST normalizes the body internally, they diverge.

**Files:**
- Possibly modify: `packages/dashboard-plugin/src/Http/SignedHttpClient.php`
- Possibly modify: `packages/connector-plugin/src/Rest/Middleware/VerifySignatureMiddleware.php`
- Test: `packages/dashboard-plugin/tests/Integration/Http/SignedHttpClientEmptyBodyTest.php` (NEW)
- Test: `packages/connector-plugin/tests/Integration/Rest/EmptyBodySignedRequestTest.php` (NEW)

- [ ] **Step 0: Reproduce + diagnose**

Write a tiny diagnostic script (`/tmp/f10-sig-diag.php`) that:
1. Computes the canonical string + signature exactly as `SignedHttpClient::signedPostJson` does for an empty `[]` body
2. Sends the request to `http://localhost:10139/wp-json/defyn-connector/v1/disconnect` (needs connector to be in `connected` state with the matching `dashboard_public_key` — may need to handshake fresh first)
3. Inspects the actual response code + envelope
4. If 401: enable WP debug logging on the connector side, re-run, grep the log for what canonical string the connector computed
5. Diff dashboard's canonical vs connector's canonical

The likely culprits:
- (a) `json_encode([])` = `"[]"` BUT Symfony's `body: '[]'` over the wire arrives as 0 bytes because empty-array → no body
- (b) Content-Type header missing on connector side causes WP to skip body parsing differently
- (c) Both produce the same hash but somewhere else mismatches (e.g. canonical path with vs without trailing slash, method casing)

- [ ] **Step 1: Write the failing test**

Once the root cause is known, write a test that exercises the same path WITHOUT going through HTTP. Either:

(a) In `packages/dashboard-plugin/tests/Integration/Http/SignedHttpClientEmptyBodyTest.php`: build a signed-empty-POST and run it through the connector's `Defyn\Connector\Crypto\Signer::verifyRequest()` directly. Both plugins are installed in the same WP instance so the namespace bridge works.

(b) In `packages/connector-plugin/tests/Integration/Rest/EmptyBodySignedRequestTest.php`: dispatch a signed empty-POST via `rest_do_request()` against the existing `/disconnect` route, asserting it returns 204 not 401.

Skeleton for (a):

```php
public function testEmptyBodySignedPostMatchesConnectorVerification(): void
{
    $kp = sodium_crypto_sign_keypair();
    $privB64 = base64_encode(sodium_crypto_sign_secretkey($kp));
    $pubB64  = base64_encode(sodium_crypto_sign_publickey($kp));

    // Capture what SignedHttpClient signs
    $capturedBody = null;
    $capturedHeaders = null;
    $mock = new MockHttpClient(function ($method, $url, $options) use (&$capturedBody, &$capturedHeaders) {
        $capturedBody = $options['body'] ?? '';
        $capturedHeaders = $options['headers'] ?? [];
        return new MockResponse('', ['http_code' => 204]);
    });

    (new SignedHttpClient($mock))->signedPostJson(
        'https://x.test/wp-json/defyn-connector/v1/disconnect',
        [],
        $privB64,
        '/defyn-connector/v1/disconnect'
    );

    // Extract the headers actually sent
    $flat = [];
    foreach ($capturedHeaders as $h) {
        [$name, $value] = explode(': ', $h, 2);
        $flat[$name] = $value;
    }

    // The connector must verify against the SAME body bytes captured.
    $nonceStore = new InMemoryNonceStore();
    $result = \Defyn\Connector\Crypto\Signer::verifyRequest(
        $pubB64,
        'POST',
        '/defyn-connector/v1/disconnect',
        $capturedBody,  // exact wire bytes
        $flat,
        $nonceStore
    );

    $this->assertSame(\Defyn\Connector\Crypto\VerificationResult::VALID, $result);
}
```

Expected: FAIL before fix; PASS after.

- [ ] **Step 2: Apply the fix**

Based on Step 0's diagnosis. Most likely fixes:

**If `body: '[]'` arrives as empty on the wire (Symfony elision):** change `signedPostJson` to NEVER pass `[]`-as-body via Symfony's `json` option; instead always use `body: <bytes>` with a manually-encoded JSON. Then `$capturedBody` should reliably be `"[]"`.

**If WP REST normalizes the body:** add `$request->set_header('Content-Type', 'application/json')` defensively on the dashboard side, and on the connector side ensure `$request->get_body()` returns the raw POST body NOT a decoded array.

**If neither — it's actually some other byte (e.g. JSON_UNESCAPED_SLASHES vs default):** lock down BOTH sides to use the exact same `json_encode` flags. Add a `JSON_FLAGS` constant in both plugins' Signer classes that they agree on.

**Document the fix in code comments** — this is the exact spot in the codebase where a future drift between the two plugins is most likely to bite. A 3-line comment in BOTH `SignedHttpClient::signedPostJson` and `VerifySignatureMiddleware::check` explaining the body-byte agreement is worth its weight in gold.

- [ ] **Step 3: Run the test to verify pass**

```bash
cd packages/dashboard-plugin && vendor/bin/phpunit --filter SignedHttpClientEmptyBodyTest
```
Expected: PASS.

- [ ] **Step 4: Re-run F8's DisconnectService smoke** (programmatically) to confirm the fix holds end-to-end — see Task 8.

- [ ] **Step 5: Run full dashboard suite**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add packages/dashboard-plugin/src/Http/SignedHttpClient.php \
        packages/connector-plugin/src/Rest/Middleware/VerifySignatureMiddleware.php \
        packages/dashboard-plugin/tests/Integration/Http/SignedHttpClientEmptyBodyTest.php
git commit -m "F10: fix empty-body signed POST body-hashing mismatch (F8/F9 carry-forward)"
```

---

### Task 2: `UrlValidator` IPv6 support

**Why:** `Defyn\Dashboard\Services\UrlValidator` uses `gethostbyname($host)` for DNS gating. `gethostbyname` only returns A records (IPv4). IPv6-only hosts return the input string unchanged (meaning DNS "failed"), so the validator rejects them with `sites.invalid_url`. Modern WordPress hosts on IPv6-only providers can't be added to the dashboard.

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/UrlValidator.php`
- Test: `packages/dashboard-plugin/tests/Unit/Services/UrlValidatorIPv6Test.php` (NEW)

- [ ] **Step 1: Inspect existing code**

Read `packages/dashboard-plugin/src/Services/UrlValidator.php` — note the exact `gethostbyname` call. The fix swaps to `dns_get_record($host, DNS_A | DNS_AAAA)` and treats success as "non-empty array returned".

- [ ] **Step 2: Write the failing test**

The test can't actually resolve a real IPv6-only host in CI without DNS variance. Instead, refactor `UrlValidator` to inject a DNS-resolver callable (similar to the `checkDns` flag from F5), and test BOTH paths via the injected resolver.

```php
public function testRejectsHostWithNoRecords(): void
{
    $resolver = fn(string $host): array => [];  // simulate no records
    $v = new UrlValidator(checkDns: true, dnsResolver: $resolver);
    $result = $v->validate('https://no-records.test');
    $this->assertFalse($result->isValid);
}

public function testAcceptsIpv6OnlyHostWhenAAAARecordResolves(): void
{
    $resolver = fn(string $host): array => [['type' => 'AAAA', 'ipv6' => '2001:db8::1']];
    $v = new UrlValidator(checkDns: true, dnsResolver: $resolver);
    $result = $v->validate('https://ipv6-only.test');
    $this->assertTrue($result->isValid);
}

public function testAcceptsIpv4OnlyHostWhenARecordResolves(): void
{
    $resolver = fn(string $host): array => [['type' => 'A', 'ip' => '203.0.113.42']];
    $v = new UrlValidator(checkDns: true, dnsResolver: $resolver);
    $result = $v->validate('https://ipv4-only.test');
    $this->assertTrue($result->isValid);
}
```

- [ ] **Step 3: Refactor `UrlValidator` to inject the resolver + use both record types**

Add a constructor arg `private readonly ?callable $dnsResolver = null` and inside the DNS check:

```php
$resolver = $this->dnsResolver ?? fn(string $host): array =>
    dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
$records = $resolver($host);
if (empty($records)) {
    return ValidationResult::invalid('sites.invalid_url', '...');
}
```

- [ ] **Step 4: Run tests to verify pass + full suite for no regression**

Expected: all green; existing F5 tests still pass because the resolver default uses real DNS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Services/UrlValidator.php \
        packages/dashboard-plugin/tests/Unit/Services/UrlValidatorIPv6Test.php
git commit -m "F10: UrlValidator supports IPv6 via dns_get_record (DNS_A | DNS_AAAA)"
```

---

### Task 3: Test base class consolidation

**Why:** F9 Task 3 implementer found that `AuthLoginTest` + `RateLimitTest` extended `WP_UnitTestCase` directly. When the F9 backfill added activity-log writes to `AuthLoginController`, the missing `wp_defyn_activity_log` table caused silent insert failures (tests "passed" but the writes failed silently). F9 fixed those two; there may be others.

**Files:**
- Audit: scan `packages/dashboard-plugin/tests/` for `extends WP_UnitTestCase` that should be `extends AbstractSchemaTestCase`
- Possibly modify: any matching test files

- [ ] **Step 1: Audit**

Run: `grep -rn "extends WP_UnitTestCase" packages/dashboard-plugin/tests/`

For each match: read the test file and determine whether it (or any class it tests) touches `wp_defyn_*` tables. If so, migrate to `AbstractSchemaTestCase` + the appropriate `freshlyActivate('defyn_X')` calls in setUp.

Some tests legitimately don't touch the custom tables (e.g. pure unit tests of utility classes) — leave those alone.

- [ ] **Step 2: Migrate the offenders**

For each test file that needs migration:
1. Change `extends WP_UnitTestCase` → `extends AbstractSchemaTestCase`
2. Add appropriate `freshlyActivate(...)` calls in setUp
3. Run that specific test file to confirm it still passes
4. Move on

- [ ] **Step 3: Run the full dashboard suite**

Expected: all green.

- [ ] **Step 4: Commit**

```bash
git add packages/dashboard-plugin/tests/Integration/**/*.php
git commit -m "F10: migrate WP_UnitTestCase tests to AbstractSchemaTestCase where needed"
```

If the audit finds NO offenders beyond what F9 fixed, skip this task with an empty commit message noting the audit was clean.

---

### Task 4: WP REST 404/405 envelope normalizer

**Why:** SPA hits a typo'd route (`/defyn/v1/site/1` missing the 's') → WP returns `{"code":"rest_no_route","message":"...","data":{"status":404}}` instead of the spec § 9.1 envelope `{"error":{"code":"X","message":"Y"}}`. Same for 405 Method Not Allowed.

**Files:**
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/RestEnvelopeNormalizerTest.php` (NEW)

- [ ] **Step 1: Read the existing normalizer**

Read `packages/dashboard-plugin/src/Rest/RestRouter.php` — find `normalizeErrorEnvelope`. It currently catches `WP_Error` returned by handlers / permission callbacks. It does NOT catch WP-native 404/405 responses (those come from the dispatcher BEFORE any handler runs).

The fix: add a `rest_post_dispatch` filter that catches WP_REST_Response with code `rest_no_route` or `rest_no_method` and rewraps as spec envelope.

- [ ] **Step 2: Write the failing test**

```php
public function test404OnUnknownDefynRouteUsesSpecEnvelope(): void
{
    $response = rest_do_request(new WP_REST_Request('GET', '/defyn/v1/this-route-does-not-exist'));
    $this->assertSame(404, $response->get_status());
    $data = $response->get_data();
    $this->assertArrayHasKey('error', $data);
    $this->assertSame('rest.route_not_found', $data['error']['code']);
}

public function test405OnWrongMethodUsesSpecEnvelope(): void
{
    // PUT against /sites which is GET/POST only
    $response = rest_do_request(new WP_REST_Request('PUT', '/defyn/v1/sites'));
    $this->assertSame(405, $response->get_status());
    $data = $response->get_data();
    $this->assertSame('rest.method_not_allowed', $data['error']['code']);
}
```

- [ ] **Step 3: Add the filter**

In `RestRouter::register()`, add:

```php
add_filter('rest_post_dispatch', [self::class, 'normalizeRouteNotFound'], 10, 3);
```

And the static method:

```php
public static function normalizeRouteNotFound($response, $server, $request)
{
    if (!$response instanceof WP_REST_Response) {
        return $response;
    }
    if (strpos($request->get_route(), '/' . self::NAMESPACE) !== 0) {
        return $response;
    }
    $status = $response->get_status();
    if ($status === 404) {
        $data = $response->get_data();
        if (is_array($data) && isset($data['code']) && $data['code'] === 'rest_no_route') {
            $response->set_data(['error' => ['code' => 'rest.route_not_found', 'message' => 'Route not found.']]);
        }
    }
    if ($status === 405) {
        $data = $response->get_data();
        if (is_array($data) && isset($data['code']) && $data['code'] === 'rest_no_method') {
            $response->set_data(['error' => ['code' => 'rest.method_not_allowed', 'message' => 'Method not allowed.']]);
        }
    }
    return $response;
}
```

- [ ] **Step 4: Run tests to verify pass + full suite for no regression**

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/RestRouter.php \
        packages/dashboard-plugin/tests/Integration/Rest/RestEnvelopeNormalizerTest.php
git commit -m "F10: rewrap WP-native 404/405 responses with spec § 9.1 envelope"
```

---

### Task 5: Hide AS admin submenu from non-operators

**Why:** `Tools → Scheduled Actions` is auto-registered by Action Scheduler and visible to any user with `manage_options`. The admin UI exposes pending/failed job arguments (site IDs, codes) which leaks information.

**Files:**
- Modify: `packages/dashboard-plugin/src/Plugin.php`

- [ ] **Step 1: Add a filter to hide the submenu**

In `Plugin::boot()`:

```php
add_action('admin_menu', static function (): void {
    remove_submenu_page('tools.php', 'action-scheduler');
}, 999);
```

- [ ] **Step 2: Manually verify** by visiting `/wp-admin/tools.php` after the plugin is active. Expected: no "Scheduled Actions" link.

- [ ] **Step 3: Commit**

```bash
git add packages/dashboard-plugin/src/Plugin.php
git commit -m "F10: hide Action Scheduler admin submenu (Tools → Scheduled Actions) — info leak"
```

---

### Task 6: Deploy runbook — Kinsta backend

**Files:** Create: `docs/deploy/kinsta-backend.md`

- [ ] **Step 1: Write the runbook**

Cover:
- Kinsta site setup (Bedrock layout, PHP 8.1+, MySQL)
- SSH access + Composer install on the server
- Required `wp-config.php` constants: `DEFYN_VAULT_KEY` (32-byte base64), `DEFYN_JWT_SECRET` (64+ char random), `DEFYN_TESTS_RUNNING` left undefined in prod
- DB migration: `dbDelta` runs via plugin activation hook
- Server cron: Kinsta DevKit → "Server cron" → add `* * * * * cd /www/<site>/public && /usr/local/bin/php wp-cron.php > /dev/null 2>&1`
- CORS: confirm `Defyn\Dashboard\Rest\Middleware\Cors` allows the SPA's production origin
- Rate limits: F3a's rate limiter uses WP transients; with Kinsta Redis fan-out works across PHP-FPM workers
- HTTPS: Kinsta handles cert auto-issuance
- Bedrock plugin path: `web/app/plugins/defyn-dashboard/`

- [ ] **Step 2: Commit**

```bash
git add docs/deploy/kinsta-backend.md
git commit -m "F10: docs — Kinsta backend deploy runbook"
```

---

### Task 7: Deploy runbook — Cloudflare Pages SPA

**Files:** Create: `docs/deploy/cloudflare-pages-spa.md`

- [ ] **Step 1: Write the runbook**

Cover:
- CF Pages project setup (GitHub source or direct upload)
- Build command: `pnpm install && pnpm build`
- Build output dir: `apps/web/dist/`
- Required env vars: `VITE_API_BASE_URL=https://defyn.com/wp-json/defyn/v1`
- DNS: `app.defyn.dev` CNAME → CF Pages target
- HTTPS: automatic via CF
- SPA routing: CF Pages built-in SPA mode (unknown paths → `index.html`)
- Caching: `index.html` short cache; hashed assets long cache (1 year, immutable)
- CORS verification against backend Cors middleware

- [ ] **Step 2: Commit**

```bash
git add docs/deploy/cloudflare-pages-spa.md
git commit -m "F10: docs — Cloudflare Pages SPA deploy runbook"
```

---

### Task 8: Programmatic E2E against a fresh Local site

**Why:** Foundation E2E. Exercises the FULL handshake → sync → ping → activity log → disconnect cycle. Step 5 of this task is the LITMUS TEST for Task 1's signed-POST fix.

**Files:**
- Create: `docs/deploy/programmatic-e2e.md` (the runbook)

- [ ] **Step 1: Reset connector state to `unconfigured`**

The F8 smoke left the connector at `state=connected` with a stale dashboard pubkey. For a clean handshake:

```bash
SOCK="/Users/pradeep/Library/Application Support/Local/run/50bJKdbjK/mysql/mysqld.sock"
PHP="/Users/pradeep/Library/Application Support/Local/lightning-services/php-8.2.27+1/bin/darwin/bin/php"
"$PHP" -d "mysqli.default_socket=$SOCK" -d "pdo_mysql.default_socket=$SOCK" -r '
  define("ABSPATH", "/Users/pradeep/Local Sites/defynWP/app/public/");
  require ABSPATH . "wp-load.php";
  (new Defyn\Connector\Storage\ConnectorState())->update(["state" => "unconfigured", "dashboard_public_key" => "", "connected_at" => ""]);
  echo "Connector reset.\n";'
```

- [ ] **Step 2: Run the full E2E smoke**

Write `/tmp/f10-e2e-smoke.php`:

1. Generate a fresh connection code via `Defyn\Connector\Admin\CodeGenerator::generate()` + persist in connector state
2. Insert a fresh pending site row + invoke `Defyn\Dashboard\Jobs\CompleteConnection::handle($siteId, $code, $url)` synchronously
3. Assert site flips to `active` + connector flips to `connected`
4. Invoke `SyncService::sync($siteId)` → assert `wp_version` populated + `site.synced` event logged
5. Invoke `HealthService::ping($siteId)` → assert `last_contact_at` advances + `site.health_ok` event logged
6. Invoke `DisconnectService::disconnect($siteId, 1)` → assert connector state resets to `unconfigured` AND dashboard row gone AND `site.disconnected` event logged
7. Report OK at each step

Run via the standard PHP override invocation.

- [ ] **Step 3: Document the runbook**

In `docs/deploy/programmatic-e2e.md`:
- The exact PHP socket/path overrides
- Step-by-step instructions to reproduce
- Expected output per step
- How to interpret failures

- [ ] **Step 4: Run the smoke against the live local stack**

Expected: all 6 steps green. **Step 6** (Disconnect → connector state resets) is the litmus test for Task 1's fix.

- [ ] **Step 5: Commit + clean up /tmp**

```bash
git add docs/deploy/programmatic-e2e.md
git commit -m "F10: docs — programmatic E2E smoke runbook (handshake → sync → ping → activity → disconnect)"
rm /tmp/f10-e2e-smoke.php
```

---

### Task 9: Update READMEs + final memory + merge

**Files:**
- Modify: `packages/dashboard-plugin/README.md`
- Modify: `packages/connector-plugin/README.md`

- [ ] **Step 1: Update READMEs**

Add a brief "F10: Hardening + deploy readiness" section to each plugin README documenting:
- The signed POST body-hashing fix
- IPv6 host support
- 404/405 envelope normalization
- Pointer to `docs/deploy/`

- [ ] **Step 2: Run all three test suites one final time**

```bash
cd packages/dashboard-plugin && vendor/bin/phpunit
cd packages/connector-plugin && vendor/bin/phpunit
cd apps/web && pnpm test
```
Expected: all green.

- [ ] **Step 3: Push + PR + merge + tag**

```bash
git push -u origin f10-deploy-harden
gh pr create --title "F10: Deploy + harden (foundation complete)" --body "..."
gh pr merge --merge --delete-branch
git tag -a f10-deploy-harden-complete -m "F10: Deploy + harden complete"
git push origin f10-deploy-harden-complete
git tag -a foundation-complete -m "DefynWP foundation phase complete (F1-F10)"
git push origin foundation-complete
```

- [ ] **Step 4: Update memory**

Rewrite `~/.claude/projects/-Users-pradeep-Local-Sites-defynWP/memory/project_defyn_current_state.md` to reflect: F1–F10 all shipped, foundation complete, next is Phase 2 features per spec § 12.

---

## Self-Review Checklist

- [ ] All HIGH + MEDIUM hardening carry-forwards from F5–F9 landed
- [ ] Task 1 fix demonstrably works (Task 8 Disconnect smoke shows connector state reset)
- [ ] Deploy runbooks cover env vars, DNS, CORS, HTTPS, cron, rate limits
- [ ] Programmatic E2E exercises EVERY phase's deliverable end-to-end
- [ ] No placeholders / TBD anywhere
- [ ] `foundation-complete` tag pushed
