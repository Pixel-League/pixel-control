# PLAN - Pixel MatchMaking Auth SSO ManiaPlanet (2026-03-07)

## Context

- **Purpose**: Implement ManiaPlanet OAuth2 SSO authentication for the Pixel MatchMaking platform. Users must log in via their ManiaPlanet account to access protected pages. This is PLAN 2 of Phase 1 from `ROADMAP-PIXEL-PLATFORM.md`.
- **Scope**: 4 features from domain D1 (Auth & Identity): D1.1 (custom NextAuth provider), D1.2 (login flow), D1.3 (session + Prisma User table), D1.5 (protected route proxy).
- **Non-goals**: Token refresh (D1.4), profile sync enrichment (D1.6), logout UX polish (D1.7), roles/permissions enforcement (D1.8) — these come in Phase 3. i18n, SDK, WebSocket — separate PLANs.
- **Background / Findings**:
  - Reference PHP implementation at `ressources/oauth2-maniaplanet/` uses League OAuth2.
  - ManiaPlanet OAuth2 endpoints: authorize (`/login/oauth2/authorize`), token (`/login/oauth2/access_token`), profile (`/webservices/me`).
  - Profile returns: `login` (unique, immutable), `nickname`, `path` (geographic zone, e.g. `World|Europe|France`).
  - Scope: `basic` (space separator). No email provided by ManiaPlanet.
  - Auth.js v5 (`next-auth@beta`) is the recommended library for Next.js 16.1 — modern async patterns, native App Router support, custom OAuth providers.
  - Next.js 16 uses `proxy.ts` instead of `middleware.ts` (deprecated). Auth.js v5 supports `export { auth as proxy }`.
  - Auth.js v5 env convention: `AUTH_` prefix. `AUTH_SECRET` replaces `NEXTAUTH_SECRET`.
- **Constraints / assumptions**:
  - Platform has its own dedicated PostgreSQL database (separate from pixel-control-server).
  - Prisma ORM for database access (schema in `pixel-matchmaking/prisma/schema.prisma`).
  - We may not have real ManiaPlanet OAuth credentials during development. Chrome QA tests the flow up to the OAuth redirect (validates UI, redirect URL, error states).
  - User ID is `login` (ManiaPlanet username), NOT email.
  - JWT session strategy with user persisted in DB (hybrid approach for admin role checks).
  - All auth pages must use DS components (Card, Button) with neumorphic styling.
  - No inline TS imports — static imports at file top.
- **Environment snapshot**: Branch `feat/pm-foundation`, commit `3087793`. New branch: `feat/pm-auth-sso`.
- **Dependencies**: `next-auth@beta` (Auth.js v5), `@prisma/client`, `prisma` (dev).

## Steps

- [Done] Phase 1 - Install dependencies and Prisma setup
- [Done] Phase 2 - ManiaPlanet custom OAuth provider
- [Done] Phase 3 - Auth.js configuration and route handler
- [Done] Phase 4 - Login page and session UI
- [Done] Phase 5 - Protected route proxy
- [Done] Phase 6 - Unit tests
- [Done] Phase 7 - Chrome QA validation

### Phase 1 - Install dependencies and Prisma setup (D1.3 partial)

- [Done] P1.1 - Install auth and database packages
  - `npm install next-auth@beta @prisma/client`
  - `npm install -D prisma`
  - Verify installation succeeds and no peer dependency conflicts.
- [Done] P1.2 - Initialize Prisma schema
  - Run `npx prisma init` to create `prisma/schema.prisma` and update `.env`.
  - Configure datasource for PostgreSQL: `DATABASE_URL` from env.
  - Create the `User` model (minimal for auth — expand in future PLANs):
    ```prisma
    model User {
      id        String   @id @default(cuid())
      login     String   @unique  // ManiaPlanet login (immutable, primary identifier)
      nickname  String             // ManiaPlanet display name
      path      String?            // Geographic zone (e.g., "World|Europe|France")
      role      String   @default("player")  // player | moderator | admin | superadmin
      createdAt DateTime @default(now())
      updatedAt DateTime @updatedAt
    }
    ```
- [Done] P1.3 - Generate Prisma client and create migration
  - `npx prisma migrate dev --name init-user-table`
  - `npx prisma generate`
  - Create `src/lib/prisma.ts` — singleton Prisma client instance (avoid multiple instances in dev hot-reload).
- [Done] P1.4 - Update environment variables
  - Update `.env.example` with Auth.js v5 conventions:
    - `AUTH_SECRET` (replaces `NEXTAUTH_SECRET`)
    - `AUTH_MANIAPLANET_ID` (replaces `MANIAPLANET_CLIENT_ID`)
    - `AUTH_MANIAPLANET_SECRET` (replaces `MANIAPLANET_CLIENT_SECRET`)
    - `AUTH_URL=http://localhost:4000` (app base URL)
    - Keep `DATABASE_URL`.
  - Update `.env.local` with matching dev values.
  - Update `src/lib/config.ts` if needed.

### Phase 2 - ManiaPlanet custom OAuth provider (D1.1)

- [Done] P2.1 - Create custom ManiaPlanet provider
  - Create `src/lib/auth/maniaplanet-provider.ts`.
  - Implement Auth.js v5 custom OAuth2 provider with:
    - `id: "maniaplanet"`, `name: "ManiaPlanet"`, `type: "oauth"`
    - `authorization.url`: `https://www.maniaplanet.com/login/oauth2/authorize` with `scope: "basic"`.
    - `token.url`: `https://www.maniaplanet.com/login/oauth2/access_token` — POST with `application/x-www-form-urlencoded` body, `Accept: application/json` header.
    - `userinfo.url`: `https://www.maniaplanet.com/webservices/me` — GET with `Authorization: Bearer <token>`, `Accept: application/json`.
    - `profile()` mapping: `{ id: login, name: nickname, login, nickname, path }`. No email from MP — use synthetic `${login}@maniaplanet.local` if needed by Auth.js.
  - Reference: `ressources/oauth2-maniaplanet/src/Provider/Maniaplanet.php` for endpoint/header details.
- [Done] P2.2 - Add TypeScript type augmentations
  - Create `src/types/next-auth.d.ts` to extend Auth.js types:
    - Augment `Session.user` with `login`, `nickname`, `path`, `role`.
    - Augment `JWT` with `login`, `nickname`, `path`, `role`.
  - This ensures TypeScript knows about our custom user fields throughout the app.

### Phase 3 - Auth.js configuration and route handler (D1.2, D1.3)

- [Done] P3.1 - Create main auth configuration
  - Create `src/lib/auth/auth.config.ts` with the NextAuth configuration:
    - Provider: ManiaPlanet custom provider from P2.1.
    - Session strategy: `"jwt"`.
    - Custom pages: `signIn: "/auth/signin"`.
    - Callbacks:
      - `signIn`: On first login, create User in DB (upsert by `login`). On subsequent logins, update `nickname` and `path`.
      - `jwt`: Enrich JWT token with `login`, `nickname`, `path`, `role` from DB user.
      - `session`: Expose `login`, `nickname`, `path`, `role` on the session object.
  - Use Prisma client from `src/lib/prisma.ts` for DB operations.
- [Done] P3.2 - Create auth entry point
  - Create `src/auth.ts` at the `src/` root (or `auth.ts` at project root depending on Auth.js v5 convention):
    ```ts
    import NextAuth from "next-auth"
    import { authConfig } from "@/lib/auth/auth.config"
    export const { auth, handlers, signIn, signOut } = NextAuth(authConfig)
    ```
  - This is the single export point used by route handler, proxy, and components.
- [Done] P3.3 - Create API route handler
  - Create `src/app/api/auth/[...nextauth]/route.ts`:
    ```ts
    import { handlers } from "@/auth"
    export const { GET, POST } = handlers
    ```
  - This handles `/api/auth/signin`, `/api/auth/callback/maniaplanet`, `/api/auth/signout`, etc.

### Phase 4 - Login page and session UI (D1.2)

- [Done] P4.1 - Create sign-in page
  - Create `src/app/auth/signin/page.tsx`.
  - Design using DS components: `Card` container, `Button` for "Se connecter avec ManiaPlanet".
  - Use a Server Action to call `signIn("maniaplanet")` on button click.
  - Display `callbackUrl` from searchParams to redirect after login.
  - Handle error states (e.g., OAuth error returned in URL params).
  - Style: centered card on dark background, neumorphic shadows, Karantina heading, Poppins body, primary color for CTA button.
- [Done] P4.2 - Update AppTopNav with auth state
  - Modify `src/components/AppTopNav.tsx` to show:
    - When logged out: "Se connecter" button (links to `/auth/signin`).
    - When logged in: User nickname + "Déconnexion" button.
  - Use `auth()` server-side or `useSession()` client-side to get session state.
  - Since `AppTopNav` is a `'use client'` component, use `SessionProvider` + `useSession()`.
- [Done] P4.3 - Add SessionProvider to Providers
  - Update `src/components/Providers.tsx` to wrap children with Auth.js `SessionProvider`.
  - Import from `next-auth/react`.
  - This enables `useSession()` in any client component.
- [Done] P4.4 - Create sign-out action
  - Add sign-out functionality via Server Action or client-side `signOut()` from `next-auth/react`.
  - Redirect to `/` after sign-out.

### Phase 5 - Protected route proxy (D1.5)

- [Done] P5.1 - Create middleware.ts for route protection (middleware.ts used instead of proxy.ts for Auth.js v5 compatibility)
  - Create `src/proxy.ts` (Next.js 16 convention, replaces middleware.ts).
  - Use Auth.js v5 `auth` wrapper: `export { auth as default } from "@/auth"` with custom logic.
  - Protected routes: `/play`, `/me`, `/me/*`, `/admin`, `/admin/*`.
  - Public routes: `/`, `/leaderboard`, `/player/*`, `/matches`, `/auth/*`, `/api/auth/*`.
  - If unauthenticated on protected route → redirect to `/auth/signin?callbackUrl=<original_url>`.
  - Matcher config to exclude static assets: `/((?!_next/static|_next/image|favicon.ico).*)`.
- [Done] P5.2 - Verify proxy behavior (will be verified in Phase 7 Chrome QA)
  - Test that unauthenticated access to `/play` redirects to sign-in.
  - Test that `/` and `/leaderboard` are accessible without auth.
  - Test that `/api/auth/*` routes are not intercepted.

### Phase 6 - Unit tests

- [Done] P6.1 - Test ManiaPlanet provider configuration
  - Verify provider has correct `id`, `name`, `type`.
  - Verify authorization URL and token URL are correct.
  - Verify `profile()` mapping transforms MP response correctly (login→id, nickname→name, etc.).
- [Done] P6.2 - Test Prisma client singleton
  - Verify `src/lib/prisma.ts` exports a singleton instance.
  - Verify it doesn't create multiple clients in dev mode.
- [Done] P6.3 - Test auth configuration
  - Verify auth config has correct session strategy (`jwt`).
  - Verify custom pages point to `/auth/signin`.
  - Verify provider is included in the config.
- [Done] P6.4 - Test navigation auth helpers
  - Test that protected route list is correct.
  - Test that public route list includes expected paths.
- [Done] P6.5 - Run all tests and build (73 tests pass, build succeeds)
  - `npm test` — all new + existing tests pass (0 failures).
  - `npm run build` — production build succeeds with zero errors.
  - Verify no regressions in existing 20 tests from PLAN-PM-FOUNDATION.

### Phase 7 - Chrome QA validation

- [Done] P7.1 - Validate sign-in page renders
  - Navigate to `http://localhost:4000/auth/signin`.
  - Verify: DS card with neumorphic shadow, "Se connecter avec ManiaPlanet" button, dark theme, correct fonts.
- [Done] P7.2 - Validate OAuth redirect (redirects to maniaplanet.com/login)
  - Click "Se connecter avec ManiaPlanet" button.
  - Verify it redirects to `https://www.maniaplanet.com/login/oauth2/authorize` with correct query params (client_id, redirect_uri, scope=basic, response_type=code).
  - Note: We cannot complete the OAuth flow without real credentials — validating the redirect URL is sufficient.
- [Done] P7.3 - Validate protected route redirect
  - Navigate to `http://localhost:4000/play` while not logged in.
  - Verify redirect to `/auth/signin?callbackUrl=%2Fplay`.
  - Navigate to `http://localhost:4000/admin` — same redirect behavior.
- [Done] P7.4 - Validate public routes
  - Navigate to `http://localhost:4000/` — no redirect, page loads normally.
  - Navigate to `http://localhost:4000/leaderboard` — no redirect, page loads normally.
- [Done] P7.5 - Validate TopNav auth state
  - When not logged in: verify "Se connecter" button appears in TopNav.
  - Verify clicking it navigates to `/auth/signin`.

## Success criteria

- Auth.js v5 (`next-auth@beta`) installed and configured with custom ManiaPlanet OAuth2 provider.
- Prisma schema with `User` model, migration applied, singleton client created.
- Sign-in page renders with DS neumorphic styling at `/auth/signin`.
- OAuth redirect goes to correct ManiaPlanet authorize URL with proper params.
- Protected routes (`/play`, `/me`, `/admin`) redirect to sign-in when unauthenticated.
- Public routes (`/`, `/leaderboard`) remain accessible without auth.
- TopNav shows "Se connecter" button when not logged in.
- All unit tests pass (new + existing 20 from PLAN 1). Build succeeds.
- Chrome QA confirms visual correctness and redirect behavior.

## Evidence / Artifacts

- `pixel-matchmaking/src/lib/auth/` — auth configuration and provider
- `pixel-matchmaking/prisma/schema.prisma` — User model
- `pixel-matchmaking/src/app/auth/signin/page.tsx` — sign-in page
- `pixel-matchmaking/src/proxy.ts` — route protection
- Test results from `npm test`
- Build output from `npm run build`

## Execution

**To execute this plan, use the `plan-execution` skill.** Do not execute steps manually or outside of this skill.
