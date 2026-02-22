# PLAN - Add veto countdown chat, matchmaking auto-start threshold, and persistence hardening (2026-02-22)

## Context

- Purpose: deliver an execution-ready, plugin-first plan for veto UX/runtime behavior updates requested by the user: countdown chat announcements, matchmaking auto-start on player threshold, persistence hardening, map-list broadcast on start, and deterministic map jump behavior on veto completion.
- Scope: `pixel-control-plugin/` implementation and QA updates, plus docs/contracts/checklist updates in first-party files; no backend runtime work in `pixel-control-server/`.
- Background / findings:
  - Matchmaking veto currently auto-starts only on first `vote` action (`ensureConfiguredMatchmakingSessionForPlayerAction(...)`), not on server player-threshold joins.
  - Veto timer tick currently handles completion/timeout only (`handleVetoDraftTimerTick()`), with no countdown announcements at `40/30/.../10` and `5..1`.
  - Start-time map visibility exists in parts of current overview flow, but this scope requires a guaranteed "at least once per session" map+veto-ID broadcast when a veto session starts (including automatic starts).
  - Completion queue apply currently delegates launch behavior to `launch_immediately`; existing same-map opener behavior can restart current map. Requested behavior is stricter: queue order + skip to first map when opener differs, and queue remaining maps only (no skip/restart) when opener already matches current map.
  - Current persisted runtime-mutable veto/series defaults include mode, matchmaking duration, BO default, maps score, and current-map score.
- Goals:
  - Countdown chat announcements are emitted in English with deterministic cadence and no duplicate spam.
  - Matchmaking veto auto-starts when connected-player threshold is reached, with threshold configurable via `//pcveto` and persisted.
  - A clear must-persist matrix is implemented and verified so restart does not reset operator defaults unexpectedly.
  - Veto session start always shows map list with veto IDs at least once.
  - Veto completion applies requested queue/skip semantics for opener map transitions.
- Non-goals:
  - No backend/API server implementation in `pixel-control-server/`.
  - No mutable workflows in `ressources/`.
  - No redesign of tournament/matchmaking algorithms beyond requested UX/runtime orchestration behavior.
- Constraints / assumptions:
  - Plugin-first architecture and existing command/communication surfaces remain authoritative (`pcveto`, `PixelControl.VetoDraft.*`, `pcadmin`).
  - Runtime setting precedence remains env-first, ManiaControl setting fallback second; persistence QA must account for env override masking.
  - Chat-visible messages in this scope must be English and consistently formatted with `[PixelControl]` prefix.
  - Auto-start must avoid rapid restart loops while player count stays above threshold (crossing/arming policy required).

## Must-persist inventory for this scope

- Persisted already (verify and keep):
  - `default_mode` (`SETTING_VETO_DRAFT_DEFAULT_MODE`)
  - `matchmaking_duration_seconds` (`SETTING_VETO_DRAFT_MATCHMAKING_DURATION_SECONDS`)
  - `default_best_of` (`SETTING_VETO_DRAFT_DEFAULT_BEST_OF`)
  - `maps_score.team_a|team_b` and `current_map_score.team_a|team_b` (`SeriesControl` settings)
- Persist in this scope (implementation required):
  - `matchmaking_autostart_min_players` (new setting + env fallback + chat setter + status visibility)
- Audit in this scope (decision + alignment required):
  - Ensure all runtime-mutated veto defaults changed via admin chat persist through `SettingManager->setSetting(...)`.
  - Keep session-ephemeral runtime fields non-persistent by design (`active session`, `votes/actions`, `countdown announcement cache`, `last applied session id`).

## Steps

Execution rule: this is a planning artifact only. Executor keeps one active `[In progress]` item and updates statuses live.

- [Done] Phase 0 - Freeze behavioral contract and persistence boundary
- [Done] Phase 1 - Add settings and command scaffolding for matchmaking auto-start threshold
- [Done] Phase 2 - Implement player-threshold auto-start flow
- [Done] Phase 3 - Implement countdown and start-time map-list chat UX
- [Done] Phase 4 - Implement completion queue+skip opener behavior
- [Done] Phase 5 - QA, verification evidence, and non-regression checks
- [Done] Phase 6 - Documentation/contract sync and handoff notes

### Phase 0 - Freeze behavioral contract and persistence boundary

Acceptance criteria: executor has an explicit, written implementation contract for countdown cadence, auto-start gating, queue/skip semantics, and persisted-vs-ephemeral state.

- [Done] P0.1 - Freeze countdown announcement policy.
  - Announce remaining time at every 10 seconds (`... 40s, 30s, 20s, 10s`) and every second for `5,4,3,2,1`.
  - Define deterministic English message templates and one-per-second dedupe policy.
  - Execution decision: emit `[PixelControl] Matchmaking veto ends in <N>s.` only for `40|30|20|10|5|4|3|2|1`, deduped by `<session_id, remaining_seconds>`.
- [Done] P0.2 - Freeze auto-start threshold semantics.
  - Define counting policy (connected human players), default value, min bound, and deterministic anti-loop arming/disarming logic.
  - Freeze admin command syntax under `//pcveto` for threshold updates (for example `min_players <int>`).
  - Execution decision: count via `PlayerManager::getPlayerCount(false, true)` (connected non-bot players), default `min_players=2`, lower bound `1`, command `//pcveto min_players <int>`, and transition-gated arming (`armed -> triggered -> suppressed -> re-armed below threshold`).
- [Done] P0.3 - Freeze completion jump behavior contract.
  - If current map differs from veto opener: queue full veto order, then `map.skip` to opener.
  - If current map already equals opener: queue only remaining maps and do not `map.skip` or restart current map.
  - Execution decision: completion queue policy is branch-based and deterministic; branch details (`opener_differs` vs `opener_already_current`) must be surfaced in apply result + chat/QA visibility.
- [Done] P0.4 - Freeze persistence matrix and non-persistence matrix.
  - Enumerate all runtime-mutable veto/series defaults and mark each `persisted` vs `ephemeral` with rationale.
  - Execution decision (persisted): `default_mode`, `matchmaking_duration_seconds`, `default_best_of`, `matchmaking_autostart_min_players` (new), `maps_score.team_a|team_b`, `current_map_score.team_a|team_b`.
  - Execution decision (ephemeral): active session state, votes/actions, countdown dedupe cache, auto-start arming/suppression flags, and last-applied-session marker.
  - Execution decision (request-scoped): start payload overrides (`launch_immediately`, explicit start duration, explicit tournament `best_of`) stay per-start and do not mutate persisted defaults.

### Phase 1 - Add settings and command scaffolding for matchmaking auto-start threshold

Acceptance criteria: plugin exposes a new persisted threshold setting with runtime/env resolution and admin chat update path.

- [Done] P1.1 - Add new setting constants/properties and bootstrap wiring.
  - Touchpoints: `pixel-control-plugin/src/PixelControlPlugin.php`, `pixel-control-plugin/src/Domain/Core/CoreDomainTrait.php`, `pixel-control-plugin/src/Domain/VetoDraft/VetoDraftDomainTrait.php`.
  - Add `SETTING_VETO_DRAFT_MATCHMAKING_AUTOSTART_MIN_PLAYERS` + runtime property + unload reset value.
- [Done] P1.2 - Initialize and resolve setting with env fallback.
  - Add env key (for example `PIXEL_CONTROL_VETO_DRAFT_MATCHMAKING_AUTOSTART_MIN_PLAYERS`) and sanitize int lower bound.
  - Include resolved value in veto bootstrap log line.
- [Done] P1.3 - Add `//pcveto` admin subcommand for threshold mutation.
  - Parse operation + parameter (`min_players`), enforce control permission, sanitize value, persist with deterministic `setting_write_failed` handling.
  - Extend `//pcveto config` output and (additively) `PixelControl.VetoDraft.Status` output to include threshold value.

### Phase 2 - Implement player-threshold auto-start flow

Acceptance criteria: matchmaking veto session auto-starts when threshold is reached, with deterministic behavior and no repeated start loops.

- [Done] P2.1 - Add connected-player threshold evaluator helper.
  - Reuse existing player manager/runtime patterns to compute current connected-player count used for auto-start gating.
- [Done] P2.2 - Integrate threshold check into periodic veto timer loop.
  - In idle matchmaking default mode, auto-start when threshold condition transitions to satisfied.
  - Ensure starts can originate from timer path without breaking existing `vote`-triggered fallback path.
- [Done] P2.3 - Add anti-loop arming/disarming state and observability markers.
  - Prevent immediate re-start churn while player count remains above threshold.
  - Emit concise markers for `armed`, `triggered`, `suppressed`, and `below_threshold` transitions.

### Phase 3 - Implement countdown and start-time map-list chat UX

Acceptance criteria: countdown and map-list messages are English, well formatted, and emitted exactly as requested.

- [Done] P3.1 - Add countdown event/state pipeline.
  - Touchpoints: `pixel-control-plugin/src/VetoDraft/VetoDraftCoordinator.php` and/or `pixel-control-plugin/src/Domain/VetoDraft/VetoDraftDomainTrait.php`.
  - Compute remaining seconds for active matchmaking sessions and emit only required countdown points.
- [Done] P3.2 - Broadcast countdown messages in chat.
  - Message examples: `[PixelControl] Matchmaking veto ends in 40s.` and `[PixelControl] Matchmaking veto ends in 5s.`
  - Guarantee no duplicate announcements for the same remaining-second value in one session.
- [Done] P3.3 - Guarantee map list with veto IDs is shown at session start at least once.
  - Reuse existing map-row formatter (`buildMapListRows`) and invoke from all start paths (chat start, communication start, threshold auto-start).
  - Keep index/UID visibility explicit so players can vote by ID immediately.

### Phase 4 - Implement completion queue+skip opener behavior

Acceptance criteria: queue apply uses requested opener-jump logic and never restarts current map when opener already matches current map.

- [Done] P4.1 - Extend runtime adapter boundary for opener comparison and skip control.
  - Touchpoints: `pixel-control-plugin/src/VetoDraft/MapRuntimeAdapterInterface.php`, `pixel-control-plugin/src/VetoDraft/ManiaControlMapRuntimeAdapter.php`.
  - Expose current-map identity and explicit skip operation needed by queue applier policy.
- [Done] P4.2 - Refactor queue apply policy to two explicit branches.
  - Touchpoint: `pixel-control-plugin/src/VetoDraft/VetoDraftQueueApplier.php`.
  - Branch A (different map): queue full veto order + execute skip to opener.
  - Branch B (same map): queue only remaining maps + no skip/restart.
- [Done] P4.3 - Align completion chat/reporting payloads with applied branch.
  - Touchpoint: `pixel-control-plugin/src/Domain/VetoDraft/VetoDraftDomainTrait.php`.
  - Surface concise branch info in completion messages/details so QA can assert behavior.

### Phase 5 - QA, verification evidence, and non-regression checks

Acceptance criteria: static checks pass, new behavior is validated with reproducible steps, and existing veto/admin matrices remain green.

- [Done] P5.1 - Static validation for touched PHP files.
  - Run `php -l` on each changed plugin PHP file.
- [Done] P5.2 - Sync runtime plugin after code edits (repo preference).
  - Run `bash pixel-sm-server/scripts/dev-plugin-hot-sync.sh` before functional verification.
- [Done] P5.3 - Targeted functional checks for new veto behavior.
  - Countdown cadence check (40/30/20/10 + 5/4/3/2/1) in chat/log.
  - Threshold auto-start check: session starts when connected-player threshold is reached and does not loop.
  - Start-time map list check: map rows with veto IDs are shown at least once per new session.
  - Completion jump check in two scenarios:
    - opener differs from current map -> skip executed,
    - opener equals current map -> no skip/restart; queue excludes opener.
- [Done] P5.4 - Scripted non-regression checks.
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh status`
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh matrix`
  - `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh matrix`
  - Optional full suite pass (time permitting): `bash pixel-sm-server/scripts/test-automated-suite.sh --modes elite,joust`
- [Done] P5.5 - Capture evidence artifacts.
  - Store/refer QA outputs under `pixel-sm-server/logs/qa/<run>/` and note key files in handoff summary.

### Phase 6 - Documentation/contract sync and handoff notes

Acceptance criteria: user-facing behavior and contract visibility are documented with additive updates only.

- [Done] P6.1 - Update feature docs.
  - `pixel-control-plugin/FEATURES.md`: countdown UX, threshold auto-start config, persistence matrix additions, completion skip behavior.
- [Done] P6.2 - Update contract docs when payload surface changes.
  - `pixel-control-plugin/docs/event-contract.md` and `API_CONTRACT.md` only if `PixelControl.VetoDraft.Status` or related payload fields are extended.
  - Keep contract evolution additive (no schema/version bump unless required).
- [Done] P6.3 - Update QA checklist.
  - `pixel-control-plugin/docs/veto-system-test-checklist.md`: add countdown assertions, threshold persistence checks, and completion branch checks.
- [Done] P6.4 - Record concise incident memory in local `AGENTS.md` for any blocker discovered during execution (symptom/root cause/fix/validation).

## Validation strategy

- Required static checks:
  - `php -l <each touched plugin PHP file>`
- Required runtime sync and core matrices:
  - `bash pixel-sm-server/scripts/dev-plugin-hot-sync.sh`
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh status`
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh matrix`
  - `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh matrix`
- Required targeted manual checks:
  - Countdown cadence lines appear exactly at requested remaining times.
  - Auto-start triggers at configured threshold and persists across restart.
  - Map list with veto IDs appears on session start.
  - Completion branch behavior follows opener different/same rules.

## Risks and rollback notes

- Risks:
  - Countdown notifications may spam if per-session dedupe is incomplete.
  - Auto-start threshold logic can thrash without transition-based arming.
  - Queue/skip refactor can regress current map-queue compatibility.
  - Env overrides can mask persisted settings during restart QA.
- Mitigations:
  - Keep countdown cache scoped by session id + remaining-second marker.
  - Require threshold crossing policy and explicit suppress/arm logging.
  - Preserve adapter boundary and add deterministic branch details in apply result.
  - Run restart QA with env values unset/aligned for persistence-focused validation.
- Rollback path:
  - Disable veto feature via `PIXEL_CONTROL_VETO_DRAFT_ENABLED=0` for emergency rollback.
  - Revert queue-apply policy changes while keeping previous stable behavior if map-transition regressions appear.

## Success criteria

- Countdown chat announcements are emitted in English at `40/30/20/10` and `5/4/3/2/1` (no duplicates).
- Matchmaking veto auto-starts when connected-player threshold is reached; threshold is configurable via `//pcveto` and survives restart.
- A documented persistence matrix is implemented and verified for all veto/series runtime defaults that should survive restart.
- Each veto session start shows map list and veto IDs at least once in chat.
- On veto completion, server behavior matches requested queue+skip contract for both opener-different and opener-already-current scenarios.
