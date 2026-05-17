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
| `sites.duplicate_url` | 409 | URL already managed by this user. |
| `sites.not_found` | 404 | Site does not exist OR not owned by authenticated user. |
| `sites.vault_not_configured` | 500 | `DEFYN_VAULT_KEY` not defined. |

## Run tests

```bash
cp tests/wp-tests-config.php.example wp-tests-config.php  # adjust DB host/port/password if needed
composer install
composer test
```

(Requires a MySQL test database.)
