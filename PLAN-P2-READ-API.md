# PLAN - Implement P2 Read API (2026-02-28)

## Context

- **Purpose:** Expose ingested plugin telemetry data through typed GET endpoints. P0 established server link/auth and connectivity ingestion. P1 added unified multi-category event storage and basic status reads. P2 is the natural next layer: querying the stored events and presenting them as domain-shaped read responses for players, combat stats, scores, lifecycle state, maps, capabilities, and mode data.

- **Scope:** 12 new GET endpoints (P2.1 through P2.12 from `NEW_API_CONTRACT.md`), organized into domain-specific read modules. All endpoints query the existing unified `Event` table (and `ConnectivityEvent` table where appropriate), extract data from JSON `payload`/`metadata` columns, and return typed response shapes. No new write endpoints, no new database tables or migrations, no schema changes.

- **Goals:**
  - All 12 P2 read endpoints are implemented, tested, and documented with Swagger/OpenAPI.
  - Endpoints return well-shaped, typed responses derived from the latest or aggregated events stored in PostgreSQL.
  - Pagination is available on list endpoints (players, combat stats, event histories).
  - Time-range filtering (`since`/`until` query params) is available where appropriate.
  - Existing 96 P0+P1 tests remain green. New tests cover all P2 endpoints.
  - QA smoke test script validates all 12 endpoints via curl.
  - `NEW_API_CONTRACT.md` is updated with P2 status markers.

- **Non-goals:**
  - Materialized views or derived tables for performance (deferred until query latency warrants it; JSON extraction on the unified Event table is sufficient for the current expected data volume).
  - Inbound command proxy endpoints (P3+).
  - Frontend / dashboard.
  - Auth enforcement on read endpoints (remains open/unauthenticated per ROADMAP).
  - Write-side improvements to category-specific services (the P1 placeholder services remain as-is for ingestion; P2 only adds read logic).
  - Player history endpoint (P5.15) -- explicitly deferred.

- **Constraints / assumptions:**
  - NestJS v11 + Fastify + Prisma ORM + PostgreSQL (same stack).
  - Vitest + @swc/core for tests. Static imports only. DTO `field!: type`.
  - Swagger/OpenAPI descriptions mandatory on all routes.
  - All read data comes from existing tables (`Event`, `ConnectivityEvent`, `Server`). No schema migrations.
  - The plugin stores rich JSON payloads; the read API extracts and reshapes these payloads into typed response contracts.
  - "Latest" semantics: endpoints that return "current state" (players, lifecycle, mode) use the most recent event of the relevant type for the given server. "Aggregated" semantics (combat stats) accumulate across all stored events of the relevant type.
  - Existing composite index `(serverId, eventCategory, sourceTime)` on `events` table supports all P2 queries efficiently.

- **Environment snapshot:**
  - Branch: `main` (P0 + P1 merged)
  - Active stack: `pixel-control-server/` with PostgreSQL on port 5433, API on port 3000
  - Prisma migrations: `init`, `p0_foundation`, `p0_bigint_sequence`, `p1_unified_events`
  - Tests: 96 across 10 spec files, all green

- **Dependencies / stakeholders:** None external. Self-contained server-side work.

- **Risks / open questions:**
  - **JSON extraction performance**: Querying `payload->'field'` in PostgreSQL over the unified Event table could be slow if event volume is very high. Mitigation: existing indexes on `(serverId, eventCategory, sourceTime)` limit scan scope, and most queries fetch only the latest 1-2 events (not full scans). Defer materialized views to P3+ if needed.
  - **Player list accuracy**: The "current" player list is reconstructed from the latest player events. If the server was offline for a while and no disconnect events were sent, the list might be stale. Mitigation: include `last_updated` timestamps so consumers can judge freshness.
  - **Combat stats reset boundary**: The plugin counter scope is `runtime_session`. There is no explicit "session reset" event. Aggregated combat stats are cumulative for the server's entire event history. Per-round/per-map scopes come from lifecycle `aggregate_stats` payloads instead.

---

## Architecture Decisions

### D1: Read modules as new NestJS modules (not extensions of IngestionModule)

Read endpoints are separated into domain-specific modules, each with their own controller and service. This follows SRP and matches the existing pattern (LinkModule, StatusModule, IngestionModule are all independent).

New modules:
- **PlayersReadModule** (`src/players/`) -- P2.1, P2.2
- **StatsReadModule** (`src/stats/`) -- P2.3, P2.4, P2.5, P2.6
- **LifecycleReadModule** (`src/lifecycle/`) -- P2.7, P2.8, P2.9
- **MapsReadModule** (`src/maps/`) -- P2.11

Extended modules:
- **StatusModule** (`src/status/`) -- P2.10 (capabilities fits naturally alongside P1.7 status + P1.8 health)
- **ModeReadModule** (`src/mode/`) -- P2.12

### D2: Query the unified Event table with JSON extraction

All P2 endpoints query the existing `Event` table. No new tables or materialized views. Prisma's `findFirst`/`findMany` with `where: { serverId, eventCategory, eventName }` and `orderBy: { sourceTime: 'desc' }` provides the query path. JSON payload fields are extracted in the service layer (TypeScript) after fetching raw events from Prisma.

**Rationale:** The current data volume does not justify the complexity of materialized views. The composite index `(serverId, eventCategory, sourceTime)` efficiently supports all queries. If performance degrades later, materialized views can be added as a non-breaking optimization.

### D3: "Latest event" pattern for current-state endpoints

Endpoints that return "current state" (players list, lifecycle state, mode state, capabilities, maps, scores) fetch the latest event(s) of the relevant type. For example:
- Players list: latest `player` events per unique player login, filtered by the most recent heartbeat window.
- Lifecycle: latest lifecycle event for each phase/variant.
- Scores: latest `combat` event with `event_kind: "scores"` (from `SM_SCORES`).

This pattern is simple, correct, and avoids maintaining in-memory state machines.

### D4: Common server resolution utility

All P2 endpoints share the pattern: resolve `serverLogin` to a `Server` record, throw 404 if not found. This is extracted into a shared utility (`ServerResolver` or a common method) to avoid duplication.

---

## P2 Endpoint Inventory

| # | Priority | Method | Endpoint | Source Category | Description | Module |
|---|----------|--------|----------|-----------------|-------------|--------|
| 1 | P2.1 | GET | `/v1/servers/:serverLogin/players` | player | Current player list with state | PlayersReadModule |
| 2 | P2.2 | GET | `/v1/servers/:serverLogin/players/:login` | player | Single player state | PlayersReadModule |
| 3 | P2.3 | GET | `/v1/servers/:serverLogin/stats/combat` | combat | Aggregated combat stats (current session) | StatsReadModule |
| 4 | P2.4 | GET | `/v1/servers/:serverLogin/stats/combat/players` | combat | Per-player combat counters | StatsReadModule |
| 5 | P2.5 | GET | `/v1/servers/:serverLogin/stats/combat/players/:login` | combat | Single player combat counters | StatsReadModule |
| 6 | P2.6 | GET | `/v1/servers/:serverLogin/stats/scores` | combat | Latest scores snapshot (teams, players, result) | StatsReadModule |
| 7 | P2.7 | GET | `/v1/servers/:serverLogin/lifecycle` | lifecycle | Current lifecycle state (phase, warmup, pause) | LifecycleReadModule |
| 8 | P2.8 | GET | `/v1/servers/:serverLogin/lifecycle/map-rotation` | lifecycle | Current map rotation + veto state | LifecycleReadModule |
| 9 | P2.9 | GET | `/v1/servers/:serverLogin/lifecycle/aggregate-stats` | lifecycle | Latest aggregate stats (round/map scope) | LifecycleReadModule |
| 10 | P2.10 | GET | `/v1/servers/:serverLogin/status/capabilities` | connectivity | Plugin capabilities snapshot | StatusModule |
| 11 | P2.11 | GET | `/v1/servers/:serverLogin/maps` | lifecycle | Map pool from telemetry | MapsReadModule |
| 12 | P2.12 | GET | `/v1/servers/:serverLogin/mode` | mode | Current game mode + active mode events | ModeReadModule |

---

## Steps

- [Done] Phase 0 -- Shared infrastructure
- [Done] Phase 1 -- Players read endpoints (P2.1, P2.2)
- [Done] Phase 2 -- Combat stats read endpoints (P2.3, P2.4, P2.5, P2.6)
- [Done] Phase 3 -- Lifecycle read endpoints (P2.7, P2.8, P2.9)
- [Done] Phase 4 -- Status capabilities endpoint (P2.10)
- [Done] Phase 5 -- Maps read endpoint (P2.11)
- [Done] Phase 6 -- Mode read endpoint (P2.12)
- [Done] Phase 7 -- Testing (170 tests, all green)
- [Done] Phase 8 -- Contract and documentation updates
- [Done] Phase 9 -- QA smoke test
- [Done] Phase 10 -- Live stack QA: start stack and validate infrastructure
- [Done] Phase 11 -- Live stack QA: enhanced smoke script with deep assertions
- [Done] Phase 12 -- Live stack QA: execute and validate all 12 endpoints
- [Done] Phase 13 -- Live stack QA: fix issues discovered during QA

---

### Phase 0 -- Shared infrastructure

- [Todo] P0.1 -- Create `ServerResolverService` utility
  - File: `src/common/services/server-resolver.service.ts`
  - Shared injectable service that resolves `serverLogin` to a `Server` record, throwing `NotFoundException` if not found.
  - Also computes `online` status using existing `isServerOnline()` utility.
  - Returns `{ server, online }` tuple.
  - Create a `CommonModule` (`src/common/common.module.ts`) that imports `PrismaModule` + `ConfigModule` and provides/exports `ServerResolverService`.
  - All read modules import `CommonModule` instead of directly importing `PrismaModule` for server resolution.
  - This eliminates the duplicated server-lookup + 404 logic currently in `StatusService`, `LinkService`, and all new P2 services.

- [Todo] P0.2 -- Define common query parameter DTOs
  - File: `src/common/dto/query-params.dto.ts`
  - `PaginationQueryDto`: `limit` (number, optional, default 50, max 200), `offset` (number, optional, default 0).
  - `TimeRangeQueryDto`: `since` (ISO8601 string, optional), `until` (ISO8601 string, optional).
  - `PaginatedTimeRangeQueryDto`: combines both.
  - Use `class-validator` decorators, `class-transformer` for type coercion.
  - Swagger decorators: `@ApiQuery` for each parameter.

- [Todo] P0.3 -- Define common response envelope types
  - File: `src/common/dto/read-response.dto.ts`
  - `PaginatedResponse<T>`: `{ data: T[], pagination: { total: number, limit: number, offset: number } }`.
  - `SingleResponse<T>`: `{ data: T }` (optional wrapper if needed, or return T directly for simplicity).
  - Keep response shapes consistent across all P2 endpoints.

---

### Phase 1 -- Players read endpoints (P2.1, P2.2)

- [Todo] P1.1 -- Create `PlayersReadModule` structure
  - Files:
    - `src/players/players-read.module.ts` -- imports CommonModule, PrismaModule
    - `src/players/players-read.controller.ts` -- routes under `servers/:serverLogin/players`
    - `src/players/players-read.service.ts` -- query logic
  - Register in `app.module.ts`.

- [Todo] P1.2 -- Implement `GET /v1/servers/:serverLogin/players` (P2.1)
  - Route: `GET servers/:serverLogin/players`
  - Query params: `PaginationQueryDto` (limit, offset).
  - Logic:
    1. Resolve server via `ServerResolverService`.
    2. Query `Event` table: `WHERE serverId = ? AND eventCategory = 'player'`, ordered by `sourceTime DESC`.
    3. Build a de-duplicated player map: iterate events from newest to oldest. For each unique `payload.player.login`, keep only the latest event's player state. A player with `payload.event_kind = 'player.disconnect'` is marked as disconnected (still included, with `is_connected: false`).
    4. Return paginated response.
  - Response shape per player:
    ```json
    {
      "login": "player1",
      "nickname": "Player One",
      "team_id": 0,
      "is_spectator": false,
      "is_connected": true,
      "has_joined_game": true,
      "auth_level": 0,
      "auth_name": "player",
      "connectivity_state": "connected",
      "readiness_state": "ready",
      "eligibility_state": "eligible",
      "last_updated": "2026-02-28T10:00:00Z"
    }
    ```
  - Swagger: `@ApiTags('Players')`, `@ApiOperation`, `@ApiParam(serverLogin)`, `@ApiQuery(limit, offset)`, `@ApiResponse(200, 404)`.

- [Todo] P1.3 -- Implement `GET /v1/servers/:serverLogin/players/:login` (P2.2)
  - Route: `GET servers/:serverLogin/players/:login`
  - Logic:
    1. Resolve server.
    2. Query latest `Event` where `eventCategory = 'player'` and `payload` contains matching `player.login`.
    3. Since Prisma does not support JSON field filtering natively for PostgreSQL in a type-safe way, fetch recent player events and filter in TypeScript. Alternative: use `prisma.$queryRaw` with `payload->>'event_kind'` or `payload->'player'->>'login'` for precise filtering.
    4. Return the full player state from the latest matching event, including `state_delta`, `permission_signals`, `roster_state`, `reconnect_continuity`, `side_change`, and `constraint_signals` if present.
  - Extended response shape (superset of list endpoint):
    ```json
    {
      "login": "player1",
      "nickname": "Player One",
      "team_id": 0,
      "is_spectator": false,
      "is_connected": true,
      "has_joined_game": true,
      "auth_level": 0,
      "auth_name": "player",
      "connectivity_state": "connected",
      "readiness_state": "ready",
      "eligibility_state": "eligible",
      "permission_signals": { ... },
      "roster_state": { ... },
      "reconnect_continuity": { ... },
      "side_change": { ... },
      "constraint_signals": { ... },
      "last_event_id": "pc-evt-player-...",
      "last_updated": "2026-02-28T10:00:00Z"
    }
    ```
  - Return 404 if no player events found for this login on this server.
  - Swagger: `@ApiParam(login)`, `@ApiResponse(200, 404)`.

---

### Phase 2 -- Combat stats read endpoints (P2.3, P2.4, P2.5, P2.6)

- [Todo] P2.1 -- Create `StatsReadModule` structure
  - Files:
    - `src/stats/stats-read.module.ts` -- imports CommonModule, PrismaModule
    - `src/stats/stats-read.controller.ts` -- routes under `servers/:serverLogin/stats`
    - `src/stats/stats-read.service.ts` -- query logic
  - Register in `app.module.ts`.

- [Todo] P2.2 -- Implement `GET /v1/servers/:serverLogin/stats/combat` (P2.3)
  - Route: `GET servers/:serverLogin/stats/combat`
  - Query params: `TimeRangeQueryDto` (since, until) for filtering events within a time window.
  - Logic:
    1. Resolve server.
    2. Query `Event` table: `WHERE serverId = ? AND eventCategory = 'combat'`, optionally filtered by `sourceTime` range.
    3. Aggregate `player_counters` across all combat events. For each player login found in any combat event's `payload.player_counters`, sum the counter values (kills, deaths, hits, shots, misses, rockets, lasers). Compute accuracy as `hits / shots` (or 0 if no shots).
    4. Return aggregated summary.
  - Response shape:
    ```json
    {
      "server_login": "...",
      "combat_summary": {
        "total_events": 42,
        "total_kills": 150,
        "total_deaths": 150,
        "total_hits": 300,
        "total_shots": 1200,
        "total_accuracy": 0.25,
        "tracked_player_count": 6,
        "event_kinds": {
          "onshoot": 800,
          "onhit": 200,
          "onarmorempty": 80,
          "onnearmiss": 50,
          "oncapture": 10,
          "scores": 5
        }
      },
      "time_range": {
        "since": "2026-02-28T09:00:00Z",
        "until": "2026-02-28T10:00:00Z",
        "event_count": 42
      }
    }
    ```
  - **Important note on combat stats aggregation:** The plugin sends cumulative `player_counters` in every combat event (the counters reflect the running total since session start, not deltas). Therefore, the aggregated stats should come from the **latest** combat event's `player_counters` (not from summing across events, which would over-count). The `total_events` count tells how many combat events occurred, but the counter values come from the most recent event's snapshot.
  - Swagger: `@ApiTags('Stats')`, `@ApiQuery(since, until)`, `@ApiResponse(200, 404)`.

- [Todo] P2.3 -- Implement `GET /v1/servers/:serverLogin/stats/combat/players` (P2.4)
  - Route: `GET servers/:serverLogin/stats/combat/players`
  - Query params: `PaginatedTimeRangeQueryDto`.
  - Logic:
    1. Resolve server.
    2. Find the latest combat event (any `event_kind`) for this server.
    3. Extract `payload.player_counters` -- this is a map of `login -> { kills, deaths, hits, shots, misses, rockets, lasers, accuracy }`.
    4. Return as a paginated array of player counter objects.
  - Response shape per player:
    ```json
    {
      "login": "player1",
      "kills": 25,
      "deaths": 10,
      "hits": 50,
      "shots": 200,
      "misses": 150,
      "rockets": 100,
      "lasers": 100,
      "accuracy": 0.25
    }
    ```
  - Swagger: `@ApiQuery(limit, offset, since, until)`, `@ApiResponse(200, 404)`.

- [Todo] P2.4 -- Implement `GET /v1/servers/:serverLogin/stats/combat/players/:login` (P2.5)
  - Route: `GET servers/:serverLogin/stats/combat/players/:login`
  - Logic:
    1. Resolve server.
    2. Find the latest combat event that has this player login in `payload.player_counters`.
    3. Extract and return that player's counters.
    4. Additionally, extract combat-specific dimension data (weapon usage breakdown, positional data) from recent combat events involving this player (from `payload.dimensions`).
  - Extended response shape:
    ```json
    {
      "login": "player1",
      "counters": {
        "kills": 25,
        "deaths": 10,
        "hits": 50,
        "shots": 200,
        "misses": 150,
        "rockets": 100,
        "lasers": 100,
        "accuracy": 0.25
      },
      "recent_events_count": 42,
      "last_updated": "2026-02-28T10:00:00Z"
    }
    ```
  - Return 404 if no combat data found for this player login.
  - Swagger: `@ApiParam(login)`, `@ApiResponse(200, 404)`.

- [Todo] P2.5 -- Implement `GET /v1/servers/:serverLogin/stats/scores` (P2.6)
  - Route: `GET servers/:serverLogin/stats/scores`
  - Logic:
    1. Resolve server.
    2. Find the latest combat event with `event_kind = 'scores'` (from `SM_SCORES` callback) -- filter by `eventName` containing `scores` or by checking `payload.event_kind`.
    3. Extract `payload.scores_section`, `payload.scores_snapshot`, `payload.scores_result`.
  - Response shape:
    ```json
    {
      "server_login": "...",
      "scores_section": "EndRound",
      "scores_snapshot": {
        "teams": [...],
        "players": [...],
        "ranks": [...],
        "points": [...]
      },
      "scores_result": {
        "result_state": "team_win",
        "winning_side": "team_a",
        "winning_reason": "score_limit"
      },
      "source_time": "2026-02-28T10:00:00Z",
      "event_id": "pc-evt-combat-..."
    }
    ```
  - If no scores event exists, return a 200 with `null` scores fields (server exists but no scores received yet), or an empty object with a `"no_scores_available": true` flag.
  - Swagger: `@ApiResponse(200, 404)`.

---

### Phase 3 -- Lifecycle read endpoints (P2.7, P2.8, P2.9)

- [Todo] P3.1 -- Create `LifecycleReadModule` structure
  - Files:
    - `src/lifecycle/lifecycle-read.module.ts` -- imports CommonModule, PrismaModule
    - `src/lifecycle/lifecycle-read.controller.ts` -- routes under `servers/:serverLogin/lifecycle`
    - `src/lifecycle/lifecycle-read.service.ts` -- query logic
  - Register in `app.module.ts`.

- [Todo] P3.2 -- Implement `GET /v1/servers/:serverLogin/lifecycle` (P2.7)
  - Route: `GET servers/:serverLogin/lifecycle`
  - Logic:
    1. Resolve server.
    2. Query latest lifecycle events for each phase (`match`, `map`, `round`, `warmup`, `pause`) by finding the most recent event of each variant.
    3. Build a lifecycle state snapshot: what phase the server is currently in, whether warmup/pause is active.
  - Response shape:
    ```json
    {
      "server_login": "...",
      "current_phase": "round",
      "match": {
        "state": "begin",
        "variant": "match.begin",
        "source_time": "2026-02-28T09:00:00Z",
        "event_id": "pc-evt-lifecycle-..."
      },
      "map": {
        "state": "begin",
        "variant": "map.begin",
        "source_time": "2026-02-28T09:01:00Z",
        "event_id": "pc-evt-lifecycle-..."
      },
      "round": {
        "state": "begin",
        "variant": "round.begin",
        "source_time": "2026-02-28T09:02:00Z",
        "event_id": "pc-evt-lifecycle-..."
      },
      "warmup": {
        "active": false,
        "last_variant": "warmup.end",
        "source_time": "2026-02-28T09:01:30Z"
      },
      "pause": {
        "active": false,
        "last_variant": null,
        "source_time": null
      },
      "last_updated": "2026-02-28T09:02:00Z"
    }
    ```
  - Determining `current_phase`: derive from the most recent lifecycle event's `variant` -- if `round.begin` is most recent, we are in a round; if `map.end` is most recent, we are between maps, etc.
  - Determining warmup/pause: the latest warmup event with `state = 'start'` and no subsequent `state = 'end'` means warmup is active. Same for pause.
  - Swagger: `@ApiTags('Lifecycle')`, `@ApiResponse(200, 404)`.

- [Todo] P3.3 -- Implement `GET /v1/servers/:serverLogin/lifecycle/map-rotation` (P2.8)
  - Route: `GET servers/:serverLogin/lifecycle/map-rotation`
  - Logic:
    1. Resolve server.
    2. Find the latest lifecycle event that contains `map_rotation` in its payload (present on `map.begin` and `map.end` variants).
    3. Extract `payload.map_rotation` which includes: `map_pool`, `map_pool_size`, `current_map`, `current_map_index`, `next_maps`, `played_map_order`, `played_map_count`, `series_targets`, `veto_draft_mode`, `veto_draft_session_status`, `matchmaking_ready_armed`, `veto_draft_actions`, `veto_result`, `matchmaking_lifecycle`.
  - Response shape:
    ```json
    {
      "server_login": "...",
      "map_pool": [
        { "uid": "...", "name": "..." },
        ...
      ],
      "map_pool_size": 5,
      "current_map": { "uid": "...", "name": "...", "file": "..." },
      "current_map_index": 0,
      "next_maps": [...],
      "played_map_order": [...],
      "played_map_count": 2,
      "series_targets": { "best_of": 3, "maps_score": { ... }, "current_map_score": { ... } },
      "veto": {
        "mode": "tournament_draft",
        "session_status": "completed",
        "ready_armed": false,
        "actions": { ... },
        "result": { ... },
        "lifecycle": { ... }
      },
      "source_time": "2026-02-28T09:01:00Z",
      "event_id": "pc-evt-lifecycle-..."
    }
    ```
  - If no map rotation data exists, return 200 with `null` fields and a `"no_rotation_data": true` flag.
  - Swagger: `@ApiResponse(200, 404)`.

- [Todo] P3.4 -- Implement `GET /v1/servers/:serverLogin/lifecycle/aggregate-stats` (P2.9)
  - Route: `GET servers/:serverLogin/lifecycle/aggregate-stats`
  - Query params: `scope` (optional: `round` | `map`, default: both latest).
  - Logic:
    1. Resolve server.
    2. Find the latest lifecycle events with `aggregate_stats` in their payload (present on `round.end` and `map.end` variants).
    3. If `scope=round`, return only the latest round-scope aggregate. If `scope=map`, return only the latest map-scope. If no scope filter, return both.
    4. Extract `payload.aggregate_stats` which includes: `scope`, `counter_scope`, `player_counters_delta`, `totals`, `team_counters_delta`, `team_summary`, `tracked_player_count`, `window`, `source_coverage`, `win_context`.
  - Response shape:
    ```json
    {
      "server_login": "...",
      "aggregates": [
        {
          "scope": "round",
          "counter_scope": "combat_delta",
          "player_counters_delta": { ... },
          "totals": { ... },
          "team_counters_delta": [...],
          "team_summary": { ... },
          "tracked_player_count": 4,
          "window": { "start": ..., "end": ..., "duration": ... },
          "source_coverage": { ... },
          "win_context": {
            "result_state": "team_win",
            "winning_side": "team_a",
            "winning_reason": "score_limit"
          },
          "source_time": "2026-02-28T09:02:30Z",
          "event_id": "pc-evt-lifecycle-..."
        }
      ]
    }
    ```
  - Swagger: `@ApiQuery(scope)`, `@ApiResponse(200, 404)`.

---

### Phase 4 -- Status capabilities endpoint (P2.10)

- [Todo] P4.1 -- Extend `StatusModule` with capabilities endpoint
  - Add `GET servers/:serverLogin/status/capabilities` route to existing `StatusController`.
  - Logic:
    1. Resolve server.
    2. Find the latest connectivity event (either registration or heartbeat) that contains `payload.capabilities`.
    3. Registration events have the richest capabilities snapshot. Fall back to latest heartbeat if no registration event exists.
    4. Extract and return `payload.capabilities`.
  - Response shape:
    ```json
    {
      "server_login": "...",
      "online": true,
      "capabilities": {
        "admin_control": { ... },
        "queue": { ... },
        "transport": { ... },
        "callbacks": { ... }
      },
      "source": "plugin_registration",
      "source_time": "2026-02-28T09:00:00Z"
    }
    ```
  - If no capabilities data available, return 200 with `capabilities: null`.
  - Swagger: `@ApiResponse(200, 404)`.

- [Todo] P4.2 -- Add `StatusService.getServerCapabilities()` method
  - Query `ConnectivityEvent` or `Event` table for the latest registration/heartbeat event with capabilities.
  - Extract and type the capabilities payload.

---

### Phase 5 -- Maps read endpoint (P2.11)

- [Todo] P5.1 -- Create `MapsReadModule` structure
  - Files:
    - `src/maps/maps-read.module.ts` -- imports CommonModule, PrismaModule
    - `src/maps/maps-read.controller.ts` -- routes under `servers/:serverLogin/maps`
    - `src/maps/maps-read.service.ts` -- query logic
  - Register in `app.module.ts`.

- [Todo] P5.2 -- Implement `GET /v1/servers/:serverLogin/maps` (P2.11)
  - Route: `GET servers/:serverLogin/maps`
  - Logic:
    1. Resolve server.
    2. Find the latest lifecycle event with `map_rotation` data (same source as P2.8 map-rotation, but this endpoint returns just the map pool in a simpler shape, without veto/lifecycle state).
    3. Extract `payload.map_rotation.map_pool` -- array of map objects with `uid`, `name`, `file`, and any additional metadata.
    4. Also extract `current_map` and `current_map_index` for context.
  - Response shape:
    ```json
    {
      "server_login": "...",
      "maps": [
        { "uid": "...", "name": "...", "file": "..." },
        ...
      ],
      "map_count": 5,
      "current_map": { "uid": "...", "name": "..." },
      "current_map_index": 0,
      "last_updated": "2026-02-28T09:01:00Z"
    }
    ```
  - If no map data, return 200 with `maps: []` and `map_count: 0`.
  - Swagger: `@ApiTags('Maps')`, `@ApiResponse(200, 404)`.

---

### Phase 6 -- Mode read endpoint (P2.12)

- [Todo] P6.1 -- Create `ModeReadModule` structure
  - Files:
    - `src/mode/mode-read.module.ts` -- imports CommonModule, PrismaModule
    - `src/mode/mode-read.controller.ts` -- routes under `servers/:serverLogin/mode`
    - `src/mode/mode-read.service.ts` -- query logic
  - Register in `app.module.ts`.

- [Todo] P6.2 -- Implement `GET /v1/servers/:serverLogin/mode` (P2.12)
  - Route: `GET servers/:serverLogin/mode`
  - Query params: `limit` (optional, default 10, max 50) -- number of recent mode events to include.
  - Logic:
    1. Resolve server.
    2. Get the current game mode from the `Server` record (`gameMode` field, set by connectivity events).
    3. Query recent `Event` entries with `eventCategory = 'mode'`, ordered by `sourceTime DESC`, limited by `limit`.
    4. Extract `raw_callback_summary` and mode-specific fields from each event's payload.
  - Response shape:
    ```json
    {
      "server_login": "...",
      "game_mode": "Elite",
      "title_id": "SMStormElite@nadeolabs",
      "recent_mode_events": [
        {
          "event_name": "pixel_control.mode.shootmania_elite_startturn",
          "event_id": "pc-evt-mode-...",
          "source_callback": "SM_ELITE_STARTTURN",
          "source_time": "2026-02-28T09:02:00Z",
          "raw_callback_summary": { ... }
        },
        ...
      ],
      "total_mode_events": 28,
      "last_updated": "2026-02-28T09:02:00Z"
    }
    ```
  - If no mode events, return 200 with `recent_mode_events: []` and `total_mode_events: 0`.
  - Swagger: `@ApiTags('Mode')`, `@ApiQuery(limit)`, `@ApiResponse(200, 404)`.

---

### Phase 7 -- Testing

- [Todo] P7.1 -- Verify existing 96 P0+P1 tests still pass
  - Run `npm run test` in `pixel-control-server/`.
  - All existing tests must remain green. No P2 changes should affect ingestion or write paths.

- [Todo] P7.2 -- Unit tests for `ServerResolverService`
  - File: `src/common/services/server-resolver.service.spec.ts`
  - Test cases:
    - Returns server + online status for known server.
    - Throws NotFoundException for unknown server.
    - Correctly computes online/offline based on threshold.

- [Todo] P7.3 -- Unit tests for `PlayersReadService` + controller
  - File: `src/players/players-read.service.spec.ts`, `src/players/players-read.controller.spec.ts`
  - Test cases:
    - Returns de-duplicated player list from player events.
    - Handles empty player events (returns empty list).
    - Correctly marks disconnected players.
    - Returns single player with full detail.
    - Returns 404 for unknown player login.
    - Respects pagination (limit/offset).

- [Todo] P7.4 -- Unit tests for `StatsReadService` + controller
  - File: `src/stats/stats-read.service.spec.ts`, `src/stats/stats-read.controller.spec.ts`
  - Test cases:
    - Returns aggregated combat stats from latest combat event.
    - Returns per-player counters.
    - Returns single player counters.
    - Returns 404 for unknown player in combat stats.
    - Returns latest scores snapshot.
    - Handles no combat events (empty/default response).
    - Handles no scores events (empty response).
    - Respects time range filtering.

- [Todo] P7.5 -- Unit tests for `LifecycleReadService` + controller
  - File: `src/lifecycle/lifecycle-read.service.spec.ts`, `src/lifecycle/lifecycle-read.controller.spec.ts`
  - Test cases:
    - Returns current lifecycle state from latest events.
    - Correctly determines warmup active/inactive.
    - Correctly determines pause active/inactive.
    - Returns map rotation data.
    - Returns aggregate stats with scope filtering.
    - Handles no lifecycle events.

- [Todo] P7.6 -- Unit tests for capabilities, maps, and mode
  - Files:
    - `src/status/status.service.spec.ts` (extend existing) -- capabilities tests.
    - `src/maps/maps-read.service.spec.ts`, `src/maps/maps-read.controller.spec.ts`
    - `src/mode/mode-read.service.spec.ts`, `src/mode/mode-read.controller.spec.ts`
  - Test cases:
    - Capabilities: returns capabilities from registration event, returns null when no data.
    - Maps: returns map pool from lifecycle events, handles empty pool.
    - Mode: returns recent mode events, respects limit, handles empty events.

- [Todo] P7.7 -- Run full test suite
  - `npm run test` -- all tests green.
  - Target: 150+ tests (96 existing + ~60 new for P2).

---

### Phase 8 -- Contract and documentation updates

- [Todo] P8.1 -- Update `NEW_API_CONTRACT.md`
  - Mark all P2 endpoints (P2.1 through P2.12) as `Done`.
  - Verify response shapes in the contract match the implementation.

- [Todo] P8.2 -- Update project memory
  - Update `MEMORY.md` with P2 module structure, new endpoint inventory, test count, and architecture decisions.

---

### Phase 9 -- QA smoke test

- [Todo] P9.1 -- Write QA smoke test script
  - File: `pixel-control-server/scripts/qa-p2-smoke.sh`
  - Prerequisites: server running + postgres up. Must first seed data by sending events (reuse patterns from `qa-p1-smoke.sh`).
  - Test scenarios (via curl):
    1. Seed: register server, send connectivity (registration + heartbeat), player (connect/disconnect/info-changed), combat (onshoot/onhit/onarmorempty/scores), lifecycle (match.begin/map.begin/round.begin/round.end with aggregate_stats + map_rotation), mode (elite_startturn) events.
    2. `GET /v1/servers/:serverLogin/players` -- verify returns player list with correct count.
    3. `GET /v1/servers/:serverLogin/players/:login` -- verify returns single player with full state.
    4. `GET /v1/servers/:serverLogin/players/:unknownLogin` -- verify 404.
    5. `GET /v1/servers/:serverLogin/stats/combat` -- verify returns combat summary.
    6. `GET /v1/servers/:serverLogin/stats/combat/players` -- verify per-player counters.
    7. `GET /v1/servers/:serverLogin/stats/combat/players/:login` -- verify single player counters.
    8. `GET /v1/servers/:serverLogin/stats/scores` -- verify scores snapshot.
    9. `GET /v1/servers/:serverLogin/lifecycle` -- verify lifecycle state.
    10. `GET /v1/servers/:serverLogin/lifecycle/map-rotation` -- verify map rotation data.
    11. `GET /v1/servers/:serverLogin/lifecycle/aggregate-stats` -- verify aggregates.
    12. `GET /v1/servers/:serverLogin/lifecycle/aggregate-stats?scope=round` -- verify scope filter.
    13. `GET /v1/servers/:serverLogin/status/capabilities` -- verify capabilities snapshot.
    14. `GET /v1/servers/:serverLogin/maps` -- verify map pool.
    15. `GET /v1/servers/:serverLogin/mode` -- verify mode data.
    16. `GET /v1/servers/:unknownServer/players` -- verify 404.
    17. Cleanup: DELETE server.
  - macOS-compatible (no bashisms unsupported on macOS).

- [Todo] P9.2 -- Run smoke test and verify all assertions pass
  - Start stack: `docker compose up -d` or `npm run start:dev` + postgres.
  - Run: `bash scripts/qa-p2-smoke.sh`
  - All assertions green.

---

### Phase 10 -- Live stack QA: start stack and validate infrastructure

This phase boots the real Docker + NestJS stack and confirms the API is alive before running end-to-end tests. All subsequent phases depend on this one.

- [Todo] P10.1 -- Start PostgreSQL via Docker Compose
  - Working directory: `pixel-control-server/`
  - Command: `docker compose up -d postgres`
  - Wait for the healthcheck to pass (`pg_isready` probe at 5s interval, 5 retries).
  - Verification: `docker compose ps` shows postgres as `healthy`.

- [Todo] P10.2 -- Apply Prisma migrations
  - Command: `npx prisma migrate deploy` (or `npm run prisma:migrate`)
  - This applies all existing migrations: `init`, `p0_foundation`, `p0_bigint_sequence`, `p1_unified_events`.
  - Verification: no error output, exit code 0.

- [Todo] P10.3 -- Start the NestJS API
  - Command: `npm run start:dev` (watch mode for interactive QA) or `npm run start:prod` (compiled).
  - Wait for the server to accept connections on port 3000.
  - Verification: `curl -sf http://localhost:3000/v1/servers` returns HTTP 200 with an empty array `[]` or a JSON response (depending on existing DB state).

- [Todo] P10.4 -- Confirm clean database state
  - If stale data from prior runs exists, it may interfere with assertions (e.g., duplicate idempotency keys).
  - Approach: either wipe the database (`rm -rf pixel-control-server/data/postgres && docker compose up -d postgres && npx prisma migrate deploy`) or use a unique `SERVER_LOGIN` per run (the smoke script already uses `qa-p2-smoke-server`).
  - The script should use unique idempotency keys per run (timestamp-based) to avoid collisions. The existing `qa-p2-smoke.sh` already uses `shasum`-based keys with an incrementing `seq` counter, but this may collide across runs. The enhanced script (Phase 11) will prefix keys with a run-unique timestamp.

---

### Phase 11 -- Live stack QA: enhanced smoke script with deep assertions

The existing `scripts/qa-p2-smoke.sh` (35 assertions) validates basic HTTP status codes and checks for key field names in responses. This phase enhances it into a comprehensive end-to-end test that validates actual data values from injected fixtures, not just field presence.

- [Todo] P11.1 -- Audit the existing `qa-p2-smoke.sh` script
  - Read the current script (403 lines, 35+ assertions).
  - Identify gaps:
    - **Value assertions**: Current script checks `assert_contains '"data"'` but does not verify the actual player login values, counter numbers, or array lengths.
    - **Pagination validation**: No test that `limit` and `offset` query params affect the response (e.g., `?limit=1` returns exactly 1 item).
    - **Time-range filtering**: No test that `since`/`until` on combat stats narrows results.
    - **Data integrity**: No check that injected fixture data (e.g., `smoke-player-1` with `kills: 12`) actually appears with correct values in the read response.
    - **Disconnected player state**: Checks for `smoke-player-2` in player list with `is_connected: false` are missing.
    - **Run isolation**: Idempotency keys use `shasum` of an incrementing counter, which will collide if the script is run twice without a DB wipe.
    - **Error on first failure**: The script uses `set -euo pipefail` but `assert_status`/`assert_contains` do not `exit 1` on failure -- they increment `FAIL` and continue. This is fine for reporting but the script should exit non-zero at the end (it already does via `[ "$FAIL" -eq 0 ]` on line 403).
    - **Missing `jq` usage**: The script uses `grep` for field checks, which is fragile. Adding `jq` assertions for precise value extraction would make the tests more robust.

- [Todo] P11.2 -- Rewrite/enhance `qa-p2-smoke.sh` with comprehensive assertions
  - File: `pixel-control-server/scripts/qa-p2-smoke.sh` (overwrite existing)
  - Requirements for the enhanced script:
    - **Prerequisite check**: Verify `curl` and `jq` are available. Print error and exit if not.
    - **Server wait loop**: Poll `GET /v1/servers` up to 15 attempts (2s apart) before starting.
    - **Run-unique keys**: Use `TIMESTAMP=$(date +%s)` to prefix all idempotency keys, ensuring no collisions across runs.
    - **Clean seed data**: Register server, inject all 5 event categories with realistic payloads matching what the plugin actually sends.
    - **Deep value assertions** using `jq` for JSON extraction (not just grep).
    - **Target: 60+ assertions** covering all 12 endpoints with both structural and value-level checks.
    - **Exit 1 on first failure** option (configurable via `FAIL_FAST=1` env var), or accumulate and report all failures.
    - **Cleanup**: DELETE the test server at the end (even on failure, via a trap).
  - Detailed test plan per endpoint (all assertions use `jq`):

  **Seed phase** (14 curl calls):
  1. `PUT /v1/servers/qa-p2-live/link/registration` -- register server. Assert HTTP 200, response has `link_token`.
  2. `POST /v1/plugin/events` -- connectivity `plugin_registration` with `capabilities` payload. Assert HTTP 200, disposition `accepted`.
  3. `POST /v1/plugin/events` -- connectivity `plugin_heartbeat` with `queue`/`retry`/`outage`/`context.players`. Assert HTTP 200.
  4. `POST /v1/plugin/events` -- player `player_connect` for `live-player-1` (team 0, connected, auth_level 0). Assert HTTP 200.
  5. `POST /v1/plugin/events` -- player `player_connect` for `live-player-2` (team 1, connected). Assert HTTP 200.
  6. `POST /v1/plugin/events` -- player `player_disconnect` for `live-player-2`. Assert HTTP 200.
  7. `POST /v1/plugin/events` -- combat `onshoot` with `player_counters` for `live-player-1` (kills:5, deaths:2, hits:20, shots:100). Assert HTTP 200.
  8. `POST /v1/plugin/events` -- combat `onarmorempty` with updated `player_counters` for `live-player-1` (kills:8, deaths:3, hits:30, shots:150). Assert HTTP 200.
  9. `POST /v1/plugin/events` -- combat `scores` with `scores_section: "EndRound"`, `scores_snapshot`, `scores_result`. Assert HTTP 200.
  10. `POST /v1/plugin/events` -- lifecycle `sm_begin_match` with `variant: "match.begin"` and `map_rotation` (2 maps: "Arena" uid-arena, "Coliseum" uid-coliseum). Assert HTTP 200.
  11. `POST /v1/plugin/events` -- lifecycle `sm_begin_map` with `variant: "map.begin"` and `map_rotation`. Assert HTTP 200.
  12. `POST /v1/plugin/events` -- lifecycle `sm_begin_round` with `variant: "round.begin"`. Assert HTTP 200.
  13. `POST /v1/plugin/events` -- lifecycle `sm_end_round` with `variant: "round.end"` and `aggregate_stats` (scope: "round", player_counters_delta, totals, team_counters_delta, win_context). Assert HTTP 200.
  14. `POST /v1/plugin/events` -- mode `sm_elite_startturn` with `raw_callback_summary`. Assert HTTP 200.

  **P2.1 -- GET /v1/servers/:serverLogin/players** (5 assertions):
  1. HTTP 200.
  2. `jq '.data | length'` equals 2 (both players present).
  3. `jq '.data[] | select(.login == "live-player-1") | .is_connected'` equals `true`.
  4. `jq '.data[] | select(.login == "live-player-2") | .is_connected'` equals `false` (disconnected player still in list).
  5. `jq '.pagination.total'` equals 2.

  **P2.1 -- Pagination** (2 assertions):
  1. `GET ...?limit=1` -- `jq '.data | length'` equals 1.
  2. `GET ...?limit=1&offset=1` -- `jq '.data | length'` equals 1 (second page).

  **P2.2 -- GET /v1/servers/:serverLogin/players/live-player-1** (4 assertions):
  1. HTTP 200.
  2. `jq '.login'` equals `"live-player-1"`.
  3. `jq '.last_event_id'` is not null (contains `pc-evt-player-`).
  4. `jq '.permission_signals'` is present (not null, since seed included it for player-1).

  **P2.2 -- GET /v1/servers/:serverLogin/players/unknown-xyz** (1 assertion):
  1. HTTP 404.

  **P2.3 -- GET /v1/servers/:serverLogin/stats/combat** (5 assertions):
  1. HTTP 200.
  2. `jq '.combat_summary.total_events'` equals 3 (onshoot + onarmorempty + scores).
  3. `jq '.combat_summary.total_kills'` equals 8 (from latest event's cumulative counters).
  4. `jq '.combat_summary.tracked_player_count'` equals 1.
  5. `jq '.combat_summary.event_kinds | keys | length'` is at least 2 (onshoot, onarmorempty, scores).

  **P2.4 -- GET /v1/servers/:serverLogin/stats/combat/players** (3 assertions):
  1. HTTP 200.
  2. `jq '.data | length'` equals 1 (only live-player-1 has counters).
  3. `jq '.data[0].kills'` equals 8 (from latest cumulative counters).

  **P2.5 -- GET /v1/servers/:serverLogin/stats/combat/players/live-player-1** (3 assertions):
  1. HTTP 200.
  2. `jq '.counters.kills'` equals 8.
  3. `jq '.counters.shots'` equals 150.

  **P2.5 -- GET /v1/servers/:serverLogin/stats/combat/players/unknown-xyz** (1 assertion):
  1. HTTP 404.

  **P2.6 -- GET /v1/servers/:serverLogin/stats/scores** (4 assertions):
  1. HTTP 200.
  2. `jq '.scores_section'` equals `"EndRound"`.
  3. `jq '.scores_result.result_state'` equals `"team_win"`.
  4. `jq '.event_id'` is not null.

  **P2.7 -- GET /v1/servers/:serverLogin/lifecycle** (5 assertions):
  1. HTTP 200.
  2. `jq '.current_phase'` is `"round"` (latest lifecycle event was round.end).
  3. `jq '.match.variant'` equals `"match.begin"`.
  4. `jq '.map.variant'` equals `"map.begin"`.
  5. `jq '.warmup.active'` equals `false` (no warmup events in seed).

  **P2.8 -- GET /v1/servers/:serverLogin/lifecycle/map-rotation** (4 assertions):
  1. HTTP 200.
  2. `jq '.map_pool | length'` equals 2.
  3. `jq '.map_pool[0].name'` equals `"Arena"` (or `"Coliseum"` depending on order).
  4. `jq '.current_map.uid'` equals `"uid-arena"`.

  **P2.9 -- GET /v1/servers/:serverLogin/lifecycle/aggregate-stats** (3 assertions):
  1. HTTP 200.
  2. `jq '.aggregates | length'` is at least 1.
  3. `jq '.aggregates[0].scope'` equals `"round"`.

  **P2.9 -- GET /v1/servers/:serverLogin/lifecycle/aggregate-stats?scope=round** (2 assertions):
  1. HTTP 200.
  2. `jq '.aggregates[] | select(.scope == "round") | .win_context.result_state'` equals `"team_win"`.

  **P2.10 -- GET /v1/servers/:serverLogin/status/capabilities** (3 assertions):
  1. HTTP 200.
  2. `jq '.capabilities.admin_control.enabled'` equals `true` (from seed registration event).
  3. `jq '.source'` equals `"plugin_registration"`.

  **P2.11 -- GET /v1/servers/:serverLogin/maps** (4 assertions):
  1. HTTP 200.
  2. `jq '.maps | length'` equals 2.
  3. `jq '.map_count'` equals 2.
  4. `jq '.current_map.uid'` equals `"uid-arena"`.

  **P2.12 -- GET /v1/servers/:serverLogin/mode** (4 assertions):
  1. HTTP 200.
  2. `jq '.game_mode'` equals `"Elite"`.
  3. `jq '.total_mode_events'` equals 1.
  4. `jq '.recent_mode_events[0].raw_callback_summary.turn_number'` equals 1.

  **Cross-cutting 404 test** (2 assertions):
  1. `GET /v1/servers/nonexistent-server/players` -- HTTP 404.
  2. `GET /v1/servers/nonexistent-server/stats/combat` -- HTTP 404.

  **Cleanup** (1 assertion):
  1. `DELETE /v1/servers/qa-p2-live` -- HTTP 200.

  **Total: ~65 assertions** (14 seed + 51 read validation).

- [Todo] P11.3 -- Add a `trap` cleanup handler to the script
  - Ensure the test server (`qa-p2-live`) is deleted even if the script exits early (via `set -e` or `FAIL_FAST`).
  - Pattern:
    ```bash
    cleanup() {
      echo "Cleaning up test server..."
      curl -s -o /dev/null -X DELETE "${API}/servers/${SERVER_LOGIN}" || true
    }
    trap cleanup EXIT
    ```

---

### Phase 12 -- Live stack QA: execute and validate all 12 endpoints

- [Todo] P12.1 -- Run the enhanced smoke script against the live stack
  - Ensure PostgreSQL is healthy and the API is running (Phase 10).
  - Command: `cd pixel-control-server && bash scripts/qa-p2-smoke.sh`
  - Capture full output to `scripts/qa-p2-smoke-output.log` for review:
    `bash scripts/qa-p2-smoke.sh 2>&1 | tee scripts/qa-p2-smoke-output.log`
  - Success criteria: all ~65 assertions pass, exit code 0.

- [Todo] P12.2 -- Validate pagination behavior
  - Within the smoke script (already covered in P11.2), confirm:
    - `GET /v1/servers/:serverLogin/players?limit=1` returns exactly 1 player in `data`.
    - `GET /v1/servers/:serverLogin/players?limit=1&offset=1` returns exactly 1 player (the second one).
    - `GET /v1/servers/:serverLogin/stats/combat/players?limit=1` returns exactly 1 entry.
  - If the smoke script does not cover these yet, add dedicated curl calls.

- [Todo] P12.3 -- Validate time-range filtering
  - Send a combat event with a known `source_time` far in the past (e.g., `1000000`).
  - Send another combat event with `source_time` at current time.
  - Query `GET /v1/servers/:serverLogin/stats/combat?since=<recent_iso>` and verify only the recent event contributes to `total_events`.
  - This is a more advanced test that may be added as an optional section in the smoke script.

- [Todo] P12.4 -- Validate 404 responses for all endpoint families
  - Confirm that each read module returns proper 404 for unknown `serverLogin`:
    - `GET /v1/servers/nonexistent/players` -- 404
    - `GET /v1/servers/nonexistent/stats/combat` -- 404
    - `GET /v1/servers/nonexistent/stats/scores` -- 404
    - `GET /v1/servers/nonexistent/lifecycle` -- 404
    - `GET /v1/servers/nonexistent/lifecycle/map-rotation` -- 404
    - `GET /v1/servers/nonexistent/lifecycle/aggregate-stats` -- 404
    - `GET /v1/servers/nonexistent/status/capabilities` -- 404
    - `GET /v1/servers/nonexistent/maps` -- 404
    - `GET /v1/servers/nonexistent/mode` -- 404
  - All 9 endpoints should return `{"statusCode":404, ...}`.

- [Todo] P12.5 -- Run existing P0 + P1 smoke tests for regression
  - After the P2 smoke test passes, also run:
    - `bash scripts/qa-p0-smoke.sh` -- all 43 assertions pass.
    - `bash scripts/qa-p1-smoke.sh` -- all 35 assertions pass.
  - This confirms no regressions from P2 code changes.

---

### Phase 13 -- Live stack QA: fix issues discovered during QA

- [Todo] P13.1 -- Triage any failures from Phase 12
  - If the smoke script reports failures, categorize each as:
    - **Script bug**: incorrect assertion logic, wrong expected value, missing `jq` filter.
    - **API bug**: endpoint returns wrong data, wrong status code, or crashes.
    - **Seed data bug**: fixture payload shape does not match what the service expects.
  - Document each failure with: endpoint, expected vs actual, and root cause hypothesis.

- [Todo] P13.2 -- Fix API bugs (if any)
  - For each API bug discovered:
    - Fix the service/controller code.
    - Add or update the corresponding unit test to cover the bug.
    - Re-run `npm run test` to confirm all 170+ tests still pass.
  - Common anticipated issues:
    - **JSON extraction failures**: If a payload field is nested differently than expected (e.g., `player.login` vs `login`), the service may return `null` instead of the value.
    - **BigInt serialization**: NestJS/Fastify may serialize BigInt `sourceTime` incorrectly. The services convert via `new Date(Number(sourceTime)).toISOString()`, which should work, but edge cases with very large timestamps could fail.
    - **Empty state responses**: Endpoints that return "no data" shapes (e.g., `no_scores_available: true`, `maps: []`) might have subtle differences from what the assertions expect.

- [Todo] P13.3 -- Fix smoke script bugs (if any)
  - If assertions are wrong (e.g., checking for a field that the implementation names differently), update the smoke script to match the actual API response shape.
  - Re-run the script to confirm the fix.

- [Todo] P13.4 -- Re-run full QA suite after fixes
  - Run the complete validation cycle:
    1. `npm run test` -- all unit tests pass.
    2. `bash scripts/qa-p2-smoke.sh` -- all live assertions pass.
    3. `bash scripts/qa-p0-smoke.sh` -- P0 regression check passes.
    4. `bash scripts/qa-p1-smoke.sh` -- P1 regression check passes.
  - Only mark Phase 13 as [Done] when all four pass cleanly.

- [Todo] P13.5 -- Update project memory with QA results
  - Record in `MEMORY.md`:
    - Final assertion counts for the enhanced smoke script.
    - Any bugs found and fixed during live QA (root cause + fix summary).
    - Any gotchas discovered (e.g., "must wipe DB between runs" or "jq required").
    - Updated test totals if unit tests were added.

---

## File Inventory (new and modified)

### New files

| File | Purpose |
|---|---|
| `src/common/common.module.ts` | CommonModule (shared imports, ServerResolverService) |
| `src/common/services/server-resolver.service.ts` | Shared server lookup + online status |
| `src/common/services/server-resolver.service.spec.ts` | Unit tests |
| `src/common/dto/query-params.dto.ts` | Pagination + time-range query param DTOs |
| `src/common/dto/read-response.dto.ts` | Common read response types |
| `src/players/players-read.module.ts` | PlayersReadModule wiring |
| `src/players/players-read.controller.ts` | GET players + single player endpoints |
| `src/players/players-read.service.ts` | Player read query logic |
| `src/players/players-read.controller.spec.ts` | Controller unit tests |
| `src/players/players-read.service.spec.ts` | Service unit tests |
| `src/stats/stats-read.module.ts` | StatsReadModule wiring |
| `src/stats/stats-read.controller.ts` | GET combat stats + scores endpoints |
| `src/stats/stats-read.service.ts` | Stats read query logic |
| `src/stats/stats-read.controller.spec.ts` | Controller unit tests |
| `src/stats/stats-read.service.spec.ts` | Service unit tests |
| `src/lifecycle/lifecycle-read.module.ts` | LifecycleReadModule wiring |
| `src/lifecycle/lifecycle-read.controller.ts` | GET lifecycle + map-rotation + aggregate endpoints |
| `src/lifecycle/lifecycle-read.service.ts` | Lifecycle read query logic |
| `src/lifecycle/lifecycle-read.controller.spec.ts` | Controller unit tests |
| `src/lifecycle/lifecycle-read.service.spec.ts` | Service unit tests |
| `src/maps/maps-read.module.ts` | MapsReadModule wiring |
| `src/maps/maps-read.controller.ts` | GET maps endpoint |
| `src/maps/maps-read.service.ts` | Maps read query logic |
| `src/maps/maps-read.controller.spec.ts` | Controller unit tests |
| `src/maps/maps-read.service.spec.ts` | Service unit tests |
| `src/mode/mode-read.module.ts` | ModeReadModule wiring |
| `src/mode/mode-read.controller.ts` | GET mode endpoint |
| `src/mode/mode-read.service.ts` | Mode read query logic |
| `src/mode/mode-read.controller.spec.ts` | Controller unit tests |
| `src/mode/mode-read.service.spec.ts` | Service unit tests |
| `scripts/qa-p2-smoke.sh` | QA smoke test script (enhanced in Phase 11 with ~65 deep `jq` assertions) |
| `scripts/qa-p2-smoke-output.log` | Captured output from live QA run (gitignored, not committed) |

### Modified files

| File | Change |
|---|---|
| `src/app.module.ts` | Add PlayersReadModule, StatsReadModule, LifecycleReadModule, MapsReadModule, ModeReadModule, CommonModule imports |
| `src/status/status.controller.ts` | Add capabilities endpoint (P2.10) |
| `src/status/status.service.ts` | Add `getServerCapabilities()` method |
| `src/status/status.service.spec.ts` | Add capabilities unit tests |
| `NEW_API_CONTRACT.md` | Mark P2.1-P2.12 as Done |

---

## Success Criteria

- `npm run test` passes with all P0 + P1 + P2 tests green (target: 150+ tests).
- `bash scripts/qa-p2-smoke.sh` passes all assertions (~65 deep assertions with `jq` value checks).
- All 12 P2 GET endpoints return correct response shapes derived from stored event data.
- No regressions in existing P0/P1 functionality (ingestion, link, status, health).
- Swagger UI at `/api/docs` documents all 12 new endpoints with complete parameter and response descriptions.
- `NEW_API_CONTRACT.md` marks P2.1-P2.12 as Done.
- Pagination and time-range filtering work correctly on applicable endpoints.
- **Live QA (Phases 10-13):**
  - PostgreSQL + NestJS API start cleanly with `docker compose up -d postgres` + `npm run start:dev`.
  - Enhanced `qa-p2-smoke.sh` passes all ~65 assertions against the live stack.
  - Actual data values from injected fixtures appear correctly in GET responses (not just field presence).
  - `qa-p0-smoke.sh` and `qa-p1-smoke.sh` both pass (no regressions).
  - All 9 endpoint families return proper 404 for nonexistent server login.
  - Any bugs discovered are fixed and covered by additional unit tests.

## Notes / outcomes

**Completed 2026-02-28.**

- All 12 P2 GET endpoints implemented, tested with Swagger/OpenAPI.
- 170 tests total (96 P0+P1 + 74 new P2), all green across 21 spec files.
- Build compiles cleanly with `nest build` (no TS errors).
- `NEW_API_CONTRACT.md` marks all P2.1–P2.12 endpoints as Done.
- Smoke test script: `pixel-control-server/scripts/qa-p2-smoke.sh` (35+ assertions).

**Architecture notes:**
- `CommonModule` + `ServerResolverService` eliminates duplicated server-lookup code.
- All read modules (PlayersReadModule, StatsReadModule, LifecycleReadModule, MapsReadModule, ModeReadModule) follow the same NestJS pattern: import CommonModule + PrismaModule.
- `getCombatPlayersCounters` uses `findFirst` (not `findMany`) since plugin counters are cumulative — only the latest event's counters are needed.
- `getCombatStats` uses `findMany` but extracts counters from the newest event with `player_counters`.
- Maps and lifecycle/map-rotation share the same data source (lifecycle events with `map_rotation`).
- Capabilities come from `ConnectivityEvent` table (legacy dual-write), not unified `Event` table.
