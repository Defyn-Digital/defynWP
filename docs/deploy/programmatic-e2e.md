# Programmatic E2E Smoke Runbook

> Foundation E2E procedure: exercise the full handshake -> sync -> ping -> activity -> disconnect chain against a live local WP stack. Use this to verify deploy-readiness after F10 lands and again post-deploy (adapted for prod URLs).

## What this smoke proves

| Step | Code path exercised | Pass criteria |
|---|---|---|
| 1 | Connector `CodeGenerator::generate` | Code persisted in `wp_options['defyn_connector']` with state `awaiting-handshake` |
| 2 | Dashboard `Connection::complete` -> signed POST `/connect` -> connector verifies challenge | Site row flips `pending` -> `active`; connector flips to `connected` |
| 3 | Dashboard `SyncService::sync` -> signed GET `/status` -> connector returns site info | `wp_version`, `php_version`, `last_sync_at` populated; `site.synced` event logged |
| 4 | Dashboard `HealthService::ping` -> signed GET `/heartbeat` | `last_contact_at` advances; `site.health_ok` event logged |
| 5 | `ActivityLogRepository::paginateForUser` | All expected events appear newest-first |
| 6 | Dashboard `DisconnectService::disconnect` -> signed POST `/disconnect` | **Connector state resets to `unconfigured`** + dashboard row deleted + `site.disconnected` event logged |

**Step 6 is the litmus test for F10 Task 1's signed-body fix.** Before F10, the connector silently rejected the signed POST due to a body-bytes mismatch, and soft-disconnect's failure-tolerance absorbed the rejection silently. After F10, the connector accepts the POST and resets its own state.

## Prerequisites

- Local-by-Flywheel installed with the `defynWP` site present at `~/Local Sites/defynWP/`
- Both `defyn-dashboard` and `defyn-connector` plugins activated on the same Local site (this is how F5-F10 smokes worked — the dashboard's outbound HTTP calls land on the same WP install, hitting the connector's REST endpoints)
- Local site Reachable on `http://localhost:10139/` (or whatever port Local assigned)
- `DEFYN_VAULT_KEY` defined in the site's `wp-config.php`

## Pre-flight: ensure Local is running

```bash
# Start Local-by-Flywheel from the menu bar OR (if installed via brew/cask):
open -a Local

# After Local starts, click the defynWP site and confirm it's marked "Running"

# Confirm reachability:
curl -sI http://localhost:10139/ | head -3
# Expected: HTTP/1.1 200 OK
```

If the site uses a different port, update the `HTTP_HOST` value in the smoke scripts below.

## Bundled PHP + MySQL socket paths

Local bundles its own PHP. Find the right binary:

```bash
ls "$HOME/Library/Application Support/Local/lightning-services/" | grep php-8
# Typical: php-8.2.27+1
```

MySQL socket:

```bash
ls "$HOME/Library/Application Support/Local/run/"
# Typical: 50bJKdbjK/mysql/mysqld.sock
```

Set shell variables for convenience:

```bash
export SOCK="$HOME/Library/Application Support/Local/run/50bJKdbjK/mysql/mysqld.sock"
export PHP="$HOME/Library/Application Support/Local/lightning-services/php-8.2.27+1/bin/darwin/bin/php"
```

## Step 0: Reset connector state

Earlier phase smokes may have left the connector at `state=connected` with a stale `dashboard_public_key`. Reset for a clean handshake.

Save as `/tmp/f10-reset-connector.php`:

```php
<?php
define('ABSPATH', '/Users/pradeep/Local Sites/defynWP/app/public/');
$_SERVER['HTTP_HOST'] = 'localhost:10139';
$_SERVER['REQUEST_URI'] = '/';
require ABSPATH . 'wp-load.php';

(new Defyn\Connector\Storage\ConnectorState())->update([
    'state'                => 'unconfigured',
    'dashboard_public_key' => '',
    'connected_at'         => '',
    'connection_code'      => '',
    'site_nonce'           => '',
    'code_created_at'      => 0,
    'code_expires_at'      => 0,
]);
echo "OK: connector state reset to unconfigured\n";
```

Run:

```bash
"$PHP" -d "mysqli.default_socket=$SOCK" -d "pdo_mysql.default_socket=$SOCK" /tmp/f10-reset-connector.php
```

Expected: `OK: connector state reset to unconfigured`

## Step 1-6: Run the full E2E smoke

Save as `/tmp/f10-e2e-smoke.php`:

```php
<?php
// F10 programmatic E2E. See docs/deploy/programmatic-e2e.md for explanation.

define('ABSPATH', '/Users/pradeep/Local Sites/defynWP/app/public/');
$_SERVER['HTTP_HOST'] = 'localhost:10139';
$_SERVER['REQUEST_URI'] = '/';
require ABSPATH . 'wp-load.php';

global $wpdb;

$userId = 1;
$siteUrl = 'http://localhost:10139';

function fail(string $msg): void { echo "FAIL: $msg\n"; exit(1); }
function ok(string $msg): void { echo "OK: $msg\n"; }
function connectorState(): array {
    $opt = get_option('defyn_connector', null);
    if (is_string($opt)) { $d = json_decode($opt, true); return is_array($d) ? $d : []; }
    return is_array($opt) ? $opt : [];
}

// 1. Generate code on connector
$cs = new Defyn\Connector\Storage\ConnectorState();
$cs->update(['state' => 'unconfigured', 'dashboard_public_key' => '', 'connected_at' => '']);
$gen = Defyn\Connector\Admin\CodeGenerator::generate();
$cs->update([
    'state'           => 'awaiting-handshake',
    'connection_code' => $gen['code'],
    'site_nonce'      => $gen['nonce'],
    'code_created_at' => $gen['created_at'],
    'code_expires_at' => $gen['expires_at'],
]);
ok("Generated code: {$gen['code']}");

// 2. Insert pending site + run handshake
$repo  = new Defyn\Dashboard\Services\SitesRepository();
$vault = new Defyn\Dashboard\Crypto\Vault(DEFYN_VAULT_KEY);
$kp    = Defyn\Dashboard\Crypto\KeyPair::generate();
$siteId = $repo->insertPending(
    userId: $userId, url: $siteUrl, label: 'F10 E2E',
    ourPublicKey: $kp->publicKey,
    ourPrivateKeyEncrypted: $vault->encrypt($kp->privateKey),
);
(new Defyn\Dashboard\Jobs\CompleteConnection())->handle($siteId, $gen['code'], $siteUrl);

$row = $wpdb->get_row($wpdb->prepare("SELECT status, last_error FROM wp_defyn_sites WHERE id=%d", $siteId), ARRAY_A);
if ($row['status'] !== 'active') fail("Handshake failed: status={$row['status']}, error={$row['last_error']}");
if ((connectorState()['state'] ?? null) !== 'connected') fail("Connector did not reach connected state");
ok("Handshake: site=$siteId active, connector=connected");

// 3. Sync
(new Defyn\Dashboard\Services\SyncService())->sync($siteId);
$row = $wpdb->get_row($wpdb->prepare("SELECT wp_version, last_sync_at, last_error FROM wp_defyn_sites WHERE id=%d", $siteId), ARRAY_A);
if (!empty($row['last_error'])) fail("Sync failed: {$row['last_error']}");
if (empty($row['wp_version']))   fail("wp_version still empty");
ok("Sync: wp_version={$row['wp_version']}, last_sync_at={$row['last_sync_at']}");

// 4. Ping
$before = $row['last_sync_at'];
sleep(1);
(new Defyn\Dashboard\Services\HealthService())->ping($siteId);
$row = $wpdb->get_row($wpdb->prepare("SELECT last_contact_at, last_error FROM wp_defyn_sites WHERE id=%d", $siteId), ARRAY_A);
if (!empty($row['last_error']))           fail("Ping failed: {$row['last_error']}");
if ($row['last_contact_at'] === $before) fail("last_contact_at did not advance");
ok("Ping: last_contact_at={$row['last_contact_at']}");

// 5. Activity feed
$ar = new Defyn\Dashboard\Services\ActivityLogRepository();
$events = $ar->paginateForUser($userId, $siteId, null, 1, 10);
$types = array_map(fn($e) => $e->eventType, $events);
foreach (['site.connected', 'site.synced', 'site.health_ok'] as $expected) {
    if (!in_array($expected, $types, true)) fail("Missing event: $expected");
}
ok("Activity: " . count($events) . " events, all expected types present");

// 6. Disconnect (litmus test for F10 Task 1)
if (!(new Defyn\Dashboard\Services\DisconnectService())->disconnect($siteId, $userId)) {
    fail("Disconnect returned false");
}
if ($wpdb->get_row($wpdb->prepare("SELECT id FROM wp_defyn_sites WHERE id=%d", $siteId)) !== null) {
    fail("Dashboard row still exists after disconnect");
}
$finalState = connectorState()['state'] ?? '(unknown)';
if ($finalState !== 'unconfigured') {
    fail("Connector did NOT reset (state='$finalState') — F10 Task 1 signed-body fix didn't propagate end-to-end");
}
ok("Disconnect: connector reset to unconfigured (Task 1 fix confirmed E2E)");

echo "\nFoundation E2E green end-to-end.\n";
```

Run:

```bash
"$PHP" -d "mysqli.default_socket=$SOCK" -d "pdo_mysql.default_socket=$SOCK" -d "memory_limit=512M" /tmp/f10-e2e-smoke.php
```

### Expected output

```
OK: Generated code: AB12CD34EF56
OK: Handshake: site=N active, connector=connected
OK: Sync: wp_version=6.X.X, last_sync_at=2026-XX-XX HH:MM:SS
OK: Ping: last_contact_at=2026-XX-XX HH:MM:SS
OK: Activity: N events, all expected types present
OK: Disconnect: connector reset to unconfigured (Task 1 fix confirmed E2E)

Foundation E2E green end-to-end.
```

### Cleanup

```bash
rm /tmp/f10-reset-connector.php /tmp/f10-e2e-smoke.php
```

## Failure diagnosis

| Failure | Likely cause |
|---|---|
| `Handshake failed: error=Failed to connect to localhost port ...` | Local-by-Flywheel isn't running or site is stopped. Start Local. |
| `Handshake failed: connector.signature_invalid` | Crypto byte-parity drift between dashboard and connector Signers. Re-run `vendor/bin/phpunit --filter SignerCanonicalTest` in both plugins. |
| `Sync failed: connector.not_connected` | Connector state was reset between handshake and sync. Re-run from Step 0. |
| `Sync failed: Failed to decrypt site keypair` | `DEFYN_VAULT_KEY` constant in `wp-config.php` was changed between insertPending and sync. Don't rotate vault keys mid-test. |
| `Connector did NOT reset (state='connected')` | F10 Task 1's signed-body fix didn't make it through. Re-run `vendor/bin/phpunit --filter SignedHttpClientEmptyBodyTest`. |

## Production E2E (post-deploy)

Once both Kinsta backend and Cloudflare Pages SPA are live, the same flow can be exercised manually via the SPA UI:

1. Sign in at `https://app.defyn.dev`
2. Install + activate the connector plugin on a real test WP site
3. In wp-admin -> Settings -> DefynWP Connector -> Generate Connection Code
4. In the SPA, click "Add Site" + paste the code + URL
5. Watch the site card flip from "Pending" to "Active" (~5-15s for AS to fire)
6. Click into the site -> click "Refresh" -> watch runtime info populate
7. Click "Ping" -> watch last contact time advance
8. Open the Activity page -> events appear
9. Back on the site detail, click "Disconnect" -> confirm
10. **Critical**: verify in the connector site's wp-admin -> Settings -> DefynWP Connector that the state shows "Not connected" (NOT "Connected"). If it still says "Connected", F10 Task 1's fix didn't deploy.
