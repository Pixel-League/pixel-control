# PLAN - Automated test suite for automatable Pixel Control features (2026-02-21)

## Context

- Purpose: deliver one execution-ready automated test suite command for Pixel Control that runs every currently automatable check and fails fast/non-zero when required checks fail.
- Scope: this plan covers first-party automation in `pixel-sm-server/` and documentation updates in repo first-party docs; it does not include backend runtime implementation in `pixel-control-server/`.
- Background / Findings:
  - Existing automation helpers already cover key slices: `qa-launch-smoke.sh`, `qa-mode-smoke.sh`, `qa-wave3-telemetry-replay.sh`, `qa-wave4-telemetry-replay.sh`, and `qa-admin-payload-sim.sh`.
  - Mode profile switching is already standardized via `.env.<mode>` files and `pixel-sm-server/scripts/dev-mode-compose.sh`; confirmed profiles include `.env.elite` and `.env.joust`.
  - Contract and feature references are current in `API_CONTRACT.md`, `pixel-control-plugin/FEATURES.md`, `pixel-control-plugin/docs/event-contract.md`, and `pixel-control-plugin/docs/admin-capability-delegation.md`.
  - Real-client gameplay validation remains explicitly open and documented separately (`PLAN-real-client-live-combat-stats-qa.md`, wave-5 manual matrix).
- Goals:
  - Add a single top-level orchestrator script (`pixel-sm-server/scripts/test-automated-suite.sh`) that chains automatable coverage end-to-end.
  - Validate automated coverage in at least Elite + Joust profiles, with explicit mode-conditional assertions.
  - Validate admin-command behavior through response evidence plus payload-marker evidence.
  - Produce deterministic artifacts and machine-readable suite summary output.
- Non-goals:
  - Any `pixel-control-server/` runtime/API implementation work.
  - Any mutable workflow in `ressources/`.
  - Replacing manual real-client gameplay scenarios with fake automation.
- Constraints / assumptions:
  - Plugin-first and `pixel-sm-server`-first execution only.
  - `ressources/` remains reference-only.
  - Existing schema/version baseline stays `2026-02-20.1`; no contract-breaking changes.
  - Some gameplay telemetry (`shoot/hit/near-miss/armor-empty/capture`) is manual-only because non-zero realistic events require real clients.
  - The suite should reuse existing scripts and Bash/Python/PHP tooling already present, avoiding new frameworks.
- Dependencies / stakeholders:
  - Executor agent implements the plan.
  - User runs manual-only gameplay closure scenarios after automated suite completion.
- Risks / open questions (to resolve in implementation phase):
  - `qa-admin-payload-sim.sh` currently writes artifacts under `logs/qa/` only; orchestrator may need a small output-root override for run-local artifact organization.
  - Some admin actions are mode/capability dependent; assertions must accept deterministic fallback codes (not only `ok`).

## Feature inventory for this test suite

### Fully automatable now

- Stack boot/readiness smoke: `pixel-sm-server/scripts/qa-launch-smoke.sh`.
- Deterministic mode boot smoke coverage: `pixel-sm-server/scripts/qa-mode-smoke.sh` (already includes Elite/Siege/Battle/Joust/Custom; suite minimum target remains Elite + Joust).
- Deterministic telemetry replay markers:
  - wave-3 marker closure via `pixel-sm-server/scripts/qa-wave3-telemetry-replay.sh`.
  - wave-4 strict and/or plugin-only profile checks via `pixel-sm-server/scripts/qa-wave4-telemetry-replay.sh`.
- Envelope identity baseline checks from wave-4 plugin-only marker report (`identity_checks.invalid=0`, plugin-only checks pass).
- Admin capability discovery and command execution response checks through `pixel-sm-server/scripts/qa-admin-payload-sim.sh`.
- Machine-readable artifact generation and pass/fail summary generation.

### Partially automatable (with caveats)

- Admin command matrix actions that require runtime-specific parameters (`map_uid`, `target_login`, `mx_id`) can be automated only when resolvable values are available; otherwise deterministic fallback codes are expected.
- Mode-sensitive admin actions (`pause.*`, `player.force_team`, `vote.custom_start`) may legitimately return capability/availability fallback codes based on active mode/plugins.
- Wave-4 strict marker closure can be validated deterministically with fixture injection, but this is not equivalent to full real-client behavioral proof.
- Reconnect/side-change/team aggregate/veto behavior can be baseline-validated automatically, but real-client evidence is still needed for production-confidence closure.

### Manual-only (real-client required) and why

- Live combat callbacks with non-zero meaningful counters (`OnShoot`, `OnHit`, `OnNearMiss`, `OnArmorEmpty`, `OnCapture`) require real in-game player actions and cannot be fully reproduced by deterministic fake-player scripting.
- Real match-intent gameplay verification (rocket/laser shot/hit correctness, warmup exclusion under live play) requires remote real clients and operator attestation.
- Real veto/draft actor behavior and full map-selection flow in realistic sessions still require manual scenario execution in supported live mode contexts.

## Orchestrator design

- Script path: `pixel-sm-server/scripts/test-automated-suite.sh`.
- Design principle: one entrypoint script orchestrates existing QA helpers, adds deterministic assertions, writes machine-readable suite output, and exits non-zero if any required check fails.
- Implementation style: Bash main flow with small embedded Python assertions for JSON/NDJSON parsing (no new dependency framework).

### Proposed suite runtime flow

- Preflight:
  - verify required commands (`docker`, `bash`, `python3`, `php`, `curl`) and required files (`.env.elite`, `.env.joust`, helper scripts).
  - create run namespace `pixel-sm-server/logs/qa/automated-suite-<timestamp>/`.
- Profile loop (minimum: `elite`, `joust`):
  - apply mode profile via `bash scripts/dev-mode-compose.sh <mode> launch|relaunch`.
  - run launch smoke (`qa-launch-smoke.sh`) with run-scoped artifact env.
  - run wave-4 plugin-only replay (`PIXEL_SM_QA_TELEMETRY_INJECT_FIXTURES=0`, profile `plugin_only`) and assert marker report.
  - run admin capability + selected execute checks and validate response files.
  - run admin payload-marker assertions against captured NDJSON evidence.
- Deterministic strict replay pass (at least once in Elite):
  - run wave-3 strict marker replay.
  - run wave-4 strict marker replay.
- Finalization:
  - aggregate per-check results into `suite-summary.json` and `suite-summary.md`.
  - print final artifact path.
  - exit with `0` only when all required checks pass.

### Proposed CLI shape (keep minimal)

- `--modes elite,joust` (default `elite,joust`).
- `--artifact-root <path>` (default `pixel-sm-server/logs/qa`).
- `--skip-wave3` (optional fast mode).
- `--skip-wave4-strict` (optional fast mode).
- `--keep-stack-running` (optional; default cleanup).

### Admin-command assertion design (response + payload evidence)

- Capability discovery assertions (`list-actions.json`):
  - `error=false`.
  - `data.enabled=true`.
  - `data.communication.exec == PixelControl.Admin.ExecuteAction`.
  - `data.communication.list == PixelControl.Admin.ListActions`.
  - required action keys exist (minimum: `map.skip`, `map.restart`, `warmup.extend`, `warmup.end`, `pause.start`, `pause.end`, `vote.cancel`, `auth.grant`, `auth.revoke`).
- Execute-action response assertions (`execute-*.json`):
  - response shape includes `error`, `data.action_name`, `data.success`, `data.code`, `data.message`.
  - action outcome code is validated against per-action allowed set.
  - deterministic acceptable fallback codes are allowed for capability-dependent actions (for example `native_rejected`, `capability_unavailable`, `native_exception`) when explicitly whitelisted.
- Payload evidence assertions (NDJSON capture):
  - at least one emitted lifecycle envelope after admin commands includes `payload.admin_action`.
  - required admin fields present in observed payloads:
    - `action_name`, `action_domain`, `action_type`, `action_phase`, `target_scope`, `initiator_kind`.
  - envelope identity fields present on matching records:
    - `event_name`, `event_id`, `idempotency_key`, `source_sequence`.

### Mode-conditional expectation baseline

- Elite profile:
  - required: launch smoke pass, wave-4 plugin-only pass, admin capability discovery pass.
  - strict replay markers (wave-3 + wave-4 strict) executed in this profile.
- Joust profile:
  - required: launch smoke pass, wave-4 plugin-only pass, admin capability discovery pass.
  - admin action checks must accept mode-capability fallbacks where valid (for example pause/team controls if mode reports unavailable).

## Steps

Execution rule: keep statuses current during execution and maintain one active `[In progress]` step.

### Phase 0 - Lock automated coverage contract

- [Done] P0.1 Confirm inventory-to-check mapping for existing helpers.
  - Freeze which existing scripts are reused directly vs wrapped with additional assertions.
- [Done] P0.2 Freeze fully/partial/manual classification in docs for this suite.
  - Ensure manual-only boundaries are explicit and not treated as automated failures.
- [Done] P0.3 Define per-mode expected action outcomes and fallback code whitelist.
  - Create one deterministic expectation map used by orchestrator assertions.

### Phase 1 - Implement top-level orchestrator

- [In progress] P1.1 Create `pixel-sm-server/scripts/test-automated-suite.sh`.
  - Add preflight checks, argument parsing, run-id/artifact namespace setup, and consistent log prefixes.
- [Todo] P1.2 Implement robust run helper primitives.
  - Add reusable command runner wrappers, step status capture, and trap-based cleanup.
- [Todo] P1.3 Implement JSON/NDJSON assertion helpers (embedded Python).
  - Validate marker files, admin response files, and payload evidence fields.
- [Todo] P1.4 Guarantee suite-level exit semantics.
  - Exit non-zero if any required check fails; support optional/soft checks without masking required failures.

### Phase 2 - Wire concrete checks (Elite + Joust minimum)

- [Todo] P2.1 Add mode loop orchestration using `.env.<mode>` profiles.
  - Use `scripts/dev-mode-compose.sh` to apply each profile before checks.
- [Todo] P2.2 Run per-mode launch smoke with run-scoped artifacts.
  - Call `qa-launch-smoke.sh` and capture output references in suite summary.
- [Todo] P2.3 Run per-mode wave-4 plugin-only replay and assert marker pass.
  - Enforce plugin-only checks and identity baseline (`identity_fields_valid=true`).
- [Todo] P2.4 Add per-mode admin capability/execute assertions.
  - Run `qa-admin-payload-sim.sh list-actions` and selected execute cases.
  - Assert required fields/codes and mode-conditional fallback behavior.
- [Todo] P2.5 Add strict deterministic replay pass in Elite.
  - Run `qa-wave3-telemetry-replay.sh` and `qa-wave4-telemetry-replay.sh` with strict profile.
  - Validate marker files for strict closure.

### Phase 3 - Evidence and machine-readable reporting

- [Todo] P3.1 Standardize suite artifact layout.
  - Ensure all generated evidence is indexed under `automated-suite-<timestamp>/` (directly or via pointer index to child script outputs).
- [Todo] P3.2 Write machine-readable summary outputs.
  - Required: `suite-summary.json` with per-mode/per-check status, artifact paths, and overall result.
  - Optional helper: `suite-summary.md` human-readable digest generated from same data.
- [Todo] P3.3 Add deterministic failure diagnostics.
  - Persist failure reason, failing check id, and primary artifact path for quick triage.

### Phase 4 - Documentation and contract synchronization

- [Todo] P4.1 Update `pixel-sm-server/README.md` with automated-suite usage.
  - Document command examples, default mode set, and artifact outputs.
- [Todo] P4.2 Update plugin-facing testing docs where needed.
  - Reflect automated vs manual boundaries in `pixel-control-plugin/FEATURES.md` and/or `pixel-control-plugin/docs/manual-feature-test-todo.md` (without changing schema claims).
- [Todo] P4.3 Reconcile contract docs only if assertion surfaces changed.
  - If required marker fields or semantics were adjusted during implementation, update `API_CONTRACT.md` and `pixel-control-plugin/docs/event-contract.md`; otherwise explicitly leave contract docs unchanged.

### Phase 5 - Verification and handoff

- [Todo] P5.1 Execute default automated suite and confirm green status.
  - Run default command and confirm zero exit + completed summary artifacts.
- [Todo] P5.2 Execute one targeted rerun for mode filtering.
  - Verify CLI mode selection works (for example `--modes elite`), still preserving summary validity.
- [Todo] P5.3 Publish final verification note with explicit manual-only handoff.
  - State that live gameplay telemetry scenarios remain pending by design and point to canonical manual matrix paths.

## Evidence / Artifacts

- Planned implementation targets:
  - `pixel-sm-server/scripts/test-automated-suite.sh`
  - `pixel-sm-server/scripts/qa-admin-payload-sim.sh` (only if output-root integration is added)
  - `pixel-sm-server/README.md`
  - `pixel-control-plugin/FEATURES.md` (if scope boundary note update is needed)
  - `pixel-control-plugin/docs/manual-feature-test-todo.md` (if scope boundary note update is needed)
  - `API_CONTRACT.md` and `pixel-control-plugin/docs/event-contract.md` (conditional only)
- Planned suite outputs:
  - `pixel-sm-server/logs/qa/automated-suite-<timestamp>/suite-summary.json`
  - `pixel-sm-server/logs/qa/automated-suite-<timestamp>/suite-summary.md`
  - `pixel-sm-server/logs/qa/automated-suite-<timestamp>/` (per-mode check evidence index and pointers)
  - existing child-script evidence under `pixel-sm-server/logs/qa/` (wave and admin simulation artifacts)

## Verification commands

- Default full automatable suite:
  - `bash pixel-sm-server/scripts/test-automated-suite.sh`
- Minimum required profile coverage explicitly:
  - `bash pixel-sm-server/scripts/test-automated-suite.sh --modes elite,joust`
- Focused profile rerun:
  - `bash pixel-sm-server/scripts/test-automated-suite.sh --modes elite`
- Verify machine-readable result quickly:
  - `python3 -c "import json,sys; d=json.load(open(sys.argv[1])); print(d.get('overall_status'));" pixel-sm-server/logs/qa/automated-suite-<timestamp>/suite-summary.json`

## Success criteria

- One top-level script (`pixel-sm-server/scripts/test-automated-suite.sh`) runs the complete automatable suite and exits non-zero on required failures.
- Elite + Joust are both executed by default and produce per-mode pass/fail records.
- Admin-command behavior is validated through both command response evidence and payload evidence with required marker fields.
- Machine-readable suite summary is generated with deterministic artifact paths and final overall status.
- Manual-only gameplay telemetry checks are explicitly excluded from automated pass criteria and routed to manual handoff documentation.

## Manual-only handoff (explicit)

- After automated suite pass, execute manual real-client gameplay scenarios for non-automatable telemetry (shoot/hit/near-miss/armor-empty/capture, live combat counter correctness, real veto actor flows) using:
  - `pixel-sm-server/logs/manual/wave5-real-client-20260220/MANUAL-TEST-MATRIX.md`
  - `PLAN-real-client-live-combat-stats-qa.md`

## Notes / outcomes

- 2026-02-21 execution lock (phase 0): orchestrator reuses `dev-mode-compose.sh` (profile apply), `qa-launch-smoke.sh`, `qa-wave3-telemetry-replay.sh`, `qa-wave4-telemetry-replay.sh`, and `qa-admin-payload-sim.sh`; `qa-mode-smoke.sh` remains optional/non-required because suite default target is Elite + Joust only.
- 2026-02-21 execution lock (phase 0): automated required checks cover launch smoke, wave-4 plugin-only baseline, admin list/execute response assertions, admin payload evidence assertions, and elite strict replays (wave-3 + wave-4 strict).
- 2026-02-21 execution lock (phase 0): manual-only gameplay telemetry (`shoot/hit/near-miss/armor-empty/capture`, live veto actor realism, real-client combat correctness) stays outside automated pass/fail and is routed to canonical manual wave-5 matrix handoff.
- 2026-02-21 execution lock (phase 0): fallback-aware admin assertion baseline for matrix responses:
  - always-allow on required actions when applicable: `ok`
  - capability/mode fallbacks: `capability_unavailable`, `unsupported_mode`
  - native execution fallbacks: `native_rejected`, `native_exception`
  - parameter/target fallbacks for placeholder-driven deterministic runs: `missing_parameters`, `invalid_parameters`, `target_not_found`, `actor_not_found`
