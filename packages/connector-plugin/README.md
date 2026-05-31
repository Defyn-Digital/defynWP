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
| GET  | `/wp-json/defyn-connector/v1/status` | Signed (Ed25519, F6) | Returns environment + theme/plugin/SSL snapshot of the site. |
| GET  | `/wp-json/defyn-connector/v1/heartbeat` | Signed (Ed25519, F6) | Lightweight liveness probe. |
| POST | `/wp-json/defyn-connector/v1/disconnect` | Signed (Ed25519, F6) | Dashboard-initiated tear-down. Wipes dashboard trust; keeps site keypair. |

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
| `connector.signature_missing` | 401 | Required signing headers missing or malformed (F6). |
| `connector.signature_expired` | 401 | Timestamp outside ±300s window (F6). |
| `connector.signature_replay` | 401 | Nonce already used within TTL (F6). |
| `connector.signature_invalid` | 401 | Signature does not verify against the stored dashboard public key (F6). |
| `connector.not_connected` | 404 | Connector is not in `connected` state — handshake not completed (F6). |
| `connector.signing_failed` | 500 | Site private key corrupted; reset connector and re-handshake (F5 carry-forward, surfaced in F6). |

## F6 — Signed endpoints

Per spec § 5.1 + § 5.2, the connector exposes three signed endpoints once the handshake is complete. The dashboard uses these for sync, health, and tear-down.

### GET /wp-json/defyn-connector/v1/status

Returns a snapshot of the site (collected by `Defyn\Connector\SiteInfo\Collector`):

```json
{
  "wp_version": "6.5.2",
  "php_version": "8.2.10",
  "active_theme": { "name": "Twenty Twenty-Four", "version": "1.2", "parent": null },
  "plugin_counts": { "installed": 12, "active": 8 },
  "theme_counts":  { "installed": 3,  "active": 1 },
  "ssl_status":     "valid",
  "ssl_expires_at": "2026-09-12T00:00:00+00:00",
  "server_time":    1717250400
}
```

### GET /wp-json/defyn-connector/v1/heartbeat

Lightweight liveness probe:

```json
{ "ok": true, "server_time": 1717250400 }
```

### POST /wp-json/defyn-connector/v1/disconnect

Dashboard-initiated tear-down. **Wipes** `dashboard_public_key`, `connected_at`, and any handshake-code state. **Preserves** the site's own `site_public_key` / `site_private_key` so an operator can re-handshake immediately from the SettingsPage — this mirrors the F4 reset-handler precedent (don't regenerate the site keypair just because the dashboard relationship was reset). Returns **204 No Content** on success.

## Signing protocol (spec § 5.2)

Every signed request MUST include three headers:

| Header | Value |
|---|---|
| `X-Defyn-Timestamp` | Unix seconds, string-encoded. |
| `X-Defyn-Nonce`     | Random hex string, replay-resistance per request. |
| `X-Defyn-Signature` | Base64 Ed25519 signature of the canonical string. |

**Canonical string** (signed bytes):

```
METHOD + "\n" + PATH + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + sha256(BODY)
```

- `METHOD` is uppercased (`GET`, `POST`).
- `PATH` is the route, e.g. `/defyn-connector/v1/status` (no host, no query).
- `sha256(BODY)` is the lowercase hex digest of the **exact raw bytes** sent on the wire (empty string for GET).

**Verification order** (cheap rejects first — implemented by `Defyn\Connector\Crypto\Signer::verifyRequest` and wired into REST via `Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::check` as the `permission_callback`):

1. All three headers present and well-formed.
2. Timestamp within ±300 seconds of server time.
3. Stored `dashboard_public_key` decodes; signature decodes; lengths sane.
4. Ed25519 signature validates against the canonical string.
5. Nonce has not been seen within TTL.

The nonce store is `Defyn\Connector\Crypto\TransientNonceStore` (WP transients backed, 10-minute TTL). It mirrors the dashboard-side `Defyn\Dashboard\Crypto\NonceStore` interface from F2 — this is **intentional duplication**, per spec § 8.2: the two plugins are deployed independently, so each owns its own copy of the signing primitives rather than sharing a package. Future readers: do not "DRY this up" into a shared library — that would re-couple the plugins.

## F5 carry-forwards landed in F6

- **`SettingsPage` connected branch.** Previously, when state was `connected`, the admin page fell through to the dangerous "Generate Connection Code" form. F6 adds a read-only `connected` render branch showing the dashboard fingerprint + `connected_at` + a Disconnect button (with JS confirm) that hits the new signed `/disconnect`.
- **`ConnectController` signing-failure envelope.** `Signer::sign` is now wrapped in try/catch; a corrupt site private key returns the spec'd `{error: {code: "connector.signing_failed", ...}}` envelope instead of a generic WP 500.

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

## F10 — Hardening note (signed POST body bytes)

F10 closed a body-bytes mismatch that silently broke every signed POST from the dashboard. Connector-side detail: `VerifySignatureMiddleware::check` reads `WP_REST_Request::get_body()` which returns `""` for an empty-body POST (no entity body on the wire). Dashboard's `SignedHttpClient::signedPostJson` now signs over that same `""` for empty inputs and sends no body. **Don't change `VerifySignatureMiddleware` to decode/re-encode the body** — the contract is "verify against exactly the raw bytes that arrived" and the dashboard agrees on the same bytes. The byte-agreement contract is documented in both `SignedHttpClient::signedPostJson` and `VerifySignatureMiddleware::check`.

## Uninstall

`uninstall.php` removes `wp_options['defyn_connector']` on full plugin uninstall, including the keypair.
