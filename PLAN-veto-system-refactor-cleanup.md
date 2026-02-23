# PLAN - Refactor and clean up veto system code (2026-02-22)

## Context

- Purpose: perform a full veto-system code health pass, refactor duplicated logic, and remove code paths that are no longer useful without changing expected operator behavior.
- Scope: veto plugin domain and adjacent veto runtime classes in `pixel-control-plugin/src/Domain/VetoDraft/VetoDraftDomainTrait.php` and `pixel-control-plugin/src/VetoDraft/*.php`; optional docs sync only if behavior or contract changes.
- Background / findings:
  - `pixel-control-plugin/src/Domain/VetoDraft/VetoDraftDomainTrait.php` is large (~2789 lines) and contains repeated feature-disabled response glue and repeated draft-default messaging.
  - Potential dead-method candidates already identified for verification: `MatchmakingVoteSession::getStatus()`, `MatchmakingVoteSession::getWinnerMapUid()`, `VetoDraftCoordinator::buildMapOrderFromStatus()`, `VetoDraftQueueApplier::applyMatchmakingWinner()`.
  - QA command surfaces already exist and are deterministic (`qa-veto-payload-sim.sh`, modular matrix actions, automated-suite gates).
- Goals:
  - Remove truly dead veto code and obsolete branches with explicit proof.
  - Reduce duplication in veto domain glue while preserving behavior and response contracts.
  - Keep veto control-surface regressions detectable through existing QA scripts.
- Non-goals:
  - No backend/runtime implementation in `pixel-control-server/`.
  - No edits under `ressources/`.
  - No broad feature redesign of matchmaking/tournament behavior.
- Constraints / assumptions:
  - Git worktree is already dirty; executor must stage/validate only intended veto-refactor files and must not revert unrelated user changes.
  - Plugin-first/dev-server-first workflow remains authoritative.
  - If external contract fields or action names must change, docs must be updated in the same execution.

## Steps

- [Done] Phase 0 - Recon and decision table for safe cleanup
- [Done] Phase 1 - Remove no-longer-useful code (dead methods and obsolete branches)
- [Done] Phase 2 - Refactor duplicated veto domain glue
- [Done] Phase 3 - Regression checks and contract safety verification
- [Done] Phase 4 - Documentation sync and handoff notes

### Phase 0 - Recon and decision table for safe cleanup

- [Done] P0.1 - Build veto-scope inventory and baseline map.
  - Confirm touched files list before edits:
    - `pixel-control-plugin/src/Domain/VetoDraft/VetoDraftDomainTrait.php`
    - `pixel-control-plugin/src/VetoDraft/MatchmakingVoteSession.php`
    - `pixel-control-plugin/src/VetoDraft/VetoDraftCoordinator.php`
    - `pixel-control-plugin/src/VetoDraft/VetoDraftQueueApplier.php`
    - plus any additional veto files that directly host extracted helpers.
- Execution notes:
  - Baseline working set confirmed in-repo; current line counts: `VetoDraftDomainTrait.php` 2789, `MatchmakingVoteSession.php` 347, `VetoDraftCoordinator.php` 628, `VetoDraftQueueApplier.php` 152.
  - Additional files available for helper extraction (if needed): `pixel-control-plugin/src/VetoDraft/VetoDraftCatalog.php` and `pixel-control-plugin/src/VetoDraft/MatchmakingLifecycleCatalog.php`.
- [Done] P0.2 - Produce a dead-code decision table for each candidate method.
  - For each candidate, record: declaration file, all repo call sites, dynamic/indirect call risk, contract risk, and keep/remove decision.
  - Candidate set to verify first:
    - `MatchmakingVoteSession::getStatus()`
    - `MatchmakingVoteSession::getWinnerMapUid()`
    - `VetoDraftCoordinator::buildMapOrderFromStatus()`
    - `VetoDraftQueueApplier::applyMatchmakingWinner()`
- Dead-code decision table (repo audit evidence from `grep` over project root + `pixel-control-plugin/src`):
  - `MatchmakingVoteSession::getStatus()` (`pixel-control-plugin/src/VetoDraft/MatchmakingVoteSession.php:223`): call sites in repo = declaration only; dynamic usage risk low (no reflective invocations over this symbol in veto code); contract risk low (not on interface/parent/external control surface) -> **remove**.
  - `MatchmakingVoteSession::getWinnerMapUid()` (`pixel-control-plugin/src/VetoDraft/MatchmakingVoteSession.php:227`): call sites in repo = declaration only; dynamic usage risk low; contract risk low (winner is already exposed through `toArray()['winner_map_uid']`) -> **remove**.
  - `VetoDraftCoordinator::buildMapOrderFromStatus()` (`pixel-control-plugin/src/VetoDraft/VetoDraftCoordinator.php:401`): call sites in repo = declaration only; dynamic usage risk low; contract risk low (internal helper wrapper only) -> **remove**.
  - `VetoDraftQueueApplier::applyMatchmakingWinner()` (`pixel-control-plugin/src/VetoDraft/VetoDraftQueueApplier.php:6`): call sites in repo = declaration only; dynamic usage risk low; contract risk low (`applySeriesMapOrder()` is the only used map-apply path) -> **remove**.
- [Done] P0.3 - Inventory duplicated glue blocks in `VetoDraftDomainTrait`.
  - Identify repeated "feature disabled" response bodies and repeated "Draft defaults" message construction paths.
  - Group duplicates by semantic behavior to define extraction targets.
- Duplicate inventory and extraction targets:
  - Feature-disabled communication response duplicated in `handleVetoDraftCommunicationStart`, `handleVetoDraftCommunicationAction`, `handleVetoDraftCommunicationCancel`, `handleVetoDraftCommunicationReady` -> extract one helper returning same `CommunicationAnswer` payload (`success=false`, `code=feature_disabled`, unchanged message).
  - Feature-disabled command chat message currently inline in `handleVetoDraftCommand` -> align through one chat helper to keep wording centralized.
  - "Draft defaults: ..." message duplicated in `mode`, `duration`, `min_players`, `ready` command branches plus `sendVetoDraftConfigToPlayer` -> extract canonical formatter + single sender helper to preserve identical ordering/wording.

- [Done] Phase 0 - Recon and decision table for safe cleanup
- [Done] Phase 1 - Remove no-longer-useful code (dead methods and obsolete branches)

### Phase 1 - Remove no-longer-useful code (dead methods and obsolete branches)

- [Done] P1.1 - Remove methods marked `remove` by the Phase 0 decision table.
  - Delete method declarations and any method-specific adapters/imports/constants that become unused.
  - Keep removals minimal and scoped; do not collapse unrelated logic in the same edit.
- Dead-code removals applied:
  - `pixel-control-plugin/src/VetoDraft/MatchmakingVoteSession.php`: removed `getStatus()` and `getWinnerMapUid()`.
  - `pixel-control-plugin/src/VetoDraft/VetoDraftCoordinator.php`: removed `buildMapOrderFromStatus()` wrapper method.
  - `pixel-control-plugin/src/VetoDraft/VetoDraftQueueApplier.php`: removed `applyMatchmakingWinner()` wrapper method.
- [Done] P1.2 - Remove obsolete/unreachable veto branches revealed by dead-call cleanup.
  - Remove only branches that are provably unreachable under current control flow and contract.
  - If uncertainty remains (possible indirect runtime usage), do not remove; mark for follow-up in outcomes.
- Execution note:
  - No additional unreachable control-flow branches were proven by this dead-method cleanup pass; branch logic retained to avoid contract risk.
- [Done] P1.3 - Re-run static usage checks after deletion.
  - Confirm no remaining references to removed symbols.
  - Confirm class/interface contracts remain valid (no orphaned overrides or signature drift).
- Static usage audit result:
  - Repo searches now return zero references for `getWinnerMapUid`, `buildMapOrderFromStatus`, and `applyMatchmakingWinner` inside `pixel-control-plugin/src`.

- [Done] Phase 1 - Remove no-longer-useful code (dead methods and obsolete branches)
- [Done] Phase 2 - Refactor duplicated veto domain glue

### Phase 2 - Refactor duplicated veto domain glue

- [Done] P2.1 - Extract shared helper(s) for feature-disabled responses.
  - Replace duplicated chat/communication disabled-answer blocks with one internal helper path per response type.
  - Preserve existing response codes/messages/shape unless explicit contract update is intended.
- [Done] P2.2 - Extract shared helper(s) for draft-default status/message rendering.
  - Replace repeated "Draft defaults" message construction with one canonical builder.
  - Keep wording and field ordering stable unless intentionally improved and validated.
- [Done] P2.3 - Simplify trait control flow without changing surface behavior.
  - Prefer private helper methods and single-responsibility blocks over repeated inline assemblies.
  - Keep command names, communication method names, lifecycle field keys, and action/result codes backward compatible.
- Refactor outcomes:
  - Added centralized disabled-response helpers in `VetoDraftDomainTrait` for chat + communication surfaces, including dedicated disabled-status payload helper.
  - Added `buildVetoDraftDefaultsSummaryLine()` + `sendVetoDraftDefaultsSummaryToPlayer()` and replaced five duplicated inline assemblies.

- [Done] Phase 2 - Refactor duplicated veto domain glue
- [Done] Phase 3 - Regression checks and contract safety verification

### Phase 3 - Regression checks and contract safety verification

- [Done] P3.1 - Run required PHP lint on all touched plugin PHP files.
  - Mandatory minimum:
    - `php -l pixel-control-plugin/src/Domain/VetoDraft/VetoDraftDomainTrait.php`
    - `php -l pixel-control-plugin/src/VetoDraft/MatchmakingVoteSession.php`
    - `php -l pixel-control-plugin/src/VetoDraft/VetoDraftCoordinator.php`
    - `php -l pixel-control-plugin/src/VetoDraft/VetoDraftQueueApplier.php`
  - Plus `php -l` for any additional touched plugin PHP files.
- Lint result:
  - All four touched plugin PHP files report `No syntax errors detected`.
- [Done] P3.2 - Validate veto communication control surface after refactor.
  - `bash pixel-sm-server/scripts/dev-plugin-hot-sync.sh`
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh status`
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh start mode=matchmaking_vote duration_seconds=8 launch_immediately=0`
  - Plan-gap adjustment (runtime ready-gate): if start returns `matchmaking_ready_required`, run `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh ready` then rerun the same start command before continuing action/cancel checks.
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh action actor_login=voter_a operation=vote map=1`
  - Plan-gap adjustment (short-duration timing): if a standalone `action ... vote` run returns `matchmaking_ready_required` after the short session window closes, run a tight precondition sequence (`ready` + immediate `action` and/or `ready` + `start` + immediate `action`) so action validation executes inside the armed/active window.
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh cancel reason=qa_cleanup`
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh matrix`
- Progress note:
  - `bash pixel-sm-server/scripts/dev-plugin-hot-sync.sh` completed successfully (evidence logs under `pixel-sm-server/logs/dev/` timestamp `20260222-190852`).
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh status` completed (artifact `pixel-sm-server/logs/qa/veto-payload-sim-20260222-190923/status.json`).
  - First `start` attempt returned deterministic gate precondition (`matchmaking_ready_required`) at `pixel-sm-server/logs/qa/veto-payload-sim-20260222-190937/start.json`; follow-up run updated per plan-gap adjustment.
  - Standalone `action` run returned `matchmaking_ready_required` at `pixel-sm-server/logs/qa/veto-payload-sim-20260222-191029/action.json` after short matchmaking window elapsed; continuing with immediate preconditioned rerun per plan-gap adjustment.
  - Ready arming evidence: `pixel-sm-server/logs/qa/veto-payload-sim-20260222-191002/ready.json`.
  - Successful rerun artifacts:
    - start success: `pixel-sm-server/logs/qa/veto-payload-sim-20260222-191018/start.json`
    - action success (vote): `pixel-sm-server/logs/qa/veto-payload-sim-20260222-191117/action.json`
    - cancel success: `pixel-sm-server/logs/qa/veto-payload-sim-20260222-191139/cancel.json`
    - matrix run: `pixel-sm-server/logs/qa/veto-payload-sim-20260222-191154/summary.md` and `pixel-sm-server/logs/qa/veto-payload-sim-20260222-191154/matrix-validation.json`
- [Done] P3.3 - Run cross-surface non-regression checks.
  - `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh matrix`
  - `bash pixel-sm-server/scripts/test-automated-suite.sh --modes elite,joust`
- Cross-surface results:
  - Admin matrix completed at `pixel-sm-server/logs/qa/admin-payload-sim-20260222-191239/summary.md`.
  - Automated suite (`elite,joust`) passed at `pixel-sm-server/logs/qa/automated-suite-20260222-191321/suite-summary.json` (`total_checks=39`, `passed_checks=39`, `required_failed_checks=0`).
- [Done] P3.4 - Verify deletion/refactor did not alter required response contracts.
  - Compare pre/post `Status` and matrix artifacts for required keys and expected codes.
  - Confirm `PixelControl.VetoDraft.*` methods remain available and operational.
- Contract-safety verification:
  - `Status` artifact confirms `PixelControl.VetoDraft.Start|Action|Status|Cancel|Ready` availability and expected status envelope keys (`enabled`, `command`, `default_mode`, lifecycle/status snapshots): `pixel-sm-server/logs/qa/veto-payload-sim-20260222-190923/status.json`.
  - Veto matrix strict validator reports `overall_passed=true` with `required_failed_checks=[]` and method-shape checks passed for `status/start/action/cancel/ready`: `pixel-sm-server/logs/qa/veto-payload-sim-20260222-191154/matrix-validation.json`.
  - Admin control-surface summary remains consistent with expected success/failure codes for placeholder-constrained actions: `pixel-sm-server/logs/qa/admin-payload-sim-20260222-191239/summary.md`.

- [Done] Phase 3 - Regression checks and contract safety verification
- [Done] Phase 4 - Documentation sync and handoff notes

### Phase 4 - Documentation sync and handoff notes

- [Done] P4.1 - Update docs only if behavior/contract changed.
  - Update `pixel-control-plugin/FEATURES.md` and `pixel-control-plugin/docs/event-contract.md` if externally observable behavior changed.
  - Update `API_CONTRACT.md` only if communication contract changed.
  - If no contract changes, explicitly record "No contract change" in outcomes.
- Outcome:
  - No behavior/contract changes were introduced by this cleanup pass; **No contract change**.
- [Done] P4.2 - Record concise execution notes and residual follow-ups.
  - Summarize what was removed, what was refactored, what remained intentionally, and why.
  - Include command/evidence paths for reproducibility.
- Execution recap:
  - Removed dead wrappers/getters with zero call sites (`getStatus`, `getWinnerMapUid`, `buildMapOrderFromStatus`, `applyMatchmakingWinner`) after explicit repo-wide audit evidence.
  - Refactored `VetoDraftDomainTrait` duplication into centralized helpers for disabled responses and draft-default summary rendering; response payload shapes/messages remain unchanged.
  - Intentionally retained uncertain runtime branches (including lifecycle fallback and warning-emitting matrix paths) where removal could alter deterministic QA expectations.
  - Required validation command evidence roots:
    - hot-sync: `pixel-sm-server/logs/dev/dev-plugin-hot-sync-shootmania-20260222-190852.log`, `pixel-sm-server/logs/dev/dev-plugin-hot-sync-maniacontrol-20260222-190852.log`
    - veto control-surface checks: `pixel-sm-server/logs/qa/veto-payload-sim-20260222-190923/`, `pixel-sm-server/logs/qa/veto-payload-sim-20260222-191018/`, `pixel-sm-server/logs/qa/veto-payload-sim-20260222-191117/`, `pixel-sm-server/logs/qa/veto-payload-sim-20260222-191139/`, `pixel-sm-server/logs/qa/veto-payload-sim-20260222-191154/`
    - admin matrix: `pixel-sm-server/logs/qa/admin-payload-sim-20260222-191239/`
    - automated suite: `pixel-sm-server/logs/qa/automated-suite-20260222-191321/`

- [Done] Phase 4 - Documentation sync and handoff notes

## Explicit removal criteria ("remove what is no longer useful")

- Remove only when all are true:
  - zero real call sites after repo-wide usage audit,
  - not required by interface/parent contract,
  - not part of documented external control surface (`/pcveto`, `PixelControl.VetoDraft.*`, lifecycle payload fields),
  - no deterministic QA scenario depends on the symbol.
- Keep (or postpone removal) when any are true:
  - plausible dynamic/indirect usage cannot be disproven,
  - removal would change existing response codes/messages/keys without explicit scope approval,
  - symbol is part of extension seam expected by current architecture.

## Regression safeguards

- Preserve the following as backward-compatible unless intentionally re-scoped with doc updates:
  - command and communication entrypoint names,
  - payload key names in veto status and lifecycle telemetry,
  - expected success/error code families used by QA matrix assertions.
- Require both targeted veto checks and broader automated-suite checks to pass before handoff.
- If any compatibility break is unavoidable, document exact old/new behavior and update contract docs in the same change set.

## Evidence / Artifacts

- `pixel-sm-server/logs/qa/veto-payload-sim-<timestamp>/`
- `pixel-sm-server/logs/qa/admin-payload-sim-<timestamp>/`
- `pixel-sm-server/logs/qa/automated-suite-<timestamp>/`

## Success criteria

- All removed methods/branches have explicit "why safe to remove" evidence.
- `VetoDraftDomainTrait` duplicated glue is consolidated into reusable helpers with no control-surface regression.
- Required PHP lint passes on every touched plugin file.
- Veto QA matrix + admin matrix + automated-suite (`elite,joust`) pass after hot-sync.
- Documentation is either updated for contract changes or explicitly states no contract change.
