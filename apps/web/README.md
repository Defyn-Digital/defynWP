# DefynWP — Web SPA

Vite + React + TypeScript SPA that talks to the DefynWP dashboard plugin's REST API. Login + welcome page in F3b; sites list + activity log come in later F-phases.

## Prerequisites

- Node 20+ and pnpm 9+
- The Local-by-Flywheel WP site `defynwp.local` running with the `defyn-dashboard` plugin activated and `DEFYN_JWT_SECRET` set in `wp-config.php`

## Setup

```bash
pnpm install
cp .env.example .env.local      # if you need to override VITE_WP_URL
pnpm dev                        # http://localhost:5173
```

## How auth works in dev

The Vite dev server proxies `/api/*` → `https://defynwp.local/wp-json/*`. This makes the SPA appear same-origin to the browser, sidestepping cross-origin cookie issues for the refresh-token flow.

In production the SPA at `app.defyn.dev` and the API at (e.g.) `defyn.com` are different origins; F3a's `Cors` middleware allowlists `DEFYN_SPA_ORIGIN` and the refresh cookie is set with `Domain=.defyn.dev`. F10 wires those bits up.

## Scripts

| Command | Purpose |
|---|---|
| `pnpm dev` | Start the Vite dev server with HMR |
| `pnpm build` | Type-check + build production bundle to `dist/` |
| `pnpm preview` | Serve the production build locally |
| `pnpm test` | Run the Vitest suite once |
| `pnpm test:watch` | Vitest in watch mode |
| `pnpm lint` | TypeScript-only lint (`tsc --noEmit`) |

## Project layout

See `apps/web/src/` — `routes/`, `components/ui/` (shadcn primitives), `lib/` (apiClient, auth context, queryClient, cn helper), `types/`, `test/` (MSW handlers + Vitest setup).

## Tests

Vitest + @testing-library/react + MSW v2. MSW handlers in `src/test/handlers.ts` stand in for the F3a backend so the SPA can be unit-tested without a running WordPress.
