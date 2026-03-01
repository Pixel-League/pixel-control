# PLAN - P2.6 Player Combat History (per-player, per-map breakdown + derived stats) (2026-03-01)

## Context

- Purpose: Add a new endpoint that returns a single player's combat stats across their last N maps, providing a chronological per-map breakdown rather than only cumulative session totals. This fills the gap between "cumulative player stats" (P2.5) and "per-map stats for all players" (P2.5.1-P2.5.3). Additionally, enrich all player combat stat endpoints with derived fields: `kd_ratio`, `win_rate`, `rocket_accuracy`, and `laser_accuracy`.
- Scope:
  - One new GET endpoint: `/v1/servers/:serverLogin/stats/combat/players/:login/maps`
  - Service method in `StatsReadService`
  - Controller route in `StatsReadController`
  - **Plugin PHP modifications**: Add `hits_rocket`/`hits_laser` tracking to `PlayerCombatStatsStore` and `CombatDomainTrait`
  - **Derived stats added to ALL endpoints returning player counters**: `kd_ratio`, `hits_rocket`, `hits_laser`, `rocket_accuracy`, `laser_accuracy`
  - **Win rate computation** on the new player maps history endpoint: `win_rate`, `maps_won`, `maps_played`, per-map `won` boolean
  - Unit tests in both `.spec.ts` files
  - Update `NEW_API_CONTRACT.md`
  - Live QA smoke testing
- Non-goals: No new Prisma models or migrations. No batch/aggregation across servers. No new NestJS modules.
- Constraints:
  - Extend existing `StatsReadService` and `StatsReadController` -- no new module/file.
  - Swagger/OpenAPI descriptions mandatory on all routes.
  - Vitest unit tests required.
  - DTO strict mode: `field!: type`.
  - No inline TS imports.
  - Plugin PHP code follows existing coding style (traits, PHPDoc, array() syntax).
  - `hits_rocket`/`hits_laser` fields are optional/nullable on the API side for backward compatibility with events emitted before the plugin update.
  - Envelope schema `2026-02-20.1` -- additive evolution only (no breaking field changes).
- Environment snapshot:
  - Branch: `main` (P0 + P1 + P2.5 complete)
  - All 96+ unit tests passing
  - Existing smoke test scripts: `qa-p0-smoke.sh`, `qa-p1-smoke.sh`

### Data Source

Lifecycle events with `variant === "map.end"` and `aggregate_stats.scope === "map"` contain `payload.aggregate_stats.player_counters_delta` -- a map keyed by player login with delta counters for that specific map. The existing `extractMapCombatEntry()` private helper already parses these events and returns `MapCombatStatsEntry` objects with a `player_stats` record.

Win determination uses `payload.aggregate_stats.win_context.winner_team_id` combined with `payload.aggregate_stats.team_counters_delta[].player_logins` to determine which players were on the winning team. Each `team_counters_delta` entry has shape: `{ team_id: number, player_logins: string[], ... }`.

### Approach

Reuse `fetchLifecycleEvents()` and `extractMapCombatEntry()` (both already in `StatsReadService`). After extracting all map entries, filter to only those where `player_stats` contains the target login, extract that player's counters for each map, and return paginated results ordered most-recent first.

For derived stats:
- **`kd_ratio`**: Computed inline wherever `PlayerCountersDelta` objects are built. Formula: `kills / deaths` (when `deaths === 0`, use `kills` as the value).
- **Win rate**: Computed in `getPlayerCombatMapHistory()` by checking each map's `team_counters_delta` for the team that contains the player login, then comparing that `team_id` against `win_context.winner_team_id`.
- **`rocket_accuracy`/`laser_accuracy`**: Computed from `hits_rocket / rockets` and `hits_laser / lasers` respectively. These fields are only present when the plugin provides `hits_rocket`/`hits_laser` in the event payload (post-plugin-update). For older events, these fields default to `null`.

### Response Shape (updated with derived stats)

```json
{
  "server_login": "pixel-elite-1.server.local",
  "player_login": "player1",
  "maps_played": 15,
  "maps_won": 10,
  "win_rate": 0.6667,
  "maps": [
    {
      "map_uid": "uid-alpha",
      "map_name": "Alpha Arena",
      "played_at": "2026-02-28T12:00:00.000Z",
      "duration_seconds": 1200,
      "won": true,
      "counters": {
        "kills": 5,
        "deaths": 2,
        "kd_ratio": 2.5,
        "hits": 18,
        "shots": 25,
        "misses": 3,
        "rockets": 8,
        "lasers": 3,
        "accuracy": 0.72,
        "hits_rocket": 6,
        "hits_laser": 2,
        "rocket_accuracy": 0.75,
        "laser_accuracy": 0.6667
      },
      "win_context": { "winner_team_id": 0 }
    }
  ],
  "pagination": {
    "total": 15,
    "limit": 10,
    "offset": 0
  }
}
```

Note: The `pagination` object uses the same `{ total, limit, offset }` shape as existing map/series endpoints for consistency (e.g., `MapCombatStatsListResponse`, `SeriesCombatListResponse`).

## Steps

- [Done] Phase 1 - Interface & Service Implementation (base endpoint)
- [Done] Phase 2 - Controller Route (base endpoint)
- [Done] Phase 3 - Unit Tests (base endpoint)
- [Done] Phase 4 - API Contract Update (base endpoint)
- [Done] Phase 5 - Live QA Smoke Testing (base endpoint)
- [Done] Phase 6 - Plugin: Add `hits_rocket`/`hits_laser` tracking (PHP)
- [Done] Phase 7 - API: Add `kd_ratio` to all player counter endpoints
- [Done] Phase 8 - API: Add `hits_rocket`, `hits_laser`, `rocket_accuracy`, `laser_accuracy` to all player counter endpoints
- [Done] Phase 9 - API: Add win rate to player maps history endpoint
- [Done] Phase 10 - Unit Tests (derived stats)
- [Done] Phase 11 - API Contract Update (derived stats)
- [Done] Phase 12 - Live QA Smoke Testing (derived stats)

### Phase 1 - Interface & Service Implementation

- [Todo] P1.1 - Add `PlayerCombatMapHistoryEntry` interface to `stats-read.service.ts`
  - New interface containing the per-map entry fields for a single player:
    ```typescript
    export interface PlayerCombatMapHistoryEntry {
      map_uid: string;
      map_name: string;
      played_at: string;
      duration_seconds: number;
      counters: PlayerCountersDelta;
      win_context: Record<string, unknown>;
    }
    ```
  - This is a focused subset of `MapCombatStatsEntry` (without `player_stats` record, `team_stats`, `totals`, `event_id`) since we are returning stats for one specific player.

- [Todo] P1.2 - Add `PlayerCombatMapHistoryResponse` interface to `stats-read.service.ts`
  ```typescript
  export interface PlayerCombatMapHistoryResponse {
    server_login: string;
    player_login: string;
    maps: PlayerCombatMapHistoryEntry[];
    pagination: {
      total: number;
      limit: number;
      offset: number;
    };
  }
  ```
  - Uses the same `pagination` shape as `MapCombatStatsListResponse` and `SeriesCombatListResponse` for consistency.

- [Todo] P1.3 - Add `getPlayerCombatMapHistory()` method to `StatsReadService`
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.ts`
  - Signature: `async getPlayerCombatMapHistory(serverLogin: string, playerLogin: string, limit: number, offset: number, since?: string, until?: string): Promise<PlayerCombatMapHistoryResponse>`
  - Implementation:
    1. Resolve server via `this.serverResolver.resolve(serverLogin)`.
    2. Convert `since`/`until` to `BigInt` millis (same pattern as `getMapCombatStatsList`).
    3. Call `this.fetchLifecycleEvents(server.id, 1000, sinceMs, untilMs)`.
    4. Loop through events, call `this.extractMapCombatEntry(event)` for each.
    5. For each valid `MapCombatStatsEntry`, check if `entry.player_stats[playerLogin]` exists.
    6. If yes, build a `PlayerCombatMapHistoryEntry` from it (extract that player's counters + map metadata + win_context).
    7. Collect all matching entries (already ordered most-recent first from the query).
    8. Apply pagination: `total = allEntries.length`, `data = allEntries.slice(offset, offset + limit)`.
    9. Return `{ server_login: serverLogin, player_login: playerLogin, maps: data, pagination: { total, limit, offset } }`.
  - If the player is not found in any map (total === 0), return the empty response with `maps: []` and `total: 0` (not a 404). This is consistent with how `getMapCombatStatsList` returns empty `maps: []` when there are no map.end events.

### Phase 2 - Controller Route

- [Todo] P2.1 - Add `getPlayerCombatMapHistory` route to `StatsReadController`
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.controller.ts`
  - Route: `@Get(':serverLogin/stats/combat/players/:login/maps')`
  - Important: This route MUST be defined BEFORE the existing `@Get(':serverLogin/stats/combat/players/:login')` route in the controller. If placed after, NestJS/Fastify will match the `:login` param with the literal string `"maps"` and never reach the new route. The `/maps` sub-resource route must have higher priority.
  - Swagger decorators (mandatory):
    - `@ApiOperation({ summary: 'Get player combat stats across recent maps', description: '...' })`
    - `@ApiParam` for `serverLogin` and `login`
    - `@ApiQuery` for `limit`, `offset`, `since`, `until`
    - `@ApiResponse` for 200 and 404
  - Query DTO: Reuse `PaginatedTimeRangeQueryDto` (already includes `limit`, `offset`, `since`, `until`).
  - Default `limit` to 10 (not the usual 50 -- this is a "last N maps" view where 10 is more natural).
  - Delegate to `this.statsService.getPlayerCombatMapHistory(serverLogin, login, query.limit ?? 10, query.offset ?? 0, query.since, query.until)`.
  - Add the `PlayerCombatMapHistoryResponse` to the controller's imports.

### Phase 3 - Unit Tests

- [Todo] P3.1 - Add service tests in `stats-read.service.spec.ts`
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.spec.ts`
  - Add a new `describe('getPlayerCombatMapHistory')` block after the existing `getMapPlayerCombatStats` tests.
  - Test cases (minimum 7):
    1. Returns empty `maps` array when no lifecycle events exist.
    2. Returns only maps where the target player participated (filters out maps where player is absent from `player_counters_delta`).
    3. Returns correct counters (kills, deaths, hits, shots, misses, rockets, lasers, accuracy) for the player on each map.
    4. Returns maps ordered most-recent first (verify order by `played_at`).
    5. Applies pagination (`limit`).
    6. Applies pagination (`offset`).
    7. Includes `win_context` and `duration_seconds` in each map entry.
  - Reuse existing `makeMapEndEvent` helper and `makeServer`/`makeResolverStub`/`makePrismaStub` factories.

- [Todo] P3.2 - Add controller tests in `stats-read.controller.spec.ts`
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.controller.spec.ts`
  - Add `getPlayerCombatMapHistory` to the service stub.
  - Test cases (minimum 3):
    1. Calls service with default pagination (limit=10, offset=0).
    2. Passes custom limit, offset, since, until to service.
    3. Returns service response directly (no transformation).

- [Todo] P3.3 - Run full test suite and verify all tests pass
  - Command: `cd /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server && npm run test`
  - All existing tests must remain green. New tests must pass.

### Phase 4 - API Contract Update

- [Todo] P4.1 - Add endpoint to the API read endpoints table in `NEW_API_CONTRACT.md`
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/NEW_API_CONTRACT.md`
  - Add a new row to the table at ~line 723 (after the series row):
    ```
    | `GET`  | `/v1/servers/:serverLogin/stats/combat/players/:login/maps`           | Player combat stats across recent maps       | Done ✅    | P2.6     |
    ```
  - Also add to the full endpoints summary table (~line 1130).

- [Todo] P4.2 - Add endpoint documentation section in `NEW_API_CONTRACT.md`
  - Add after the series documentation (~line 775), before section 2.5:
    ```markdown
    **`GET /v1/servers/:serverLogin/stats/combat/players/:login/maps`** (P2.6)
    - Returns a per-map combat history for a single player, ordered most-recent first.
    - Query params: `limit` (default 10, max 200), `offset` (default 0), `since` (ISO8601), `until` (ISO8601).
    - Response: `{ server_login, player_login, maps: PlayerCombatMapHistoryEntry[], pagination: { total, limit, offset } }`.
    - Each entry: `{ map_uid, map_name, played_at, duration_seconds, counters: PlayerCountersDelta, win_context }`.
    - Data source: same lifecycle `map.end` events as P2.5 endpoints, filtered to maps where the player has `player_counters_delta` data.
    - Returns empty `maps: []` (not 404) if the player has no map history.
    ```

### Phase 5 - Live QA Smoke Testing

- [Todo] P5.1 - Ensure the dev stack is running
  - Start PostgreSQL: `cd /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server && docker compose up -d postgres`
  - Start server: `npm run start:dev`
  - Verify server is up: `curl -s http://localhost:3000/v1/servers | head -1`

- [Todo] P5.2 - Seed test data via existing endpoints
  - Register a test server via PUT `/v1/servers/qa-p26-server/link/registration`.
  - Send a lifecycle `map.end` event with `player_counters_delta` containing 2 players via POST `/v1/plugin/events`.
  - Send a second lifecycle `map.end` event for a different map with only 1 of the 2 players.
  - Send a third lifecycle `map.end` event for a third map with both players.

- [Todo] P5.3 - Test the new endpoint
  - `GET /v1/servers/qa-p26-server/stats/combat/players/player1/maps` -- should return 3 maps (player1 was in all 3).
  - `GET /v1/servers/qa-p26-server/stats/combat/players/player2/maps` -- should return 2 maps (player2 was only in 2).
  - Verify `limit=1` returns only 1 map with `total: 3`.
  - Verify `offset=1` skips the first map.
  - Verify counters match what was sent.
  - Verify ordering is most-recent first.
  - `GET /v1/servers/qa-p26-server/stats/combat/players/nonexistent/maps` -- should return empty `maps: []` with `total: 0`.

- [Todo] P5.4 - Verify existing endpoints still work
  - `GET /v1/servers/qa-p26-server/stats/combat/maps` -- still returns all maps.
  - `GET /v1/servers/qa-p26-server/stats/combat/players/player1` -- cumulative counters still work.

- [Todo] P5.5 - Clean up test data
  - Delete the test server: `DELETE /v1/servers/qa-p26-server` (cascading delete removes events).

---

### Phase 6 - Plugin: Add `hits_rocket`/`hits_laser` tracking (PHP)

This phase modifies the ManiaControl PHP plugin to track per-weapon hit counts, enabling rocket and laser accuracy computation on the API side.

- [Todo] P6.1 - Update `PlayerCombatStatsStore::buildDefaultCounterRow()` to include `hits_rocket` and `hits_laser`
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-plugin/src/Stats/PlayerCombatStatsStore.php`
  - Add `'hits_rocket' => 0` and `'hits_laser' => 0` to the `buildDefaultCounterRow()` return array (after `'lasers' => 0`).

- [Todo] P6.2 - Update `PlayerCombatStatsStore::recordHit()` to accept `$weaponId` parameter
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-plugin/src/Stats/PlayerCombatStatsStore.php`
  - Change signature from `public function recordHit($login)` to `public function recordHit($login, $weaponId = null)`.
  - After incrementing `$this->playerCounters[$normalizedLogin]['hits']++`, add:
    ```php
    if ($weaponId === self::WEAPON_LASER) {
        $this->playerCounters[$normalizedLogin]['hits_laser']++;
    }
    if ($weaponId === self::WEAPON_ROCKET) {
        $this->playerCounters[$normalizedLogin]['hits_rocket']++;
    }
    ```
  - This follows the exact same pattern as `recordShot()`.

- [Todo] P6.3 - Update `PlayerCombatStatsStore::withComputedFields()` to include `rocket_accuracy` and `laser_accuracy`
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-plugin/src/Stats/PlayerCombatStatsStore.php`
  - After computing `$counters['accuracy']`, add:
    ```php
    $rockets = isset($counters['rockets']) ? (int) $counters['rockets'] : 0;
    $hitsRocket = isset($counters['hits_rocket']) ? (int) $counters['hits_rocket'] : 0;
    $counters['rocket_accuracy'] = ($rockets > 0) ? round($hitsRocket / $rockets, 4) : 0.0;

    $lasers = isset($counters['lasers']) ? (int) $counters['lasers'] : 0;
    $hitsLaser = isset($counters['hits_laser']) ? (int) $counters['hits_laser'] : 0;
    $counters['laser_accuracy'] = ($lasers > 0) ? round($hitsLaser / $lasers, 4) : 0.0;
    ```

- [Todo] P6.4 - Update `CombatDomainTrait::updateCombatStatsCounters()` to pass `$weaponId` to `recordHit()`
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-plugin/src/Domain/Combat/CombatDomainTrait.php`
  - In the `switch` block, change the `shootmania_event_onhit` case from:
    ```php
    case 'shootmania_event_onhit':
        $this->playerCombatStatsStore->recordHit($shooterLogin);
        break;
    ```
    to:
    ```php
    case 'shootmania_event_onhit':
        $this->playerCombatStatsStore->recordHit($shooterLogin, $weaponId);
        break;
    ```
  - The `$weaponId` variable is already extracted earlier in the method (line ~158-161), so no additional parsing is needed.

- [Todo] P6.5 - Update `MatchAggregateTelemetryTrait::getCombatCounterKeys()` to include new keys
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-plugin/src/Domain/Match/MatchAggregateTelemetryTrait.php`
  - Change `getCombatCounterKeys()` return from:
    ```php
    return array('kills', 'deaths', 'hits', 'shots', 'misses', 'rockets', 'lasers', 'accuracy');
    ```
    to:
    ```php
    return array('kills', 'deaths', 'hits', 'shots', 'misses', 'rockets', 'lasers', 'hits_rocket', 'hits_laser', 'accuracy', 'rocket_accuracy', 'laser_accuracy');
    ```

- [Todo] P6.6 - Update `MatchAggregateTelemetryTrait::buildCombatCounterDelta()` to include new numeric keys
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-plugin/src/Domain/Match/MatchAggregateTelemetryTrait.php`
  - Change `$numericCounterKeys` from:
    ```php
    $numericCounterKeys = array('kills', 'deaths', 'hits', 'shots', 'misses', 'rockets', 'lasers');
    ```
    to:
    ```php
    $numericCounterKeys = array('kills', 'deaths', 'hits', 'shots', 'misses', 'rockets', 'lasers', 'hits_rocket', 'hits_laser');
    ```
  - After computing `$deltaRow['accuracy']`, add computation for `rocket_accuracy` and `laser_accuracy`:
    ```php
    $deltaRockets = isset($deltaRow['rockets']) ? (int) $deltaRow['rockets'] : 0;
    $deltaHitsRocket = isset($deltaRow['hits_rocket']) ? (int) $deltaRow['hits_rocket'] : 0;
    $deltaRow['rocket_accuracy'] = ($deltaRockets > 0 ? round($deltaHitsRocket / $deltaRockets, 4) : 0.0);

    $deltaLasers = isset($deltaRow['lasers']) ? (int) $deltaRow['lasers'] : 0;
    $deltaHitsLaser = isset($deltaRow['hits_laser']) ? (int) $deltaRow['hits_laser'] : 0;
    $deltaRow['laser_accuracy'] = ($deltaLasers > 0 ? round($deltaHitsLaser / $deltaLasers, 4) : 0.0);
    ```

- [Todo] P6.7 - Update `MatchAggregateTelemetryTrait::buildZeroCounterRow()` to include new keys
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-plugin/src/Domain/Match/MatchAggregateTelemetryTrait.php`
  - Add `'hits_rocket' => 0`, `'hits_laser' => 0`, `'rocket_accuracy' => 0.0`, `'laser_accuracy' => 0.0` to the return array.

- [Todo] P6.8 - Update `MatchAggregateTelemetryTrait::buildCombatCounterTotals()` to sum new keys
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-plugin/src/Domain/Match/MatchAggregateTelemetryTrait.php`
  - After the existing `$totals['lasers'] += ...` line, add:
    ```php
    $totals['hits_rocket'] += isset($counterRow['hits_rocket']) ? (int) $counterRow['hits_rocket'] : 0;
    $totals['hits_laser'] += isset($counterRow['hits_laser']) ? (int) $counterRow['hits_laser'] : 0;
    ```
  - After the existing `$totals['accuracy'] = ...` line, add:
    ```php
    $totals['rocket_accuracy'] = ($totals['rockets'] > 0 ? round($totals['hits_rocket'] / $totals['rockets'], 4) : 0.0);
    $totals['laser_accuracy'] = ($totals['lasers'] > 0 ? round($totals['hits_laser'] / $totals['lasers'], 4) : 0.0);
    ```

- [Todo] P6.9 - Update `MatchAggregateTelemetryTrait::buildTeamCounterDelta()` to sum new keys per team
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-plugin/src/Domain/Match/MatchAggregateTelemetryTrait.php`
  - In the per-player loop (after `$teamsByKey[$teamKey]['totals']['lasers'] += ...`), add:
    ```php
    $teamsByKey[$teamKey]['totals']['hits_rocket'] += isset($counterRow['hits_rocket']) ? (int) $counterRow['hits_rocket'] : 0;
    $teamsByKey[$teamKey]['totals']['hits_laser'] += isset($counterRow['hits_laser']) ? (int) $counterRow['hits_laser'] : 0;
    ```
  - In the per-team post-processing loop (after computing `$teamRow['totals']['accuracy']`), add:
    ```php
    $teamRockets = isset($teamRow['totals']['rockets']) ? (int) $teamRow['totals']['rockets'] : 0;
    $teamHitsRocket = isset($teamRow['totals']['hits_rocket']) ? (int) $teamRow['totals']['hits_rocket'] : 0;
    $teamRow['totals']['rocket_accuracy'] = ($teamRockets > 0 ? round($teamHitsRocket / $teamRockets, 4) : 0.0);

    $teamLasers = isset($teamRow['totals']['lasers']) ? (int) $teamRow['totals']['lasers'] : 0;
    $teamHitsLaser = isset($teamRow['totals']['hits_laser']) ? (int) $teamRow['totals']['hits_laser'] : 0;
    $teamRow['totals']['laser_accuracy'] = ($teamLasers > 0 ? round($teamHitsLaser / $teamLasers, 4) : 0.0);
    ```

- [Todo] P6.10 - Run plugin quality checks
  - Command: `bash /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-plugin/scripts/check-quality.sh`
  - All PHP syntax checks must pass.

### Phase 7 - API: Add `kd_ratio` to all player counter endpoints

This phase adds the `kd_ratio` computed field everywhere player combat counters are returned. The formula is `kills / deaths` (when `deaths === 0`, use `kills` as the ratio value, i.e. treat it as `kills / 1` effectively but returning `kills` directly; when both are 0 return `0.0`).

- [Todo] P7.1 - Add `kd_ratio` to `PlayerCountersDelta` interface
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.ts`
  - Add `kd_ratio: number;` to the `PlayerCountersDelta` interface (after `accuracy`).

- [Todo] P7.2 - Add `kd_ratio` to `PlayerCounters` interface
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.ts`
  - Add `kd_ratio: number;` to the `PlayerCounters` interface (after `accuracy`).

- [Todo] P7.3 - Create private helper `computeKdRatio(kills: number, deaths: number): number`
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.ts`
  - Add as a private method on `StatsReadService` (or a module-level pure function at top of file):
    ```typescript
    private computeKdRatio(kills: number, deaths: number): number {
      if (deaths === 0) return kills > 0 ? kills : 0;
      return Math.round((kills / deaths) * 10000) / 10000;
    }
    ```
  - Rounding to 4 decimal places matches the existing `accuracy` precision pattern.

- [Todo] P7.4 - Add `kd_ratio` to `extractMapCombatEntry()` -- per-player counters in `player_stats`
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.ts`
  - In the loop that builds `playerStats[login]` inside `extractMapCombatEntry()`, add `kd_ratio` computed from the player's kills and deaths.
  - This automatically propagates to all endpoints that use `MapCombatStatsEntry.player_stats`:
    - `GET .../stats/combat/maps` (per-player entries within each map)
    - `GET .../stats/combat/maps/:mapUid` (per-player entries)
    - `GET .../stats/combat/maps/:mapUid/players/:login` (single player counters)
    - `GET .../stats/combat/series` (per-player entries within each series map)

- [Todo] P7.5 - Add `kd_ratio` to `getCombatPlayersCounters()` -- the per-player list endpoint
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.ts`
  - In `getCombatPlayersCounters()`, when building `PlayerCounters` objects from the combat event's `player_counters`, compute and add `kd_ratio`.

- [Todo] P7.6 - Add `kd_ratio` to `getPlayerCombatCounters()` -- the single player detail endpoint
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.ts`
  - In `getPlayerCombatCounters()`, when building the `counters` object in the return value, compute and add `kd_ratio`.

- [Todo] P7.7 - Add `kd_ratio` to `getPlayerCombatMapHistory()` -- per-map counters in the new endpoint
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.ts`
  - This is automatically handled if P7.4 is done, since the new endpoint reads from `entry.player_stats[playerLogin]` which already includes `kd_ratio` from `extractMapCombatEntry()`.
  - Verify this is the case -- if `getPlayerCombatMapHistory()` builds its own counters object separately, add `kd_ratio` there too.

- [Todo] P7.8 - Update `RawLifecyclePayload` and `RawCombatPayload` type hints for `kd_ratio`
  - The raw payloads from the database may or may not have `kd_ratio` (it is computed server-side, not stored). No changes needed to raw types -- `kd_ratio` is purely a computed field added at response time.
  - Confirm this by reviewing the flow: `kd_ratio` should never be persisted, only computed when building response objects.

### Phase 8 - API: Add `hits_rocket`, `hits_laser`, `rocket_accuracy`, `laser_accuracy`

These fields are optional/nullable because events emitted before the plugin update (Phase 6) will not contain `hits_rocket`/`hits_laser`. The API must gracefully handle their absence.

- [Todo] P8.1 - Add new fields to `PlayerCountersDelta` interface
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.ts`
  - Add to the interface:
    ```typescript
    hits_rocket: number | null;
    hits_laser: number | null;
    rocket_accuracy: number | null;
    laser_accuracy: number | null;
    ```
  - These are `number | null` (not optional `?`) so they always appear in the JSON response. `null` means "data not available from this event".

- [Todo] P8.2 - Add new fields to `PlayerCounters` interface
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.ts`
  - Add the same 4 fields as in P8.1.

- [Todo] P8.3 - Update `RawLifecyclePayload.aggregate_stats.player_counters_delta` type
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.ts`
  - Add `hits_rocket?: number;` and `hits_laser?: number;` to the `player_counters_delta` value type in `RawLifecyclePayload`.
  - Also add to `RawCombatPayload.player_counters` value type.

- [Todo] P8.4 - Create private helper `computeWeaponAccuracy(hits: number | null, shots: number): number | null`
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.ts`
  - Returns `null` if `hits` is `null`. Otherwise: `shots > 0 ? Math.round((hits / shots) * 10000) / 10000 : 0`.

- [Todo] P8.5 - Update `extractMapCombatEntry()` to include new fields in `player_stats`
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.ts`
  - When building `playerStats[login]`, read `c.hits_rocket` and `c.hits_laser` from the raw payload.
  - If present (`!== undefined`), set them as numbers. If absent, set them as `null`.
  - Compute `rocket_accuracy` and `laser_accuracy` using the helper from P8.4.

- [Todo] P8.6 - Update `getCombatPlayersCounters()` to include new fields
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.ts`
  - When building `PlayerCounters` objects, extract `hits_rocket`/`hits_laser` from `RawCombatPayload.player_counters` and compute derived accuracies.

- [Todo] P8.7 - Update `getPlayerCombatCounters()` to include new fields
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.ts`
  - Same approach as P8.6, but for the single-player detail endpoint.

### Phase 9 - API: Add win rate to player maps history endpoint

- [Todo] P9.1 - Update `RawLifecyclePayload` type to type `team_counters_delta` more precisely
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.ts`
  - Change `team_counters_delta?: unknown[];` to:
    ```typescript
    team_counters_delta?: Array<{
      team_id?: number | null;
      player_logins?: string[];
      [key: string]: unknown;
    }>;
    ```
  - This allows reading `team_id` and `player_logins` for win determination without affecting the rest of the code (the `unknown[]` was already cast to `unknown[]` in `extractMapCombatEntry` for `team_stats`).

- [Todo] P9.2 - Add `team_stats` field to `MapCombatStatsEntry` extraction (preserve for win check)
  - The `team_stats` field in `MapCombatStatsEntry` is already extracted as `(agg.team_counters_delta as unknown[]) ?? []`. For win determination, the raw `team_counters_delta` array (with typed `team_id`/`player_logins`) is what we need.
  - Option A: Parse `team_counters_delta` into a richer type inside `extractMapCombatEntry()` and store it on `MapCombatStatsEntry`.
  - Option B (preferred): Create a separate private helper `determinePlayerWon(teamStats: unknown[], winContext: Record<string, unknown>, playerLogin: string): boolean | null` that casts and inspects the data.
  - Go with Option B to minimize changes to existing interfaces.

- [Todo] P9.3 - Create private helper `determinePlayerWon()`
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.ts`
  - Signature: `private determinePlayerWon(teamStats: unknown[], winContext: Record<string, unknown>, playerLogin: string): boolean | null`
  - Logic:
    1. Extract `winnerTeamId` from `winContext.winner_team_id`. If missing or not a number, return `null` (cannot determine).
    2. Iterate over `teamStats` entries. Each entry is expected to have `{ team_id: number, player_logins: string[] }`.
    3. Find the team entry whose `player_logins` array includes `playerLogin`.
    4. If found, return `team.team_id === winnerTeamId`.
    5. If the player is not found in any team, return `null` (cannot determine; player might have been unresolved).
  - Return type is `boolean | null` to distinguish "won"/"lost"/"unknown".

- [Todo] P9.4 - Add `won` field to `PlayerCombatMapHistoryEntry` interface
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.ts`
  - Add `won: boolean | null;` to the interface. `null` means win status could not be determined.

- [Todo] P9.5 - Add `maps_played`, `maps_won`, `win_rate` fields to `PlayerCombatMapHistoryResponse`
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.ts`
  - Add to the interface:
    ```typescript
    maps_played: number;
    maps_won: number;
    win_rate: number;
    ```
  - `maps_played` = total count of all maps (before pagination). `maps_won` = count of maps where `won === true`. `win_rate` = `maps_won / maps_played` (0.0 when `maps_played === 0`).

- [Todo] P9.6 - Update `getPlayerCombatMapHistory()` to compute win data
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.ts`
  - When building each `PlayerCombatMapHistoryEntry`, call `this.determinePlayerWon(entry.team_stats, entry.win_context, playerLogin)` and set the `won` field.
  - After collecting all entries (before pagination), compute:
    - `maps_played = allEntries.length`
    - `maps_won = allEntries.filter(e => e.won === true).length`
    - `win_rate = maps_played > 0 ? Math.round((maps_won / maps_played) * 10000) / 10000 : 0`
  - Include these in the response alongside `maps` and `pagination`.

- [Todo] P9.7 - Update Swagger descriptions for the player maps history endpoint
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.controller.ts`
  - Update the `@ApiOperation` description and `@ApiResponse` description to mention `maps_played`, `maps_won`, `win_rate`, and per-map `won` field.

### Phase 10 - Unit Tests (derived stats)

- [Todo] P10.1 - Add `kd_ratio` tests to existing service test blocks
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.spec.ts`
  - Add test cases within the existing `describe` blocks:
    - `getCombatPlayersCounters`: verify `kd_ratio` is present and correct (kills/deaths).
    - `getPlayerCombatCounters`: verify `kd_ratio` is present and correct.
    - `getMapCombatStatsList`: verify `kd_ratio` in each player's `player_stats` entry.
    - `getPlayerCombatMapHistory`: verify `kd_ratio` in each map's `counters`.
  - Edge cases:
    - `kd_ratio` when `deaths === 0` and `kills > 0` should return `kills`.
    - `kd_ratio` when both `kills === 0` and `deaths === 0` should return `0`.

- [Todo] P10.2 - Add `hits_rocket`/`hits_laser` tests
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.spec.ts`
  - Test cases:
    - When event payload includes `hits_rocket`/`hits_laser`, they appear in response with correct values.
    - When event payload does NOT include `hits_rocket`/`hits_laser` (old events), they appear as `null`.
    - `rocket_accuracy` = `hits_rocket / rockets`. `laser_accuracy` = `hits_laser / lasers`.
    - `rocket_accuracy`/`laser_accuracy` are `null` when `hits_rocket`/`hits_laser` are `null`.
  - Update `makeMapEndEvent` helper to optionally accept `hits_rocket`/`hits_laser` in player counter overrides.
  - Update `makeCombatEvent` helper similarly.

- [Todo] P10.3 - Add win rate tests for `getPlayerCombatMapHistory`
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.service.spec.ts`
  - Test cases (minimum 5):
    1. Correctly determines `won: true` when player's team matches `winner_team_id`.
    2. Correctly determines `won: false` when player's team does not match `winner_team_id`.
    3. Returns `won: null` when `team_counters_delta` is empty or player is not in any team.
    4. Returns `won: null` when `win_context` has no `winner_team_id`.
    5. `maps_won` and `win_rate` are computed correctly across multiple maps.
  - The `makeMapEndEvent` helper needs to be enhanced to accept `team_counters_delta` (currently hardcoded to `[]`). Add an optional `teamCountersDelta` parameter.

- [Todo] P10.4 - Add controller tests for updated Swagger descriptions
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.controller.spec.ts`
  - Verify controller still passes through service responses unchanged for all derived-stat-enriched endpoints.

- [Todo] P10.5 - Run full test suite
  - Command: `cd /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server && npm run test`
  - All tests must pass.

### Phase 11 - API Contract Update (derived stats)

- [Todo] P11.1 - Update `PlayerCountersDelta` documentation in `NEW_API_CONTRACT.md`
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/NEW_API_CONTRACT.md`
  - Wherever `PlayerCountersDelta` or player counter fields are documented, add the new fields:
    - `kd_ratio` (number): Kill/death ratio. `kills / deaths`. Returns `kills` when `deaths === 0`.
    - `hits_rocket` (number | null): Rocket hits. `null` if event predates plugin v2 (hits_rocket tracking).
    - `hits_laser` (number | null): Laser hits. `null` if event predates plugin v2.
    - `rocket_accuracy` (number | null): `hits_rocket / rockets`. `null` when `hits_rocket` is null.
    - `laser_accuracy` (number | null): `hits_laser / lasers`. `null` when `hits_laser` is null.
  - Update the `MapCombatStatsEntry` JSON example to include these fields.

- [Todo] P11.2 - Add win rate documentation to the player maps history endpoint section
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/NEW_API_CONTRACT.md`
  - Update the P2.6 endpoint documentation (added in Phase 4) to include:
    - Top-level response fields: `maps_played`, `maps_won`, `win_rate`.
    - Per-map entry field: `won` (boolean | null).
    - Win determination logic: player's team (from `team_counters_delta[].player_logins`) is compared against `win_context.winner_team_id`.
  - Update the response shape JSON example.

- [Todo] P11.3 - Update Swagger descriptions on all affected controller routes
  - File: `/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server/src/stats/stats-read.controller.ts`
  - Update `@ApiResponse` descriptions on:
    - `getCombatPlayers` -- mention `kd_ratio`, `hits_rocket`, `hits_laser`, `rocket_accuracy`, `laser_accuracy`.
    - `getPlayerCombatCounters` -- same new fields.
    - `getCombatMaps` -- per-player entries now include new fields.
    - `getCombatMapByUid` -- per-player entries now include new fields.
    - `getCombatMapPlayer` -- counters now include new fields.
    - `getCombatSeries` -- per-player entries within maps now include new fields.

### Phase 12 - Live QA Smoke Testing (derived stats)

- [Todo] P12.1 - Ensure the dev stack is running
  - Start PostgreSQL + server (same as Phase 5).

- [Todo] P12.2 - Seed test data with `hits_rocket`/`hits_laser` fields
  - Register test server.
  - Send lifecycle `map.end` events with `team_counters_delta` containing team assignments AND `player_counters_delta` with `hits_rocket`/`hits_laser` fields.
  - Include at least 3 maps: player1 wins 2, loses 1.
  - Include one map event WITHOUT `hits_rocket`/`hits_laser` (simulating old plugin data) to verify backward compat.

- [Todo] P12.3 - Test `kd_ratio` on all endpoints
  - `GET .../stats/combat/players` -- each player entry has `kd_ratio`.
  - `GET .../stats/combat/players/:login` -- `counters.kd_ratio` is present.
  - `GET .../stats/combat/maps` -- each map's `player_stats` entries have `kd_ratio`.
  - `GET .../stats/combat/maps/:mapUid` -- player entries have `kd_ratio`.
  - `GET .../stats/combat/maps/:mapUid/players/:login` -- `counters.kd_ratio` is present.
  - `GET .../stats/combat/players/:login/maps` -- each map's `counters.kd_ratio` is present.
  - Verify edge case: player with 0 deaths has `kd_ratio` = kills.

- [Todo] P12.4 - Test `hits_rocket`/`hits_laser`/`rocket_accuracy`/`laser_accuracy`
  - Verify events with the new fields return numeric values.
  - Verify events without the new fields return `null` for all 4 fields.

- [Todo] P12.5 - Test win rate on player maps history
  - `GET .../stats/combat/players/player1/maps` -- verify `maps_played`, `maps_won`, `win_rate` top-level fields.
  - Verify per-map `won` boolean matches expected outcomes.
  - Verify `won: null` when team data is unavailable.

- [Todo] P12.6 - Verify all existing endpoints still work (no regressions)
  - Run through all existing endpoints and verify responses are valid (new fields added, no fields removed).

- [Todo] P12.7 - Clean up test data
  - Delete test server.

## Evidence / Artifacts

- Unit tests: `pixel-control-server/src/stats/stats-read.service.spec.ts`, `pixel-control-server/src/stats/stats-read.controller.spec.ts`
- API contract: `NEW_API_CONTRACT.md`
- Plugin files modified: `pixel-control-plugin/src/Stats/PlayerCombatStatsStore.php`, `pixel-control-plugin/src/Domain/Combat/CombatDomainTrait.php`, `pixel-control-plugin/src/Domain/Match/MatchAggregateTelemetryTrait.php`

## Success Criteria

### Base endpoint (Phases 1-5)
- New endpoint `GET /v1/servers/:serverLogin/stats/combat/players/:login/maps` returns paginated per-map combat history for a single player.
- Response includes correct per-map counters, map metadata (uid, name, played_at, duration_seconds), and win_context.
- Maps are ordered most-recent first.
- Pagination (limit/offset) works correctly.
- Time-range filtering (since/until) works correctly.
- Returns empty `maps: []` when player has no history (not 404).
- All new unit tests pass (7+ service tests, 3+ controller tests).
- All existing tests remain green.
- Swagger/OpenAPI documentation is present on the new route.
- `NEW_API_CONTRACT.md` is updated with the new endpoint.
- Live QA smoke tests confirm correct behavior with real HTTP requests.

### Plugin modifications (Phase 6)
- `PlayerCombatStatsStore` tracks `hits_rocket` and `hits_laser` separately per weapon type on hit events.
- `recordHit()` accepts optional `$weaponId` parameter and increments weapon-specific hit counters.
- `withComputedFields()` computes `rocket_accuracy` and `laser_accuracy`.
- Delta computation, totals, and team aggregates all include the new counter keys.
- PHP quality checks pass.

### Derived stats (Phases 7-12)
- `kd_ratio` is present on ALL endpoints that return player combat counters:
  - `GET .../stats/combat/players` (each player entry)
  - `GET .../stats/combat/players/:login` (counters object)
  - `GET .../stats/combat/maps` (per-player entries in each map)
  - `GET .../stats/combat/maps/:mapUid` (per-player entries)
  - `GET .../stats/combat/maps/:mapUid/players/:login` (counters)
  - `GET .../stats/combat/players/:login/maps` (per-map counters)
  - `GET .../stats/combat/series` (per-player entries within maps)
- `kd_ratio` handles edge cases: 0 deaths returns kills; 0 kills and 0 deaths returns 0.
- `hits_rocket`, `hits_laser`, `rocket_accuracy`, `laser_accuracy` are present on all counter endpoints.
- These 4 fields are `null` for events predating the plugin update (backward compatible).
- Win rate fields (`maps_played`, `maps_won`, `win_rate`, per-map `won`) work correctly on the player maps history endpoint.
- `win_rate` correctly identifies winners by matching player login against `team_counters_delta[].player_logins` and comparing team_id with `win_context.winner_team_id`.
- `won: null` when team assignment data is unavailable.
- All unit tests pass. All existing tests remain green.
- `NEW_API_CONTRACT.md` fully documents all new fields.
- Swagger descriptions updated on all affected routes.

## Notes / outcomes

- Phases 1-12 all completed in a single session (2026-03-01).
- Service, controller, and both spec files modified in a comprehensive single pass (phases 1, 2, 3, 7, 8, 9, 10 collapsed into coordinated edits to avoid conflicts).
- 228 unit tests pass (up from 96 before P2.6). 29 live smoke assertions pass.
- PHP quality check: 76 files lint-OK, 32/32 tests pass.
- Build: clean (no TypeScript errors).
- Key implementation details:
  - New endpoint `/v1/servers/:serverLogin/stats/combat/players/:login/maps` placed BEFORE `players/:login` to prevent route shadowing.
  - `computeKdRatio`: kills/deaths rounded 4dp; deaths=0 returns kills; kills=deaths=0 returns 0.
  - `computeWeaponAccuracy`: returns null when hits_rocket/hits_laser absent (backward compat).
  - `determinePlayerWon`: inspects team_counters_delta[].player_logins vs win_context.winner_team_id; null when data unavailable.
  - `team_counters_delta` type changed from `unknown[]` to typed array in RawLifecyclePayload.
  - Smoke test script: `scripts/qa-p2.6-smoke.sh`.
