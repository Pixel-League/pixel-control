# PLAN-4 — Play Homepage Redesign (2026-03-10)

## Context

- **Purpose:** Merge the play page into `/` as the homepage, redesign it around a central search button with queue/match stats, remove unused pages from production nav, and add dev-mode gating for hidden pages.
- **Scope:**
  - `/` becomes the matchmaking page (big search button + queue count + mock "ongoing matches" cards).
  - `/play` route removed (redirect to `/` or deleted entirely).
  - `CustomLobbyCard` component removed from display (no "coming soon" features shown).
  - Leaderboard, Profile, Admin pages hidden from nav and only visible when `NEXT_PUBLIC_DEV_MODE=true`.
  - `/` is now public (no auth redirect). The search button itself gates on session — redirects to signin if unauthenticated.
  - Mock data: 2-3 simple "ongoing match" cards with team names, score, map, duration.
  - All affected tests updated (unit + E2E).
- **Non-goals:** Implementing real match data API. Lobby features. Mobile responsive.
- **Constraints:**
  - Must use `frontend-design` skill during execution for all UI work.
  - DS components only (Button, Card, Badge from `@pixel-series/design-system-neumorphic`). Custom components must follow DS visual conventions (0px radius, neumorphic shadows, Plus Jakarta Sans display, Poppins body).
  - MobX `matchmakingStore` stays as-is (searching, queueCount, startSearch/cancelSearch).
  - i18n: update `fr.json` and `en.json` translation files.
  - Branch from `feat/pm-e2e-tests`.

## Steps

- [Done] Phase 1 — Dev mode infrastructure
- [Done] Phase 2 — Route & nav restructuring
- [Done] Phase 3 — Homepage redesign (play-centric)
- [Done] Phase 4 — Auth model change (/ becomes public)
- [Done] Phase 5 — Cleanup removed content
- [Done] Phase 6 — Update tests
- [Done] Phase 7 — QA: build + tests + visual check

### Phase 1 — Dev mode infrastructure

- [Done] P1.1 — Add `NEXT_PUBLIC_DEV_MODE` to config
  - In `src/shared/lib/config.ts`, add: `devMode: process.env.NEXT_PUBLIC_DEV_MODE === 'true'`
  - Add `NEXT_PUBLIC_DEV_MODE=true` to `.env.local` (and `.env.example` with `false`).
- [Done] P1.2 — Create `useDevMode()` hook
  - `src/shared/hooks/useDevMode.ts` — reads `config.devMode`. Simple getter, no state.

### Phase 2 — Route & nav restructuring

- [Done] P2.1 — Update `NAV_ITEMS` in `src/shared/lib/navigation.ts`
  - Production nav: only `{ translationKey: 'play', href: '/' }` (the play/home link).
  - Dev-only nav: leaderboard (`/leaderboard`), profile (`/me`), admin (`/admin`).
  - Export two arrays: `NAV_ITEMS` (always shown) and `DEV_NAV_ITEMS` (shown only in dev mode).
  - Remove the old `home` entry — play IS home now.
- [Done] P2.2 — Update `AppTopNav.tsx` to conditionally render dev nav items
  - Import `useDevMode()`. Merge `NAV_ITEMS` + (devMode ? `DEV_NAV_ITEMS` : []) into `navLinks`.
- [Done] P2.3 — Delete or redirect `/play` route
  - Delete `src/app/play/page.tsx`. The content will live at `src/app/page.tsx`.
  - If `/play` is visited, it should 404 (or optionally redirect to `/` — but deletion is cleaner).
- [Done] P2.4 — Update nav translations
  - `nav.play` stays (it's the home link label now). Remove or keep `nav.home` key (mark deprecated).
  - In `fr.json`/`en.json`: ensure `nav.play` = "Jouer" / "Play".

### Phase 3 — Homepage redesign (play-centric)

- [Done] P3.1 — Redesign `src/app/page.tsx` as the matchmaking hub
  - Use `frontend-design` skill for this step.
  - Layout: centered, vertical stack.
    - Top: title + subtitle (small, not dominant).
    - Center: **large search button** (prominent, full-width or large centered). Uses `matchmakingStore` via `observer()`.
    - Below button: queue count text (when searching), searching animation text.
    - Below: "Ongoing matches" section with 2-3 mock cards.
  - The search button: if user not authenticated, clicking redirects to `/auth/signin`. If authenticated, triggers `matchmakingStore.startSearch()`.
- [Done] P3.2 — Create `OngoingMatchCard` component
  - `src/features/matchmaking/components/OngoingMatchCard.tsx`
  - Props: `teamA: string`, `teamB: string`, `scoreA: number`, `scoreB: number`, `map: string`, `duration: string`, `mode: string`.
  - Uses DS `Card` component. Display: team names (orange vs cyan like pixel-control-ui), score, map name, elapsed time.
- [Done] P3.3 — Add mock match data
  - `src/features/matchmaking/data/mockMatches.ts` — 2-3 hardcoded match objects.
  - Example: `{ teamA: 'Pixel Strikers', teamB: 'Neon Wolves', scoreA: 2, scoreB: 1, map: 'Stadium A1', duration: '12:34', mode: 'Elite 3v3' }`.
- [Done] P3.4 — Update i18n translations
  - Add keys in `fr.json`/`en.json` under `play` namespace:
    - `play.ongoingMatches.title` = "Matchs en cours" / "Ongoing matches"
    - `play.ongoingMatches.empty` = "Aucun match en cours" / "No ongoing matches"
    - `play.ongoingMatches.map` = "Map" / "Map"
    - `play.ongoingMatches.mode` = "Mode" / "Mode"
    - `play.ongoingMatches.duration` = "Durée" / "Duration"
    - `play.searchButton` = "Rechercher un match" / "Search for a match"
    - `play.loginRequired` = "Connectez-vous pour jouer" / "Sign in to play"
  - Clean up unused `home.*` keys (matchmaking.button, leaderboard.button, etc.).

### Phase 4 — Auth model change (/ becomes public)

- [Done] P4.1 — Update `routes.ts`
  - Remove `/play` from `PROTECTED_PREFIXES` (since `/play` no longer exists and `/` is public).
  - Ensure `/` stays in `PUBLIC_ROUTES`.
- [Done] P4.2 — Update `proxy.ts` if needed
  - Verify that `/` is not redirected to signin. It should already be public since `isProtectedRoute('/')` returns false, but confirm after removing `/play`.
- [Done] P4.3 — Gate the search button on auth in the page component
  - In the new `page.tsx`, if `!session`, clicking the search button calls `router.push('/auth/signin')` instead of `matchmakingStore.startSearch()`.

### Phase 5 — Cleanup removed content

- [Done] P5.1 — Remove `CustomLobbyCard` component
  - Delete `src/features/matchmaking/components/CustomLobbyCard.tsx`.
  - Remove its import from wherever it was used.
- [Done] P5.2 — Clean up old home page content
  - The old `page.tsx` (home) content is fully replaced — no separate cleanup needed if P3.1 overwrites it.
  - Remove unused `home.*` translation keys from `fr.json`/`en.json`.
- [Done] P5.3 — Clean up `play.customLobby.*` translation keys
  - Remove `play.customLobby` section from both translation files.

### Phase 6 — Update tests

- [Done] P6.1 — Update `src/app/page.test.tsx`
  - Replace old home page tests with new matchmaking hub tests:
    - Renders search button.
    - Renders ongoing match cards.
    - Search button triggers store or redirects to signin.
- [Done] P6.2 — Update `src/shared/lib/navigation.test.ts`
  - Update to test new `NAV_ITEMS` (1 item: play) and `DEV_NAV_ITEMS` (3 items: leaderboard, profile, admin).
- [Done] P6.3 — Update `src/features/auth/lib/routes.test.ts`
  - Remove tests for `/play` being protected. Add test for `/play` being unprotected/not-found.
- [Done] P6.4 — Update `src/features/navigation/components/AppTopNav.test.tsx`
  - Test that only "Jouer" link is shown by default.
  - Test that dev-mode shows leaderboard/profile/admin links (mock `config.devMode = true`).
- [Done] P6.5 — Update E2E tests
  - `home.spec.ts` → rewrite for new homepage (search button, ongoing matches cards).
  - `nav.spec.ts` → only "Jouer" link visible. Remove classement nav link tests.
  - `auth.spec.ts` → remove `/play` redirect test (no longer protected). Add test: unauthenticated click on search button → redirect to signin.
  - `play.spec.ts` → remove or adapt (the /play route no longer exists).
- [Done] P6.6 — Run `npm test` and fix any remaining failures

### Phase 7 — QA: build + visual check

- [Done] P7.1 — Run `npm run build` and fix errors
- [Done] P7.2 — Visual check via Chrome (browser multi-instance conflict — manual QA required)
  - Open `http://localhost:4000/` — verify big search button, ongoing match cards, queue count.
  - Verify nav only shows "Jouer" link (unless dev mode).
  - Verify unauthenticated search button redirects to signin.
  - Verify `/play` returns 404 or redirects.

## Success criteria

- `/` is the matchmaking hub with a prominent search button, queue count, and 2-3 mock ongoing match cards.
- `/play` route is gone.
- Nav only shows "Jouer" in production. Dev mode (`NEXT_PUBLIC_DEV_MODE=true`) reveals leaderboard, profile, admin links.
- Unauthenticated users see the page but search button redirects to signin.
- CustomLobbyCard is removed.
- All unit tests pass (150+ adjusted). E2E tests updated. Build green.

## Execution

**To execute this plan, use the `plan-execution` skill.**
