# Google Workspace SSO + Shared Team Fleet — Design

**Date:** 2026-06-22
**Status:** Approved (pending spec review)
**Scope:** dashboard plugin (PHP) + SPA (`apps/web`). Connector unchanged. **No schema migration.**

## Goal

Let **anyone with a `@defyn.com.au` Google Workspace account** sign in to the DefynWP app and co-manage **one shared agency fleet** of client sites, with every action attributed to the real person.

## Locked decisions (from brainstorm)

1. **Access scope — shared team fleet (Approach C).** A single implicit team = the `defyn.com.au` org. The fleet (sites + all their data) is visible to every authenticated team member. Implemented by **dropping the per-user `WHERE user_id = %d` filter** from fleet queries — not by building a `teams`/`team_id` abstraction (YAGNI for one internal team). `user_id` stays on each row as a **"created by" attribution stamp**.
2. **Sign-in — Google Workspace SSO.** "Sign in with Google," restricted to the `defyn.com.au` Workspace via the authoritative `hd` claim. Email/password login (`/auth/login`) is **kept as a break-glass fallback**.
3. **Permissions — flat.** Every signed-in teammate is a full admin (connect/update/delete sites, reports, settings). The activity log is the accountability layer. No roles, no approval gate (both noted as future options).

## Non-goals (explicitly out of scope)

- A `teams`/`team_id` multi-tenant model (Approach B). One internal team only.
- Roles, per-permission gating, or an approval/pending state for new sign-ins.
- Self-service email/password signup (SSO is the only new sign-in path; email/password stays only as the existing break-glass).
- Removing or changing the connector, or any connector-side auth.

---

## Section 1 — Identity & sign-in

### New endpoint: `POST /defyn/v1/auth/google`

**Body:** `{ "credential": "<google_id_token_jwt>" }` (the ID token the browser receives from Google Identity Services).

**Flow:**
1. **Verify the ID token** via a new `GoogleIdTokenVerifier` service (testable seam — keys/claims injectable):
   - Signature: RS256 against Google's JWKS (`https://www.googleapis.com/oauth2/v3/certs`), fetched and cached in a transient (respect `Cache-Control max-age`). Reuse the existing `firebase/php-jwt` dependency — **no new composer dep**.
   - `iss` ∈ {`accounts.google.com`, `https://accounts.google.com`}.
   - `aud === DEFYN_GOOGLE_CLIENT_ID`.
   - `exp` not passed (not expired); `email_verified === true`.
   - **`hd === "defyn.com.au"`** AND the email ends with `@defyn.com.au` (belt-and-suspenders; `hd` is authoritative).
   - Any failure → `401 auth.google_invalid` (bad/expired/forged token) or `403 auth.google_domain` (valid Google identity but wrong Workspace/domain).
2. **Find-or-create the WordPress user** by email:
   - `get_user_by('email', $claims['email'])` → if found, use it (this links `pradeep@defyn.com.au` to the existing `user_id 1` — no duplicate).
   - else `wp_insert_user` with: the verified email, a cryptographically random password (never used — SSO only), `display_name` from the Google `name` claim, default role (a plain authenticated WP user; the app's authorization is JWT-based, not WP-cap-based).
   - Store `$claims['sub']` in user-meta (`defyn_google_sub`) for linkage/audit.
3. **Issue our own tokens** via the existing `TokenService` exactly like `/auth/login`: access JWT (`sub` = real WP user id) in the body, rotating refresh JWT in the `defyn_refresh` httpOnly cookie. Log `auth.login` (method=google) to the activity log.

**Config:** `DEFYN_GOOGLE_CLIENT_ID` env constant, bootstrapped in `defyn-dashboard.php` using the existing optional-load pattern (mirrors `DEFYN_JWT_SECRET`/`DEFYN_VAULT_KEY`): if absent, the plugin still loads and only `/auth/google` fails with `503 auth.google_not_configured` + an admin notice. No client secret is needed (ID-token verification only checks `aud`).

**Break-glass:** `POST /auth/login` (email/password via `PasswordVerifier`/`wp_authenticate`) is unchanged and remains available for the owner.

---

## Section 2 — Shared fleet (de-scoping)

**Principle:** authorization becomes "is a signed-in team member" (already enforced by the JWT `RequireAuth` middleware) + "does the resource exist." The per-user fleet filter is removed.

**Concrete changes:**
- **Ownership-404 → existence-404:** controllers that call `SitesRepository::findByIdForUser($id, $userId)` switch to `findById($id)` (returns `null` → unchanged `404 sites.not_found` for bogus ids; any *existing* site is now reachable by any team member).
- **Fleet list/rollup queries:** every `...ForUser($userId)` method that scopes the fleet gets a team-wide (unfiltered) variant. The implementer must enumerate them exhaustively via grep; the known set includes:
  - `SitesRepository`: `findAllForUser` (+ its `?filter=` drill-in), `countAllForUser`, the 5 pending-update/attention **count** methods, `findSitesNeedingAttention`.
  - `SitePluginsRepository::findAllPendingUpdatesForUser`, `ThemesRepository::findAllPendingUpdatesForUser`.
  - `BulkJobsRepository` list/find (jobs become team-wide).
  - `ActivityLogRepository::tailForUser` (overview recent-activity) and the report range helpers.
  - Fleet rollups in `SecurityService`/`SiteVulnerabilitiesRepository`, `InsightsService` (`SitePerformanceRepository::findFleetForUser`, `SiteAnalyticsRepository::findFleetForUser`), and the monitoring fleet view.
  - Per-site reads (plugins/themes/core/security/performance/analytics/links/reports) are already site-scoped — they de-scope automatically once the site-ownership gate is relaxed; verify each controller's gate.
- **Attribution preserved:** `user_id` columns are kept. Controllers keep receiving `_authenticated_user_id` and continue to: stamp `created_by` on newly connected sites, and pass the real user into `ActivityLogger::log(...)`. Activity attribution ("who did what") is therefore correct for free under real per-person identities.
- **Report branding → team-wide:** `BrandingService` currently stores white-label config in **per-user** `user_meta`. Move it to a **shared site option** (e.g. `defyn_report_branding`). `SettingsController` report_branding GET/POST read/write the shared option. On upgrade, **one-time migrate** the current owner's (`user_id 1`) existing branding meta into the shared option so the brand isn't lost.

**Stays per-person (NOT de-scoped):** rate-limit buckets, refresh-token `jti` store (`user_meta`), and sessions — each real user has their own.

---

## Section 3 — SPA login screen

- Add a **"Sign in with Google"** button to `routes/Login.tsx` using Google Identity Services (`VITE_GOOGLE_CLIENT_ID`). On the credential callback, call a new `apiClient`/`AuthContext` path `loginWithGoogle(credential)` → `POST /auth/google` → store access token → navigate to `/overview`.
- The existing **email/password form stays** as a secondary/break-glass option (e.g. below the Google button or behind a "Sign in another way" toggle).
- The account menu shows the **real person's** name/email (from `/auth/me`, already returns the authenticated user).
- Visual: clean centered auth card with the Google button primary. (If the user supplies a specific login mockup, match it; otherwise default to the Branded-Navy design-system card.)

---

## Section 4 — Security, config, testing, rollout

### Security
- ID-token verification is the security boundary: reject on any failed claim (signature/`aud`/`iss`/`exp`/`email_verified`/`hd`). The `hd` Workspace claim — not the email string — is the authoritative domain gate.
- **Token issuance is domain-gated.** Because the fleet is de-scoped (any authenticated user sees everything), BOTH sign-in paths only mint a token for an `@defyn.com.au` user: Google via the `hd` claim, and the break-glass `/auth/login` gains a post-`wp_authenticate` check that the resolved user's email ends with `@defyn.com.au` (else `403 auth.domain_forbidden`). This guarantees a stray non-team WordPress user (e.g. one created directly in wp-admin) can never obtain a token and therefore can never see the fleet. (Allowed domain is a constant; future-proof as a config if needed.)
- Rate-limit `/auth/google` (new `RateLimit::authGoogle` bucket, e.g. per-IP, abuse-resistant).
- The Google client ID is public (lives in the SPA) — that is expected and safe; no secret is involved in the ID-token flow.
- CORS stays locked to the SPA origin; `/auth/google` added to the CORS-tested route set.

### Config / env
- Backend (Kinsta): `DEFYN_GOOGLE_CLIENT_ID`.
- SPA (Cloudflare build env): `VITE_GOOGLE_CLIENT_ID`.

### No schema change
Google `sub` → user-meta; branding → shared option; de-scoping removes `WHERE` clauses. `Activation::SCHEMA_VERSION` is **unchanged** (no migration, no version-pin ripple) — a lower-risk dashboard release.

### Testing
- **PHP:** `GoogleIdTokenVerifier` (valid claims pass; reject bad `aud`/`iss`/`exp`/`email_verified`; reject `hd !== defyn.com.au`), find-or-create (links existing email; creates new; stores `sub`), `/auth/google` controller (200 issues tokens + refresh cookie / 401 invalid / 403 wrong domain / 503 not configured), the de-scoped repositories (a second user sees user 1's sites), team-wide branding (option read/write + one-time migration), rate-limit bucket, CORS.
- **SPA:** Login renders the Google button, `loginWithGoogle` success path stores the token and redirects, break-glass form still works, AuthContext exposes the new path.

### Rollout
1. Implementer builds + tests (dashboard release vNEXT + SPA).
2. **Operator one-time Google Cloud setup** (exact click-by-click provided at implementation time): create an OAuth **client ID** (Web application) with `https://app.defynwp.defyn.agency` (and `http://localhost:5173` for dev) as authorized JavaScript origins; set the **OAuth consent screen to "Internal"** (restricts to the `defyn.com.au` Workspace, complementing the `hd` check); copy the client ID.
3. Operator sets `DEFYN_GOOGLE_CLIENT_ID` (Kinsta) + `VITE_GOOGLE_CLIENT_ID` (Cloudflare), installs the dashboard release (Replace-in-place, never delete), SPA auto-deploys.
4. Smoke: a non-owner `@defyn.com.au` teammate signs in with Google → sees the shared fleet (SmartCoding + uberbrand) → can act; a non-`defyn.com.au` Google account is rejected with `403`.

## Future (not now)
- Roles (admin vs member) or an approve-new-member gate — both deferred; the flat model + activity log is the v1.
- Multi-team/org (`team_id`) if DefynWP ever serves multiple agencies.
