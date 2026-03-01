# PLAN - Comprehensive QA Integration Test Suite for P2/P2.5/P2.6 Endpoints (2026-03-01)

## Context

- **Purpose**: Create a single comprehensive bash QA script (`pixel-control-server/scripts/qa-full-integration.sh`) that simulates a realistic BO3 Elite match session end-to-end -- from server registration through match completion -- then validates every single P0 through P2.6 read endpoint returns correct, coherent data. This replaces ad-hoc smoke testing with a deterministic, data-consistent integration suite.
- **Scope**: In-scope: one self-contained bash script that injects realistic payloads matching exact plugin envelope formats, then asserts against all 19 GET/read endpoints plus the POST ingestion endpoint and link endpoints. Out-of-scope: P3+ admin/socket proxy endpoints (not yet implemented), unit tests, Vitest changes.
- **Goals**:
  1. Script simulates a full competitive BO3 Elite session with 6 players, 2 teams, 3 maps, multiple rounds per map, realistic combat events, scores, and aggregate stats.
  2. Every injected payload matches the exact structure the PHP plugin produces (envelope fields, payload shapes, counter semantics, aggregate_stats structure).
  3. After injection, every read endpoint is called and responses are validated for correct HTTP status, response structure, and numeric correctness (e.g. kill counts, accuracy values, kd_ratio, win_rate).
  4. Edge cases are tested: pagination (limit/offset), time-range filtering (since/until), 404 for unknown server/player/map, empty-state responses, idempotency deduplication.
  5. Script is macOS-compatible (no GNU-only commands), self-contained (only requires curl + jq), cleans up after itself (deletes test server), and exits non-zero on first failure.
- **Non-goals**: Testing P3+ admin endpoints. Replacing the existing `qa-p0-smoke.sh` or `qa-p1-smoke.sh` scripts. Testing WebSocket/CommunicationManager flows. Testing the Docker build process itself.
- **Constraints / assumptions**:
  - Server must be running on `http://localhost:3000` with PostgreSQL accessible.
  - The script uses unique timestamps per run (millisecond epoch * run suffix) to avoid idempotency collisions across runs.
  - The plugin combat stats store uses: `WEAPON_LASER = 1`, `WEAPON_ROCKET = 2`. Weapon-specific counters (hits_rocket, hits_laser, rockets, lasers) must be populated accordingly.
  - `player_counters` on combat events are cumulative session totals (not deltas). The aggregate_stats `player_counters_delta` on lifecycle `map.end` events are per-map deltas.
  - `scores_snapshot` on combat `scores` events must include `section`, `use_teams`, `winner_team_id`, `team_scores[]`, `player_scores[]`, matching what `buildScoresContextSnapshot()` produces.
  - The `map.end` event must carry both `aggregate_stats` (with `scope: "map"`, `player_counters_delta`, `team_counters_delta`, `totals`, `win_context`, `window`) and `map_rotation` (with `current_map.uid` and `current_map.name`).
  - The series endpoint (`stats/combat/series`) requires a `match.begin` + `match.end` lifecycle pair to form a series boundary, and collects all `map.end` events between those timestamps.
  - Stats read service computes `kd_ratio` as `kills / deaths` (rounded to 4dp; returns `kills` when deaths=0, 0 when both 0).
  - Stats read service computes weapon accuracy fields from raw counters, not from pre-computed accuracy values in the payload.
  - Players read service extracts from `payload.player` (login, nickname, team_id, is_spectator, is_connected, has_joined_game, auth_level, auth_name) and from `payload.event_kind` to determine connectivity state.
  - Maps read service looks for `payload.map_rotation.map_pool` on any lifecycle event.
  - Mode read service returns stored mode events with `event_name`, `event_id`, `source_callback`, `source_time`, `raw_callback_summary`.
  - Capabilities endpoint reads from `ConnectivityEvent` table (not `Event`), looking for `payload.capabilities` on registration or heartbeat events.
- **Environment snapshot**: Branch `main`, P0+P1 merged. All P2/P2.5/P2.6 service code is implemented and present on `main`.
- **Dependencies**: curl, jq, running pixel-control-server on port 3000 with PostgreSQL.

## Steps

- [Done] Phase 1 - Fixture data design
- [Done] Phase 2 - Script scaffold and helpers
- [Done] Phase 3 - Event injection implementation
- [Done] Phase 4 - Endpoint validation implementation
- [Done] Phase 5 - Edge case and regression tests
- [Done] Phase 6 - Live QA run

---

### Phase 1 - Fixture data design

Design the exact data for the BO3 simulation. All numbers must be internally consistent.

- [Todo] P1.1 - Define the 6 players with logins, nicknames, team assignments
  - Team 0 (Red): `qa-player-alpha` (Alpha), `qa-player-bravo` (Bravo), `qa-player-charlie` (Charlie)
  - Team 1 (Blue): `qa-player-delta` (Delta), `qa-player-echo` (Echo), `qa-player-foxtrot` (Foxtrot)
  - All players: `team_id` as integer 0 or 1, `is_spectator: false`, `is_connected: true`, `has_joined_game: true`, `auth_level: 0`, `auth_name: "player"`

- [Todo] P1.2 - Define the 3 maps
  - Map 1: `uid=qa-map-oasis`, `name=Oasis Elite`, `file=Oasis.Map.Gbx`, `environment=Storm`
  - Map 2: `uid=qa-map-zenith`, `name=Zenith Storm`, `file=Zenith.Map.Gbx`, `environment=Storm`
  - Map 3: `uid=qa-map-colosseum`, `name=Colosseum`, `file=Colosseum.Map.Gbx`, `environment=Storm`

- [Todo] P1.3 - Design per-map combat data with exact counter values
  - For each of the 3 maps, define exactly how many rounds are played (4-5 per map), which player is attacker/defender each round, and per-round combat events (onshoot, onhit, onnearmiss, onarmorempty).
  - Track cumulative `player_counters` values that the plugin would maintain across the entire session.
  - Track per-map `player_counters_delta` values (difference between map-start and map-end cumulative counters).
  - Track weapon breakdown: each shot/hit has a `weapon_id` (1=laser or 2=rocket). The counters `rockets`, `lasers`, `hits_rocket`, `hits_laser` must be consistent.
  - Design the data so that:
    - `accuracy = hits / shots` is exact to 4 decimal places
    - `kd_ratio = kills / deaths` is exact to 4 decimal places
    - `rocket_accuracy = hits_rocket / rockets` is exact to 4 decimal places
    - `laser_accuracy = hits_laser / lasers` is exact to 4 decimal places
    - `attack_win_rate = attack_rounds_won / attack_rounds_played` is exact to 4 decimal places
    - `defense_win_rate = defense_rounds_won / defense_rounds_played` is exact to 4 decimal places
  - Example Map 1 (Oasis Elite) - 5 rounds, Team 0 wins 3-2:
    - Round 1: alpha attacks, delta/echo/foxtrot defend. Alpha captures (victoryType=2). alpha gets 2 kills (rocket), 1 death. Bravo/Charlie: defenders have some shots/hits/misses.
    - Round 2: delta attacks, alpha/bravo/charlie defend. Delta eliminated (victoryType=3). delta gets 1 kill, 2 deaths. alpha gets 1 kill.
    - Round 3: bravo attacks, delta/echo/foxtrot defend. Bravo eliminates all defenders (victoryType=4). bravo gets 3 kills.
    - Round 4: echo attacks, alpha/bravo/charlie defend. Time limit (victoryType=1). echo gets 0 kills, 1 death.
    - Round 5: charlie attacks, delta/echo/foxtrot defend. Charlie captures (victoryType=2). charlie gets 1 kill.
  - Map 1 outcome: Team 0 wins 3-2 (3 attack wins for Team 0's attackers + 2 defense wins for Team 1's attackers).
  - Map 2 and Map 3 follow similar patterns with Team 1 winning Map 2, and Team 0 winning Map 3 (BO3 ends 2-1 for Team 0).

- [Todo] P1.4 - Pre-compute all expected response values
  - For each endpoint, compute the exact expected JSON values that the service code will return.
  - This includes: kd_ratio, accuracy, rocket_accuracy, laser_accuracy, attack_win_rate, defense_win_rate for every player on every map and cumulatively.
  - Pre-compute series totals (sum of all 3 map totals).
  - Pre-compute `maps_won` and `win_rate` for the P2.6 player-map-history endpoint (per-player across 3 maps).

- [Todo] P1.5 - Design scores_snapshot payloads
  - After each round: `scores_section: "EndRound"`, `use_teams: true`, team_scores with `round_points`, `map_points`, `match_points`.
  - After each map end: `scores_section: "EndMap"`, with cumulative `map_points` and `match_points`.
  - After final map: `scores_section: "EndMatch"`, with final `match_points`.
  - `winner_team_id` must match the team that actually won.
  - `player_scores[]` must include all 6 players with `login`, `nickname`, `team_id`, `rank`, `round_points`, `map_points`, `match_points`.

---

### Phase 2 - Script scaffold and helpers

Create the bash script structure.

- [Todo] P2.1 - Create script file at `pixel-control-server/scripts/qa-full-integration.sh`
  - Shebang: `#!/usr/bin/env bash`
  - `set -euo pipefail`
  - Define constants: `API`, `SERVER_LOGIN=qa-integration-server`, color codes.
  - Define `RUN_ID=$(date +%s%N | cut -c1-13)` for run-unique idempotency keys (macOS: use `$(date +%s)000` fallback if `%N` not available).
  - Sequence counter for `source_sequence` / `source_time`: start at `$RUN_ID`, increment by 1 for each event.

- [Todo] P2.2 - Implement helper functions
  - `log_pass`, `log_fail`, `log_info`, `log_section` (colored output).
  - `assert_contains`, `assert_not_contains`, `assert_eq`, `assert_status_code`.
  - `assert_json_field` — uses jq to extract a field and compare to expected value. E.g. `assert_json_field "label" "$response" ".combat_summary.total_kills" "15"`.
  - `assert_json_field_gte` — numeric >= comparison.
  - `assert_json_type` — checks field type (string, number, array, object, null).
  - `assert_json_length` — checks array length.
  - `send_event` — wraps curl POST to `/v1/plugin/events` with standard headers (`X-Pixel-Server-Login`, `X-Pixel-Plugin-Version`, `Content-Type`). Returns the response body.
  - `next_seq` — returns next sequence number and increments.
  - `build_envelope` — generates an event envelope JSON given category, source_callback, payload. Auto-generates event_name, event_id, idempotency_key, source_sequence, source_time from parameters.
  - `cleanup` — DELETE the test server on exit (trap EXIT).
  - `check_prerequisites` — verify curl, jq are installed, server is reachable.

- [Todo] P2.3 - Implement payload builder functions
  - `build_registration_payload` — plugin_registration connectivity payload with capabilities, context, timestamp.
  - `build_heartbeat_payload` — plugin_heartbeat connectivity payload with queue/retry/outage snapshots, context with player counts.
  - `build_player_connect_payload` — player event with `event_kind: "player.connect"`, `transition_kind: "connectivity"`, full player snapshot.
  - `build_lifecycle_payload` — lifecycle event with variant, phase, state, source_channel.
  - `build_combat_payload` — combat event with event_kind, dimensions (weapon_id, shooter, victim, positions), player_counters (cumulative), field_availability.
  - `build_scores_payload` — combat scores event with scores_section, scores_snapshot, scores_result, player_counters.
  - `build_map_end_payload` — lifecycle map.end event with variant, aggregate_stats (scope=map, player_counters_delta, team_counters_delta, totals, win_context, window), map_rotation (current_map, map_pool).
  - `build_mode_event_payload` — mode event with raw_callback_summary for Elite start/end turn.

---

### Phase 3 - Event injection implementation

Inject all events in realistic chronological order.

- [Todo] P3.1 - Phase: Server setup
  - `PUT /v1/servers/qa-integration-server/link/registration` with `server_name: "QA Integration Server"`, `game_mode: "Elite"`, `title_id: "SMStormElite@nadeolabs"`.
  - Assert: response contains `link_token`.
  - Send connectivity `plugin_registration` event with full capabilities payload (including `admin_control`, `callback_groups`, `transport`, `queue` fields matching the plugin's `buildCapabilitiesPayload()` output).
  - Assert: response contains `"accepted"`.
  - Send connectivity `plugin_heartbeat` event with player counts (active: 6, total: 6, spectators: 0), queue/retry/outage snapshots.
  - Assert: response contains `"accepted"`.

- [Todo] P3.2 - Phase: Player connections
  - Send 6 `player_connect` player events, one per player.
  - Each with `event_kind: "player.connect"`, `transition_kind: "connectivity"`, full player snapshot matching `buildPlayerTelemetrySnapshot()` output:
    - `login`, `nickname`, `team_id` (0 or 1), `is_spectator: false`, `is_connected: true`, `has_joined_game: true`, `auth_level: 0`, `auth_name: "player"`, `auth_role: "player"`, `connectivity_state: "connected"`, `readiness_state: "ready"`, `eligibility_state: "eligible"`, `can_join_round: true`, `is_temporary_spectator: false`, `is_pure_spectator: false`, `forced_spectator_state: 0`, `has_player_slot: true`, `is_referee: false`, `is_managed_by_other_server: false`, `is_broadcasting: false`, `is_podium_ready: false`, `is_official: false`, `is_server: false`, `is_fake: false`.
  - Include `state_delta`, `permission_signals`, `roster_snapshot`, `field_availability`, `missing_fields` fields matching plugin output.
  - Assert: all 6 return `"accepted"`.

- [Todo] P3.3 - Phase: Match begin
  - Send lifecycle `match.begin` event: `variant: "match.begin"`, `phase: "match"`, `state: "begin"`, `source_channel: "maniaplanet"`, `raw_source_callback: "Maniaplanet.BeginMatch"`.
  - Assert: `"accepted"`.

- [Todo] P3.4 - Phase: Map 1 (Oasis Elite) — 5 rounds
  - Send lifecycle `map.begin` event with `map_rotation` payload:
    - `current_map: { uid: "qa-map-oasis", name: "Oasis Elite", file: "Oasis.Map.Gbx", environment: "Storm" }`
    - `map_pool: [all 3 maps]`, `map_pool_size: 3`, `current_map_index: 0`
    - `played_map_order: [{ order: 1, uid: "qa-map-oasis", name: "Oasis Elite" }]`
    - `series_targets: { best_of: 3, maps_score: { team_a: 0, team_b: 0 }, current_map_score: { team_a: 0, team_b: 0 } }`
  - For each of 5 rounds:
    - Send mode event `shootmania_elite_startturn` with attacker_login, defender_logins, turn number.
    - Send lifecycle `round.begin`.
    - Send multiple combat events:
      - `onshoot` events (with `weapon_id` 1 or 2, `shooter` with login/nickname/team_id).
      - `onhit` events (with `weapon_id`, `damage`, `shooter`, `victim`, `distance`).
      - `onnearmiss` events (with `weapon_id`, `shooter`, `distance`).
      - `onarmorempty` events (with `shooter` = killer, `victim` = eliminated player).
    - Each combat event carries cumulative `player_counters` for all tracked players up to that point.
    - Send combat `scores` event with `scores_section: "EndRound"`, `use_teams: true`, team/player scores.
    - Send lifecycle `round.end` (with `aggregate_stats` scope=round).
    - Send mode event `shootmania_elite_endturn` with victoryType.
  - After all 5 rounds, send combat `scores` event with `scores_section: "EndMap"`.
  - Send lifecycle `map.end` with:
    - `aggregate_stats.scope: "map"`, `player_counters_delta` (per-player deltas for this map), `team_counters_delta` (per-team), `totals` (sum), `win_context` (winner_team_id matching which team won the map), `window` (started_at, ended_at, duration_seconds).
    - `map_rotation.current_map: { uid: "qa-map-oasis", name: "Oasis Elite" }`.
  - Assert: all events return `"accepted"`.

- [Todo] P3.5 - Phase: Map 2 (Zenith Storm) — 4 rounds
  - Same pattern as Map 1 but with different combat data.
  - Team 1 wins this map (e.g. 3-1).
  - Update cumulative player_counters accordingly (they continue from Map 1 values because the plugin store resets on match.begin but we are within the same match).
  - **Important**: The plugin resets counters on `match.begin` and `map.begin`. Since we are between maps within the same match, the counters reset on `map.begin`. So Map 2's cumulative counters start fresh.
  - Actually re-reading `resetCombatStatsWindowIfNeeded`: it resets on `match.begin` AND `map.begin`. So each map starts with fresh counters. The `player_counters` on combat events within Map 2 are Map-2-only cumulative values.
  - The `player_counters_delta` on `map.end` is `current_counters - baseline_counters`. Since both are map-scoped (reset on map.begin), the delta equals the cumulative at map.end (since baseline is all zeros at map.begin).
  - So `player_counters_delta = player_counters` at end of map. This simplifies fixture design.

- [Todo] P3.6 - Phase: Map 3 (Colosseum) — 5 rounds
  - Team 0 wins this map (e.g. 3-2), completing the BO3 2-1 for Team 0.
  - Same pattern. Fresh counters for Map 3.

- [Todo] P3.7 - Phase: Match end
  - Send lifecycle `match.end` event.
  - Send combat `scores` event with `scores_section: "EndMatch"`, final match_points (Team 0: 2, Team 1: 1).
  - Assert: `"accepted"`.

---

### Phase 4 - Endpoint validation implementation

Call every read endpoint and validate responses.

- [Todo] P4.1 - P0 endpoints validation
  - `GET /v1/servers/qa-integration-server/link/auth-state` — assert returns linked=true.
  - `GET /v1/servers/qa-integration-server/link/access` — assert returns access data.
  - `GET /v1/servers` — assert list includes `qa-integration-server`.

- [Todo] P4.2 - P1 endpoints validation (status + health)
  - `GET .../status` — assert: `server_login`, `linked: true`, `online` (may be true depending on heartbeat threshold), `game_mode: "Elite"`, `player_counts.active: 6`, `event_counts.total > 0`, `event_counts.by_category.connectivity >= 2`, `event_counts.by_category.lifecycle > 0`, `event_counts.by_category.combat > 0`, `event_counts.by_category.player == 6`, `event_counts.by_category.mode > 0`.
  - `GET .../status/health` — assert: `server_login`, `plugin_health.queue.depth == 0`, `plugin_health.outage.active == false`, `connectivity_metrics.total_connectivity_events >= 2`, `connectivity_metrics.registration_count >= 1`, `connectivity_metrics.heartbeat_count >= 1`.
  - `GET .../status/capabilities` — assert: `capabilities` is not null, `capabilities.event_envelope == true`, `capabilities.schema_version == "2026-02-20.1"`, `source == "plugin_registration"`.

- [Todo] P4.3 - Players endpoints validation (P2.1, P2.2)
  - `GET .../players` — assert: `data` array has exactly 6 entries. Each has `login`, `nickname`, `team_id`, `is_connected: true`, `is_spectator: false`, `has_joined_game: true`. Verify all 6 logins are present.
  - `GET .../players?limit=3&offset=0` — assert: `data` array length is 3, `pagination.total == 6`, `pagination.limit == 3`.
  - `GET .../players/qa-player-alpha` — assert: `login == "qa-player-alpha"`, `nickname == "Alpha"`, `team_id == 0`, `is_connected == true`.
  - `GET .../players/nonexistent-player` — assert: HTTP 404.

- [Todo] P4.4 - Combat stats endpoints validation (P2.3, P2.4, P2.5)
  - `GET .../stats/combat` — assert: `combat_summary.total_events > 0`, `combat_summary.tracked_player_count == 6`, `combat_summary.total_kills > 0`, `combat_summary.total_shots > 0`, `combat_summary.event_kinds` has keys for `shootmania_event_onshoot`, `shootmania_event_onhit`, `shootmania_event_onarmorempty`, `shootmania_event_scores`.
  - `GET .../stats/combat/players` — assert: `data` has 6 entries. Each has `login`, `kills`, `deaths`, `hits`, `shots`, `misses`, `rockets`, `lasers`, `accuracy`, `kd_ratio`, `hits_rocket`, `hits_laser`, `rocket_accuracy`, `laser_accuracy`, `attack_rounds_played`, `attack_rounds_won`, `attack_win_rate`, `defense_rounds_played`, `defense_rounds_won`, `defense_win_rate`.
  - Validate specific numeric values for qa-player-alpha against pre-computed expected values from P1.3.
  - `GET .../stats/combat/players/qa-player-alpha` — assert: `login == "qa-player-alpha"`, counters match expected values (kills, deaths, hits, shots, accuracy, kd_ratio, hits_rocket, hits_laser, rocket_accuracy, laser_accuracy, attack_rounds_played, attack_rounds_won, attack_win_rate, defense_rounds_played, defense_rounds_won, defense_win_rate).
  - `GET .../stats/combat/players/nonexistent-player` — assert: HTTP 404.

- [Todo] P4.5 - Scores endpoint validation (P2.6)
  - `GET .../stats/scores` — assert: `scores_section` is `"EndMatch"` (the last scores event), `scores_snapshot.use_teams == true`, `scores_snapshot.team_scores` has 2 entries, `scores_snapshot.player_scores` has 6 entries. `scores_result.result_state == "team_win"`.

- [Todo] P4.6 - Lifecycle endpoints validation (P2.7, P2.8, P2.9)
  - `GET .../lifecycle` — assert: `current_phase` is `"match"` (last event is match.end). `match` state exists. `map` state exists. `round` state exists.
  - `GET .../lifecycle/map-rotation` — assert: `map_pool` has 3 maps, `current_map.uid` matches last map (qa-map-colosseum), `map_pool_size == 3`. `series_targets.best_of == 3`.
  - `GET .../lifecycle/aggregate-stats` — assert: `aggregates` is an array. Should have entries for scope `"round"` and `"map"`. The `"map"` entry's `player_counters_delta` should have 6 players. `totals.kills > 0`.

- [Todo] P4.7 - Maps endpoint validation (P2.11)
  - `GET .../maps` — assert: `maps` array has 3 maps (the map_pool). `map_count == 3`. Each map has `uid` and `name`. `current_map` is not null.

- [Todo] P4.8 - Mode endpoint validation (P2.12)
  - `GET .../mode` — assert: `game_mode == "Elite"`, `title_id == "SMStormElite@nadeolabs"`, `recent_mode_events` is not empty (we injected Elite start/end turn mode events), `total_mode_events > 0`.

- [Todo] P4.9 - Per-map combat stats endpoints validation (P2.5.1, P2.5.2, P2.5.3)
  - `GET .../stats/combat/maps` — assert: `maps` array has exactly 3 entries (one per completed map). Ordered most-recent first (Colosseum, Zenith, Oasis). Each has `map_uid`, `map_name`, `played_at`, `duration_seconds`, `player_stats` (6 players each), `team_stats`, `totals`, `win_context`, `event_id`.
  - `GET .../stats/combat/maps?limit=2&offset=0` — assert: 2 entries, `pagination.total == 3`.
  - `GET .../stats/combat/maps?limit=1&offset=2` — assert: 1 entry (the oldest = Oasis).
  - `GET .../stats/combat/maps/qa-map-oasis` — assert: `map_uid == "qa-map-oasis"`, `map_name == "Oasis Elite"`. `player_stats.qa-player-alpha.kills` matches pre-computed Map 1 value. `win_context.winner_team_id == 0` (Team 0 won Map 1).
  - `GET .../stats/combat/maps/qa-map-zenith` — assert: `win_context.winner_team_id == 1` (Team 1 won Map 2).
  - `GET .../stats/combat/maps/qa-map-oasis/players/qa-player-alpha` — assert: `player_login == "qa-player-alpha"`, `counters.kills` matches pre-computed value, `counters.kd_ratio` matches, `counters.hits_rocket` is not null, `counters.rocket_accuracy` is not null, `counters.attack_rounds_played` is not null.
  - `GET .../stats/combat/maps/nonexistent-map` — assert: HTTP 404.
  - `GET .../stats/combat/maps/qa-map-oasis/players/nonexistent` — assert: HTTP 404.

- [Todo] P4.10 - Series combat stats endpoint validation (P2.5.4)
  - `GET .../stats/combat/series` — assert: `series` array has exactly 1 entry (one complete BO3). `series[0].total_maps_played == 3`. `series[0].maps` has 3 entries. `series[0].series_totals.kills > 0`. `series[0].match_started_at` is valid ISO8601. `series[0].match_ended_at` is valid ISO8601.

- [Todo] P4.11 - Player combat map history endpoint validation (P2.6)
  - `GET .../stats/combat/players/qa-player-alpha/maps` — assert: `player_login == "qa-player-alpha"`, `maps_played == 3` (alpha participated in all 3 maps), `maps` array has 3 entries (ordered most-recent first). Each entry has `map_uid`, `map_name`, `played_at`, `counters`, `win_context`, `won`.
  - Assert: `maps_won` is correct (alpha is on Team 0 which won Maps 1 and 3 = 2 maps won).
  - Assert: `win_rate` is correct (2/3 = 0.6667).
  - Assert: the `won` field is `true` for Maps 1 and 3, `false` for Map 2.
  - Assert: `counters.kd_ratio` is present and correct for each map entry.
  - Assert: `counters.hits_rocket`, `counters.hits_laser`, `counters.rocket_accuracy`, `counters.laser_accuracy` are present (not null).
  - Assert: `counters.attack_rounds_played`, `counters.attack_rounds_won`, `counters.attack_win_rate` are present (not null).
  - Assert: `counters.defense_rounds_played`, `counters.defense_rounds_won`, `counters.defense_win_rate` are present (not null).
  - `GET .../stats/combat/players/qa-player-alpha/maps?limit=1` — assert: `maps` array has 1 entry, `pagination.total == 3`.
  - `GET .../stats/combat/players/nonexistent/maps` — assert: HTTP 200 with `maps: []`, `maps_played: 0`.

---

### Phase 5 - Edge case and regression tests

- [Todo] P5.1 - Idempotency deduplication
  - Re-send one of the combat events with the same `idempotency_key`.
  - Assert: response contains `"duplicate"`.

- [Todo] P5.2 - Time-range filtering
  - Call `GET .../stats/combat?since=<future_timestamp>` — assert: `combat_summary.total_events == 0`.
  - Call `GET .../stats/combat/maps?since=<future_timestamp>` — assert: `maps` is empty.
  - Call `GET .../stats/combat/players/qa-player-alpha/maps?since=<future_timestamp>` — assert: `maps_played == 0`, `maps: []`.

- [Todo] P5.3 - Unknown server 404s
  - `GET /v1/servers/nonexistent-server-xyz/status` — assert: HTTP 404.
  - `GET /v1/servers/nonexistent-server-xyz/players` — assert: HTTP 404.
  - `GET /v1/servers/nonexistent-server-xyz/stats/combat` — assert: HTTP 404.
  - `GET /v1/servers/nonexistent-server-xyz/lifecycle` — assert: HTTP 404.
  - `GET /v1/servers/nonexistent-server-xyz/maps` — assert: HTTP 404.
  - `GET /v1/servers/nonexistent-server-xyz/mode` — assert: HTTP 404.

- [Todo] P5.4 - Malformed input
  - POST event with missing required fields — assert: rejected.
  - POST event with missing X-Pixel-Server-Login header — assert: `"rejected"`, `"missing_server_login"`.

- [Todo] P5.5 - Cleanup and final tally
  - `DELETE /v1/servers/qa-integration-server` — assert: `"deleted"`.
  - `GET /v1/servers/qa-integration-server/status` — assert: HTTP 404 (cascade delete verified).
  - Print final results: total PASS count, total FAIL count.
  - Exit 0 if all pass, exit 1 if any fail.

- [Todo] P5.6 - Regression: existing smoke tests
  - After implementing the full script, verify that `bash scripts/qa-p0-smoke.sh` and `bash scripts/qa-p1-smoke.sh` still pass (run them as a post-check; do not modify them).

---

### Phase 6 - Live QA run

- [Todo] P6.1 - Start the stack and run the script
  - Ensure Docker (postgres) is running: `cd pixel-control-server && docker compose up -d postgres`.
  - Run migrations: `npm run prisma:migrate`.
  - Start the server: `npm run start:dev`.
  - Run the script: `bash scripts/qa-full-integration.sh`.
  - Capture full output.

- [Todo] P6.2 - Fix any failures
  - If any assertions fail, analyze whether the issue is in the script fixtures (wrong expected values) or in the server code (bug).
  - Fix script fixtures if the server behavior is correct per the contract.
  - If a server bug is found, document it and fix it separately.

- [Todo] P6.3 - Run regression smoke tests
  - `bash scripts/qa-p0-smoke.sh` — all assertions pass.
  - `bash scripts/qa-p1-smoke.sh` — all assertions pass.

---

## Evidence / Artifacts

- `pixel-control-server/scripts/qa-full-integration.sh` — the main integration test script.
- Console output from the QA run showing all assertions pass.

## Success criteria

- The script runs to completion with 0 failures on a clean database.
- All 19 read endpoints return correct HTTP status codes and response shapes.
- Numeric combat counters (kills, deaths, accuracy, kd_ratio, weapon-specific stats, Elite round stats) are verified to exact pre-computed values for at least 2 players.
- Per-map stats correctly reflect per-map deltas (not cumulative session totals).
- Series endpoint correctly groups 3 maps within the match.begin/match.end boundary.
- Player map history correctly computes `won`, `maps_won`, and `win_rate`.
- Edge cases (pagination, time-range, 404s, deduplication) all pass.
- Existing P0 and P1 smoke tests still pass (no regressions).

## Notes / outcomes

- Script created at `pixel-control-server/scripts/qa-full-integration.sh`.
- **255 assertions pass** on first clean run.
- All regression smoke tests continue to pass: P0 (43/43), P1 (35/35), P2 (94/94), P2.5 (59/59), P2.6 (29/29), P2.6-elite (21/21).
- Key bugs fixed during script authoring:
  1. `HTTP_CODE` subshell issue — `do_get()` called via `$()` runs in a subshell so `HTTP_CODE=$(...)` inside it was invisible to parent. Fixed by writing code to a temp file `$_CODE` and reading via `get_http_code()`.
  2. `GET /servers` field name — server list uses snake_case `server_login`, not camelCase `serverLogin`.
  3. jq `--argjson` with inline JSON literal fails — must assign JSON to a variable first, then pass via `--argjson`.
  4. `stats/combat/players` returned 0 players because the last combat event (EndMatch scores) had no `player_counters`. Fixed by adding `player_counters` to the EndMatch scores event.
- macOS-compatible throughout: no GNU-only commands, `$()` for timestamps, file-based sequence counter.
