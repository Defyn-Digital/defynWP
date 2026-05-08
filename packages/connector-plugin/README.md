# DefynWP Connector

A WordPress plugin that turns a managed site into a DefynWP-managed agent. Pairs with the central [DefynWP Dashboard](../dashboard-plugin/) plugin.

## What it does

- Generates an Ed25519 keypair on activation, storing it in `wp_options['defyn_connector']`.
- Adds a **Settings → DefynWP Connector** page in `wp-admin`.
- Lets a WP admin generate a **12-character connection code** (15-minute expiry) to pair the site with the DefynWP Dashboard.
- Exposes `POST /wp-json/defyn-connector/v1/connect` — the endpoint the dashboard calls to validate the connection code during the handshake.

> **F4 scope:** code-validation only. Ed25519 challenge/response signing of the dashboard's `callback_challenge` is added in F5.

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
| POST | `/wp-json/defyn-connector/v1/connect` | Public; gated by code validation | Validates a posted code; marks it consumed. F5 will extend with crypto challenge-response. |

### Error envelope

All non-200 responses use the same envelope:

```json
{ "error": { "code": "connector.invalid_code", "message": "..." } }
```

| Code | HTTP | Meaning |
|---|---|---|
| `connector.missing_code` | 400 | Body is missing the `code` field. |
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
  "state": "unconfigured | awaiting-handshake | code-consumed | connected (F5+)",
  "site_public_key":  "<base64 Ed25519>",
  "site_private_key": "<base64 Ed25519>",
  "generated_at":     "<ISO 8601>",
  "connection_code":  "<12-char>",
  "site_nonce":       "<base64 32 bytes>",
  "code_created_at":  <unix ts>,
  "code_expires_at":  <unix ts>,
  "code_consumed_at": <unix ts>
}
```

## Uninstall

`uninstall.php` removes `wp_options['defyn_connector']` on full plugin uninstall, including the keypair.
