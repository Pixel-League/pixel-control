# Pixel Control API Contract (Plugin -> Future API)

This file defines the future API routes that the `pixel-control-plugin` will use.

Current status:

- `pixel-control-server/` backend implementation is intentionally deferred.
- This document is the contract source to implement the backend later without changing plugin semantics.

## Versioning

- API base path: `/v1`
- Plugin envelope version (current): `2026-02-20.1`
- Transport style: JSON over HTTPS
- Wave-5 strategy: keep `2026-02-20.1` and evolve via additive optional payload fields (no route/path changes).

## Common request/response rules

All write routes below use:

- `Content-Type: application/json`
- `X-Pixel-Server-Login: <server_login>`
- `X-Pixel-Plugin-Version: <plugin_version>`
- Auth header from plugin mode:
  - bearer: `Authorization: Bearer <token>`
  - api_key: `X-API-Key: <key>`

Common envelope fields expected in request body:

- `event_name`
- `schema_version`
- `event_id`
- `event_category`
- `source_callback`
- `source_sequence`
- `source_time`
- `idempotency_key`
- `payload`
- `metadata`

Common ack contract returned by API:

- success: `{ "ack": { "status": "accepted", ... } }`
- duplicate: `{ "ack": { "status": "accepted", "disposition": "duplicate", ... } }`
- rejected: `{ "ack": { "status": "rejected", "code": "...", "retryable": false, ... } }`
- temporary error: `{ "error": { "code": "...", "retryable": true, "retry_after_seconds": <n> } }`

Compatibility note for wave-5:

- New telemetry is additive on existing categories and callbacks.
- Existing required envelope fields remain unchanged.
- Consumers should ignore unknown additive payload fields when they are not yet projected.

Compatibility note for native-admin delegation refactor:

- This refactor is execution-path delegation only: control actions are now executed through native ManiaControl services behind the plugin control surface.
- Plugin-to-API transport routes and required envelope fields are unchanged.
- Any new admin-control visibility is additive under connectivity capability payload (`payload.capabilities.admin_control.*`) and does not change route contracts.

Identity guardrails (wave-5 runtime hardening):

- Plugin event identity remains deterministic:
  - `event_id = pc-evt-<event_category>-<normalized_source_callback>-<source_sequence>`
  - `idempotency_key = pc-idem-<sha1(event_id)>`
- Plugin runtime validates identity tuple consistency (`event_name`, `event_id`, `idempotency_key`, category/source/sequence) before enqueue and before dispatch.
- If identity validation fails, plugin drops the malformed envelope locally and emits queue warning marker `drop_identity_invalid` (with queue telemetry counter `dropped_on_identity_validation`).

## Routes

### 1) Connectivity

- `POST /v1/plugin/events/connectivity`

Linked plugin operations:

- `Connectivity::Register` (plugin startup handshake)
- `Connectivity::Heartbeat` (periodic health + queue status)

Linked plugin source callbacks/signals:

- startup signal (`plugin_registration`)
- timer signal (`plugin_heartbeat`)

### 2) Lifecycle

- `POST /v1/plugin/events/lifecycle`

Linked plugin operations:

- `Lifecycle::WarmupStart`, `Lifecycle::WarmupEnd`, `Lifecycle::WarmupStatus`
- `Lifecycle::MatchBegin`, `Lifecycle::MatchEnd`
- `Lifecycle::MapBegin`, `Lifecycle::MapEnd`
- `Lifecycle::RoundBegin`, `Lifecycle::RoundEnd`

Linked plugin source callbacks:

- `CB_MP_BEGINMATCH`, `CB_MP_ENDMATCH`
- `CB_MP_BEGINMAP`, `CB_MP_ENDMAP`
- `CB_MP_BEGINROUND`, `CB_MP_ENDROUND`
- `MP_WARMUP_START`, `MP_WARMUP_END`, `MP_WARMUP_STATUS`
- `MP_STARTMATCHSTART`, `MP_STARTMATCHEND`
- `MP_ENDMATCHSTART`, `MP_ENDMATCHEND`
- `MP_LOADINGMAPSTART`, `MP_LOADINGMAPEND`
- `MP_UNLOADINGMAPSTART`, `MP_UNLOADINGMAPEND`
- `MP_STARTROUNDSTART`, `MP_STARTROUNDEND`
- `MP_ENDROUNDSTART`, `MP_ENDROUNDEND`

Wave-4 additive lifecycle payload fields:

- `aggregate_stats` (emitted on round/map boundaries):
  - `scope` (`round|map`),
  - `counter_scope` (`combat_delta`),
  - `player_counters_delta` + `totals`,
  - `team_counters_delta` + `team_summary` (with assignment-source coverage/fallback notes),
  - `source_coverage`, `window`, `field_availability`, `missing_fields`,
  - `win_context` deterministic result projection (`result_state`, `winning_side`, `winning_reason`, tie/draw markers, fallback markers).
- `map_rotation` (emitted on `map.begin`/`map.end` lifecycle variants):
  - `map_pool` + `map_pool_size`,
  - `current_map`, `current_map_index`, `next_maps`,
  - `played_map_order` / `played_map_count`,
  - `veto_draft_actions` additive action stream (`ban|pick|pass|lock` where exposed, inferred fallback where not),
  - `veto_result` projection with explicit `partial|unavailable` fallback semantics when runtime veto callbacks are incomplete.

### 3) Admin actions

- `POST /v1/plugin/events/admin`

Linked plugin operations (from lifecycle admin-action normalization):

- `Admin::WarmupStart`, `Admin::WarmupEnd`, `Admin::WarmupStatus`
- `Admin::MatchStart`, `Admin::MatchEnd`
- `Admin::MapLoadStart`, `Admin::MapLoadEnd`
- `Admin::MapUnloadStart`, `Admin::MapUnloadEnd`
- `Admin::RoundStart`, `Admin::RoundEnd`

Planned extensions (explicitly reserved):

- `Admin::PauseMatch`
- `Admin::ResumeMatch`
- `Admin::RestartMatch`
- `Admin::CancelMatch`

### 4) Player state

- `POST /v1/plugin/events/players`

Linked plugin operations:

- `Player::Connect`
- `Player::Disconnect`
- `Player::InfoChanged`
- `Player::InfosChanged`

Linked plugin source callbacks:

- `CB_PLAYERCONNECT`
- `CB_PLAYERDISCONNECT`
- `CB_PLAYERINFOCHANGED`
- `CB_PLAYERINFOSCHANGED`

Wave-4 additive player payload fields:

- `roster_state`:
  - `current`, `previous`, and `delta` normalized roster/readiness/eligibility states,
  - server-wide `aggregate` roster counters,
  - `field_availability` and `missing_fields`.
- `permission_signals` now includes explicit eligibility/readiness markers:
  - `eligibility_state`, `readiness_state`, `can_join_round`,
  - change flags (`slot_changed`, `readiness_changed`, `eligibility_changed`),
  - deterministic fallback availability markers.
- `admin_correlation`:
  - windowed linkage between player transitions and recent lifecycle admin actions,
  - correlation confidence/reasons,
  - fallback shape when no link is inferable.
- `reconnect_continuity`:
  - deterministic identity/session chain (`identity_key`, `session_id`, `session_ordinal`, `previous_session_id`),
  - transition/continuity states with reconnect/disconnect timing hints,
  - replay ordering metadata (`global_transition_sequence`, `player_transition_sequence`).
- `side_change`:
  - explicit previous/current team-side projection,
  - deterministic transition kind and detection markers,
  - dedupe-oriented key plus ordering metadata.

Wave-5 additive player payload fields:

- `constraint_signals`:
  - `policy_context` snapshot for dedicated forced-team/slot policy visibility (`forced_teams`, `keep_player_slots`, max player/spectator limits) with cache age + deterministic unavailable reasons,
  - `forced_team_policy` projection (`enabled`, policy state, team assignment-change semantics, fallback markers),
  - `slot_policy` projection (slot state/reason, forced-spectator pressure hints, max-player utilization hints),
  - explicit `field_availability` and `missing_fields` for policy surfaces not exposed by current runtime.

### 5) Stats/combat

- `POST /v1/plugin/events/stats`

Linked plugin operations:

- `Stats::Shot`
- `Stats::Hit`
- `Stats::NearMiss`
- `Stats::Dead`
- `Stats::Capture`
- `Stats::Scores`

Linked plugin source callbacks:

- `SM_ONSHOOT` -> `Stats::Shot`
- `SM_ONHIT` -> `Stats::Hit`
- `SM_ONNEARMISS` -> `Stats::NearMiss`
- `SM_ONARMOREMPTY` -> `Stats::Dead`
- `SM_ONCAPTURE` -> `Stats::Capture`
- `SM_SCORES` -> `Stats::Scores`

Wave-4 note:

- `SM_SCORES` remains the score/winner source used to enrich lifecycle `aggregate_stats.win_context` snapshots and team-assignment fallback resolution.

### 6) Mode-specific gameplay

- `POST /v1/plugin/events/mode`

Linked plugin operations:

- `Mode::EliteStartTurn`, `Mode::EliteEndTurn`
- `Mode::JoustOnReload`, `Mode::JoustSelectedPlayers`, `Mode::JoustRoundResult`
- `Mode::RoyalPoints`, `Mode::RoyalPlayerSpawn`, `Mode::RoyalRoundWinner`

Linked plugin source callbacks:

- `SM_ELITE_STARTTURN`, `SM_ELITE_ENDTURN`
- `SM_JOUST_ONRELOAD`, `SM_JOUST_SELECTEDPLAYERS`, `SM_JOUST_ROUNDRESULT`
- `SM_ROYAL_POINTS`, `SM_ROYAL_PLAYERSPAWN`, `SM_ROYAL_ROUNDWINNER`

### 7) Queue flush / batch delivery

- `POST /v1/plugin/events/batch`

Linked plugin operations:

- `Queue::FlushBatch` (when local outage queue drains/replays)

Request body:

- `{ "events": [<envelope>, ...], "batch_meta": { ... } }`

## Routing ownership

- Producer: `pixel-control-plugin`
- Consumer (future): `pixel-control-server`

## Implementation note

When backend implementation starts, keep route paths stable and evolve only through versioned fields/schemas, not by changing plugin callback semantics.
