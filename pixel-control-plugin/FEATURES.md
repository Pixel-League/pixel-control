# Features Pixel Control Plugin

This file tracks implemented features in `pixel-control-plugin` from `src/` and `docs/schema`.

## Plugin runtime

- Full ManiaControl plugin contract (`prepare`, `load`, `unload`, metadata getters)
- Optional dev auto-enable flow (`PIXEL_CONTROL_AUTO_ENABLE=1`) in `prepare`
- Callback groups registered through `CallbackRegistry`:
  - lifecycle callbacks
  - lifecycle script callbacks
  - player callbacks
  - combat callbacks
  - mode-specific callbacks
- Timer loop:
  - dispatch timer every second
  - heartbeat timer (`heartbeat_interval_seconds`)
- Deterministic runtime reset on `unload` (queue/outage/player/map/admin caches)

## Event identity and envelope contract

- Envelope schema version `2026-02-20.1`
- Canonical event naming pattern: `pixel_control.<event_category>.<normalized_source_callback>`
- Monotonic source sequence on every envelope
- Deterministic `event_id` + `idempotency_key`
- Runtime identity validation on enqueue + dispatch with explicit invalid-envelope drop marker (`drop_identity_invalid`)
- Canonical event catalog shipped in `docs/schema/event-name-catalog-2026-02-20.1.json`:
  - 5 categories (`connectivity`, `lifecycle`, `player`, `combat`, `mode`)
  - 42 canonical event names

## Connectivity

- Plugin registration event on load (`pixel_control.connectivity.plugin_registration`)
- Periodic heartbeat event (`pixel_control.connectivity.plugin_heartbeat`)
- Capability snapshot in connectivity payloads:
  - callback group counts
  - transport and queue knobs
  - schema and identity capabilities
- Runtime context snapshot:
  - server info + current map identity
  - active/total/spectator player counts
- Queue/retry/outage snapshots included in connectivity metadata and heartbeat payloads

## Lifecycle

- Unified lifecycle normalization across ManiaPlanet + script callbacks
- Variants:
  - warmup (`start`, `end`, `status`)
  - pause (`start`, `end`, `status`)
  - match (`begin`, `end`)
  - map (`begin`, `end`)
  - round (`begin`, `end`)
  - fallback `lifecycle.unknown`
- Source channel traceability (`maniaplanet` vs `script`)
- Raw callback summary and decoded script callback snapshot when available
- Lifecycle hooks drive:
  - aggregate combat telemetry (`aggregate_stats`)
  - map rotation telemetry (`map_rotation`)
  - veto/draft action stream updates

## Admin

- Admin action telemetry from script lifecycle callbacks
- Structured admin action fields:
  - `action_name`
  - `action_domain`
  - `action_type`
  - `action_phase`
  - `target_scope`
  - `target_id`
  - `initiator_kind`
  - `actor`
- Supported admin action families:
  - warmup start/end/status
  - pause start/end/status (dynamic resolution from `Maniaplanet.Pause.Status` `active` value)
  - match start/end
  - map loading/unloading
  - round start/end
- Pause request context enrichment (`requested_by_login`, `requested_by_team_id`, `requested_by_team_side`, `active`)
- Bounded recent admin-action history for player correlation

## Players

- Player connect event (`Player::Connect`)
- Player disconnect event (`Player::Disconnect`)
- Player info change event (`Player::InfoChanged`)
- Player infos changed event (`Player::InfosChanged`)
- Structured player transition payloads:
  - previous/current player snapshots
  - state delta (`before/after/changed` entries)
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
  - forced-team projection (`policy_state`, `reason`, assignment-change hints)
  - slot-policy projection (`policy_state`, `reason`, pressure/utilization hints)
  - deterministic unavailable/fallback reasons when dedicated policy fields are missing
  - cached policy fetch strategy with TTL and failure-log cooldown

## Combat and scores

- Player shot event (`Stats::Shot`)
- Player hit event (`Stats::Hit`)
- Player near-miss event (`Stats::NearMiss`)
- Player armor-empty/kill-edge event (`Stats::Dead` semantics via `OnArmorEmpty`)
- Capture event (`Stats::Capture`)
- Score snapshot event (`Stats::Scores`)
- Score-result projection on score updates (`scores_snapshot`, `scores_result`, winner/tie context)

Runtime counters tracked per player (in-memory, non-persistent):

- kills
- deaths
- hits
- shots
- misses
- rockets
- lasers
- accuracy

- Counters are kept only in plugin runtime memory and reset on `match.begin` / `map.begin` boundaries (and on plugin reload/restart)

Combat dimensions currently emitted when available:

- `weapon_id`
- `damage`
- `distance`
- `event_time`
- shooter identity + position
- victim identity + position

Additional combat observability:

- `[Pixel Plugin]` combat log lines for shoot/hit/near-miss/armor-empty/capture/scores callbacks

## Lifecycle aggregate telemetry

- `aggregate_stats` emitted on `round.end` and `map.end`
- Aggregate payload includes:
  - per-player combat deltas and total counters
  - per-team combat aggregates (`team_counters_delta`, `team_summary`)
    - assignment-source coverage (`player_manager`, `scores_snapshot`, `unknown`)
  - boundary window metadata + source coverage notes
  - deterministic `win_context` (`result_state`, `winning_side`, `winning_reason`, tie/draw and fallback semantics)

## Maps and veto/draft

- `map_rotation` telemetry on `map.begin` and `map.end`
- Map rotation payload includes:
  - map pool snapshot (`map_pool`, `map_pool_size`)
  - current index + next map hints
  - played map order history
  - normalized map identifiers (`uid`, `name`, optional `external_ids.mx_id`)
  - additive veto/draft stream (`veto_draft_actions`) with action metadata (`action_kind`, actor context, order index, timestamp/source, map identity)
  - final-selection projection (`veto_result`) with explicit `partial|unavailable` fallback semantics when dedicated veto callbacks are incomplete
- Veto action kinds normalized to `ban|pick|pass|lock` (with inferred `lock` fallback when needed)

## Mode-specific callbacks

- Elite callbacks: start turn, end turn
- Joust callbacks: reload, selected players, round result
- Royal callbacks: points, player spawn, round winner
- Current mode payload behavior is generic callback summary passthrough (dedicated mode enrichers are not implemented yet)

## Transport and resilience

- Async HTTP transport for envelope delivery
- Typed delivery error normalization (`DeliveryError`) with retryability + optional `retry_after_seconds`
- Bounded local in-memory queue for temporary API outages (ephemeral, not persisted to disk/database)
- Capacity pressure behavior drops oldest ready item at max queue size (`drop_capacity`)
- Retry/backoff behavior with retryable error semantics
- Outage/recovery markers:
  - `outage_entered`
  - `retry_scheduled`
  - `outage_recovered`
  - `recovery_flush_complete`
- Queue observability markers and counters:
  - `queue_growth`
  - `dropped_on_capacity`
  - `dropped_on_identity_validation`
  - high-watermark and recovery-flush pending tracking
- Dispatch batch-size controls
- Telemetry persistence boundary: durable historical storage is delegated to `pixel-control-server` (plugin side is runtime-memory + temporary queue only)

## Configuration surface

- API transport settings:
  - API base URL/path
  - timeout
  - max retry attempts
  - retry backoff
- Queue/dispatch settings:
  - queue max size
  - dispatch batch size
  - heartbeat interval
- Auth settings:
  - modes (`none`, `bearer`, `api_key`)
  - auth value
  - auth header name (API key mode)
- Runtime setting precedence: environment override first, ManiaControl setting fallback second

## Contract artifacts

- `docs/event-contract.md` (canonical contract narrative)
- `docs/schema/envelope-2026-02-20.1.schema.json`
- `docs/schema/lifecycle-payload-2026-02-20.1.schema.json`
- `docs/schema/delivery-error-2026-02-20.1.schema.json`
- `docs/schema/event-name-catalog-2026-02-20.1.json`
