# PLAN - Pixel MatchMaking Foundation (2026-03-07)

## Context

- **Purpose**: Bootstrap the `pixel-matchmaking/` Next.js project with design system integration, environment configuration, and a functional global layout. This is PLAN 1 of Phase 1 from `ROADMAP-PIXEL-PLATFORM.md`.
- **Scope**: 4 features from domain D0 (Foundation): D0.1 (scaffold), D0.2 (DS integration), D0.5 (env config), D0.7 (global layout + navigation).
- **Non-goals**: Auth (D1), i18n (D0.8), SDK generation (D0.3), Prisma schema (D0.6), WebSocket (D10.1). These come in subsequent PLANs.
- **Constraints / assumptions**:
  - `pixel-design-system/` is already built (`dist/` exists with `index.js` + `styles.css`).
  - DS uses React 18 as peer dependency (`^18.0.0`). Next.js 16.1 requires React 19.2. We must update the DS `peerDependencies` to `"react": "^18.0.0 || ^19.0.0"` (DS components are functional — they work fine with React 19).
  - No monorepo root `package.json` — each project is standalone with its own `node_modules`.
  - DS is imported via `file:../pixel-design-system` in `package.json` (local file reference).
  - DS exports: `ThemeProvider`, `TopNav`, `cn`, all tokens, 26 components.
  - DS Tailwind config tokens must be replicated in `pixel-matchmaking/tailwind.config.ts` (colors, shadows, fonts, 0px border-radius).
  - DS CSS (`dist/styles.css`) must be imported for Google Fonts (Karantina + Poppins) and base styles.
  - Desktop only — no mobile responsive.
  - Dark theme by default.
- **Environment snapshot**: Branch `main`, commit `7d4ec3c`.
- **Dependencies**: `pixel-design-system/` (local, already built).

## Steps

- [Done] Phase 1 - Scaffold Next.js 16.1 project
- [Done] Phase 2 - Design system integration
- [Done] Phase 3 - Environment configuration
- [Done] Phase 4 - Global layout and navigation
- [Done] Phase 5 - Unit tests
- [Done] Phase 6 - Chrome QA validation

### Phase 1 - Scaffold Next.js 16.1 project (D0.1)

- [Done] P1.1 - Create `pixel-matchmaking/` directory at monorepo root
  - Run `npx create-next-app@latest pixel-matchmaking` with options: TypeScript, App Router, Tailwind CSS, src/ directory, import alias `@/*`.
  - This installs Next.js 16.1 + React 19.2 + Turbopack by default.
  - Update `pixel-design-system/package.json` peerDependencies: change `"react": "^18.0.0"` to `"react": "^18.0.0 || ^19.0.0"` and same for `react-dom` (DS components are all functional — no breaking change with React 19).
- [Done] P1.2 - Verify project structure
  - Ensure `app/` directory exists under `src/` (or at root depending on scaffold choice).
  - Create directories if missing: `src/components/`, `src/lib/`.
  - Verify `npm run dev` starts without error.
- [Done] P1.3 - Configure TypeScript strict mode
  - Verify `tsconfig.json` has `"strict": true`.
  - Add path alias `@/*` mapping to `src/*` if not already configured.
- [Done] P1.4 - Configure dev server port
  - Set dev server to port **4000** (avoid conflict with pixel-control-server on 3000 and pixel-control-ui on 5173).
  - Update `package.json` dev script: `"dev": "next dev -p 4000"`.
  - Note: Next.js 16.1 uses Turbopack by default — no extra config needed.

### Phase 2 - Design system integration (D0.2)

- [Done] P2.1 - Add DS as local dependency
  - In `pixel-matchmaking/package.json`, add `"@pixel-series/design-system-neumorphic": "file:../pixel-design-system"` to `dependencies`.
  - Run `npm install`.
  - Verify import works: `import { Button } from '@pixel-series/design-system-neumorphic'`.
- [Done] P2.2 - Configure Tailwind v3 with DS tokens
  - Copy DS tokens into `pixel-matchmaking/tailwind.config.ts`:
    - Colors: `px-primary`, `px-primary-light`, `px-primary-dark`, `px-primary-30`, `px-dark`, `px-offblack`, `px-white`, `px-offwhite`, `px-input`, `px-label`, `px-line`, `px-error`, `px-success`, `px-warning`, `nm-dark`, `nm-dark-s`, `nm-light`, `nm-light-s`.
    - Font families: `display: ['Karantina', 'cursive']`, `body: ['Poppins', 'sans-serif']`.
    - Letter spacing: `display: '3px'`, `wide-body: '0.75px'`.
    - Border radius: ALL set to `0px` (none, DEFAULT, sm, md, lg).
    - Box shadows: all 8 neumorphic shadows (`nm-raised-d`, `nm-inset-d`, etc.).
    - Animation: `fade-slide-up`.
  - Add `content` path to include DS source: `'../pixel-design-system/src/**/*.{ts,tsx}'` (so Tailwind scans DS classes).
- [Done] P2.3 - Import DS styles and fonts
  - In the root `globals.css` (or `app/globals.css`), import the DS CSS: `@import '@pixel-series/design-system-neumorphic/styles.css';`.
  - This brings in Google Fonts (Karantina + Poppins) and base styles (box-sizing, dark color-scheme, body bg/font).
  - Keep the existing Tailwind directives (`@tailwind base/components/utilities`).
- [Done] P2.4 - Wire ThemeProvider in root layout
  - In `src/app/layout.tsx`, wrap `{children}` with `<ThemeProvider defaultTheme="dark">`.
  - Import `ThemeProvider` from `@pixel-series/design-system-neumorphic`.
  - Mark the layout component as `'use client'` OR create a separate client component `Providers.tsx` that wraps `ThemeProvider` (preferred — keeps layout as server component).
- [Done] P2.5 - Create a smoke test page
  - Replace default Next.js home page content with a simple page that renders a DS `Button` and `Card` to verify integration.
  - Verify: dark background (`nm-dark`), Karantina font on headings, Poppins on body text, neumorphic shadows visible, 0px border-radius.

### Phase 3 - Environment configuration (D0.5)

- [Done] P3.1 - Create `.env.example`
  - Document all required env vars with placeholder values:
    ```
    # Pixel Control Server API
    NEXT_PUBLIC_API_URL=http://localhost:3000/v1

    # ManiaPlanet OAuth2 SSO
    MANIAPLANET_CLIENT_ID=your_client_id
    MANIAPLANET_CLIENT_SECRET=your_client_secret

    # NextAuth
    NEXTAUTH_SECRET=generate_a_random_secret
    NEXTAUTH_URL=http://localhost:4000

    # Database (platform-dedicated PostgreSQL)
    DATABASE_URL=postgresql://user:password@localhost:5432/pixel_matchmaking
    ```
- [Done] P3.2 - Create `.env.local` (gitignored)
  - Copy `.env.example` to `.env.local` with dev-friendly defaults.
  - Verify `.gitignore` includes `.env.local` (Next.js scaffold should already include it).
- [Done] P3.3 - Create `src/lib/config.ts`
  - Export a typed config object that reads env vars with runtime validation:
    ```ts
    export const config = {
      apiUrl: process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:3000/v1',
      // Server-only vars (not NEXT_PUBLIC_) are only available server-side
    } as const;
    ```
  - Keep it simple — just a typed accessor, no heavy validation framework.

### Phase 4 - Global layout and navigation (D0.7)

- [Done] P4.1 - Create navigation config
  - Create `src/lib/navigation.ts` with the nav link definitions:
    - Accueil (`/`)
    - Jouer (`/play`)
    - Classement (`/leaderboard`)
    - Profil (`/me`)
    - Admin (`/admin`) — will be conditionally shown based on role (future)
  - Export as typed array of `TopNavLink` (from DS).
- [Done] P4.2 - Build the root layout (`src/app/layout.tsx`)
  - HTML lang="fr" (default language).
  - Metadata: title "Pixel MatchMaking", description.
  - Body: dark background class (`bg-nm-dark text-px-white`).
  - Structure: `<Providers>` → `<TopNav>` + `<main>{children}</main>`.
  - Import DS `TopNav` with brand text "PIXEL MATCHMAKING" (Karantina display font).
  - Desktop only — no viewport meta tricks for mobile (keep default).
- [Done] P4.3 - Create `src/components/Providers.tsx` (client component)
  - `'use client'` component that wraps children with `ThemeProvider defaultTheme="dark"`.
  - Keeps `layout.tsx` as a server component.
- [Done] P4.4 - Create `src/components/AppTopNav.tsx` (client component)
  - `'use client'` component that uses Next.js `usePathname()` to set the `active` state on the correct nav link.
  - Renders DS `TopNav` with brand, links, and placeholder actions slot (for future auth buttons).
  - Links use `<Link>` from `next/link` behavior (but TopNav uses `<a>` tags, so we use `onClick` + `router.push` or pass `href` directly — depends on TopNav API which uses plain `<a href>`).
- [Done] P4.5 - Create placeholder pages
  - `src/app/page.tsx` — Home page with "Pixel MatchMaking" heading and placeholder content.
  - `src/app/play/page.tsx` — Placeholder "Jouer" page.
  - `src/app/leaderboard/page.tsx` — Placeholder "Classement" page.
  - `src/app/me/page.tsx` — Placeholder "Mon Profil" page.
  - `src/app/admin/page.tsx` — Placeholder "Administration" page.
  - Each page should use DS components (`Card`, `Badge`) to demonstrate consistent styling.

### Phase 5 - Unit tests

- [Done] P5.1 - Set up Vitest for Next.js
  - Install `vitest`, `@vitejs/plugin-react`, `jsdom`, `@testing-library/react`, `@testing-library/jest-dom`.
  - Create `vitest.config.ts` with jsdom environment, path aliases matching tsconfig.
- [Done] P5.2 - Write unit tests for `src/lib/config.ts`
  - Test that default values are used when env vars are missing.
  - Test that env vars are read correctly when set.
- [Done] P5.3 - Write unit tests for `src/lib/navigation.ts`
  - Test that all nav links are defined with correct paths and labels.
  - Test that the exported array has the expected length and structure.
- [Done] P5.4 - Write component render tests
  - Test that `AppTopNav` renders all navigation links.
  - Test that `Providers` wraps children with ThemeProvider (renders without crash).
  - Test that the home page renders the heading.
- [Done] P5.5 - Verify all tests pass
  - Run `npm test` — all tests must pass with zero failures.
  - Run `npm run build` — production build must succeed with zero errors.

### Phase 6 - Chrome QA validation

- [Done] P6.1 - Start dev server and navigate to home page
  - Run `npm run dev` in `pixel-matchmaking/`.
  - Open `http://localhost:4000` in Chrome.
  - Verify: page loads, dark background, no console errors.
- [Done] P6.2 - Validate design system integration
  - Check: Karantina font on headings (uppercase, tracking).
  - Check: Poppins font on body text.
  - Check: 0px border-radius on all elements (no rounded corners).
  - Check: Neumorphic shadows on Card components.
  - Check: Primary color `#2C12D9` is used (not orange).
  - Check: Dark theme is active by default.
- [Done] P6.3 - Validate navigation
  - Check: TopNav renders with "PIXEL MATCHMAKING" brand.
  - Check: All 5 nav links are visible (Accueil, Jouer, Classement, Profil, Admin).
  - Click each nav link and verify it navigates to the correct page.
  - Check: Active link is highlighted (neumorphic inset style).
- [Done] P6.4 - Validate placeholder pages
  - Navigate to each page: `/`, `/play`, `/leaderboard`, `/me`, `/admin`.
  - Each page should render its title and placeholder content with DS components.
  - No broken pages, no 404s, no console errors.

## Success criteria

- `pixel-matchmaking/` exists at monorepo root with Next.js 16.1 + React 19.2 + TypeScript strict + Tailwind CSS v3 + Turbopack.
- DS components render correctly with neumorphic styling (dark theme, 0px radius, correct fonts and colors).
- All 5 navigation routes work and TopNav shows active state.
- All unit tests pass (`npm test`).
- Production build succeeds (`npm run build`) with zero errors.
- Chrome QA confirms visual correctness: fonts, shadows, colors, layout.

## Evidence / Artifacts

- `pixel-matchmaking/` — the new project directory
- Test results from `npm test`
- Build output from `npm run build`
- Chrome QA screenshots (captured during Phase 6)
