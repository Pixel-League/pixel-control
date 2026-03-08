# PLAN-2 - MobX Stores with Persistence (2026-03-08)

## Context

- **Purpose:** Replace ad-hoc `useState` in feature components with MobX observable stores, with persistence via `mobx-persist-store`.
- **Scope:** Matchmaking store (searching state + queue polling logic), auth store (session wrapper). Wire into Providers. Keep tests green.
- **Branch:** `feat/pm-mobx-stores` from `feat/pm-domain-arch`
- **Goals:**
  - `src/features/matchmaking/store/matchmakingStore.ts` — MobX observable: `searching`, `queueCount`. Actions: `startSearch`, `cancelSearch`. Autorun polling. Persist `searching` to localStorage.
  - `src/features/auth/store/authStore.ts` — thin observable wrapper: exposes `user` and `isLoading` from next-auth session (updated via reaction when session changes).
  - `Providers.tsx` updated to include MobX store initialization.
  - `QuickMatchCard.tsx` refactored to consume `matchmakingStore` via `observer()` instead of `useState`.
  - Tests updated/added for both stores.
  - 135+ tests pass, build green.
- **Non-goals:** E2E tests (Plan 3). Replacing next-auth entirely.
- **Constraints:**
  - `mobx-react-lite` for React integration (`observer`, `useLocalObservable`)
  - `mobx-persist-store` for localStorage persistence
  - `'use client'` required for all components using MobX
  - No inline TypeScript imports
  - `matchmakingStore` is a singleton (module-level instance)
  - `authStore` is updated externally (next-auth manages the actual session)

## Steps

- [Done] Phase 1 - Install dependencies
- [Done] Phase 2 - Create stores
- [Done] Phase 3 - Integrate stores into components
- [Done] Phase 4 - Update Providers
- [Done] Phase 5 - Tests
- [Done] Phase 6 - QA: tests + build

### Phase 1 - Install dependencies

- [Done] P1.1 - Install `mobx`, `mobx-react-lite`, `mobx-persist-store`

### Phase 2 - Create stores

- [Done] P2.1 - Create `src/features/matchmaking/store/matchmakingStore.ts`
  - Observable: `searching: boolean`, `queueCount: number | null`
  - Actions: `startSearch(login)`, `cancelSearch(login)`, `updateQueueCount(count)`
  - Polling autorun via `setInterval` started/stopped by actions
  - Persist `searching` to localStorage with `makePersistable`
- [Done] P2.2 - Create `src/features/auth/store/authStore.ts`
  - Simple observable: `user`, `isLoading`
  - Action: `setSession(session)` — called from component with next-auth session data

### Phase 3 - Integrate stores into components

- [Done] P3.1 - Refactor `QuickMatchCard.tsx` to use `matchmakingStore` via `observer()`
  - Replace `useState(searching)`, `useState(queueCount)`, `useEffect`, `useRef` with store calls
  - Keep `useSession()` to pass login to store actions
- [Done] P3.2 - (Optional) Wrap `AppTopNav.tsx` or `UserMenu.tsx` with `observer()` if needed

### Phase 4 - Update Providers

- [Done] P4.1 - Update `src/features/auth/components/Providers.tsx` to add `configure({ enforceActions: 'never' })` from mobx (permissive mode for SSR compatibility)

### Phase 5 - Tests

- [Done] P5.1 - Create `src/features/matchmaking/store/matchmakingStore.test.ts`
  - Test: initial state (searching=false, queueCount=null)
  - Test: startSearch sets searching=true, calls joinQueue
  - Test: cancelSearch sets searching=false, calls leaveQueue
  - Test: updateQueueCount updates queueCount
- [Done] P5.2 - Create `src/features/auth/store/authStore.test.ts`
  - Test: initial state
  - Test: setSession updates user and isLoading
- [Done] P5.3 - Update `QuickMatchCard` tests (if any exist or add new ones)

### Phase 6 - QA

- [Done] P6.1 - Run `npm test` and fix failures
- [Done] P6.2 - Run `npm run build` and fix errors

## Success criteria

- `matchmakingStore` drives `QuickMatchCard` state (no `useState` for searching/queue)
- `localStorage` persists `searching` across page reloads
- All tests pass, build green

## Execution

**To execute this plan, use the `plan-execution` skill.**
