# PLAN - Gate matchmaking veto cycles behind explicit ready command (2026-02-22)

## Context

- Purpose: diagnose and fix a matchmaking veto lifecycle regression where, after veto completion and map transition, a new veto auto-restarts with default 10s duration, map restarts, and ManiaControl behavior appears duplicated.
- Scope: plugin-first changes in `pixel-control-plugin/`, plus first-party QA scripts/evidence in `pixel-sm-server/` and documentation updates in first-party docs (`FEATURES.md`, contract/checklist docs, `AGENTS.md` incident memory).
- Background / findings:
  - Matchmaking veto orchestration already combines timer-driven checks, min-player auto-start logic, and post-completion lifecycle handling.
  - Current issue appears in post-completion transition: a fresh matchmaking cycle is triggered without an explicit operator intent gate.
  - Requested operator model is explicit arming via command, then automatic system handling; no silent re-arming after completion.
- Goals:
  - Add admin command `//pcveto ready`.
  - Allow a new matchmaking veto cycle only after `//pcveto ready` is explicitly run.
  - Preserve existing threshold/autostart + normal matchmaking veto flow once ready is armed.
  - Ensure that after veto completion (single-map or multi-map progression), no new matchmaking veto starts until `//pcveto ready` is run again.
- Non-goals:
  - No behavioral change to tournament flow.
  - No backend implementation in `pixel-control-server/`.
  - No edits under `ressources/`.
- Constraints / assumptions:
  - Keep communication/contracts additive and backward compatible (no removals/renames of existing fields/actions).
  - Follow modular QA script preference (one action/check script per step/feature where applicable).
  - Keep readiness gate scoped to matchmaking mode only.
- Risks / open questions:
  - Multiple start paths exist (timer threshold, vote-triggered path, payload/API start path); all matchmaking start entrypoints must share one gate to avoid bypasses.
  - Completion/lifecycle callback timing can race with fallback logic; diagnosis must identify exact trigger order before implementation.

## Steps

Execution rule: initialize with only the first recon step as `[In progress]`; all other steps remain `[Todo]`.

- [Done] Phase 0 - Diagnose regression and freeze ready-gate contract
- [Done] Phase 1 - Implement `//pcveto ready` and matchmaking cycle gate
- [Done] Phase 2 - QA validation, evidence capture, and non-regression
- [Done] Phase 3 - Documentation, contract sync, and incident memory update

### Phase 0 - Diagnose regression and freeze ready-gate contract

Acceptance criteria: exact root cause and trigger sequence are documented; ready-gate semantics are frozen before code edits.

- [Done] P0.1 - Reproduce and diagnose unintended matchmaking auto-restart and duplicated behavior.
  - Reproduce the issue with existing first-party flows and inspect runtime markers around veto completion and map transition.
  - Trace the exact path(s) that spawn the unintended 10s matchmaking veto restart and duplicated map/lifecycle behavior.
- [Done] P0.2 - Freeze `//pcveto ready` behavior contract for matchmaking.
  - Define command semantics: admin-only, explicit one-cycle arming for matchmaking.
  - Define consumption/reset semantics: after a completed matchmaking cycle, readiness returns to not-ready until command is run again.
  - Define interaction contract: once armed, existing min-player threshold/autostart and normal veto progression operate unchanged.
- [Done] P0.3 - Freeze compatibility and safety guardrails.
  - Tournament mode unchanged by explicit mode guards.
  - Additive-only contract evolution for status/telemetry/action surfaces.
  - Confirm no mutable workflows in `ressources/`.

Diagnostic freeze notes:

- Current matchmaking start entrypoints are ungated: timer threshold (`evaluateMatchmakingAutostartThreshold()`), player vote bootstrap (`ensureConfiguredMatchmakingSessionForPlayerAction()`), and payload/chat start path (`handleVetoDraftCommunicationStart()` / `executeVetoDraftStartRequest()`).
- Post-matchmaking lifecycle completion currently re-arms threshold auto-start (`completeMatchmakingLifecycleContext()` sets autostart armed=true), which allows a fresh cycle without explicit operator re-arming in environments where threshold remains satisfied.
- Ready-gate implementation target is matchmaking-only and additive: `//pcveto ready` grants one explicit matchmaking cycle token consumed on session start; token must not auto-reset to ready on completion.

### Phase 1 - Implement `//pcveto ready` and matchmaking cycle gate

Acceptance criteria: matchmaking cycles cannot start unless armed by `//pcveto ready`; tournament remains unchanged.

- [Done] P1.1 - Add admin command `//pcveto ready` to veto control surface.
  - Touchpoints: `pixel-control-plugin/src/Domain/VetoDraft/VetoDraftDomainTrait.php` and related command/help surfaces.
  - Enforce existing veto-control permission model for command access.
- [Done] P1.2 - Add a dedicated matchmaking ready gate state model.
  - Track whether next matchmaking cycle is explicitly armed, with deterministic reset points.
  - Keep state handling isolated and mode-scoped to avoid tournament coupling.
- [Done] P1.3 - Gate all matchmaking start entrypoints through the ready state.
  - Apply gate to timer/min-player auto-start path.
  - Apply gate to vote-triggered matchmaking bootstrap path.
  - Apply gate to any matchmaking payload/control start path so behavior is consistent across interfaces.
- [Done] P1.4 - Reset readiness after matchmaking completion and transition closure.
  - Ensure no automatic re-arming after completion, map end, or map transition.
  - Prevent default-duration (10s) immediate re-launch loops unless `//pcveto ready` is run again.
- [Done] P1.5 - Keep contracts additive and observability explicit.
  - If status/telemetry visibility is needed, add fields additively (for example ready armed/not-armed) without breaking existing consumers.
  - Preserve existing tournament/session payload behavior.

### Phase 2 - QA validation, evidence capture, and non-regression

Acceptance criteria: new ready-gated lifecycle is verified end-to-end; existing matrices stay green.

- [Done] P2.1 - Run static checks for touched plugin PHP files.
  - `php -l <each touched plugin PHP file>`.
- [Done] P2.2 - Sync runtime plugin before functional verification.
  - `bash pixel-sm-server/scripts/dev-plugin-hot-sync.sh`.
- [Done] P2.3 - Extend modular veto QA flow for ready-gate behavior.
  - Add/update modular matrix action/check scripts under first-party QA script structure (no monolithic hardcoded additions).
  - Add machine-checkable assertions to matrix validation artifacts for ready-gate pre/post conditions.
- [Done] P2.4 - Execute targeted behavior checks for requested outcomes.
  - Without `//pcveto ready`, matchmaking veto does not auto-start even when threshold conditions are met.
  - After `//pcveto ready`, system auto-handles threshold/autostart and normal veto flow.
  - After veto completion and map transition, no new veto starts until `//pcveto ready` is run again.
  - Verify duplicated restart behavior is absent in runtime markers/logs.
- [Done] P2.5 - Run non-regression command matrices.
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh matrix`.
  - `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh matrix`.
  - Optional full regression executed: `bash pixel-sm-server/scripts/test-automated-suite.sh --modes elite,joust` (`checks_total=39`, `passed=39`, `required_failures=0`, run dir `pixel-sm-server/logs/qa/automated-suite-20260222-180955/`).
- [Done] P2.6 - Archive evidence artifacts.
  - Store summaries/validation outputs in a dedicated QA artifact root and list them for handoff.

### Phase 3 - Documentation, contract sync, and incident memory update

Acceptance criteria: operator behavior and compatibility boundaries are documented; durable incident memory is recorded.

- [Done] P3.1 - Update feature docs for new command and lifecycle gate.
  - `pixel-control-plugin/FEATURES.md`: document `//pcveto ready` flow and post-completion no-autorestart rule.
- [Done] P3.2 - Update contract docs only if surfaces changed.
  - `pixel-control-plugin/docs/event-contract.md` and `API_CONTRACT.md` for additive status/telemetry/action notes (if introduced).
- [Done] P3.3 - Update veto QA checklist/documentation.
  - `pixel-control-plugin/docs/veto-system-test-checklist.md`: add ready-gate assertions and completion re-arm requirements.
- [Done] P3.4 - Append incident memory in local `AGENTS.md` after execution.
  - Record symptom, root cause, applied fix, and validation signal for this regression.

## Validation strategy

- Required static/runtime prep:
  - `php -l <each touched plugin PHP file>`
  - `bash pixel-sm-server/scripts/dev-plugin-hot-sync.sh`
- Required functional/QA checks:
  - Ready-gate targeted scenario checks (blocked-before-ready, allowed-after-ready, blocked-again-after-completion).
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh matrix`
  - `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh matrix`
- Required regression guarantees:
  - Tournament behavior remains unchanged.
  - No breaking contract changes (additive only where needed).

## Evidence / Artifacts

- Planned QA artifact root:
  - `pixel-sm-server/logs/qa/veto-ready-gate-<timestamp>/`
- Recorded QA artifact root:
  - `pixel-sm-server/logs/qa/veto-ready-gate-20260222-1807/`
- Expected key artifacts:
  - `<run>/summary.md`
  - `<run>/matrix-validation.json`
  - `<run>/matrix-step-manifest.ndjson`
  - `<run>/evidence-index.md`
- Recorded canonical matrix runs:
  - `pixel-sm-server/logs/qa/veto-payload-sim-20260222-180544/`
  - `pixel-sm-server/logs/qa/admin-payload-sim-20260222-180638/`

## Success criteria

- Admin command `//pcveto ready` exists and is permission-guarded.
- Matchmaking veto cycle cannot start unless explicitly armed via `//pcveto ready`.
- Once armed, threshold/autostart and normal matchmaking veto flow proceed automatically.
- After veto completion and subsequent map transition(s), another matchmaking veto does not start until `//pcveto ready` is run again.
- Tournament behavior is unchanged, and QA matrices remain green with evidence captured.
