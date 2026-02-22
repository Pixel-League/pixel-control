# PLAN - Enforce matchmaking veto-to-match lifecycle with post-map reset (2026-02-22)

## Context

- Purpose: implement a deterministic matchmaking-mode server lifecycle after veto completion: launch veto -> go to selected map -> start match -> on selected-map end kick all players -> change map -> mark match ended -> keep veto ready for the next player cohort.
- Scope: plugin-first implementation in `pixel-control-plugin/` only, plus QA automation/evidence in `pixel-sm-server/` and documentation/contract updates; no backend implementation in `pixel-control-server/`.
- Background / findings:
  - Existing veto flow already resolves matchmaking winner and applies map queue in `pixel-control-plugin/src/Domain/VetoDraft/VetoDraftDomainTrait.php` (`handleDraftCompletionIfNeeded(...)`) through `VetoDraftQueueApplier` and `ManiaControlMapRuntimeAdapter`.
  - Lifecycle callbacks (`map.begin`, `map.end`, `match.begin`, `match.end`) are already normalized in `pixel-control-plugin/src/Domain/Lifecycle/LifecycleDomainTrait.php` and routed through callback registry/timers.
  - Current matchmaking completion path applies queue + opener jump but does not yet enforce a post-map lifecycle policy (kick-all, forced map advance, explicit match-end mark, ready-state projection).
  - Tournament mode is active in the same architecture; matchmaking-specific lifecycle automation must not alter tournament draft/series behavior.
- Goals:
  - Add an additive, guarded matchmaking lifecycle orchestrator that tracks post-veto match progression and executes only in matchmaking mode.
  - Ensure requested sequence is observable in runtime logs/status payloads and reproducible in QA scripts.
  - Keep tournament flow behavior unchanged.
- Non-goals:
  - No server/backend runtime implementation in `pixel-control-server/`.
  - No rewrites of existing veto coordinator/session engines.
  - No edits under `ressources/`.
- Constraints / assumptions:
  - Reuse existing veto architecture (`VetoDraftDomainTrait`, lifecycle callbacks, queue applier) and keep changes additive.
  - Prefer mode-guarded orchestration helpers over broad callback-path branching.
  - Maintain communication payload backward compatibility; any new fields must be additive.
  - Kick-all policy must be deterministic and safe for local dev/QA (actorless server path allowed where already supported).
- Risks / open questions:
  - Native "kick all players" semantics are ambiguous (disconnect vs force spectator); plan includes a freeze step before implementation.
  - Native explicit match-start/match-end entrypoints differ by mode/script; fallback command sequence may be required.
  - Map-boundary correlation must be precise (selected map end vs unrelated map transition), otherwise automation could trigger at wrong boundary.

## Steps

Execution rule: keep only the initial recon/planning step `[In progress]` at plan creation time; all other steps remain `[Todo]`.

- [Done] Phase 0 - Recon and lifecycle contract freeze
- [Done] Phase 1 - Add matchmaking lifecycle orchestration (plugin state + wiring)
- [Done] Phase 2 - Implement runtime lifecycle actions (start, kick-all, map-change, end mark, ready)
- [Done] Phase 3 - QA verification and evidence capture (scripted where possible)
- [Done] Phase 4 - Documentation and contract synchronization

### Phase 0 - Recon and lifecycle contract freeze

Acceptance criteria: requested sequence is translated into explicit technical gates, mode guards, and fallback behavior before code edits.

- [Done] P0.1 - Freeze lifecycle state machine and trigger boundaries for matchmaking mode.
  - Define canonical stages (for example: `veto_completed`, `selected_map_loaded`, `match_started`, `selected_map_finished`, `players_removed`, `map_changed`, `match_ended`, `ready_for_next_players`).
  - Define which lifecycle callbacks advance each stage (`map.begin`/`map.end` plus guarded map UID checks).
  - Freeze result:
    - Canonical staged flow for `matchmaking_vote` only: `veto_completed -> selected_map_loaded -> match_started -> selected_map_finished -> players_removed -> map_changed -> match_ended -> ready_for_next_players`.
    - Arm point: only after successful queue apply for completed matchmaking session (`session.status=completed`, `mode=matchmaking_vote`).
    - Stage advancement trigger boundaries:
      - `map.begin`: advance only when observed map UID equals selected winner map UID.
      - `map.end`: execute post-map actions only when observed map UID equals selected winner map UID.
      - Non-target maps and non-matchmaking sessions are no-op.
- [Done] P0.2 - Freeze operational semantics for "kick all players" and "start/end match".
  - Confirm native command/API path precedence and deterministic fallback sequence.
  - Confirm player targeting policy (human players only vs all connected players).
  - Freeze result:
    - Kick-all policy targets all currently connected players from `PlayerManager` (players + spectators + fake players), with fake-player disconnect fallback (`disconnectFakePlayer`) and regular kick fallback (`kick`).
    - Match-start attempt order on selected map load:
      - mode-script event trigger (`Maniaplanet.StartMatch.Start`) as primary signal,
      - then compatibility fallback commands (`Command_ForceWarmUp=false`, `Command_SetPause=false`, `Command_ForceEndRound=false`) plus warmup stop request.
    - Match-end mark attempt order after map-change:
      - mode-script event trigger (`Maniaplanet.EndMatch.Start`) as primary signal,
      - then compatibility fallback (`checkEndMatchCondition` when available + safe mode-script command fallback).
    - Map-change policy after kick-all: force `map.skip` once and record deterministic success/failure marker.
- [Done] P0.3 - Freeze additive compatibility contract.
  - Define additive status/telemetry fields and log markers that expose lifecycle progress without breaking existing payload readers.
  - Freeze result:
    - Additive status projection under `PixelControl.VetoDraft.Status` with deterministic `matchmaking_lifecycle` snapshot.
    - Additive lifecycle telemetry projection under `payload.map_rotation.matchmaking_lifecycle`.
    - Deterministic log marker family for stage and action observability:
      - `[PixelControl][veto][matchmaking_lifecycle][stage] ...`
      - `[PixelControl][veto][matchmaking_lifecycle][action] ...`
      - `[PixelControl][veto][matchmaking_lifecycle][ready] ...`

### Phase 1 - Add matchmaking lifecycle orchestration (plugin state + wiring)

Acceptance criteria: plugin has a dedicated, guarded matchmaking lifecycle context that can be advanced from existing veto/lifecycle callbacks.

- [Done] P1.1 - Introduce a dedicated matchmaking lifecycle context container.
  - Touchpoints: `pixel-control-plugin/src/PixelControlPlugin.php` + `pixel-control-plugin/src/Domain/VetoDraft/VetoDraftDomainTrait.php` (or additive helper under `pixel-control-plugin/src/VetoDraft/`).
  - Store session id, selected map uid, current lifecycle stage, timestamps, and safety flags/idempotency markers.
- [Done] P1.2 - Arm lifecycle context on successful matchmaking completion.
  - Touchpoint: `handleDraftCompletionIfNeeded(...)` in `pixel-control-plugin/src/Domain/VetoDraft/VetoDraftDomainTrait.php`.
  - Restrict arming to `mode=matchmaking_vote` and successful map-order application only.
- [Done] P1.3 - Add guarded lifecycle advancement hooks from callback path.
  - Touchpoints: existing lifecycle callback flow (`PipelineDomainTrait`/`LifecycleDomainTrait` integration) via additive helper invocations.
  - Ensure tournament sessions and non-target maps are no-op.
- [Done] P1.4 - Add reset/cleanup semantics.
  - Reset lifecycle context on plugin unload/reload, cancelled sessions, or unrecoverable action failures.

### Phase 2 - Implement runtime lifecycle actions (start, kick-all, map-change, end mark, ready)

Acceptance criteria: requested matchmaking sequence executes in order with deterministic guards and observable completion markers.

- [Done] P2.1 - On selected-map load, trigger match-start action once.
  - Use native runtime action path(s) validated in Phase 0 and persist stage transition marker.
- [Done] P2.2 - On selected-map end, execute kick-all policy.
  - Apply frozen player-targeting semantics and log per-run result summary (attempted, succeeded, failed).
- [Done] P2.3 - After kick-all, force map change and confirm next map boundary.
  - Reuse existing map action/runtime adapter path; keep branch-safe behavior if map change fails.
- [Done] P2.4 - Mark match ended and ready-for-next-players state.
  - Persist additive lifecycle result state and ensure veto control surface reports readiness for subsequent player cohort.
- [Done] P2.5 - Maintain tournament safety guarantees.
  - Explicit guards so tournament draft flow remains unchanged (no kick-all automation, no forced map-change lifecycle).

### Phase 3 - QA verification and evidence capture (scripted where possible)

Acceptance criteria: scripted checks verify deterministic lifecycle transitions and non-regression; artifacts are captured under QA logs.

- [Done] P3.1 - Static validation for touched PHP files.
  - `php -l <each touched plugin PHP file>`.
- [Done] P3.2 - Runtime sync before functional checks.
  - `bash pixel-sm-server/scripts/dev-plugin-hot-sync.sh`.
- [Done] P3.3 - Add/extend scripted matchmaking lifecycle smoke scenario.
  - Preferred: extend/add `pixel-sm-server/scripts/qa-veto-payload-sim.sh` matrix steps (or additive companion script) to verify:
    - matchmaking completion selects map,
    - selected-map boundary triggers match-start marker,
    - selected-map end triggers kick-all marker,
    - map-change marker appears,
    - final lifecycle state marks match ended + ready.
  - Persist strict machine-readable assertions in `pixel-sm-server/logs/qa/<run>/`.
- [Done] P3.4 - Execute non-regression veto/admin matrices.
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh matrix`
  - `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh matrix`
  - Ensure tournament compatibility checks remain green.
- [Done] P3.5 - Archive evidence references.
  - Capture run summary (`summary.md`/`matrix-validation.json`) and key runtime log markers in a dedicated QA evidence index.

### Phase 4 - Documentation and contract synchronization

Acceptance criteria: docs describe matchmaking lifecycle behavior, compatibility boundaries, and QA procedure updates.

- [Done] P4.1 - Update feature behavior documentation.
  - `pixel-control-plugin/FEATURES.md`: add explicit matchmaking lifecycle sequence and tournament guard notes.
- [Done] P4.2 - Update event/contract docs with additive fields and semantics.
  - `pixel-control-plugin/docs/event-contract.md`
  - `API_CONTRACT.md`
  - Note additive status/telemetry fields and no backend contract breakage.
- [Done] P4.3 - Update QA checklist documentation.
  - `pixel-control-plugin/docs/veto-system-test-checklist.md`: add lifecycle assertions for start/kick/change/end/ready sequence.
- [Done] P4.4 - Record durable incident memory if new blockers appear.
  - Append concise symptom/root-cause/fix/validation note to `AGENTS.md` after execution.

## Validation strategy

- Required static checks:
  - `php -l <each touched plugin PHP file>`
- Required runtime sync:
  - `bash pixel-sm-server/scripts/dev-plugin-hot-sync.sh`
- Required scripted checks:
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh matrix`
  - `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh matrix`
  - Additive matchmaking lifecycle smoke/assertion script (Phase 3 scope).
- Required behavior assertions:
  - Sequence markers present in order: veto completed -> selected map loaded -> match started -> selected map ended -> players removed -> map changed -> match ended -> ready.
  - Tournament mode remains unaffected.

## Evidence / Artifacts

- Planned QA artifact root:
  - `pixel-sm-server/logs/qa/matchmaking-lifecycle-<timestamp>/`
- Expected key artifacts:
  - `<run>/summary.md`
  - `<run>/matrix-validation.json`
  - `<run>/matrix-step-manifest.ndjson`
  - `<run>/evidence-index.md`

## Success criteria

- Matchmaking path enforces requested lifecycle sequence end-to-end with deterministic state transitions.
- Kick-all/map-change/end-mark actions are mode-guarded and idempotent for matchmaking only.
- `PixelControl.VetoDraft.Status` and lifecycle telemetry expose additive, machine-checkable lifecycle progress and ready-state.
- Tournament veto flow remains non-regressed.
- QA scripted checks pass and evidence artifacts are captured.
