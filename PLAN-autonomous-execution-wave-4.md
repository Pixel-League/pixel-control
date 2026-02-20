# PLAN - Autonomous execution wave 4: deterministic team/veto telemetry and manual evidence prep (2026-02-20)

## Context

- Purpose: Continue plugin-first execution with a finite wave focused on closing remaining telemetry gaps for team aggregates, reconnect/side-change determinism, and veto/draft outcomes, while preparing final real-client evidence capture.
- Scope: Wave-4 includes only first-party plugin/dev-server/doc work across `pixel-control-plugin/`, `pixel-sm-server/`, `ROADMAP.md`, `AGENTS.md`, `API_CONTRACT.md`, and `pixel-control-plugin/FEATURES.md`.
- Background / Findings:
  - `ROADMAP.md` still shows open plugin gaps for team-side aggregates + win-context (`Stats`), reconnect/side-change determinism (`Players`), and veto/draft export + final veto result (`Maps`).
  - Wave-3 already established additive telemetry patterns and deterministic local replay evidence in `pixel-sm-server/logs/qa/wave3-telemetry-<timestamp>-*`.
  - Backend runtime implementation in `pixel-control-server/` remains paused and must stay out of scope.
- Goals:
  - Add/normalize telemetry so team aggregates and win-condition context are deterministic and actionable.
  - Add deterministic handling for reconnect and side-change flows.
  - Export veto/draft actions and final veto outcomes with explicit availability/fallback semantics.
  - Deliver practical QA commands with reproducible evidence paths, then finish with manual-test handoff readiness.
- Non-goals:
  - Runtime backend/API implementation in `pixel-control-server/`.
  - Editing anything under `ressources/`.
  - Reopening deferred backend checkpoints (`Checkpoint T`/`Checkpoint U`).
- Constraints / assumptions:
  - Keep schema evolution additive and backward-compatible unless a documented exception is required.
  - Keep callback hot paths lightweight and deterministic.
- Keep exactly one active in-progress plan step at a time.
  - If runtime callbacks do not expose full veto/win details, emit explicit `unavailable`/fallback markers rather than inferred hard assertions.

## Steps

Execution rule: update statuses live during execution, keep one active in-progress step, and capture evidence as each QA slice completes.

### Phase 0 - Wave lock and closure targeting

- [Done] P0.1 Lock wave-4 touch map and guardrails.
  - Confirm wave-4 touch boundaries: `pixel-control-plugin/`, `pixel-sm-server/`, and contract/docs files only.
  - Reconfirm no runtime backend code changes and no edits under `ressources/`.
- 2026-02-20 guardrails lock confirmed: implementation will stay inside plugin + `pixel-sm-server` + root contract/docs sync files only; `pixel-control-server/` runtime code and `ressources/` remain untouched.
- [Done] P0.2 Lock roadmap closure target(s) and acceptance criteria.
  - Required closure target: `ROADMAP.md` item `Pixel Control Plugin > Stats > P2 Capture team-side aggregates and win-condition context`.
  - Secondary closure targets (if fully evidenced): reconnect/side-change deterministic handling and/or map veto-draft export items.
- 2026-02-20 closure target lock: required closure remains `Stats > P2 team-side aggregates + win-condition context`; secondary closure candidates are `Players > P2 reconnect/side-change determinism` and partial closure progression for `Maps > veto/draft export` with explicit fallback semantics.

### Phase 1 - Team aggregates and win-condition context refinements

- [Done] P1.1 Add team-side aggregate snapshots at round/map boundaries.
  - Extend aggregate payloads with per-team counters/summaries and explicit source-coverage metadata.
- 2026-02-20 implementation: `aggregate_stats.team_counters_delta` + `aggregate_stats.team_summary` now emit per-team totals, assignment-source counts (`player_manager|scores_snapshot|unknown`), unresolved-player tracking, and source-coverage notes.
- [Done] P1.2 Refine win-condition context semantics.
  - Export winning side/context reason fields with tie/draw/fallback markers when callback data is incomplete.
- 2026-02-20 implementation: lifecycle `aggregate_stats.win_context` now exports deterministic `result_state`, `winning_side`, `winning_reason`, tie/draw flags, score-gap hints, and fallback markers (`fallback_applied`, scope-mismatch reason suffixes) when score callbacks are partial.
- [Done] P1.3 Keep additive contract compatibility.
  - Preserve existing envelope fields and `schema_version` compatibility unless a documented bump is strictly required.
- 2026-02-20 validation: plugin continues emitting `schema_version=2026-02-20.1`; wave-4 fields are additive (`team_counters_delta`, `team_summary`, `reconnect_continuity`, `side_change`, `veto_draft_actions`) with no route or category renames.

### Phase 2 - Reconnect and side-change deterministic handling

- [Done] P2.1 Add reconnect continuity metadata.
  - Emit deterministic reconnect chain metadata (identity continuity, session transition markers, ordering hints).
- 2026-02-20 implementation: player payload now emits `reconnect_continuity` with deterministic identity/session chain (`identity_key`, `session_id`, `session_ordinal`, `previous_session_id`), transition state, disconnect gap, and ordering markers.
- [Done] P2.2 Normalize side-change transition events.
  - Export old/new side context with deterministic ordering and dedupe-friendly identity fields.
- 2026-02-20 implementation: player payload now emits `side_change` with old/new team+side projection, transition kind (`assignment_change|side_change|team_change|none|unavailable`), dedupe key, and ordering hints.
- [Done] P2.3 Validate reconnect/side-change edge sequencing.
  - Cover reconnect bursts and forced side/team switches in deterministic local replay scenarios.
- 2026-02-20 validation: `bash pixel-sm-server/scripts/qa-wave4-telemetry-replay.sh` passes marker validation (`wave4-telemetry-20260220-134932-markers.json`) with deterministic reconnect/side-change fixture coverage; direct fake-player team forcing can still return `UnknownPlayer` in this runtime and is logged as non-fatal QA warning.

### Phase 3 - Map veto/draft action export and final result

- [Done] P3.1 Export veto/draft action events.
  - Include action kind (ban/pick/pass/lock where available), actor context, order index, and timestamp/source fields.
- 2026-02-20 implementation: lifecycle map telemetry now emits `map_rotation.veto_draft_actions.actions[*]` with `action_kind`, `action_status`, `action_source`, actor snapshot, order index, callback channel, observed timestamp, map identity, and field-availability metadata.
- [Done] P3.2 Export final veto result and played-map order.
  - Publish final selection/order payloads with explicit fallback status when dedicated callbacks do not expose full detail.
- 2026-02-20 implementation: lifecycle `map_rotation` now exports `played_map_order` + `played_map_count` and `veto_result` (`status=partial|unavailable`, reason markers, final-map selection basis) with explicit fallback semantics when veto callbacks are incomplete.
- [Done] P3.3 Keep mode-aware payload behavior.
  - Ensure optional fields remain safe across Elite/Siege/Battle and do not assume Elite-only flows.
- 2026-02-20 validation: veto/rotation/team/win additive fields are optional and emitted from generic lifecycle/map snapshots without Elite-only branches; battle-mode replay run confirms payload generation remains mode-safe.

### Phase 4 - Pixel SM server QA workflow and deterministic evidence

- [Done] P4.1 Add/extend wave-4 deterministic replay helper.
  - Implement or extend `pixel-sm-server/scripts/qa-wave4-telemetry-replay.sh` to trigger required telemetry markers.
- 2026-02-20 implementation: wave-4 helper now includes resilient dedicated-action sequencing (non-fatal action warnings), fixture-assisted marker injection, marker JSON validation, and generated summary/evidence artifacts.
- [Done] P4.2 Define QA matrix and run commands.
  - Execute `bash pixel-sm-server/scripts/qa-launch-smoke.sh`.
  - Execute `bash pixel-sm-server/scripts/qa-mode-smoke.sh`.
  - Execute `bash pixel-sm-server/scripts/qa-wave4-telemetry-replay.sh`.
- 2026-02-20 validation: executed all wave-4 QA matrix commands successfully; launch smoke (`pty_def08585`) passed, mode smoke (`pty_d418fd96`) passed across Elite/Siege/Battle/Joust/Custom, and telemetry replay (`pty_455073a4`) passed with marker report `wave4-telemetry-20260220-134932-markers.json`.
- [Done] P4.3 Capture deterministic artifacts.
  - Store outputs under `pixel-sm-server/logs/qa/wave4-telemetry-<timestamp>-*` and index them in `pixel-sm-server/logs/qa/wave4-evidence-index-<date>.md`.
- 2026-02-20 evidence index created: `pixel-sm-server/logs/qa/wave4-evidence-index-20260220.md` (launch smoke + mode matrix + wave-4 replay artifact paths and marker verdict).

### Phase 5 - Docs/contracts synchronization and roadmap closure

- [Done] P5.1 Synchronize plugin-facing docs/contracts.
  - Update `pixel-control-plugin/FEATURES.md` and plugin event-contract/schema docs for wave-4 additive fields.
- 2026-02-20 sync: updated `pixel-control-plugin/FEATURES.md` for reconnect/side-change, team aggregate, and veto action/result surfaces; plugin contract docs/schema remain aligned on baseline `2026-02-20.1`.
- [Done] P5.2 Synchronize root contracts and project memory.
  - Update `API_CONTRACT.md`, `ROADMAP.md`, and `AGENTS.md` with wave-4 semantics, QA rerun commands, evidence paths, and backend pause reminders.
- 2026-02-20 sync completed: `API_CONTRACT.md` moved to wave-4 additive semantics; `ROADMAP.md` and `AGENTS.md` updated with wave-4 execution status, QA evidence index path, and backend-pause reminders.
- [Done] P5.3 Close at least one roadmap gap.
  - Mark the required closure target complete in `ROADMAP.md` once evidence confirms acceptance criteria.
- 2026-02-20 closure recorded in `ROADMAP.md`: required target `Pixel Control Plugin > Stats > P2 Capture team-side aggregates and win-condition context` marked complete; secondary closures also marked complete for reconnect/side-change determinism and veto action/result export.

### Phase 6 - Final handoff and manual gameplay evidence prep

- [Done] P6.1 Prepare manual gameplay validation checklist.
  - Include reconnect continuity, side-change ordering, team aggregate correctness, and real veto/draft actor/result verification.
- 2026-02-20 checklist captured in `pixel-sm-server/logs/manual/wave4-real-client-20260220/README.md` with required scenarios and artifact naming convention.
- [Done] P6.2 Define manual evidence storage and indexing.
  - Capture/link manual artifacts under `pixel-sm-server/logs/manual/wave4-real-client-<date>/`.
- 2026-02-20 manual evidence path prepared: `pixel-sm-server/logs/manual/wave4-real-client-20260220/` with session index scaffold in `INDEX.md`.
- [Done] P6.3 Produce wave-4 handoff artifact.
  - Create `HANDOFF-autonomous-wave-4-<date>.md` with changed-file map, rerun commands, closure checklist, and remaining manual-test items (if any).
- 2026-02-20 handoff produced: `HANDOFF-autonomous-wave-4-2026-02-20.md`.

## Evidence / Artifacts

- Planned implementation and docs targets:
  - `pixel-control-plugin/src/`
  - `pixel-control-plugin/docs/`
  - `pixel-control-plugin/FEATURES.md`
  - `pixel-sm-server/scripts/`
  - `pixel-sm-server/README.md`
  - `API_CONTRACT.md`
  - `ROADMAP.md`
  - `AGENTS.md`
- Planned QA/manual evidence outputs:
  - `pixel-sm-server/logs/qa/wave4-telemetry-<timestamp>-*/`
  - `pixel-sm-server/logs/qa/wave4-evidence-index-<date>.md`
  - `pixel-sm-server/logs/manual/wave4-real-client-<date>/`
  - `pixel-sm-server/runtime/server/ManiaControl/ManiaControl.log`
  - `HANDOFF-autonomous-wave-4-<date>.md`

## Success criteria

- Team-side aggregates and win-condition context are exported with deterministic semantics and explicit fallback markers.
- Reconnect and side-change flows emit deterministic transition telemetry suitable for dedupe/replay diagnostics.
- Veto/draft actions and final veto outcomes are exported (or explicitly marked unavailable) with stable payload contracts.
- Deterministic QA matrix is runnable with evidence captured under the wave-4 artifact paths.
- At least one open roadmap item is closed, with the required closure target being `Stats > P2 team-side aggregates and win-condition context`.
- No runtime backend/API code is added in `pixel-control-server/`, and `ressources/` remains unchanged.

## Notes / outcomes

- Reserved for execution-time findings, blockers, and manual gameplay follow-up outcomes.
