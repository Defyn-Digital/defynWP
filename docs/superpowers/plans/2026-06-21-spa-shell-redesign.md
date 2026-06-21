# SPA Redesign — Slice 1: App Shell + Design System — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wrap every authenticated SPA page in one persistent navy left-sidebar + topbar shell and expand the design tokens to an on-brand "Branded Navy" palette, so the whole app instantly reads as one cohesive product and finally has real navigation (including the missing **Sites** link).

**Architecture:** A single `AppShell` layout component renders a fixed navy `Sidebar` (logo + grouped `NavItem`s + `AccountMenu`) and a `Topbar` (mobile hamburger + search placeholder), with the routed page in a scrollable `<main>`. `AppShell` is wired as a React-Router layout route inside `RequireAuth`, so all existing routes render unchanged inside it. The shell pieces are presentational (props in); `AppShell` is the only `useAuth()` container. Tokens live in `src/index.css` (`H S% L%` triples) + `tailwind.config.ts`. New shadcn/ui primitives are hand-authored in the existing style. The five fragmented `*NavLink` header components are deleted (the sidebar owns nav).

**Tech Stack:** React 18 + TypeScript, react-router-dom, TanStack Query, Tailwind CSS v4 (`@import "tailwindcss"` + `tailwind.config.ts`), shadcn/ui (hand-authored CVA + `cn()` from `@/lib/cn`), Radix UI primitives, lucide-react (icons, already a dep), Vitest + Testing Library + MSW, pnpm, Node 22 via fnm.

**Scope:** `apps/web` ONLY. No backend / connector / schema / version change. Ships via Cloudflare Pages auto-deploy from `main` (merge the `spa-shell-redesign` branch). Branch `spa-shell-redesign` is already checked out (spec committed at `e57bdee`).

**Spec:** `docs/superpowers/specs/2026-06-21-spa-shell-redesign-design.md`

**Test runner notes:**
- Run SPA tests with Node 22: `export FNM_DIR="$HOME/.fnm"; eval "$(fnm env --shell bash)"; fnm use 22; pnpm test -- --run`.
- `pnpm build` runs `tsc` — a vitest-green change can still fail the typecheck. Build must be clean before shipping.
- Existing component/route tests render the component directly inside `QueryClientProvider` + `MemoryRouter`, using MSW `server` from `@/test/setup`. Mirror that harness — do NOT introduce a global `AuthProvider` in tests.
- Carry-forward failures (pre-existing, leave as-is): `tests/SiteDetail.test.tsx` ×2 + `tests/components/sites/SiteCoreCard.test.tsx` ×2. Everything else must stay green.
- Existing `ui/` primitive files are lowercase (`button.tsx`, `tooltip.tsx`, …). New primitives follow that. Radix wrapper pattern: see `src/components/ui/tooltip.tsx` (Portal + `forwardRef` + `cn`). CVA pattern: see `src/components/ui/button.tsx`.

---

## File Structure

**Create:**
- `src/components/ui/separator.tsx`, `skeleton.tsx`, `scroll-area.tsx`, `avatar.tsx`, `dropdown-menu.tsx`, `dialog.tsx`, `sheet.tsx`, `tabs.tsx` — new shadcn primitives.
- `src/components/layout/NavItem.tsx`, `SidebarNav.tsx`, `Sidebar.tsx`, `AccountMenu.tsx`, `Topbar.tsx`, `PageHeader.tsx`, `AppShell.tsx` — the shell.
- `src/components/layout/navItems.ts` — the nav data (single source of truth for sidebar items).
- Tests under `apps/web/tests/` mirroring paths (e.g. `tests/components/layout/AppShell.test.tsx`, `tests/components/ui/dropdown-menu.test.tsx`).

**Modify:**
- `src/index.css` — expand `:root` tokens.
- `tailwind.config.ts` — expand `theme.extend.colors`.
- `src/lib/auth.tsx` — add one additive export (`AuthContext`) so `AppShell`'s test can supply a fake authenticated value.
- `src/App.tsx` — wrap the authed routes in the `AppShell` layout route.
- `src/routes/Overview.tsx` — remove the 5 `*NavLink`s; adopt `PageHeader`; move header action buttons into its actions slot.

**Delete:**
- `src/components/nav/{Jobs,Monitoring,Security,Insights,Settings}NavLink.tsx`
- `tests/components/nav/{Jobs,Monitoring,Settings}NavLink.test.tsx`

---

## Task 1: Design tokens (Branded Navy)

**Files:**
- Modify: `src/index.css`
- Modify: `tailwind.config.ts`
- Test: `tests/tailwindConfig.test.ts` (new)

- [ ] **Step 1: Write the failing test**

Create `tests/tailwindConfig.test.ts`:
```ts
import { describe, it, expect } from 'vitest';
import config from '../tailwind.config';

describe('tailwind theme tokens (Branded Navy)', () => {
  const colors = (config.theme?.extend?.colors ?? {}) as Record<string, unknown>;

  it('exposes the expanded shadcn token set', () => {
    for (const key of ['card', 'muted', 'secondary', 'accent', 'destructive', 'popover', 'input', 'success', 'warning', 'info']) {
      expect(colors, `missing color token: ${key}`).toHaveProperty(key);
    }
  });

  it('exposes the sidebar token scope', () => {
    expect(colors).toHaveProperty('sidebar');
    const sidebar = colors.sidebar as Record<string, unknown>;
    expect(sidebar).toHaveProperty('DEFAULT');
    expect(sidebar).toHaveProperty('accent');
    expect(sidebar).toHaveProperty('foreground');
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pnpm test -- --run tests/tailwindConfig.test.ts`
Expected: FAIL (`missing color token: card`).

- [ ] **Step 3: Expand `src/index.css`**

Replace the `:root { … }` block (and the now-unused `.dark { … }` block — light only, no toggle exists) with:
```css
:root {
  /* content surface */
  --background: 210 40% 98%;
  --foreground: 222 47% 11%;
  --card: 0 0% 100%;
  --card-foreground: 222 47% 11%;
  --popover: 0 0% 100%;
  --popover-foreground: 222 47% 11%;
  --muted: 210 40% 96%;
  --muted-foreground: 215 16% 47%;
  --border: 214 32% 91%;
  --input: 214 32% 91%;
  --ring: 239 84% 67%;

  /* brand / accent */
  --primary: 239 84% 67%;
  --primary-foreground: 0 0% 100%;
  --secondary: 226 100% 97%;
  --secondary-foreground: 244 54% 41%;
  --accent: 226 100% 97%;
  --accent-foreground: 244 54% 41%;

  /* sidebar (navy rail) */
  --sidebar: 245 47% 25%;
  --sidebar-foreground: 245 44% 84%;
  --sidebar-accent: 239 84% 67%;
  --sidebar-accent-foreground: 0 0% 100%;
  --sidebar-border: 246 39% 30%;
  --sidebar-muted: 246 33% 69%;

  /* semantic status */
  --success: 142 71% 45%;
  --success-foreground: 0 0% 100%;
  --warning: 32 95% 44%;
  --warning-foreground: 0 0% 100%;
  --destructive: 0 72% 51%;
  --destructive-foreground: 0 0% 100%;
  --info: 221 83% 53%;
  --info-foreground: 0 0% 100%;

  --radius: 0.5rem;
}
```
(Leave the `@import "tailwindcss";` line and the `body { … }` block untouched.)

- [ ] **Step 4: Expand `tailwind.config.ts`**

Replace the `colors: { … }` object inside `theme.extend` with:
```ts
colors: {
  background: 'hsl(var(--background))',
  foreground: 'hsl(var(--foreground))',
  card: { DEFAULT: 'hsl(var(--card))', foreground: 'hsl(var(--card-foreground))' },
  popover: { DEFAULT: 'hsl(var(--popover))', foreground: 'hsl(var(--popover-foreground))' },
  primary: { DEFAULT: 'hsl(var(--primary))', foreground: 'hsl(var(--primary-foreground))' },
  secondary: { DEFAULT: 'hsl(var(--secondary))', foreground: 'hsl(var(--secondary-foreground))' },
  muted: { DEFAULT: 'hsl(var(--muted))', foreground: 'hsl(var(--muted-foreground))' },
  accent: { DEFAULT: 'hsl(var(--accent))', foreground: 'hsl(var(--accent-foreground))' },
  destructive: { DEFAULT: 'hsl(var(--destructive))', foreground: 'hsl(var(--destructive-foreground))' },
  success: { DEFAULT: 'hsl(var(--success))', foreground: 'hsl(var(--success-foreground))' },
  warning: { DEFAULT: 'hsl(var(--warning))', foreground: 'hsl(var(--warning-foreground))' },
  info: { DEFAULT: 'hsl(var(--info))', foreground: 'hsl(var(--info-foreground))' },
  border: 'hsl(var(--border))',
  input: 'hsl(var(--input))',
  ring: 'hsl(var(--ring))',
  sidebar: {
    DEFAULT: 'hsl(var(--sidebar))',
    foreground: 'hsl(var(--sidebar-foreground))',
    accent: 'hsl(var(--sidebar-accent))',
    'accent-foreground': 'hsl(var(--sidebar-accent-foreground))',
    border: 'hsl(var(--sidebar-border))',
    muted: 'hsl(var(--sidebar-muted))',
  },
},
```
(This also fixes a pre-existing gap: `Overview.tsx` already uses `text-muted-foreground`, which had no token until now.)

- [ ] **Step 5: Run test + build to verify**

Run: `pnpm test -- --run tests/tailwindConfig.test.ts` → PASS.
Run: `pnpm build` → tsc clean.

- [ ] **Step 6: Commit**
```bash
git add src/index.css tailwind.config.ts tests/tailwindConfig.test.ts
git commit -m "feat(redesign): expand design tokens to Branded Navy palette"
```

---

## Task 2: Static primitives — separator, skeleton, scroll-area, avatar

**Files:**
- Create: `src/components/ui/separator.tsx`, `skeleton.tsx`, `scroll-area.tsx`, `avatar.tsx`
- Test: `tests/components/ui/primitives-static.test.tsx`
- Adds deps: `@radix-ui/react-separator`, `@radix-ui/react-scroll-area`, `@radix-ui/react-avatar`

- [ ] **Step 1: Install the Radix deps**

Run: `pnpm add @radix-ui/react-separator @radix-ui/react-scroll-area @radix-ui/react-avatar`

- [ ] **Step 2: Write the failing test**

Create `tests/components/ui/primitives-static.test.tsx`:
```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';

describe('static ui primitives', () => {
  it('Separator renders a separator role', () => {
    render(<Separator />);
    expect(screen.getByRole('separator')).toBeInTheDocument();
  });

  it('Skeleton renders an element with the pulse class', () => {
    render(<Skeleton data-testid="sk" className="h-4 w-10" />);
    expect(screen.getByTestId('sk').className).toContain('animate-pulse');
  });

  it('Avatar renders its fallback', () => {
    render(<Avatar><AvatarFallback>PD</AvatarFallback></Avatar>);
    expect(screen.getByText('PD')).toBeInTheDocument();
  });
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `pnpm test -- --run tests/components/ui/primitives-static.test.tsx`
Expected: FAIL (cannot resolve `@/components/ui/separator`).

- [ ] **Step 4: Create the primitives**

`src/components/ui/separator.tsx`:
```tsx
import * as React from 'react';
import * as SeparatorPrimitive from '@radix-ui/react-separator';
import { cn } from '@/lib/cn';

export const Separator = React.forwardRef<
  React.ElementRef<typeof SeparatorPrimitive.Root>,
  React.ComponentPropsWithoutRef<typeof SeparatorPrimitive.Root>
>(({ className, orientation = 'horizontal', decorative = true, ...props }, ref) => (
  <SeparatorPrimitive.Root
    ref={ref}
    decorative={decorative}
    orientation={orientation}
    className={cn(
      'shrink-0 bg-border',
      orientation === 'horizontal' ? 'h-px w-full' : 'h-full w-px',
      className,
    )}
    {...props}
  />
));
Separator.displayName = 'Separator';
```

`src/components/ui/skeleton.tsx`:
```tsx
import * as React from 'react';
import { cn } from '@/lib/cn';

export function Skeleton({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('animate-pulse rounded-md bg-muted', className)} {...props} />;
}
```

`src/components/ui/scroll-area.tsx`:
```tsx
import * as React from 'react';
import * as ScrollAreaPrimitive from '@radix-ui/react-scroll-area';
import { cn } from '@/lib/cn';

export const ScrollArea = React.forwardRef<
  React.ElementRef<typeof ScrollAreaPrimitive.Root>,
  React.ComponentPropsWithoutRef<typeof ScrollAreaPrimitive.Root>
>(({ className, children, ...props }, ref) => (
  <ScrollAreaPrimitive.Root ref={ref} className={cn('relative overflow-hidden', className)} {...props}>
    <ScrollAreaPrimitive.Viewport className="h-full w-full rounded-[inherit]">
      {children}
    </ScrollAreaPrimitive.Viewport>
    <ScrollAreaPrimitive.Scrollbar
      orientation="vertical"
      className="flex touch-none select-none p-0.5 transition-colors"
    >
      <ScrollAreaPrimitive.Thumb className="relative flex-1 rounded-full bg-border" />
    </ScrollAreaPrimitive.Scrollbar>
    <ScrollAreaPrimitive.Corner />
  </ScrollAreaPrimitive.Root>
));
ScrollArea.displayName = 'ScrollArea';
```

`src/components/ui/avatar.tsx`:
```tsx
import * as React from 'react';
import * as AvatarPrimitive from '@radix-ui/react-avatar';
import { cn } from '@/lib/cn';

export const Avatar = React.forwardRef<
  React.ElementRef<typeof AvatarPrimitive.Root>,
  React.ComponentPropsWithoutRef<typeof AvatarPrimitive.Root>
>(({ className, ...props }, ref) => (
  <AvatarPrimitive.Root
    ref={ref}
    className={cn('relative flex h-8 w-8 shrink-0 overflow-hidden rounded-full', className)}
    {...props}
  />
));
Avatar.displayName = 'Avatar';

export const AvatarImage = React.forwardRef<
  React.ElementRef<typeof AvatarPrimitive.Image>,
  React.ComponentPropsWithoutRef<typeof AvatarPrimitive.Image>
>(({ className, ...props }, ref) => (
  <AvatarPrimitive.Image ref={ref} className={cn('aspect-square h-full w-full', className)} {...props} />
));
AvatarImage.displayName = 'AvatarImage';

export const AvatarFallback = React.forwardRef<
  React.ElementRef<typeof AvatarPrimitive.Fallback>,
  React.ComponentPropsWithoutRef<typeof AvatarPrimitive.Fallback>
>(({ className, ...props }, ref) => (
  <AvatarPrimitive.Fallback
    ref={ref}
    className={cn('flex h-full w-full items-center justify-center rounded-full bg-muted text-xs font-medium', className)}
    {...props}
  />
));
AvatarFallback.displayName = 'AvatarFallback';
```

- [ ] **Step 5: Run test to verify it passes**

Run: `pnpm test -- --run tests/components/ui/primitives-static.test.tsx` → PASS.

- [ ] **Step 6: Commit**
```bash
git add package.json pnpm-lock.yaml src/components/ui/separator.tsx src/components/ui/skeleton.tsx src/components/ui/scroll-area.tsx src/components/ui/avatar.tsx tests/components/ui/primitives-static.test.tsx
git commit -m "feat(redesign): add separator, skeleton, scroll-area, avatar primitives"
```

---

## Task 3: Interactive primitives — dropdown-menu, dialog, sheet, tabs

**Files:**
- Create: `src/components/ui/dropdown-menu.tsx`, `dialog.tsx`, `sheet.tsx`, `tabs.tsx`
- Test: `tests/components/ui/dropdown-menu.test.tsx`, `tests/components/ui/sheet.test.tsx`
- Adds deps: `@radix-ui/react-dropdown-menu`, `@radix-ui/react-dialog`, `@radix-ui/react-tabs`

- [ ] **Step 1: Install the Radix deps**

Run: `pnpm add @radix-ui/react-dropdown-menu @radix-ui/react-dialog @radix-ui/react-tabs`
(Also confirm `@testing-library/user-event` is a dev dep — `grep user-event package.json`; if absent, `pnpm add -D @testing-library/user-event`.)

- [ ] **Step 2: Write the failing tests**

Create `tests/components/ui/dropdown-menu.test.tsx`:
```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {
  DropdownMenu, DropdownMenuTrigger, DropdownMenuContent, DropdownMenuItem,
} from '@/components/ui/dropdown-menu';

describe('DropdownMenu', () => {
  it('opens on trigger click and fires the item handler', async () => {
    const user = userEvent.setup();
    let clicked = false;
    render(
      <DropdownMenu>
        <DropdownMenuTrigger>Open</DropdownMenuTrigger>
        <DropdownMenuContent>
          <DropdownMenuItem onSelect={() => { clicked = true; }}>Sign out</DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>,
    );
    await user.click(screen.getByText('Open'));
    await user.click(await screen.findByText('Sign out'));
    expect(clicked).toBe(true);
  });
});
```

Create `tests/components/ui/sheet.test.tsx`:
```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Sheet, SheetContent } from '@/components/ui/sheet';

describe('Sheet', () => {
  it('renders its content when open', () => {
    render(
      <Sheet open onOpenChange={() => {}}>
        <SheetContent side="left">drawer-body</SheetContent>
      </Sheet>,
    );
    expect(screen.getByText('drawer-body')).toBeInTheDocument();
  });
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `pnpm test -- --run tests/components/ui/dropdown-menu.test.tsx tests/components/ui/sheet.test.tsx`
Expected: FAIL (cannot resolve modules).

- [ ] **Step 4: Create the primitives**

`src/components/ui/dropdown-menu.tsx`:
```tsx
import * as React from 'react';
import * as DropdownMenuPrimitive from '@radix-ui/react-dropdown-menu';
import { cn } from '@/lib/cn';

export const DropdownMenu = DropdownMenuPrimitive.Root;
export const DropdownMenuTrigger = DropdownMenuPrimitive.Trigger;

export const DropdownMenuContent = React.forwardRef<
  React.ElementRef<typeof DropdownMenuPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof DropdownMenuPrimitive.Content>
>(({ className, sideOffset = 4, ...props }, ref) => (
  <DropdownMenuPrimitive.Portal>
    <DropdownMenuPrimitive.Content
      ref={ref}
      sideOffset={sideOffset}
      className={cn(
        'z-50 min-w-[12rem] overflow-hidden rounded-md border border-border bg-popover p-1 text-popover-foreground shadow-md',
        className,
      )}
      {...props}
    />
  </DropdownMenuPrimitive.Portal>
));
DropdownMenuContent.displayName = 'DropdownMenuContent';

export const DropdownMenuItem = React.forwardRef<
  React.ElementRef<typeof DropdownMenuPrimitive.Item>,
  React.ComponentPropsWithoutRef<typeof DropdownMenuPrimitive.Item>
>(({ className, ...props }, ref) => (
  <DropdownMenuPrimitive.Item
    ref={ref}
    className={cn(
      'relative flex cursor-pointer select-none items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-none focus:bg-accent focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50',
      className,
    )}
    {...props}
  />
));
DropdownMenuItem.displayName = 'DropdownMenuItem';

export const DropdownMenuLabel = React.forwardRef<
  React.ElementRef<typeof DropdownMenuPrimitive.Label>,
  React.ComponentPropsWithoutRef<typeof DropdownMenuPrimitive.Label>
>(({ className, ...props }, ref) => (
  <DropdownMenuPrimitive.Label ref={ref} className={cn('px-2 py-1.5 text-sm font-semibold', className)} {...props} />
));
DropdownMenuLabel.displayName = 'DropdownMenuLabel';

export const DropdownMenuSeparator = React.forwardRef<
  React.ElementRef<typeof DropdownMenuPrimitive.Separator>,
  React.ComponentPropsWithoutRef<typeof DropdownMenuPrimitive.Separator>
>(({ className, ...props }, ref) => (
  <DropdownMenuPrimitive.Separator ref={ref} className={cn('-mx-1 my-1 h-px bg-border', className)} {...props} />
));
DropdownMenuSeparator.displayName = 'DropdownMenuSeparator';
```

`src/components/ui/dialog.tsx`:
```tsx
import * as React from 'react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { cn } from '@/lib/cn';

export const Dialog = DialogPrimitive.Root;
export const DialogTrigger = DialogPrimitive.Trigger;
export const DialogClose = DialogPrimitive.Close;

export const DialogOverlay = React.forwardRef<
  React.ElementRef<typeof DialogPrimitive.Overlay>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Overlay>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Overlay ref={ref} className={cn('fixed inset-0 z-50 bg-black/40', className)} {...props} />
));
DialogOverlay.displayName = 'DialogOverlay';

export const DialogContent = React.forwardRef<
  React.ElementRef<typeof DialogPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Content>
>(({ className, children, ...props }, ref) => (
  <DialogPrimitive.Portal>
    <DialogOverlay />
    <DialogPrimitive.Content
      ref={ref}
      className={cn(
        'fixed left-1/2 top-1/2 z-50 w-full max-w-lg -translate-x-1/2 -translate-y-1/2 rounded-lg border border-border bg-card p-6 shadow-lg',
        className,
      )}
      {...props}
    >
      {children}
      <DialogPrimitive.Close className="absolute right-4 top-4 rounded-sm opacity-70 hover:opacity-100">
        <X className="h-4 w-4" />
        <span className="sr-only">Close</span>
      </DialogPrimitive.Close>
    </DialogPrimitive.Content>
  </DialogPrimitive.Portal>
));
DialogContent.displayName = 'DialogContent';

export function DialogHeader({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('mb-4 flex flex-col gap-1.5', className)} {...props} />;
}
export const DialogTitle = React.forwardRef<
  React.ElementRef<typeof DialogPrimitive.Title>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Title>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Title ref={ref} className={cn('text-lg font-semibold', className)} {...props} />
));
DialogTitle.displayName = 'DialogTitle';
export const DialogDescription = React.forwardRef<
  React.ElementRef<typeof DialogPrimitive.Description>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Description>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Description ref={ref} className={cn('text-sm text-muted-foreground', className)} {...props} />
));
DialogDescription.displayName = 'DialogDescription';
```

`src/components/ui/sheet.tsx` (off-canvas, built on the dialog primitive):
```tsx
import * as React from 'react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { cva, type VariantProps } from 'class-variance-authority';
import { X } from 'lucide-react';
import { cn } from '@/lib/cn';

export const Sheet = DialogPrimitive.Root;
export const SheetTrigger = DialogPrimitive.Trigger;
export const SheetClose = DialogPrimitive.Close;

const sheetVariants = cva(
  'fixed z-50 bg-card shadow-lg transition ease-in-out',
  {
    variants: {
      side: {
        left: 'inset-y-0 left-0 h-full w-72 border-r border-border',
        right: 'inset-y-0 right-0 h-full w-72 border-l border-border',
      },
    },
    defaultVariants: { side: 'left' },
  },
);

export interface SheetContentProps
  extends React.ComponentPropsWithoutRef<typeof DialogPrimitive.Content>,
    VariantProps<typeof sheetVariants> {}

export const SheetContent = React.forwardRef<
  React.ElementRef<typeof DialogPrimitive.Content>,
  SheetContentProps
>(({ side = 'left', className, children, ...props }, ref) => (
  <DialogPrimitive.Portal>
    <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-black/40" />
    <DialogPrimitive.Content ref={ref} className={cn(sheetVariants({ side }), className)} {...props}>
      {children}
      <DialogPrimitive.Close className="absolute right-4 top-4 rounded-sm opacity-70 hover:opacity-100">
        <X className="h-4 w-4" />
        <span className="sr-only">Close</span>
      </DialogPrimitive.Close>
    </DialogPrimitive.Content>
  </DialogPrimitive.Portal>
));
SheetContent.displayName = 'SheetContent';
```

`src/components/ui/tabs.tsx`:
```tsx
import * as React from 'react';
import * as TabsPrimitive from '@radix-ui/react-tabs';
import { cn } from '@/lib/cn';

export const Tabs = TabsPrimitive.Root;

export const TabsList = React.forwardRef<
  React.ElementRef<typeof TabsPrimitive.List>,
  React.ComponentPropsWithoutRef<typeof TabsPrimitive.List>
>(({ className, ...props }, ref) => (
  <TabsPrimitive.List
    ref={ref}
    className={cn('inline-flex h-10 items-center gap-1 rounded-md bg-muted p-1 text-muted-foreground', className)}
    {...props}
  />
));
TabsList.displayName = 'TabsList';

export const TabsTrigger = React.forwardRef<
  React.ElementRef<typeof TabsPrimitive.Trigger>,
  React.ComponentPropsWithoutRef<typeof TabsPrimitive.Trigger>
>(({ className, ...props }, ref) => (
  <TabsPrimitive.Trigger
    ref={ref}
    className={cn(
      'inline-flex items-center justify-center whitespace-nowrap rounded-sm px-3 py-1.5 text-sm font-medium transition-all data-[state=active]:bg-card data-[state=active]:text-foreground data-[state=active]:shadow-sm',
      className,
    )}
    {...props}
  />
));
TabsTrigger.displayName = 'TabsTrigger';

export const TabsContent = React.forwardRef<
  React.ElementRef<typeof TabsPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof TabsPrimitive.Content>
>(({ className, ...props }, ref) => (
  <TabsPrimitive.Content ref={ref} className={cn('mt-4 focus-visible:outline-none', className)} {...props} />
));
TabsContent.displayName = 'TabsContent';
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `pnpm test -- --run tests/components/ui/dropdown-menu.test.tsx tests/components/ui/sheet.test.tsx` → PASS.

- [ ] **Step 6: Commit**
```bash
git add package.json pnpm-lock.yaml src/components/ui/dropdown-menu.tsx src/components/ui/dialog.tsx src/components/ui/sheet.tsx src/components/ui/tabs.tsx tests/components/ui/dropdown-menu.test.tsx tests/components/ui/sheet.test.tsx
git commit -m "feat(redesign): add dropdown-menu, dialog, sheet, tabs primitives"
```

---

## Task 4: Nav data + NavItem + SidebarNav

**Files:**
- Create: `src/components/layout/navItems.ts`, `src/components/layout/NavItem.tsx`, `src/components/layout/SidebarNav.tsx`
- Test: `tests/components/layout/SidebarNav.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `tests/components/layout/SidebarNav.test.tsx`:
```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { SidebarNav } from '@/components/layout/SidebarNav';

function renderNav(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <SidebarNav />
    </MemoryRouter>,
  );
}

describe('SidebarNav', () => {
  it('renders every top-level destination, including Sites', () => {
    renderNav('/overview');
    for (const label of ['Dashboard', 'Sites', 'Monitoring', 'Security', 'Insights', 'Jobs', 'Activity', 'Settings']) {
      expect(screen.getByRole('link', { name: new RegExp(label, 'i') })).toBeInTheDocument();
    }
  });

  it('points Sites at /sites', () => {
    renderNav('/overview');
    expect(screen.getByRole('link', { name: /sites/i })).toHaveAttribute('href', '/sites');
  });

  it('marks the active route with aria-current', () => {
    renderNav('/security');
    expect(screen.getByRole('link', { name: /security/i })).toHaveAttribute('aria-current', 'page');
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pnpm test -- --run tests/components/layout/SidebarNav.test.tsx`
Expected: FAIL (cannot resolve `@/components/layout/SidebarNav`).

- [ ] **Step 3: Create the nav data + components**

`src/components/layout/navItems.ts`:
```ts
import {
  LayoutDashboard, Globe, Activity, ShieldCheck, BarChart3, ListChecks, History, Settings,
  type LucideIcon,
} from 'lucide-react';

export interface NavItemDef {
  label: string;
  to: string;
  icon: LucideIcon;
  /** `true` → only active on an exact path match (e.g. Dashboard at /overview). */
  end?: boolean;
}

export interface NavGroup {
  heading: string;
  items: NavItemDef[];
}

export const NAV_GROUPS: NavGroup[] = [
  {
    heading: 'Main',
    items: [
      { label: 'Dashboard', to: '/overview', icon: LayoutDashboard, end: true },
      { label: 'Sites', to: '/sites', icon: Globe },
    ],
  },
  {
    heading: 'Fleet',
    items: [
      { label: 'Monitoring', to: '/monitoring', icon: Activity },
      { label: 'Security', to: '/security', icon: ShieldCheck },
      { label: 'Insights', to: '/insights', icon: BarChart3 },
    ],
  },
  {
    heading: 'Operations',
    items: [
      { label: 'Jobs', to: '/jobs', icon: ListChecks },
      { label: 'Activity', to: '/activity', icon: History },
    ],
  },
  {
    heading: 'Account',
    items: [{ label: 'Settings', to: '/settings', icon: Settings }],
  },
];
```

`src/components/layout/NavItem.tsx`:
```tsx
import { NavLink } from 'react-router-dom';
import { cn } from '@/lib/cn';
import type { NavItemDef } from './navItems';

interface NavItemProps extends NavItemDef {
  onNavigate?: () => void;
}

export function NavItem({ label, to, icon: Icon, end, onNavigate }: NavItemProps) {
  return (
    <NavLink
      to={to}
      end={end}
      onClick={onNavigate}
      className={({ isActive }) =>
        cn(
          'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
          isActive
            ? 'bg-sidebar-accent text-sidebar-accent-foreground'
            : 'text-sidebar-foreground hover:bg-white/5 hover:text-white',
        )
      }
    >
      <Icon className="h-4 w-4 shrink-0" aria-hidden="true" />
      <span>{label}</span>
    </NavLink>
  );
}
```

`src/components/layout/SidebarNav.tsx`:
```tsx
import { NAV_GROUPS } from './navItems';
import { NavItem } from './NavItem';

interface SidebarNavProps {
  onNavigate?: () => void;
}

export function SidebarNav({ onNavigate }: SidebarNavProps) {
  return (
    <nav className="flex flex-col gap-6">
      {NAV_GROUPS.map((group) => (
        <div key={group.heading} className="flex flex-col gap-1">
          <p className="px-3 text-[0.65rem] font-semibold uppercase tracking-wider text-sidebar-muted">
            {group.heading}
          </p>
          {group.items.map((item) => (
            <NavItem key={item.to} {...item} onNavigate={onNavigate} />
          ))}
        </div>
      ))}
    </nav>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `pnpm test -- --run tests/components/layout/SidebarNav.test.tsx` → PASS.

- [ ] **Step 5: Commit**
```bash
git add src/components/layout/navItems.ts src/components/layout/NavItem.tsx src/components/layout/SidebarNav.tsx tests/components/layout/SidebarNav.test.tsx
git commit -m "feat(redesign): sidebar nav data + NavItem + SidebarNav"
```

---

## Task 5: AccountMenu

**Files:**
- Create: `src/components/layout/AccountMenu.tsx`
- Test: `tests/components/layout/AccountMenu.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `tests/components/layout/AccountMenu.test.tsx`:
```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AccountMenu } from '@/components/layout/AccountMenu';

const user = { id: 1, email: 'pradeep@defyn.com.au', display_name: 'Pradeep' };

describe('AccountMenu', () => {
  it('shows the display name and email', () => {
    render(<AccountMenu user={user} onSignOut={() => {}} />);
    expect(screen.getByText('Pradeep')).toBeInTheDocument();
    expect(screen.getByText('pradeep@defyn.com.au')).toBeInTheDocument();
  });

  it('calls onSignOut when Sign out is chosen', async () => {
    const u = userEvent.setup();
    const onSignOut = vi.fn();
    render(<AccountMenu user={user} onSignOut={onSignOut} />);
    await u.click(screen.getByRole('button', { name: /pradeep/i }));
    await u.click(await screen.findByText(/sign out/i));
    expect(onSignOut).toHaveBeenCalledTimes(1);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pnpm test -- --run tests/components/layout/AccountMenu.test.tsx`
Expected: FAIL (cannot resolve `@/components/layout/AccountMenu`).

- [ ] **Step 3: Create the component**

`src/components/layout/AccountMenu.tsx`:
```tsx
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
  DropdownMenu, DropdownMenuTrigger, DropdownMenuContent, DropdownMenuItem,
  DropdownMenuLabel, DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { LogOut } from 'lucide-react';

interface AccountUser {
  id: number;
  email: string;
  display_name: string;
}

interface AccountMenuProps {
  user: AccountUser;
  onSignOut: () => void;
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '?';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

export function AccountMenu({ user, onSignOut }: AccountMenuProps) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        className="flex w-full items-center gap-3 rounded-md px-2 py-2 text-left text-sm text-sidebar-foreground hover:bg-white/5"
        aria-label={user.display_name}
      >
        <Avatar className="h-8 w-8 bg-sidebar-accent">
          <AvatarFallback className="bg-sidebar-accent text-sidebar-accent-foreground">
            {initials(user.display_name)}
          </AvatarFallback>
        </Avatar>
        <span className="min-w-0 flex-1">
          <span className="block truncate font-medium text-white">{user.display_name}</span>
          <span className="block truncate text-xs text-sidebar-muted">{user.email}</span>
        </span>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" side="top" className="w-56">
        <DropdownMenuLabel>{user.email}</DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem onSelect={onSignOut}>
          <LogOut className="h-4 w-4" aria-hidden="true" />
          Sign out
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `pnpm test -- --run tests/components/layout/AccountMenu.test.tsx` → PASS.

- [ ] **Step 5: Commit**
```bash
git add src/components/layout/AccountMenu.tsx tests/components/layout/AccountMenu.test.tsx
git commit -m "feat(redesign): AccountMenu (avatar + dropdown + sign out)"
```

---

## Task 6: Sidebar

**Files:**
- Create: `src/components/layout/Sidebar.tsx`
- Test: `tests/components/layout/Sidebar.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `tests/components/layout/Sidebar.test.tsx`:
```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { Sidebar } from '@/components/layout/Sidebar';

const user = { id: 1, email: 'pradeep@defyn.com.au', display_name: 'Pradeep' };

describe('Sidebar', () => {
  it('renders the brand, nav, and account', () => {
    render(
      <MemoryRouter initialEntries={['/overview']}>
        <Sidebar user={user} onSignOut={() => {}} />
      </MemoryRouter>,
    );
    expect(screen.getByText('DefynWP')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /sites/i })).toBeInTheDocument();
    expect(screen.getByText('Pradeep')).toBeInTheDocument();
  });
});
```
(Note: the brand text is `◆ DefynWP`; `getByText('DefynWP')` will fail on exact match — use `getByText(/DefynWP/)`. Write the assertion as `screen.getByText(/DefynWP/)`.)

- [ ] **Step 2: Run test to verify it fails**

Run: `pnpm test -- --run tests/components/layout/Sidebar.test.tsx`
Expected: FAIL (cannot resolve `@/components/layout/Sidebar`).

- [ ] **Step 3: Create the component**

`src/components/layout/Sidebar.tsx`:
```tsx
import { ScrollArea } from '@/components/ui/scroll-area';
import { SidebarNav } from './SidebarNav';
import { AccountMenu } from './AccountMenu';

interface SidebarUser {
  id: number;
  email: string;
  display_name: string;
}

interface SidebarProps {
  user: SidebarUser;
  onSignOut: () => void;
  /** Called when a nav link is followed — used to close the mobile sheet. */
  onNavigate?: () => void;
}

export function Sidebar({ user, onSignOut, onNavigate }: SidebarProps) {
  return (
    <div className="flex h-full w-72 flex-col bg-sidebar text-sidebar-foreground">
      <div className="flex items-center gap-2 px-5 py-5">
        <span className="text-base font-bold text-white">◆ DefynWP</span>
      </div>
      <ScrollArea className="flex-1 px-3">
        <SidebarNav onNavigate={onNavigate} />
      </ScrollArea>
      <div className="border-t border-sidebar-border p-3">
        <AccountMenu user={user} onSignOut={onSignOut} />
      </div>
    </div>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `pnpm test -- --run tests/components/layout/Sidebar.test.tsx` → PASS.

- [ ] **Step 5: Commit**
```bash
git add src/components/layout/Sidebar.tsx tests/components/layout/Sidebar.test.tsx
git commit -m "feat(redesign): Sidebar (brand + nav + account)"
```

---

## Task 7: Topbar + PageHeader

**Files:**
- Create: `src/components/layout/Topbar.tsx`, `src/components/layout/PageHeader.tsx`
- Test: `tests/components/layout/Topbar.test.tsx`, `tests/components/layout/PageHeader.test.tsx`

- [ ] **Step 1: Write the failing tests**

Create `tests/components/layout/Topbar.test.tsx`:
```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Topbar } from '@/components/layout/Topbar';

describe('Topbar', () => {
  it('fires onOpenSidebar when the menu button is clicked', async () => {
    const u = userEvent.setup();
    const onOpenSidebar = vi.fn();
    render(<Topbar onOpenSidebar={onOpenSidebar} />);
    await u.click(screen.getByRole('button', { name: /open navigation/i }));
    expect(onOpenSidebar).toHaveBeenCalledTimes(1);
  });
});
```

Create `tests/components/layout/PageHeader.test.tsx`:
```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { PageHeader } from '@/components/layout/PageHeader';

describe('PageHeader', () => {
  it('renders the title, subtitle, and actions', () => {
    render(<PageHeader title="Overview" subtitle="Your fleet at a glance" actions={<button>Sync all</button>} />);
    expect(screen.getByRole('heading', { name: 'Overview' })).toBeInTheDocument();
    expect(screen.getByText('Your fleet at a glance')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Sync all' })).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `pnpm test -- --run tests/components/layout/Topbar.test.tsx tests/components/layout/PageHeader.test.tsx`
Expected: FAIL (modules unresolved).

- [ ] **Step 3: Create the components**

`src/components/layout/Topbar.tsx`:
```tsx
import { Menu, Search } from 'lucide-react';

interface TopbarProps {
  onOpenSidebar: () => void;
}

export function Topbar({ onOpenSidebar }: TopbarProps) {
  return (
    <header className="flex h-14 items-center gap-3 border-b border-border bg-card px-4">
      <button
        type="button"
        onClick={onOpenSidebar}
        aria-label="Open navigation"
        className="rounded-md p-2 text-muted-foreground hover:bg-muted md:hidden"
      >
        <Menu className="h-5 w-5" />
      </button>
      <div className="relative hidden max-w-sm flex-1 md:block">
        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <input
          type="search"
          disabled
          placeholder="Search (coming soon)"
          className="h-9 w-full rounded-md border border-input bg-muted/40 pl-9 pr-3 text-sm text-muted-foreground"
        />
      </div>
      <div className="ml-auto" />
    </header>
  );
}
```

`src/components/layout/PageHeader.tsx`:
```tsx
import * as React from 'react';

interface PageHeaderProps {
  title: string;
  subtitle?: React.ReactNode;
  actions?: React.ReactNode;
}

export function PageHeader({ title, subtitle, actions }: PageHeaderProps) {
  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight text-foreground">{title}</h1>
        {subtitle ? <p className="mt-1 text-sm text-muted-foreground">{subtitle}</p> : null}
      </div>
      {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
    </div>
  );
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `pnpm test -- --run tests/components/layout/Topbar.test.tsx tests/components/layout/PageHeader.test.tsx` → PASS.

- [ ] **Step 5: Commit**
```bash
git add src/components/layout/Topbar.tsx src/components/layout/PageHeader.tsx tests/components/layout/Topbar.test.tsx tests/components/layout/PageHeader.test.tsx
git commit -m "feat(redesign): Topbar + PageHeader"
```

---

## Task 8: Export AuthContext + AppShell

**Files:**
- Modify: `src/lib/auth.tsx` (one additive export)
- Create: `src/components/layout/AppShell.tsx`
- Test: `tests/components/layout/AppShell.test.tsx`

- [ ] **Step 1: Add the additive `AuthContext` export**

In `src/lib/auth.tsx`, change the context declaration line:
```tsx
const AuthContext = React.createContext<AuthContextValue | null>(null);
```
to:
```tsx
export const AuthContext = React.createContext<AuthContextValue | null>(null);
```
(Nothing else changes — `AuthProvider`/`useAuth` keep using it. This lets `AppShell`'s test supply a fake authenticated value without the login round-trip.)

- [ ] **Step 2: Write the failing test**

Create `tests/components/layout/AppShell.test.tsx`:
```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { AuthContext } from '@/lib/auth';
import { AppShell } from '@/components/layout/AppShell';

const authValue = {
  status: 'authenticated' as const,
  user: { id: 1, email: 'pradeep@defyn.com.au', display_name: 'Pradeep' },
  login: async () => {},
  logout: async () => {},
};

function renderShell() {
  return render(
    <AuthContext.Provider value={authValue}>
      <MemoryRouter initialEntries={['/overview']}>
        <AppShell><div>page-body</div></AppShell>
      </MemoryRouter>
    </AuthContext.Provider>,
  );
}

describe('AppShell', () => {
  it('renders the sidebar nav and the routed child', () => {
    renderShell();
    expect(screen.getByRole('link', { name: /sites/i })).toBeInTheDocument();
    expect(screen.getByText('page-body')).toBeInTheDocument();
  });

  it('opens the mobile sidebar sheet from the topbar menu button', async () => {
    const u = userEvent.setup();
    renderShell();
    // Before opening, the sheet content is not mounted; the desktop sidebar
    // has one Sites link. Opening the sheet mounts a second.
    await u.click(screen.getByRole('button', { name: /open navigation/i }));
    const sitesLinks = await screen.findAllByRole('link', { name: /sites/i });
    expect(sitesLinks.length).toBeGreaterThanOrEqual(2);
  });
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `pnpm test -- --run tests/components/layout/AppShell.test.tsx`
Expected: FAIL (cannot resolve `@/components/layout/AppShell`).

- [ ] **Step 4: Create the component**

`src/components/layout/AppShell.tsx`:
```tsx
import * as React from 'react';
import { useLocation } from 'react-router-dom';
import { Sheet, SheetContent } from '@/components/ui/sheet';
import { useAuth } from '@/lib/auth';
import { Sidebar } from './Sidebar';
import { Topbar } from './Topbar';

export function AppShell({ children }: { children: React.ReactNode }) {
  const { user, logout } = useAuth();
  const [mobileOpen, setMobileOpen] = React.useState(false);
  const location = useLocation();

  // Close the mobile drawer whenever the route changes.
  React.useEffect(() => {
    setMobileOpen(false);
  }, [location.pathname]);

  const handleSignOut = React.useCallback(() => {
    void logout();
  }, [logout]);

  // Defensive: RequireAuth guarantees an authenticated user, but guard anyway.
  if (!user) return <>{children}</>;

  return (
    <div className="flex h-screen w-full overflow-hidden bg-background">
      {/* Desktop sidebar */}
      <aside className="hidden md:block">
        <Sidebar user={user} onSignOut={handleSignOut} />
      </aside>

      {/* Mobile sidebar (off-canvas) */}
      <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
        <SheetContent side="left" className="w-72 p-0">
          <Sidebar user={user} onSignOut={handleSignOut} onNavigate={() => setMobileOpen(false)} />
        </SheetContent>
      </Sheet>

      <div className="flex min-w-0 flex-1 flex-col">
        <Topbar onOpenSidebar={() => setMobileOpen(true)} />
        <main className="flex-1 overflow-y-auto">{children}</main>
      </div>
    </div>
  );
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `pnpm test -- --run tests/components/layout/AppShell.test.tsx` → PASS.

- [ ] **Step 6: Commit**
```bash
git add src/lib/auth.tsx src/components/layout/AppShell.tsx tests/components/layout/AppShell.test.tsx
git commit -m "feat(redesign): AppShell frame + export AuthContext for tests"
```

---

## Task 9: Wire AppShell into App.tsx

**Files:**
- Modify: `src/App.tsx`
- Test: `tests/App.shell.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `tests/App.shell.test.tsx`:
```tsx
import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthContext } from '@/lib/auth';
import App from '@/App';

const authValue = {
  status: 'authenticated' as const,
  user: { id: 1, email: 'pradeep@defyn.com.au', display_name: 'Pradeep' },
  login: async () => {},
  logout: async () => {},
};

describe('App shell wiring', () => {
  it('renders authed routes inside the sidebar shell', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={qc}>
        <AuthContext.Provider value={authValue}>
          <MemoryRouter initialEntries={['/overview']}>
            <App />
          </MemoryRouter>
        </AuthContext.Provider>
      </QueryClientProvider>,
    );
    // The shell's Sites link is present on every authed route.
    await waitFor(() => expect(screen.getByRole('link', { name: /sites/i })).toBeInTheDocument());
  });
});
```
(The default MSW handlers in `@/test/setup` answer `/overview`'s `useOverview` query, so the page mounts.)

- [ ] **Step 2: Run test to verify it fails**

Run: `pnpm test -- --run tests/App.shell.test.tsx`
Expected: FAIL (no Sites link — App.tsx still renders bare routes).

- [ ] **Step 3: Wrap the authed routes**

In `src/App.tsx`:
1. Change the router import to add `Outlet`:
```tsx
import { Routes, Route, Outlet } from 'react-router-dom';
```
2. Add the AppShell import (after the other route imports):
```tsx
import { AppShell } from './components/layout/AppShell';
```
3. Nest a layout route inside the `RequireAuth` route. Replace:
```tsx
      <Route element={<RequireAuth />}>
        <Route path="/" element={<Home />} />
        <Route path="/overview" element={<Overview />} />
        <Route path="/overview/plugins" element={<OverviewPlugins />} />
        <Route path="/overview/themes" element={<OverviewThemes />} />
        <Route path="/sites" element={<SitesList />} />
        <Route path="/sites/add" element={<SiteAdd />} />
        <Route path="/sites/:id" element={<SiteDetail />} />
        <Route path="/sites/:id/report" element={<SiteReport />} />
        <Route path="/jobs" element={<Jobs />} />
        <Route path="/jobs/:id" element={<JobDetail />} />
        <Route path="/activity" element={<Activity />} />
        <Route path="/monitoring" element={<Monitoring />} />
        <Route path="/security" element={<Security />} />
        <Route path="/insights" element={<Insights />} />
        <Route path="/settings" element={<Settings />} />
      </Route>
```
with:
```tsx
      <Route element={<RequireAuth />}>
        <Route element={<AppShell><Outlet /></AppShell>}>
          <Route path="/" element={<Home />} />
          <Route path="/overview" element={<Overview />} />
          <Route path="/overview/plugins" element={<OverviewPlugins />} />
          <Route path="/overview/themes" element={<OverviewThemes />} />
          <Route path="/sites" element={<SitesList />} />
          <Route path="/sites/add" element={<SiteAdd />} />
          <Route path="/sites/:id" element={<SiteDetail />} />
          <Route path="/sites/:id/report" element={<SiteReport />} />
          <Route path="/jobs" element={<Jobs />} />
          <Route path="/jobs/:id" element={<JobDetail />} />
          <Route path="/activity" element={<Activity />} />
          <Route path="/monitoring" element={<Monitoring />} />
          <Route path="/security" element={<Security />} />
          <Route path="/insights" element={<Insights />} />
          <Route path="/settings" element={<Settings />} />
        </Route>
      </Route>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `pnpm test -- --run tests/App.shell.test.tsx` → PASS.
Run: `pnpm build` → tsc clean.

- [ ] **Step 5: Commit**
```bash
git add src/App.tsx tests/App.shell.test.tsx
git commit -m "feat(redesign): wrap authed routes in the AppShell layout route"
```

---

## Task 10: Remove the fragmented nav + migrate Overview to PageHeader

**Files:**
- Delete: `src/components/nav/{Jobs,Monitoring,Security,Insights,Settings}NavLink.tsx`
- Delete: `tests/components/nav/{Jobs,Monitoring,Settings}NavLink.test.tsx`
- Modify: `src/routes/Overview.tsx`
- Test: `tests/routes/Overview.header.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `tests/routes/Overview.header.test.tsx`:
```tsx
import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import Overview from '@/routes/Overview';

function renderOverview() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/overview']}>
        <Overview />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('Overview header (post-shell)', () => {
  it('shows the PageHeader title and no in-page Monitoring/Security/Settings nav links', async () => {
    renderOverview();
    await waitFor(() => expect(screen.getByRole('heading', { name: /overview/i })).toBeInTheDocument());
    // These used to be header nav links; the sidebar owns them now, so they must NOT appear as links here.
    expect(screen.queryByRole('link', { name: /^monitoring$/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /^security$/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /^settings$/i })).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pnpm test -- --run tests/routes/Overview.header.test.tsx`
Expected: FAIL (the 5 NavLinks still render as links).

- [ ] **Step 3: Delete the nav components + their tests**

Run:
```bash
git rm src/components/nav/JobsNavLink.tsx src/components/nav/MonitoringNavLink.tsx src/components/nav/SecurityNavLink.tsx src/components/nav/InsightsNavLink.tsx src/components/nav/SettingsNavLink.tsx
git rm tests/components/nav/JobsNavLink.test.tsx tests/components/nav/MonitoringNavLink.test.tsx tests/components/nav/SettingsNavLink.test.tsx
```
(If `src/components/nav/` and `tests/components/nav/` are now empty, that's fine.)

- [ ] **Step 4: Migrate `src/routes/Overview.tsx`**

Remove these imports (old lines 6–10):
```tsx
import { JobsNavLink } from '@/components/nav/JobsNavLink'
import { MonitoringNavLink } from '@/components/nav/MonitoringNavLink'
import { SecurityNavLink } from '@/components/nav/SecurityNavLink'
import { InsightsNavLink } from '@/components/nav/InsightsNavLink'
import { SettingsNavLink } from '@/components/nav/SettingsNavLink'
```
Add this import:
```tsx
import { PageHeader } from '@/components/layout/PageHeader'
```
Replace the entire header block (the `<div className="flex items-start justify-between">…</div>` spanning old lines 53–70) with a `PageHeader`:
```tsx
      <PageHeader
        title="Overview"
        subtitle={`Last refreshed: ${formatRelativeTime(data.generated_at)}`}
        actions={
          <>
            <SyncAllSitesButton totalSites={data.total_sites} />
            <BulkUpdatePluginsButton pendingCount={data.pending_updates.plugins} />
            <BulkUpdateThemesButton pendingCount={data.pending_updates.themes} />
          </>
        }
      />
```
(Keep the rest of the page — `OpenIncidentsWidget`, `PendingUpdatesWidget`, the two-column widgets — unchanged. The loading/error branches keep their existing markup. The `formatRelativeTime`, `SyncAllSitesButton`, `BulkUpdatePluginsButton`, `BulkUpdateThemesButton` imports stay.)

- [ ] **Step 5: Run the test + grep to verify nothing else imports the deleted files**

Run: `pnpm test -- --run tests/routes/Overview.header.test.tsx` → PASS.
Run: `grep -rn "NavLink'" src tests` → no matches (all references gone).

- [ ] **Step 6: Commit**
```bash
git add src/routes/Overview.tsx tests/routes/Overview.header.test.tsx
git commit -m "refactor(redesign): retire per-page nav links; Overview adopts PageHeader"
```

---

## Task 11: Full verification + ship

**Files:** none (build + release)

- [ ] **Step 1: Full SPA test suite**

Run:
```bash
export FNM_DIR="$HOME/.fnm"; eval "$(fnm env --shell bash)"; fnm use 22
pnpm test -- --run
```
Expected: all green **except** the 4 known carry-forwards (`tests/SiteDetail.test.tsx` ×2 + `tests/components/sites/SiteCoreCard.test.tsx` ×2). No NEW failures. If a new failure appears, fix it before continuing.

- [ ] **Step 2: Typecheck + production build**

Run: `pnpm build`
Expected: tsc clean, Vite build succeeds.

- [ ] **Step 3: Local visual smoke (recommended)**

Run `pnpm dev`, open the app, sign in, and confirm: navy sidebar with all nav items incl. **Sites**, active highlight tracks the route, account menu shows name/email + Sign out works, the topbar hamburger opens the drawer at a narrow width, and every page renders inside the shell. Stop the dev server.

- [ ] **Step 4: Merge to main + deploy**

Run:
```bash
git checkout main
git merge --no-ff spa-shell-redesign -m "merge: SPA shell + design system redesign (Slice 1)"
git push origin main
```
Cloudflare Pages auto-deploys `main`.

- [ ] **Step 5: Deploy verification**

After the Cloudflare build completes, fetch the deployed bundle and confirm the new shell shipped:
```bash
curl -s https://app.defynwp.defyn.agency/ | grep -o 'index-[A-Za-z0-9_-]*\.js'
```
Then `curl -s` that bundle URL and grep for a shell literal (e.g. `Open navigation` or the `DefynWP` wordmark). Confirm the SPA still loads (200) and the login page renders.

- [ ] **Step 6: Tag + MEMORY**

```bash
git tag spa-shell-redesign-slice1-complete
git push origin spa-shell-redesign-slice1-complete
```
Update MEMORY: record Slice 1 shipped (app shell + Branded Navy tokens + 8 new primitives + Sites nav), the new `src/components/layout/` structure, that the `*NavLink` components were retired, and that the next slices are the bespoke page redesigns (Overview → Sites list → Site detail tabs → the rest).

---

## Self-Review

**Spec coverage:**
- Design tokens (§1) → Task 1. ✅
- shadcn primitives (§2: tabs, dropdown-menu, dialog, sheet, avatar, separator, skeleton, scroll-area) → Tasks 2–3 (all 8). ✅
- App shell (§3: AppShell, Sidebar, SidebarNav/NavItem, Topbar, AccountMenu, PageHeader + App.tsx layout route) → Tasks 4–9. ✅
- Nav IA (§4: Dashboard, Sites, Monitoring, Security, Insights, Jobs, Activity, Settings, grouped) → Task 4 (`navItems.ts`). ✅
- Per-page migration (§5: remove 5 `*NavLink` + tests; Overview → PageHeader; move action buttons) → Task 10. ✅
- Testing (§6: shell nav incl. Sites, active state, mobile toggle, primitives, route smoke, suite green) → Tasks 4/8/9/11. ✅
- Out of scope (§7) honored — no page internals redesigned, no dark mode, search is a disabled placeholder, dialogs not yet migrated. ✅
- SPA-only, ships via Cloudflare (§ scope) → Task 11. ✅

**Placeholder scan:** No TBD/TODO; every code/test step has complete code. Token hex→HSL triples are concrete.

**Type consistency:** `NavItemDef`/`NavGroup` defined in Task 4 and consumed by `NavItem`/`SidebarNav`. `AccountUser`/`SidebarUser` shapes match the `User` interface in `auth.tsx` (`id`/`email`/`display_name`). `AuthContext` exported in Task 8 and used by the Task 8/9 tests. `Sheet`/`SheetContent` (Task 3) consumed by `AppShell` (Task 8). `PageHeader` (Task 7) consumed by Overview (Task 10). `onOpenSidebar` (Topbar) ↔ `setMobileOpen(true)` (AppShell) consistent. ✅

**Notes for the executor:**
- Confirm `@testing-library/user-event` is a dev dependency before Task 3 (the dropdown/account/topbar/AppShell tests use it); if absent, `pnpm add -D @testing-library/user-event`.
- In Task 6's Sidebar test, assert the brand with `getByText(/DefynWP/)` (the literal is `◆ DefynWP`), not an exact `'DefynWP'` match.
- Read `src/App.tsx` before Task 9 to confirm the exact route block to replace.
