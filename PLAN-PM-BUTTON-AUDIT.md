# PLAN - Button Audit & Fix (2026-03-08)

## Context

- **Purpose:** Fix all non-functional buttons across the pixel-matchmaking site.
- **Scope:** Home page (2 buttons) + Play page (1 button). All other pages have no actionable buttons or are already correctly wired.
- **Background:** Audit of all pages revealed 3 broken buttons:
  - Home `/`: "Jouer" button → no onClick
  - Home `/`: "Voir le classement" button → no onClick
  - Play `/play`: "Rechercher un match" button → no onClick, feature not yet implemented
- **Goals:**
  - Home "Jouer" navigates to `/play`
  - Home "Voir le classement" navigates to `/leaderboard`
  - Play "Rechercher un match" is properly disabled (feature not implemented)
  - All tests pass, build green
- **Non-goals:** Implementing the actual matchmaking feature.
- **Constraints:**
  - `'use client'` already present on all pages
  - Use `useRouter` from `next/navigation` for navigation
  - No new i18n keys needed

## Steps

- [Done] Phase 1 - Fix buttons
- [Done] Phase 2 - Verify build and tests

### Phase 1 - Fix buttons

- [Done] P1.1 - Fix Home page (`src/app/page.tsx`)
  - Add `useRouter` import from `next/navigation`
  - Add `onClick={() => router.push('/play')}` to the "Jouer" button
  - Add `onClick={() => router.push('/leaderboard')}` to the "Voir le classement" button

- [Done] P1.2 - Fix Play page (`src/app/play/page.tsx`)
  - Add `disabled` prop to "Rechercher un match" button

### Phase 2 - Verify build and tests

- [Done] P2.1 - Run `npm test` in pixel-matchmaking/ and fix any failures
- [Done] P2.2 - Run `npm run build` in pixel-matchmaking/ and fix any build errors

## Files to create / modify

| File | Action |
|---|---|
| `pixel-matchmaking/src/app/page.tsx` | Modify |
| `pixel-matchmaking/src/app/play/page.tsx` | Modify |

## Success criteria

- Clicking "Jouer" on home navigates to `/play`
- Clicking "Voir le classement" on home navigates to `/leaderboard`
- "Rechercher un match" is visually disabled and non-clickable
- All tests pass, build green

## Execution

**To execute this plan, use the `plan-execution` skill.**
