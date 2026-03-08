# PLAN - User Avatar Dropdown Menu (2026-03-08)

## Context

- **Purpose:** Replace the inline "nickname + logout button" in the navbar with an avatar-triggered dropdown menu positioned top-right.
- **Scope:** Create a `UserMenu` component using DS `Avatar` (trigger) and `DropdownMenu`, wire it into `AppTopNav`, update tests, verify build.
- **Background:**
  - `Avatar` props: `size`, `initials`, `alt`, `src`, `theme`, `className`. Renders initials as fallback when no image.
  - `DropdownMenu` props: `trigger` (ReactNode), `items` (DropdownMenuItem[]), `align` ('left'|'right'), `theme`. Items have `label: string` only (no ReactNode), so `MpNickname` cannot be used inside items — use `stripMpStyles()` for plain-text display in dropdown.
  - Both components are already exported from the DS index.
  - Existing translations: `nav.profile` ("Profil"/"Profile"), `common.logout` ("Déconnexion"/"Sign out") — no new keys needed.
- **Goals:**
  - Avatar with player initials in top-right of navbar, replacing current nick + button.
  - Dropdown items: ① player plain-text name (disabled header), ② divider, ③ "Profil" → /me, ④ "Déconnexion" (danger).
  - All tests pass, build green.
- **Non-goals:** Dark mode support (deferred), avatar image (no image URL available from MP OAuth).
- **Constraints / assumptions:**
  - Theme is forced to `light` via `ThemeProvider defaultTheme="light"`.
  - `UserMenu` must be `'use client'` (uses hooks + signOut).
  - No new i18n translation keys needed.
- **Environment snapshot:** Branch `feat/pm-sdk-i18n`. 129 tests (18 files).

## Steps

- [Done] Phase 1 - Create UserMenu component
- [Done] Phase 2 - Integrate UserMenu in AppTopNav
- [Done] Phase 3 - Update tests and verify build

### Phase 1 - Create UserMenu component

- [Done] P1.1 - Create `pixel-matchmaking/src/components/UserMenu.tsx`
  - `'use client'` directive
  - Props: `{ nickname: string; login: string }`
  - Compute `stripped = stripMpStyles(nickname) || login` for initials and dropdown label
  - Compute `initials`: first 1-2 uppercase chars of `stripped`
  - Use `useTranslations('nav')` and `useTranslations('common')` for item labels
  - Use `useRouter` for `/me` navigation, `signOut` from `next-auth/react` for logout
  - Dropdown items:
    1. `{ label: stripped, disabled: true }` — read-only name header
    2. `{ divider: true }`
    3. `{ label: tNav('profile'), onClick: () => router.push('/me') }`
    4. `{ label: tCommon('logout'), danger: true, onClick: () => signOut({ callbackUrl: '/' }) }`
  - Return `<DropdownMenu trigger={<Avatar initials={initials} size="sm" alt={stripped} />} items={items} align="right" />`
  - Wrap with `cursor-pointer` on the trigger via Avatar's `className`

### Phase 2 - Integrate UserMenu in AppTopNav

- [Done] P2.1 - Update `pixel-matchmaking/src/components/AppTopNav.tsx`
  - Import `UserMenu` from `@/components/UserMenu`
  - Remove imports of `signOut` and `Button` (logout button no longer needed directly in AppTopNav — keep `Button` import for the sign-in case)
  - Replace the `authActions` block for logged-in state:
    - Old: `<div><MpNickname .../><Button variant="ghost" ... logout .../><LanguageSelector /></div>`
    - New: `<div className="flex items-center gap-3"><UserMenu nickname={session.user.nickname ?? session.user.name ?? ''} login={session.user.login ?? ''} /><LanguageSelector /></div>`
  - Keep `MpNickname` import only if used elsewhere; if not, remove it
  - Keep `Button` import for the signed-out state (sign-in button)

### Phase 3 - Update tests and verify build

- [Done] P3.1 - Update `pixel-matchmaking/src/components/AppTopNav.test.tsx`
  - The test `'shows user nickname when logged in'` currently checks `screen.getByText('TestPlayer')` — the nickname is now shown in the dropdown (as disabled label). The Avatar renders initials ("TE" from "TestPlayer"). Update the assertion to check for the Avatar element or for the DropdownMenu trigger being rendered. Strategy: mock `UserMenu` or check that the logout button is no longer directly visible (replaced by dropdown).
  - Simplest approach: keep mock session and assert the avatar initials or that the dropdown trigger exists via `screen.getByRole('button', ...)`.
  - The test `'renders formatted nickname with ManiaPlanet codes'` needs updating similarly.
  - Keep the LanguageSelector and other tests intact.
- [Done] P3.2 - Create `pixel-matchmaking/src/components/UserMenu.test.tsx`
  - Use `TestProviders` wrapper (includes all required providers)
  - Mock `next/navigation` (useRouter) and `next-auth/react` (signOut)
  - Test: renders avatar with correct initials from plain nickname
  - Test: renders avatar with initials from MP-formatted nickname (stripped)
  - Test: clicking avatar opens dropdown with profile and logout items
- [Done] P3.3 - Run `npm test` and fix any failures
- [Done] P3.4 - Run `npm run build` and fix any build errors

## Files to create / modify

| File | Action |
|---|---|
| `pixel-matchmaking/src/components/UserMenu.tsx` | Create |
| `pixel-matchmaking/src/components/UserMenu.test.tsx` | Create |
| `pixel-matchmaking/src/components/AppTopNav.tsx` | Modify |
| `pixel-matchmaking/src/components/AppTopNav.test.tsx` | Modify |

## Success criteria

- Navbar shows only the Avatar in top-right for a logged-in user (no inline nickname/button).
- Clicking the Avatar opens a dropdown with: player name (disabled), divider, Profil, Déconnexion.
- All 130+ tests pass, build is green.

## Execution

**To execute this plan, use the `plan-execution` skill.**
