# PLAN - Automated orchestrator for Pixel Control automatable coverage (2026-02-21)

## Context

- Purpose: provide one execution-ready orchestrator command that validates all currently automatable Pixel Control features in the plugin-first stack and exits non-zero on required failures.
- Scope: implementation and documentation updates in first-party project areas only (`pixel-sm-server/`, `pixel-control-plugin/`, root docs/plans); no backend runtime implementation in `pixel-control-server/`.
- Background / Findings:
  - Existing reusable QA scripts are already available and stable enough for orchestration: `qa-launch-smoke.sh`, `qa-mode-smoke.sh`, `qa-wave3-telemetry-replay.sh`, `qa-wave4-telemetry-replay.sh`, `qa-admin-payload-sim.sh`, `dev-mode-compose.sh`, `manual-wave5-ack-stub.sh`, and `dev-plugin-sync.sh`.
  - Existing plans/docs already separate deterministic automation from real-client validation (`PLAN-real-client-live-combat-stats-qa.md`, `PLAN-autonomous-execution-wave-5.md`, wave-5 manual matrix).
  - `qa-wave4-telemetry-replay.sh` already supports both `strict` and `plugin_only` marker profiles and emits machine-readable marker JSON.
  - `qa-admin-payload-sim.sh` already produces list/execute response artifacts but currently writes to a fixed logs root; orchestrator-friendly output-root wiring must be added.
- Goals:
  - Deliver `pixel-sm-server/scripts/test-automated-suite.sh` as a single top-level orchestrator.
  - Run minimal required multi-mode coverage on `elite` and `joust` by default.
  - Validate admin behavior through both response assertions and payload assertions.
  - Produce deterministic, machine-readable suite artifacts (`.json`/`.ndjson`) plus a concise human-readable summary.
  - Keep manual-only gameplay telemetry explicitly outside automated pass criteria and route it to manual handoff.
- Non-goals:
  - Any mutable workflow under `ressources/`.
  - Any runtime/backend implementation work in `pixel-control-server/`.
  - Faking real-client combat telemetry as if it were equivalent to live gameplay evidence.
- Constraints / assumptions:
  - Plugin-first execution model remains authoritative.
  - Backend remains deferred; orchestrator must work without local backend service availability.
  - Reuse existing scripts first; only add minimal glue/flags needed for orchestration.
  - Manual-only combat events remain out-of-scope for automated pass/fail.

## Recon snapshot (locked)

- Script inventory checked in `pixel-sm-server/scripts/`:
  - mode/profile control: `dev-mode-compose.sh`
  - smoke: `qa-launch-smoke.sh`, `qa-mode-smoke.sh`
  - deterministic telemetry: `qa-wave3-telemetry-replay.sh`, `qa-wave4-telemetry-replay.sh`
  - admin simulation: `qa-admin-payload-sim.sh`
  - payload capture helper: `manual-wave5-ack-stub.sh`
- Existing plan/docs checked for boundaries and conventions:
  - `PLAN-real-client-live-combat-stats-qa.md`
  - `PLAN-autonomous-execution-wave-5.md`
  - `pixel-sm-server/README.md`
  - `pixel-control-plugin/docs/manual-feature-test-todo.md` (manual closure reference)

## Inventory - automatable vs manual-only

### Automatable now (required in orchestrator)

- Stack launch/readiness smoke via `qa-launch-smoke.sh`.
- Deterministic callback/payload baseline via `qa-wave4-telemetry-replay.sh` with `plugin_only` profile (fixture-off identity-safe baseline).
- Deterministic strict marker closure in at least one mode (`elite`) via:
  - `qa-wave3-telemetry-replay.sh`
  - `qa-wave4-telemetry-replay.sh` (`strict` profile).
- Admin capability and action response checks via `qa-admin-payload-sim.sh` (`list-actions`, targeted `execute` actions, optional `matrix`).
- Machine-readable suite status generation and non-zero exit contract.

### Partially automatable (fallback-aware assertions)

- Some admin actions are mode/capability dependent and can validly return deterministic fallback codes (`capability_unavailable`, `unsupported_mode`, `native_rejected`, `native_exception`, `missing_parameters`, `invalid_parameters`, `target_not_found`, `actor_not_found`).
- Placeholder-driven matrix actions (`map_uid`, `target_login`, `mx_id`) may return parameter/target fallback codes and must not be treated as suite regressions when explicitly whitelisted.
- Strict marker closure can be deterministic with fixtures; this remains complementary to (not a replacement for) real-client confidence.

### Manual-only (explicitly out of automated pass criteria)

- Real gameplay combat events requiring live player actions and non-zero realistic counters:
  - `OnShoot`
  - `OnHit`
  - `OnNearMiss`
  - `OnArmorEmpty`
  - `OnCapture`
- Real-session validation of combat correctness (rocket/laser hit truth under live gameplay).
- Real veto/draft actor behavior in realistic player sessions.

## Orchestrator architecture

- Primary entrypoint: `pixel-sm-server/scripts/test-automated-suite.sh`.
- Design: orchestrate existing helper scripts, add deterministic assertions, centralize artifacts, and expose one stable CLI for executor/user runs.

### Runtime flow (deterministic)

- Preflight:
  - verify required commands (`docker`, `bash`, `python3`, `php`, `curl`), `.env`, and required scripts.
  - compute `run_id` + run directory: `pixel-sm-server/logs/qa/automated-suite-<timestamp>/`.
  - emit `run-manifest.json` (requested modes, toggles, command versions, timestamps).
- Mode loop (default `elite,joust`):
  - apply mode profile through `bash scripts/dev-mode-compose.sh <mode> relaunch`.
  - run `qa-launch-smoke.sh` with run-scoped artifact directory.
  - run `qa-wave4-telemetry-replay.sh` in `plugin_only` profile and assert marker JSON (`profile_passed=true`, `plugin_only_checks.identity_fields_valid=true`).
  - run admin checks (response + payload) for the mode.
- Strict deterministic replay gate (at least once in `elite`):
  - run `qa-wave3-telemetry-replay.sh` (strict marker closure path).
  - run `qa-wave4-telemetry-replay.sh` with strict profile.
- Finalization:
  - write machine-readable rollups (`check-results.ndjson`, `suite-summary.json`, `coverage-inventory.json`).
  - write `suite-summary.md` and `manual-handoff.md`.
  - exit `0` only if all required automatable checks pass.

### Admin assertion model (response + payload)

- Response assertions:
  - `list-actions.json` must contain `error=false`, `data.enabled=true`, communication method names, and required action keys.
  - `execute-*.json` must include normalized response shape (`error`, `data.action_name`, `data.success`, `data.code`, `data.message`).
  - each action validates against an allowed-code set (mode-aware whitelist).
- Payload assertions:
  - orchestrator captures plugin payloads during admin simulation using existing helper flow:
    - start `manual-wave5-ack-stub.sh` with run-local output file,
    - run `dev-plugin-sync.sh` with `PIXEL_CONTROL_API_BASE_URL=http://host.docker.internal:<stub_port>`,
    - run `qa-admin-payload-sim.sh` actions,
    - stop stub and validate captured NDJSON.
  - required payload fields on observed admin-correlated envelopes:
    - `payload.admin_action.action_name`
    - `payload.admin_action.action_domain`
    - `payload.admin_action.action_type`
    - `payload.admin_action.action_phase`
    - `payload.admin_action.target_scope`
    - `payload.admin_action.initiator_kind`
  - required envelope identity fields:
    - `event_name`
    - `event_id`
    - `idempotency_key`
    - `source_sequence`

### Minimal script extension required for clean orchestration

- Extend `qa-admin-payload-sim.sh` to accept configurable output root (`PIXEL_SM_ADMIN_SIM_OUTPUT_ROOT`) so orchestrator can place artifacts under the suite run directory without post-hoc guessing.
- Keep default behavior unchanged when override is not provided.

### Artifact contract (machine-readable first)

- Root run directory: `pixel-sm-server/logs/qa/automated-suite-<timestamp>/`.
- Required files:
  - `run-manifest.json`
  - `coverage-inventory.json`
  - `check-results.ndjson`
  - `suite-summary.json`
  - `suite-summary.md`
  - `manual-handoff.md`
- Recommended per-mode subtree:
  - `modes/<mode>/launch-smoke/`
  - `modes/<mode>/wave4-plugin-only/`
  - `modes/<mode>/admin-sim/`
  - `modes/<mode>/admin-capture.ndjson`
- Strict replay subtree:
  - `strict/elite/wave3/`
  - `strict/elite/wave4/`

## Steps

Execution rule: planner completed recon and contract freeze; executor starts implementation at Phase 1 and keeps one active `[In progress]` step at a time.

### Phase 0 - Recon and contract freeze

- [Done] P0.1 Inventory reusable QA scripts and their artifact semantics.
  - Lock exact script reuse list and required env overrides.
- [Done] P0.2 Inventory existing plans/docs for automated vs manual boundaries.
  - Lock canonical manual references to avoid scope drift.
- [Done] P0.3 Freeze orchestrator acceptance contract.
  - Lock required modes (`elite`, `joust`), required checks, and fallback policy.

### Phase 1 - Build orchestrator foundation

- [Done] P1.1 Create `pixel-sm-server/scripts/test-automated-suite.sh` skeleton.
  - Add strict Bash settings, argument parsing, usage/help, and default mode list.
- [Done] P1.2 Implement run context + artifact namespace creation.
  - Generate run id, root directories, manifest metadata, and check ledger bootstrap.
- [Done] P1.3 Implement shared runner/cleanup primitives.
  - Add command wrapper with check-id logging, timing, exit capture, and trap-based cleanup.
- [Done] P1.4 Implement suite exit semantics.
  - Required check failure -> suite failure; optional checks logged but non-blocking.

### Phase 2 - Wire reusable automation flows (elite + joust minimum)

- [Done] P2.1 Implement per-mode profile apply via `dev-mode-compose.sh`.
  - Support `--modes` override while defaulting to `elite,joust`.
- [Done] P2.2 Add per-mode launch smoke checks.
  - Run `qa-launch-smoke.sh` with run-local artifact root and capture output path references.
- [Done] P2.3 Add per-mode wave-4 plugin-only checks.
  - Run `qa-wave4-telemetry-replay.sh` with fixture-off/plugin-only baseline and assert marker JSON.
- [Done] P2.4 Add elite strict replay gate.
  - Run `qa-wave3-telemetry-replay.sh` and strict `qa-wave4-telemetry-replay.sh` in elite profile.
- [Done] P2.5 Add optional deep smoke toggle.
  - Add opt-in flag to run `qa-mode-smoke.sh` (non-default) for broader drift detection.

### Phase 3 - Admin response and payload assertions

- [Done] P3.1 Extend `qa-admin-payload-sim.sh` with output-root override.
  - Add `PIXEL_SM_ADMIN_SIM_OUTPUT_ROOT` support with backward-compatible default.
- [Done] P3.2 Implement per-mode admin response assertions.
  - Run `list-actions` + selected `execute` checks; validate required shape/keys and allowed codes.
- [Done] P3.3 Implement per-mode admin payload capture and assertions.
  - Reuse `manual-wave5-ack-stub.sh` + `dev-plugin-sync.sh` + `qa-admin-payload-sim.sh` to capture and assert admin payload fields.
- [Done] P3.4 Correlate response and payload evidence.
  - For each action with `data.success=true`, require at least one matching payload admin action marker within the mode run window.

### Phase 4 - Machine-readable reporting and manual handoff output

- [Done] P4.1 Emit coverage inventory report.
  - Write `coverage-inventory.json` containing `automatable`, `partial`, and `manual_only` lists.
- [Done] P4.2 Emit deterministic suite rollups.
  - Write `check-results.ndjson` and `suite-summary.json` with per-check status and artifact pointers.
- [Done] P4.3 Emit human-readable outputs.
  - Write `suite-summary.md` and `manual-handoff.md` from machine-readable sources.
- [Done] P4.4 Ensure diagnostics are triage-ready.
  - Include failure reason, failing check id, and primary artifact path in summary outputs.

### Phase 5 - Documentation sync

- [Done] P5.1 Update `pixel-sm-server/README.md` with orchestrator usage.
  - Add default command, mode override example, and artifact layout.
- [Done] P5.2 Update manual-boundary references in plugin docs if needed.
  - Ensure docs state that live combat telemetry validation remains manual-only.
- [Done] P5.3 Keep contract docs unchanged unless assertion schema surface changed.
  - If assertion-required fields change, update `API_CONTRACT.md` and `pixel-control-plugin/docs/event-contract.md`; otherwise document "no contract change" in outcomes.

### Phase 6 - Verification and executor handoff

- [Done] P6.1 Run full default suite and capture green run evidence.
  - Confirm non-zero/zero behavior and generated artifact set.
- [Done] P6.2 Run targeted rerun (`--modes elite`) and confirm deterministic subset behavior.
  - Validate mode filtering and summary consistency.
- [Done] P6.3 Publish final execution note.
  - Explicitly state manual-only remaining scope and reference manual matrix paths.

## Evidence / Artifacts

- Planned implementation targets:
  - `pixel-sm-server/scripts/test-automated-suite.sh`
  - `pixel-sm-server/scripts/qa-admin-payload-sim.sh` (output-root override)
  - `pixel-sm-server/README.md`
  - `pixel-control-plugin/docs/manual-feature-test-todo.md` (only if wording sync is needed)
  - `API_CONTRACT.md` and `pixel-control-plugin/docs/event-contract.md` (conditional)
- Planned generated outputs per run:
  - `pixel-sm-server/logs/qa/automated-suite-<timestamp>/run-manifest.json`
  - `pixel-sm-server/logs/qa/automated-suite-<timestamp>/coverage-inventory.json`
  - `pixel-sm-server/logs/qa/automated-suite-<timestamp>/check-results.ndjson`
  - `pixel-sm-server/logs/qa/automated-suite-<timestamp>/suite-summary.json`
  - `pixel-sm-server/logs/qa/automated-suite-<timestamp>/suite-summary.md`
  - `pixel-sm-server/logs/qa/automated-suite-<timestamp>/manual-handoff.md`

## Verification commands

- Full default orchestrator run:
  - `bash pixel-sm-server/scripts/test-automated-suite.sh`
- Explicit minimum mode set:
  - `bash pixel-sm-server/scripts/test-automated-suite.sh --modes elite,joust`
- Focused subset run:
  - `bash pixel-sm-server/scripts/test-automated-suite.sh --modes elite`
- Optional deep smoke enabled:
  - `bash pixel-sm-server/scripts/test-automated-suite.sh --modes elite,joust --with-mode-smoke`
- Quick machine-readable status check:
  - `python3 -c "import json,sys; d=json.load(open(sys.argv[1])); print(d.get('overall_status'));" pixel-sm-server/logs/qa/automated-suite-<timestamp>/suite-summary.json`

## Success criteria

- A single orchestrator command validates all currently automatable checks and exits non-zero when any required check fails.
- `elite` and `joust` are both covered by default with per-mode check outcomes in machine-readable outputs.
- Admin assertions include both response validation and payload validation with explicit required fields.
- Strict deterministic replay checks (wave-3 + wave-4 strict) are executed at least once in `elite`.
- Artifact outputs are deterministic, machine-readable, and sufficient for CI-style parsing or local triage.
- Manual-only gameplay telemetry remains explicitly out-of-scope for automated pass/fail and is routed to manual handoff.

## Manual-only handoff (explicit, outside automated pass criteria)

- The following remain manual-only and must be validated with real clients after automated suite pass:
  - `OnShoot`
  - `OnHit`
  - `OnNearMiss`
  - `OnArmorEmpty`
  - `OnCapture`
- Canonical manual references:
  - `PLAN-real-client-live-combat-stats-qa.md`
  - `pixel-sm-server/logs/manual/wave5-real-client-20260220/MANUAL-TEST-MATRIX.md`

## Notes / outcomes

- Implementation delivered:
  - `pixel-sm-server/scripts/test-automated-suite.sh` (full orchestrator + machine-readable assertions + summaries),
  - `pixel-sm-server/scripts/qa-admin-payload-sim.sh` (`PIXEL_SM_ADMIN_SIM_OUTPUT_ROOT` support, backward compatible),
  - `pixel-sm-server/README.md` (orchestrator usage and artifacts),
  - `pixel-control-plugin/docs/manual-feature-test-todo.md` (explicit manual-only auto boundary reminder).
- Verification evidence:
  - expected non-zero behavior observed on failing correlation run before correlation-rule fix (`pixel-sm-server/logs/qa/automated-suite-20260221-211533/`),
  - full default run green after fix (`pixel-sm-server/logs/qa/automated-suite-20260221-212244/`, `overall_status=passed`),
  - targeted subset run green (`pixel-sm-server/logs/qa/automated-suite-20260221-213008/`, `requested_modes_csv=elite`, `overall_status=passed`),
  - final default re-validation green (`pixel-sm-server/logs/qa/automated-suite-20260221-214203/`, `overall_status=passed`, `checks_total=31`, `passed=31`).
- Contract docs (`API_CONTRACT.md`, `pixel-control-plugin/docs/event-contract.md`) unchanged for this scope; no external API/schema surface change was introduced.
