# PLAN-3 - E2E Tests + Test Review (2026-03-08)

## Context

- **Purpose:** Add Playwright E2E tests for critical user flows and review/improve existing unit tests.
- **Scope:** Playwright setup, E2E specs for auth/home/play/nav, script in package.json, unit test review.
- **Branch:** `feat/pm-e2e-tests` from `feat/pm-mobx-stores`
- **Goals:**
  - Install and configure Playwright (`@playwright/test`)
  - `playwright.config.ts` — baseURL: http://localhost:4000, chromium only, no other browsers
  - `tests/e2e/auth.spec.ts` — signin page accessible, ManiaPlanet button present, protected routes redirect
  - `tests/e2e/home.spec.ts` — heading present, buttons Jouer → /play, Classement → /leaderboard
  - `tests/e2e/play.spec.ts` — QuickMatchCard renders, button is clickable
  - `tests/e2e/nav.spec.ts` — navbar links present and navigate correctly
  - `npm run test:e2e` script in package.json
  - All 148+ unit tests still pass
- **Non-goals:** Running E2E tests in CI (no CI configured). Visual regression tests.
- **Constraints:**
  - E2E tests are NOT run with `npm test` (Vitest) — separate `test:e2e` script
  - Playwright runs against the live dev server (localhost:4000) — not mocked
  - Tests must handle unauthenticated state (most pages redirect to signin if not logged in)
  - `'use client'` required for all components using MobX/hooks
  - No inline TypeScript imports

## Steps

- [Done] Phase 1 - Install and configure Playwright
- [Done] Phase 2 - Write E2E specs
- [Done] Phase 3 - Update package.json with test:e2e script
- [Done] Phase 4 - Unit test review and improvements
- [Done] Phase 5 - QA: unit tests pass

### Phase 1 - Install and configure Playwright

- [Done] P1.1 - Install `@playwright/test` (dev dependency)
- [Done] P1.2 - Install Playwright browsers (chromium only): `npx playwright install chromium`
- [Done] P1.3 - Create `playwright.config.ts` at project root
  - baseURL: `http://localhost:4000`
  - Browser: chromium only
  - Output dir: `tests/e2e-results`
  - webServer: start `npm run dev` and wait for localhost:4000 (timeout 30s)
  - Screenshots on failure

### Phase 2 - Write E2E specs

- [Done] P2.1 - Create `tests/e2e/auth.spec.ts`
  - Visit `/auth/signin` → expect page to load (no redirect loop)
  - Expect ManiaPlanet button text present
  - Visit `/play` unauthenticated → expect redirect to `/auth/signin`
  - Visit `/me` unauthenticated → expect redirect to `/auth/signin`
  - Visit `/admin` unauthenticated → expect redirect to `/auth/signin`
- [Done] P2.2 - Create `tests/e2e/home.spec.ts`
  - Visit `/` → expect h1 heading text
  - Expect "Jouer" button present
  - Expect "Voir le classement" (or translated equivalent) button present
  - Click "Jouer" → navigates to `/play` (redirects to signin because unauthenticated)
- [Done] P2.3 - Create `tests/e2e/play.spec.ts`
  - Visit `/play` unauthenticated → redirected to `/auth/signin`
  - Signin page: expect ManiaPlanet button
- [Done] P2.4 - Create `tests/e2e/nav.spec.ts`
  - Visit `/` → expect navbar links (Home, Play, Leaderboard, etc.)
  - Click leaderboard nav link → navigates to `/leaderboard`

### Phase 3 - Update package.json

- [Done] P3.1 - Add `"test:e2e": "playwright test"` to scripts

### Phase 4 - Unit test review and improvements

- [Done] P4.1 - Review existing test files for missing cases
  - Check coverage of `config.ts`, `mp-api.ts`
  - Check `AppTopNav.test.tsx` and `UserMenu.test.tsx`
  - Check Providers test
- [Done] P4.2 - Add missing unit tests where gaps are found
  - `src/shared/lib/config.test.ts` — if not exists, test env accessor
  - `src/app/page.test.tsx` — ensure home button navigation is tested

### Phase 5 - QA

- [Done] P5.1 - Run `npm test` and confirm all unit tests pass

## Success criteria

- `npm run test:e2e` runs Playwright tests (requires running dev server)
- All critical user flows covered: auth redirect, home navigation, signin page
- 148+ unit tests still pass

## Execution

**To execute this plan, use the `plan-execution` skill.**
