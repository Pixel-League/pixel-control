# Features Pixel Control Plugin

This file tracks implemented features in `pixel-control-plugin` from `src/` and `docs/schema`.

## Plugin runtime

- Full ManiaControl plugin contract (`prepare`, `load`, `unload`, metadata getters)
- Optional dev auto-enable flow (`PIXEL_CONTROL_AUTO_ENABLE=1`) in `prepare`
- Callback groups registered through `CallbackRegistry`:
  - lifecycle callbacks
  - lifecycle script callbacks
  - player callbacks
  - vote callbacks
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
  - 44 canonical event names

## Connectivity

- Plugin registration event on load (`pixel_control.connectivity.plugin_registration`)
- Periodic heartbeat event (`pixel_control.connectivity.plugin_heartbeat`)
- Capability snapshot in connectivity payloads:
  - callback group counts
  - transport and queue knobs
  - schema and identity capabilities
  - delegated admin-control capabilities (`admin_control`) with ownership boundary (`telemetry_transport=pixel_plugin`, `admin_execution=native_maniacontrol`)
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
- Elite compatibility projection: `OnEliteStartTurn`/`OnEliteEndTurn` also emit lifecycle `round.begin`/`round.end` for cross-mode telemetry parity
- Raw callback summary and decoded script callback snapshot when available
- Lifecycle hooks drive:
  - aggregate combat telemetry (`aggregate_stats`)
  - map rotation telemetry (`map_rotation`)
  - veto/draft action stream updates

## Admin

- Admin action telemetry from script lifecycle callbacks
- Delegated native-admin execution surface (no duplicated business rules in plugin)
- Feature-gated control surface:
  - setting/env toggle: `Pixel Control Native Admin Control Enabled` / `PIXEL_CONTROL_ADMIN_CONTROL_ENABLED` (safe default: disabled)
  - command alias setting/env: `Pixel Control Native Admin Command` / `PIXEL_CONTROL_ADMIN_COMMAND` (default `pcadmin`)
  - pause-state freshness setting/env: `Pixel Control Pause State Max Age Seconds` / `PIXEL_CONTROL_ADMIN_PAUSE_STATE_MAX_AGE_SECONDS`
- Control entry points:
  - admin chat command: `//pcadmin` (or configured alias)
  - veto chat command: `//pcveto` runtime status/config helpers (`status`, `maps`, admin-only `config`, `ready`)
  - communication methods: `PixelControl.Admin.ExecuteAction`, `PixelControl.Admin.ListActions`
- Permission model:
  - chat command path is actor-bound and gated by native `AuthenticationManager` plugin rights (`definePluginPermissionLevel` + `checkPluginPermission`)
  - communication payload path currently supports actorless execution (`actor_login` optional) and skips permission checks in temporary trusted mode
- Delegated action catalog:
  - map: `map.skip`, `map.restart`, `map.jump`, `map.queue`, `map.add`, `map.remove`
  - warmup/pause: `warmup.extend`, `warmup.end`, `pause.start`, `pause.end`
  - votes: `vote.cancel`, `vote.set_ratio`, optional `vote.custom_start`
  - access/vote-policy state: `whitelist.enable`, `whitelist.disable`, `whitelist.add`, `whitelist.remove`, `whitelist.list`, `whitelist.clean`, `whitelist.sync`, `vote.policy.get`, `vote.policy.set`
  - team-control state: `team.policy.get`, `team.policy.set`, `team.roster.assign`, `team.roster.unassign`, `team.roster.list`
  - player/auth: `player.force_team`, `player.force_play`, `player.force_spec`, `auth.grant`, `auth.revoke`
  - BO policy: `match.bo.set`, `match.bo.get`
  - score recovery: `match.maps.set`, `match.maps.get`, `match.score.set`, `match.score.get`
- Deterministic delegated-action observability markers:
  - `[PixelControl][admin][action_requested]`
  - `[PixelControl][admin][action_success]`
  - `[PixelControl][admin][action_failed]`
  - `[PixelControl][admin][security_mode]` (actorless payload warning marker)
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

## Access control and vote policy

- Persisted whitelist runtime state (`WhitelistState`) with canonical login normalization and deterministic add/remove/clean/list semantics.
- Whitelist control settings:
  - `Pixel Control Whitelist Enabled` / `PIXEL_CONTROL_WHITELIST_ENABLED`
  - `Pixel Control Whitelist Logins` / `PIXEL_CONTROL_WHITELIST_LOGINS`
- Whitelist enforcement:
  - native guest-list sync (`cleanGuestList`, `addGuest`, `saveGuestList`) on bootstrap and state mutations,
  - deterministic connect/info callback fallback (`kick`) for non-whitelisted logins when whitelist is enabled,
  - deny-path marker: `[PixelControl][access][whitelist_denied]`.
- Vote-policy runtime state (`VotePolicyState`) with additive policy modes:
  - `cancel_non_admin_vote_on_callback` (native-first): cancels non-admin vote initiators on `ManiaPlanet.VoteUpdated`.
  - `disable_callvotes_and_use_admin_actions` (strict fallback): forces `setCallVoteTimeOut(0)` and keeps vote control via privileged admin actions.
- Vote policy setting:
  - `Pixel Control Vote Policy Mode` / `PIXEL_CONTROL_VOTE_POLICY_MODE`.

## Team roster control (milestone 1)

- Persisted login->team assignment state (`TeamRosterState`) with canonical aliases:
  - team A: `0|blue|team_a|a`
  - team B: `1|red|team_b|b`
- Team policy settings:
  - `Pixel Control Team Policy Enabled` / `PIXEL_CONTROL_TEAM_POLICY_ENABLED`
  - `Pixel Control Team Switch Lock Enabled` / `PIXEL_CONTROL_TEAM_SWITCH_LOCK_ENABLED`
  - `Pixel Control Team Roster Assignments` / `PIXEL_CONTROL_TEAM_ROSTER_ASSIGNMENTS` (JSON object map)
- Enforcement behavior:
  - team-mode guard via native script manager (`modeIsTeamMode()`),
  - forced-team runtime policy via dedicated `setForcedTeams(...)`,
  - assigned-player reconciliation on player callbacks, lifecycle starts, and periodic policy tick,
  - deterministic enforcement marker: `[PixelControl][team][enforce_applied]`.
- Runtime observability includes additive `team_control.runtime` snapshot with `team_mode_active`, `forced_teams_enabled`, `forced_club_links`, and `team_info` (`getTeamInfo` best-effort projection).

## Series policy controls

- Dedicated plugin-side state owner for runtime series policy defaults (`SeriesControlState`)
- Canonical runtime fields:
  - `best_of` (odd, bounded)
  - `maps_score.team_a`, `maps_score.team_b` (maps won in current BO)
  - `current_map_score.team_a`, `current_map_score.team_b` (rounds won in current map)
  - metadata (`updated_at`, `updated_by`, `update_source`)
- Update surfaces:
  - chat: `//pcveto config`, `//pcadmin match.bo.set best_of=<odd>`, `//pcadmin match.bo.get`, `//pcadmin match.maps.set target_team=<0|1|red|blue> maps_score=<int>`, `//pcadmin match.maps.get`, `//pcadmin match.score.set target_team=<0|1|red|blue> score=<int>`, `//pcadmin match.score.get`
  - communication: `PixelControl.Admin.ExecuteAction` with `match.bo.set|match.bo.get|match.maps.set|match.maps.get|match.score.set|match.score.get`
- Mutation behavior:
  - BO updates (`match.bo.set`) are applied immediately to runtime default policy state
  - active tournament sessions keep their current sequence; updated defaults apply to next start
  - score-recovery setters (`match.maps.set`, `match.score.set`) apply immediately to runtime BO/map progress state
  - score-recovery getters (`match.maps.get`, `match.score.get`) return current runtime values for teams 0/1
  - successful BO/maps/score mutations are persisted through ManiaControl settings with rollback on write failure (`setting_write_failed`), so runtime and stored state stay aligned
  - `target_team` accepts `0|1|red|blue` and aliases (`team_a|team_b|a|b`), normalized internally to `team_a|team_b`
  - deterministic validation failures use `missing_parameters` / `invalid_parameters`

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
  - series policy snapshot (`series_targets`)
  - additive matchmaking lifecycle snapshot (`matchmaking_lifecycle`) with stage/status/action/history observability
  - additive veto/draft mode metadata (`veto_draft_mode`, `veto_draft_session_status`)
  - authoritative veto/draft stream (`veto_draft_actions`) emitted by in-plugin draft engine with action metadata (`action_kind`, actor context, order index, timestamp/source, map identity)
  - final-selection projection (`veto_result`) with explicit `running|completed|cancelled|unavailable` status semantics
- Veto action kinds normalized to `ban|pick|pass|lock`
- Supported draft modes:
  - `matchmaking_vote`: all eligible players vote on map pool; top-vote winner selected; ties resolved by random draw among tied top-vote maps
  - `tournament_draft`: captain-driven ban phase then ordered pick phase with automatic decider lock for odd best-of formats
- Runtime default policy controls (admin-configurable):
  - `mode <matchmaking|tournament>` updates default session mode
  - `duration <seconds>` updates default matchmaking vote window
  - `min_players <int>` updates matchmaking threshold auto-start minimum connected human players
  - `ready` arms one explicit matchmaking cycle token (`//pcveto ready`)
  - default settings are persisted through ManiaControl plugin settings
- Matchmaking ready-gate behavior:
  - matchmaking session start is blocked until explicit arming (`matchmaking_ready_required`)
  - readiness token is consumed on successful matchmaking start (chat start, payload start, threshold auto-start, player vote bootstrap)
  - readiness token is not auto-rearmed on lifecycle completion; operator must run `//pcveto ready` again for the next cycle
  - additive status field `matchmaking_ready_armed` is exposed through `PixelControl.VetoDraft.Status` and lifecycle `map_rotation`
- Matchmaking player-first launch behavior:
  - when no veto session is active and default mode is `matchmaking_vote`, first player `vote` request auto-starts a matchmaking session with configured duration only when ready gate is armed
  - auto-start is intentionally limited to matchmaking mode for current rollout phase
- Matchmaking threshold auto-start behavior:
  - when default mode is `matchmaking_vote` and ready gate is armed, timer path arms a pre-start window once connected human players reach configured `min_players`
  - armed threshold window broadcasts one notice (`[PixelControl] Matchmaking veto starts in 15s.`) and launches the session only after the 15-second deadline if guards are still valid
  - pending threshold launch is canceled if ready/threshold guards drop before deadline; no stale delayed start is allowed after cancellation
  - explicit start paths (`start matchmaking`, communication `start`, and vote bootstrap) keep immediate behavior once ready gate is armed
  - anti-loop gating is transition-based (`armed`, `triggered`, `suppressed`, `below_threshold`) to avoid repeated start churn while count stays above threshold
- Matchmaking countdown UX:
  - running matchmaking sessions broadcast countdown from configured duration with deterministic cadence `N, N-10, ..., 10, 5, 4, 3, 2, 1`
  - per-session second-level dedupe avoids duplicate countdown spam for the same remaining second
- Matchmaking post-veto lifecycle automation (matchmaking mode only):
  - lifecycle context is armed only after successful queue apply for a completed `matchmaking_vote` session
  - deterministic stage sequence: `veto_completed -> selected_map_loaded -> match_started -> selected_map_finished -> players_removed -> map_changed -> match_ended -> ready_for_next_players`
  - selected-map load triggers match-start signaling (mode-script event first, warmup/pause compatibility fallback)
  - selected-map end triggers safe cleanup policy, map skip, and explicit match-end mark before lifecycle closure
  - cleanup policy is conservative: only fake/test actors are eligible for disconnect; human or unclassified identities are skipped
  - lifecycle action telemetry includes additive cleanup fields (`skipped_count`, `skipped_logins`, `cleanup_policy=fake_players_only`)
  - stage progression prefers lifecycle map callbacks and keeps a timer-based fallback inference path when callback coverage is missing in local runtime/QA
  - lifecycle state is additive in `PixelControl.VetoDraft.Status.matchmaking_lifecycle` and `payload.map_rotation.matchmaking_lifecycle`
  - tournament sessions are explicitly excluded from this lifecycle automation path
- `pcveto help` UX behavior:
  - role-aware visibility: admin-only docs (`start`, `cancel`, `mode`, `duration`, `config`, override usage) are shown only to users with veto control rights
  - mode-aware visibility: help output shows only one mode command group at a time based on effective mode policy (`active session mode` when running, else `configured default mode`)
  - deterministic fallback: unknown mode context falls back to configured default mode and emits a guidance line
- Chat observability:
  - explicit launch message on matchmaking session start (`Matchmaking veto launched ...`)
  - role-scoped map listing at session start:
    - players receive index+name rows only (`Available maps:` / `Available veto maps:`)
    - admins receive index+name+uid rows (`Map vote IDs:` / `Available veto IDs:`)
  - explicit queued-map messages after successful queue apply (`Queued maps:` + ordered map rows)
  - completion diagnostics (`Series order`, `Completion branch`, `Opener jump`) are admin-only
- Status command visibility (`/pcveto status`):
  - players receive veto-result projection only (`running|completed|cancelled|unavailable` + final map/series result when available)
  - admins keep operational diagnostics (`Map draft/veto status`, no-active-session guidance, vote/turn details, series config)
- Completion map-order apply policy:
  - branch `opener_differs`: queue full veto order then execute `map.skip` to opener
  - branch `opener_already_current`: queue remaining maps only and do not skip/restart current opener map
  - completion chat and log observability exposes branch + opener/current identities + skip result code
- Tournament draft BO precedence:
  - explicit request `best_of` takes priority
  - when omitted, start flow uses runtime series-policy `best_of` default

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
- Access/team policy settings:
  - whitelist enabled + login registry
  - vote policy mode
  - team policy enabled + switch-lock flag + roster assignments
- Veto/draft settings:
  - feature toggle (`enabled`)
  - command name (`pcveto` by default)
  - default mode (`matchmaking_vote` or `tournament_draft`)
  - matchmaking vote duration
  - matchmaking auto-start min players threshold
  - tournament turn timeout
  - default odd best-of value
  - immediate-launch toggle for applying selected opener
- Series policy settings:
  - persisted keys: veto default BO + runtime series scores (`maps_score.team_a|team_b`, `current_map_score.team_a|team_b`)
  - no extra series env settings beyond BO default (`PIXEL_CONTROL_VETO_DRAFT_DEFAULT_BEST_OF`)
- Persistence hardening:
  - veto default mode, matchmaking duration, and matchmaking auto-start min players persist through `SettingManager->setSetting(...)`
  - veto default mutations rollback runtime value when setting persistence fails (`setting_write_failed`)
- Runtime setting precedence: environment override first, ManiaControl setting fallback second

## Rollout and rollback

- Safe rollout default: veto/draft feature is disabled unless explicitly enabled (`PIXEL_CONTROL_VETO_DRAFT_ENABLED=1`).
- Permission guardrails:
  - all players can access vote/action command surface,
  - privileged operations (`start`, `cancel`, override actions) are gated by plugin permission rights.
- Rollback path:
  - set `PIXEL_CONTROL_VETO_DRAFT_ENABLED=0` (or disable corresponding ManiaControl setting),
  - reload plugin/server process,
  - plugin returns to baseline map-rotation behavior without persistent state migration.

## Contract artifacts

- `docs/event-contract.md` (canonical contract narrative)
- `docs/schema/envelope-2026-02-20.1.schema.json`
- `docs/schema/lifecycle-payload-2026-02-20.1.schema.json`
- `docs/schema/delivery-error-2026-02-20.1.schema.json`
- `docs/schema/event-name-catalog-2026-02-20.1.json`
