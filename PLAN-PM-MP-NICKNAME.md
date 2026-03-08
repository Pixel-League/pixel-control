# PLAN - ManiaPlanet Nickname Formatting (2026-03-08)

## Context

- **Purpose:** Display ManiaPlanet nicknames with proper formatting (colors, styles) across the platform. Currently, the navbar shows raw formatting codes (e.g. `$o$n$fftop`) instead of styled text. Also provide a utility to fetch and format any player's nickname by their login.
- **Scope:** Install `tm-text` npm library, create formatting utilities and a `MpNickname` React component, integrate into the navbar, expose a server action for fetching other players' profiles.
- **Background:** ManiaPlanet uses `$xxx` codes for colors (`$f00` = red) and styles (`$o` bold, `$i` italic, `$s` shadow, `$n` narrow, `$l` link). The library `tm-text` (MIT, no deps, TypeScript) provides `htmlify()` (HTML with styles) and `humanize()` (strip codes). `blockify()` returns an array of styled blocks.
- **Goals:**
  - Logged-in user's nickname rendered with ManiaPlanet colors/styles in the navbar.
  - Reusable `MpNickname` React component and `mp-text.ts` utility.
  - `getPlayerNickname(login)` server action: DB lookup → ManiaPlanet API fallback → raw login.
- **Non-goals:** Full profile pages, leaderboard nickname rendering (can reuse `MpNickname` later), dark mode support.
- **Constraints / assumptions:**
  - `pixel-matchmaking/` uses Next.js 16, React 19, TypeScript strict, Tailwind CSS v3.
  - `tm-text` v1.1.0 has CJS + ESM + TypeScript types.
  - ManiaPlanet player profile API: `GET https://www.maniaplanet.com/webservices/players/{login}` with `Authorization: Basic base64(clientId:clientSecret)`.
  - Prisma DB already stores users who have logged in (`login`, `nickname` fields).
- **Environment snapshot:** Branch `feat/pm-sdk-i18n`. `pixel-matchmaking/` test count: 108 tests, 15 files.

## Steps

- [Done] Phase 1 - Install tm-text and create formatting utility
- [Done] Phase 2 - Create MpNickname React component
- [Done] Phase 3 - Integrate MpNickname in the navbar
- [Done] Phase 4 - Server action for fetching other players' nicknames
- [Done] Phase 5 - Tests and build verification

### Phase 1 - Install tm-text and create formatting utility

- [Done] P1.1 - Install `tm-text` in `pixel-matchmaking/`
  - `cd pixel-matchmaking && npm install tm-text`
- [Done] P1.2 - Create `pixel-matchmaking/src/lib/mp-text.ts`
  - Export `stripMpStyles(text: string): string` — wraps `humanize(text)`
  - Export `mpToHtml(text: string): string` — wraps `htmlify(text)`
  - Export `hasMpStyles(text: string): boolean` — returns `text.includes('$')`
- [Done] P1.3 - Create `pixel-matchmaking/src/lib/mp-text.test.ts`
  - Test `stripMpStyles('$o$n$fftop')` → `'top'`
  - Test `stripMpStyles('$f00Rouge')` → `'Rouge'`
  - Test `mpToHtml('$f00R')` → contains `color` style
  - Test `hasMpStyles('$o$n$fftop')` → `true`
  - Test `hasMpStyles('TestPlayer')` → `false`

### Phase 2 - Create MpNickname React component

- [Done] P2.1 - Create `pixel-matchmaking/src/components/MpNickname.tsx`
  - `'use client'` directive
  - Props: `{ nickname: string; className?: string }`
  - If `hasMpStyles(nickname)` → render `<span dangerouslySetInnerHTML={{ __html: mpToHtml(nickname) }} />`
  - Otherwise → render `<span>{nickname}</span>`
  - Apply `className` to the outer span
- [Done] P2.2 - Create `pixel-matchmaking/src/components/MpNickname.test.tsx`
  - Test: plain text `'TestPlayer'` renders as text, no inline styles
  - Test: `'$fffTestPlayer'` → DOM contains text "TestPlayer" (visible)
  - Test: `'$f00R'` → rendered HTML contains a `style` attribute with color

### Phase 3 - Integrate MpNickname in the navbar

- [Done] P3.1 - Update `pixel-matchmaking/src/components/AppTopNav.tsx`
  - Import `MpNickname` from `@/components/MpNickname`
  - Replace the raw `<span>` displaying `session.user.nickname ?? session.user.name` with `<MpNickname nickname={session.user.nickname ?? session.user.name ?? ''} className="text-sm font-body text-px-label tracking-wide-body" />`
  - Remove the outer `<span>` wrapper (keep `MpNickname` directly in the flex div)
- [Done] P3.2 - Update `pixel-matchmaking/src/components/AppTopNav.test.tsx`
  - Existing test `'shows user nickname when logged in'` uses `nickname: 'TestPlayer'` (no codes) → `getByText('TestPlayer')` still works
  - Add test: `'renders formatted nickname with ManiaPlanet codes'` — session with `nickname: '$fffTestPlayer'` → `screen.getByText('TestPlayer')` found in DOM

### Phase 4 - Server action for fetching other players' nicknames

- [Done] P4.1 - Create `pixel-matchmaking/src/lib/mp-api.ts`
  - Server-only utility (no `'use client'`)
  - Export `getMpPlayerProfile(login: string): Promise<{ login: string; nickname: string; path: string | null } | null>`
  - Build `Authorization: Basic base64(ID:SECRET)` header from env vars
  - `fetch('https://www.maniaplanet.com/webservices/players/{login}', { headers, signal: AbortSignal.timeout(5000) })`
  - Return `null` on any error (non-2xx, network error, parse error)
- [Done] P4.2 - Create `pixel-matchmaking/src/lib/actions/players.ts`
  - `'use server'` directive
  - Import `prisma`, `getMpPlayerProfile`, `stripMpStyles`
  - Export `getPlayerNickname(login: string): Promise<string>`
    1. `prisma.user.findUnique({ where: { login }, select: { nickname: true } })` → return `stripMpStyles(nickname)` if found
    2. Else: `getMpPlayerProfile(login)` → return `stripMpStyles(nickname)` if found
    3. Else: return `login`
  - Export `getPlayerProfile(login: string): Promise<{ login: string; nickname: string; path: string | null } | null>`
    - Returns raw profile (with raw nickname, not stripped) from DB first, then MP API
- [Done] P4.3 - Create `pixel-matchmaking/src/lib/actions/players.test.ts`
  - Mock `@/lib/prisma` and `@/lib/mp-api`
  - Test: user in DB → returns DB nickname (stripped)
  - Test: user not in DB, MP API succeeds → returns API nickname (stripped)
  - Test: user not in DB, MP API fails → returns raw login

### Phase 5 - Tests and build verification

- [Done] P5.1 - Run `npm test` in `pixel-matchmaking/` and fix any failures
  - Expected: all existing 108 tests + new tests pass
- [Done] P5.2 - Run `npm run build` in `pixel-matchmaking/` and fix any build errors

## Files to create / modify

| File | Action |
|---|---|
| `pixel-matchmaking/src/lib/mp-text.ts` | Create |
| `pixel-matchmaking/src/lib/mp-text.test.ts` | Create |
| `pixel-matchmaking/src/components/MpNickname.tsx` | Create |
| `pixel-matchmaking/src/components/MpNickname.test.tsx` | Create |
| `pixel-matchmaking/src/components/AppTopNav.tsx` | Modify |
| `pixel-matchmaking/src/components/AppTopNav.test.tsx` | Modify |
| `pixel-matchmaking/src/lib/mp-api.ts` | Create |
| `pixel-matchmaking/src/lib/actions/players.ts` | Create |
| `pixel-matchmaking/src/lib/actions/players.test.ts` | Create |
| `pixel-matchmaking/package.json` | Modified by npm install |

## Success criteria

- Navbar displays `top` with ManiaPlanet styles for nickname `$o$n$fftop` (no raw codes visible).
- `MpNickname` component works for any `$xxx`-encoded string.
- `getPlayerNickname('somelogin')` returns a clean (stripped) nickname.
- All tests pass, build is green, zero TypeScript errors.

## Execution

**To execute this plan, use the `plan-execution` skill.**
