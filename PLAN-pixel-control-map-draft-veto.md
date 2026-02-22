# PLAN - Implement map draft and veto feature in Pixel Control plugin (2026-02-21)

## Context

- Purpose: Define an execution-ready plan to implement a clean, modular map draft/veto feature in `pixel-control-plugin/` with two modes: matchmaking vote and competitive tournament draft/veto.
- Scope: Implementation will target plugin code under `pixel-control-plugin/src/`, with a dedicated feature subtree for the new logic, plus minimal integration wiring in existing plugin entry points and required docs/contract updates.
- Background / Findings:
  - `pixel-control-plugin` already exposes map-pool and partial veto telemetry (`veto_draft_actions`, `veto_result`) in `MatchDomainTrait`, but it currently infers data from callbacks and has no user-driven veto engine.
  - Plugin architecture is trait-driven (`Domain/*`) with dedicated folders for cross-cutting concerns (`Admin`, `Api`, `Queue`, `Retry`, `Stats`), so a new feature should follow this segmented style.
  - Existing reference sample `ressources/ManiaControl-Plugin-Examples/VetoPlugin/` demonstrates useful concepts (sequence-based veto, vote tie-break randomization) but is monolithic; new implementation should avoid that structure.
  - ManiaControl native map-control primitives exist (`MapActions`, `MapQueue`) and should be reused for applying final map order instead of custom server-control reimplementation.
- Goals:
  - Deliver two production-ready flows:
    - Matchmaking mode: all connected players vote on maps, highest votes wins, ties resolved by random draw among tied maps.
    - Tournament mode: team-based ban/pick flow with deterministic order and decider map resolution for BO formats.
  - Keep codebase maintainable: small, single-responsibility classes/files (no giant feature file).
  - Keep telemetry/contract explicit and additive where possible.
- Non-goals:
  - Backend runtime implementation in `pixel-control-server/`.
  - Editing or running mutable workflows inside `ressources/`.
  - Building a generic tournament platform outside plugin scope.
- Constraints / assumptions:
  - New feature code lives in a dedicated subfolder under `pixel-control-plugin/src/`.
  - Multi-mode compatibility remains required (no Elite-only assumptions).
  - `ressources/` remains read-only reference.
  - Existing plugin architecture conventions (traits, setting/env precedence, deterministic logs) must be preserved.
  - Default tournament fairness model will use a balanced alternating sequence (ABBA/snake-style turns) with explicit starter selection and automatic decider from remaining pool.

## Steps

Execution rule: keep one active `[In progress]` step during implementation. This plan is authoring-only; execution is for Executor.

- [Done] Phase 0 - Product rules and policy closure
- [Done] Phase 1 - Feature architecture and scaffolding
- [Done] Phase 2 - Matchmaking vote mode implementation
- [Done] Phase 3 - Tournament draft/veto engine implementation
- [Done] Phase 4 - UI, commands, and communication integration
- [Done] Phase 5 - Telemetry and contract synchronization
- [Done] Phase 6 - QA matrix and evidence capture
- [Done] Phase 7 - Rollout and acceptance closure
- [Done] Phase 8 - Runtime command activation and chat control fixes
- [Done] Phase 9 - Server-orchestrated veto simulation script
- [Done] Phase 10 - End-to-end QA hardening for chat + payload flows
- [Done] Phase 11 - Automated suite integration for veto feature
- [Done] Phase 12 - Full automated-suite execution and hardening

### Phase 0 - Product rules and policy closure

Dependencies: None.
Completion signal: A frozen ruleset document section (in-code constants/docs) exists for both modes, including BO behavior and turn-order policy.

- [Done] P0.1 - Freeze mode definitions and runtime configuration surface.
  - Define canonical mode ids (`matchmaking_vote`, `tournament_draft`) and defaults.
  - Define BO format handling (`bo1`, `bo3`, `bo5`, extensible odd BO).
  - Define timer defaults, quorum/min-player constraints, and cancel/timeout behavior.
- [Done] P0.2 - Freeze tournament fairness algorithm.
  - Adopt deterministic ban/pick policy with configurable starter (`team_a`, `team_b`, `random`).
  - Use balanced alternating order (ABBA/snake-style) for bans/picks to reduce first-move advantage.
  - Define decider rule: last remaining eligible map auto-locked as final map in series order.
- [Done] P0.3 - Freeze role/permission model.
  - Matchmaking: all connected eligible players can vote once (vote replacement allowed before lock).
  - Tournament: only designated captains (or authorized admin override) can ban/pick.
  - Define admin command permissions for start/cancel/force/override paths.
- [Done] P0.4 - Freeze failure and edge-case policy.
  - Tie handling in matchmaking (random among top-voted maps).
  - Insufficient map pool vs BO size, disconnected captains, timeout auto-actions, duplicate/invalid selections.

### Phase 1 - Feature architecture and scaffolding

Dependencies: Phase 0.
Completion signal: Dedicated feature subtree and wiring skeleton compile/lint clean, with no oversized monolithic class.

- [Done] P1.1 - Create dedicated feature subtree under `pixel-control-plugin/src/`.
  - Add `pixel-control-plugin/src/VetoDraft/` as the primary feature folder.
  - Segment by responsibility (for example: `Config/`, `Model/`, `Engine/`, `Service/`, `Ui/`, `Integration/`, `Telemetry/`).
- [Done] P1.2 - Add thin plugin-domain bridge without bloating existing traits.
  - Add `pixel-control-plugin/src/Domain/VetoDraft/VetoDraftDomainTrait.php` for lifecycle integration, state reset hooks, and callback/command entry-point wiring.
  - Keep heavy business logic inside `src/VetoDraft/*` services/engines.
- [Done] P1.3 - Add configuration and state contracts.
  - Introduce typed-like PHP array contracts/DTO-style models for session state, team/captain assignments, votes, actions, and final map order.
  - Add setting/env constants in plugin entry only for feature toggles and defaults.
- [Done] P1.4 - Enforce maintainability boundaries.
  - Define target max complexity per class (single responsibility, compact methods).
  - Add internal file/module map in docs for future extension.

### Phase 2 - Matchmaking vote mode implementation

Dependencies: Phase 1.
Completion signal: Matchmaking flow can start, collect player votes, resolve winner with deterministic tie policy, and apply selected map.

- [Done] P2.1 - Implement map candidate and ballot lifecycle.
  - Build candidate list from current server map pool (`MapManager` snapshot).
  - Allow one active vote per player with update/replace semantics before lock.
- [Done] P2.2 - Implement vote window orchestration.
  - Add start/countdown/lock/finalize states and timer-driven closure.
  - Handle late joiners/leavers and invalid votes safely.
- [Done] P2.3 - Implement result resolver.
  - Compute per-map totals, pick highest vote count.
  - Resolve ties via secure random draw among tied highest maps; log tie context for auditability.
- [Done] P2.4 - Apply winning map and publish state.
  - Route final map application through native map queue/actions.
  - Emit structured summary payload for UI + telemetry (`winner`, `vote_totals`, `tie_break_applied`).

### Phase 3 - Tournament draft/veto engine implementation

Dependencies: Phase 1.
Completion signal: Team captains can run full ban/pick sequence for BO formats with deterministic turn order and final ordered map list including decider.

- [Done] P3.1 - Implement team and captain session context.
  - Track `team_a` and `team_b` identities and captain logins.
  - Add validation for captain eligibility and reconnect continuity.
- [Done] P3.2 - Implement sequence/turn-order generator.
  - Generate action timeline from map-pool size + BO target + starter policy.
  - Support explicit action kinds (`ban`, `pick`, `lock` decider) and enforce legal next actions.
- [Done] P3.3 - Implement tournament state machine.
  - States: setup -> active_turns -> decider_lock -> completed/cancelled.
  - Guard rails for invalid actor, invalid map, already-removed map, out-of-turn actions, timeout fallback.
- [Done] P3.4 - Implement final series map order resolver.
  - Persist picks in play order (`map1..mapN`) and compute final decider map.
  - Publish deterministic result object for queue application and telemetry.
- [Done] P3.5 - Implement timeout and recovery policy.
  - Define auto-ban/auto-pick fallback strategy on captain timeout.
  - Keep deterministic replayable action history (`order_index`, actor, timestamp, source).

### Phase 4 - UI, commands, and communication integration

Dependencies: Phases 2-3.
Completion signal: Players/admins can operate both modes through in-game interfaces and programmatic methods.

- [Done] P4.1 - Build matchmaking vote UI (Manialink) and fallback commands.
  - Render map cards with names and vote affordances.
  - Show countdown, current tally, and final winner.
- [Done] P4.2 - Build tournament veto/draft board UI.
  - Render available/banned/picked/decider map states.
  - Show active turn owner, action type, and ordered picks timeline.
- [Done] P4.3 - Add command surface and permission checks.
  - Add start/cancel/status actions and per-mode interaction commands.
  - Bind to native plugin permission levels (no bypass path).
- [Done] P4.4 - Add communication-method integration for automation.
  - Add bounded methods for start/action/status/cancel payload workflows.
  - Keep payload validation strict with deterministic error responses.

### Phase 5 - Telemetry and contract synchronization

Dependencies: Phases 2-4.
Completion signal: Veto/draft telemetry is authoritative and documented, with additive compatibility posture.

- [Done] P5.1 - Replace inferred-only veto action stream with authoritative feature events.
  - Emit actual action history from new engine into lifecycle/map telemetry context.
  - Preserve backward-compatible fields where possible (`veto_draft_actions`, `veto_result`).
- [Done] P5.2 - Add mode-specific metadata fields.
  - Include `mode`, `bo_format`, `starter_policy`, `action_timeline`, and field-availability fallbacks.
- [Done] P5.3 - Update plugin docs and schema catalog artifacts.
  - Update `pixel-control-plugin/FEATURES.md`.
  - Update `pixel-control-plugin/docs/event-contract.md`.
  - Update schema/event catalog files only if envelope contract shape changes.
- [Done] P5.4 - Update `API_CONTRACT.md` if plugin->server wire semantics change.
  - If no route/shape change: document execution-path-only behavior update.

### Phase 6 - QA matrix and evidence capture

Dependencies: Phases 2-5.
Completion signal: Deterministic QA matrix passes for both modes and non-regression checks are captured with artifacts.

- [Done] P6.1 - Run static checks on all touched plugin files.
  - PHP lint on changed files in `pixel-control-plugin/src/`.
- [Done] P6.2 - Validate matchmaking scenarios.
  - Single winner vote, tie vote randomization, no-vote fallback, reconnect during vote window.
- [Done] P6.3 - Validate tournament scenarios.
  - BO1/BO3/BO5 action sequences, out-of-turn rejection, timeout fallback, decider correctness.
  - Verify fair alternating behavior for both possible starter teams.
- [Done] P6.4 - Validate map-application behavior.
  - Confirm final selected maps are applied in correct order through native queue/actions.
  - Confirm first-map edge cases (current map already selected) are handled predictably.
- [Done] P6.5 - Validate telemetry and regression safety.
  - Ensure lifecycle/player/combat/admin existing payloads remain stable.
  - Verify new veto fields are consistent and identity guards remain healthy.
- [Done] P6.6 - Capture QA evidence artifacts.
  - Store logs/replay outputs in a dedicated `pixel-sm-server/logs/qa/<run>/` directory.

### Phase 7 - Rollout and acceptance closure

Dependencies: Phase 6.
Completion signal: Feature can be toggled safely, rollback is documented, and acceptance criteria are met.

- [Done] P7.1 - Add rollout guardrails.
  - Feature toggle default-safe behavior (`disabled` until explicitly enabled).
  - Mode default and permission defaults aligned with least privilege.
- [Done] P7.2 - Define rollback path.
  - Disable feature toggle and revert to existing map rotation behavior without data corruption.
- [Done] P7.3 - Final acceptance checklist.
  - Two modes fully operational.
  - Segmented architecture delivered (no giant single-file implementation).
  - Documentation and evidence synchronized.

### Phase 8 - Runtime command activation and chat control fixes

Dependencies: Phase 7.
Completion signal: Veto command responds from chat using expected admin prefix and feature toggles are injectable through dev stack env.

- [Done] P8.1 - Fix command routing so `//pcveto ...` is handled.
  - Register veto command listeners for both user and admin command channels to support `/pcveto` and `//pcveto` entry paths.
  - Keep privileged operations rights-gated in handler logic.
- [Done] P8.2 - Ensure veto env settings are propagated into shootmania container.
  - Add veto env variables in `pixel-sm-server/docker-compose.yml` service environment.
  - Add matching defaults/documentation in `pixel-sm-server/.env.example`.
- [Done] P8.3 - Verify command handler behavior after routing fixes.
  - Re-run lint and targeted command parsing checks to confirm `maps/status/start` are reachable.

### Phase 9 - Server-orchestrated veto simulation script

Dependencies: Phase 8.
Completion signal: A dedicated QA script can exercise `PixelControl.VetoDraft.*` methods over ManiaControl communication socket and produce evidence artifacts.

- [Done] P9.1 - Create `pixel-sm-server/scripts/qa-veto-payload-sim.sh`.
  - Mirror operator UX and artifact model used by `qa-admin-payload-sim.sh`.
  - Support commands: status, start, action, cancel, matrix.
- [Done] P9.2 - Implement deterministic matrix scenarios for both modes.
  - Matchmaking flow: start -> vote actions -> status -> optional cancel.
  - Tournament flow: start -> captain actions -> status snapshots -> optional cancel.
- [Done] P9.3 - Persist evidence outputs and summaries.
  - Write JSON responses + markdown summary under `pixel-sm-server/logs/qa/veto-payload-sim-<timestamp>/`.

### Phase 10 - End-to-end QA hardening for chat + payload flows

Dependencies: Phase 9.
Completion signal: Chat and communication control surfaces are validated with updated evidence and no veto blockers remain.

- [Done] P10.1 - Run static checks on all modified files (plugin + scripts).
  - PHP lint for touched plugin files.
  - Bash syntax check for new/updated QA scripts.
- [Done] P10.2 - Execute veto payload simulation QA run.
  - Run new matrix script against active stack and capture artifacts.
  - Confirm communication responses include successful status transitions.
- [Done] P10.3 - Update QA evidence and docs with blocker/fix traceability.
  - Add a dedicated QA summary and acceptance notes for this follow-up wave.
  - Update local `AGENTS.md` with any incident memory and durable operational notes.

### Phase 11 - Automated suite integration for veto feature

Dependencies: Phase 10.
Completion signal: `test-automated-suite.sh` includes required veto checks and validates payload simulation artifacts per mode.

- [Done] P11.1 - Wire veto simulation script into automated suite prerequisites and orchestration.
  - Add `qa-veto-payload-sim.sh` as required script dependency.
  - Add per-mode automated suite execution hook for veto simulation.
- [Done] P11.2 - Add deterministic veto artifact validation in automated suite.
  - Validate communication payload shape and required success codes for matchmaking/tournament flow.
  - Validate tournament completion status for full-mode runs with bounded fallback behavior for sparse map pools.
- [Done] P11.3 - Update automated suite coverage inventory for veto capabilities.
  - Register veto checks under automatable coverage entries.
  - Keep manual-only boundaries explicit for real-client gameplay behavior.

### Phase 12 - Full automated-suite execution and hardening

Dependencies: Phase 11.
Completion signal: automated suite executes with veto checks included and all required checks pass (or blockers are fixed and rerun to green).

- [Done] P12.1 - Run static validation on modified automation scripts.
  - Bash syntax checks for `test-automated-suite.sh` and veto simulation dependencies.
- [Done] P12.2 - Execute `scripts/test-automated-suite.sh` with veto checks enabled.
  - Capture resulting run artifacts (`run-manifest`, `check-results`, `suite-summary`, veto matrix artifacts).
- [Done] P12.3 - Resolve failures (if any), rerun to green, and document outcomes.
  - Apply targeted fixes and re-run until required checks pass.
  - Update local memory and QA evidence references.

Phase-12 execution notes:

- Initial full-suite runs exposed two deterministic orchestration issues in `test-automated-suite.sh`: (1) intermittent compose recovery races causing transient container-id reuse/no-such-container churn, and (2) communication socket readiness races after veto profile relaunch (`127.0.0.1:31501` connection refused) before matrix calls.
- Hardening applied in orchestrator:
  - centralized retry path (`run_logged_command_with_mode_recovery`) now handles mode relaunch recovery consistently for launch/wave/admin/veto checks,
  - recovery now removes stale shootmania containers by discovered name pattern before relaunch,
  - recovery now waits for communication socket readiness before retrying communication-backed checks,
  - admin payload dev-sync step now uses the same retry-aware runner.
- Validation closure:
  - `bash -n pixel-sm-server/scripts/test-automated-suite.sh` passed after each script update,
  - hardening closure run passed end-to-end with `checks_total=39`, `passed=39`, `required_failures=0`,
  - fresh execution revalidation rerun also passed with `checks_total=39`, `passed=39`, `required_failures=0`.
- Canonical green evidence sets:
  - `pixel-sm-server/logs/qa/automated-suite-20260222-003125/suite-summary.json`
  - `pixel-sm-server/logs/qa/automated-suite-20260222-003125/suite-summary.md`
  - `pixel-sm-server/logs/qa/automated-suite-20260222-003125/check-results.ndjson`
  - `pixel-sm-server/logs/qa/automated-suite-20260222-094958/suite-summary.json`
  - `pixel-sm-server/logs/qa/automated-suite-20260222-094958/suite-summary.md`
  - `pixel-sm-server/logs/qa/automated-suite-20260222-094958/check-results.ndjson`

## Evidence / Artifacts

- Planned QA evidence directory: `pixel-sm-server/logs/qa/map-draft-veto-<timestamp>/`
- Follow-up payload simulation evidence directory: `pixel-sm-server/logs/qa/veto-payload-sim-<timestamp>/`
- Planned feature docs update targets:
  - `pixel-control-plugin/FEATURES.md`
  - `pixel-control-plugin/docs/event-contract.md`
  - `pixel-control-plugin/docs/schema/*` (if contract shape changes)
  - `API_CONTRACT.md` (if wire behavior changes)

## Success criteria

- Matchmaking mode supports all-player voting with deterministic winner resolution and random tie-break among top maps.
- Tournament mode supports captain-based ban/pick with fair turn policy, BO-aware map order, and automatic decider handling.
- Final chosen maps are applied through native ManiaControl map-management facilities.
- New code is concentrated in a dedicated `src` subfolder with clear module boundaries and maintainable file sizes.
- Existing plugin telemetry/transport behavior remains stable, and veto/draft telemetry becomes authoritative and documented.
