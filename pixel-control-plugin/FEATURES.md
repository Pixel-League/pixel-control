# Features Pixel Control Plugin

This file is the functional checklist for `pixel-control-plugin`.

## Connectivity

- Plugin registration event on load (`Connectivity::Register`)
- Periodic heartbeat event (`Connectivity::Heartbeat`)
- Monotonic source sequence on every envelope
- Deterministic `event_id` + `idempotency_key`
- Runtime identity validation guardrails (enqueue + dispatch) with explicit invalid-envelope drop marker (`drop_identity_invalid`)

## Lifecycle

- Warmup lifecycle events (`warmup.start`, `warmup.end`, `warmup.status`)
- Pause lifecycle events (`pause.start`, `pause.end`, `pause.status`)
- Match lifecycle events (`match.begin`, `match.end`)
- Map lifecycle events (`map.begin`, `map.end`)
- Round lifecycle events (`round.begin`, `round.end`)
- Unified lifecycle normalization across ManiaPlanet + script callbacks

## Admin

- Admin action telemetry from lifecycle callbacks (`Admin::*` semantics)
- Structured admin action fields:
  - `action_domain`
  - `action_type`
  - `action_phase`
  - `target_scope`
  - `target_id`
  - `initiator_kind`
- Supported admin action families:
  - warmup start/end/status
  - pause start/end/status (dynamic resolution from `Maniaplanet.Pause.Status` payload)
  - match start/end
  - map loading/unloading
  - round start/end

## Players

- Player connect event (`Player::Connect`)
- Player disconnect event (`Player::Disconnect`)
- Player info change event (`Player::InfoChanged`)
- Player infos changed event (`Player::InfosChanged`)
- Structured player transition payloads:
  - previous/current player snapshots
  - state delta
  - permission signals
  - roster-state telemetry (`current`, `previous`, `delta`, aggregate)
  - admin correlation context (`admin_correlation`)
  - reconnect continuity chain (`reconnect_continuity`)
  - side/team transition projection (`side_change`)
  - team/slot policy constraint telemetry (`constraint_signals`)
  - roster snapshot
- Eligibility/readiness signal surface:
  - `eligibility_state`, `readiness_state`, `can_join_round`
  - deterministic fallback markers (`field_availability`, `missing_fields`)
  - change markers (`slot_changed`, `readiness_changed`, `eligibility_changed`)
- Constraint policy surface:
  - dedicated policy context snapshot (`forced_teams`, `keep_player_slots`, max players/spectators)
  - forced-team projection (`policy_state`, `reason`, team assignment change hints)
  - slot-policy projection (`policy_state`, `reason`, pressure/utilization hints)
  - deterministic unavailable/fallback reasons when runtime policy fields are missing

## Stats

- Player shot event (`Stats::Shot`)
- Player hit event (`Stats::Hit`)
- Player near-miss event (`Stats::NearMiss`)
- Player dead/armor-empty event (`Stats::Dead`)
- Capture event (`Stats::Capture`)
- Score snapshot event (`Stats::Scores`)
- Score-result projection on score updates (`scores_snapshot`, `scores_result`, winner/tie context)

Runtime counters currently tracked per player:

- kills
- deaths
- hits
- shots
- misses
- rockets
- lasers
- accuracy

Combat dimensions currently emitted when available:

- `weapon_id`
- `damage`
- `distance`
- shooter identity + position
- victim identity + position

Aggregate telemetry emitted on lifecycle boundaries:

- `aggregate_stats` on `round.end` and `map.end`
  - per-player combat deltas and total counters
  - per-team combat aggregates (`team_counters_delta`, `team_summary`)
    - assignment-source coverage (`player_manager`, `scores_snapshot`, `unknown`)
  - boundary window metadata + source coverage notes
  - win-context projection (`win_context`) with deterministic result semantics (`result_state`, `winning_side`, `winning_reason`, tie/draw markers, fallback semantics)

## Maps

- `map_rotation` telemetry on `map.begin` and `map.end`
  - map pool snapshot (`map_pool`, `map_pool_size`)
  - current index + next map hints
  - played map order history
  - normalized map identifiers (`uid`, `name`, optional `external_ids.mx_id`)
  - additive veto/draft stream (`veto_draft_actions`) with action metadata (`action_kind`, actor context, order index, timestamp/source, map identity)
  - final-selection projection (`veto_result`) with explicit `partial|unavailable` status/reason fallback semantics when dedicated veto callbacks are incomplete

## Mode-specific

- Elite start/end turn callbacks
- Joust callbacks (reload, selected players, round result)
- Royal callbacks (points, player spawn, round winner)

## Transport and resilience

- Bounded local queue for temporary API outages
- Retry/backoff behavior with retryable error semantics
- Outage markers (`outage_entered`, `retry_scheduled`, `outage_recovered`, `recovery_flush_complete`)
- Queue identity drop telemetry (`dropped_on_identity_validation`)
- Dispatch batch-size controls

## Configuration surface

- API base URL/path
- Timeout/retry controls
- Queue max size / dispatch batch size
- Heartbeat interval
- Auth modes (`none`, `bearer`, `api_key`)
