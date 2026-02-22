# PLAN - Harden veto QA control-surface coverage and regression safety (2026-02-22)

## Context

- Purpose: deeply harden veto QA/testing so text-only tooling can reliably control and validate veto behavior, with regression confidence after future changes.
- Scope: first-party plugin/dev-server QA tooling and docs only (`pixel-sm-server/`, `pixel-control-plugin/`, root docs/plans, local `AGENTS.md` updates as needed); no backend runtime implementation in `pixel-control-server/`.
- Background / findings:
  - `pixel-sm-server/scripts/qa-veto-payload-sim.sh` already drives `PixelControl.VetoDraft.Start|Action|Status|Cancel` and emits run artifacts, but matrix steps are monolithic and not one-file-per-scenario.
  - `pixel-sm-server/scripts/test-automated-suite.sh` runs per-mode veto matrix + response-shape assertions, but veto-specific coverage expansion still depends on extending the veto simulation/assertion surface.
  - `pixel-sm-server/scripts/qa-admin-payload-sim.sh` already uses modular one-file-per-action step scripts (`scripts/qa-admin-matrix-actions/*.sh`) and descriptor-based automated-suite checks (`scripts/automated-suite/admin-actions/*.sh`), which is the preferred pattern to replicate for veto.
  - Existing veto checklist coverage is broad (`pixel-control-plugin/docs/veto-system-test-checklist.md`), but large portions are manual and not fully mapped to deterministic script gates.
  - Current product direction is plugin-first/dev-server-first with server orchestration simulated through communication methods; this plan must preserve that direction.
- Goals:
  - Build a clear, reproducible inventory of veto control surfaces and current QA coverage.
  - Identify and close automation gaps for veto behavior, especially regressions detectable through text-only scripts.
  - Align QA tooling with future Pixel Control Server orchestration by validating the same communication surfaces and expected response contracts.
  - Keep QA scripts modular (one script per action/feature where applicable) and easy to extend.
  - Produce deterministic artifacts proving pass/fail outcomes.
- Non-goals:
  - No backend/API runtime work in `pixel-control-server/`.
  - No edits under `ressources/`.
  - No replacement of real-client manual gameplay coverage where deterministic automation is fundamentally limited.
- Constraints / assumptions:
  - Plugin-first/dev-server-first remains authoritative.
  - Communication methods (`PixelControl.VetoDraft.*`) are the primary server-orchestration contract under test.
  - Existing automated-suite pass/fail semantics and required-check discipline must be preserved.
  - Any new QA automation must emit machine-readable artifacts under `pixel-sm-server/logs/qa/`.

## Steps

Execution rule: planning artifact only. Executor keeps one active `[In progress]` step at a time, updates statuses live, and does not perform backend runtime work.

- [Done] Phase 1 - Inventory and audit current veto QA/testing coverage
- [Done] Phase 2 - Identify veto control-surface and regression gaps
- [Done] Phase 3 - Implement missing veto tooling/assertions with modular script layout
- [Done] Phase 4 - Run validations and collect reproducible evidence
- [Done] Phase 5 - Documentation sync and AGENTS memory update

### Phase 1 - Inventory and audit current veto QA/testing coverage

Acceptance criteria: a concrete inventory maps veto control surfaces to existing scripts, assertions, and artifact outputs.

- [Done] P1.1 - Build script inventory for veto-related automation.
  - Audit `qa-veto-payload-sim.sh`, `test-automated-suite.sh`, `qa-admin-payload-sim.sh`, and supporting descriptor/action-module directories.
  - Record command entrypoints, env knobs, expected outputs, and current failure semantics.
- [Done] P1.2 - Build control-surface coverage matrix.
  - Map each veto surface to current coverage status: communication methods (`Start`, `Action`, `Status`, `Cancel`), chat/admin surfaces, lifecycle telemetry (`map_rotation.veto_draft_actions`, `veto_result`), and automated-suite gates.
  - Tag each row `covered`, `partial`, `manual_only`, or `uncovered`.
- [Done] P1.3 - Freeze baseline artifacts and reproducibility contract.
  - Define mandatory artifact files per run (summary, step JSONs, validation JSON, suite summary).
  - Lock baseline output-root conventions under `pixel-sm-server/logs/qa/`.
- [Done] P1.4 - Capture baseline limitations explicitly.
  - Document known constraints (for example placeholder captain/login behavior, mode-dependent compatibility codes, manual-only real-session actor behavior).

### Phase 2 - Identify veto control-surface and regression gaps

Acceptance criteria: gap list is prioritized and translated into concrete implementation tasks.

- [Done] P2.1 - Identify assertion gaps in current veto matrix behavior.
  - Check where matrix runs produce artifacts without strict pass/fail assertions.
  - Identify missing negative-case coverage (invalid params/session-state conflicts/captain constraints/turn-permission constraints).
- [Done] P2.2 - Identify control-surface parity gaps for future server orchestration.
  - Verify whether communication-driven behavior validates the same operational outcomes expected from chat/admin control surfaces.
  - Flag any missing parity assertions between response payloads and veto lifecycle telemetry fields.
- [Done] P2.3 - Prioritize hardening scope into required vs optional work.
  - Required: regressions that can silently pass today.
  - Optional: additional convenience diagnostics that do not affect correctness gates.
- [Done] P2.4 - Freeze implementation design for modular veto QA.
  - Define one-file-per-action/feature layout for veto checks (mirroring admin matrix modularity).
  - Define integration points with `qa-veto-payload-sim.sh` and `test-automated-suite.sh`.

### Phase 3 - Implement missing veto tooling/assertions with modular script layout

Acceptance criteria: veto QA can be run and extended through modular scripts with deterministic assertions.

- [Done] P3.1 - Introduce modular veto matrix action/step scripts.
  - Create a dedicated module directory (for example `pixel-sm-server/scripts/qa-veto-matrix-actions/`) with one file per matrix step/scenario.
  - Refactor veto matrix runner to source/execute modules in deterministic order.
- [Done] P3.2 - Add strict veto response/state assertion tooling.
  - Implement parser/validator logic to fail on malformed response shape, unexpected communication errors, and invalid transition codes/states.
  - Ensure checks cover both matchmaking and tournament flows.
- [Done] P3.3 - Add veto coverage descriptors for automated-suite extensibility.
  - Add modular descriptors (one file per veto required check) for automated-suite required coverage inventory/gates.
  - Wire descriptor loading so veto coverage expansion avoids monolithic hardcoded lists.
- [Done] P3.4 - Add deterministic negative-path checks.
  - Cover expected veto failure codes (`session_active`, captain/parameter validation errors, actor/turn restrictions, pool-size constraints) through scripted scenarios.
- [Done] P3.5 - Keep output-root and evidence generation deterministic.
  - Ensure all new/updated scripts support run-local artifact roots and emit machine-readable outputs consumed by follow-up assertions.

### Phase 4 - Run validations and collect reproducible evidence

Acceptance criteria: required checks pass with reproducible artifacts; regressions are detectable through strict gates.

- [Done] P4.1 - Run static/script integrity checks.
  - Validate shell syntax for touched scripts (`bash -n`) and run optional lint if available.
- [Done] P4.2 - Run targeted veto control-surface checks.
  - Run status/start/action/cancel paths plus modular veto matrix with strict assertions.
  - Verify deterministic handling for both success and expected-failure scenarios.
- [Done] P4.3 - Run non-regression suites.
  - Re-run `qa-admin-payload-sim.sh matrix` for cross-surface safety.
  - Re-run `test-automated-suite.sh` (at minimum `--modes elite,joust`) and confirm required checks remain green.
- [Done] P4.4 - Produce evidence index with canonical paths.
  - Write a concise evidence index summarizing run IDs, pass/fail verdicts, and key artifact files.

### Phase 5 - Documentation sync and AGENTS memory update

Acceptance criteria: docs and memory accurately reflect the hardened veto QA workflow.

- [Done] P5.1 - Update QA/tooling docs for veto.
  - Document new modular veto scripts, execution order, and extension pattern.
  - Keep plugin-first/dev-server-first framing explicit.
- [Done] P5.2 - Update contract docs only if control-surface contract changes.
  - If payload/response expectations changed, update `API_CONTRACT.md` and `pixel-control-plugin/docs/event-contract.md` additively.
  - If unchanged, record "no contract change" in outcomes.
  - Outcome for this execution: no contract change; QA tooling/assertion coverage only.
- [Done] P5.3 - Update local `AGENTS.md` with durable learnings.
  - Add concise incident/operational memory: symptom, root cause, fix, validation signal, and artifact paths.
- [Done] P5.4 - Publish executor handoff summary.
  - Include completed checks, remaining manual-only boundaries, and exact evidence locations.

## Verification commands (required during execution)

- Baseline veto method checks:
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh status`
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh start mode=matchmaking_vote duration_seconds=8 launch_immediately=0`
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh action actor_login=voter_a operation=vote map=1`
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh cancel reason=qa_cleanup`
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh matrix`
- Cross-surface/admin baseline checks:
  - `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh list-actions`
  - `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh matrix`
- Automated regression gates:
  - `bash pixel-sm-server/scripts/test-automated-suite.sh --modes elite,joust`
- Runtime sync when plugin code is modified in execution:
  - `bash pixel-sm-server/scripts/dev-plugin-hot-sync.sh`
- Planned new strict veto checks (after Phase 3 implementation):
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh matrix` (modularized + strict assertion mode)
  - `bash pixel-sm-server/scripts/test-automated-suite.sh --modes elite,joust` (with new veto descriptor coverage loaded)

## Required evidence paths

- Veto simulation artifacts:
  - `pixel-sm-server/logs/qa/veto-payload-sim-<timestamp>/summary.md`
  - `pixel-sm-server/logs/qa/veto-payload-sim-<timestamp>/step-*.json`
- Admin simulation artifacts (cross-surface safety):
  - `pixel-sm-server/logs/qa/admin-payload-sim-<timestamp>/summary.md`
  - `pixel-sm-server/logs/qa/admin-payload-sim-<timestamp>/list-actions.json`
- Automated-suite artifacts:
  - `pixel-sm-server/logs/qa/automated-suite-<timestamp>/suite-summary.json`
  - `pixel-sm-server/logs/qa/automated-suite-<timestamp>/check-results.ndjson`
  - `pixel-sm-server/logs/qa/automated-suite-<timestamp>/coverage-inventory.json`
- New veto hardening audit/index artifacts (to add in this scope):
  - `pixel-sm-server/logs/qa/veto-control-surface-hardening-<timestamp>/inventory.md`
  - `pixel-sm-server/logs/qa/veto-control-surface-hardening-<timestamp>/gap-analysis.md`
  - `pixel-sm-server/logs/qa/veto-control-surface-hardening-<timestamp>/evidence-index.md`

## Success criteria

- Every veto control surface relevant to server orchestration is mapped to deterministic QA coverage (`covered` or explicitly `manual_only` with rationale).
- Veto QA regression checks are modular and extensible (one script per action/feature where applicable), not locked to a monolithic matrix-only implementation.
- Required veto assertions fail reliably on real regressions and stay green on expected compatibility/fallback behavior.
- Automated suite remains green for required checks after veto hardening updates.
- Documentation and `AGENTS.md` memory are updated with repeatable commands, constraints, and evidence locations.
