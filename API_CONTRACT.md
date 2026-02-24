# Pixel Control API Contract (Plugin -> Server API)

This file defines plugin-facing API routes used by `pixel-control-plugin` and implemented by `pixel-control-server`.

Current status:

- NestJS MVP backend is active in `pixel-control-server/`.
- Canonical ingestion routes under `/v1/plugin/events/*` are implemented.
- Compatibility single-path routes (`/plugin/events` and `/v1/plugin/events`) are implemented for current plugin transport defaults.
- NestJS wave-2 no-DB expansion is active with additive read/diagnostics endpoints under `/v1/servers/*` and `/v1/ingestion/diagnostics`.
- NestJS wave-3 no-DB expansion is active with additive control/workflow endpoints under `/v1/servers/:serverLogin/control/*` and `/v1/control/audit`.
- Wave-2 note: plugin-facing write routes and ACK/error semantics are unchanged (additive read-only backend surface only).
- Wave-3 note: plugin-facing write routes and ACK/error semantics remain unchanged (control/workflow additions are backend-orchestration surfaces only).
- Cross-project link/auth note: canonical server-link routes are active under `/v1/servers/:serverLogin/link/*`, and control write routes now require `link_bearer` auth evidence.
- Wave-4 note: Prisma raw-traceability persistence foundation (SQLite bootstrap) is additive behind ingestion projection hooks; plugin-facing write routes and ACK/error semantics remain unchanged.
- Control read provenance note: whitelist control reads are observed-runtime-first from connectivity capabilities when available; map-pool reads expose explicit fallback metadata when strict observed telemetry is unavailable.
- This document remains the route/contract source-of-truth; implementation changes must stay additive unless versioned.

## Versioning

- API base path: `/v1`
- Plugin envelope version (current): `2026-02-20.1`
- Transport style: JSON over HTTPS
- Wave-5 strategy: keep `2026-02-20.1` and evolve via additive optional payload fields (no route/path changes).

## Server-link routes (control auth)

Canonical server-link routes:

- `PUT /v1/servers/:serverLogin/link/registration`
- `GET /v1/servers/:serverLogin/link/access`
- `POST /v1/servers/:serverLogin/link/token`
- `GET /v1/servers/:serverLogin/link/auth-state`

These routes provide per-server registration/access/token/auth-state used by `link_bearer` authorization on server-scoped control writes.

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
- Communication control path now requires linked auth evidence (`authentication_mode=link_bearer`) for server-scoped admin methods:
  - `server_login`
  - `auth.mode=link_bearer`
  - `auth.token`
- Deterministic rejection codes for missing/invalid/mismatched auth evidence:
  - `link_auth_missing`
  - `link_auth_invalid`
  - `link_server_mismatch`
  - `admin_command_unauthorized`
- Additive communication action extensions (local control-surface contract, no new plugin->API route):
  - `whitelist.enable` (no parameters)
  - `whitelist.disable` (no parameters)
  - `whitelist.add` (`target_login`)
  - `whitelist.remove` (`target_login`)
  - `whitelist.list` (no parameters)
  - `whitelist.clean` (no parameters)
  - `whitelist.sync` (no parameters)
  - `vote.policy.get` (no parameters)
  - `vote.policy.set` (`mode`)
  - `team.policy.get` (no parameters)
  - `team.policy.set` (`enabled` and/or `switch_lock`)
  - `team.roster.assign` (`target_login`, `team`)
  - `team.roster.unassign` (`target_login`)
  - `team.roster.list` (no parameters)
  - `match.bo.set` (`best_of`)
  - `match.bo.get` (no parameters)
  - `match.maps.set` (`target_team`, `maps_score`)
  - `match.maps.get` (no parameters)
  - `match.score.set` (`target_team`, `score`)
  - `match.score.get` (no parameters)
- Additive communication status/list snapshots:
  - `PixelControl.Admin.ListActions` includes top-level `whitelist`
  - `PixelControl.Admin.ListActions` includes top-level `vote_policy`
  - `PixelControl.Admin.ListActions` includes top-level `team_control`
  - `PixelControl.Admin.ListActions` includes top-level `series_targets`
  - `PixelControl.VetoDraft.Status` includes top-level `matchmaking_autostart_min_players`
  - `PixelControl.VetoDraft.Status` includes top-level `matchmaking_ready_armed`
  - `PixelControl.VetoDraft.Status` includes top-level `series_targets`
  - `PixelControl.VetoDraft.Status` includes top-level `matchmaking_lifecycle` snapshot (`status`, `stage`, `ready_for_next_players`, action summaries, bounded history)
  - `PixelControl.VetoDraft.Status.communication` includes additive `ready` method (`PixelControl.VetoDraft.Ready`)
- Team selector normalization for recovery actions:
  - `target_team` accepts `0|1|red|blue` (plus aliases `team_a|team_b|a|b`) and normalizes to `team_a|team_b`.
- Additive veto control-surface behavior notes:
  - matchmaking start paths now require explicit one-cycle ready arming (`//pcveto ready` or `PixelControl.VetoDraft.Ready`) and return `matchmaking_ready_required` until armed,
  - successful matchmaking start consumes readiness token; completion/lifecycle closure does not auto-rearm,
  - matchmaking countdown announcements run from configured duration with deterministic cadence `N, N-10, ..., 10, 5..1` and per-session dedupe,
  - role-based chat/status visibility is control-surface only (non-admin map/status output narrowed, admin output retains UID/operational diagnostics),
  - `PixelControl.VetoDraft.Status` payload schema is unchanged by visibility scoping,
  - completion queue/apply policy is branch-based (`opener_differs` => queue full order + `map.skip`; `opener_already_current` => queue remaining maps only, no skip/restart),
  - matchmaking post-veto lifecycle automation is additive and mode-guarded (`matchmaking_vote` only) with deterministic stage progression (`veto_completed -> selected_map_loaded -> match_started -> selected_map_finished -> players_removed -> map_changed -> match_ended -> ready_for_next_players`),
  - lifecycle boundary detection prefers callbacks and uses timer fallback inference when map callback coverage is missing in local runtime.

Control read provenance metadata (additive):

- Control read responses include `source_metadata` with:
  - `source_of_truth` (`observed_runtime` or `desired_state_fallback`),
  - `strict_observation`,
  - `observed_at`, `observed_event_at`, `observed_revision`,
  - `fallback_reason`.
- `GET /v1/servers/:serverLogin/control/player-eligibility-policy` and desired-state `player_eligibility_policy` are observed-first from `registration.capabilities.admin_control.whitelist` when available.
- `GET /v1/servers/:serverLogin/control/map-pool-policy` and desired-state `map_pool_policy` expose explicit fallback provenance when strict observed map-pool telemetry is unavailable.

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

Additive runtime-capability behavior:

- `Connectivity::Register` and `Connectivity::Heartbeat` both carry `payload.capabilities.admin_control.*` snapshots.
- After successful delegated control-policy mutations (`whitelist.*`, `vote.policy.*`, `team.policy.*`, `team.roster.*`, `match.bo.*`, `match.maps.*`, `match.score.*`), plugin runtime queues an immediate connectivity capability refresh envelope.
- These capability snapshots are consumed by server control read routes as observed source-of-truth when available.

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
  - `series_targets` runtime policy snapshot (`best_of`, `maps_score`, `current_map_score`, metadata),
  - additive veto mode metadata (`veto_draft_mode`, `veto_draft_session_status`),
  - additive matchmaking ready-gate state (`matchmaking_ready_armed`),
  - `veto_draft_actions` authoritative draft/veto action stream (`ban|pick|pass|lock`) emitted by plugin-side draft sessions,
  - `veto_result` projection with explicit `running|completed|cancelled|unavailable` semantics,
  - additive `matchmaking_lifecycle` projection (`status`, `stage`, `ready_for_next_players`, action summaries, bounded history).
- Tournament BO precedence note:
  - explicit start payload `best_of` is honored first,
  - missing `best_of` falls back to runtime series-policy default.

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
- Consumer: `pixel-control-server` (NestJS MVP)

## Implementation note

Keep route paths stable and evolve only through versioned fields/schemas, not by changing plugin callback semantics.
