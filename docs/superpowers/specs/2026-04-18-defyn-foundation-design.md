# DefynWP — Foundation Design Spec

**Date:** 2026-04-18
**Status:** Design complete, awaiting user review before writing-plans
**Scope:** Foundation phase only (Phase 1 of the multi-phase product)

---

## 1. Overview

Build a ManageWP-style multi-site WordPress management platform called **DefynWP**. The foundation phase establishes the core architecture: a dashboard that can connect to managed WordPress sites, pull basic information from them, and display it in a custom UI. All higher-value features (updates, backups, monitoring, reports) build on this foundation in later phases.

### Vision

- **Starts as:** single-tenant internal tool for managing a handful of WordPress sites
- **Grows into:** agency whitelabel product — clients get scoped, read-only access to their own sites
- **Eventually:** multi-tenant SaaS is possible, but out of scope for the foreseeable future

### Why this scope matters

ManageWP is many independent subsystems (updates, backups, security, uptime, reports). Trying to spec all of them at once produces an unusable document. The foundation proves the architecture works end-to-end; every subsequent phase plugs into the same keypair-signing, Action-Scheduler-backed foundation with minimal new infrastructure.

---

## 2. Architecture

Three runtimes with clear responsibilities.

### 2.1 Runtimes

| Runtime | Host | Role |
|---|---|---|
| **React SPA** (`app.defyn.dev`) | Cloudflare Pages (free) | Presentation only. Holds no secrets. |
| **Bedrock WordPress + custom plugin** (`defyn.com`) | Kinsta (user's existing plan) | Backend brain. Owns data, keys, scheduling, all outbound calls. |
| **Connector plugin** (on each managed site) | Client's existing WP host | Stateless agent. Verifies signed requests, reports site facts. |

### 2.2 Request flows

1. **User → SPA:** opens `app.defyn.dev`, React app loads, logs in
2. **SPA → WP REST:** bearer JWT for logged-in user calls (list sites, add site, etc.)
3. **WP → Managed site:** signed HTTPS (Ed25519) pulls site info + health
4. **Action Scheduler:** WP cron triggers background sync/health jobs

### 2.3 Key principle

Each runtime has one job. The SPA never talks to managed sites directly — only the WP backend does. Secrets stay server-side.

---

## 3. Tech stack

| Concern | Choice | Rationale |
|---|---|---|
| Backend | Bedrock WordPress + custom plugin | Same language as WP world; dev team friendly; Action Scheduler is mature |
| Host (backend) | Kinsta | User's existing plan; real server cron; SSH + Composer; Bedrock-compatible |
| Queue | Action Scheduler (WooCommerce's queue lib) | Battle-tested in WordPress; no external infra needed |
| Crypto | Ed25519 via PHP libsodium | Fast, short keys, zero runtime deps (PHP 7.2+) |
| Frontend | Vite + React + TypeScript | Standard SPA stack; TS prevents API-shape drift |
| Frontend host | Cloudflare Pages | Free, global CDN, custom domains + SSL included |
| UI kit | shadcn/ui + Tailwind | Own the component code — whitelabel-friendly |
| Server state | TanStack Query | Cache + refetch semantics made for dashboards |
| Forms | React Hook Form + Zod | Same schemas validate API responses |
| Routing | React Router v6 | De facto standard |
| Testing | PHPUnit + wp-phpunit (backend), Vitest + Testing Library + MSW (frontend) | Native toolchains for each side |

### Rejected alternatives (and why)

- **Laravel instead of WP:** user is WP-native; going Laravel means maintaining two language stacks and rebuilding user management
- **Next.js instead of Vite SPA:** adds a Node runtime we don't need; static SPA + WP REST is simpler
- **WordPress.com hosting:** incompatible with Bedrock layout, no server cron control, PHP execution limits; kept for testing only
- **API keys instead of keypair signing:** simpler to build but weaker security; no compromise containment, no replay protection
- **Dashboard-first connect flow:** worse UX (two copy-pastes); plugin-first is 2-day build
- **OAuth one-click connect:** redirect flow across domains adds ~1 week; deferred to later phase
- **CPT + post_meta for sites:** structured queries beat post_meta joins; table is cleaner

---

## 4. Data model

### 4.1 Tables on the dashboard backend (Kinsta MySQL)

**`wp_defyn_sites`** — one row per managed WordPress site

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | bigint FK → `wp_users` | Owner — sets us up for whitelabel |
| `url` | varchar(255) | Normalized, unique per user |
| `label` | varchar(120) | User-visible name |
| `status` | enum | `pending`, `active`, `offline`, `error` |
| `site_public_key` | text | Connector's public key (received during handshake) |
| `our_public_key` | text | Per-site dashboard public key |
| `our_private_key` | text (encrypted) | AES-256 at rest, key in Bedrock `.env` |
| `wp_version` | varchar(20) | Cached from last sync |
| `php_version` | varchar(20) | Cached from last sync |
| `active_theme` | json | `{name, version, parent}` |
| `plugin_counts` | json | `{installed, active}` |
| `theme_counts` | json | `{installed, active}` |
| `ssl_status` | enum | `valid`, `expired`, `none`, `unknown` |
| `ssl_expires_at` | datetime nullable | |
| `last_contact_at` | datetime nullable | Last successful call (any endpoint) |
| `last_sync_at` | datetime nullable | Last full info refresh |
| `last_error` | text nullable | Human-readable, null if healthy |
| `created_at`, `updated_at` | datetime | |

**`wp_defyn_connection_codes`** — short-lived handshake tokens (15-min expiry)

| Column | Type | Notes |
|---|---|---|
| `code` | varchar(32) PK | Random token shown to user |
| `site_url` | varchar(255) | URL plugin reported at generation |
| `site_nonce` | varchar(64) | One-time value; plugin stores it locally |
| `expires_at` | datetime | ~15 min from creation |
| `consumed_at` | datetime nullable | Null until used, then frozen |
| `created_at` | datetime | |

**`wp_defyn_activity_log`** — audit trail

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | bigint FK nullable | |
| `site_id` | bigint FK nullable | |
| `event_type` | varchar(64) | `site.connected`, `site.synced`, `auth.login`, etc. |
| `details` | json | Event-specific payload |
| `ip_address` | varchar(45) | |
| `created_at` | datetime | |

**Reused WP-native:** `wp_users`, `wp_usermeta`, `wp_options`. Action Scheduler installs its own tables; we don't modify those.

### 4.2 Connector-side state (on each managed site)

Single `wp_options` row keyed `defyn_connector`, value is JSON:

```json
{
  "state": "connected",
  "connection_code": "...",
  "site_nonce": "...",
  "site_public_key": "...",
  "site_private_key": "...",
  "dashboard_public_key": "...",
  "connected_at": "2026-04-18T..."
}
```

### 4.3 Key choices

- Custom tables over CPT/post_meta — structured queries (status, timestamps) need real columns + indexes
- Per-site keypair on dashboard side — one leak only compromises that one site
- Private keys encrypted at rest with AES-256; key in Bedrock `.env`, never in DB
- `user_id` on sites table costs nothing now, prevents a migration when whitelabel lands

---

## 5. Connector plugin (on each managed site)

### 5.1 REST endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/wp-json/defyn-connector/v1/connect` | Unauthenticated, rate-limited | Receive connection code, exchange public keys. Single-use. |
| GET | `/wp-json/defyn-connector/v1/status` | Signed (Ed25519) | Return WP version, PHP version, active theme, plugin + theme counts, SSL status, server time |
| GET | `/wp-json/defyn-connector/v1/heartbeat` | Signed | Lightweight "alive" ping. Returns OK + server time. |
| POST | `/wp-json/defyn-connector/v1/disconnect` | Signed | Wipe stored keys, reset state |

### 5.2 Request signing protocol

**Algorithm:** Ed25519 via PHP's built-in libsodium (no runtime deps, PHP 7.2+)

**Headers on every signed call:**
```
X-Defyn-Timestamp: 1776494192
X-Defyn-Nonce: 4f2a1e8b9c7d...
X-Defyn-Signature: base64(ed25519_sign(priv_key, canonical))
```

**Canonical string:**
```
METHOD + "\n" + PATH + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + sha256(BODY)
```

**Verification:**
- Timestamp within ±300 seconds (replay window)
- Nonce not seen before (cached in WP transient, 10-min TTL)
- Signature valid against stored `dashboard_public_key`

### 5.3 Admin UI

One wp-admin screen under Settings. States:
- **Not connected:** "Generate Connection Code" button → shows 12-char code + expiry + instructions
- **Connected:** status + timestamps + "Disconnect" button

### 5.4 File structure

```
defyn-connector/
├── defyn-connector.php         # WP plugin headers + bootstrap
├── composer.json               # dev deps only; no runtime deps
├── src/
│   ├── Plugin.php
│   ├── Crypto/{KeyPair,Signer}.php
│   ├── Rest/{Connect,Status,Heartbeat,Disconnect}Controller.php
│   ├── Rest/VerifySignatureMiddleware.php
│   ├── Storage/ConnectorState.php
│   ├── SiteInfo/Collector.php
│   └── Admin/SettingsPage.php
├── assets/admin.css
├── languages/
└── readme.txt
```

### 5.5 Requirements

- WordPress 5.5+ (modern REST API features)
- PHP 7.2+ (built-in libsodium)
- HTTPS required (plugin refuses to connect over plain HTTP)

---

## 6. Dashboard WordPress plugin (on Kinsta)

### 6.1 REST API

**Auth (public):**
```
POST /wp-json/defyn/v1/auth/login        # email+password → JWT access + refresh
POST /wp-json/defyn/v1/auth/refresh      # refresh → new access
POST /wp-json/defyn/v1/auth/logout       # revoke refresh
GET  /wp-json/defyn/v1/auth/me           # current user
```

**Sites (signed-in):**
```
GET    /wp-json/defyn/v1/sites                # list (paginated, filter by status)
POST   /wp-json/defyn/v1/sites                # create {url, label, code} → kicks off handshake
GET    /wp-json/defyn/v1/sites/{id}           # details + cached info
PATCH  /wp-json/defyn/v1/sites/{id}           # rename, change label
DELETE /wp-json/defyn/v1/sites/{id}           # remove + tell connector to disconnect
POST   /wp-json/defyn/v1/sites/{id}/sync      # "refresh now" → schedules immediate sync
POST   /wp-json/defyn/v1/sites/{id}/ping      # "check health now"
```

**Activity (signed-in):**
```
GET /wp-json/defyn/v1/activity                # paginated audit log (filter by site / type)
```

### 6.2 Authentication model

JWT with short-lived access + long-lived refresh:

- **Access token:** 15-minute TTL, issued on login, sent in `Authorization: Bearer` header
- **Refresh token:** 30-day TTL, stored in httpOnly Secure cookie scoped to `.defyn.dev` (so both `app.defyn.dev` and `api.defyn.dev` can read)
- **Rotation:** every refresh issues a new pair; old refresh token is invalidated (stops replay if stolen)
- **Underlying store:** WP's own `wp_users` + `wp_check_password` — no custom user store
- **CORS:** backend only allows origin `https://app.defyn.dev` with credentials
- **Rate limit:** `/auth/login` capped at 5/min per IP (transient-backed limiter)

### 6.3 Background jobs (Action Scheduler)

| Job | Interval | Purpose |
|---|---|---|
| `defyn_sync_all_sites` | every 30 min | Fan-out: schedules `defyn_sync_site` per active site |
| `defyn_sync_site(site_id)` | on-demand | Calls `GET /status`, updates cached info + `last_sync_at` |
| `defyn_health_ping_all` | every 5 min | Fan-out: schedules `defyn_health_ping` per active site |
| `defyn_health_ping(site_id)` | on-demand | Calls `GET /heartbeat`, updates `last_contact_at` + status |
| `defyn_complete_connection(site_id, code, url)` | once, on Add Site | Calls `POST /connect`, exchanges keys, flips site to `active` |
| `defyn_cleanup_expired_codes` | hourly | Deletes expired/consumed connection codes |

Fan-out pattern keeps each job safely under Kinsta's 300s PHP limit.

### 6.4 File structure

```
web/app/plugins/defyn-dashboard/
├── defyn-dashboard.php
├── composer.json                    # firebase/php-jwt, symfony/http-client
├── src/
│   ├── Plugin.php
│   ├── Activation.php               # creates custom tables on activate
│   ├── Crypto/{KeyPair,Signer,Vault}.php
│   ├── Http/SignedHttpClient.php
│   ├── Rest/{Auth,Sites,Activity}Controller.php
│   ├── Rest/Middleware/{JwtAuth,Cors,RateLimit}.php
│   ├── Models/{Site,ConnectionCode,ActivityLog}.php
│   ├── Services/{Connection,Sync,Health,Auth,ActivityLogger}.php
│   ├── Jobs/{SyncAllSites,SyncSite,HealthPingAll,HealthPingSite,CompleteConnection,CleanupExpiredCodes}.php
│   └── Admin/SettingsPage.php       # operator-only: queue health, vault status
└── tests/
```

### 6.5 Key design choices

- All outbound cross-site calls go through one `SignedHttpClient` — signing lives in exactly one place
- Symfony `http-client` over `wp_remote_get` (async-capable, better timeouts, testable)
- Refresh token rotation follows OAuth2 best practice
- Operator wp-admin page exists for config + diagnostics; end users never see it (they use the SPA)

---

## 7. React SPA (`app.defyn.dev`)

### 7.1 Routes

**Public:** `/login`, `/forgot-password`, `/reset-password?token=…`

**Authenticated:** `/` (redirects to `/sites`), `/sites`, `/sites/add`, `/sites/:id`, `/activity`, `/settings`, `/logout`

### 7.2 Auth flow

1. User hits `app.defyn.dev` → SPA loads, checks for access token in memory + refresh cookie
2. No token? → redirect to `/login`
3. Submits form → `POST /auth/login` → access token in memory, refresh token in httpOnly cookie
4. TanStack Query caches user from `/auth/me`; all subsequent requests include `Authorization: Bearer {access}`
5. On 401, `apiClient` auto-calls `/auth/refresh` (uses cookie), retries the original request
6. Refresh fails → clear state, redirect to `/login`
7. Logout → `POST /auth/logout` (revokes refresh) + clears client state

### 7.3 Project structure

```
apps/web/
├── package.json
├── vite.config.ts
├── tailwind.config.ts
├── tsconfig.json
├── index.html
└── src/
    ├── main.tsx
    ├── App.tsx
    ├── routes/{Login,ForgotPassword,ResetPassword,SitesList,SiteAdd,SiteDetail,Activity,Settings}.tsx
    ├── components/
    │   ├── ui/                   # shadcn/ui components (owned code)
    │   ├── layout/{Sidebar,AppShell}.tsx
    │   └── sites/{SiteCard,SiteStatusBadge}.tsx
    ├── lib/
    │   ├── apiClient.ts          # fetch wrapper + auto-refresh
    │   ├── auth.ts
    │   └── queries/{useSites,useSite,useActivity}.ts
    ├── types/api.ts              # Zod schemas = TS types
    └── styles/globals.css
```

### 7.4 Key design choices

- TypeScript + Zod end-to-end: Zod schemas double as TS types; API-shape drift caught immediately
- shadcn/ui (not MUI/Ant): you own the component code, whitelabel restyling is trivial
- TanStack Query handles refetch + cache invalidation; "Refresh now" is just `queryClient.invalidateQueries()`
- No secrets in the SPA — private keys and DB creds are backend-only

---

## 8. Connection handshake (end-to-end, plugin-first)

10-step flow:

1. **User installs + activates the connector plugin** on a managed site → plugin generates Ed25519 keypair (K_site) and stores it in `wp_options`
2. **User clicks "Generate Connection Code"** in plugin admin → plugin generates `code` (12-char) + `nonce` (32 bytes random), stores both locally, displays only the code
3. **User opens SPA → Add Site → pastes URL + code + optional label** → `POST /wp-json/defyn/v1/sites`
4. **Backend validates + creates pending record:**
   - Validates URL (HTTPS, DNS-resolvable, not a duplicate)
   - Generates per-site dashboard keypair K_dash; encrypts private key with Vault
   - `INSERT wp_defyn_sites` with `status='pending'`
   - Schedules `defyn_complete_connection(site_id, code, url)` to run immediately
   - Returns `202 Accepted {site_id}`
5. **SPA polls `GET /sites/{id}` every 2s** for up to 30s
6. **Action Scheduler job calls connector:**
   ```
   POST https://clientA.com/wp-json/defyn-connector/v1/connect
   { "code", "dashboard_public_key", "callback_challenge" }
   ```
7. **Connector validates + responds:**
   - Look up code — must exist, not expired, not consumed
   - Mark code consumed (one-shot)
   - Store `dashboard_public_key`
   - Sign `callback_challenge + nonce` with K_site private key
   - Return `{site_public_key, challenge_signature, site_url, site_name}`
8. **Dashboard verifies challenge_signature** with returned `site_public_key`
   - Valid → we're talking to the same plugin that generated the code (no MITM)
   - Invalid → reject, mark `error`, log failure
9. **Dashboard finalizes the site record:**
   - UPDATE `wp_defyn_sites`: `site_public_key`, `status='active'`, `last_contact_at=now()`
   - INSERT `wp_defyn_activity_log`: `event_type='site.connected'`
   - Schedule `defyn_sync_site(site_id)` for first full info pull
10. **SPA sees `status='active'`** → navigates to site detail; cached info populates from sync

### 8.1 Error paths

| Scenario | Behavior |
|---|---|
| Code expired (>15 min) | Connector returns 410 Gone → dashboard marks site `error` → SPA shows "regenerate" |
| Code already consumed | Connector returns 409 Conflict → same UX |
| URL not reachable / DNS fail | Dashboard marks `error` with `last_error`; one retry after 60s then stop |
| Plugin not installed | 404 on `/connect` → "No DefynWP Connector found" |
| HTTP (not HTTPS) | Refused at step 4 before call |
| Challenge signature invalid | Reject, log `site.connection_rejected` (potential MITM) |
| Duplicate URL for user | 409 at step 4 |
| User cancels mid-flow | Pending row + job continue; "Delete" available to clean up |
| Job timeout / 300s limit | Action Scheduler retries 3× exponential backoff; final fail → `error` + `last_error` |

### 8.2 Security properties

- **Mutual authentication:** both sides have each other's public keys, can verify every future request
- **No shared secret over the wire after handshake:** signatures, not passwords
- **Compromise containment:** per-site dashboard keypair means one leak affects one site
- **Self-service disconnect:** both sides can sever; the other side detects on next poll

---

## 9. Error handling philosophy

### 9.1 API error response shape (consistent across system)

```json
{
  "error": {
    "code": "site.code_expired",
    "message": "Connection code expired. Regenerate a new one on the site.",
    "details": { "expired_at": "2026-04-18T14:32:00Z" }
  }
}
```

- Dotted `code` (machine-parseable)
- User-friendly `message` (SPA shows verbatim)
- Optional `details` (structured context, omitted if not useful)
- HTTP status matches class: 400 validation, 401 auth, 404 missing, 409 conflict, 410 gone, 429 rate-limited, 500 server

### 9.2 Strategy by layer

| Layer | Strategy |
|---|---|
| Backend — validation | Return 4xx with structured error. Never log (expected). |
| Backend — unexpected | Return 500 with generic message. Log full exception to WP `debug.log`. Write `system.error` to activity log. |
| Action Scheduler jobs | Throw exception → AS retries up to 3× with exponential backoff. Final failure writes `site.error` to activity log + updates `last_error`. |
| Signed request rejected | Always log to activity (could be MITM). Connector returns 401 with code `auth.signature_invalid`. |
| SPA — 4xx | Toast notification with message. Inline form errors for field issues. |
| SPA — 401 | Auto-refresh; if refresh fails, redirect to `/login`. |
| SPA — 5xx / network | Toast "Something went wrong, try again." TanStack Query auto-retries idempotent calls. |

---

## 10. Testing strategy

| Level | Target | Tools |
|---|---|---|
| **Unit** | KeyPair, Signer, Vault, rate-limit logic, JWT issue/verify, Zod schemas | PHPUnit (backend), Vitest (SPA) |
| **Integration** | REST endpoints, Action Scheduler jobs, DB writes | PHPUnit + `wp-phpunit` + test DB |
| **Contract** | Connector ↔ Dashboard signing protocol | Shared PHPUnit test fixtures + signed-payload golden files |
| **End-to-end (manual)** | Full connect flow: Local by Flywheel WP site ↔ dev dashboard ↔ dev SPA | Manual for MVP |
| **E2E (automated, phase 2)** | Connect, sync, disconnect flows in a browser | Playwright — not required for foundation |
| **Security** | Tampered sig, expired timestamp, replayed nonce, HTTP-only, wrong CORS origin → all must fail closed | PHPUnit cases in integration suite |

---

## 11. Build order (foundation sub-phases)

| Phase | Deliverable |
|---|---|
| **F1** | Scaffolding: Bedrock WP on Kinsta + dev env, empty `defyn-dashboard` plugin with activation hook creating 3 tables, CI placeholder |
| **F2** | Crypto primitives: KeyPair, Signer, Vault classes + unit tests (sign/verify, tamper detection, replay rejection). No HTTP yet. |
| **F3** | Dashboard auth REST + SPA login: JWT issue/refresh/logout/me endpoints, rate limiter, SPA scaffold (Vite + shadcn), working login flow |
| **F4** | Connector plugin scaffold + `POST /connect`: separate plugin repo, WP admin page "Generate Connection Code," stores keypair, code-validation only |
| **F5** | Handshake end-to-end: Dashboard `POST /sites` → AS job → connector `/connect` → challenge-response verified → sites row `pending → active`. SPA Add Site form works. |
| **F6** | Signed `/status` + `/heartbeat`: VerifySignature middleware. Dashboard SyncService + HealthService. First successful sync populates site info. |
| **F7** | Background scheduling: recurring AS jobs (sync_all, health_ping_all), cleanup job, Kinsta server cron verified |
| **F8** | SPA sites list + detail: filter + search, cached info display, action buttons (Refresh, Ping, Disconnect) |
| **F9** | Activity log: endpoints + SPA page, per-site activity on detail page |
| **F10** | Deploy + harden: Bedrock → Kinsta, SPA → Cloudflare Pages. CORS, HTTPS, rate limits verified in prod. Manual E2E against a real WP site. |

---

## 12. Deliberately out of scope

- Email verification for new accounts (operator accounts created manually via wp-admin or WP-CLI for single-tenant)
- Multi-factor auth (Phase 2)
- Sentry / external error monitoring (activity log is enough for MVP)
- Automated E2E tests (Playwright) — manual E2E in F10 covers MVP
- Whitelabel theming, per-client access control — Phase 2+
- Actually doing anything with a managed site (updates, backups, reports, scans, monitoring) — each becomes its own later phase

---

## 13. Definition of "foundation done"

- ✅ Log into `app.defyn.dev` with operator account
- ✅ Install connector plugin on a WP site, generate code, paste into Add Site
- ✅ Site appears in list with live cached info (WP/PHP versions, theme, counts, SSL, health)
- ✅ Background sync (30 min) + health jobs (5 min) running; stale sites flip to `stale`, unreachable to `offline`
- ✅ Disconnect works from both sides (SPA DELETE or plugin admin)
- ✅ Activity log shows every meaningful event with timestamps

---

## 14. Open questions / decisions to revisit

None at the time of writing — all foundation decisions are locked. Items likely to surface during implementation planning (writing-plans phase):

- Exact JWT library choice on PHP side (`firebase/php-jwt` is the default but worth confirming license fit)
- Rate-limit store: transients vs Redis (if Kinsta plan includes Redis, use it; otherwise transients)
- Whether to ship the connector plugin to WP.org public directory in Phase 2 (affects branding, support obligations)
- Final domain naming: `app.defyn.dev` + `defyn.com` vs `app.defyn.com` + `api.defyn.com` — operator preference

---

## Appendix A — Visual companion artifacts

The design was developed via the brainstorming skill's visual companion. Source HTML mockups are in `.superpowers/brainstorm/9353-1776494192/content/`:

- `dashboard-ui-approach.html` — four UI-approach options (A-D); chose C (standalone SPA)
- `architecture-overview.html` — three-runtime architecture diagram
- `data-model.html` — table schemas
- `connector-plugin.html` — connector design
- `dashboard-plugin.html` — backend design
- `spa-design.html` — SPA stack + routes
- `connection-flow.html` — 10-step handshake sequence
- `errors-testing-build.html` — error handling + testing + build order

(These are gitignored — reference-only.)
