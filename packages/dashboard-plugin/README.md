# DefynWP Dashboard

The central WordPress plugin for the DefynWP multi-site management platform. Pairs with the [DefynWP Connector](../connector-plugin/) plugin installed on each managed site.

## What it does

- Exposes a REST API consumed by the SPA at `app.defyn.dev` (Vite + React, repo at `apps/web/`).
- Manages per-site Ed25519 keypairs encrypted at rest via libsodium secretbox.
- Schedules background handshake jobs via [Action Scheduler](https://actionscheduler.org/).
- Audits every meaningful event to `wp_defyn_activity_log`.

## Requirements

- WordPress 5.5+
- PHP 8.1+
- `ext-sodium`
- MySQL 5.7+ / MariaDB 10.3+

## Required env vars / wp-config constants

| Constant | Loaded from | Purpose |
|---|---|---|
| `DEFYN_JWT_SECRET` | env `DEFYN_JWT_SECRET` → `define()` fallback | JWT signing secret (≥ 32 chars). Required for `/auth/*` endpoints. |
| `DEFYN_VAULT_KEY` | env `DEFYN_VAULT_KEY` → `define()` fallback | Base64-encoded 32-byte sodium secretbox key for encrypting per-site private keys. Required for `/sites` create. Generate via `php -r "require 'vendor/autoload.php'; echo \Defyn\Dashboard\Crypto\Vault::generateKey() . PHP_EOL;"`. |
| `DEFYN_SPA_ORIGIN` | env or `define()`; default `http://localhost:5173` | CORS origin allowed to call the REST API. |

On Bedrock/Kinsta set these in `.env`; on vanilla WP / Local-by-Flywheel `define()` them in `wp-config.php` above the "stop editing" line.

## Install (development)

1. `composer install` in this directory.
2. Symlink into a target WP install's `wp-content/plugins/`:
   ```bash
   ln -s /absolute/path/to/packages/dashboard-plugin <wp>/wp-content/plugins/defyn-dashboard
   ```
3. Activate **DefynWP Dashboard** in the WP admin Plugins screen.
4. Define the env vars above.

## REST API

All endpoints live under `/wp-json/defyn/v1/`. All failure responses use the envelope `{"error": {"code": "...", "message": "..."}}`.

### Auth (public)

| Method | Path | Notes |
|---|---|---|
| POST | `/auth/login` | `{email, password}` → 200 `{access_token}` + `Set-Cookie: defyn_refresh=...`. Rate-limited 5/min/IP. |
| POST | `/auth/refresh` | Reads refresh cookie → 200 `{access_token}` + rotated refresh cookie. |
| POST | `/auth/logout` | Revokes the refresh JTI, clears cookie. 204 on success. |
| GET  | `/auth/me` | Bearer auth → 200 `{id, email, display_name}`. |

### Sites (Bearer auth required via `Authorization: Bearer <access_token>`)

| Method | Path | Notes |
|---|---|---|
| POST | `/sites` | `{url, label, code}` → 202 `{site_id}`. Generates K_dash, encrypts private key, inserts pending site row, schedules handshake AS job. |
| GET  | `/sites` | → 200 `{sites: [...]}` — list authenticated user's sites. |
| GET  | `/sites/{id}` | → 200 site JSON OR 404 `sites.not_found`. User-scoped (404 if not the owner). |
| POST | `/sites/{id}/sync` | (F6) → 202 `{site_id, scheduled: true}`. Schedules a `defyn_sync_site` Action Scheduler job. 404 `sites.not_found` if site is missing or not owned by caller. |
| POST | `/sites/{id}/ping` | (F6) → 202 `{site_id, scheduled: true}`. Schedules a `defyn_health_ping` Action Scheduler job. Same guards as `/sync`. |
| DELETE | `/sites/{id}` | (F8) → 204 No Content. Soft disconnect: signs `POST /disconnect` to the connector (best-effort) then DELETEs the dashboard row. **Failure-tolerant** — the row is deleted even if the connector call fails (transport error, 4xx, 5xx, decrypt failure) so the operator isn't stranded with an unrecoverable state. 404 `sites.not_found` if site is missing or not owned. |

### Error codes

| Code | HTTP | Meaning |
|---|---|---|
| `auth.missing_token` | 401 | Missing/malformed `Authorization` header. |
| `auth.invalid_token` | 401 | Token invalid or expired. |
| `auth.wrong_token_type` | 401 | Refresh token sent where access token required. |
| `auth.invalid_credentials` | 401 | Login failed. |
| `auth.missing_fields` | 400 | Login body missing email or password. |
| `auth.missing_refresh` | 401 | Refresh endpoint called without cookie. |
| `auth.invalid_refresh` | 401 | Refresh token invalid/expired. |
| `auth.refresh_revoked` | 401 | Refresh token previously revoked. |
| `auth.rate_limited` | 429 | Too many login attempts from this IP. |
| `sites.missing_fields` | 400 | POST /sites missing url or code. |
| `sites.invalid_url` | 400 | URL not HTTPS / not well-formed / no host / DNS does not resolve. |
| `sites.invalid_code` | 400 | Connection code is not exactly 12 characters (F6 — server now mirrors the SPA-side schema guard). |
| `sites.duplicate_url` | 409 | URL already managed by this user. |
| `sites.not_found` | 404 | Site does not exist OR not owned by authenticated user. Also returned by `/sites/{id}/sync` and `/sites/{id}/ping` (F6). |
| `sites.vault_not_configured` | 500 | `DEFYN_VAULT_KEY` not defined. |

## F6 — Sync + Health

Per spec § 5, the dashboard pulls site state via the connector's signed `GET /status` and probes liveness via signed `GET /heartbeat`. Both run as Action Scheduler jobs scheduled from REST controllers — no synchronous outbound work from the request thread.

### Services

- **`Defyn\Dashboard\Services\SyncService`** — orchestrates a single signed `/status` pull for a given site id. Loads the site → decrypts `our_private_key` via `Vault` → signed GET against the connector → on success, persists the returned snapshot and calls `markSynced`. Every failure path (vault decrypt failure, transport error, non-2xx, malformed payload) routes through `markError`. Caller is always an AS job, never a controller.
- **`Defyn\Dashboard\Services\HealthService`** — same shape but signed GET against `/heartbeat`. Success bumps `last_contact_at` via `markContactAt`; if the site was previously `offline`, it flips back to `active` via `markRecovered`. Failures route through `markOffline` (distinct from SyncService's `markError` — a missed heartbeat is operationally different from a failed sync).

### Action Scheduler hooks

Registered in `Plugin::boot()`:

| Hook | Handler |
|---|---|
| `defyn_sync_site($siteId)`   | `Defyn\Dashboard\Jobs\SyncSite::handle`   |
| `defyn_health_ping($siteId)` | `Defyn\Dashboard\Jobs\HealthPing::handle` |

### Outbound signing — `SignedHttpClient` upgrade

The F5 placeholder `postJson()` is **preserved** — the handshake step still uses it, because at that point the site's public key isn't yet stored on the dashboard. F6 adds two signed methods alongside it:

- `signedGet($url, $privateKeyBase64, $canonicalPath)`
- `signedPostJson($url, $body, $privateKeyBase64, $canonicalPath)`

Both build the canonical string per spec § 5.2 (`METHOD + "\n" + PATH + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + sha256(BODY)`) and sign via `Defyn\Dashboard\Crypto\Signer::signRequest`. The request body is serialized **once** and signed over the exact bytes sent on the wire — never re-serialized in transit.

The connector mirrors this in its own `Defyn\Connector\Crypto\Signer` + `TransientNonceStore`. This duplication between plugins is **intentional** (spec § 8.2) — the two plugins ship independently, so each owns its own copy of the signing primitives. Do not extract them into a shared package.

### Site status — new value

The status column already accepts an enum-as-string in `VARCHAR(20)`. F6 adds:

- `offline` — added alongside existing `pending` / `active` / `error`. No schema migration required.

`HealthService` is the only writer that transitions a site to `offline`; `SyncService` continues to use `error` for failed pulls.

### Site model — expanded fields

`Defyn\Dashboard\Models\Site` gained the following fields (additive only, appended at the end of the constructor with `null` defaults — F1 callers continue to work unchanged):

| Field | Source |
|---|---|
| `ourPrivateKey`   | `our_private_key` (encrypted blob; decrypted by `Vault` at sync time). |
| `wpVersion`       | `wp_version` (populated by SyncService from connector `/status`). |
| `phpVersion`      | `php_version` |
| `activeTheme`     | `active_theme_json` — decoded JSON array `{name, version, parent}`. |
| `pluginCounts`    | `plugin_counts_json` — decoded JSON `{installed, active}`. |
| `themeCounts`     | `theme_counts_json` — decoded JSON `{installed, active}`. |
| `sslStatus`       | `ssl_status` |
| `sslExpiresAt`    | `ssl_expires_at` |
| `lastSyncAt`      | `last_sync_at` — set by `SitesRepository::markSynced`. |

## F7 — Background scheduling

Per spec § 6.3, the dashboard runs three recurring Action Scheduler jobs that fan out leaf work across every schedulable site. The single-site F6 leaf jobs (`defyn_sync_site`, `defyn_health_ping`) are unchanged — F7 only adds the master fan-out and cleanup layer on top.

### Recurring hooks

Cadences are defined as a `private const SCHEDULES` map in `Defyn\Dashboard\Jobs\Scheduler` (single source of truth):

| Hook | Cadence | Handler | accepted_args |
|---|---|---|---|
| `defyn_sync_all_sites`        | every 1800s (30 minutes) | `Defyn\Dashboard\Jobs\SyncAllSites::handle`        | 0 |
| `defyn_health_ping_all`       | every 300s (5 minutes)   | `Defyn\Dashboard\Jobs\HealthPingAll::handle`       | 0 |
| `defyn_cleanup_expired_codes` | every 3600s (1 hour)     | `Defyn\Dashboard\Jobs\CleanupExpiredCodes::handle` | 0 |

### Fan-out pattern

Each `*_all` master is a thin enqueuer — it runs `SitesRepository::findAllSchedulable()` and schedules **one leaf job per site** via `as_enqueue_async_action`. The master itself does no outbound HTTP. This keeps each individual PHP run well under Kinsta's 300s budget regardless of how many sites are managed, since the heavy work is spread across many short leaf jobs picked up by subsequent Action Scheduler ticks.

- `defyn_sync_all_sites` → fan-outs to `defyn_sync_site($siteId)` leafs (F6 handler).
- `defyn_health_ping_all` → fan-outs to `defyn_health_ping($siteId)` leafs (F6 handler).
- `defyn_cleanup_expired_codes` → purges stale `wp_defyn_connection_codes` rows directly (no per-site fan-out).

### Schedulable site filter

`SitesRepository::findAllSchedulable(int $limit = 500): list<int>` is the source-of-truth for "which sites the fan-out masters should touch". It returns site ids where `status IN ('active', 'offline', 'error')` with a 500-row LIMIT.

- `pending` sites are deliberately excluded — they haven't completed the handshake yet, so the dashboard has no private key with which to sign outbound requests.
- The 500-row LIMIT is a soft ceiling for F7. Once managed-site counts approach this, pagination across multiple fan-out ticks becomes F10 (deploy-hardening) work.

### Connection-code cleanup

`Defyn\Dashboard\Repositories\ConnectionCodesRepository::deleteExpiredAndConsumed(): int` is a new repo class — the only F1-defined reader/writer for the `wp_defyn_connection_codes` table. It deletes rows that are either `expires_at < NOW()` or `consumed_at IS NOT NULL`, and returns the affected row count for the AS job log.

### Lifecycle

- **Activation** — `Defyn\Dashboard\Activation::activate()` runs schema setup, then calls `Scheduler::installRecurringSchedules()`. Install is **idempotent**: it unschedules all three hooks first, so reactivating the plugin never creates duplicate recurring rows.
- **Deactivation** — `register_deactivation_hook` runs `Scheduler::uninstallRecurringSchedules()`, which calls `as_unschedule_all_actions` for each hook in the map. No orphaned recurring schedules remain after deactivation.

### Immediate first sync after handshake

F7 modifies `Defyn\Dashboard\Services\Connection::complete()` so that a successful handshake also schedules a one-shot `defyn_sync_site($siteId)` immediately. A newly connected site shows runtime info (`wp_version`, `php_version`, theme/plugin counts, SSL status) within seconds, rather than waiting up to 30 minutes for the next `defyn_sync_all_sites` tick.

## F8 — Sites UI polish + Disconnect

F8 adds the `DELETE /sites/{id}` endpoint above and expands `Defyn\Dashboard\Models\Site::toJson()` so the SPA sees the F6/F7 runtime fields it had been missing.

### Site JSON shape (expanded in F8)

`Site::toJson()` now exposes these fields to the SPA on top of the F5 originals:

| Field | Source | Notes |
|---|---|---|
| `last_sync_at` | `lastSyncAt` | set by `SitesRepository::markSynced` (F6). |
| `wp_version` | `wpVersion` | (F6) site's WordPress version captured in last sync. |
| `php_version` | `phpVersion` | (F6) site's PHP version. |
| `active_theme` | `activeTheme` | (F6) JSON-decoded `{name, version, parent}` or null. |
| `plugin_counts` | `pluginCounts` | (F6) JSON-decoded `{installed, active}` or null. |
| `theme_counts` | `themeCounts` | (F6) JSON-decoded `{installed, active}` or null. |
| `ssl_status` | `sslStatus` | (F6) `'enabled'` / `'disabled'` / `'unknown'`. |
| `ssl_expires_at` | `sslExpiresAt` | (F6) DATETIME string (UTC) or null — not a unix int. |

**Sensitive fields still hidden** (F5 contract preserved): `user_id`, `our_public_key`, `our_private_key`, `site_public_key`.

### Status enum

The `status` field can now be `pending | active | error | offline`. `offline` was added backend-side in F6 (and now exposed via the SPA schema in F8) — distinct from `error` (config-broken) since `offline` means connectivity-failed but possibly recoverable.

### Soft disconnect — `DisconnectService`

`Defyn\Dashboard\Services\DisconnectService::disconnect(int $siteId, int $userId): bool` orchestrates the soft tear-down:

1. `findByIdForUser($siteId, $userId)` → null returns false (404 envelope at controller layer; non-owner can't trigger the endpoint).
2. `Vault::decrypt($site->ourPrivateKey)` → on failure, swallow and proceed.
3. `SignedHttpClient::signedPostJson(...connector .../disconnect, [], $privateKey, '/defyn-connector/v1/disconnect')` → on transport error or non-2xx, swallow and proceed.
4. `SitesRepository::deleteForUser($siteId, $userId)` → returns true iff the row was deleted.

The connector tear-down is **best-effort by design**. An offline / deactivated / key-corrupted connector must not strand the operator with a row they can't remove. The connector's stale `dashboard_public_key` (if any) will be overwritten by the next handshake the operator runs against a fresh code.

## Run tests

```bash
cp tests/wp-tests-config.php.example wp-tests-config.php  # adjust DB host/port/password if needed
composer install
composer test
```

(Requires a MySQL test database.)
