# DefynWP Connector

A WordPress plugin that turns a managed site into a DefynWP-managed agent. Pairs with the central [DefynWP Dashboard](../dashboard-plugin/) plugin.

## What it does

- Generates an Ed25519 keypair on activation, storing it in `wp_options['defyn_connector']`.
- Adds a **Settings → DefynWP Connector** page in `wp-admin`.
- Lets a WP admin generate a **12-character connection code** (15-minute expiry) to pair the site with the DefynWP Dashboard.
- Exposes `POST /wp-json/defyn-connector/v1/connect` — the endpoint the dashboard calls to validate the connection code during the handshake.

> **F5 scope:** Code validation + Ed25519 challenge/response handshake. Dashboard provides `dashboard_public_key` and `callback_challenge`; connector returns `site_public_key`, `challenge_signature`, `site_url`, and `site_name`.

## Requirements

- WordPress 5.5+
- PHP 8.1+
- `ext-sodium` (for Ed25519). Standard on modern PHP.

## Install (development)

1. From `packages/connector-plugin/`, run `composer install`.
2. Symlink (or copy) the directory into a target WP install's `wp-content/plugins/`:
   ```bash
   ln -s /absolute/path/to/packages/connector-plugin <wp>/wp-content/plugins/defyn-connector
   ```
3. Activate **DefynWP Connector** from the WP admin Plugins screen.

## Generate a connection code

1. Go to **Settings → DefynWP Connector**.
2. Click **Generate Connection Code**. The page will display a 12-character code that expires in 15 minutes.
3. (F5+) Paste the code into the DefynWP Dashboard SPA's "Add Site" form.

## REST API

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/wp-json/defyn-connector/v1/connect` | Public; gated by code validation | Performs the handshake: validates code, signs dashboard's challenge with site private key, returns site public key + signature + site metadata. |

### POST /wp-json/defyn-connector/v1/connect

**Request body:**

```json
{
  "code": "<12-char connection code>",
  "dashboard_public_key": "<base64 Ed25519 public key>",
  "callback_challenge": "<base64 challenge string, max 256 bytes>"
}
```

**Success response (200):**

```json
{
  "site_public_key": "<base64 Ed25519 public key>",
  "challenge_signature": "<base64 Ed25519 signature>",
  "site_url": "https://example.com",
  "site_name": "Example Site"
}
```

State transitions to `connected`; dashboard stores the `challenge_signature` and proceeds to verify it.

### Error envelope

All non-200 responses use the same envelope:

```json
{ "error": { "code": "connector.invalid_code", "message": "..." } }
```

| Code | HTTP | Meaning |
|---|---|---|
| `connector.missing_code` | 400 | Body is missing the `code` field. |
| `connector.missing_dashboard_key` | 400 | Body is missing the `dashboard_public_key` field. |
| `connector.missing_challenge` | 400 | Body is missing the `callback_challenge` field or it exceeds 256 bytes. |
| `connector.invalid_dashboard_key` | 400 | `dashboard_public_key` is not a valid base64-encoded 32-byte Ed25519 key. |
| `connector.no_pending_code` | 404 | No code has been generated on this site yet. |
| `connector.invalid_code` | 401 | Posted code does not match what the connector stored. |
| `connector.code_expired` | 410 | Code's 15-minute window has passed. |
| `connector.code_consumed` | 409 | Code was already consumed by a previous call. |

## Run tests

```bash
cp tests/wp-tests-config.php.example wp-tests-config.php
# adjust DB section if needed
composer install
composer test
```

## State shape

The plugin stores a single JSON value under `wp_options['defyn_connector']`:

```json
{
  "state": "unconfigured | awaiting-handshake | code-consumed | connected",
  "site_public_key":       "<base64 Ed25519>",
  "site_private_key":      "<base64 Ed25519>",
  "generated_at":          "<ISO 8601>",
  "connection_code":       "<12-char>",
  "site_nonce":            "<base64 32 bytes>",
  "code_created_at":       <unix ts>,
  "code_expires_at":       <unix ts>,
  "code_consumed_at":      <unix ts>,
  "dashboard_public_key":  "<base64 Ed25519> (set after successful handshake, F5+)",
  "connected_at":          "<ISO 8601> (set after successful handshake, F5+)"
}
```

The state machine flow:
1. `unconfigured` — initial state, no keypair generated yet.
2. `awaiting-handshake` — keypair generated, connection code issued, ready for dashboard to call `/connect`.
3. `code-consumed` — intermediate state during handshake (code validated but challenge-response not yet complete).
4. `connected` — handshake complete, dashboard has validated signature, bi-directional trust established.

## Uninstall

`uninstall.php` removes `wp_options['defyn_connector']` on full plugin uninstall, including the keypair.
