# PLAN - Eliminate theme flash on page load using native CSS (2026-03-08)

## Context

- **Purpose:** Eliminate the visible flash of dark theme that occurs on page load when the user's system preference is light. Currently the site always renders dark first, then corrects after JS hydration, causing a perceptible flicker.
- **Root cause:** Two-layered problem:
  1. `ThemedRoot` (in `Providers.tsx`) applies `bg-nm-dark` as the SSR default. The correction only fires inside a `useEffect` in `ThemeContext.tsx`, which runs *after* hydration — one full render cycle too late.
  2. DS components (cards, nav, inputs…) also start from `defaultTheme = 'dark'` in `useState`, so they too flash before the `useEffect` corrects them.
- **Scope:** `pixel-design-system/src/context/ThemeContext.tsx` + `pixel-matchmaking` (globals.css, layout.tsx, Providers.tsx). No other projects touched.
- **Goals:**
  - Page background is on the correct theme from the very first pixel painted — using native CSS `@media (prefers-color-scheme)`, no JS involved.
  - DS components are on the correct theme from the first React render (lazy `useState` initializer, not `useEffect`).
  - System theme changes at runtime continue to work.
  - Zero new hydration errors/warnings (handled with `suppressHydrationWarning`).
- **Non-goals:** Manual theme toggle UI, theme persistence in localStorage, Chrome QA (user will validate manually).
- **Constraints:** Next.js App Router (layout.tsx is a Server Component — cannot use hooks). DS build required after changes. All 108 pixel-matchmaking tests must stay green.

## Steps

- [Done] Phase 1 - CSS layer: native `prefers-color-scheme` for page background
- [Done] Phase 2 - DS layer: synchronous theme initialisation (no flash for components)
- [Done] Phase 3 - App layer: cleanup and SSR hygiene
- [In progress] Phase 4 - Tests, build, commit

### Phase 1 - CSS layer: native `prefers-color-scheme` for page background

- [Done] P1.1 - Add `prefers-color-scheme` rules to `globals.css`
  - Define `body` background and text color using the design token values directly (no Tailwind classes needed here since this runs before JS).
  - Dark default (`:root`): `background-color: #121212` (nm-dark), `color: #FFFFFF` (px-white).
  - Light override (`@media (prefers-color-scheme: light)`): `background-color: #F0F2F5` (nm-light), `color: #14142B` (px-offblack).
  - This CSS is evaluated by the browser *before* any JavaScript runs → zero flash for the background.

- [Done] P1.2 - Remove bg/text classes from `ThemedRoot` in `Providers.tsx`
  - `ThemedRoot` no longer needs `bg-nm-dark`/`bg-nm-light` since CSS handles it.
  - Keep `ThemedRoot` as a thin wrapper (it still provides theme context to DS child components via `useTheme()`). Remove only the color classes; keep `min-h-screen`.

### Phase 2 - DS layer: synchronous theme initialisation

- [Done] P2.1 - Replace `useState(defaultTheme)` + `useEffect` init with a lazy initializer in `ThemeContext.tsx`
  - Change: `useState<Theme>(defaultTheme)` → `useState<Theme>(() => { if (typeof window === 'undefined') return defaultTheme; return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; })`
  - The lazy initializer runs **synchronously** on the first client render during React hydration. DS components therefore receive the correct theme before any paint — no double-render flash.
  - Keep the `useEffect` but remove the `setThemeState(mq.matches …)` call inside it (the lazy initializer already handled the initial value). The `useEffect` only registers the `change` listener going forward.

### Phase 3 - App layer: cleanup and SSR hygiene

- [Done] P3.1 - Add `suppressHydrationWarning` to `<html>` in `layout.tsx`
  - Server renders with `dark` (SSR default), client may hydrate with `light`. React will reconcile correctly but logs a hydration mismatch warning without this attribute.
  - `<html lang={locale} suppressHydrationWarning>` — standard Next.js pattern for theme systems.

- [Done] P3.2 - Rebuild the design system
  - `cd pixel-design-system && npm run build` — required after touching `ThemeContext.tsx`.

### Phase 4 - Tests, build, commit

- [Done] P4.1 - Run pixel-matchmaking tests (`npm test -- --run`) — expect 108/108 green
- [Done] P4.2 - Run pixel-matchmaking build (`npm run build`) — expect zero errors
- [In progress] P4.3 - Commit on `feat/pm-sdk-i18n` with all changed files

## Success criteria

- `globals.css` defines `body` background via `prefers-color-scheme` CSS (no JS).
- `ThemeContext.tsx` uses a lazy `useState` initializer (no `setThemeState` call inside `useEffect`).
- `ThemedRoot` has no color classes (`bg-*`, `text-*`).
- `<html>` has `suppressHydrationWarning`.
- 108/108 tests green, build clean.
- No visible theme flash when loading with light system preference (user to validate).

## Execution

**To execute this plan, use the `plan-execution` skill.**

## Notes / outcomes

- Token values used in CSS: nm-dark = `#121212`, nm-light = `#F0F2F5`, px-white = `#FFFFFF`, px-offblack = `#14142B`.
