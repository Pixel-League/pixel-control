# PLAN-1 - Domain-Based Architecture Refactoring (2026-03-08)

## Context

- **Purpose:** Restructure `pixel-matchmaking/src/` from a flat folder layout to a domain-based architecture.
- **Scope:** Move and reorganize all source files into `shared/` and `features/` subdirectories; update all imports; keep Next.js routing convention intact (`app/` stays); extract inline page logic into feature components.
- **Branch:** `feat/pm-domain-arch` from `feat/pm-sdk-i18n`
- **Goals:**
  - `src/shared/` — generic utilities, types, i18n, test helpers, lib
  - `src/features/{auth,matchmaking,profile,navigation}/` — domain components and logic
  - `app/` pages become thin wrappers importing from features
  - All 135 tests pass, build green
- **Non-goals:** MobX (Plan 2), E2E tests (Plan 3).
- **Constraints:**
  - `src/auth.ts` stays at root (Auth.js v5 entry point)
  - `src/proxy.ts` stays at root (Next.js 16 middleware)
  - `src/generated/` stays as-is (Prisma auto-generated)
  - `app/api/` routes stay in-place (Next.js routing)
  - `app/` pages stay in-place but become thin wrappers
  - `next.config.ts` references `'./src/i18n/request.ts'` → must update to `'./src/shared/i18n/request.ts'`
  - All imports use `@/` alias (points to `src/`)
  - `git mv` for all file moves to preserve git history

## Target Structure

```
src/
  auth.ts                        # stays (Auth.js v5 entry)
  proxy.ts                       # stays (Next.js middleware)
  shared/
    components/
      ErrorBoundary.tsx
      LanguageSelector.tsx + test
      MpNickname.tsx + test
    lib/
      config.ts + test
      mp-api.ts
      mp-text.ts + test
      navigation.ts + test
      prisma.ts + test
      actions/
        players.ts + test
      api/                       # entire api/ subtree
    i18n/
      actions.ts
      config.ts + test
      request.ts
    test/
      intl-wrapper.tsx
    types/
      next-auth.d.ts
      tm-text.d.ts
  features/
    auth/
      components/
        Providers.tsx + test
        SignInForm.tsx
      lib/
        actions.ts
        auth.config.ts + test
        maniaplanet-provider.ts + test
        routes.ts + test
    matchmaking/
      components/
        QuickMatchCard.tsx       # extracted from app/play/page.tsx
        CustomLobbyCard.tsx      # extracted from app/play/page.tsx
    profile/
      components/
        StatsCard.tsx            # extracted from app/me/page.tsx
        SettingsCard.tsx         # extracted from app/me/page.tsx
    navigation/
      components/
        AppTopNav.tsx + test
        UserMenu.tsx + test
  app/                           # Next.js routing (thin pages)
  generated/                     # Prisma (unchanged)
```

## Import Changes Summary

| Old path | New path |
|---|---|
| `@/components/MpNickname` | `@/shared/components/MpNickname` |
| `@/components/LanguageSelector` | `@/shared/components/LanguageSelector` |
| `@/components/ErrorBoundary` | `@/shared/components/ErrorBoundary` |
| `@/components/Providers` | `@/features/auth/components/Providers` |
| `@/components/SignInForm` | `@/features/auth/components/SignInForm` |
| `@/components/AppTopNav` | `@/features/navigation/components/AppTopNav` |
| `@/components/UserMenu` | `@/features/navigation/components/UserMenu` |
| `@/lib/config` | `@/shared/lib/config` |
| `@/lib/navigation` | `@/shared/lib/navigation` |
| `@/lib/prisma` | `@/shared/lib/prisma` |
| `@/lib/mp-text` | `@/shared/lib/mp-text` |
| `@/lib/mp-api` | `@/shared/lib/mp-api` |
| `@/lib/api/*` | `@/shared/lib/api/*` |
| `@/lib/actions/players` | `@/shared/lib/actions/players` |
| `@/lib/auth/auth.config` | `@/features/auth/lib/auth.config` |
| `@/lib/auth/maniaplanet-provider` | `@/features/auth/lib/maniaplanet-provider` |
| `@/lib/auth/actions` | `@/features/auth/lib/actions` |
| `@/lib/auth/routes` | `@/features/auth/lib/routes` |
| `@/i18n/config` | `@/shared/i18n/config` |
| `@/i18n/request` | `@/shared/i18n/request` |
| `@/i18n/actions` | `@/shared/i18n/actions` |
| `@/test/intl-wrapper` | `@/shared/test/intl-wrapper` |
| `@/types/*` | `@/shared/types/*` |

## Steps

- [Done] Phase 1 - Create branch and directory structure
- [Done] Phase 2 - Move shared files
- [Done] Phase 3 - Move feature files
- [Done] Phase 4 - Extract page components into features
- [Done] Phase 5 - Update all import paths
- [Done] Phase 6 - Update config files
- [Done] Phase 7 - QA: tests + build

### Phase 1 - Create branch and directory structure

- [Done] P1.1 - Create git branch `feat/pm-domain-arch` from current
- [Done] P1.2 - Create all target directories

### Phase 2 - Move shared files (git mv)

- [Done] P2.1 - Move `src/types/` → `src/shared/types/`
- [Done] P2.2 - Move `src/test/` → `src/shared/test/`
- [Done] P2.3 - Move `src/i18n/` → `src/shared/i18n/`
- [Done] P2.4 - Move `src/lib/config.*` → `src/shared/lib/config.*`
- [Done] P2.5 - Move `src/lib/navigation.*` → `src/shared/lib/navigation.*`
- [Done] P2.6 - Move `src/lib/prisma.*` → `src/shared/lib/prisma.*`
- [Done] P2.7 - Move `src/lib/mp-text.*` → `src/shared/lib/mp-text.*`
- [Done] P2.8 - Move `src/lib/mp-api.ts` → `src/shared/lib/mp-api.ts`
- [Done] P2.9 - Move `src/lib/api/` → `src/shared/lib/api/`
- [Done] P2.10 - Move `src/lib/actions/` → `src/shared/lib/actions/`
- [Done] P2.11 - Move shared components (MpNickname, LanguageSelector, ErrorBoundary)

### Phase 3 - Move feature files (git mv)

- [Done] P3.1 - Move auth lib: `src/lib/auth/` → `src/features/auth/lib/`
- [Done] P3.2 - Move auth components: SignInForm, Providers → `src/features/auth/components/`
- [Done] P3.3 - Move navigation components: AppTopNav, UserMenu → `src/features/navigation/components/`

### Phase 4 - Extract page components into features

- [Done] P4.1 - Create `src/features/matchmaking/components/QuickMatchCard.tsx` (from play/page.tsx)
- [Done] P4.2 - Create `src/features/matchmaking/components/CustomLobbyCard.tsx` (from play/page.tsx)
- [Done] P4.3 - Create `src/features/profile/components/StatsCard.tsx` (from me/page.tsx)
- [Done] P4.4 - Create `src/features/profile/components/SettingsCard.tsx` (from me/page.tsx)
- [Done] P4.5 - Rewrite `app/play/page.tsx` as thin wrapper
- [Done] P4.6 - Rewrite `app/me/page.tsx` as thin wrapper

### Phase 5 - Update all import paths

- [Todo] P5.1 - Update `src/auth.ts`
- [Todo] P5.2 - Update `src/proxy.ts`
- [Todo] P5.3 - Update `src/app/layout.tsx`
- [Todo] P5.4 - Update `src/app/auth/signin/page.tsx`
- [Todo] P5.5 - Update all moved files (fix their own internal imports)
- [Todo] P5.6 - Update all test files

### Phase 6 - Update config files

- [Todo] P6.1 - Update `next.config.ts` (i18n path: `./src/shared/i18n/request.ts`)
- [Todo] P6.2 - Update `vitest.config.ts` (alias paths if needed)

### Phase 7 - QA

- [Todo] P7.1 - Run `npm test` and fix failures
- [Todo] P7.2 - Run `npm run build` and fix errors

## Success criteria

- All 135 tests pass
- Build green with all routes present
- No leftover files in old locations (src/components/, src/lib/, src/i18n/, src/test/, src/types/)

## Execution

**To execute this plan, use the `plan-execution` skill.**
