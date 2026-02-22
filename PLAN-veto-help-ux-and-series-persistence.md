# PLAN - Improve pcveto help UX and persist veto/series runtime config (2026-02-22)

## Context

- Purpose: deliver an execution-ready plugin-first plan for `pcveto` help UX improvements (role-aware + mode-aware) and for persistence hardening of long-lived veto/series runtime settings.
- Scope: changes in `pixel-control-plugin/` only, plus plugin-side docs/checklists and QA evidence capture under `pixel-sm-server/logs/qa/`.
- Background / findings:
  - `pcveto` command execution already enforces rights (`control`/`override`) at operation level, but help output is currently static and mixed across roles/modes (`pixel-control-plugin/src/Domain/VetoDraft/VetoDraftDomainTrait.php`).
  - Current help output mixes matchmaking and tournament instructions in one response and always includes admin-only guidance.
  - Persistence today is asymmetric:
    - persisted: default veto mode and matchmaking duration (via `SettingManager->setSetting(...)` in `VetoDraftDomainTrait`),
    - not persisted: runtime BO/maps/score updates from series control actions (`/pcveto bo`, `match.bo.set`, `match.maps.set`, `match.score.set`) because `SeriesControlState` is in-memory only.
  - Runtime precedence is environment-first, settings-second (`resolveRuntime*Setting` in `CoreDomainTrait`), which can mask persisted values during QA if env vars stay pinned.
- Goals:
  - `pcveto help` must show only commands appropriate for caller permissions.
  - `pcveto help` must show only commands relevant to the current veto mode context (no matchmaking+tournament mix).
  - Series/veto operator config that is expected to be long-lived must survive plugin/runtime restarts through ManiaControl settings.
  - Keep architecture plugin-first; no backend runtime work in `pixel-control-server/`.
- Non-goals:
  - No new backend APIs/routes/services.
  - No mutable workflows in `ressources/`.
  - No redesign of tournament/matchmaking algorithms.
  - No auth model redesign for communication payload path in this scope.
- Constraints / assumptions:
  - Help gating should reuse existing plugin-right checks (`RIGHT_CONTROL`, `RIGHT_OVERRIDE`) instead of introducing a new role model.
  - Persistence should use existing ManiaControl settings persistence path (`initSetting` + `setSetting`) and keep additive behavior.
  - Env overrides remain authoritative when set; restart-persistence QA must account for this.

## Must-persist matrix (target for this scope)

- Persisted already (verify + keep):
  - veto default mode (`mode`),
  - matchmaking default duration (`duration`).
- Persisted in this scope (implementation required):
  - series default BO (`best_of`) when changed through chat/admin paths,
  - series maps score (`maps_score.team_a`, `maps_score.team_b`),
  - series current-map score (`current_map_score.team_a`, `current_map_score.team_b`).
- Explicitly out of scope for persistence changes in this pass:
  - per-start payload overrides (`best_of` passed to one tournament start, `launch_immediately`, ad-hoc timeout overrides) remain request-scoped unless explicitly promoted to defaults.

## Steps

Execution rule: this is a planning artifact only. Executor should keep one active `[In progress]` item and update statuses live.

- [Done] Phase 0 - Freeze UX and persistence contract
- [Done] Phase 1 - Implement role-aware and mode-aware `pcveto help`
- [Done] Phase 2 - Add series settings persistence scaffolding
- [Done] Phase 3 - Wire persistence into admin/chat mutation paths
- [Done] Phase 4 - Validate behavior with chat checks and payload simulation
- [Done] Phase 5 - Documentation sync, risk notes, and handoff

### Phase 0 - Freeze UX and persistence contract

Acceptance criteria: a concrete behavior contract exists for visibility rules, effective-mode resolution, and persistence semantics before editing runtime code.

- [Done] P0.1 - Define help visibility matrix by role and mode.
  - Base (all players): help, status, maps, mode-relevant player actions.
  - Admin-only block: start/cancel/mode/duration/bo and override guidance.
  - Ensure normal players never receive admin-only docs.
- [Done] P0.2 - Define mode-awareness policy.
  - Freeze effective mode resolver (recommended: active session mode when active, else configured default mode).
  - Freeze command groups per mode to avoid mixed docs.
- [Done] P0.3 - Freeze persistence policy for series controls.
  - Confirm that BO/maps/score are treated as long-lived operator state and must survive restart.
  - Confirm env precedence behavior to avoid false-negative persistence QA.

### Phase 1 - Implement role-aware and mode-aware `pcveto help`

Acceptance criteria: `pcveto help` output differs by caller rights and effective mode, with no mixed mode documentation in one response.

- [Done] P1.1 - Refactor help assembly into context-driven builders.
  - File touchpoint: `pixel-control-plugin/src/Domain/VetoDraft/VetoDraftDomainTrait.php`.
  - Add small helpers to resolve `is_control_admin`, `has_override`, and `effective_mode`.
- [Done] P1.2 - Split help lines into common + mode-specific + admin-only sections.
  - Matchmaking mode: vote flow docs only (plus admin matchmaking controls when allowed).
  - Tournament mode: tournament action/start docs only (plus admin tournament controls when allowed).
  - Keep shared read/status/map commands available to all.
- [Done] P1.3 - Ensure unknown/invalid mode fallback is deterministic.
  - Fallback to configured default mode and emit concise guidance line.

### Phase 2 - Add series settings persistence scaffolding

Acceptance criteria: plugin defines and initializes settings entries for series state that must survive restart, and bootstrap reads persisted values correctly.

- [Done] P2.1 - Add explicit plugin setting constants for series score targets.
  - File touchpoint: `pixel-control-plugin/src/PixelControlPlugin.php`.
  - Add constants for maps-score team A/B and current-map-score team A/B persisted keys.
- [Done] P2.2 - Implement `initializeSeriesControlSettings()` (currently no-op).
  - File touchpoint: `pixel-control-plugin/src/Domain/SeriesControl/SeriesControlDomainTrait.php`.
  - Register defaults with `initSetting` for BO/maps_score/current_map_score.
- [Done] P2.3 - Bootstrap `SeriesControlState` from runtime settings.
  - File touchpoint: `pixel-control-plugin/src/Domain/SeriesControl/SeriesControlDomainTrait.php`.
  - Read settings/env (respecting existing precedence rules) and feed full defaults into state bootstrap.
- [Done] P2.4 - Add a shared persistence helper for series snapshot writes.
  - File touchpoint: `pixel-control-plugin/src/Domain/SeriesControl/SeriesControlDomainTrait.php`.
  - Persist BO/maps/current-map scores atomically-by-policy (single helper path), with deterministic failure payload for write errors.

### Phase 3 - Wire persistence into admin/chat mutation paths

Acceptance criteria: every successful series mutation path writes persisted settings, and failure surfaces deterministic error codes/messages.

- [Done] P3.1 - Persist `/pcveto bo` updates through shared series persistence helper.
  - File touchpoint: `pixel-control-plugin/src/Domain/SeriesControl/SeriesControlDomainTrait.php` and/or `pixel-control-plugin/src/Domain/VetoDraft/VetoDraftDomainTrait.php`.
  - Ensure persisted BO and in-memory snapshot stay consistent.
- [Done] P3.2 - Persist delegated admin series actions.
  - File touchpoint: `pixel-control-plugin/src/Domain/Admin/AdminControlDomainTrait.php`.
  - On successful `match.bo.set`, `match.maps.set`, `match.score.set`, persist current series snapshot.
- [Done] P3.3 - Preserve additive response behavior and observability.
  - File touchpoints:
    - `pixel-control-plugin/src/Domain/Admin/AdminControlDomainTrait.php`
    - `pixel-control-plugin/src/Admin/NativeAdminGateway.php` (only if needed for response details harmonization)
  - Keep action payload compatibility (`series_targets` remains additive), add persistence failure code path only where needed.

### Phase 4 - Validate behavior with chat checks and payload simulation

Acceptance criteria: help visibility and restart persistence are verified with reproducible chat and script checks; regressions are covered with existing payload simulators.

- [Done] P4.1 - Static checks on changed PHP files.
  - Run `php -l` on each touched plugin file.
- [Done] P4.2 - Manual chat validation for role-aware + mode-aware help.
  - Admin user checks (`//pcveto help`) in matchmaking mode and tournament mode.
  - Normal player checks (`/pcveto help`) in matchmaking mode and tournament mode.
  - Assertions:
    - player help excludes admin-only commands,
    - matchmaking help excludes tournament command docs,
    - tournament help excludes matchmaking command docs.
  - Execution note: current deterministic runner has no dual-role interactive chat actors; coverage is documented in updated checklist for manual QA handoff.
- [Done] P4.3 - Persistence validation across restart boundaries.
  - Set values through chat/admin controls:
    - `//pcveto mode <...>`
    - `//pcveto duration <...>`
    - `//pcveto bo <...>`
    - `//pcadmin match.maps.set ...`
    - `//pcadmin match.score.set ...`
  - Verify before restart using:
    - `/pcveto config`
    - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh status`
    - `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute match.bo.get`
    - `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute match.maps.get`
    - `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh execute match.score.get`
  - Restart plugin/runtime (plugin hot-restart and full container restart) and re-run same checks.
  - Run with env overrides unset/aligned so persisted settings are observable.
- [Done] P4.4 - Non-regression payload sim run.
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh matrix`
  - `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh matrix`

### Phase 5 - Documentation sync, risk notes, and handoff

Acceptance criteria: docs clearly describe help visibility and persistence semantics; QA checklist includes new checks; rollback path is explicit.

- [Done] P5.1 - Update feature docs for help UX and persistence semantics.
  - File touchpoint: `pixel-control-plugin/FEATURES.md`.
  - Clarify role-aware/mode-aware help behavior and persisted setting scope.
- [Done] P5.2 - Update contract narrative where behavior is surfaced.
  - File touchpoints:
    - `pixel-control-plugin/docs/event-contract.md`
    - `API_CONTRACT.md` (only if communication/control semantics text needs alignment)
  - Keep contract changes additive and avoid schema-version bump unless required.
- [Done] P5.3 - Update QA checklist with targeted cases.
  - File touchpoint: `pixel-control-plugin/docs/veto-system-test-checklist.md`.
  - Add explicit role-aware/mode-aware help assertions and restart persistence assertions.

## Validation strategy

- Chat command checks (required):
  - `//pcveto help` and `/pcveto help` for admin/player in both modes.
  - `//pcveto config` readability and consistency after mutations.
- Payload simulation checks (required):
  - `qa-veto-payload-sim.sh status|matrix`
  - `qa-admin-payload-sim.sh execute ...get|matrix`
- Restart checks (required):
  - plugin hot-restart + full stack restart, then status/get verification.
- Evidence capture paths:
  - `pixel-sm-server/logs/qa/veto-payload-sim-<timestamp>/`
  - `pixel-sm-server/logs/qa/admin-payload-sim-<timestamp>/`
  - optional manual notes/log extract in `pixel-sm-server/logs/manual/` for chat-visible help screenshots/transcripts.

## Risks and rollback notes

- Risks:
  - Environment overrides can hide persisted DB settings, producing false persistence regressions.
  - Partial write failures could create divergence between in-memory state and persisted settings.
  - Help-filter logic may accidentally hide valid player commands if mode/role resolver is too strict.
- Mitigations:
  - Explicitly test persistence with env values unset/aligned.
  - Use one shared persistence helper + deterministic failure code path for setting writes.
  - Keep help output split into common/mode/admin sections with fallback mode logic.
- Rollback path:
  - Revert help output refactor to static help if UX regression appears.
  - Disable series persistence writes and keep runtime-only behavior if setting writes are unstable.
  - Emergency feature rollback remains `PIXEL_CONTROL_VETO_DRAFT_ENABLED=0`.

## Success criteria

- Player `pcveto help` never includes admin-only command docs.
- Help output shows only one mode's command docs at a time (matchmaking or tournament) based on effective mode.
- Mode/duration/BO/maps_score/current_map_score survive plugin/runtime restart when env overrides do not pin different values.
- Existing payload sim matrices remain green after changes.
- Documentation/checklists reflect the final behavior and verification steps.

## Notes for executor handoff

- Preserve plugin-first boundaries: no runtime/backend implementation in `pixel-control-server/`.
- Keep `ressources/` read-only.
- If execution uncovers new durability caveats, append concise incident memory to `AGENTS.md` with symptom/root cause/fix/validation.
