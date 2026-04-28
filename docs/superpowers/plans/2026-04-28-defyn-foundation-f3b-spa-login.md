# DefynWP Foundation F3b — SPA Scaffold + Login Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A working React SPA at `apps/web/` that boots in dev, has a login form which calls F3a's `POST /auth/login`, stores the access token in memory, persists the refresh token via httpOnly cookie, automatically refreshes on 401 via `apiClient`, and shows a logged-in welcome page that calls `GET /auth/me`. Vitest + Testing Library + MSW give it test coverage. New CI job runs alongside the existing PHP one.

**Architecture:** Vite-built React SPA, TypeScript everywhere, shadcn/ui + Tailwind for components, React Router v6 for routes, TanStack Query for server state + auto-refetch, React Hook Form + Zod for the login form. A single `apiClient` wraps `fetch` with `credentials: 'include'`, attaches `Authorization: Bearer <access>` from an in-memory token store, and on 401 attempts `POST /auth/refresh`. Vite dev-server proxies `/api/*` through to the local WP install so cross-origin cookie weirdness is avoided in dev — production uses the cross-origin CORS that F3a already supports.

**Tech Stack:** Node 20+ · pnpm 9 · Vite 5 · React 18 · TypeScript 5 · Tailwind CSS 4 · shadcn/ui · React Router v6 · TanStack Query v5 · React Hook Form 7 + Zod 3 · Vitest + @testing-library/react + MSW v2

---

## About this plan

This is **F3b of the F3 split** (F3a = backend auth REST, already on main; F3b = SPA scaffold + login page, this plan).

**Source spec:** [`docs/superpowers/specs/2026-04-18-defyn-foundation-design.md`](../specs/2026-04-18-defyn-foundation-design.md) — § 7.1 (routes), § 7.2 (auth flow), § 7.3 (project structure).

**Definition of "F3b done":**
1. `pnpm dev` from `apps/web/` boots the SPA at `localhost:5173`. Visiting `/` redirects to `/login` when unauthenticated.
2. Login form validates email + password client-side (Zod) and calls `POST /auth/login` via `apiClient`. On success, stores access token in memory, navigates to `/`. On 401, shows the spec error message inline. On 429 (rate-limited), shows a banner.
3. The authenticated `/` route fetches `GET /auth/me` via TanStack Query and renders `Welcome, <display_name>`. Logout button calls `POST /auth/logout` and redirects to `/login`.
4. `apiClient` automatically attempts `POST /auth/refresh` on any 401 response, retries the original request once with the new access token, and redirects to `/login` if refresh itself fails.
5. Vitest suite passes with at least the apiClient logic, login form happy-path + error mapping, and route gating tested via MSW-mocked endpoints.
6. CI green: a new `web` job runs alongside the existing `dashboard-plugin` job in the same workflow.

---

## Important deviations from the original (combined) F3 plan

The F1 plan's roadmap row mentioned "shadcn-ui" and "Vite" with no further detail. F3b's concrete choices, with rationale:

- **Tailwind 4** (not 3.x) — Tailwind 4 is the current stable line, has a vastly simpler config (a single `@import "tailwindcss"` instead of postcss config + content scanning), and shadcn/ui has first-class support for it as of 2024.
- **MSW v2** (not v1) — v2 is the current stable, uses Service Worker / Fetch API correctly, and has better TypeScript support.
- **TanStack Query v5** (not v4) — v5 is current stable, hooks have cleaner APIs (`useQuery({queryKey})` instead of positional args).
- **No Zustand for F3b** — the in-memory access token + a tiny `<AuthContext>` for routing is simpler than introducing a third state library. Zustand can be added later when local UI state grows.

---

## File structure after F3b

```
apps/web/                                       # NEW package — the SPA
├── .gitignore                                  # node_modules/, dist/, .env*.local
├── package.json
├── pnpm-lock.yaml
├── tsconfig.json
├── tsconfig.node.json
├── vite.config.ts                              # dev proxy /api → defynwp.local
├── tailwind.config.ts
├── postcss.config.js                           # Tailwind 4 plugin
├── index.html
├── components.json                             # shadcn/ui config
├── README.md                                   # SPA-specific dev setup
├── src/
│   ├── main.tsx                                # bootstrap: Router + QueryClient + AuthProvider
│   ├── App.tsx                                 # route tree (public + protected)
│   ├── index.css                               # @import "tailwindcss"; + shadcn theme tokens
│   ├── routes/
│   │   ├── Login.tsx
│   │   └── Home.tsx                            # the protected welcome page
│   ├── components/
│   │   └── ui/                                 # shadcn/ui generated components (button, input, label, card, alert, form)
│   ├── lib/
│   │   ├── apiClient.ts                        # fetch wrapper, auth header injection, auto-refresh-on-401
│   │   ├── auth.ts                             # in-memory access token store + AuthContext
│   │   ├── queryClient.ts                      # TanStack Query default config
│   │   └── cn.ts                               # tailwind className merger (shadcn convention)
│   ├── types/
│   │   └── api.ts                              # Zod schemas: LoginResponse, MeResponse, ErrorEnvelope
│   └── test/
│       ├── setup.ts                            # MSW server setup, RTL globals
│       └── handlers.ts                         # default MSW handlers for auth endpoints
└── tests/
    ├── apiClient.test.ts                       # apiClient unit + integration tests via MSW
    ├── Login.test.tsx                          # login form + error mapping
    ├── auth.test.tsx                           # AuthContext + route gating
    └── (more as Tasks add features)

# Modified at repo root:
.github/workflows/test.yml                      # ADD a `web` job parallel to dashboard-plugin
```

---

## Prerequisites

```bash
node --version   # ≥ 20 — current Local has v25.x
pnpm --version   # ≥ 9 — current Local has v9.12
```

If pnpm is missing: `npm install -g pnpm`.

The Local-by-Flywheel WP site `defynwp.local` (the local dev WP for F1+F2+F3a) must be **running** so the Vite proxy has somewhere to point. F3a's auth endpoints are at `https://defynwp.local/wp-json/defyn/v1/auth/*`.

> **Pre-flight requirement for the live SPA**: `DEFYN_JWT_SECRET` must be set in the WP install's environment (Bedrock `.env` or `wp-config.php` `define('DEFYN_JWT_SECRET', '...');`) before login actually works in the browser. Tasks 5+ work without it because they use MSW mocks. The plan calls out the live-test step in the acceptance task (Task 12) and provides a one-time setup snippet there.

---

## Tasks

### Task 1: `apps/web/` skeleton — Vite + React + TS

**Why first:** every later task assumes a working Vite project. Get the package.json, tsconfig, and `pnpm dev` working before adding anything else.

**Files:**
- Create: `apps/web/package.json`
- Create: `apps/web/tsconfig.json`
- Create: `apps/web/tsconfig.node.json`
- Create: `apps/web/vite.config.ts`
- Create: `apps/web/index.html`
- Create: `apps/web/src/main.tsx`
- Create: `apps/web/src/App.tsx`
- Create: `apps/web/.gitignore`

- [ ] **Step 1: Create the directory and initialize package.json**

```bash
mkdir -p "/Users/pradeep/Local Sites/defynWP/apps/web/src"
```

Write `apps/web/package.json`:

```json
{
  "name": "defyn-web",
  "private": true,
  "version": "0.1.0",
  "type": "module",
  "scripts": {
    "dev": "vite",
    "build": "tsc -b && vite build",
    "preview": "vite preview",
    "test": "vitest run",
    "test:watch": "vitest",
    "lint": "tsc --noEmit"
  },
  "dependencies": {
    "react": "^18.3.1",
    "react-dom": "^18.3.1"
  },
  "devDependencies": {
    "@types/react": "^18.3.12",
    "@types/react-dom": "^18.3.1",
    "@vitejs/plugin-react": "^4.3.3",
    "typescript": "^5.6.3",
    "vite": "^5.4.10"
  }
}
```

- [ ] **Step 2: Write `apps/web/tsconfig.json`**

```json
{
  "compilerOptions": {
    "target": "ES2022",
    "lib": ["ES2022", "DOM", "DOM.Iterable"],
    "module": "ESNext",
    "moduleResolution": "Bundler",
    "jsx": "react-jsx",
    "strict": true,
    "noUnusedLocals": true,
    "noUnusedParameters": true,
    "noFallthroughCasesInSwitch": true,
    "isolatedModules": true,
    "esModuleInterop": true,
    "skipLibCheck": true,
    "resolveJsonModule": true,
    "allowImportingTsExtensions": false,
    "noEmit": true,
    "useDefineForClassFields": true,
    "baseUrl": ".",
    "paths": {
      "@/*": ["./src/*"]
    }
  },
  "include": ["src", "tests"],
  "references": [{ "path": "./tsconfig.node.json" }]
}
```

- [ ] **Step 3: Write `apps/web/tsconfig.node.json`**

```json
{
  "compilerOptions": {
    "composite": true,
    "module": "ESNext",
    "moduleResolution": "Bundler",
    "skipLibCheck": true,
    "allowSyntheticDefaultImports": true,
    "strict": true
  },
  "include": ["vite.config.ts"]
}
```

- [ ] **Step 4: Write `apps/web/vite.config.ts`**

```ts
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

// Vite dev proxy: any request the SPA makes to /api/* gets forwarded to the local
// WordPress install. This avoids cross-origin cookie weirdness in dev — the SPA
// thinks it's same-origin. Production uses real CORS (DEFYN_SPA_ORIGIN allowlist
// in F3a's Cors middleware).
const WP_TARGET = process.env.VITE_WP_URL ?? 'https://defynwp.local';

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: WP_TARGET,
        changeOrigin: true,
        secure: false, // Local-by-Flywheel uses self-signed certs
        rewrite: (p) => p.replace(/^\/api/, '/wp-json'),
      },
    },
  },
});
```

- [ ] **Step 5: Write `apps/web/index.html`**

```html
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <link rel="icon" type="image/svg+xml" href="/vite.svg" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>DefynWP</title>
  </head>
  <body>
    <div id="root"></div>
    <script type="module" src="/src/main.tsx"></script>
  </body>
</html>
```

- [ ] **Step 6: Write `apps/web/src/main.tsx`**

```tsx
import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>,
);
```

- [ ] **Step 7: Write `apps/web/src/App.tsx`** (placeholder — Task 5 wires Router)

```tsx
export default function App() {
  return (
    <main style={{ padding: '2rem', fontFamily: 'system-ui' }}>
      <h1>DefynWP</h1>
      <p>Skeleton bootstrapped. Routing arrives in Task 5.</p>
    </main>
  );
}
```

- [ ] **Step 8: Write `apps/web/.gitignore`**

```gitignore
node_modules/
dist/
*.local
.env*.local
.DS_Store

# Editor
.vscode/
.idea/

# Test/build artifacts
coverage/
.vitest-cache/
```

- [ ] **Step 9: Install + verify**

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm install
```

Expected: ~150 packages installed. No vulnerabilities flagged (or only low-severity transitive ones — note them but don't block).

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm build 2>&1 | tail -10
```

Expected: a successful build emitting `dist/index.html` + assets.

- [ ] **Step 10: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add apps/web/ && git commit -m "$(cat <<'EOF'
F3b: apps/web/ skeleton — Vite + React 18 + TypeScript 5

Bare-minimum SPA package: package.json with pnpm scripts (dev, build,
test, lint), strict TS config with @/ path alias, Vite config with
dev proxy /api → https://defynwp.local for cross-origin cookie
avoidance in dev, placeholder App that boots cleanly. Tasks 2-11 add
Tailwind, shadcn/ui, routing, apiClient, auth, login form, tests, CI.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Tailwind CSS 4 + design tokens

**Why:** every component from Task 3 onward expects Tailwind classes to compile. shadcn/ui requires it.

**Files:**
- Modify: `apps/web/package.json` (add tailwindcss, @tailwindcss/vite, postcss, autoprefixer)
- Create: `apps/web/postcss.config.js`
- Modify: `apps/web/vite.config.ts` (add Tailwind plugin)
- Create: `apps/web/src/index.css`
- Modify: `apps/web/src/main.tsx` (import index.css)
- Modify: `apps/web/src/App.tsx` (use a Tailwind class to verify it's wired)

- [ ] **Step 1: Add Tailwind 4 + plugin**

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm add -D tailwindcss@^4 @tailwindcss/vite@^4 postcss autoprefixer
```

Expected: 4 packages added.

- [ ] **Step 2: Write `apps/web/postcss.config.js`**

```js
export default {
  plugins: {
    autoprefixer: {},
  },
};
```

(Tailwind 4 doesn't need a PostCSS plugin entry — the Vite plugin handles compilation. Autoprefixer alone for vendor prefixes.)

- [ ] **Step 3: Update `apps/web/vite.config.ts` to include the Tailwind plugin**

Replace the entire file with:

```ts
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import path from 'node:path';

const WP_TARGET = process.env.VITE_WP_URL ?? 'https://defynwp.local';

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: WP_TARGET,
        changeOrigin: true,
        secure: false,
        rewrite: (p) => p.replace(/^\/api/, '/wp-json'),
      },
    },
  },
});
```

- [ ] **Step 4: Write `apps/web/src/index.css`**

```css
@import "tailwindcss";

/* shadcn/ui design tokens — these get extended in Task 3 when we install shadcn */
:root {
  --background: 0 0% 100%;
  --foreground: 240 10% 3.9%;
  --primary: 240 5.9% 10%;
  --primary-foreground: 0 0% 98%;
  --border: 240 5.9% 90%;
  --ring: 240 5.9% 10%;
  --radius: 0.5rem;
}

.dark {
  --background: 240 10% 3.9%;
  --foreground: 0 0% 98%;
  --primary: 0 0% 98%;
  --primary-foreground: 240 5.9% 10%;
  --border: 240 3.7% 15.9%;
  --ring: 240 4.9% 83.9%;
}

body {
  margin: 0;
  font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  background: hsl(var(--background));
  color: hsl(var(--foreground));
}
```

- [ ] **Step 5: Update `apps/web/src/main.tsx` to import the CSS**

```tsx
import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';
import './index.css';

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>,
);
```

- [ ] **Step 6: Update `apps/web/src/App.tsx` to use Tailwind classes (sanity check)**

```tsx
export default function App() {
  return (
    <main className="min-h-screen p-8 font-sans">
      <h1 className="text-3xl font-bold mb-2">DefynWP</h1>
      <p className="text-sm text-gray-500">Skeleton + Tailwind 4 wired. Routing arrives in Task 5.</p>
    </main>
  );
}
```

- [ ] **Step 7: Build to verify Tailwind compiles**

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm build 2>&1 | tail -10
```

Expected: build succeeds; `dist/assets/*.css` exists and contains Tailwind utility classes (e.g. `.text-3xl`, `.font-bold`).

- [ ] **Step 8: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add apps/web/package.json apps/web/pnpm-lock.yaml apps/web/postcss.config.js apps/web/vite.config.ts apps/web/src/index.css apps/web/src/main.tsx apps/web/src/App.tsx && git commit -m "$(cat <<'EOF'
F3b: Tailwind CSS 4 + shadcn-compatible design tokens

@tailwindcss/vite plugin compiles Tailwind in-process during dev/build.
HSL-based design tokens (--background, --primary, --border, --radius,
etc.) under :root + .dark provide the variables shadcn/ui's components
consume in Task 3. Sanity-checked App.tsx uses Tailwind utilities so a
dev catching a regression sees the failure immediately.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: shadcn/ui base components

**Why:** Login form needs `Button`, `Input`, `Label`, `Card`, `Alert`, `Form` (with React Hook Form integration). Set them up via shadcn's CLI.

**Files:**
- Modify: `apps/web/package.json` (lots of new deps — clsx, tailwind-merge, class-variance-authority, lucide-react, radix-ui primitives, react-hook-form integration)
- Create: `apps/web/components.json` (shadcn config)
- Create: `apps/web/src/lib/cn.ts`
- Create: `apps/web/src/components/ui/{button,input,label,card,alert,form}.tsx`
- Modify: `apps/web/tailwind.config.ts` (theme extension to read CSS vars)

- [ ] **Step 1: Install shadcn dependencies**

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm add clsx tailwind-merge class-variance-authority lucide-react @radix-ui/react-label @radix-ui/react-slot react-hook-form @hookform/resolvers zod
```

Expected: ~10 packages added including transitives.

- [ ] **Step 2: Write `apps/web/src/lib/cn.ts`** (Tailwind className merger — shadcn convention)

```ts
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}
```

- [ ] **Step 3: Write `apps/web/components.json`** (shadcn config — informational, not used at runtime)

```json
{
  "$schema": "https://ui.shadcn.com/schema.json",
  "style": "default",
  "rsc": false,
  "tsx": true,
  "tailwind": {
    "config": "tailwind.config.ts",
    "css": "src/index.css",
    "baseColor": "zinc",
    "cssVariables": true,
    "prefix": ""
  },
  "aliases": {
    "components": "@/components",
    "utils": "@/lib/cn",
    "ui": "@/components/ui",
    "lib": "@/lib",
    "hooks": "@/hooks"
  }
}
```

- [ ] **Step 4: Write `apps/web/tailwind.config.ts`**

Tailwind 4 reads most config from CSS, but a `tailwind.config.ts` file provides explicit theme tokens for tools (like editor IntelliSense) that need them.

```ts
import type { Config } from 'tailwindcss';

const config: Config = {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        background: 'hsl(var(--background))',
        foreground: 'hsl(var(--foreground))',
        primary: {
          DEFAULT: 'hsl(var(--primary))',
          foreground: 'hsl(var(--primary-foreground))',
        },
        border: 'hsl(var(--border))',
        ring: 'hsl(var(--ring))',
      },
      borderRadius: {
        lg: 'var(--radius)',
        md: 'calc(var(--radius) - 2px)',
        sm: 'calc(var(--radius) - 4px)',
      },
    },
  },
};

export default config;
```

- [ ] **Step 5: Create components directory**

```bash
mkdir -p "/Users/pradeep/Local Sites/defynWP/apps/web/src/components/ui"
```

- [ ] **Step 6: Write `apps/web/src/components/ui/button.tsx`**

```tsx
import * as React from 'react';
import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/cn';

const buttonVariants = cva(
  'inline-flex items-center justify-center rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50',
  {
    variants: {
      variant: {
        default: 'bg-primary text-primary-foreground hover:bg-primary/90',
        outline: 'border border-border bg-background hover:bg-zinc-100',
        ghost: 'hover:bg-zinc-100',
      },
      size: {
        default: 'h-9 px-4 py-2',
        sm: 'h-8 px-3',
        lg: 'h-10 px-8',
      },
    },
    defaultVariants: { variant: 'default', size: 'default' },
  },
);

export interface ButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof buttonVariants> {
  asChild?: boolean;
}

export const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant, size, asChild = false, ...props }, ref) => {
    const Comp = asChild ? Slot : 'button';
    return <Comp className={cn(buttonVariants({ variant, size, className }))} ref={ref} {...props} />;
  },
);
Button.displayName = 'Button';
```

- [ ] **Step 7: Write `apps/web/src/components/ui/input.tsx`**

```tsx
import * as React from 'react';
import { cn } from '@/lib/cn';

export const Input = React.forwardRef<HTMLInputElement, React.InputHTMLAttributes<HTMLInputElement>>(
  ({ className, type, ...props }, ref) => (
    <input
      type={type}
      className={cn(
        'flex h-9 w-full rounded-md border border-border bg-background px-3 py-1 text-sm shadow-sm transition-colors file:border-0 file:bg-transparent file:text-sm placeholder:text-zinc-500 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
        className,
      )}
      ref={ref}
      {...props}
    />
  ),
);
Input.displayName = 'Input';
```

- [ ] **Step 8: Write `apps/web/src/components/ui/label.tsx`**

```tsx
import * as React from 'react';
import * as LabelPrimitive from '@radix-ui/react-label';
import { cn } from '@/lib/cn';

export const Label = React.forwardRef<
  React.ElementRef<typeof LabelPrimitive.Root>,
  React.ComponentPropsWithoutRef<typeof LabelPrimitive.Root>
>(({ className, ...props }, ref) => (
  <LabelPrimitive.Root
    ref={ref}
    className={cn('text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70', className)}
    {...props}
  />
));
Label.displayName = 'Label';
```

- [ ] **Step 9: Write `apps/web/src/components/ui/card.tsx`**

```tsx
import * as React from 'react';
import { cn } from '@/lib/cn';

export const Card = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div ref={ref} className={cn('rounded-lg border border-border bg-background text-foreground shadow-sm', className)} {...props} />
  ),
);
Card.displayName = 'Card';

export const CardHeader = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => <div ref={ref} className={cn('flex flex-col space-y-1.5 p-6', className)} {...props} />,
);
CardHeader.displayName = 'CardHeader';

export const CardTitle = React.forwardRef<HTMLParagraphElement, React.HTMLAttributes<HTMLHeadingElement>>(
  ({ className, ...props }, ref) => <h3 ref={ref} className={cn('text-lg font-semibold leading-none tracking-tight', className)} {...props} />,
);
CardTitle.displayName = 'CardTitle';

export const CardContent = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => <div ref={ref} className={cn('p-6 pt-0', className)} {...props} />,
);
CardContent.displayName = 'CardContent';
```

- [ ] **Step 10: Write `apps/web/src/components/ui/alert.tsx`**

```tsx
import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/cn';

const alertVariants = cva('relative w-full rounded-lg border border-border p-4 text-sm', {
  variants: {
    variant: {
      default: 'bg-background text-foreground',
      destructive: 'border-red-500/50 text-red-600 [&>svg]:text-red-600',
    },
  },
  defaultVariants: { variant: 'default' },
});

export const Alert = React.forwardRef<
  HTMLDivElement,
  React.HTMLAttributes<HTMLDivElement> & VariantProps<typeof alertVariants>
>(({ className, variant, ...props }, ref) => (
  <div ref={ref} role="alert" className={cn(alertVariants({ variant }), className)} {...props} />
));
Alert.displayName = 'Alert';

export const AlertDescription = React.forwardRef<HTMLParagraphElement, React.HTMLAttributes<HTMLParagraphElement>>(
  ({ className, ...props }, ref) => <div ref={ref} className={cn('text-sm leading-relaxed', className)} {...props} />,
);
AlertDescription.displayName = 'AlertDescription';
```

- [ ] **Step 11: Verify build**

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm build 2>&1 | tail -8
```

Expected: build succeeds, no TS errors. (Components aren't used yet — Task 7's Login page will import them.)

- [ ] **Step 12: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add apps/web/package.json apps/web/pnpm-lock.yaml apps/web/components.json apps/web/tailwind.config.ts apps/web/src/lib/cn.ts apps/web/src/components/ && git commit -m "$(cat <<'EOF'
F3b: shadcn/ui base components — Button, Input, Label, Card, Alert

Manually-vendored shadcn/ui components (rather than running their CLI
and committing the same files indirectly). Five primitives cover the
F3b login + welcome page surface; Form integration with React Hook
Form is added in Task 7 alongside the actual login form.

Adds clsx, tailwind-merge, class-variance-authority, lucide-react,
@radix-ui/react-{label,slot}, react-hook-form, @hookform/resolvers,
zod. cn() helper at @/lib/cn merges Tailwind class strings idiomatically.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Vitest + Testing Library + MSW v2 harness

**Why:** every later task is TDD. Set up the test runner before writing tests.

**Files:**
- Modify: `apps/web/package.json` (add vitest, @testing-library/react, jsdom, msw, @testing-library/jest-dom)
- Create: `apps/web/vitest.config.ts`
- Create: `apps/web/src/test/setup.ts`
- Create: `apps/web/src/test/handlers.ts`
- Create: `apps/web/tests/smoke.test.ts`

- [ ] **Step 1: Install test deps**

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm add -D vitest @vitest/ui @testing-library/react @testing-library/jest-dom @testing-library/user-event jsdom msw@^2 happy-dom
```

Expected: ~10 packages added.

- [ ] **Step 2: Write `apps/web/vitest.config.ts`**

```ts
import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import path from 'node:path';

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    include: ['tests/**/*.{test,spec}.{ts,tsx}'],
  },
});
```

- [ ] **Step 3: Write `apps/web/src/test/handlers.ts`** (MSW handlers — default mocks for the F3a auth API)

```ts
import { http, HttpResponse } from 'msw';

export const handlers = [
  // Default: login succeeds with a fake access token.
  http.post('*/wp-json/defyn/v1/auth/login', async ({ request }) => {
    const body = (await request.json()) as { email?: string; password?: string };
    if (!body.email || !body.password) {
      return HttpResponse.json(
        { error: { code: 'auth.missing_fields', message: 'Email and password are required.' } },
        { status: 400 },
      );
    }
    if (body.password === 'wrong') {
      return HttpResponse.json(
        { error: { code: 'auth.invalid_credentials', message: 'Invalid email or password.' } },
        { status: 401 },
      );
    }
    return HttpResponse.json({ access_token: 'fake.access.token' }, { status: 200 });
  }),

  // /auth/me returns a fixed user when given any Bearer token.
  http.get('*/wp-json/defyn/v1/auth/me', ({ request }) => {
    const auth = request.headers.get('Authorization') ?? '';
    if (!auth.startsWith('Bearer ')) {
      return HttpResponse.json(
        { error: { code: 'auth.missing_token', message: 'Authorization: Bearer <token> required.' } },
        { status: 401 },
      );
    }
    return HttpResponse.json({ id: 1, email: 'admin@defyn.test', display_name: 'Admin User' }, { status: 200 });
  }),

  // /auth/refresh — generic success.
  http.post('*/wp-json/defyn/v1/auth/refresh', () =>
    HttpResponse.json({ access_token: 'fake.access.token.v2' }, { status: 200 }),
  ),

  // /auth/logout — always 204.
  http.post('*/wp-json/defyn/v1/auth/logout', () => new HttpResponse(null, { status: 204 })),
];
```

- [ ] **Step 4: Write `apps/web/src/test/setup.ts`**

```ts
import '@testing-library/jest-dom/vitest';
import { afterAll, afterEach, beforeAll } from 'vitest';
import { setupServer } from 'msw/node';
import { handlers } from './handlers';

export const server = setupServer(...handlers);

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());
```

- [ ] **Step 5: Write `apps/web/tests/smoke.test.ts`**

```ts
import { describe, it, expect } from 'vitest';

describe('smoke', () => {
  it('toolchain works', () => {
    expect(1 + 1).toBe(2);
  });

  it('jsdom environment is active', () => {
    expect(typeof window).toBe('object');
    expect(typeof document).toBe('object');
  });
});
```

- [ ] **Step 6: Run the tests**

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm test 2>&1 | tail -10
```

Expected: 2 tests, 2 passing.

- [ ] **Step 7: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add apps/web/package.json apps/web/pnpm-lock.yaml apps/web/vitest.config.ts apps/web/src/test/ apps/web/tests/smoke.test.ts && git commit -m "$(cat <<'EOF'
F3b: Vitest + Testing Library + MSW v2 test harness

Vitest with jsdom env, RTL globals, MSW v2 handlers covering all four
F3a auth endpoints with reasonable defaults. Smoke test proves the
toolchain wired correctly.

Tests live in apps/web/tests/ (siblings to src/), keeping test code
out of the bundle while still letting them import from @/* via the
resolve alias.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: TDD `apiClient` — fetch wrapper + auth header injection

**Why:** every other Task that talks to the backend needs this. Build it before consumers arrive.

**Files:**
- Create: `apps/web/src/lib/apiClient.ts`
- Create: `apps/web/tests/apiClient.test.ts`

- [ ] **Step 1: Write the failing test**

Write `apps/web/tests/apiClient.test.ts`:

```ts
import { describe, it, expect, beforeEach } from 'vitest';
import { apiClient, setAccessToken, clearAccessToken } from '@/lib/apiClient';
import { server } from '@/test/setup';
import { http, HttpResponse } from 'msw';

describe('apiClient', () => {
  beforeEach(() => {
    clearAccessToken();
  });

  it('GET returns parsed JSON on 2xx', async () => {
    setAccessToken('fake.access.token');
    const res = await apiClient.get<{ id: number; email: string; display_name: string }>('/auth/me');
    expect(res.id).toBe(1);
    expect(res.email).toBe('admin@defyn.test');
  });

  it('attaches Authorization: Bearer header when access token is set', async () => {
    setAccessToken('fake.access.token');
    let captured: string | null = null;
    server.use(
      http.get('*/wp-json/defyn/v1/auth/me', ({ request }) => {
        captured = request.headers.get('Authorization');
        return HttpResponse.json({ id: 1, email: 'x@x.test', display_name: 'X' }, { status: 200 });
      }),
    );
    await apiClient.get('/auth/me');
    expect(captured).toBe('Bearer fake.access.token');
  });

  it('omits Authorization header when no access token is set', async () => {
    let captured: string | null = null;
    server.use(
      http.post('*/wp-json/defyn/v1/auth/login', async ({ request }) => {
        captured = request.headers.get('Authorization');
        return HttpResponse.json({ access_token: 'x' }, { status: 200 });
      }),
    );
    await apiClient.post('/auth/login', { email: 'a@b', password: 'p' });
    expect(captured).toBeNull();
  });

  it('throws ApiError with status + envelope on 4xx', async () => {
    server.use(
      http.post('*/wp-json/defyn/v1/auth/login', () =>
        HttpResponse.json({ error: { code: 'auth.invalid_credentials', message: 'Invalid email or password.' } }, { status: 401 }),
      ),
    );
    await expect(apiClient.post('/auth/login', { email: 'a@b', password: 'wrong' })).rejects.toMatchObject({
      status: 401,
      code: 'auth.invalid_credentials',
      message: 'Invalid email or password.',
    });
  });

  it('sends credentials: include on every request', async () => {
    let captured: RequestCredentials | null = null;
    server.use(
      http.get('*/wp-json/defyn/v1/auth/me', ({ request }) => {
        captured = request.credentials;
        return HttpResponse.json({ id: 1, email: 'x@x.test', display_name: 'X' }, { status: 200 });
      }),
    );
    setAccessToken('t');
    await apiClient.get('/auth/me');
    expect(captured).toBe('include');
  });
});
```

- [ ] **Step 2: Run — verify FAIL**

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm test 2>&1 | tail -15
```

Expected: 5 errors — module not found.

- [ ] **Step 3: Write `apps/web/src/lib/apiClient.ts`**

```ts
/**
 * Thin fetch wrapper that:
 *   - prepends the API base URL (`/api` in dev → vite proxy → wp-json; configurable for prod)
 *   - attaches Authorization: Bearer header when an access token is set in memory
 *   - sets credentials: 'include' so the refresh cookie travels
 *   - throws ApiError on non-2xx with the spec envelope's code + message exposed
 *
 * Auto-refresh-on-401 logic is added in Task 6.
 */

const API_BASE = import.meta.env.VITE_API_BASE ?? '/api/defyn/v1';

let accessToken: string | null = null;

export function setAccessToken(token: string | null): void {
  accessToken = token;
}

export function clearAccessToken(): void {
  accessToken = null;
}

export function getAccessToken(): string | null {
  return accessToken;
}

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

interface RequestOptions {
  method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
}

async function request<T>(path: string, opts: RequestOptions): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
  };
  if (opts.body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }
  if (accessToken) {
    headers['Authorization'] = `Bearer ${accessToken}`;
  }

  const response = await fetch(`${API_BASE}${path}`, {
    method: opts.method,
    headers,
    body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
    credentials: 'include',
  });

  if (response.status === 204) {
    return undefined as T;
  }

  const contentType = response.headers.get('Content-Type') ?? '';
  const data = contentType.includes('application/json') ? await response.json() : null;

  if (!response.ok) {
    const code = data?.error?.code ?? 'unknown';
    const message = data?.error?.message ?? `Request failed with status ${response.status}`;
    throw new ApiError(response.status, code, message);
  }

  return data as T;
}

export const apiClient = {
  get: <T>(path: string) => request<T>(path, { method: 'GET' }),
  post: <T>(path: string, body?: unknown) => request<T>(path, { method: 'POST', body }),
};
```

- [ ] **Step 4: Run — verify GREEN**

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm test 2>&1 | tail -10
```

Expected: 7 tests passing (2 smoke + 5 apiClient).

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add apps/web/src/lib/apiClient.ts apps/web/tests/apiClient.test.ts && git commit -m "$(cat <<'EOF'
F3b: TDD apiClient — fetch wrapper with auth header + ApiError envelope

GET/POST helpers prepend API_BASE (defaulting to /api/defyn/v1 so the
Vite proxy can route to local WP). Authorization: Bearer <access>
attached automatically when setAccessToken() is called. credentials:
include on every request so the refresh cookie travels. Non-2xx
responses throw an ApiError carrying status + spec envelope code +
message — consumers pattern-match on .code, not .message.

Auto-refresh-on-401 retry logic is the Task 6 increment on top of this.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: TDD `apiClient` auto-refresh-on-401

**Why:** the SPA needs to silently refresh expired access tokens. This logic is the apiClient's most complex job; isolated tests pin it.

**Files:**
- Modify: `apps/web/src/lib/apiClient.ts`
- Modify: `apps/web/tests/apiClient.test.ts` (add tests)

- [ ] **Step 1: Add the failing tests**

Append these tests to `apps/web/tests/apiClient.test.ts` (inside the existing `describe('apiClient', ...)` block, after the existing tests):

```ts
  it('on 401, attempts refresh and retries the original request once', async () => {
    setAccessToken('expired.access.token');
    let attempt = 0;
    server.use(
      http.get('*/wp-json/defyn/v1/auth/me', ({ request }) => {
        attempt += 1;
        const auth = request.headers.get('Authorization');
        if (attempt === 1) {
          return HttpResponse.json(
            { error: { code: 'auth.invalid_token', message: 'Token is invalid or expired.' } },
            { status: 401 },
          );
        }
        // After refresh, attempt 2 should carry the new token.
        if (auth === 'Bearer fresh.access.token') {
          return HttpResponse.json({ id: 1, email: 'x@x.test', display_name: 'X' }, { status: 200 });
        }
        return HttpResponse.json({ error: { code: 'auth.invalid_token', message: '' } }, { status: 401 });
      }),
      http.post('*/wp-json/defyn/v1/auth/refresh', () =>
        HttpResponse.json({ access_token: 'fresh.access.token' }, { status: 200 }),
      ),
    );
    const res = await apiClient.get<{ id: number }>('/auth/me');
    expect(res.id).toBe(1);
    expect(attempt).toBe(2);
  });

  it('if refresh itself fails with 401, the original error propagates', async () => {
    setAccessToken('expired.access.token');
    server.use(
      http.get('*/wp-json/defyn/v1/auth/me', () =>
        HttpResponse.json({ error: { code: 'auth.invalid_token', message: 'expired' } }, { status: 401 }),
      ),
      http.post('*/wp-json/defyn/v1/auth/refresh', () =>
        HttpResponse.json({ error: { code: 'auth.refresh_revoked', message: 'gone' } }, { status: 401 }),
      ),
    );
    await expect(apiClient.get('/auth/me')).rejects.toMatchObject({ status: 401 });
  });

  it('does not infinite-loop on persistent 401 (refresh once, then give up)', async () => {
    setAccessToken('always.expired');
    let meAttempts = 0;
    server.use(
      http.get('*/wp-json/defyn/v1/auth/me', () => {
        meAttempts += 1;
        return HttpResponse.json({ error: { code: 'auth.invalid_token', message: 'expired' } }, { status: 401 });
      }),
      http.post('*/wp-json/defyn/v1/auth/refresh', () =>
        HttpResponse.json({ access_token: 'still.bad.token' }, { status: 200 }),
      ),
    );
    await expect(apiClient.get('/auth/me')).rejects.toMatchObject({ status: 401 });
    expect(meAttempts).toBeLessThanOrEqual(2);
  });
```

- [ ] **Step 2: Run — verify FAIL**

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm test 2>&1 | tail -20
```

Expected: 3 new failures — first one expects 2 attempts but gets 1, second works coincidentally, third expects ≤2 but the current implementation just throws on first 401.

- [ ] **Step 3: Update `apiClient.ts` to add refresh-and-retry logic**

Replace the `request` function (only that function — keep everything else) with:

```ts
async function request<T>(path: string, opts: RequestOptions, isRetry = false): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
  };
  if (opts.body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }
  if (accessToken) {
    headers['Authorization'] = `Bearer ${accessToken}`;
  }

  const response = await fetch(`${API_BASE}${path}`, {
    method: opts.method,
    headers,
    body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
    credentials: 'include',
  });

  if (response.status === 204) {
    return undefined as T;
  }

  const contentType = response.headers.get('Content-Type') ?? '';
  const data = contentType.includes('application/json') ? await response.json() : null;

  // Auto-refresh-on-401 — only attempt once, and never on /auth/refresh itself.
  if (response.status === 401 && !isRetry && path !== '/auth/refresh') {
    const refreshed = await tryRefresh();
    if (refreshed) {
      return request<T>(path, opts, /*isRetry*/ true);
    }
    // Refresh failed; fall through to throw the original 401.
  }

  if (!response.ok) {
    const code = data?.error?.code ?? 'unknown';
    const message = data?.error?.message ?? `Request failed with status ${response.status}`;
    throw new ApiError(response.status, code, message);
  }

  return data as T;
}

/** Try to refresh the access token. Returns true on success, false on failure. */
async function tryRefresh(): Promise<boolean> {
  try {
    const data = await request<{ access_token: string }>('/auth/refresh', { method: 'POST' });
    setAccessToken(data.access_token);
    return true;
  } catch {
    clearAccessToken();
    return false;
  }
}
```

- [ ] **Step 4: Run — verify GREEN**

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm test 2>&1 | tail -10
```

Expected: 10 tests passing (2 smoke + 8 apiClient).

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add apps/web/src/lib/apiClient.ts apps/web/tests/apiClient.test.ts && git commit -m "$(cat <<'EOF'
F3b: TDD apiClient auto-refresh-on-401

On any 401 (except from /auth/refresh itself), attempt POST /auth/refresh
once. Success: store new access token, retry original request once with
the new token. Failure: propagate the original 401, clear the in-memory
token. Tests cover happy-path retry, persistent-401 abort (≤2 me calls),
and refresh-failure propagation.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: TDD AuthContext + useAuth hook

**Why:** the SPA needs a single source of truth for "am I logged in" that route components can subscribe to. The auth context owns the access token (in-memory) plus a small state machine: `idle | authenticating | authenticated | unauthenticated`.

**Files:**
- Create: `apps/web/src/lib/auth.tsx`
- Create: `apps/web/tests/auth.test.tsx`

- [ ] **Step 1: Write the failing test**

Write `apps/web/tests/auth.test.tsx`:

```tsx
import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, act } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { AuthProvider, useAuth } from '@/lib/auth';
import { clearAccessToken } from '@/lib/apiClient';

function ProbeComponent() {
  const auth = useAuth();
  return (
    <div>
      <div data-testid="state">{auth.status}</div>
      <div data-testid="user">{auth.user?.email ?? 'none'}</div>
      <button onClick={() => auth.login('a@b.test', 'pass').catch(() => {})}>login</button>
      <button onClick={() => auth.logout()}>logout</button>
    </div>
  );
}

function renderWithAuth() {
  return render(
    <AuthProvider>
      <ProbeComponent />
    </AuthProvider>,
  );
}

describe('AuthContext', () => {
  beforeEach(() => {
    clearAccessToken();
  });

  it('starts in unauthenticated state', () => {
    renderWithAuth();
    expect(screen.getByTestId('state').textContent).toBe('unauthenticated');
  });

  it('successful login transitions to authenticated and loads user', async () => {
    renderWithAuth();
    await act(async () => {
      await userEvent.click(screen.getByText('login'));
    });
    expect(screen.getByTestId('state').textContent).toBe('authenticated');
    expect(screen.getByTestId('user').textContent).toBe('admin@defyn.test');
  });

  it('logout clears state and returns to unauthenticated', async () => {
    renderWithAuth();
    await act(async () => {
      await userEvent.click(screen.getByText('login'));
    });
    expect(screen.getByTestId('state').textContent).toBe('authenticated');
    await act(async () => {
      await userEvent.click(screen.getByText('logout'));
    });
    expect(screen.getByTestId('state').textContent).toBe('unauthenticated');
    expect(screen.getByTestId('user').textContent).toBe('none');
  });
});
```

- [ ] **Step 2: Run — verify FAIL**

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm test 2>&1 | tail -10
```

Expected: 3 errors — module not found.

- [ ] **Step 3: Write `apps/web/src/lib/auth.tsx`**

```tsx
import * as React from 'react';
import { apiClient, setAccessToken, clearAccessToken } from './apiClient';

interface User {
  id: number;
  email: string;
  display_name: string;
}

type AuthStatus = 'unauthenticated' | 'authenticating' | 'authenticated';

interface AuthState {
  status: AuthStatus;
  user: User | null;
}

interface AuthContextValue extends AuthState {
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
}

const AuthContext = React.createContext<AuthContextValue | null>(null);

interface LoginResponse {
  access_token: string;
}

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [state, setState] = React.useState<AuthState>({ status: 'unauthenticated', user: null });

  const login = React.useCallback(async (email: string, password: string) => {
    setState((s) => ({ ...s, status: 'authenticating' }));
    try {
      const { access_token } = await apiClient.post<LoginResponse>('/auth/login', { email, password });
      setAccessToken(access_token);
      const user = await apiClient.get<User>('/auth/me');
      setState({ status: 'authenticated', user });
    } catch (e) {
      clearAccessToken();
      setState({ status: 'unauthenticated', user: null });
      throw e;
    }
  }, []);

  const logout = React.useCallback(async () => {
    try {
      await apiClient.post('/auth/logout');
    } catch {
      // logout is idempotent on the backend; ignore network/API errors here.
    }
    clearAccessToken();
    setState({ status: 'unauthenticated', user: null });
  }, []);

  const value = React.useMemo<AuthContextValue>(
    () => ({ ...state, login, logout }),
    [state, login, logout],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = React.useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be called inside <AuthProvider>');
  return ctx;
}
```

- [ ] **Step 4: Run — verify GREEN**

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm test 2>&1 | tail -10
```

Expected: 13 tests passing (2 smoke + 8 apiClient + 3 auth).

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add apps/web/src/lib/auth.tsx apps/web/tests/auth.test.tsx && git commit -m "$(cat <<'EOF'
F3b: TDD AuthContext + useAuth — login/logout state machine

State machine: unauthenticated → authenticating → authenticated
(with user) or back to unauthenticated on failure. login() calls
POST /auth/login then GET /auth/me to populate the user, both via
apiClient. logout() best-effort POSTs /auth/logout (idempotent
backend) then clears state. Errors propagate to caller (Task 8's
login form maps them to inline UI).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: TDD Login route + form

**Why:** the user-visible login screen. Validates client-side via Zod, calls `useAuth().login`, maps backend error codes (auth.invalid_credentials, auth.rate_limited) to inline UI.

**Files:**
- Create: `apps/web/src/routes/Login.tsx`
- Create: `apps/web/tests/Login.test.tsx`

- [ ] **Step 1: Write the failing test**

Write `apps/web/tests/Login.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { AuthProvider } from '@/lib/auth';
import Login from '@/routes/Login';
import { server } from '@/test/setup';
import { http, HttpResponse } from 'msw';

function renderLogin() {
  return render(
    <MemoryRouter>
      <AuthProvider>
        <Login />
      </AuthProvider>
    </MemoryRouter>,
  );
}

describe('Login route', () => {
  it('renders email + password fields and a submit button', () => {
    renderLogin();
    expect(screen.getByLabelText(/email/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/password/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /sign in/i })).toBeInTheDocument();
  });

  it('shows client-side validation when email is invalid', async () => {
    renderLogin();
    await userEvent.type(screen.getByLabelText(/email/i), 'not-an-email');
    await userEvent.type(screen.getByLabelText(/password/i), 'password123');
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }));
    expect(await screen.findByText(/valid email/i)).toBeInTheDocument();
  });

  it('shows the backend error message on auth.invalid_credentials', async () => {
    server.use(
      http.post('*/wp-json/defyn/v1/auth/login', () =>
        HttpResponse.json(
          { error: { code: 'auth.invalid_credentials', message: 'Invalid email or password.' } },
          { status: 401 },
        ),
      ),
    );
    renderLogin();
    await userEvent.type(screen.getByLabelText(/email/i), 'admin@defyn.test');
    await userEvent.type(screen.getByLabelText(/password/i), 'wrong');
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }));
    expect(await screen.findByText(/invalid email or password/i)).toBeInTheDocument();
  });

  it('shows a rate-limit banner on auth.rate_limited', async () => {
    server.use(
      http.post('*/wp-json/defyn/v1/auth/login', () =>
        HttpResponse.json(
          { error: { code: 'auth.rate_limited', message: 'Too many login attempts. Try again in a minute.' } },
          { status: 429 },
        ),
      ),
    );
    renderLogin();
    await userEvent.type(screen.getByLabelText(/email/i), 'admin@defyn.test');
    await userEvent.type(screen.getByLabelText(/password/i), 'p');
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }));
    expect(await screen.findByText(/too many login attempts/i)).toBeInTheDocument();
  });

  it('disables submit while authenticating', async () => {
    let resolveLogin: ((v: unknown) => void) | null = null;
    server.use(
      http.post('*/wp-json/defyn/v1/auth/login', () =>
        new Promise((resolve) => {
          resolveLogin = (v) => resolve(v as Response);
        }),
      ),
    );
    renderLogin();
    await userEvent.type(screen.getByLabelText(/email/i), 'admin@defyn.test');
    await userEvent.type(screen.getByLabelText(/password/i), 'pass');
    const submit = screen.getByRole('button', { name: /sign in/i });
    await userEvent.click(submit);
    await waitFor(() => expect(submit).toBeDisabled());
    resolveLogin!(HttpResponse.json({ access_token: 'x' }, { status: 200 }));
  });
});
```

- [ ] **Step 2: Install React Router**

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm add react-router-dom@^6
```

- [ ] **Step 3: Run — verify FAIL**

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm test 2>&1 | tail -10
```

Expected: 5 errors — module not found / Login not exported.

- [ ] **Step 4: Write `apps/web/src/routes/Login.tsx`**

```tsx
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useNavigate } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useAuth } from '@/lib/auth';
import { ApiError } from '@/lib/apiClient';

const loginSchema = z.object({
  email: z.string().email('Please enter a valid email.'),
  password: z.string().min(1, 'Password is required.'),
});

type LoginInput = z.infer<typeof loginSchema>;

export default function Login() {
  const [serverError, setServerError] = useState<string | null>(null);
  const auth = useAuth();
  const navigate = useNavigate();
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<LoginInput>({ resolver: zodResolver(loginSchema) });

  async function onSubmit(values: LoginInput) {
    setServerError(null);
    try {
      await auth.login(values.email, values.password);
      navigate('/');
    } catch (e) {
      if (e instanceof ApiError) {
        setServerError(e.message);
      } else {
        setServerError('Something went wrong. Please try again.');
      }
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center p-6 bg-zinc-50">
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle>Sign in to DefynWP</CardTitle>
        </CardHeader>
        <CardContent>
          {serverError && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>{serverError}</AlertDescription>
            </Alert>
          )}
          <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
            <div className="space-y-2">
              <Label htmlFor="email">Email</Label>
              <Input id="email" type="email" autoComplete="email" {...register('email')} />
              {errors.email && <p className="text-sm text-red-600">{errors.email.message}</p>}
            </div>
            <div className="space-y-2">
              <Label htmlFor="password">Password</Label>
              <Input id="password" type="password" autoComplete="current-password" {...register('password')} />
              {errors.password && <p className="text-sm text-red-600">{errors.password.message}</p>}
            </div>
            <Button type="submit" className="w-full" disabled={isSubmitting}>
              {isSubmitting ? 'Signing in...' : 'Sign in'}
            </Button>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}
```

- [ ] **Step 5: Run — verify GREEN**

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm test 2>&1 | tail -10
```

Expected: 18 tests passing (13 prior + 5 login).

- [ ] **Step 6: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add apps/web/package.json apps/web/pnpm-lock.yaml apps/web/src/routes/Login.tsx apps/web/tests/Login.test.tsx && git commit -m "$(cat <<'EOF'
F3b: TDD Login route + form

shadcn/ui-styled login card with email + password fields. Client-side
validation via Zod (valid email, non-empty password). Server errors
mapped to an inline Alert via ApiError.message — relies on F3a's spec
envelope being consistent. auth.invalid_credentials and auth.rate_limited
both surface their backend message verbatim. Submit button disables
during authenticating state. On success, navigates to /.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: Home route + protected route gate + App routing

**Why:** the authenticated landing page that proves end-to-end auth flow works. Plus the route gate that redirects unauthenticated users to /login.

**Files:**
- Create: `apps/web/src/routes/Home.tsx`
- Create: `apps/web/src/routes/RequireAuth.tsx`
- Modify: `apps/web/src/App.tsx`
- Modify: `apps/web/src/main.tsx` (wrap in QueryClientProvider + AuthProvider + BrowserRouter)
- Create: `apps/web/src/lib/queryClient.ts`
- Create: `apps/web/tests/Home.test.tsx`

- [ ] **Step 1: Install TanStack Query**

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm add @tanstack/react-query
```

- [ ] **Step 2: Write the failing test**

Write `apps/web/tests/Home.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen, act } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthProvider, useAuth } from '@/lib/auth';
import Home from '@/routes/Home';
import RequireAuth from '@/routes/RequireAuth';

function makeApp(initialPath: string) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <MemoryRouter initialEntries={[initialPath]}>
      <QueryClientProvider client={queryClient}>
        <AuthProvider>
          <Routes>
            <Route path="/login" element={<div>LOGIN PAGE</div>} />
            <Route element={<RequireAuth />}>
              <Route path="/" element={<Home />} />
            </Route>
          </Routes>
        </AuthProvider>
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

function LoginAndGoHome() {
  const auth = useAuth();
  return <button onClick={() => auth.login('a@b.test', 'p')}>auth-login</button>;
}

describe('Home route + RequireAuth', () => {
  it('redirects to /login when unauthenticated', () => {
    makeApp('/');
    expect(screen.getByText('LOGIN PAGE')).toBeInTheDocument();
  });

  it('renders Home with welcome message when authenticated', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <MemoryRouter initialEntries={['/']}>
        <QueryClientProvider client={queryClient}>
          <AuthProvider>
            <LoginAndGoHome />
            <Routes>
              <Route path="/login" element={<div>LOGIN PAGE</div>} />
              <Route element={<RequireAuth />}>
                <Route path="/" element={<Home />} />
              </Route>
            </Routes>
          </AuthProvider>
        </QueryClientProvider>
      </MemoryRouter>,
    );

    await act(async () => {
      await userEvent.click(screen.getByText('auth-login'));
    });

    expect(await screen.findByText(/welcome.*admin user/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 3: Run — verify FAIL**

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm test 2>&1 | tail -10
```

Expected: 2 errors — module not found.

- [ ] **Step 4: Write `apps/web/src/lib/queryClient.ts`**

```ts
import { QueryClient } from '@tanstack/react-query';

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      refetchOnWindowFocus: false,
      retry: 1,
    },
  },
});
```

- [ ] **Step 5: Write `apps/web/src/routes/RequireAuth.tsx`**

```tsx
import { Navigate, Outlet } from 'react-router-dom';
import { useAuth } from '@/lib/auth';

export default function RequireAuth() {
  const { status } = useAuth();
  if (status === 'authenticated') return <Outlet />;
  return <Navigate to="/login" replace />;
}
```

- [ ] **Step 6: Write `apps/web/src/routes/Home.tsx`**

```tsx
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useAuth } from '@/lib/auth';

export default function Home() {
  const { user, logout } = useAuth();

  return (
    <div className="min-h-screen p-8">
      <Card className="max-w-2xl mx-auto">
        <CardHeader>
          <CardTitle>Welcome, {user?.display_name ?? 'there'}</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <p className="text-sm text-zinc-600">Signed in as {user?.email}</p>
          <Button variant="outline" onClick={() => logout()}>
            Sign out
          </Button>
        </CardContent>
      </Card>
    </div>
  );
}
```

- [ ] **Step 7: Update `apps/web/src/App.tsx`**

```tsx
import { Routes, Route } from 'react-router-dom';
import Login from './routes/Login';
import Home from './routes/Home';
import RequireAuth from './routes/RequireAuth';

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route element={<RequireAuth />}>
        <Route path="/" element={<Home />} />
      </Route>
    </Routes>
  );
}
```

- [ ] **Step 8: Update `apps/web/src/main.tsx`**

```tsx
import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { QueryClientProvider } from '@tanstack/react-query';
import App from './App';
import { AuthProvider } from './lib/auth';
import { queryClient } from './lib/queryClient';
import './index.css';

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <BrowserRouter>
      <QueryClientProvider client={queryClient}>
        <AuthProvider>
          <App />
        </AuthProvider>
      </QueryClientProvider>
    </BrowserRouter>
  </React.StrictMode>,
);
```

- [ ] **Step 9: Run — verify GREEN**

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm test 2>&1 | tail -10
```

Expected: 20 tests passing (18 prior + 2 home).

- [ ] **Step 10: Verify `pnpm build` still succeeds**

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm build 2>&1 | tail -8
```

Expected: clean build, no TS errors, dist emitted.

- [ ] **Step 11: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add apps/web/package.json apps/web/pnpm-lock.yaml apps/web/src/lib/queryClient.ts apps/web/src/routes/Home.tsx apps/web/src/routes/RequireAuth.tsx apps/web/src/App.tsx apps/web/src/main.tsx apps/web/tests/Home.test.tsx && git commit -m "$(cat <<'EOF'
F3b: Home route + RequireAuth gate + App routing wired end-to-end

RequireAuth checks AuthContext status — Outlet when authenticated,
<Navigate to=/login> otherwise. Home renders the welcome card with
display_name + sign-out button. App.tsx route tree: /login (public)
+ / (protected by RequireAuth wrapper). main.tsx wraps everything
in BrowserRouter + QueryClientProvider + AuthProvider.

E2E tests cover: unauthenticated visit to / redirects to /login;
post-login navigation surfaces the welcome message with the user's
display_name from /auth/me.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: README + .env.example

**Why:** the SPA's dev setup has a few rough edges (DEFYN_JWT_SECRET, Local SSL, proxy target) — document them once.

**Files:**
- Create: `apps/web/README.md`
- Create: `apps/web/.env.example`

- [ ] **Step 1: Write `apps/web/.env.example`**

```bash
# Vite dev-time configuration. Copy to .env.local to override.

# Where the WordPress backend lives. Vite's dev server proxies /api/* here.
# Default targets the Local-by-Flywheel site at https://defynwp.local.
VITE_WP_URL=https://defynwp.local

# (Optional) Override the API base path the apiClient prepends.
# Default: /api/defyn/v1 (which the Vite proxy rewrites to /wp-json/defyn/v1).
# In production you'd typically set this to https://defyn.com/wp-json/defyn/v1.
# VITE_API_BASE=
```

- [ ] **Step 2: Write `apps/web/README.md`**

```markdown
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
```

- [ ] **Step 3: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add apps/web/README.md apps/web/.env.example && git commit -m "$(cat <<'EOF'
F3b: apps/web/ README + .env.example

Documents the Vite proxy mechanism, prerequisites (DEFYN_JWT_SECRET
must be set on the WP install), pnpm scripts, and project layout.
.env.example covers the two override points (VITE_WP_URL,
VITE_API_BASE).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 11: GitHub Actions — add `web` job alongside `dashboard-plugin`

**Why:** every commit/PR should run both test suites in parallel.

**Files:**
- Modify: `.github/workflows/test.yml`

- [ ] **Step 1: Read the current workflow**

```bash
cat "/Users/pradeep/Local Sites/defynWP/.github/workflows/test.yml"
```

- [ ] **Step 2: Add a `web` job after the existing `dashboard-plugin` job**

Edit `.github/workflows/test.yml`. After the existing `dashboard-plugin` job (which ends after the `Run PHPUnit` step), add:

```yaml

  web:
    name: web (Node ${{ matrix.node }})
    runs-on: ubuntu-latest
    timeout-minutes: 10
    strategy:
      fail-fast: false
      matrix:
        node: ['20', '22']

    defaults:
      run:
        working-directory: apps/web

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Set up pnpm
        uses: pnpm/action-setup@v4
        with:
          version: 9

      - name: Set up Node ${{ matrix.node }}
        uses: actions/setup-node@v4
        with:
          node-version: ${{ matrix.node }}
          cache: 'pnpm'
          cache-dependency-path: apps/web/pnpm-lock.yaml

      - name: Install dependencies
        run: pnpm install --frozen-lockfile

      - name: Type-check
        run: pnpm lint

      - name: Test
        run: pnpm test

      - name: Build
        run: pnpm build
```

- [ ] **Step 3: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add .github/workflows/test.yml && git commit -m "$(cat <<'EOF'
F3b: CI — add `web` job with Node 20 + 22 matrix

Runs alongside the existing dashboard-plugin job. Steps: pnpm install
--frozen-lockfile (fails on lockfile drift), tsc --noEmit, vitest run,
vite build. 10-minute timeout. Matrix bookends Node 20 (current LTS
floor) and 22 (current LTS).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 12: F3b acceptance — full suite + manual smoke test + tag

**Why:** prove every F3b addition works, prove F1/F2/F3a didn't regress, then tag.

- [ ] **Step 1: Run the full SPA test suite**

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm test 2>&1 | tail -10
```

Expected: 20 tests passing.

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm build 2>&1 | tail -10
```

Expected: clean production build.

- [ ] **Step 2: Verify F1/F2/F3a backend tests still pass**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit 2>&1 | tail -3
```

Expected: still `OK (89 tests, 174 assertions)`.

- [ ] **Step 3: Manual smoke test (USER step — not subagent-doable)**

Set `DEFYN_JWT_SECRET` in the local WP install. Run `pnpm dev` in `apps/web/`. Visit `localhost:5173`. Should redirect to `/login`. Sign in with the WP admin user (the one created when the Local site was provisioned). Should land on `/` with "Welcome, <name>". Click sign out. Should redirect back to `/login`.

Setup snippet for the `DEFYN_JWT_SECRET` (run once, then never again):

```bash
# Generate a random 32+ byte secret
openssl rand -hex 32
# Then add to wp-config.php BEFORE the "/* That's all, stop editing!" line:
# define('DEFYN_JWT_SECRET', 'YOUR-GENERATED-VALUE-HERE');
```

- [ ] **Step 4: Tag F3b complete**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git tag -a f3b-spa-complete -m "F3b: SPA scaffold + login page complete — Vite + React 18 + TS, Tailwind 4, shadcn/ui primitives, React Router v6, TanStack Query v5, React Hook Form + Zod, Vitest + MSW v2 harness, apiClient with auto-refresh-on-401, AuthContext, Login + Home routes, CI web job. ~20 frontend tests."
```

- [ ] **Step 5: Verify**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git tag --list "f*" && git log --oneline f3b-spa-complete | head -15
```

Expected: f1, f2, f3a, f3b tags listed; f3b-spa-complete points at the latest commit.

---

## F3b Verification Checklist (Definition of Done)

- [ ] `pnpm dev` boots the SPA at localhost:5173
- [ ] Visiting `/` unauthenticated redirects to `/login`
- [ ] Login form validates client-side and shows backend errors inline
- [ ] Successful login navigates to `/` and shows welcome message with `display_name`
- [ ] `apiClient` auto-refreshes on 401 (covered by tests; verified by manual smoke test optionally)
- [ ] Sign-out clears state and returns to `/login`
- [ ] All 20 Vitest tests pass
- [ ] All 89 backend tests still pass
- [ ] `pnpm build` produces a clean production bundle
- [ ] CI green: web job runs alongside dashboard-plugin on PR + push
- [ ] Tag `f3b-spa-complete` exists

---

## Notes for F4 (forward-looking)

- F4 introduces the connector handshake (`POST /sites` + Action Scheduler job hitting the connector's `/connect` endpoint). The SPA in F8 will get an "Add Site" form that calls `POST /sites`. The auth pieces are now in place — F8 just needs to use the established apiClient + AuthProvider.
- The error envelope contract (`{error: {code, message}}`) and prefix convention (`auth.*`, `sites.*` etc.) is now solid. F4's REST controllers should follow the same convention so the SPA's error mapping in `Login.tsx` generalizes.
- TanStack Query is already wired with sensible defaults (30s staleTime, no window-focus refetch, 1 retry). F8's sites list will be a clean `useQuery({queryKey: ['sites']})` call.
