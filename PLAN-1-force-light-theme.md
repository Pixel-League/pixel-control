# PLAN-1 - Force pixel-matchmaking to always use light theme (2026-03-08)

## Context

- **Purpose:** Force the site to always render in light theme regardless of the OS system preference. Dark mode support is deferred for later. The system is currently set to dark to verify the site stays fully in light mode (background, navbar, all DS components).
- **Scope:** `pixel-design-system/src/context/ThemeContext.tsx` + `pixel-matchmaking` (globals.css, Providers.tsx). No other projects.
- **Root cause of current problem:** Three places propagate dark theme when system is dark:
  1. `ThemeContext.tsx` lazy initializer reads `prefers-color-scheme` → returns `dark`
  2. `ThemeContext.tsx` useEffect registers a `change` listener → updates to dark on system change
  3. `globals.css` sets dark body background by default (`#121212`), overrides to light only via `@media (prefers-color-scheme: light)`
  4. `Providers.tsx` passes `defaultTheme="dark"` to ThemeProvider
- **Goals:**
  - All DS components (TopNav, Cards, Buttons…) always render in light theme.
  - Page background is always light (`#f0f2f5`), regardless of system preference.
  - System preference changes have zero effect.
  - 108 pixel-matchmaking tests stay green, build clean.
- **Non-goals:** Dark mode support (deferred), manual theme toggle.
- **Constraints:** DS rebuild required. Chrome QA on localhost:4000 with dark system preference active.

## Steps

- [Done] Phase 1 - Remove system preference detection, force light theme
- [Done] Phase 2 - QA in Chrome
- [Done] Phase 3 - Tests, build, commit

### Phase 1 - Remove system preference detection, force light theme

- [Done] P1.1 - Strip `prefers-color-scheme` detection from `ThemeContext.tsx` in DS
  - Remove the lazy initializer that reads `matchMedia` — replace with plain `useState<Theme>(defaultTheme)`.
  - Remove the `useEffect` that registers the `change` listener entirely (and its import if unused).
  - `setTheme` / `toggleTheme` stay intact for future use.

- [Done] P1.2 - Set `defaultTheme="light"` in `Providers.tsx`
  - Change `<ThemeProvider defaultTheme="dark">` → `<ThemeProvider defaultTheme="light">`.

- [Done] P1.3 - Fix `globals.css` to always use light background (no media query)
  - Remove the dark default (`background-color: #121212`) and the `@media (prefers-color-scheme: light)` block.
  - Set a single static rule: `body { background-color: #f0f2f5; color: #14142b; }`.

- [Done] P1.4 - Rebuild the design system
  - `cd pixel-design-system && npm run build`

### Phase 2 - QA in Chrome

- [Done] P2.1 - Navigate to localhost:4000 and take a screenshot
  - Verify: light background, light navbar, light cards — no dark anywhere.

- [Done] P2.2 - Reload the page and confirm no dark flash
  - Hard reload (Cmd+Shift+R), screenshot immediately.

- [Done] P2.3 - Check body background and nav background in JS console
  - `getComputedStyle(document.body).backgroundColor` should be `rgb(240, 242, 245)`.
  - Nav background should be light, not `rgb(18, 18, 18)`.

### Phase 3 - Tests, build, commit

- [Done] P3.1 - Run pixel-matchmaking tests — expect 108/108
- [Done] P3.2 - Run pixel-matchmaking build — expect zero errors
- [Done] P3.3 - Commit all changes on `feat/pm-sdk-i18n`

## Success criteria

- Screenshot shows fully light site with dark system preference active.
- `getComputedStyle(document.body).backgroundColor` = `rgb(240, 242, 245)`.
- Nav background is light (not `rgb(18, 18, 18)`).
- 108/108 tests, build clean.

## Execution

**To execute this plan, use the `plan-execution` skill.**
