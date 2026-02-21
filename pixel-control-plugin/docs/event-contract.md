# Pixel Control Event Contract (v2026-02-20.1)

This document defines the active plugin-to-server envelope baseline for wave 5.

## Versioning baseline

- Active schema version: `2026-02-20.1`.
- Previous baseline (`2026-02-19.1`) is retained for historical replay and compatibility reference.
- Wave-5 keeps `2026-02-20.1` and evolves through additive optional fields only (no route/category renames, no required-field removals).

## Naming rules

- Event name pattern: `pixel_control.<event_category>.<normalized_source_callback>`.
- `event_category` values: `connectivity`, `lifecycle`, `player`, `combat`, `mode`.
- `normalized_source_callback` is lowercase with non-alphanumeric separators converted to `_`.
- `event_id` pattern: `pc-evt-<event_category>-<normalized_source_callback>-<source_sequence>`.
- `idempotency_key` pattern: `pc-idem-<sha1(event_id)>`.

## Identity validation guards

- Wave-5 keeps deterministic event identity tuple generation (`event_name`, `event_id`, `idempotency_key`) for all categories.
- Runtime validates identity consistency before enqueue and before dispatch:
  - expected event-name derivation from `<event_category, source_callback>`,
  - expected event-id derivation from `<event_category, source_callback, source_sequence>`,
  - expected idempotency-key derivation from `<sha1(event_id)>`.
- Invalid envelopes are dropped locally with marker `[PixelControl][queue][drop_identity_invalid]` and queue telemetry counter `dropped_on_identity_validation`.

## Canonical event catalog

- Machine-readable catalog: `docs/schema/event-name-catalog-2026-02-20.1.json`.
- Baseline catalog contains `44` canonical event names:
  - connectivity: `2`
  - lifecycle: `24`
  - player: `4`
  - combat: `6`
  - mode: `8`

## Delegated admin-control execution surface (additive)

- Admin delegation refactor keeps schema version `2026-02-20.1` and does not add/rename event categories or callbacks.
- Execution-path change only: privileged map/warmup/pause/vote/player/auth control requests are delegated to native ManiaControl services.
- Additive connectivity capability field in plugin registration payload:
  - `payload.capabilities.admin_control.available`
  - `payload.capabilities.admin_control.enabled`
  - `payload.capabilities.admin_control.command`
  - `payload.capabilities.admin_control.pause_state_ttl_seconds`
  - `payload.capabilities.admin_control.communication.execute_action`
  - `payload.capabilities.admin_control.communication.list_actions`
  - `payload.capabilities.admin_control.security.chat_command.actor_login_required`
  - `payload.capabilities.admin_control.security.chat_command.permission_model`
  - `payload.capabilities.admin_control.security.communication.authentication_mode`
  - `payload.capabilities.admin_control.security.communication.actor_login_required`
  - `payload.capabilities.admin_control.security.communication.permission_model`
  - `payload.capabilities.admin_control.actions[]`
  - `payload.capabilities.admin_control.ownership_boundary.telemetry_transport`
  - `payload.capabilities.admin_control.ownership_boundary.admin_execution`
- Delegated action request/response payloads are local plugin control-surface semantics (chat/communication) and are not new plugin-to-API event envelopes.
- Chat command requests remain actor-bound and permission-gated with ManiaControl plugin rights.
- Communication payload requests currently run in temporary trusted mode (`authentication_mode=none_temporary`): `actor_login` is optional and plugin permission checks are intentionally skipped.
- Existing lifecycle `admin_action` telemetry semantics remain unchanged and continue to be emitted by native callback observations.

## Lifecycle variant catalog

Lifecycle payload field `variant` and metadata field `lifecycle_variant` are normalized to:

- `warmup.start`
- `warmup.end`
- `warmup.status`
- `pause.start`
- `pause.end`
- `pause.status`
- `match.begin`
- `match.end`
- `map.begin`
- `map.end`
- `round.begin`
- `round.end`
- `lifecycle.unknown`

Elite compatibility note:

- In Elite mode, turn callbacks are also projected into lifecycle round variants for cross-mode parity:
  - `OnEliteStartTurn` -> `round.begin`
  - `OnEliteEndTurn` -> `round.end`

Lifecycle payload field `source_channel` values:

- `maniaplanet` for direct callback-manager lifecycle callbacks.
- `script` for script lifecycle callbacks bridged via `registerScriptCallbackListener(...)`.

## Enriched admin-action semantics

Lifecycle script callbacks emit an `admin_action` object with normalized actor/target/action semantics.

Required semantic fields:

- `action_name`
- `action_domain` (wave-2 baseline: `match_flow`)
- `action_type` (`warmup`, `pause`, `match_start`, `match_end`, `map_loading`, `map_unloading`, `round_start`, `round_end`)
- `action_phase` (`start|end|status`)
- `target` (`warmup|pause|match|map|round`)
- `target_scope` (`server|match|map|round|unknown`)
- `target_id` (resolved identifier, fallback `unknown`)
- `initiator_kind` (`player|system|script|unknown`)

Actor and fallback fields remain part of the contract:

- `actor` (`player`, `login`, or `unknown` fallback)
- `field_availability` and `missing_fields`
- `source_callback`, `source_channel`, `context`, optional `script_payload`

Pause-specific additive semantics:

- `Maniaplanet.Pause.Status` now participates in lifecycle normalization/admin-action telemetry.
- `variant` + `lifecycle_variant` are resolved dynamically from pause status payload:
  - `pause.start` when `active=true`
  - `pause.end` when `active=false`
  - `pause.status` fallback when active state is unavailable.
- `admin_action.context.pause_request` includes best-effort requester context when callback payload exposes it:
  - `requested_by_login`
  - `requested_by_team_id`
  - `requested_by_team_side`
  - status flags (`active`, `available`) plus availability markers.

## Player transition telemetry baseline

Player callbacks now emit structured transition payloads (instead of callback-summary-only payloads).

Core fields:

- `event_kind` (`player.connect`, `player.disconnect`, `player.info_changed`, `player.infos_changed`, `player.unknown`)
- `transition_kind` (`connectivity`, `state_change`, `batch_refresh`, `unknown`)
- `player` and `previous_player` snapshots (nullable with deterministic fallback)
- `state_delta` entries for connectivity/spectator/team/auth/referee/slot transitions
- `permission_signals` for auth-level and role-change observability
- `roster_snapshot`, `tracked_player_cache_size`, `field_availability`, `missing_fields`

Wave-4 additive player fields:

- `roster_state`:
  - normalized `current`/`previous`/`delta` roster states,
  - aggregate roster counters (connected/spectator/readiness/eligibility/team distribution),
  - deterministic `field_availability` + `missing_fields`.
- expanded `permission_signals`:
  - `eligibility_state`, `readiness_state`, `can_join_round`,
  - transition flags (`slot_changed`, `readiness_changed`, `eligibility_changed`),
  - additive availability fallbacks when callback fields are missing.
- `admin_correlation`:
  - windowed inference linking player transitions with recent lifecycle `admin_action` contexts,
  - confidence + inference reasons,
  - explicit fallback shape when no correlation is inferable.
- `reconnect_continuity`:
  - deterministic identity/session chain (`identity_key`, `session_id`, `session_ordinal`),
  - reconnect transition states (`initial_connect`, `reconnect`, `disconnect`, `batch_refresh`, ...),
  - ordering hints for replay/dedupe diagnostics (`global_transition_sequence`, `player_transition_sequence`).
- `side_change`:
  - explicit old/new team-side projection (`previous_team_id`, `current_team_id`, `previous_side`, `current_side`),
  - deterministic detection markers (`team_changed`, `side_changed`, `transition_kind`),
  - dedupe-oriented key (`dedupe_key`) and ordering hints.

Wave-5 additive player fields:

- `constraint_signals`:
  - dedicated policy context snapshot (`forced_teams`, `keep_player_slots`, max player/spectator limits) with cache-age metadata,
  - deterministic forced-team policy projection (`policy_state`, `reason`, team assignment change hints),
  - deterministic slot-policy projection (`policy_state`, `reason`, slot pressure/utilization hints),
  - explicit fallback markers (`field_availability`, `missing_fields`, deterministic unavailable reasons) when runtime policy fields are not exposed.

This remains telemetry-only (no plugin-side policy enforcement).

## Lifecycle aggregate + map telemetry baseline

Wave-4 lifecycle payloads include additive map/stats context on existing lifecycle callbacks:

- `aggregate_stats` on boundary variants (`round.end`, `map.end`):
  - delta combat counters per player + totals,
  - per-team aggregate snapshots (`team_counters_delta`, `team_summary`) with source-coverage metadata,
  - boundary window metadata (`started_at`, `ended_at`, callbacks),
  - source coverage notes (combat callbacks + score callback),
  - `win_context` projection using latest `SM_SCORES` snapshot with deterministic result semantics (`result_state`, `winning_side`, `winning_reason`, tie/draw markers, fallback flag).
- `map_rotation` on map boundary variants (`map.begin`, `map.end`):
  - `map_pool`, `map_pool_size`, `current_map_index`, `next_maps`,
  - `played_map_order` runtime history,
  - normalized identifiers (`uid`, `name`, optional `external_ids.mx_id`),
  - `veto_draft_actions` additive action stream (`ban|pick|pass|lock` where callback data exposes it; inferred `lock` fallback otherwise),
  - `veto_result` final-selection projection with explicit partial/unavailable semantics when dedicated veto callbacks are not exposed.

## Combat stats payload contract

- Combat callbacks continue to emit in-memory counters under `player_counters`.
- Counter retention is intentionally bounded to the active match/map window (store reset on `match.begin` and `map.begin`), so plugin-side combat counters are not long-lived runtime history.
- Required counters per tracked player:
  - `kills`, `deaths`, `hits`, `shots`, `misses`, `rockets`, `lasers`, `accuracy`
- Combat dimensions continue under `dimensions` with field fallback markers:
  - `weapon_id`, `damage`, `distance`, `event_time`
  - `shooter`, `victim`, `shooter_position`, `victim_position`
- `Stats::Scores` combat payload now includes additive score-result projection fields:
  - `scores_snapshot` (section/use-teams/winner ids + team/player score rows)
  - `scores_result` (normalized winner/tie/draw semantics used by lifecycle win-context).

## JSON schema artifacts

- Envelope schema: `docs/schema/envelope-2026-02-20.1.schema.json`
- Lifecycle payload schema: `docs/schema/lifecycle-payload-2026-02-20.1.schema.json`
- Delivery error schema: `docs/schema/delivery-error-2026-02-20.1.schema.json`
- Event name catalog: `docs/schema/event-name-catalog-2026-02-20.1.json`

Validation expectation:

- Plugin emitters must produce envelopes valid against the `2026-02-20.1` schema files.
- Server ingestors should validate incoming envelopes by `schema_version` and reject unknown/invalid contracts with typed delivery errors.

## Outage queue telemetry

Connectivity payloads/metadata include queue and transport state snapshots:

- `queue` (`depth`, `max_size`, `high_watermark`, `dropped_on_capacity`, `dropped_on_identity_validation`, `recovery_flush_pending`)
- `retry` (`max_retry_attempts`, `retry_backoff_ms`, `dispatch_batch_size`)
- `outage` (`active`, `started_at`, `failure_count`, `last_error_code`, `recovery_flush_pending`)

Queue observability markers emitted by plugin runtime:

- `[PixelControl][queue][growth]`
- `[PixelControl][queue][retry_scheduled]`
- `[PixelControl][queue][drop_capacity]`
- `[PixelControl][queue][drop_identity_invalid]`
- `[PixelControl][queue][outage_entered]`
- `[PixelControl][queue][outage_recovered]`
- `[PixelControl][queue][recovery_flush_complete]`

## Compatibility notes

- Plugin `0.1.0-dev` now emits `schema_version=2026-02-20.1`.
- Consumers of this baseline must support:
  - canonical event naming and idempotency keys,
  - runtime identity-validation drop semantics for malformed envelopes,
  - enriched lifecycle `admin_action` semantics,
  - structured player transition telemetry payloads,
  - additive `roster_state`/eligibility/admin-correlation/reconnect/side-change/constraint-signals player fields,
  - additive lifecycle `aggregate_stats` team+win refinements and `map_rotation` veto action/result fields,
  - typed delivery error contract (`code`, `message`, `retryable`, `retry_after_seconds`).
