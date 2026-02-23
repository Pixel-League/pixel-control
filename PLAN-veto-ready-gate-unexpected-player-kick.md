# PLAN - Prevent unintended player kicks after `//pcveto ready` (2026-02-22)

## Context

- Purpose: investigate and fix an incident where arming matchmaking with `//pcveto ready` is followed by an unexpected real-player disconnect/kick when a second account joins.
- Scope: plugin-first changes in `pixel-control-plugin/` (matchmaking lifecycle and player-kick policy), QA verification in `pixel-sm-server/`, and documentation/memory updates.
- Background / findings:
  - Runtime stack is already up in `pixel-sm-server`.
  - `pixel-sm-server/runtime/server/ManiaControl/ManiaControl.log` contains `[PixelControl][veto][ready_gate][armed]` near line 191.
  - `pixel-sm-server/runtime/server/Logs/ConsoleLog.1.txt` shows repeated connect/disconnect for `onepiece2000` around 19:55-19:57 without explicit reason text.
  - Current explicit kick path is in `pixel-control-plugin/src/Domain/VetoDraft/VetoDraftDomainTrait.php` via `executeMatchmakingLifecycleKickAllPlayersAction()`, called by `finalizeMatchmakingLifecycleAfterSelectedMapEnd()`.
- Goals:
  - Confirm whether lifecycle finalization can incorrectly target real players.
  - Prevent unintended human-player kicks while preserving intended matchmaking lifecycle transitions.
  - Keep communication/control behavior stable and additive.
- Non-goals:
  - No backend implementation in `pixel-control-server/`.
  - No edits under `ressources/`.
  - No redesign of the full veto system beyond this incident scope.
- Constraints / assumptions:
  - Prefer safe default policy: do not kick when player identity/type cannot be confidently classified as non-human test actor.
  - Preserve existing lifecycle closure flow (selected-map end handling, session state transitions, and queue/apply behavior).
  - Follow existing QA script workflow and capture evidence paths.
- Risks / open questions:
  - Player-type detection may be ambiguous in some runtime paths; fallback must remain non-destructive.
  - Lifecycle cleanup could be used by test harness behavior; fix must avoid regressing deterministic QA runs.

## Steps

- [Done] Phase 0 - Incident analysis and root cause freeze
- [Done] Phase 1 - Implement safe matchmaking cleanup policy
- [Done] Phase 2 - Validation and regression checks
- [Done] Phase 3 - Documentation and incident memory updates
- [Done] Phase 4 - Completion checkpoint rerun and evidence consolidation

### Phase 0 - Incident analysis and root cause freeze

Acceptance criteria: incident timeline and likely root cause are documented before edits.

- [Done] P0.1 - Build a precise event timeline around the failure window.
  - Correlate ready-gate arm marker, join/disconnect cycles, and matchmaking lifecycle markers from `ManiaControl.log` and `ConsoleLog.1.txt`.
- [Done] P0.2 - Trace kick call chain and invocation conditions.
  - Confirm how `finalizeMatchmakingLifecycleAfterSelectedMapEnd()` reaches `executeMatchmakingLifecycleKickAllPlayersAction()` in the reported sequence.
  - Record which player set is iterated and which filters are currently applied.
- [Done] P0.3 - Freeze likely root cause and remediation direction.
  - Likely root cause: lifecycle finalization uses broad player cleanup (`kick(...)`) without a strict human-player exclusion policy, so real joiners can be caught during selected-map-end cleanup windows.
  - Freeze fix direction: enforce explicit non-human-only cleanup criteria and make unsafe/ambiguous cases skip-kick.

### Phase 1 - Implement safe matchmaking cleanup policy

Acceptance criteria: real players are never kicked by matchmaking lifecycle cleanup, while lifecycle completion still succeeds.

- [Done] P1.1 - Introduce a deterministic cleanup eligibility policy.
  - Add helper logic to classify whether a player can be lifecycle-cleaned (for example fake/test actors only).
  - Define conservative fallback behavior: unknown classification => do not kick.
- [Done] P1.2 - Refactor `executeMatchmakingLifecycleKickAllPlayersAction()` to use the eligibility policy.
  - Replace broad non-fake iteration with explicit allowlist-based cleanup checks.
  - Emit structured observability markers for `kick_applied` vs `kick_skipped` reasons.
- [Done] P1.3 - Guard finalization path to preserve flow without human kick side effects.
  - Ensure `finalizeMatchmakingLifecycleAfterSelectedMapEnd()` still closes lifecycle/session state and map-order flow when no players are eligible for cleanup.
  - Keep behavior additive and avoid changes to unrelated tournament paths.
- [Done] P1.4 - Add/adjust targeted unit-style runtime guards where practical.
  - Cover edge cases: second human join after ready, no fake players present, mixed fake+human roster.

### Phase 2 - Validation and regression checks

Acceptance criteria: incident is resolved and required matrices stay green.

- [Done] P2.1 - Run static syntax checks for all touched plugin PHP files.
  - `php -l <each touched plugin file>`
- [Done] P2.2 - Sync runtime plugin.
  - `bash pixel-sm-server/scripts/dev-plugin-hot-sync.sh`
- [Done] P2.3 - Run veto matrix verification.
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh matrix`
- [Done] P2.4 - Run admin matrix non-regression.
  - `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh matrix`
- [Done] P2.5 - Execute targeted incident replay.
  - Arm with `//pcveto ready`, join with second real account, and verify no unexpected kick/disconnect loop.
  - Capture relevant markers proving lifecycle completion and no human kick action.
  - Environment limitation: second real-account replay is not fully automatable here; closest deterministic replay/evidence captured in `pixel-sm-server/logs/qa/veto-payload-sim-20260222-210658/` and `pixel-sm-server/logs/qa/veto-payload-sim-20260222-211206/`.

### Phase 3 - Documentation and incident memory updates

Acceptance criteria: durable records describe symptom, cause, fix, and validation evidence.

- [Done] P3.1 - Update plugin docs for matchmaking cleanup behavior.
  - Document the new non-human-only cleanup policy and safety fallback in `pixel-control-plugin/FEATURES.md` (and contract docs only if surface/fields changed).
- [Done] P3.2 - Append local incident memory entry in `AGENTS.md`.
  - Record symptom, root cause, applied fix, and validation signal for this kick regression.
- [Done] P3.3 - Index evidence artifacts.
  - Reference command outputs/log evidence paths under `pixel-sm-server/logs/qa/` (or incident-specific artifact directory) for handoff traceability.

### Phase 4 - Completion checkpoint rerun and evidence consolidation

Acceptance criteria: mandatory completion criteria are revalidated in one pass and captured for final handoff.

- [Done] P4.1 - Re-run mandatory validation commands for this incident scope.
  - `php -l` on touched plugin PHP files.
  - `bash pixel-sm-server/scripts/dev-plugin-hot-sync.sh`
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh matrix`
  - `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh matrix`
- [Done] P4.2 - Re-check automated suite evidence validity.
  - Keep latest passing run if valid; rerun only if invalidated by new plugin/runtime edits.
- [Done] P4.3 - Reconfirm local memory entry and deterministic replay limitation statement.
  - Ensure `AGENTS.md` incident entry remains accurate and includes strongest deterministic proxy path when second-real-account replay is non-automatable.
- [Done] P4.4 - Prepare diff-level outcome mapping for handoff.
  - Enumerate changed methods and behavior-level impact in the final report.

Completion checkpoint evidence:

- `php -l` pass set captured from rerun on touched plugin files (`VetoDraftDomainTrait`, `CoreDomainTrait`, `MatchDomainTrait`, `PixelControlPlugin`, `MatchmakingVoteSession`, `VetoDraftCatalog`, `VetoDraftCoordinator`, `VetoDraftQueueApplier`).
- Hot-sync rerun artifacts: `pixel-sm-server/logs/dev/dev-plugin-hot-sync-shootmania-20260222-213125.log`, `pixel-sm-server/logs/dev/dev-plugin-hot-sync-maniacontrol-20260222-213125.log`.
- Veto matrix rerun: `pixel-sm-server/logs/qa/veto-payload-sim-20260222-213212/matrix-validation.json` (`overall_passed=true`).
- Admin matrix rerun: `pixel-sm-server/logs/qa/admin-payload-sim-20260222-213253/summary.md`.
- Automated suite validity retained (no plugin-source edits after run): `pixel-sm-server/logs/qa/automated-suite-20260222-212207/suite-summary.json` (`checks_total=39`, `passed=39`, `required_failures=0`).

## Validation strategy

- Required commands:
  - `php -l <each touched plugin file>`
  - `bash pixel-sm-server/scripts/dev-plugin-hot-sync.sh`
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh matrix`
  - `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh matrix`
- Required behavioral check:
  - Reproduce the reported ready + second-account join flow and confirm no unexpected kick while matchmaking lifecycle remains functional.

## Success criteria

- Incident root cause is confirmed with evidence.
- Matchmaking lifecycle cleanup does not kick normal human players.
- Matchmaking lifecycle still completes normally after selected-map end operations.
- Required QA commands pass and evidence is captured.
- Local `AGENTS.md` includes a new incident memory entry for this regression.
