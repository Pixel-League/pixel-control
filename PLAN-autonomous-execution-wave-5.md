# PLAN - Autonomous execution wave 5: final plugin/dev-server closure and real-client handoff (2026-02-20)

## Context

- Purpose: Deliver the fifth and final autonomous plugin-first wave with finite scope, closing practical non-backend gaps and packaging a complete user-run manual gameplay validation path.
- Scope: Wave-5 is limited to `pixel-control-plugin/`, `pixel-sm-server/`, `API_CONTRACT.md`, `pixel-control-plugin/FEATURES.md`, `ROADMAP.md`, `AGENTS.md`, and final handoff/testing artifacts.
- Background / Findings:
  - Wave-4 deterministic telemetry closure is complete with indexed QA artifacts, but real-client gameplay evidence remains pending.
  - Prior plans still carry manual-only pending items (`PLAN-autonomous-execution-wave-1.md` P7.2/P7.3 and `PLAN-autonomous-execution-wave-3.md` P6.2).
  - `ROADMAP.md` still shows open lines that require wave-5 reconciliation, including documentation consistency around idempotency-key status versus current implementation reality.
  - Backend/API runtime work remains explicitly deferred; `API_CONTRACT.md` stays the source of truth for future backend implementation.
- Goals:
  - Tighten remaining plugin items feasible without real gameplay clients.
  - Improve deterministic plus manual evidence workflows so user-run ShootMania sessions produce reviewable artifacts without re-discovery.
  - Eliminate contract/documentation drift across `API_CONTRACT.md`, `pixel-control-plugin/FEATURES.md`, `ROADMAP.md`, and `AGENTS.md`.
  - Produce one final consolidated handoff package with clear rerun commands, acceptance gates, and manual-client test guidance.
- Non-goals:
  - Any runtime/backend implementation in `pixel-control-server/`.
  - Editing `ressources/` or running mutable workflows from reference directories.
  - Re-opening deferred backend checkpoints (`Checkpoint T` and `Checkpoint U`).
- Constraints / assumptions:
  - Schema evolution stays additive unless a documented contract exception is unavoidable.
  - Real client gameplay cannot be fully automated in this environment; wave-5 must produce deterministic preparation plus user-run manual matrix closure.
  - Keep one active step state at a time during execution.
- Dependencies / stakeholders:
  - User performs final ShootMania client gameplay runs and provides/records manual evidence artifacts.

## Steps

Execution rule: keep statuses current during execution and maintain a single active step.

### Phase 0 - Wave-5 lock and acceptance gates

- [Done] P0.1 Lock final-wave scope, file-touch map, and closure targets.
  - Confirm allowed touch areas and explicit exclusions (`pixel-control-server/` runtime remains untouched).
  - Lock wave-5 completion gates: plugin feasible hardening done, deterministic QA rerun green, docs synchronized, final manual-client matrix and handoff produced.
- [Done] P0.2 Freeze roadmap closure candidates for this wave.
  - Required reconciliation target: `Pixel Control Plugin > Stats > P1 Add event idempotency keys to avoid duplicate processing` consistency versus implementation reality.
  - Secondary candidate: progress `Pixel Control Plugin > Players > P2 Add constraints for forced teams and slot policies` as far as runtime exposure permits (implementation or explicit deferred/fallback semantics).
- 2026-02-20 closure target lock: required roadmap reconciliation is to mark `Stats > P1 Add event idempotency keys` according to existing envelope implementation (`event_id` + `idempotency_key` already emitted); secondary closure target is to harden player forced-team/slot-policy telemetry with explicit availability/fallback signaling before deciding done vs deferred wording.

### Phase 1 - Plugin hardening feasible without real gameplay clients

- [Done] P1.1 Tighten and verify event identity/idempotency behavior.
  - Audit envelope generation paths to ensure deterministic `event_id` and `idempotency_key` coverage across categories.
  - Add or refine validation hooks/tests/log checks to detect identity drift or missing idempotency fields before dispatch.
- 2026-02-20 implementation: outbound envelope flow now validates identity determinism at enqueue and dispatch (`event_name`, `event_id`, `idempotency_key`, category/source/sequence consistency), drops malformed envelopes with explicit `drop_identity_invalid` warnings, and tracks identity-drop telemetry via queue snapshot field `dropped_on_identity_validation`.
- [Done] P1.2 Improve forced-team/slot-policy telemetry and constraint signaling.
  - Extend player payload semantics to expose available forced-team/slot policy context from runtime callbacks/settings.
  - When policy enforcement data is unavailable, emit explicit availability markers and deterministic fallback reasons instead of silent omission.
- 2026-02-20 implementation: player payload now includes additive `constraint_signals` with cached dedicated policy context (`forced_team_policy`, `slot_policy`, policy fetch availability/failure markers, deterministic fallback reasons, and ordering metadata) instead of silently omitting unavailable team/slot enforcement context.
- [Done] P1.3 Keep plugin transport and callback hot-path behavior safe.
  - Ensure any new checks remain lightweight and do not introduce blocking logic in callback handlers.
  - Preserve additive compatibility for `schema_version=2026-02-20.1` unless a documented bump becomes necessary.
- 2026-02-20 validation: callback hot-path now consumes cached policy context only (`resolvePlayerConstraintPolicyContext(false)`), dedicated API refresh runs on load/heartbeat timers, and schema evolution remains additive under `2026-02-20.1` (no category/route renames, no required-field removals).

### Phase 2 - Pixel SM server deterministic/manual evidence pipeline upgrades

- [Done] P2.1 Add wave-5 manual-session bootstrap helper(s).
  - Provide a reproducible command flow that initializes `pixel-sm-server/logs/manual/wave5-real-client-<date>/` with required template files.
  - Include explicit fixture-off capture mode for plugin-only evidence collection.
- 2026-02-20 implementation: added `pixel-sm-server/scripts/manual-wave5-session-bootstrap.sh` and generated canonical scaffold `pixel-sm-server/logs/manual/wave5-real-client-20260220/` with required session templates (`INDEX.md`, session notes/payload/evidence files) plus explicit fixture-off replay command (`PIXEL_SM_QA_TELEMETRY_INJECT_FIXTURES=0`).
- [Done] P2.2 Improve operator-facing evidence capture guidance in first-party workflows.
  - Document exact commands for local ACK stub capture, ManiaControl log extraction, and optional dedicated-action traces.
  - Add deterministic naming conventions for manual session IDs and artifacts.
- 2026-02-20 implementation: added operator helper scripts `pixel-sm-server/scripts/manual-wave5-ack-stub.sh` (local ACK NDJSON capture) and `pixel-sm-server/scripts/manual-wave5-log-export.sh` (ManiaControl + shootmania log export), and documented exact wave-5 command flow + naming conventions in `pixel-sm-server/README.md` and generated manual README scaffold.
- [Done] P2.3 Add basic evidence completeness checks.
  - Validate that expected artifact files exist for each manual session entry and report missing items clearly.
- 2026-02-20 implementation: added `pixel-sm-server/scripts/manual-wave5-evidence-check.sh` (INDEX-driven session validation with explicit missing-file reports and status-aware payload checks) and validated current scaffold via `bash pixel-sm-server/scripts/manual-wave5-evidence-check.sh --manual-dir pixel-sm-server/logs/manual/wave5-real-client-20260220`.

### Phase 3 - Contract/docs synchronization and roadmap consistency closure

- [Done] P3.1 Reconcile idempotency-key status across roadmap and contracts.
  - Update `ROADMAP.md` to reflect validated implementation reality (or document remaining gap precisely if incomplete).
  - Ensure wording is aligned with `API_CONTRACT.md`, plugin contract docs, and `pixel-control-plugin/FEATURES.md`.
- 2026-02-20 reconciliation: `ROADMAP.md` now marks `Stats > P1 Add event idempotency keys to avoid duplicate processing` as done; contract/docs now explicitly describe deterministic identity generation and wave-5 identity-validation drop semantics.
- [Done] P3.2 Synchronize wave-5 payload and workflow documentation.
  - Update `API_CONTRACT.md` for any additive wave-5 player/identity/manual-evidence-related semantics.
  - Update `pixel-control-plugin/FEATURES.md` to reflect finalized plugin capabilities and explicit limitations.
- 2026-02-20 synchronization: `API_CONTRACT.md` now covers wave-5 identity guardrails and `player.constraint_signals`; `pixel-control-plugin/FEATURES.md` + `pixel-control-plugin/docs/event-contract.md` now describe identity-validation drop behavior, queue telemetry additions, and forced-team/slot-policy constraint signaling as telemetry-only surfaces.
- [Done] P3.3 Update execution memory and final-wave thread pointers.
  - Update `ROADMAP.md` active plan pointer to wave-5 and mark this as the final autonomous wave in the current sequence.
  - Update `AGENTS.md` with wave-5 status, reproducible commands, evidence paths, and remaining user-only manual closure notes.
- 2026-02-20 synchronization: `ROADMAP.md` now points to wave-5 as the final autonomous wave and records checkpoint `X`; `AGENTS.md` now captures wave-5 identity/constraint hardening, new manual evidence helper commands, canonical manual scaffold paths, and explicit remaining user-run gameplay closure notes.

### Phase 4 - QA and verification gates

- [Done] P4.1 Run syntax/schema/script sanity checks for touched files.
  - PHP lint for modified plugin files, shell syntax checks for modified scripts, and JSON schema/document parse checks where applicable.
- 2026-02-20 validation: `php -l pixel-control-plugin/src/PixelControlPlugin.php` and `php -l pixel-control-plugin/src/Api/EventEnvelope.php` both pass; `bash -n` checks pass for all new wave-5 helper scripts; `python3 -c "import json ..."` confirms updated envelope schema JSON is valid.
- [Done] P4.2 Run deterministic QA matrix before manual-client handoff.
  - Execute `bash pixel-sm-server/scripts/qa-launch-smoke.sh`.
  - Execute `bash pixel-sm-server/scripts/qa-mode-smoke.sh`.
  - Execute `bash pixel-sm-server/scripts/qa-wave4-telemetry-replay.sh` (including plugin-only/fixture-off mode if introduced in wave-5 workflow).
- 2026-02-20 validation: wave-5 matrix reruns passed (`pty_e8f46a44` launch smoke, `pty_99a35038` mode smoke, `pty_041be617` strict replay, `pty_c1d410c5` fixture-off plugin-only replay); initial fixture-off strict-profile failure (`pty_e4d4563d`) was resolved by marker-profile gating (`strict` vs `plugin_only`) in replay validation.
- [Done] P4.3 Capture and index wave-5 deterministic evidence.
  - Store outputs under `pixel-sm-server/logs/qa/wave5-<timestamp>-*` and publish an index file linking all relevant artifacts.
  - Record expected warnings/caveats (for example runtime fake-player `UnknownPlayer` edge behavior) as non-fatal QA notes when applicable.
- 2026-02-20 evidence index: published `pixel-sm-server/logs/qa/wave5-evidence-index-20260220.md` with launch/mode smoke artifacts, strict replay (`20260220-143317`), fixture-off plugin-only replay (`20260220-143433`), transitional fixture-off failure trace (`20260220-143020`), PTY command matrix, and explicit non-fatal caveat notes.

### Phase 5 - Comprehensive ShootMania client manual matrix and final handoff

- [Done] P5.1 Build comprehensive manual test matrix for real ShootMania clients.
  - Include scenario groups: stack/join baseline, admin flow actions, live combat counters, reconnect continuity, side/team transitions, team aggregates + win-context, veto/draft actor/result behavior, and outage/recovery replay.
  - For each scenario define prerequisites, exact operator actions in client, expected payload/log fields, and pass/fail criteria.
- 2026-02-20 deliverable: published `pixel-sm-server/logs/manual/wave5-real-client-20260220/MANUAL-TEST-MATRIX.md` with scenarios `W5-M01..W5-M10`, explicit prerequisites, operator actions, expected payload/log fields, pass/fail gates, and deterministic evidence destinations.
- [Done] P5.2 Define canonical manual evidence storage contract and templates.
  - Standardize artifact set under `pixel-sm-server/logs/manual/wave5-real-client-<date>/` with required files (`INDEX.md`, session notes, payload captures, screenshot/video references, mismatch notes).
  - Provide a matrix-to-artifact mapping so each manual scenario has a deterministic evidence destination.
- 2026-02-20 contract update: wave-5 scaffold now includes `MANUAL-TEST-MATRIX.md`, expanded session notes/evidence templates keyed by `W5-M01..W5-M10`, index guidance for status closure, and checker enforcement for matrix presence (`manual-wave5-evidence-check.sh`).
- [Done] P5.3 Produce final consolidated handoff/testing guidance.
  - Create `HANDOFF-autonomous-wave-5-<date>.md` with changed-file map, deterministic rerun commands, manual matrix quickstart, acceptance checklist, and explicit backend-deferred reminder.
  - Include closure statement for prior manual-pending plan items and clear next action for the user-run gameplay sessions.
- 2026-02-20 handoff published: `HANDOFF-autonomous-wave-5-2026-02-20.md` with wave-5 changed-file map, verification matrix, deterministic evidence links, real-client matrix quickstart, acceptance checklist, prior-plan manual-closure statement, and explicit backend-deferred reminder.

## Evidence / Artifacts

- Planned implementation and documentation touch targets:
  - `pixel-control-plugin/src/`
  - `pixel-control-plugin/docs/`
  - `pixel-control-plugin/FEATURES.md`
  - `pixel-sm-server/scripts/`
  - `pixel-sm-server/README.md`
  - `API_CONTRACT.md`
  - `ROADMAP.md`
  - `AGENTS.md`
- Expected deterministic QA artifacts:
  - `pixel-sm-server/logs/qa/wave5-<timestamp>-*/`
  - `pixel-sm-server/logs/qa/wave5-evidence-index-<date>.md`
  - `pixel-sm-server/runtime/server/ManiaControl/ManiaControl.log`
- Expected manual-client evidence artifacts:
  - `pixel-sm-server/logs/manual/wave5-real-client-<date>/MANUAL-TEST-MATRIX.md`
  - `pixel-sm-server/logs/manual/wave5-real-client-<date>/INDEX.md`
  - `pixel-sm-server/logs/manual/wave5-real-client-<date>/README.md`
  - `pixel-sm-server/logs/manual/wave5-real-client-<date>/SESSION-<id>-notes.md`
  - `pixel-sm-server/logs/manual/wave5-real-client-<date>/SESSION-<id>-payload.ndjson`
  - `pixel-sm-server/logs/manual/wave5-real-client-<date>/SESSION-<id>-evidence.md`
  - optional screenshots/videos linked from session evidence notes
- Final handoff artifact:
  - `HANDOFF-autonomous-wave-5-<date>.md`

## Success criteria

- Wave-5 remains fully within plugin/dev-server/docs scope; no runtime/backend code is added in `pixel-control-server/`.
- Plugin identity/idempotency and feasible forced-team/slot-policy telemetry gaps are either implemented or explicitly documented with deterministic fallback semantics.
- Deterministic QA matrix commands pass with indexed wave-5 artifacts.
- `API_CONTRACT.md`, `pixel-control-plugin/FEATURES.md`, `ROADMAP.md`, and `AGENTS.md` are synchronized and internally consistent.
- A comprehensive ShootMania client manual test matrix is delivered with explicit pass/fail criteria and canonical evidence storage paths.
- Final consolidated handoff guidance is published and ready for user-run real-client validation.

## Notes / outcomes

- Wave-5 execution scope stayed plugin/dev-server/docs only; backend runtime implementation remained untouched.
- Deterministic QA closure now has canonical indexing in `pixel-sm-server/logs/qa/wave5-evidence-index-20260220.md`.
- Manual real-client closure now uses one canonical matrix contract (`W5-M01..W5-M10`) under `pixel-sm-server/logs/manual/wave5-real-client-20260220/`.
- Remaining open work is exclusively user-run real-client evidence capture and INDEX status promotion (`planned` -> `passed`/`failed`), not additional implementation.
