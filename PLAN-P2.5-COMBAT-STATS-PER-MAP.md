# PLAN - P2.5 Per-Map and Per-Series Combat Stats Endpoints (2026-02-28)

## Context

- **Purpose**: Add four new read-only endpoints that expose per-map and per-series (Best-Of) combat statistics. The plugin already sends all required data embedded in lifecycle events -- the server just needs to extract, reshape, and serve it.
- **Scope**:
  - Four new GET endpoints under `/v1/servers/:serverLogin/stats/combat/maps` and `.../stats/combat/series`.
  - Extend the existing `StatsReadModule` (service + controller + tests). No new NestJS module.
  - Update `NEW_API_CONTRACT.md` with the new endpoints.
  - QA smoke test (extend `qa-p2-smoke.sh` or create a dedicated `qa-p2.5-smoke.sh`).
- **Background / Findings**:
  - P2 Read API is complete (12 endpoints). These 4 endpoints are a refinement under the Stats domain.
  - Lifecycle events with `payload.variant === "map.end"` carry `payload.aggregate_stats` with `scope: "map"` containing per-player `player_counters_delta`, `team_counters_delta`, `totals`, `window`, and `win_context`. Map identity is in `payload.map_rotation.current_map` (`uid`, `name`, `file`, `environment`).
  - Series (match) boundaries are lifecycle events with `payload.variant === "match.begin"` and `payload.variant === "match.end"` ordered by `sourceTime`.
  - The `Event` table stores all lifecycle events with `eventCategory = 'lifecycle'`. Filtering is done in TypeScript by inspecting `payload` JSON (consistent with existing P2 patterns in `LifecycleReadService` and `StatsReadService`).
- **Goals**:
  - Endpoint 1: List recent completed maps with per-player combat stats (paginated, time-filterable).
  - Endpoint 2: Get combat stats for a specific map UID (latest occurrence).
  - Endpoint 3: Get a single player's combat stats on a specific map UID.
  - Endpoint 4: List completed series (BO) with per-map breakdowns within each series.
- **Non-goals**:
  - No database schema changes (no new tables, no new indexes, no migrations).
  - No changes to ingestion pipeline.
  - No new NestJS module -- all code goes into the existing `StatsReadModule`.
- **Constraints / assumptions**:
  - Map identity is `payload.map_rotation.current_map.uid`. This is always present on `map.end` lifecycle events.
  - Series identification relies on pairing `match.begin` and `match.end` events by `sourceTime` ordering. A series is "complete" when both events exist; an open series (no `match.end`) can optionally be returned with a flag.
  - The existing pattern fetches up to 200 lifecycle events ordered by `sourceTime DESC`. For map/series endpoints we may need a higher `take` limit (e.g., 1000) to cover historical data. This is controlled by pagination query params.
  - Swagger/OpenAPI descriptions are mandatory on all new routes.
  - All DTO classes use `field!: type` (definite assignment assertion).
  - No inline TS imports.
- **Environment snapshot**:
  - Branch: `main`
  - Key files:
    - `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.ts`
    - `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.controller.ts`
    - `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.module.ts`
    - `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.spec.ts`
    - `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.controller.spec.ts`
    - `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/lifecycle/lifecycle-read.service.ts` (reference for `RawLifecyclePayload` interface)
    - `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/common/dto/query-params.dto.ts` (reuse `PaginatedTimeRangeQueryDto`)
    - `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/common/dto/read-response.dto.ts` (reuse `PaginatedResponse`, `paginate()`)
    - `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/NEW_API_CONTRACT.md`

## Steps

- [Done] Phase 1 - TypeScript interfaces and service methods
- [Done] Phase 2 - Controller routes with Swagger documentation
- [Done] Phase 3 - Unit tests (service + controller)
- [Done] Phase 4 - Contract documentation update
- [Done] Phase 5 - Live QA smoke testing

### Phase 1 - TypeScript interfaces and service methods

Add interfaces and four new methods to `StatsReadService` in `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.ts`.

- [Todo] P1.1 - Add `RawLifecyclePayload` interface to `stats-read.service.ts`
  - Define a local `RawLifecyclePayload` interface (or import/share from `lifecycle-read.service.ts` if practical -- but since the existing codebase defines local interfaces per service, follow that pattern and define it locally).
  - Must include: `variant?: string`, `aggregate_stats?` (with `scope`, `counter_scope`, `player_counters_delta`, `team_counters_delta`, `totals`, `tracked_player_count`, `window`, `win_context`, `window_state`, `counter_keys`), `map_rotation?` (with `current_map` containing `uid`, `name`, `file`, `environment`).
  - This is the same shape as in `lifecycle-read.service.ts` lines 66-96 but with the specific sub-fields needed for combat extraction.

- [Todo] P1.2 - Add response interfaces for the four endpoints
  - `MapCombatStatsEntry`: `map_uid`, `map_name`, `played_at` (ISO8601), `duration_seconds`, `player_stats` (Record<string, PlayerCountersDelta>), `team_stats` (array), `totals` (Record<string, number>), `win_context` (Record<string, unknown>), `event_id`.
  - `PlayerCountersDelta`: `kills`, `deaths`, `hits`, `shots`, `misses`, `rockets`, `lasers`, `accuracy` (all numbers).
  - `MapCombatStatsListResponse`: `server_login`, `maps` (MapCombatStatsEntry[]), plus pagination fields (`total`, `limit`, `offset`).
  - `MapPlayerCombatStatsResponse`: `server_login`, `map_uid`, `map_name`, `player_login`, `counters` (PlayerCountersDelta), `played_at`.
  - `SeriesCombatEntry`: `match_started_at`, `match_ended_at`, `total_maps_played`, `maps` (MapCombatStatsEntry[]), `series_totals` (Record<string, number>), `series_win_context` (Record<string, unknown> | null).
  - `SeriesCombatListResponse`: `server_login`, `series` (SeriesCombatEntry[]), plus pagination fields.

- [Todo] P1.3 - Implement `getMapCombatStatsList()` method
  - Signature: `async getMapCombatStatsList(serverLogin: string, limit: number, offset: number, since?: string, until?: string): Promise<MapCombatStatsListResponse>`
  - Use `serverResolver.resolve(serverLogin)` to get server.
  - Query `Event` table: `where: { serverId, eventCategory: 'lifecycle' }`, `orderBy: { sourceTime: 'desc' }`, `take: 1000` (generous limit to capture map history).
  - Filter in TypeScript: events where `payload.variant === 'map.end'` AND `payload.aggregate_stats?.scope === 'map'`.
  - Apply time-range filter if `since`/`until` provided (compare `sourceTime` as BigInt).
  - For each matching event, extract:
    - `map_uid` from `payload.map_rotation.current_map.uid`
    - `map_name` from `payload.map_rotation.current_map.name`
    - `played_at` from `new Date(Number(event.sourceTime)).toISOString()`
    - `duration_seconds` from `payload.aggregate_stats.window.duration_seconds` (or compute from `ended_at - started_at`)
    - `player_stats` from `payload.aggregate_stats.player_counters_delta`
    - `team_stats` from `payload.aggregate_stats.team_counters_delta`
    - `totals` from `payload.aggregate_stats.totals`
    - `win_context` from `payload.aggregate_stats.win_context`
    - `event_id` from `event.eventId`
  - Apply pagination (slice by offset/limit) and return.

- [Todo] P1.4 - Implement `getMapCombatStats()` method
  - Signature: `async getMapCombatStats(serverLogin: string, mapUid: string): Promise<MapCombatStatsEntry>`
  - Same event query pattern as P1.3 but find the latest `map.end` event where `payload.map_rotation.current_map.uid === mapUid`.
  - If not found, throw `NotFoundException` with descriptive message.

- [Todo] P1.5 - Implement `getMapPlayerCombatStats()` method
  - Signature: `async getMapPlayerCombatStats(serverLogin: string, mapUid: string, playerLogin: string): Promise<MapPlayerCombatStatsResponse>`
  - Reuse the `getMapCombatStats()` method to get the map entry, then extract the specific player's counters from `player_stats`.
  - If the player is not found in the map's `player_stats`, throw `NotFoundException`.

- [Todo] P1.6 - Implement `getSeriesCombatStatsList()` method
  - Signature: `async getSeriesCombatStatsList(serverLogin: string, limit: number, offset: number, since?: string, until?: string): Promise<SeriesCombatListResponse>`
  - Query lifecycle events: `take: 2000` (series span multiple maps).
  - Algorithm:
    1. Collect all lifecycle events sorted by `sourceTime` ascending (reverse the DESC result).
    2. Identify series boundaries: find pairs of `match.begin` and `match.end` events.
    3. For each complete series (both begin and end exist):
       a. Find all `map.end` events with `aggregate_stats.scope === 'map'` whose `sourceTime` falls between the match begin and end times.
       b. Build `MapCombatStatsEntry[]` for each map in the series.
       c. Compute `series_totals` by summing all map totals across the series.
       d. Extract `series_win_context` from the `match.end` event's `payload.aggregate_stats.win_context` (if present) or from `payload.win_context`.
    4. Sort series by `match_started_at` descending (most recent first).
    5. Apply time-range filter and pagination.

- [Todo] P1.7 - Extract shared helper: `extractMapCombatEntry()`
  - Private helper method in `StatsReadService` that takes an `Event` row and returns a `MapCombatStatsEntry`.
  - Avoids code duplication between P1.3, P1.4, and P1.6 which all need to build `MapCombatStatsEntry` from a `map.end` lifecycle event.

### Phase 2 - Controller routes with Swagger documentation

Add four new routes to `StatsReadController` in `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.controller.ts`.

- [Todo] P2.1 - Add `GET :serverLogin/stats/combat/maps` route
  - Method: `getCombatMaps(@Param('serverLogin') serverLogin: string, @Query() query: PaginatedTimeRangeQueryDto)`
  - Swagger: `@ApiTags('Stats')`, `@ApiOperation({ summary: 'List per-map combat stats', description: 'Returns combat statistics broken down by completed map...' })`, `@ApiParam` for serverLogin, `@ApiQuery` for limit/offset/since/until, `@ApiResponse` for 200 and 404.
  - Delegates to `statsService.getMapCombatStatsList(...)`.

- [Todo] P2.2 - Add `GET :serverLogin/stats/combat/maps/:mapUid` route
  - Method: `getCombatMapByUid(@Param('serverLogin') serverLogin: string, @Param('mapUid') mapUid: string)`
  - Swagger: `@ApiOperation({ summary: 'Get combat stats for a specific map', description: '...' })`, `@ApiParam` for serverLogin + mapUid, `@ApiResponse` for 200 and 404.
  - Delegates to `statsService.getMapCombatStats(...)`.
  - Wrap in try/catch to surface `NotFoundException` cleanly (same pattern as existing `getPlayerCombatCounters`).

- [Todo] P2.3 - Add `GET :serverLogin/stats/combat/maps/:mapUid/players/:login` route
  - Method: `getCombatMapPlayer(@Param('serverLogin') serverLogin: string, @Param('mapUid') mapUid: string, @Param('login') login: string)`
  - Swagger: full documentation with all three params.
  - Delegates to `statsService.getMapPlayerCombatStats(...)`.
  - try/catch for NotFoundException.

- [Todo] P2.4 - Add `GET :serverLogin/stats/combat/series` route
  - Method: `getCombatSeries(@Param('serverLogin') serverLogin: string, @Query() query: PaginatedTimeRangeQueryDto)`
  - Swagger: `@ApiOperation({ summary: 'List per-series (Best-Of) combat stats', description: 'Returns combat statistics grouped by completed series/match...' })`.
  - Delegates to `statsService.getSeriesCombatStatsList(...)`.

### Phase 3 - Unit tests (service + controller)

Add tests to the existing spec files.

- [Todo] P3.1 - Service tests for `getMapCombatStatsList()` in `stats-read.service.spec.ts`
  - Add a `makeLifecycleEvent()` factory that builds a lifecycle event with configurable `variant`, `aggregate_stats`, and `map_rotation`.
  - Test cases:
    - Returns empty maps array when no lifecycle events exist.
    - Returns map combat stats from `map.end` events with `aggregate_stats.scope === 'map'`.
    - Ignores lifecycle events that are not `map.end` (e.g., `round.end`, `map.begin`).
    - Ignores `map.end` events without `aggregate_stats`.
    - Applies pagination (limit/offset).
    - Applies time-range filtering (since/until).
    - Extracts correct `map_uid`, `map_name`, `player_stats`, `team_stats`, `totals`, `win_context`.

- [Todo] P3.2 - Service tests for `getMapCombatStats()` (single map by UID)
  - Returns stats for matching `map_uid`.
  - Throws `NotFoundException` when no `map.end` event found for the given UID.
  - Returns the latest occurrence when multiple `map.end` events exist for the same UID.

- [Todo] P3.3 - Service tests for `getMapPlayerCombatStats()` (single player on map)
  - Returns player counters when player exists in the map's `player_counters_delta`.
  - Throws `NotFoundException` when player not found on the map.
  - Throws `NotFoundException` when map UID not found.

- [Todo] P3.4 - Service tests for `getSeriesCombatStatsList()`
  - Returns empty series array when no match begin/end events exist.
  - Correctly pairs `match.begin` and `match.end` events into a series.
  - Includes only `map.end` events that fall within each series time window.
  - Computes `series_totals` by summing map totals.
  - Applies pagination.
  - Handles incomplete series (match.begin without match.end) gracefully (excludes them).

- [Todo] P3.5 - Controller tests for all four new routes in `stats-read.controller.spec.ts`
  - Add stubs for the four new service methods in `makeServiceStub()`.
  - Test that each controller method delegates to the correct service method with correct arguments.
  - Test that `NotFoundException` propagates correctly for the 404 cases.

- [Todo] P3.6 - Run full test suite: `cd pixel-control-server && npm run test`
  - All existing tests (96+) must pass alongside the new tests.
  - Verify zero regressions.

### Phase 4 - Contract documentation update

- [Todo] P4.1 - Update `NEW_API_CONTRACT.md` endpoint tables
  - In section 4.3 (Read Endpoints), add four new rows after the existing P2.5 entry:
    - `GET .../stats/combat/maps` | lifecycle | Per-map combat stats list | Done | P2.5.1
    - `GET .../stats/combat/maps/:mapUid` | lifecycle | Combat stats for specific map | Done | P2.5.2
    - `GET .../stats/combat/maps/:mapUid/players/:login` | lifecycle | Player combat stats on specific map | Done | P2.5.3
    - `GET .../stats/combat/series` | lifecycle | Per-series (BO) combat stats | Done | P2.5.4
  - Source category is `lifecycle` (data comes from lifecycle events, even though the endpoint path is under `stats/combat`).

- [Todo] P4.2 - Add detailed endpoint documentation
  - Add a new subsection (e.g., section 2.8 or an addendum within the combat stats section) documenting:
    - Request format (path params, query params) for each endpoint.
    - Response shape (JSON structure) for each endpoint.
    - How data is derived (from lifecycle `map.end` events with `aggregate_stats.scope === 'map'`).
    - Series correlation logic (match.begin/match.end pairing).

### Phase 5 - Live QA smoke testing

- [Todo] P5.1 - Create `qa-p2.5-smoke.sh` smoke test script
  - Location: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/scripts/qa-p2.5-smoke.sh`
  - Reuse the helper pattern from `qa-p2-smoke.sh` (assert_status, assert_jq, assert_jq_gte, assert_jq_not_null, make_envelope, send_event).
  - **Section 0 - Seed data**:
    - Register server via `PUT /v1/servers/:serverLogin/link/registration`.
    - Send a `match.begin` lifecycle event with `map_rotation` (map pool of 3 maps, series_targets: best_of: 3).
    - Send a `map.begin` lifecycle event (map 1: uid-alpha, "Alpha Arena").
    - Send a `map.end` lifecycle event with `aggregate_stats.scope: "map"`, `player_counters_delta` for 2 players, `team_counters_delta` for 2 teams, `totals`, `window`, `win_context`, and `map_rotation.current_map: { uid: "uid-alpha", name: "Alpha Arena" }`.
    - Send a second `map.begin` (map 2: uid-bravo, "Bravo Stadium").
    - Send a second `map.end` with different stats for map 2.
    - Send a `match.end` lifecycle event.
  - **Section 1 - GET /stats/combat/maps**:
    - Assert HTTP 200.
    - Assert response contains 2 maps.
    - Assert first map (most recent) has `map_uid: "uid-bravo"`.
    - Assert second map has `map_uid: "uid-alpha"`.
    - Assert `player_stats` contains expected player logins.
    - Assert `totals` values match seeded data.
    - Assert pagination fields present.
  - **Section 2 - GET /stats/combat/maps with pagination**:
    - `?limit=1` returns 1 map, total = 2.
    - `?limit=1&offset=1` returns the second map.
  - **Section 3 - GET /stats/combat/maps/:mapUid**:
    - Fetch `uid-alpha` -- assert 200, assert `map_uid`, `map_name`, `player_stats`, `win_context`.
    - Fetch unknown UID -- assert 404.
  - **Section 4 - GET /stats/combat/maps/:mapUid/players/:login**:
    - Fetch valid player on uid-alpha -- assert 200, assert `counters` fields.
    - Fetch unknown player on uid-alpha -- assert 404.
    - Fetch valid player on unknown map -- assert 404.
  - **Section 5 - GET /stats/combat/series**:
    - Assert HTTP 200.
    - Assert 1 series returned.
    - Assert `total_maps_played: 2`.
    - Assert `maps` array has 2 entries with correct UIDs.
    - Assert `series_totals` is the sum of both maps' totals.
    - Assert `match_started_at` and `match_ended_at` are present.
    - Assert pagination fields present.
  - **Section 6 - 404s for unknown server**:
    - All 4 endpoints with a nonexistent server login return 404.
  - **Section 7 - Cleanup**:
    - `DELETE /v1/servers/:serverLogin`.
  - Summary line with pass/fail counts.

- [Todo] P5.2 - Start the server stack and run the smoke test
  - Ensure PostgreSQL is running: `docker compose up -d postgres` (from `pixel-control-server/`).
  - Run `npm run start:dev` or `docker compose up -d`.
  - Execute: `bash scripts/qa-p2.5-smoke.sh`.
  - All assertions must pass (target: 30+ assertions, 0 failures).

- [Todo] P5.3 - Run existing P2 smoke test to verify no regressions
  - Execute: `bash scripts/qa-p2-smoke.sh`.
  - All existing assertions must still pass.

## Success criteria

- All 4 new endpoints return correct data shaped from lifecycle `map.end` and `match.begin`/`match.end` events.
- Existing 12 P2 endpoints unaffected (P2 smoke test passes).
- Full unit test suite passes (existing 96+ tests + new tests, target ~120+ total).
- `qa-p2.5-smoke.sh` passes with 0 failures (30+ assertions).
- `NEW_API_CONTRACT.md` updated with all 4 new endpoints.
- Swagger documentation is complete on all new routes.
- No new NestJS modules -- all additions are within `StatsReadModule`.

## Notes / outcomes

- Executed 2026-02-28. All 5 phases completed.
- 199 unit tests pass (21 test files). Previous count was 96; added 103 new tests across `stats-read.service.spec.ts` and `stats-read.controller.spec.ts`.
- `qa-p2.5-smoke.sh`: 59/59 assertions passed.
- `qa-p2-smoke.sh` (regression): 94/94 assertions passed — no regressions.
- Route ordering note: the new routes (e.g. `:serverLogin/stats/combat/maps`) must be registered **before** the existing `:serverLogin/stats/combat/players` route in the controller. NestJS resolves conflicts correctly by insertion order; `/maps` does not conflict with `/players/:login`.
- The `lifecycleSeq` counter in service spec is a module-level `let` that is reset in each `beforeEach` to ensure deterministic test behavior.
- Smoke test startup gotcha: if an old NestJS process is still bound to port 3000, the new process throws EADDRINUSE and exits silently. Always kill old processes before starting fresh.
