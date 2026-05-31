# Cloudflare Pages SPA Deploy Runbook

> Operator runbook for deploying the DefynWP React SPA to Cloudflare Pages. Sister doc to `kinsta-backend.md`. After both deploy paths complete, run `programmatic-e2e.md` to verify end-to-end.

## Prerequisites

- Cloudflare account (free tier is sufficient)
- Domain ready for the SPA (e.g. `app.defyn.dev`)
- DNS provider access (Cloudflare DNS recommended — auto-config with Pages)
- Backend already deployed per `kinsta-backend.md` (you need the backend URL for the env var)
- Local clone of `defynWP` (this repo) up to date with `main` at `f10-deploy-harden-complete`
- `pnpm` installed locally (for first-time test build, optional)

## 1. Cloudflare Pages project setup

### 1a. GitHub integration (recommended)

1. **Cloudflare dashboard -> Workers & Pages -> Create application -> Pages -> Connect to Git**
2. Authorize Cloudflare to access the `Defyn-Digital/defynWP` GitHub repo (or your fork)
3. Set up the build:
   - **Project name**: `defynwp-spa` (or whatever you prefer; this becomes the default `<name>.pages.dev` URL)
   - **Production branch**: `main`
   - **Framework preset**: **Vite** (CF Pages auto-detects)
   - **Build command**: `pnpm install --frozen-lockfile && pnpm --filter ./apps/web build`
   - **Build output directory**: `apps/web/dist`
   - **Root directory**: leave blank (build runs from repo root)

### 1b. Direct upload path (alternative)

If you'd rather build locally and upload:

```bash
cd /path/to/defynWP/apps/web
pnpm install --frozen-lockfile
VITE_API_BASE_URL="https://<backend-domain>/wp-json/defyn/v1" pnpm build

# Upload the dist/ folder via wrangler CLI:
npx wrangler pages deploy ./dist --project-name=defynwp-spa
```

## 2. Required environment variables

Set in Cloudflare dashboard -> Pages project -> Settings -> Environment variables:

| Name | Production value | Notes |
|---|---|---|
| `VITE_API_BASE_URL` | `https://<backend-domain>/wp-json/defyn/v1` | Backend Kinsta URL. The SPA reads this at build time and embeds it in the bundle. |

**Build-time vs runtime:** Vite env vars prefixed `VITE_` are baked into the static bundle at build time. Changing them requires a rebuild + redeploy.

## 3. DNS setup

### If your domain is on Cloudflare DNS

1. **Cloudflare dashboard -> Pages project -> Custom domains -> Set up a custom domain**
2. Enter `app.defyn.dev` (or your chosen domain)
3. Cloudflare auto-creates the CNAME record. Wait 1-5 min for propagation.

### If your domain is elsewhere

1. Pages project -> Custom domains -> Set up a custom domain -> Enter `app.defyn.dev`
2. Cloudflare gives you a target hostname (e.g. `defynwp-spa.pages.dev`)
3. Add a CNAME record in your DNS provider: `app` -> `defynwp-spa.pages.dev`
4. Wait for propagation
5. Cloudflare verifies and auto-issues a Let's Encrypt cert

## 4. HTTPS

Automatic via Cloudflare. No action needed once the custom domain is verified. The cert auto-renews.

## 5. SPA routing

Cloudflare Pages has built-in SPA mode: any path that doesn't match a static file in `dist/` serves `index.html`. This is exactly what React Router expects.

Verify by visiting `https://app.defyn.dev/sites/1` directly (not via in-app nav) - the React app should load and route to SiteDetail.

If for some reason CF Pages doesn't auto-detect SPA mode, add `apps/web/public/_redirects`:

```
/*  /index.html  200
```

## 6. Caching policy

Cloudflare Pages defaults are sensible for Vite:

- `index.html` - short cache (no-cache, must-revalidate)
- Hashed asset files (`assets/*-<hash>.js`, `assets/*-<hash>.css`) - long cache (immutable, 1 year)

The Vite build emits content-hashed filenames so cache busting Just Works. No manual cache config needed.

## 7. CORS verification

The dashboard backend's `Defyn\Dashboard\Rest\Middleware\Cors` must include the SPA's origin (`https://app.defyn.dev`) in its allowed list. See `kinsta-backend.md` § 6 for backend-side setup.

Verify after both sides deployed:

```bash
curl -i -H "Origin: https://app.defyn.dev" \
        -H "Access-Control-Request-Method: GET" \
        -X OPTIONS \
        https://<backend-domain>/wp-json/defyn/v1/auth/me

# Expected response headers:
#   Access-Control-Allow-Origin: https://app.defyn.dev
#   Access-Control-Allow-Methods: ... GET ...
#   Access-Control-Allow-Credentials: true   (JWT in cookies)
```

If CORS is misconfigured, the SPA will fail to log in (preflight rejected) - symptom is the browser console showing `CORS policy: ... blocked`.

## 8. Cookie domain (JWT refresh)

F3a's refresh-token cookie is scoped to a parent domain so the SPA on `app.defyn.dev` can share auth state with the backend on `defyn.com` (or wherever).

**If SPA and backend share a parent domain** (e.g. both under `*.defyn.com`): cookie domain = `.defyn.com`, automatic.

**If SPA and backend are on entirely separate root domains** (e.g. `app.defyn.dev` SPA + `api.client.tld` backend): you'll need SameSite=None + Secure cookies, plus the backend's CORS must include `Access-Control-Allow-Credentials: true`. Browsers are increasingly strict about cross-site cookies - recommended path is to use a shared parent domain.

Verify by logging in via the deployed SPA and checking devtools -> Application -> Cookies. You should see a `defyn_refresh` cookie scoped to the parent domain.

## 9. Verification

After both sides deployed:

```bash
# 1. SPA loads
curl -I https://app.defyn.dev/
# Expected: 200 with text/html content-type

# 2. Static assets cached
curl -I https://app.defyn.dev/assets/index-<hash>.js
# Expected: cache-control: public, max-age=31536000, immutable

# 3. SPA-routed paths resolve to index.html
curl -s https://app.defyn.dev/sites/1 | grep '<div id="root">'
# Expected: match (proves SPA mode is working)

# 4. SPA can reach backend (open the deployed URL in a real browser, log in,
#    confirm the sites list loads from the live backend)
```

## 10. Post-deploy checklist

- [ ] Production build succeeded in CF Pages (check the build log under Pages -> Deployments)
- [ ] Custom domain shows green "Active" with valid cert
- [ ] `VITE_API_BASE_URL` is set on the production deployment (NOT just preview)
- [ ] Login round-trip works: SPA -> backend `/auth/login` returns 200 + sets refresh cookie
- [ ] First `/sites` GET round-trip returns 200 with an empty array (no sites yet)
- [ ] Network panel shows the right CORS headers on `auth/me` preflight

## 11. Adding a managed site (smoke test against real WP)

Once SPA + backend are both live:

1. Install the `defyn-connector` plugin on a real test WP site (any host)
2. In wp-admin -> Settings -> DefynWP Connector -> Generate Connection Code
3. In the SPA at `app.defyn.dev`, click Add Site, paste the code + URL
4. Watch the site flip pending -> active within a few seconds (handshake AS job)
5. Click Refresh -> watch `last_sync_at` advance + runtime info populate
6. Click Ping -> `last_contact_at` advances
7. View Activity -> events appear newest-first
8. Click Disconnect -> confirms -> SPA navigates to /sites, the connector plugin admin shows the site reset to `unconfigured` state. **F10 Task 1's signed-body fix is what makes this disconnect step actually reset the connector** - if state stays `connected`, the fix didn't make it through.

## Deferred to post-foundation

- Cloudflare Pages Functions for server-side rendering of meta tags / OG images
- Preview deployments for PRs (gated on backend staging environment)
- Sentry / web vitals integration for production error monitoring
