# P7.1 — Broken-Link Monitoring Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a broken-link scanner — the connector crawls each site's published content and HTTP-checks its links; the dashboard stores the bad ones, surfaces them on Site Detail + Overview, and adds a Broken-links report section.

**Architecture:** Mirrors the Security (P4.1) + Performance (P6.1) scanners exactly. Connector (v0.1.8) does a bounded synchronous scan behind a signed `POST /links/scan`; the dashboard (v0.27.0, schema v17) calls it from an Action-Scheduler job (weekly fan-out + on-demand), is best-effort everywhere, and never blocks a web request. SPA gets a per-site panel + report section + Overview chip.

**Tech Stack:** PHP 8.1 (connector + dashboard, PHPUnit/wp-phpunit), React 18 + TS + TanStack Query v5 + Tailwind v4 + shadcn + Vitest + MSW (apps/web, pnpm, Node 22), Action Scheduler, dompdf 3.1.5.

**Spec:** `docs/superpowers/specs/2026-06-21-p7-1-broken-link-monitoring-design.md`

---

## Locked contracts (every task must match these exactly)

- **Connector endpoint:** `POST /defyn-connector/v1/links/scan` (signed). Returns
  `{ links: Finding[], scanned_posts:int, total_posts:int, checked_links:int, truncated:bool, server_time:int }`
  where `Finding = { url, status:?int, transport_error:bool, link_type:'internal'|'external', source_url, source_title:?string, anchor_text:?string }`.
- **Dashboard endpoints:** `GET /defyn/v1/sites/{id}/broken-links` (30/MIN) → `{ data:{ last_link_scan_at:?string, counts, links: Row[] }, error:null }`; `POST /defyn/v1/sites/{id}/links/scan` (6/HR) → 202 `{ data:{ scheduled:true }, error:null }`.
- **Stored Row / list `links[]` item:** `{ url, status_code:?int, severity:'broken'|'warning', reason, link_type, source_url, source_title:?string, anchor_text:?string, first_detected_at, last_detected_at }`.
- **`counts`:** `{ broken:int, warning:int, total:int, internal:int, external:int }`.
- **Report `broken_links` key:** `{ state:'not_checked'|'clean'|'issues', last_scanned:?string, counts, items: Item[] }` where `Item = { url, status_code:?int, severity, reason, link_type, source_url }` (broken-first, ≤20).
- **`reason` values:** `not_found | server_error | blocked | client_error | unreachable`.
- **Classification (`LinkClassifier`):** transport-error/null→`warning/unreachable`; 404|410→`broken/not_found`; ≥500→`warning/server_error`; 403|401|429→`warning/blocked`; other ≥400→`warning/client_error`.
- **Overview attention reason string:** `has_broken_links` (fires only on ≥1 `broken` row).
- **AS hooks:** `LinkScan::HOOK = 'defyn_link_scan'`, `LinkScanAll::HOOK = 'defyn_link_scan_all'`, group `'defyn'`.
- **RateLimit transients:** `defyn_rl_linksScan_{userId}_{siteId}` (6/HR), `defyn_rl_linksRead_{userId}_{siteId}` (30/MIN); 429 code `links.rate_limited`.
- **Versions:** connector `0.1.7→0.1.8`; dashboard `0.26.0→0.27.0`; `Activation::SCHEMA_VERSION 16→17`.

## Environment notes (carry-forward — read before any test run)

- **Local `defynWP` DB is OFFLINE.** If a PHP suite errors on DB connection, start a standalone mysqld 8.0.35: binary `~/Library/Application Support/lightning-services/mysql-8.0.35+4/bin/darwin/bin/mysqld`, datadir `~/Library/Application Support/Local/run/50bJKdbjK/mysql/data`, port 10166, own socket `/tmp/defyn_test_mysqld.sock`, root/root, **NO `--skip-grant-tables`** (forces skip_networking in 8.0). Shut down via `mysqladmin` from the same bin dir. **NEVER modify the gitignored `wp-tests-config.php`.**
- **Dashboard PHP suite:** `cd packages/dashboard-plugin && COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit`. Baseline = green except `UninstallTest` (tolerate ONLY that). The schema-version-pin tests (grep `tests/` for the literal `16` asserting `SCHEMA_VERSION`/`SchemaVersion::current()`) join the carry-forward set on the v17 bump — update them in Task 6.
- **Connector PHP suite:** `cd packages/connector-plugin && composer test` (or `composer test:unit` / `composer test:integration`). Unit tests extend `PHPUnit\Framework\TestCase`; integration tests extend `WP_UnitTestCase` and `do_action('rest_api_init')` in setUp.
- **SPA:** `cd apps/web`, `export FNM_DIR="$HOME/.fnm"; eval "$(fnm env --shell bash)"; fnm use 22`, then `pnpm test -- --run` and `pnpm build` (runs `tsc` — a green vitest can still fail the typecheck). Carry-forward 4 failures: `tests/SiteDetail.test.tsx` ×2 + `tests/components/sites/SiteCoreCard.test.tsx` ×2.
- `/tmp` ENOSPC → set `CLAUDE_CODE_TMPDIR` to project-local `.claude-tmp/`.

---

# PART A — CONNECTOR (v0.1.8)

### Task 1: `LinkExtractor` (pure HTML link extraction + resolution)

**Files:**
- Create: `packages/connector-plugin/src/Links/LinkExtractor.php`
- Test: `packages/connector-plugin/tests/Unit/Links/LinkExtractorTest.php`

- [ ] **Step 1: Write the failing test** — `LinkExtractorTest.php` (extends `PHPUnit\Framework\TestCase`, namespace `Defyn\Connector\Tests\Unit\Links`, `@group unit`):

```php
public function testExtractsAbsoluteHttpLinks(): void {
    $out = (new LinkExtractor())->extract('<a href="https://example.com/a">A</a>', 'https://site.test');
    self::assertCount(1, $out);
    self::assertSame('https://example.com/a', $out[0]['url']);
    self::assertSame('A', $out[0]['anchor_text']);
}
public function testResolvesRootRelativeAgainstHome(): void {
    $out = (new LinkExtractor())->extract('<a href="/blog/x">x</a>', 'https://site.test');
    self::assertSame('https://site.test/blog/x', $out[0]['url']);
}
public function testResolvesProtocolRelativeUsingHomeScheme(): void {
    $out = (new LinkExtractor())->extract('<a href="//cdn.test/y">y</a>', 'https://site.test');
    self::assertSame('https://cdn.test/y', $out[0]['url']);
}
public function testStripsFragmentAndDedupes(): void {
    $out = (new LinkExtractor())->extract('<a href="https://x.test/a#one">1</a><a href="https://x.test/a#two">2</a>', 'https://site.test');
    self::assertCount(1, $out);
    self::assertSame('https://x.test/a', $out[0]['url']);
}
public function testSkipsMailtoTelJsAndPureFragment(): void {
    $html = '<a href="mailto:a@b.test">m</a><a href="tel:123">t</a><a href="javascript:void(0)">j</a><a href="#sec">f</a>';
    self::assertSame([], (new LinkExtractor())->extract($html, 'https://site.test'));
}
public function testEmptyContentReturnsEmpty(): void {
    self::assertSame([], (new LinkExtractor())->extract('   ', 'https://site.test'));
}
```

- [ ] **Step 2: Run, expect fail** — `cd packages/connector-plugin && composer test:unit -- --filter LinkExtractorTest` → FAIL (class not found).
- [ ] **Step 3: Implement** `src/Links/LinkExtractor.php`:

```php
<?php
declare(strict_types=1);
namespace Defyn\Connector\Links;

/**
 * P7.1 — pure extraction of http(s) link occurrences from a post's HTML,
 * resolved to absolute URLs against the site home URL. No WP calls; unit-tested.
 */
final class LinkExtractor
{
    /** @return list<array{url:string, anchor_text:string}> */
    public function extract(string $postContent, string $homeUrl): array
    {
        if (trim($postContent) === '') {
            return [];
        }
        $dom  = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $postContent . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $out  = [];
        $seen = [];
        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if ($href === '') {
                continue;
            }
            $abs = $this->resolve($href, $homeUrl);
            if ($abs === null || isset($seen[$abs])) {
                continue;
            }
            $seen[$abs] = true;
            $text = trim((string) $a->textContent);
            $out[] = [
                'url'         => $abs,
                'anchor_text' => strlen($text) <= 255 ? $text : substr($text, 0, 255),
            ];
        }
        return $out;
    }

    private function resolve(string $href, string $homeUrl): ?string
    {
        if ($href[0] === '#') {
            return null;
        }
        $lower = strtolower($href);
        foreach (['mailto:', 'tel:', 'javascript:', 'data:'] as $bad) {
            if (str_starts_with($lower, $bad)) {
                return null;
            }
        }
        if (str_starts_with($href, '//')) {
            $scheme = parse_url($homeUrl, PHP_URL_SCHEME) ?: 'https';
            $href   = $scheme . ':' . $href;
        }
        $parts = parse_url($href);
        if ($parts === false) {
            return null;
        }
        if (isset($parts['scheme'])) {
            $scheme = strtolower($parts['scheme']);
            return ($scheme === 'http' || $scheme === 'https') ? $this->stripFragment($href) : null;
        }
        $home = parse_url($homeUrl);
        if ($home === false || !isset($home['scheme'], $home['host'])) {
            return null;
        }
        $base = $home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '');
        if (str_starts_with($href, '/')) {
            return $this->stripFragment($base . $href);
        }
        $homePath = $home['path'] ?? '/';
        $slash    = strrpos($homePath, '/');
        $dir      = $slash === false ? '/' : substr($homePath, 0, $slash + 1);
        return $this->stripFragment($base . $dir . $href);
    }

    private function stripFragment(string $url): string
    {
        $pos = strpos($url, '#');
        return $pos === false ? $url : substr($url, 0, $pos);
    }
}
```

- [ ] **Step 4: Run, expect pass** — `composer test:unit -- --filter LinkExtractorTest` → PASS.
- [ ] **Step 5: Commit** — `git add packages/connector-plugin/src/Links/LinkExtractor.php packages/connector-plugin/tests/Unit/Links/LinkExtractorTest.php && git commit -m "feat(connector): LinkExtractor — extract+resolve http links from post content (P7.1)"`

---

### Task 2: `LinkChecker` (best-effort HTTP check)

**Files:**
- Create: `packages/connector-plugin/src/Links/LinkChecker.php`
- Test: `packages/connector-plugin/tests/Integration/Links/LinkCheckerTest.php` (needs WP's `pre_http_request` filter → integration)

- [ ] **Step 1: Write the failing test** — extends `WP_UnitTestCase`, `@group integration`. Stub HTTP via the `pre_http_request` filter (return an array `['response'=>['code'=>N]]` or a `WP_Error`). In `tearDown`, `remove_all_filters('pre_http_request')`.

```php
private function stubHttp(int $code): void {
    add_filter('pre_http_request', fn() => ['response' => ['code' => $code], 'body' => ''], 10, 0);
}
public function testReturnsStatusForReachableUrl(): void {
    $this->stubHttp(404);
    self::assertSame(['status' => 404, 'transport_error' => false], (new LinkChecker())->check('https://x.test/a'));
}
public function testWpErrorIsTransportError(): void {
    add_filter('pre_http_request', fn() => new \WP_Error('http', 'down'), 10, 0);
    self::assertSame(['status' => null, 'transport_error' => true], (new LinkChecker())->check('https://x.test/a'));
}
public function testHealthy200(): void {
    $this->stubHttp(200);
    self::assertSame(['status' => 200, 'transport_error' => false], (new LinkChecker())->check('https://x.test/a'));
}
```

- [ ] **Step 2: Run, expect fail** — `composer test:integration -- --filter LinkCheckerTest` → FAIL (class not found).
- [ ] **Step 3: Implement** `src/Links/LinkChecker.php`:

```php
<?php
declare(strict_types=1);
namespace Defyn\Connector\Links;

/** P7.1 — best-effort HTTP check of a single URL. Never throws. */
final class LinkChecker
{
    private const TIMEOUT = 5;
    private const UA      = 'DefynWP-LinkChecker/1.0 (+https://defyn.dev)';

    /** @return array{status:?int, transport_error:bool} */
    public function check(string $url): array
    {
        $args = ['timeout' => self::TIMEOUT, 'redirection' => 5, 'user-agent' => self::UA, 'sslverify' => true];

        $res = wp_remote_head($url, $args);
        if (is_wp_error($res)) {
            $res = wp_remote_get($url, $args);
            if (is_wp_error($res)) {
                return ['status' => null, 'transport_error' => true];
            }
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code === 405 || $code === 501 || $code === 0) {
            $res = wp_remote_get($url, $args);
            if (is_wp_error($res)) {
                return ['status' => null, 'transport_error' => true];
            }
            $code = (int) wp_remote_retrieve_response_code($res);
        }
        return $code === 0
            ? ['status' => null, 'transport_error' => true]
            : ['status' => $code, 'transport_error' => false];
    }
}
```

- [ ] **Step 4: Run, expect pass** — `composer test:integration -- --filter LinkCheckerTest` → PASS.
- [ ] **Step 5: Commit** — `git add packages/connector-plugin/src/Links/LinkChecker.php packages/connector-plugin/tests/Integration/Links/LinkCheckerTest.php && git commit -m "feat(connector): LinkChecker — HEAD->GET best-effort URL check (P7.1)"`

---

### Task 3: `BrokenLinkScanner` (orchestrator with caps)

**Files:**
- Create: `packages/connector-plugin/src/Links/BrokenLinkScanner.php`
- Test: `packages/connector-plugin/tests/Integration/Links/BrokenLinkScannerTest.php`

Constructor seams both collaborators so the test injects a fake `LinkChecker`.

- [ ] **Step 1: Write the failing test** — extends `WP_UnitTestCase`. Use the WP factory to create published posts with link-bearing content; inject a fake checker (anonymous subclass of `LinkChecker` overriding `check()` to return canned statuses by URL).

```php
public function testReportsOnlyUnhealthyLinksWithLinkType(): void {
    $this->factory->post->create(['post_status' => 'publish', 'post_content' =>
        '<a href="https://ok.test/a">ok</a><a href="https://dead.test/x">dead</a>']);
    $checker = new class extends LinkChecker {
        public function check(string $url): array {
            return str_contains($url, 'dead')
                ? ['status' => 404, 'transport_error' => false]
                : ['status' => 200, 'transport_error' => false];
        }
    };
    $out = (new BrokenLinkScanner(new LinkExtractor(), $checker))->scan();
    self::assertCount(1, $out['links']);
    self::assertSame('https://dead.test/x', $out['links'][0]['url']);
    self::assertSame(404, $out['links'][0]['status']);
    self::assertSame('external', $out['links'][0]['link_type']);
    self::assertGreaterThanOrEqual(1, $out['scanned_posts']);
    self::assertFalse($out['truncated']);
}
public function testInternalLinkTypedInternal(): void {
    $home = wp_parse_url(get_home_url(), PHP_URL_HOST);
    $this->factory->post->create(['post_status' => 'publish',
        'post_content' => '<a href="https://' . $home . '/missing">x</a>']);
    $checker = new class extends LinkChecker {
        public function check(string $url): array { return ['status' => 404, 'transport_error' => false]; }
    };
    $out = (new BrokenLinkScanner(new LinkExtractor(), $checker))->scan();
    self::assertSame('internal', $out['links'][0]['link_type']);
}
```

- [ ] **Step 2: Run, expect fail** — `composer test:integration -- --filter BrokenLinkScannerTest` → FAIL.
- [ ] **Step 3: Implement** `src/Links/BrokenLinkScanner.php`:

```php
<?php
declare(strict_types=1);
namespace Defyn\Connector\Links;

/** P7.1 — enumerate published posts/pages, extract + check their links, report the bad ones. Bounded. */
final class BrokenLinkScanner
{
    public const MAX_POSTS    = 500;
    public const MAX_LINKS    = 3000;
    public const MAX_FINDINGS = 2000;
    public const MAX_SECONDS  = 90;

    public function __construct(
        private readonly LinkExtractor $extractor = new LinkExtractor(),
        private readonly LinkChecker $checker = new LinkChecker(),
    ) {}

    /**
     * @return array{links:list<array<string,mixed>>, scanned_posts:int, total_posts:int, checked_links:int, truncated:bool}
     */
    public function scan(?int $now = null): array
    {
        $start    = $now ?? time();
        $homeUrl  = (string) get_home_url();
        $homeHost = strtolower((string) (parse_url($homeUrl, PHP_URL_HOST) ?: ''));

        $query = new \WP_Query([
            'post_type'      => ['post', 'page'],
            'post_status'    => 'publish',
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'posts_per_page' => self::MAX_POSTS,
            'fields'         => 'ids',
        ]);
        $totalPosts = (int) $query->found_posts;

        $cache     = [];
        $findings  = [];
        $scanned   = 0;
        $truncated = $totalPosts > self::MAX_POSTS;

        foreach (array_map('intval', $query->posts) as $postId) {
            if ((time() - $start) >= self::MAX_SECONDS) { $truncated = true; break; }
            $scanned++;
            $content   = (string) get_post_field('post_content', $postId);
            $permalink = (string) get_permalink($postId);
            $title     = (string) get_the_title($postId);

            foreach ($this->extractor->extract($content, $homeUrl) as $occ) {
                $url = $occ['url'];
                if (!isset($cache[$url])) {
                    if (count($cache) >= self::MAX_LINKS) { $truncated = true; continue; }
                    if ((time() - $start) >= self::MAX_SECONDS) { $truncated = true; break 2; }
                    $cache[$url] = $this->checker->check($url);
                }
                $r = $cache[$url];
                if (!$r['transport_error'] && $r['status'] !== null && $r['status'] >= 200 && $r['status'] < 400) {
                    continue;
                }
                if (count($findings) >= self::MAX_FINDINGS) { $truncated = true; break 2; }
                $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
                $findings[] = [
                    'url'             => $url,
                    'status'          => $r['status'],
                    'transport_error' => $r['transport_error'],
                    'link_type'       => ($host !== '' && $host === $homeHost) ? 'internal' : 'external',
                    'source_url'      => $permalink,
                    'source_title'    => $title !== '' ? $title : null,
                    'anchor_text'     => $occ['anchor_text'] !== '' ? $occ['anchor_text'] : null,
                ];
            }
        }

        return [
            'links'         => $findings,
            'scanned_posts' => $scanned,
            'total_posts'   => $totalPosts,
            'checked_links' => count($cache),
            'truncated'     => $truncated,
        ];
    }
}
```

- [ ] **Step 4: Run, expect pass** — `composer test:integration -- --filter BrokenLinkScannerTest` → PASS.
- [ ] **Step 5: Commit** — `git add packages/connector-plugin/src/Links/ packages/connector-plugin/tests/Integration/Links/BrokenLinkScannerTest.php && git commit -m "feat(connector): BrokenLinkScanner — bounded crawl of posts -> findings (P7.1)"`

---

### Task 4: `LinksScanController` + route registration

**Files:**
- Create: `packages/connector-plugin/src/Rest/LinksScanController.php`
- Modify: `packages/connector-plugin/src/Rest/RestRouter.php` (add one `register_rest_route` in `register()`, signed)
- Test: `packages/connector-plugin/tests/Integration/Rest/LinksScanTest.php`

Mirror `ThemesController` (thin) but POST + signed + `ob_start()/ob_end_clean()` discipline (a scan can echo notices; guard the JSON body). Reference: `src/Rest/PluginUpdateController.php:60-88` for the ob_* pattern, `src/Rest/RestRouter.php:90-110` for registration with `VerifySignatureMiddleware::class, 'check'`.

- [ ] **Step 1: Write the failing test** — extends `WP_UnitTestCase`, sets a connected state + signs the request exactly like `tests/Integration/Rest/PluginsListTest.php:51-77` but `POST /defyn-connector/v1/links/scan` with empty body. Assert 401 unsigned, and 200 + body has keys `links`,`scanned_posts`,`truncated`,`server_time` when signed.
- [ ] **Step 2: Run, expect fail** — `composer test:integration -- --filter LinksScanTest` → FAIL (route 404 / class missing).
- [ ] **Step 3: Implement** the controller:

```php
<?php
declare(strict_types=1);
namespace Defyn\Connector\Rest;

use Defyn\Connector\Links\BrokenLinkScanner;
use WP_REST_Request;
use WP_REST_Response;

/** POST /defyn-connector/v1/links/scan — signed. Runs a bounded broken-link scan and returns the findings. */
final class LinksScanController
{
    public function __construct(private readonly BrokenLinkScanner $scanner = new BrokenLinkScanner()) {}

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        ob_start();
        try {
            $data                = $this->scanner->scan();
            $data['server_time'] = time();
            return new WP_REST_Response($data, 200);
        } finally {
            ob_end_clean();
        }
    }
}
```

Then add to `RestRouter::register()` (after the `/core/update` route):

```php
register_rest_route(self::NAMESPACE, '/links/scan', [
    'methods'             => 'POST',
    'callback'            => [new LinksScanController(), 'handle'],
    'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
]);
```

- [ ] **Step 4: Run, expect pass** — `composer test:integration -- --filter LinksScanTest` → PASS.
- [ ] **Step 5: Commit** — `git add packages/connector-plugin/src/Rest/LinksScanController.php packages/connector-plugin/src/Rest/RestRouter.php packages/connector-plugin/tests/Integration/Rest/LinksScanTest.php && git commit -m "feat(connector): POST /links/scan signed endpoint (P7.1)"`

---

### Task 5: Connector v0.1.8 bump + cache-header regression

**Files:**
- Modify: `packages/connector-plugin/defyn-connector.php` (line 6 header `Version:`, line 42 `DEFYN_CONNECTOR_VERSION`)
- Modify (if a version-pin test exists): `grep -rn "0.1.7" packages/connector-plugin/tests` and bump any literal
- Test: extend `LinksScanTest` with a cache-header assertion

- [ ] **Step 1:** Add to `LinksScanTest`: after a signed 200, assert the `Cache-Control` header contains `no-store` (route is under the namespace so `applyNoCacheHeaders` covers it). Dispatch via `rest_get_server()->dispatch($req)` like the sibling cache-header tests so the post-dispatch filter runs. Run → confirm pass.
- [ ] **Step 2:** Edit `defyn-connector.php:6` → `* Version:           0.1.8` and `:42` → `define('DEFYN_CONNECTOR_VERSION', '0.1.8');`.
- [ ] **Step 3:** `grep -rn "0\.1\.7" packages/connector-plugin` — bump any test that pins the version string.
- [ ] **Step 4: Run** — `cd packages/connector-plugin && composer test` → all green.
- [ ] **Step 5: Commit** — `git add packages/connector-plugin && git commit -m "chore(connector): v0.1.8 — broken-link scan endpoint (P7.1)"`

---

# PART B — DASHBOARD BACKEND (v0.27.0, schema v17)

### Task 6: Schema v17 — `SiteBrokenLinksTable` + `last_link_scan_at` + version-pin ripple

**Files:**
- Create: `packages/dashboard-plugin/src/Schema/SiteBrokenLinksTable.php`
- Modify: `packages/dashboard-plugin/src/Activation.php` (`SCHEMA_VERSION` 16→17 at line 33; add `SiteBrokenLinksTable::class` to `TABLES`; add `addLastLinkScanAtColumn($wpdb)` call in `ensureSchema()` + the guarded ALTER method mirroring `addLastSecurityScanAtColumn`)
- Modify: every schema-version-pin test that asserts `16` (find via `grep -rn "SCHEMA_VERSION\|SchemaVersion::current\|, 16)" packages/dashboard-plugin/tests | grep 16`)
- Create: `packages/dashboard-plugin/tests/Integration/Schema/BrokenLinksSchemaTest.php`
- **Git-add the whole `tests/Integration/` dir** when committing (P5.3 add-path lesson).

`Uninstaller` iterates `Activation::TABLES` (Uninstaller.php:20), so adding the class to `TABLES` is the only uninstall change needed.

- [ ] **Step 1: Write failing test** — `BrokenLinksSchemaTest` (extends `AbstractSchemaTestCase`):

```php
public function testActivationBumpsSchemaTo17(): void {
    Activation::activate();
    self::assertSame(17, Activation::SCHEMA_VERSION);
    self::assertSame(17, SchemaVersion::current());
}
public function testBrokenLinksTableExists(): void {
    global $wpdb; Activation::activate();
    $t = SiteBrokenLinksTable::tableName();
    self::assertSame($t, $wpdb->get_var("SHOW TABLES LIKE '{$t}'"));
}
public function testSitesHasLastLinkScanAtColumn(): void {
    global $wpdb; Activation::activate();
    $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `" . SitesTable::tableName() . "` LIKE %s", 'last_link_scan_at'));
    self::assertSame('last_link_scan_at', $col);
}
```

- [ ] **Step 2: Run, expect fail** — `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit --filter BrokenLinksSchemaTest` → FAIL.
- [ ] **Step 3: Implement** `SiteBrokenLinksTable.php` (mirror `SitePerformanceTable.php`):

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Schema;

final class SiteBrokenLinksTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_site_broken_links';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();
        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id BIGINT UNSIGNED NOT NULL,
            url VARCHAR(2048) NOT NULL,
            url_hash CHAR(40) NOT NULL,
            source_url VARCHAR(2048) NOT NULL,
            source_hash CHAR(40) NOT NULL,
            source_title VARCHAR(255) NULL,
            anchor_text VARCHAR(255) NULL,
            status_code SMALLINT UNSIGNED NULL,
            severity VARCHAR(10) NOT NULL,
            reason VARCHAR(20) NOT NULL,
            link_type VARCHAR(10) NOT NULL,
            first_detected_at DATETIME NOT NULL,
            last_detected_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_links_site_url_src (site_id, url_hash, source_hash),
            KEY idx_links_site (site_id),
            KEY idx_links_site_sev (site_id, severity)
        ) {$charset};";
    }
}
```

Then in `Activation.php`: bump `SCHEMA_VERSION` to `17`; add `SiteBrokenLinksTable::class` to the `TABLES` array; in `ensureSchema()` add `self::addLastLinkScanAtColumn($wpdb);` (mirror the `addLastSecurityScanAtColumn` SHOW-COLUMNS-guarded ALTER, column `last_link_scan_at DATETIME NULL`).

- [ ] **Step 4:** Bump every version-pin test from `16`→`17`. **Run** the full suite: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit` → green except `UninstallTest`.
- [ ] **Step 5: Commit** — `git add packages/dashboard-plugin/src/Schema/SiteBrokenLinksTable.php packages/dashboard-plugin/src/Activation.php packages/dashboard-plugin/tests/Integration/ && git commit -m "feat(dashboard): schema v17 — site_broken_links table + last_link_scan_at (P7.1)"`

---

### Task 7: `LinkClassifier` (pure)

**Files:**
- Create: `packages/dashboard-plugin/src/Services/LinkClassifier.php`
- Test: `packages/dashboard-plugin/tests/Unit/Services/LinkClassifierTest.php` (extends `PHPUnit\Framework\TestCase`)

- [ ] **Step 1: Write failing test** — parameterized via a data provider covering: `(null,true)→warning/unreachable`, `(404,false)→broken/not_found`, `(410,false)→broken/not_found`, `(500,false)→warning/server_error`, `(503,false)→warning/server_error`, `(403,false)→warning/blocked`, `(401,false)→warning/blocked`, `(429,false)→warning/blocked`, `(400,false)→warning/client_error`, `(418,false)→warning/client_error`.
- [ ] **Step 2: Run, expect fail.**
- [ ] **Step 3: Implement:**

```php
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
```

- [ ] **Step 4: Run, expect pass.**
- [ ] **Step 5: Commit** — `git commit -m "feat(dashboard): LinkClassifier — status -> severity/reason (P7.1)"`

---

### Task 8: `BrokenLinksRepository`

**Files:**
- Create: `packages/dashboard-plugin/src/Services/BrokenLinksRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/BrokenLinksRepositoryTest.php` (extends `AbstractSchemaTestCase`; copy `seedSite` from `ReportsRepositoryTest` — real cols `id,user_id,url,label,status,created_at,updated_at,wp_version`)

Methods (all use `global $wpdb` + `$wpdb->prepare` + `SiteBrokenLinksTable::tableName()`):
- `upsertForSite(int $siteId, array $finding, string $scanAt): void` — compute `url_hash=sha1(url)`, `source_hash=sha1(source_url)`; `INSERT ... ON DUPLICATE KEY UPDATE status_code=VALUES(status_code), severity=VALUES(severity), reason=VALUES(reason), link_type=VALUES(link_type), source_title=VALUES(source_title), anchor_text=VALUES(anchor_text), last_detected_at=VALUES(last_detected_at)` — `first_detected_at` set only on insert (`= $scanAt`).
- `pruneStaleForSite(int $siteId, string $scanAt): int` — `DELETE ... WHERE site_id=%d AND last_detected_at < %s`; return affected rows.
- `findForSite(int $siteId): array` — `ORDER BY (severity='broken') DESC, last_detected_at DESC` → array of assoc rows (`status_code` cast to ?int).
- `countsForSite(int $siteId): array` — single grouped query → `{broken,warning,total,internal,external}` (default zeros when no rows).
- `countSitesWithBrokenLinksForUser(int $userId): int` — `SELECT COUNT(DISTINCT bl.site_id) ... JOIN sites s ON s.id=bl.site_id WHERE s.user_id=%d AND bl.severity='broken'`.
- `findTopForReport(int $siteId, int $limit): array` — broken-first, `LIMIT %d`.

- [ ] **Step 1: Write failing tests:** `testUpsertInsertsThenUpdatesKeepingFirstDetected` (upsert same (site,url,source) twice with different scanAt → 1 row, `first_detected_at` == first scanAt, `last_detected_at` == second); `testPruneRemovesRowsOlderThanScan`; `testCountsForSite` (seed 2 broken + 1 warning, 1 internal/2 external → asserts); `testCountSitesWithBrokenLinksForUserCountsOnlyBroken` (a site with only warnings is NOT counted); `testFindForSiteBrokenFirst`.
- [ ] **Step 2: Run, expect fail.**
- [ ] **Step 3: Implement** the repository (mirror `SitePerformanceRepository` style; `$wpdb->query($wpdb->prepare(...))` for the upsert).
- [ ] **Step 4: Run, expect pass.**
- [ ] **Step 5: Commit** — `git commit -m "feat(dashboard): BrokenLinksRepository — upsert/prune/find/counts (P7.1)"`

---

### Task 9: `BrokenLinkScanService` (best-effort connector call)

**Files:**
- Create: `packages/dashboard-plugin/src/Services/BrokenLinkScanService.php`
- Modify: `packages/dashboard-plugin/src/Services/SitesRepository.php` (add `markLinkScannedAt(int $siteId, string $at): void` mirroring `markSecurityScannedAt`)
- Modify: `packages/dashboard-plugin/src/Models/Site.php` (add `lastLinkScanAt` — constructor + `fromRow('last_link_scan_at')` + `toJson('last_link_scan_at')`, mirroring `lastSecurityScanAt`)
- Test: `packages/dashboard-plugin/tests/Integration/Services/BrokenLinkScanServiceTest.php`

**CRITICAL — find the real signed-connector-call pattern first.** `PerformanceScanService` calls an HTTP API, NOT the connector. Grep for the canonical "dashboard POSTs to a connector endpoint with the site's decrypted key": `grep -rln "signedPostJson\|/defyn-connector/v1" packages/dashboard-plugin/src` (likely the plugin/theme/core **update jobs** or a `ConnectorClient`/`SyncService`). Read that site and mirror EXACTLY how it (a) decrypts the site's dashboard private key from the Vault, (b) builds the canonical path, (c) calls `SignedHttpClient::signedPostJson($url, [], $privKey, '/defyn-connector/v1/links/scan', 120)`, (d) reads `['status','body','error']`. Do NOT invent the key-decryption call.

Service shape (mirror `PerformanceScanService` constructor-injection + best-effort + `ActivityLogger`); add a `?callable $caller = null` seam returning `['status','body','error']` so tests avoid real HTTP:

```php
public function __construct(
    private readonly ?BrokenLinksRepository $repo = null,
    private readonly ?SitesRepository $sites = null,
    private $caller = null,   // ?callable(string $url, array $body, string $privKey, string $path):array
) {}

/** Best-effort. Never throws. */
public function scan(int $siteId): void
{
    $sites = $this->sites ?? new SitesRepository();
    $site  = $sites->findById($siteId);
    if ($site === null) { return; }

    $now = gmdate('Y-m-d H:i:s');
    $resp = ($this->caller ?? $this->defaultCaller())($site, [], '/defyn-connector/v1/links/scan');

    $sites->markLinkScannedAt($siteId, $now);   // always advance "last checked"

    if (($resp['error'] ?? '') !== '' || (int)($resp['status'] ?? 0) !== 200 || !is_array($resp['body']['links'] ?? null)) {
        return; // best-effort failure: nothing stored, no event
    }

    $repo = $this->repo ?? new BrokenLinksRepository();
    foreach ($resp['body']['links'] as $f) {
        $cls = LinkClassifier::classify(isset($f['status']) ? (int)$f['status'] : null, (bool)($f['transport_error'] ?? false));
        $repo->upsertForSite($siteId, [
            'url' => (string)$f['url'], 'source_url' => (string)$f['source_url'],
            'source_title' => $f['source_title'] ?? null, 'anchor_text' => $f['anchor_text'] ?? null,
            'status_code' => isset($f['status']) ? (int)$f['status'] : null,
            'severity' => $cls['severity'], 'reason' => $cls['reason'],
            'link_type' => (string)($f['link_type'] ?? 'external'),
        ], $now);
    }
    $repo->pruneStaleForSite($siteId, $now);
    $counts = $repo->countsForSite($siteId);
    (new ActivityLogger())->log($site->userId, $siteId, 'links.scan_completed', [
        'broken' => $counts['broken'], 'warning' => $counts['warning'],
        'truncated' => (bool)($resp['body']['truncated'] ?? false),
    ]);
}

// defaultCaller(): builds the signed connector POST EXACTLY as the canonical existing
// connector-call site does (decrypt site dashboard key from Vault → SignedHttpClient::signedPostJson).
```

- [ ] **Step 1: Write failing tests** — inject the `$caller` seam: (a) success body with 1 broken (404) + 1 warning (503) → asserts 2 rows + `last_link_scan_at` set + 1 `links.scan_completed` activity row; (b) `error` non-empty → 0 rows + `last_link_scan_at` STILL set + 0 activity rows; (c) connector `status:404` (old connector) → same as (b).
- [ ] **Step 2: Run, expect fail.**
- [ ] **Step 3: Implement** the service + `markLinkScannedAt` + `Site::$lastLinkScanAt` (with `defaultCaller()` mirroring the canonical signed-call site).
- [ ] **Step 4: Run, expect pass.**
- [ ] **Step 5: Commit** — `git commit -m "feat(dashboard): BrokenLinkScanService — best-effort connector scan + persist (P7.1)"`

---

### Task 10: Jobs `LinkScan` + `LinkScanAll` + Scheduler + self-heal + Plugin::boot

**Files:**
- Create: `packages/dashboard-plugin/src/Jobs/LinkScan.php`, `packages/dashboard-plugin/src/Jobs/LinkScanAll.php`
- Modify: `src/Jobs/Scheduler.php` (add `LinkScanAll::HOOK => WEEK_IN_SECONDS` to `SCHEDULES`)
- Modify: `src/Activation.php` (add the self-heal ensure-scheduled guard block keyed on `LinkScanAll::HOOK`, mirroring the `PerformanceScanAll` block)
- Modify: `src/Plugin.php` (add the two `add_action(LinkScanAll::HOOK, …)` + `add_action(LinkScan::HOOK, …)` registrations, mirroring the P6.1 block ~Plugin.php:143-149)
- Test: `packages/dashboard-plugin/tests/Integration/Jobs/LinkScanAllTest.php`

`LinkScan` mirrors `PerformanceScan` (HOOK `'defyn_link_scan'`, `handle(int $siteId)` → `BrokenLinkScanService::scan`). `LinkScanAll` mirrors `PerformanceScanAll` (HOOK `'defyn_link_scan_all'`, `handle()` fans out `as_schedule_single_action(time(), LinkScan::HOOK, [$siteId], 'defyn')` over the **same schedulable-sites query `PerformanceScanAll` uses** — confirm the real method name; recon called it `findAllSchedulable`, verify against `PerformanceScanAll`).

- [ ] **Step 1: Write failing test** — mirror `tests/Integration/Jobs/PerformanceScanAllTest.php`: seed 2 connected sites, run `LinkScanAll::handle()`, assert 2 `LinkScan` actions enqueued in group `defyn` (via `as_get_scheduled_actions` as the Performance test does).
- [ ] **Step 2: Run, expect fail.**
- [ ] **Step 3: Implement** the two jobs + Scheduler entry + Activation self-heal guard + Plugin::boot registration.
- [ ] **Step 4: Run, expect pass** + full suite green.
- [ ] **Step 5: Commit** — `git commit -m "feat(dashboard): LinkScan/LinkScanAll jobs + weekly schedule + self-heal (P7.1)"`

---

### Task 11: RateLimit `linksScan` + `linksRead`

**Files:**
- Modify: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php` (add 4 consts + 2 static methods, mirroring `performanceScan`/`performanceRead` exactly)
- Test: `packages/dashboard-plugin/tests/Integration/Rest/RateLimitLinksTest.php` (or extend the existing RateLimit test file)

Consts: `LINKS_SCAN_LIMIT=6`, `LINKS_SCAN_WINDOW=HOUR_IN_SECONDS`, `LINKS_READ_LIMIT=30`, `LINKS_READ_WINDOW=MINUTE_IN_SECONDS`. Keys `defyn_rl_linksScan_%d_%d` / `defyn_rl_linksRead_%d_%d`. Error code `links.rate_limited` (status 429).

- [ ] **Step 1: Write failing test** — call `RateLimit::linksScan($req)` 7× with a stubbed `_authenticated_user_id` + `id`; assert the 7th returns a `WP_Error` with `['status'=>429]` and code `links.rate_limited` (mirror the performance rate-limit test).
- [ ] **Step 2: Run, expect fail.**
- [ ] **Step 3: Implement** both methods (copy `performanceScan`/`performanceRead`, s/performance/links/, code `links.rate_limited`).
- [ ] **Step 4: Run, expect pass.**
- [ ] **Step 5: Commit** — `git commit -m "feat(dashboard): RateLimit linksScan 6/hr + linksRead 30/min (P7.1)"`

---

### Task 12: REST endpoints + routes + CORS

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/SitesBrokenLinksController.php` (GET), `packages/dashboard-plugin/src/Rest/SitesLinksScanController.php` (POST→202)
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php` (2 routes mirroring the P6.1 performance routes ~RestRouter.php:422-434)
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SitesBrokenLinksTest.php`

GET handler: ownership via `SitesRepository::findByIdForUser($siteId,$userId)` → 404 `sites.not_found`; else `new WP_REST_Response(['data' => ['last_link_scan_at' => $site->lastLinkScanAt, 'counts' => $repo->countsForSite($siteId), 'links' => $repo->findForSite($siteId)], 'error' => null], 200)`. POST handler: same ownership-404, then `as_enqueue_async_action(LinkScan::HOOK, [$siteId], 'defyn')`, return 202 `['data'=>['scheduled'=>true],'error'=>null]`. Routes use `permission_callback => [RateLimit::class, 'linksRead' | 'linksScan']`.

- [ ] **Step 1: Write failing tests** (mirror the performance controller tests): GET no-auth→401; GET ownership 404 `sites.not_found`; GET owned→200 with `data.counts`+`data.links`+`data.last_link_scan_at`; POST owned→202 `data.scheduled===true` + 1 `LinkScan` action enqueued; plus a CORS-preflight assertion mirroring the sibling CORS test for both routes.
- [ ] **Step 2: Run, expect fail.**
- [ ] **Step 3: Implement** both controllers + register both routes.
- [ ] **Step 4: Run, expect pass** + full suite green.
- [ ] **Step 5: Commit** — `git commit -m "feat(dashboard): GET /broken-links + POST /links/scan endpoints (P7.1)"`

---

### Task 13: Overview `has_broken_links` attention reason

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/SitesRepository.php` (`findSitesNeedingAttention` — add a `CASE WHEN EXISTS(SELECT 1 FROM {broken_links} bl WHERE bl.site_id=s.id AND bl.severity='broken') THEN 1 ELSE 0 END AS has_broken_links` column + a `has_broken_links` reason push + extend the `HAVING`/where so a site with broken links qualifies)
- Possibly: `OverviewService` if it whitelists reason strings
- Test: `packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryAttentionTest.php` (or the existing overview/attention test)

- [ ] **Step 1: Write failing test** — seed an owned site with 1 `broken` row → `findSitesNeedingAttention($userId)` includes that site with `'has_broken_links'` in its `reasons`; a site with only `warning` rows does NOT get the reason.
- [ ] **Step 2: Run, expect fail.**
- [ ] **Step 3: Implement** mirroring the `has_vulnerabilities` EXISTS pattern (recon: SitesRepository.php ~707-765).
- [ ] **Step 4: Run, expect pass.**
- [ ] **Step 5: Commit** — `git commit -m "feat(dashboard): Overview has_broken_links attention reason (P7.1)"`

---

### Task 14: Report `broken_links` — `ReportService` + `ReportPdfService`

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/ReportService.php` (constructor DI `?BrokenLinksRepository $brokenLinks = null`; add `buildBrokenLinks(int $siteId): array`; add `'broken_links' => $this->buildBrokenLinks($siteId)` to the `compose()` return)
- Modify: `packages/dashboard-plugin/src/Services/ReportPdfService.php` (add `renderBrokenLinks(?array $data): string`, tolerate null/missing → "Not yet checked", insert into the section sequence; every value `esc()`'d)
- Test: extend `ReportServiceTest` + `ReportPdfServiceTest`

`buildBrokenLinks` (reads cached rows, NEVER calls the connector):
```php
private function buildBrokenLinks(int $siteId): array
{
    $repo   = $this->brokenLinks ?? new BrokenLinksRepository();
    $site   = ($this->sites ?? new SitesRepository())->findById($siteId); // for last_link_scan_at
    $last   = $site?->lastLinkScanAt;
    $counts = $repo->countsForSite($siteId);
    $state  = $last === null ? 'not_checked' : (($counts['total'] ?? 0) === 0 ? 'clean' : 'issues');
    $items  = [];
    if ($state === 'issues') {
        foreach ($repo->findTopForReport($siteId, 20) as $r) {
            $items[] = [
                'url' => $r['url'], 'status_code' => $r['status_code'] ?? null,
                'severity' => $r['severity'], 'reason' => $r['reason'],
                'link_type' => $r['link_type'], 'source_url' => $r['source_url'],
            ];
        }
    }
    return ['state' => $state, 'last_scanned' => $last, 'counts' => $counts, 'items' => $items];
}
```

`ReportPdfService::renderBrokenLinks` — counts strip + a capped HTML table (no chart, no new dompdf primitive); tolerate `$data===null` (old sample reports) → `''`; `state==='not_checked'` → "Broken links — not yet checked"; `state==='clean'` → "No broken links found"; `issues` → counts + table. `esc()` every interpolated value.

- [ ] **Step 1: Write failing tests:** `ReportServiceTest::testBrokenLinksNotCheckedWhenNeverScanned` (last null → state `not_checked`, items `[]`); `testBrokenLinksCleanWhenScannedZero`; `testBrokenLinksIssuesListsTopBrokenFirst`. `ReportPdfServiceTest::testBrokenLinksSectionRendersIssues` (HTML contains the URL + a counts label) + `testBrokenLinksToleratesMissingKey` (sample report lacking `broken_links` → no fatal, existing tests stay green).
- [ ] **Step 2: Run, expect fail.**
- [ ] **Step 3: Implement** both.
- [ ] **Step 4: Run, expect pass** + full PHP suite green (tolerate only `UninstallTest`).
- [ ] **Step 5: Commit** — `git commit -m "feat(dashboard): report broken_links section (service + PDF) (P7.1)"`

---

### Task 15: Dashboard v0.27.0 bump

**Files:**
- Modify: `packages/dashboard-plugin/defyn-dashboard.php` (line 6 header `Version: 0.27.0`, line 46 `define('DEFYN_DASHBOARD_VERSION', '0.27.0')`)

- [ ] **Step 1:** Edit both lines. `grep -rn "0\.26\.0" packages/dashboard-plugin/src packages/dashboard-plugin/defyn-dashboard.php` — bump any other live reference.
- [ ] **Step 2: Run** full PHP suite → green except `UninstallTest`.
- [ ] **Step 3: Commit** — `git commit -m "chore(dashboard): v0.27.0 — broken-link monitoring (P7.1)"`

---

# PART C — SPA (apps/web)

### Task 16: SPA schemas + MSW + hooks

**Files:**
- Modify: `apps/web/src/types/api.ts` (add `siteBrokenLinksSchema`, `reportBrokenLinksSchema`, extend `siteReportSchema` with `broken_links`, add `'has_broken_links'` to `overviewAttentionReasonSchema`)
- Create: `apps/web/src/lib/queries/useSiteBrokenLinks.ts`
- Create: `apps/web/src/lib/mutations/useScanSiteLinks.ts`
- Modify: `apps/web/src/test/handlers.ts` (GET `/broken-links` empty default, POST `/links/scan` 202, report fixture `broken_links`)
- Test: `apps/web/tests/useScanSiteLinks.test.tsx`, `apps/web/tests/api.brokenLinks.test.ts` (schema parse)

Schemas (match the locked contracts exactly):
```typescript
export const brokenLinkRowSchema = z.object({
  url: z.string(),
  status_code: z.number().int().nullable(),
  severity: z.enum(['broken', 'warning']),
  reason: z.enum(['not_found', 'server_error', 'blocked', 'client_error', 'unreachable']),
  link_type: z.enum(['internal', 'external']),
  source_url: z.string(),
  source_title: z.string().nullable(),
  anchor_text: z.string().nullable(),
  first_detected_at: z.string(),
  last_detected_at: z.string(),
});
export const brokenLinkCountsSchema = z.object({
  broken: z.number(), warning: z.number(), total: z.number(), internal: z.number(), external: z.number(),
});
export const siteBrokenLinksSchema = z.object({
  last_link_scan_at: z.string().nullable(),
  counts: brokenLinkCountsSchema,
  links: z.array(brokenLinkRowSchema),
});
export type SiteBrokenLinks = z.infer<typeof siteBrokenLinksSchema>;

export const reportBrokenLinksSchema = z.object({
  state: z.enum(['not_checked', 'clean', 'issues']),
  last_scanned: z.string().nullable(),
  counts: brokenLinkCountsSchema,
  items: z.array(z.object({
    url: z.string(), status_code: z.number().int().nullable(),
    severity: z.enum(['broken', 'warning']),
    reason: z.enum(['not_found', 'server_error', 'blocked', 'client_error', 'unreachable']),
    link_type: z.enum(['internal', 'external']), source_url: z.string(),
  })),
});
```
Add `broken_links: reportBrokenLinksSchema` to `siteReportSchema`. Add `'has_broken_links'` to `overviewAttentionReasonSchema`.

`useSiteBrokenLinks` (mirror `useSiteVulnerabilities`): queryKey `['siteBrokenLinks', siteId]`, GET `/sites/${siteId}/broken-links`, parse `z.object({data: siteBrokenLinksSchema, error: z.null()}).parse(raw).data`, `staleTime: 30_000`, optional `refetchInterval`.

`useScanSiteLinks` (mirror `useScanSiteSecurity` bounded-poll EXACTLY — `preScanRef` captures `last_link_scan_at` BEFORE the POST, poll `useSiteBrokenLinks(siteId, {refetchInterval: isPolling ? 2000 : false})`, stop effect keyed on the **primitive** `query.data?.last_link_scan_at` + `isPolling` (NEVER an object/array — the P2.10 render-loop lesson), 60s hard-timeout effect, POST `/sites/${siteId}/links/scan`, on success invalidate `['siteBrokenLinks', siteId]` + start polling).

MSW:
```typescript
http.get('*/wp-json/defyn/v1/sites/:id/broken-links', () =>
  HttpResponse.json({ data: { last_link_scan_at: null, counts: { broken:0,warning:0,total:0,internal:0,external:0 }, links: [] }, error: null })),
http.post('*/wp-json/defyn/v1/sites/:id/links/scan', () =>
  HttpResponse.json({ data: { scheduled: true }, error: null }, { status: 202 })),
```
Add a `broken_links` block to the report fixture (state `issues`, 2 items: one `broken/not_found` 404 + one `warning/server_error` 503).

- [ ] **Step 1: Write failing tests** — `api.brokenLinks.test.ts` parses a full `siteBrokenLinksSchema` payload + a report with `broken_links`; `useScanSiteLinks.test.tsx` mirrors `useScanSiteSecurity.test.tsx` (scan → `isPolling` true).
- [ ] **Step 2: Run, expect fail** — `pnpm test -- --run api.brokenLinks useScanSiteLinks`.
- [ ] **Step 3: Implement** schemas + hooks + MSW + fixture.
- [ ] **Step 4: Run, expect pass.**
- [ ] **Step 5: Commit** — `git commit -m "feat(spa): broken-links schemas + hooks + MSW (P7.1)"`

---

### Task 17: SPA components — panel, report section, chip, mounts

**Files:**
- Create: `apps/web/src/components/sites/SiteBrokenLinksPanel.tsx`
- Create: `apps/web/src/components/report/ReportBrokenLinks.tsx`
- Modify: `apps/web/src/components/overview/AttentionReasonChip.tsx` (add `has_broken_links` to `PALETTE`)
- Modify: `apps/web/src/routes/SiteDetail.tsx` (import + mount `<SiteBrokenLinksPanel siteId={siteId} />` after `<SiteSecurityPanel>`, gated on `data.status !== 'pending'`)
- Modify: `apps/web/src/pages/SiteReport.tsx` (import + mount `<ReportBrokenLinks broken_links={data.broken_links} />` after `<ReportSecurity>`)
- Test: `apps/web/tests/components/sites/SiteBrokenLinksPanel.test.tsx`, `apps/web/tests/components/report/ReportBrokenLinks.test.tsx`

> Confirm the real paths first: SiteDetail may be `src/routes/SiteDetail.tsx` and the report page `src/pages/SiteReport.tsx` (recon reported both). Match whatever exists.

`SiteBrokenLinksPanel` (mirror `SiteSecurityPanel` structure; design-system tokens, post-redesign): header "Broken links" + counts + last-scanned + a "Check links now" `Button` (disabled while `isPending || isPolling`); states — loading / error / `last_link_scan_at===null` → "Not checked yet" / `counts.total===0` → "No broken links found 🎉" / else group `links` by `source_url`, each row: severity pill (`broken` → destructive tokens, `warning` → warning tokens), the `url` (truncating), `status_code` (or "—"), an internal/external chip, anchor text muted.

`ReportBrokenLinks` (mirror `ReportSecurity`): lucide `Unlink`/`AlertTriangle` icon + "Broken links" heading + inline badge (`counts.broken>0` destructive else success); `state==='not_checked'` → "Not checked yet" line; `clean` → "No broken links found"; `issues` → counts strip + a table of `items` (severity, status, URL, source page). Read-only (no buttons).

`AttentionReasonChip` PALETTE add: `has_broken_links: { className: 'bg-red-100 text-red-800', label: 'broken links' }`.

- [ ] **Step 1: Write failing tests** — `SiteBrokenLinksPanel.test.tsx` (mirror `SitePerformancePanel.test.tsx`): renders "Not checked yet" when `last_link_scan_at` null; renders a broken row + status when the GET returns issues; clicking "Check links now" disables the button (POST 202). `ReportBrokenLinks.test.tsx`: renders the items table in `issues`; renders the not-checked line in `not_checked`.
- [ ] **Step 2: Run, expect fail.**
- [ ] **Step 3: Implement** the components + the 4 mounts/edits.
- [ ] **Step 4: Run** — `pnpm test -- --run` → all pass except the 4 carry-forward; then `pnpm build` (tsc) clean.
- [ ] **Step 5: Commit** — `git commit -m "feat(spa): SiteBrokenLinksPanel + ReportBrokenLinks + Overview chip + mounts (P7.1)"`

---

# PART D — RELEASE

### Task 18: Release v0.27.0 / connector v0.1.8

**Files:** build artifacts only; no src changes beyond what shipped above.

- [ ] **Step 1: Full suites green**
  - Connector: `cd packages/connector-plugin && composer test` → all green.
  - Dashboard: `cd packages/dashboard-plugin && COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit` → green except `UninstallTest`.
  - SPA: `cd apps/web && pnpm test -- --run` → green except the 4 carry-forward; `pnpm build` (tsc) clean.
- [ ] **Step 2: Local PDF eyeball** — render a report whose `broken_links` is in `issues` state through `ReportPdfService::render` to `.claude-tmp/p7-1-report.pdf` (reuse the P6.5 throwaway-script approach; trim `$report` to what `buildHtml` needs, populate `broken_links`); confirm `%PDF` + the section/table draws + the URL text appears.
- [ ] **Step 3: Build zips** (dompdf-preserving for the dashboard):
  - **Connector:** `cd packages/connector-plugin && composer install --no-dev --classmap-authoritative`, zip the canonical plugin folder to `dist/defyn-connector-0.1.8.zip` (match the folder name used by prior connector zips in `dist/`), exclude tests/dev tooling, then `composer install` to restore dev autoload.
  - **Dashboard:** `cd packages/dashboard-plugin && composer install --no-dev --classmap-authoritative`, zip top folder `dashboard-plugin/` to `dist/defyn-dashboard-0.27.0.zip`, exclude ONLY tests/dev tooling NEVER `vendor/*`, then **VERIFY** present in the zip: `vendor/symfony/deprecation-contracts/function.php`, `vendor/symfony/polyfill-php83/bootstrap.php`, json-machine `Items.php`, `vendor/dompdf/dompdf/src/Dompdf.php`, `vendor/dompdf/php-svg-lib/...`, `vendor/firebase/php-jwt/src/JWT.php`, `src/Schema/SiteBrokenLinksTable.php`. Then `composer install` to restore dev autoload.
- [ ] **Step 4: Merge to main** — `git checkout main && git merge --no-ff p7-1-broken-links -m "merge: P7.1 broken-link monitoring (connector v0.1.8 + dashboard v0.27.0)"`; `git push origin main` (SPA auto-deploys via Cloudflare).
- [ ] **Step 5: PAUSE for manual installs** — instruct the operator:
  1. Install **`dist/defyn-dashboard-0.27.0.zip`** on Kinsta via **Replace in place — NEVER delete** (schema v17 auto-applies); clear Kinsta cache.
  2. Install **`dist/defyn-connector-0.1.8.zip`** on each managed site (in-place replace). When reconnecting SmartCoding (wiped earlier), use the v0.1.8 connector.
  Wait for "installed".
- [ ] **Step 6: Indirect prod curl smoke** (creds curl-only, login field `access_token`; `pradeep@defyn.com.au` / `DefynWP-ifirCh5pXm5bTOj0`; backend `defynwp.defyn.agency`; UI password entry stays prohibited):
  - `POST /sites/999999/links/scan` no-auth → 401 `auth.missing_token`; auth → 404 `sites.not_found`.
  - `GET /sites/999999/broken-links` auth → 404 `sites.not_found`.
  - bogus `/sites/1/links/scanz` → `rest.route_not_found`.
  - Happy path (real broken-links scan) validated once SmartCoding is reconnected — operator-driven.
- [ ] **Step 7: Cloudflare deploy verify** — deployed SPA bundle contains a P7.1 literal (e.g. `Check links now` or `Broken links`).
- [ ] **Step 8: Tag + MEMORY** — `git tag p7-1-broken-links-complete && git push origin p7-1-broken-links-complete`; update MEMORY (new topic file + one-line MEMORY.md pointer; note connector v0.1.8 + dashboard v0.27.0 + schema v17 + the new scanner).

---

## Self-Review

**Spec coverage:** §3 classification → Task 7. §4.1 connector (LinkExtractor/LinkChecker/BrokenLinkScanner/LinksScanController/version) → Tasks 1-5. §4.2 dashboard (schema/repository/scan-service/jobs/RateLimit/REST/Overview/report/version) → Tasks 6-15. §4.3 SPA (schemas/hooks/panel/report/chip/mounts) → Tasks 16-17. §6 rollout (two installs, Replace-not-delete) → Task 18. §7 testing → every task's TDD steps + Task 18 local PDF eyeball. §8 smoke → Task 18 Step 6. No spec section is unmapped.

**Placeholder scan:** the only "find the real pattern" instruction is Task 9's signed-connector-call (deliberate — the exact Vault key-decryption call must be read from the canonical site, not guessed); all other code is concrete. `findAllSchedulable` (Task 10) and the `markSecurityScannedAt` mirror (Task 9) are flagged "confirm the real method name" rather than assumed.

**Type/name consistency:** endpoint paths, `counts`/`Row`/`Item`/`Finding` shapes, `reason` enum, `severity` enum, HOOK constants, RateLimit keys, schema columns, and the `broken_links` report key are all pinned identically in the "Locked contracts" block and reused verbatim in Tasks 1-18. SPA Zod enums (`reason`, `severity`, `link_type`) match the PHP-emitted strings. `last_link_scan_at` (list + bounded-poll key) is consistent across Tasks 6, 9, 12, 16.
