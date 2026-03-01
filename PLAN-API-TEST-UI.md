# PLAN - Build interactive API test UI for Pixel Control (2026-03-01)

## Context

- **Purpose**: Build a standalone single-page application to interactively test all Pixel Control API endpoints. This replaces the raw Swagger experience with a visually appealing, dark-themed esport dashboard with forms, data displays, and formatted responses.
- **Scope**: Create `pixel-control-ui/` at the monorepo root — a React + Vite + Tailwind CSS SPA covering all implemented endpoints (30 endpoints Done, plus the event ingestion POST). The app is a dev tool only (no auth). Out of scope: endpoints with "Todo" status in `NEW_API_CONTRACT.md` (P3-P5 command/write endpoints).
- **Background**: The NestJS server at `pixel-control-server/` exposes Swagger docs at `http://localhost:3000/api/docs`, but the raw Swagger UI lacks a tailored UX — no server selector, no dashboard feel, no formatted combat stats or leaderboards.

### Goals
- Cover all 31 implemented API endpoints in an interactive UI.
- Provide a server selector that scopes all subsequent views to the selected server.
- Display data with proper formatting: leaderboards, color-coded badges, stat tables, pagination.
- Include an "Event Injector" to POST test events to `POST /v1/plugin/events`.
- Dark theme with esport aesthetic (dark backgrounds, accent colors).
- Works out of the box with `npm install && npm run dev`.

### Non-goals
- No SSR, no backend — purely a client-side SPA.
- No state management library (useState/useEffect sufficient).
- P3-P5 "Todo" endpoints (map skip/restart, veto, teams, whitelist, etc.) are out of scope for the initial build. They can be added later when implemented server-side.
- No authentication — this is a local dev tool.

### Constraints / assumptions
- API base URL defaults to `http://localhost:3000/v1`, configurable.
- CORS must be enabled on the NestJS server (`pixel-control-server/src/main.ts`) — currently it is NOT enabled.
- The NestJS server uses Fastify adapter, so CORS must be enabled via `app.enableCors()`.
- No inline TS imports — use static imports only.
- The server exposes Swagger JSON at `http://localhost:3000/api/docs-json` which can be used as reference, but the UI should call endpoints directly.

### Environment snapshot
- Branch: `main`
- Server: NestJS v11, Fastify, port 3000, prefix `/v1`
- Swagger at `/api/docs`, JSON at `/api/docs-json`
- No CORS currently configured in `main.ts`

### Risks / open questions
- None blocking — all endpoints are documented in `NEW_API_CONTRACT.md` and all 31 are implemented.

---

## Endpoints to cover (31 total, all implemented)

### Server Management (P0) — 6 endpoints
| # | Method | Endpoint | UI Element |
|---|--------|----------|------------|
| 1 | `PUT` | `/v1/servers/:serverLogin/link/registration` | Registration form (serverLogin, server_name, game_mode, title_id) |
| 2 | `POST` | `/v1/servers/:serverLogin/link/token` | Token rotate form (serverLogin, rotate checkbox) |
| 3 | `GET` | `/v1/servers/:serverLogin/link/auth-state` | Auth state card |
| 4 | `GET` | `/v1/servers/:serverLogin/link/access` | Access check card |
| 5 | `GET` | `/v1/servers` | Server list (card/table with status indicators) |
| 6 | `DELETE` | `/v1/servers/:serverLogin` | Delete button with confirmation modal |

### Event Ingestion (P1) — 1 endpoint
| # | Method | Endpoint | UI Element |
|---|--------|----------|------------|
| 7 | `POST` | `/v1/plugin/events` | Event injector form (category selector, JSON editor, headers) |

### Server Status (P1-P2) — 3 endpoints
| # | Method | Endpoint | UI Element |
|---|--------|----------|------------|
| 8 | `GET` | `/v1/servers/:serverLogin/status` | Status dashboard (player counts, online indicator) |
| 9 | `GET` | `/v1/servers/:serverLogin/status/health` | Health dashboard (queue, retry, outage) |
| 10 | `GET` | `/v1/servers/:serverLogin/status/capabilities` | Capabilities view |

### Players (P2) — 2 endpoints
| # | Method | Endpoint | UI Element |
|---|--------|----------|------------|
| 11 | `GET` | `/v1/servers/:serverLogin/players` | Player list table with connected/disconnected badges |
| 12 | `GET` | `/v1/servers/:serverLogin/players/:login` | Player detail card |

### Combat Stats (P2) — 5 endpoints
| # | Method | Endpoint | UI Element |
|---|--------|----------|------------|
| 13 | `GET` | `.../stats/combat` | Combat summary dashboard |
| 14 | `GET` | `.../stats/combat/players` | Player combat leaderboard (sortable table) |
| 15 | `GET` | `.../stats/combat/players/:login` | Player combat detail card |
| 16 | `GET` | `.../stats/combat/players/:login/maps` | Player map history (list with per-map stats) |
| 17 | `GET` | `.../stats/scores` | Latest scores display |

### Combat Maps (P2.5) — 3 endpoints
| # | Method | Endpoint | UI Element |
|---|--------|----------|------------|
| 18 | `GET` | `.../stats/combat/maps` | Map combat stats list (cards per map) |
| 19 | `GET` | `.../stats/combat/maps/:mapUid` | Single map detail |
| 20 | `GET` | `.../stats/combat/maps/:mapUid/players/:login` | Map-player detail |

### Combat Series (P2.5) — 1 endpoint
| # | Method | Endpoint | UI Element |
|---|--------|----------|------------|
| 21 | `GET` | `.../stats/combat/series` | Series list with map breakdowns |

### Elite Turns (P2.12-P2.15) — 4 endpoints
| # | Method | Endpoint | UI Element |
|---|--------|----------|------------|
| 22 | `GET` | `.../stats/combat/turns` | Turn list (table/timeline) |
| 23 | `GET` | `.../stats/combat/turns/:turnNumber` | Turn detail card |
| 24 | `GET` | `.../stats/combat/players/:login/clutches` | Clutch stats card |
| 25 | `GET` | `.../stats/combat/players/:login/turns` | Player turn history (paginated) |

### Lifecycle (P2) — 3 endpoints
| # | Method | Endpoint | UI Element |
|---|--------|----------|------------|
| 26 | `GET` | `.../lifecycle` | Current lifecycle state |
| 27 | `GET` | `.../lifecycle/map-rotation` | Map rotation view |
| 28 | `GET` | `.../lifecycle/aggregate-stats` | Aggregate stats |

### Maps & Mode (P2) — 2 endpoints
| # | Method | Endpoint | UI Element |
|---|--------|----------|------------|
| 29 | `GET` | `.../maps` | Map list |
| 30 | `GET` | `.../mode` | Mode info |

---

## Steps

- [Done] Phase 0 - Prerequisites and server-side CORS
- [Done] Phase 1 - Project scaffold (Vite + React + Tailwind)
- [Done] Phase 2 - Core layout and navigation
- [Done] Phase 3 - API client layer
- [Done] Phase 4 - Server management views (P0 endpoints)
- [Done] Phase 5 - Event injector
- [Done] Phase 6 - Status & health views
- [Done] Phase 7 - Player views
- [Done] Phase 8 - Combat stats views
- [Done] Phase 9 - Map & series combat views
- [Done] Phase 10 - Elite turn views
- [Done] Phase 11 - Lifecycle, maps, mode views
- [Done] Phase 12 - Polish and shared components
- [Done] Phase 13 - QA

---

### Phase 0 - Prerequisites and server-side CORS

- [Todo] P0.1 - Enable CORS on the NestJS server
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/main.ts`
  - Add `app.enableCors()` before `app.listen()`. This allows all origins in dev mode.
  - Do not restrict origins — this is a local dev tool.

### Phase 1 - Project scaffold

- [Todo] P1.1 - Initialize Vite + React + TypeScript project
  - Run `npm create vite@latest pixel-control-ui -- --template react-ts` from the monorepo root.
  - Verify `tsconfig.json` uses strict mode.
- [Todo] P1.2 - Install and configure Tailwind CSS v4
  - Install `tailwindcss @tailwindcss/vite` as dev dependencies.
  - Configure Vite plugin in `vite.config.ts`.
  - Set up `src/index.css` with `@import "tailwindcss"`.
  - Configure dark theme as default (use CSS custom properties or Tailwind dark mode class on `<html>`).
- [Todo] P1.3 - Install React Router
  - Install `react-router` (v7).
  - Set up `BrowserRouter` in `main.tsx`.
- [Todo] P1.4 - Set up project structure
  - Create directory layout:
    ```
    pixel-control-ui/src/
      api/            # API client functions
      components/     # Reusable UI components (Badge, Card, Table, JsonViewer, etc.)
      layouts/        # MainLayout with sidebar
      pages/          # One file per view/route
      hooks/          # Custom hooks (useApi, useServerContext, etc.)
      types/          # TypeScript types for API responses
      lib/            # Utility functions (formatters, constants)
    ```
- [Todo] P1.5 - Configure API base URL
  - Use Vite env variable `VITE_API_BASE_URL` defaulting to `http://localhost:3000/v1`.
  - Create `src/lib/config.ts` exporting `API_BASE_URL`.
- [Todo] P1.6 - Verify scaffold runs
  - `cd pixel-control-ui && npm install && npm run dev` starts without errors.

### Phase 2 - Core layout and navigation

- [Todo] P2.1 - Build `MainLayout` component
  - Sidebar navigation on the left (fixed, ~256px wide on desktop).
  - Main content area with top bar showing current page title and server selector.
  - Dark background: `bg-gray-950` or similar. Sidebar: `bg-gray-900`.
  - Accent colors: orange (`#F97316`) for primary actions, cyan (`#06B6D4`) for info/secondary.
- [Todo] P2.2 - Build sidebar navigation
  - Sections with icons (text-based icons or simple SVG):
    - **Servers** (server list, registration, delete)
    - **Players** (player list, player detail)
    - **Combat Stats** (combat summary, leaderboard, player detail, maps, series)
    - **Elite** (turns, turn detail, clutches, player turns)
    - **Lifecycle** (state, map rotation, aggregate stats)
    - **Maps & Mode** (map list, mode info)
    - **Event Injector** (send test events)
  - Active route highlight with accent color.
  - Collapsible on mobile (hamburger menu).
- [Todo] P2.3 - Build server selector component
  - Dropdown at the top of the content area.
  - Fetches `GET /v1/servers` on mount.
  - Once a server is selected, stores `serverLogin` in React context.
  - All server-scoped pages read from this context.
  - Show online/offline badge next to each server in the dropdown.
- [Todo] P2.4 - Set up React Router routes
  - Define routes:
    ```
    /                           → ServerList
    /servers                    → ServerList
    /servers/register           → ServerRegister
    /servers/:serverLogin       → ServerDetail (auth-state + access + status)
    /players                    → PlayerList (requires server context)
    /players/:login             → PlayerDetail
    /stats/combat               → CombatSummary
    /stats/combat/players       → CombatLeaderboard
    /stats/combat/players/:login → PlayerCombatDetail
    /stats/combat/players/:login/maps → PlayerMapHistory
    /stats/combat/maps          → MapCombatList
    /stats/combat/maps/:mapUid  → MapCombatDetail
    /stats/combat/maps/:mapUid/players/:login → MapPlayerDetail
    /stats/combat/series        → SeriesList
    /stats/combat/turns         → EliteTurnList
    /stats/combat/turns/:turnNumber → EliteTurnDetail
    /stats/combat/players/:login/clutches → PlayerClutches
    /stats/combat/players/:login/turns → PlayerTurnHistory
    /stats/scores               → LatestScores
    /lifecycle                  → LifecycleState
    /lifecycle/map-rotation     → MapRotation
    /lifecycle/aggregate-stats  → AggregateStats
    /maps                       → MapList
    /mode                       → ModeInfo
    /status                     → ServerStatus
    /status/health              → HealthDashboard
    /status/capabilities        → Capabilities
    /event-injector             → EventInjector
    /token                      → TokenRotate
    ```

### Phase 3 - API client layer

- [Todo] P3.1 - Create typed API client functions
  - File: `src/api/client.ts` — base fetch wrapper with error handling.
    - `apiGet<T>(path: string): Promise<T>` — GET with JSON parsing.
    - `apiPost<T>(path: string, body?: unknown, headers?: Record<string, string>): Promise<T>` — POST.
    - `apiPut<T>(path: string, body?: unknown): Promise<T>` — PUT.
    - `apiDelete<T>(path: string): Promise<T>` — DELETE.
    - All functions prepend `API_BASE_URL`.
    - Return `{ data, error, status }` shape for consistent handling.
- [Todo] P3.2 - Create domain-specific API modules
  - `src/api/servers.ts` — `listServers()`, `registerServer()`, `rotateToken()`, `getAuthState()`, `getAccess()`, `deleteServer()`.
  - `src/api/events.ts` — `sendEvent()`.
  - `src/api/status.ts` — `getStatus()`, `getHealth()`, `getCapabilities()`.
  - `src/api/players.ts` — `listPlayers()`, `getPlayer()`.
  - `src/api/stats.ts` — `getCombatStats()`, `getCombatPlayers()`, `getPlayerCombatCounters()`, `getPlayerMapHistory()`, `getScores()`.
  - `src/api/maps.ts` — `getMapCombatStatsList()`, `getMapCombatStats()`, `getMapPlayerCombatStats()`, `getSeriesCombatStatsList()`.
  - `src/api/elite.ts` — `getEliteTurns()`, `getEliteTurnByNumber()`, `getPlayerClutchStats()`, `getPlayerTurnHistory()`.
  - `src/api/lifecycle.ts` — `getLifecycleState()`, `getMapRotation()`, `getAggregateStats()`.
  - `src/api/mapList.ts` — `getMapList()`.
  - `src/api/mode.ts` — `getModeInfo()`.
- [Todo] P3.3 - Create TypeScript types for API responses
  - File: `src/types/api.ts` — types matching `NEW_API_CONTRACT.md` response shapes.
  - Key types: `Server`, `PlayerCountersDelta`, `MapCombatStatsEntry`, `SeriesCombatEntry`, `EliteTurnSummary`, `EliteClutchTurnRef`, `PlayerEliteTurnEntry`, `EventEnvelope`, `AckResponse`.
- [Todo] P3.4 - Create `useApi` custom hook
  - Generic hook: `useApi<T>(fetcher: () => Promise<T>, deps?: unknown[])`.
  - Returns `{ data, loading, error, refetch }`.
  - Handles loading states, error states, and dependency-driven re-fetching.

### Phase 4 - Server management views

- [Todo] P4.1 - Server list page (`/servers`)
  - Fetch `GET /v1/servers`.
  - Display as a table or card grid:
    - Server login (bold), server name, game mode, title ID.
    - Online/offline badge (green/red).
    - Linked/unlinked badge.
    - Last heartbeat (relative time).
    - Plugin version.
  - Click row to navigate to server detail.
  - "Register new server" button linking to `/servers/register`.
- [Todo] P4.2 - Server registration form (`/servers/register`)
  - Form fields: serverLogin (required), server_name (optional), game_mode (optional dropdown: Elite, Siege, Battle, Joust, Custom), title_id (optional).
  - Submit calls `PUT /v1/servers/:serverLogin/link/registration`.
  - Display response (registered, link_token) in a success card.
  - Copy-to-clipboard button for the link token.
- [Todo] P4.3 - Server detail page (`/servers/:serverLogin`)
  - Tab or card layout showing:
    - Auth state (from `GET .../link/auth-state`): linked, online, last_heartbeat, plugin_version.
    - Access (from `GET .../link/access`): access check result.
  - "Delete Server" button with confirmation modal calling `DELETE /v1/servers/:serverLogin`.
- [Todo] P4.4 - Token rotation form
  - Accessible from server detail page.
  - Form: serverLogin (pre-filled from context), rotate toggle.
  - Calls `POST /v1/servers/:serverLogin/link/token`.
  - Displays the new token with copy-to-clipboard.

### Phase 5 - Event injector (`/event-injector`)

- [Todo] P5.1 - Build event injector page
  - Server login input (pre-filled from server context if set).
  - Category selector dropdown: `connectivity`, `lifecycle`, `combat`, `player`, `mode`, `batch`.
  - Plugin version header input (default: `1.0.0`).
  - JSON editor textarea for the full event envelope body.
    - Provide template/example JSON for each category (auto-fill on category change).
    - Syntax highlighting if feasible (use a `<pre>` with colored text or a lightweight library).
  - "Send Event" button calling `POST /v1/plugin/events` with headers `X-Pixel-Server-Login`, `X-Pixel-Plugin-Version`.
  - Response display: show ack status, disposition, or error in a result card below the form.
  - History: keep a log of recently sent events and their responses (in-memory, session only).

### Phase 6 - Status & health views

- [Todo] P6.1 - Server status page (`/status`)
  - Fetch `GET /v1/servers/:serverLogin/status`.
  - Display: server login, online indicator (large badge), game mode, title ID, current map.
  - Player counts: active, total, spectators (as stat cards with icons).
  - Event counts by category (if available in response).
- [Todo] P6.2 - Health dashboard (`/status/health`)
  - Fetch `GET /v1/servers/:serverLogin/status/health`.
  - Display:
    - Queue depth gauge or bar (current depth vs max).
    - Retry state: max attempts, backoff, current failures.
    - Outage state: active/inactive badge, failure count, recovery time.
    - Connectivity metrics: last heartbeat, uptime.
- [Todo] P6.3 - Capabilities page (`/status/capabilities`)
  - Fetch `GET /v1/servers/:serverLogin/status/capabilities`.
  - Display capabilities as a structured list or JSON tree.

### Phase 7 - Player views

- [Todo] P7.1 - Player list page (`/players`)
  - Fetch `GET /v1/servers/:serverLogin/players`.
  - Table with columns: login, nickname, team (color-coded), connected/disconnected badge, spectator badge, auth level.
  - Click row to navigate to player detail.
- [Todo] P7.2 - Player detail page (`/players/:login`)
  - Fetch `GET /v1/servers/:serverLogin/players/:login`.
  - Card layout with all player fields: login, nickname, team, spectator status, auth level, connectivity state, readiness, eligibility.
  - Link to player combat stats (`/stats/combat/players/:login`).

### Phase 8 - Combat stats views

- [Todo] P8.1 - Combat summary dashboard (`/stats/combat`)
  - Fetch `GET /v1/servers/:serverLogin/stats/combat`.
  - Stat cards: total events, total kills, total deaths, total hits, total shots, accuracy %.
  - Event kind breakdown (bar chart or badge list).
  - Time range filter inputs (since/until).
- [Todo] P8.2 - Player combat leaderboard (`/stats/combat/players`)
  - Fetch `GET /v1/servers/:serverLogin/stats/combat/players`.
  - Sortable table with columns: login, kills, deaths, K/D, accuracy %, rocket accuracy %, laser accuracy %, attack win rate %, defense win rate %.
  - Default sort: kills descending.
  - Click row to navigate to player combat detail.
  - Pagination controls.
- [Todo] P8.3 - Player combat detail card (`/stats/combat/players/:login`)
  - Fetch `GET /v1/servers/:serverLogin/stats/combat/players/:login`.
  - Full stat card: all counter fields formatted nicely.
  - Accuracy as progress bars or gauges.
  - Links to: player map history, clutch stats, turn history.
- [Todo] P8.4 - Player map history (`/stats/combat/players/:login/maps`)
  - Fetch `GET /v1/servers/:serverLogin/stats/combat/players/:login/maps`.
  - Top-level stats: maps_played, maps_won, win_rate %.
  - List of map entries: map name, played_at (relative time), duration, key counters, win/loss badge.
  - Pagination controls.
- [Todo] P8.5 - Latest scores display (`/stats/scores`)
  - Fetch `GET /v1/servers/:serverLogin/stats/scores`.
  - Display scores section (EndRound/EndMap/EndMatch badge).
  - Team scores side by side.
  - Player scores table with rank/points.
  - Result state badge (team_win, tie, draw, etc.).

### Phase 9 - Map & series combat views

- [Todo] P9.1 - Map combat stats list (`/stats/combat/maps`)
  - Fetch `GET /v1/servers/:serverLogin/stats/combat/maps`.
  - Card grid or table: map name, played_at, duration, totals (kills/deaths), win context.
  - Click to navigate to map detail.
  - Pagination and time range filters.
- [Todo] P9.2 - Map combat detail (`/stats/combat/maps/:mapUid`)
  - Fetch `GET /v1/servers/:serverLogin/stats/combat/maps/:mapUid`.
  - Map header: name, UID, played_at, duration.
  - Player stats table within the map (all PlayerCountersDelta fields).
  - Team stats display.
  - Win context badge.
- [Todo] P9.3 - Map-player detail (`/stats/combat/maps/:mapUid/players/:login`)
  - Fetch `GET /v1/servers/:serverLogin/stats/combat/maps/:mapUid/players/:login`.
  - Full counter card for the player on that specific map.
- [Todo] P9.4 - Series list (`/stats/combat/series`)
  - Fetch `GET /v1/servers/:serverLogin/stats/combat/series`.
  - Each series as a card: start/end times, total maps played, series totals, series win context.
  - Expandable: show map cards within each series.
  - Pagination and time range filters.

### Phase 10 - Elite turn views

- [Todo] P10.1 - Elite turn list (`/stats/combat/turns`)
  - Fetch `GET /v1/servers/:serverLogin/stats/combat/turns`.
  - Table or timeline view: turn number, attacker, defenders, outcome badge (capture/eliminated/time_limit), duration, clutch indicator.
  - Color-coded outcome badges: green for defense success, red for attack success.
  - Click to navigate to turn detail.
  - Pagination and time range filters.
- [Todo] P10.2 - Elite turn detail (`/stats/combat/turns/:turnNumber`)
  - Fetch `GET /v1/servers/:serverLogin/stats/combat/turns/:turnNumber`.
  - Full turn card: attacker, defenders, outcome, duration, map info.
  - Per-player stats table within the turn (kills, deaths, hits, shots, misses, rocket_hits).
  - Clutch info card (if is_clutch: clutch player, alive defenders, total defenders).
- [Todo] P10.3 - Player clutch stats (`/stats/combat/players/:login/clutches`)
  - Fetch `GET /v1/servers/:serverLogin/stats/combat/players/:login/clutches`.
  - Top stat cards: clutch_count, total_defense_rounds, clutch_rate %.
  - Clutch turns list: turn number, map, recorded_at, outcome.
- [Todo] P10.4 - Player turn history (`/stats/combat/players/:login/turns`)
  - Fetch `GET /v1/servers/:serverLogin/stats/combat/players/:login/turns`.
  - Table: turn number, role (attacker/defender badge), stats summary, outcome, clutch indicator.
  - Pagination controls.

### Phase 11 - Lifecycle, maps, mode views

- [Todo] P11.1 - Lifecycle state page (`/lifecycle`)
  - Fetch `GET /v1/servers/:serverLogin/lifecycle`.
  - Display: current phase, variant, warmup/pause state, source channel.
  - Visual phase indicator (match > map > round progression).
- [Todo] P11.2 - Map rotation page (`/lifecycle/map-rotation`)
  - Fetch `GET /v1/servers/:serverLogin/lifecycle/map-rotation`.
  - Current map card (highlighted).
  - Map pool list with next maps indicated.
  - Played map order (timeline or numbered list).
  - Series targets display (best_of, maps_score).
  - Veto state badges.
- [Todo] P11.3 - Aggregate stats page (`/lifecycle/aggregate-stats`)
  - Fetch `GET /v1/servers/:serverLogin/lifecycle/aggregate-stats`.
  - Scope indicator (round/map).
  - Player counters delta table.
  - Totals, team counters, win context.
- [Todo] P11.4 - Map list page (`/maps`)
  - Fetch `GET /v1/servers/:serverLogin/maps`.
  - Simple table or card list of maps in the pool.
- [Todo] P11.5 - Mode info page (`/mode`)
  - Fetch `GET /v1/servers/:serverLogin/mode`.
  - Display current game mode, recent mode events if available.

### Phase 12 - Polish and shared components

- [Todo] P12.1 - Build reusable components
  - `Badge` — color-coded status badges (online/offline, connected/disconnected, win/loss, outcome types).
  - `StatCard` — numeric stat with label and optional icon.
  - `DataTable` — sortable, paginated table with column definitions.
  - `JsonViewer` — collapsible raw JSON display for debugging (toggle per response).
  - `Pagination` — page controls (prev/next, page number, items per page).
  - `LoadingSpinner` — consistent loading state.
  - `ErrorBanner` — error display with retry button.
  - `ConfirmModal` — confirmation dialog for destructive actions (delete server).
  - `CopyButton` — copy-to-clipboard for tokens/values.
  - `TimeRangeFilter` — since/until date inputs.
  - Note: these components should be built incrementally during Phases 4-11, then consolidated here. This step is for final polish and consistency pass.
- [Todo] P12.2 - Add raw JSON toggle to all data pages
  - Every page that displays API data should have a "Show raw JSON" toggle.
  - Use the `JsonViewer` component.
- [Todo] P12.3 - Responsive design pass
  - Sidebar collapses to hamburger on screens < 768px.
  - Tables become scrollable on small screens.
  - Stat cards stack vertically on mobile.
- [Todo] P12.4 - Loading and error states
  - Every page shows a loading spinner while fetching.
  - API errors show the error banner with status code and message.
  - Empty states: "No data available" messages with appropriate context.
- [Todo] P12.5 - Favicon and page title
  - Set page title to "Pixel Control - API Test UI".
  - Add a simple favicon (can be a generic one or the project icon if available).

### Phase 13 - QA

- [Todo] P13.1 - Build verification
  - `cd /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-ui && npm install && npm run build` must succeed with zero errors and zero TypeScript errors.
- [Todo] P13.2 - Dev server verification
  - `npm run dev` starts successfully.
  - App loads in browser without console errors.
  - All routes render without crashes.
- [Todo] P13.3 - CORS verification
  - Start the NestJS server (`cd pixel-control-server && npm run start:dev`).
  - Verify the UI dev server can call `GET /v1/servers` without CORS errors.
  - If CORS fails, debug and fix the server-side configuration.
- [Todo] P13.4 - Regression: existing server tests
  - `cd /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server && npm run test`
  - All Vitest tests must pass (currently 272+ tests across 10+ spec files).
- [Todo] P13.5 - Regression: smoke scripts
  - Run all existing smoke scripts from `pixel-control-server/scripts/`:
    - `qa-p0-smoke.sh`
    - `qa-p1-smoke.sh`
    - `qa-p2-smoke.sh`
    - `qa-p2.5-smoke.sh`
    - `qa-p2.6-smoke.sh`
    - `qa-p2.6-elite-smoke.sh`
    - `qa-elite-enrichment-smoke.sh`
    - `qa-full-integration.sh`
  - All must pass without regressions.
- [Todo] P13.6 - Endpoint coverage audit
  - Verify all 31 endpoints from the table above have a corresponding UI page.
  - Each page must call the correct endpoint and display the response.

---

## Tech stack

| Tool | Version | Purpose |
|------|---------|---------|
| React | 19 | UI framework |
| Vite | 6 | Build tool / dev server |
| TypeScript | 5.x | Type safety |
| Tailwind CSS | v4 | Styling (dark theme) |
| React Router | v7 | Client-side routing |

No additional dependencies needed (no axios, no state management library, no UI component library).

---

## Success criteria

- All 31 implemented API endpoints are accessible and testable from the UI.
- `npm install && npm run dev` works out of the box from `pixel-control-ui/`.
- `npm run build` succeeds with zero errors.
- Dark theme with esport aesthetic is applied consistently.
- Server selector works and scopes all views.
- Event injector can send test events and display responses.
- All existing server tests and smoke scripts pass without regressions.
- CORS works between UI dev server and NestJS API.

## Evidence / Artifacts

- `pixel-control-ui/` — the new SPA directory
- `pixel-control-server/src/main.ts` — CORS configuration change

## Notes / outcomes

- CORS enabled on NestJS server via `app.enableCors()` in `pixel-control-server/src/main.ts` (requires server restart).
- UI built at `pixel-control-ui/` — Vite 7 + React 19 + TypeScript + Tailwind CSS v4 + React Router v7.
- All 30+ endpoints covered across 25+ page components + 10 reusable components.
- Build: `npm run build` completes with zero errors (93 modules, 317KB JS, 29KB CSS).
- Server tests: 272/272 pass. All 8 smoke scripts pass (P0: 43, P1: 35, P2: 94, P2.5: 59, P2.6: 29, P2.6-elite: 21, elite-enrichment: 53, full-integration: 255 assertions).
- Start UI dev server: `cd pixel-control-ui && npm install && npm run dev`.
- API base URL configurable via `VITE_API_BASE_URL` env var (default: `http://localhost:3000/v1`).
