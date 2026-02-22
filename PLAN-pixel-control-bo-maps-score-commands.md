# PLAN - Add BO, map-count, and team-score control commands (2026-02-21)

## Context

- Purpose: define an execution-ready plan to add operator controls in `pixel-control-plugin/` for (1) setting BO (`BO3`, `BO5`, `BO7`, ...), and (2) setting map-count target and per-team score target via commands.
- Scope: plugin-first changes in `pixel-control-plugin/src/` plus contract/documentation sync in `pixel-control-plugin/FEATURES.md`, `pixel-control-plugin/docs/event-contract.md`, `API_CONTRACT.md`, and TODO closure in `TODO.md` when implementation is accepted.
- Constraints / assumptions:
  - `pixel-control-server/` remains implementation-deferred (no backend runtime work).
  - Existing command/control patterns are reused (`pcveto`, `pcadmin`, `PixelControl.Admin.*`, `PixelControl.VetoDraft.*`).
  - `best_of` already exists in tournament start payloads; this work adds explicit operator-set controls and persistent runtime defaults.
  - Contract evolution remains additive (schema version stays `2026-02-20.1` unless a breaking change is explicitly approved).

## Objective and non-goals

- Objective:
  - Provide a clear operator workflow to set BO and series targets (map-count target + team A/B score target) without editing env files between matches.
  - Keep ownership boundaries clean: plugin controls runtime policy state, ManiaControl native services remain the execution backend where applicable.
  - Keep SOLID structure (catalog/constants + focused service + trait integration, no monolithic trait growth).
- Non-goals:
  - No `pixel-control-server/` implementation.
  - No edits inside `ressources/`.
  - No redesign of veto flow logic (ban/pick sequence algorithm remains as-is unless required for BO compatibility).
  - No production auth redesign for communication channel in this scope.

## Current-state summary

- Veto baseline:
  - `pcveto start tournament ... [bo=3]` and `PixelControl.VetoDraft.Start` already accept `best_of`.
  - No explicit operator command exists to set/update default BO outside each start request.
- Admin baseline:
  - `pcadmin` and `PixelControl.Admin.ExecuteAction` already dispatch a catalog of delegated actions.
  - No action exists for series policy (`best_of`, map-count target, per-team score target).
- Telemetry/docs baseline:
  - `map_rotation` already exposes veto/draft status/actions/result.
  - No explicit series-target snapshot is documented today.

## Proposed command/API surface

### Chat command surface

- `//pcveto bo <odd>`
  - Sets runtime default BO for tournament draft starts (normalized odd value, bounded range).
- `//pcveto config`
  - Returns current veto/series control snapshot (default BO, map-count target, team score targets).
- `//pcadmin match.series.set maps=<odd> score_a=<int> score_b=<int> [bo=<odd>]`
  - Sets operator series targets and optional BO in one command.
- `//pcadmin match.series.status`
  - Returns current series control snapshot and last update metadata.

### Communication/API surface

- Extend `PixelControl.Admin.ExecuteAction` action catalog with:
  - `match.series.set` (parameters: `map_count_target`, `team_a_score_target`, `team_b_score_target`, optional `best_of`).
  - `match.series.status` (no required params).
- Keep `PixelControl.VetoDraft.Start` backward compatible:
  - If `best_of` is omitted, use operator-set runtime BO default.
  - Explicit request `best_of` still overrides default for that start request.
- Extend `PixelControl.VetoDraft.Status` response additively with `series_targets` snapshot (no removal/rename of existing fields).

## Data model and state ownership

- Add a dedicated plugin-side series-control state owner (new focused service/module under `pixel-control-plugin/src/`, for example `SeriesControl/*`).
- Canonical runtime state fields:
  - `best_of` (odd, bounded, sanitized).
  - `map_count_target` (positive int, typically odd for tournament flow).
  - `team_score_target.team_a` and `team_score_target.team_b` (non-negative ints).
  - `updated_at`, `updated_by`, `update_source` (`env|setting|chat|communication`).
- Ownership rules:
  - Single writer path through explicit command/communication handlers.
  - Veto start reads this state as default policy.
  - Lifecycle/map telemetry reads this state as contextual metadata only (additive).
  - No backend persistence; defaults come from env/settings on load and are mutable at runtime.

## Steps

Execution rule: this plan is authoring-only; Executor performs implementation and keeps one active `[In progress]` step.

- [Done] Phase 0 - Freeze behavior and parameter policy
- [Done] Phase 1 - Add series-control domain scaffolding
- [Done] Phase 2 - Extend command and communication surfaces
- [Done] Phase 3 - Integrate behavior with veto/admin flows
- [Done] Phase 4 - Validation and QA evidence
- [Done] Phase 5 - Documentation and TODO closure

### Phase 0 - Freeze behavior and parameter policy

Acceptance criteria: a written policy (in plan-to-code decisions) exists for normalization, precedence, and active-session behavior.

- [Done] P0.1 - Define canonical parameter names and aliases.
  - Freeze command/action payload keys: `best_of`, `map_count_target`, `team_a_score_target`, `team_b_score_target`.
  - Keep short chat aliases (`bo`, `maps`, `score_a`, `score_b`) mapped in parser layer.
- [Done] P0.2 - Define normalization and bounds.
  - `best_of` sanitized odd and bounded (`1..9` unless catalog max is updated).
  - `map_count_target` positive integer (odd preference policy documented).
  - team score targets must be integers >= 0.
- [Done] P0.3 - Define active-session mutation policy.
  - Freeze whether updates apply immediately or only to next session; return deterministic rejection code when blocked.

Phase-0 execution decisions (frozen for implementation):

- Canonical payload keys stay `best_of`, `map_count_target`, `team_a_score_target`, `team_b_score_target`.
- Chat/operator aliases are parser-only: `bo -> best_of`, `maps -> map_count_target`, `score_a -> team_a_score_target`, `score_b -> team_b_score_target`.
- Normalization policy:
  - `best_of`: odd integer, bounded to `1..9` via deterministic sanitization.
  - `map_count_target`: required positive integer (`>=1`), odd value preferred but not enforced.
  - `team_a_score_target` / `team_b_score_target`: required integers `>=0`.
- Mutation policy:
  - Runtime series policy updates are accepted immediately for default state storage.
  - Active veto/draft sessions are not rewritten retroactively; updated BO/targets apply deterministically to next session start.
  - Invalid/missing write inputs return deterministic failures (`missing_parameters`, `invalid_parameters`).

### Phase 1 - Add series-control domain scaffolding

Acceptance criteria: a dedicated, testable state holder exists; plugin boot resolves defaults from env/settings without duplicating logic across traits.

- [Done] P1.1 - Introduce catalog/constants for series-control settings and validation helpers.
  - Follow existing catalog pattern (`VetoDraftCatalog`, `AdminActionCatalog`) with focused responsibility.
- [Done] P1.2 - Add a small service/state object for canonical runtime series controls.
  - Expose getter/setter methods returning deterministic result payloads (`success`, `code`, `message`, `snapshot`).
- [Done] P1.3 - Wire plugin bootstrap and unload boundaries.
  - Initialize defaults at load; reset in unload path.
  - Preserve existing setting/env precedence conventions.

### Phase 2 - Extend command and communication surfaces

Acceptance criteria: operators can set/read BO and map/score targets from both chat and communication paths; help/list outputs include new capabilities.

- [Done] P2.1 - Extend `pcveto` command operations.
  - Add `bo` setter operation and `config` status operation.
  - Keep existing `start/status/maps/cancel/vote/action` behavior unchanged.
- [Done] P2.2 - Extend admin action catalog and normalization.
  - Add `match.series.set` and `match.series.status` definitions, permissions, and parameter metadata.
- [Done] P2.3 - Implement delegated execution handlers.
  - Add NativeAdminGateway handlers for new actions using series-control service.
  - Include deterministic error codes for invalid/missing values.
- [Done] P2.4 - Extend communication status payloads.
  - `PixelControl.Admin.ListActions` and `PixelControl.VetoDraft.Status` expose new series fields additively.

### Phase 3 - Integrate behavior with veto/admin flows

Acceptance criteria: configured BO/targets are consumed by tournament starts and visible in runtime status/telemetry with no regression to existing veto flow.

- [Done] P3.1 - BO precedence integration.
  - Start tournament uses explicit request `best_of` first, then runtime series-control default.
- [Done] P3.2 - Map-count and score-target integration.
  - Ensure canonical targets are available in status snapshots and connectivity/map-rotation context where appropriate.
  - Keep fields additive and optional when unavailable.
- [Done] P3.3 - Correlation and observability.
  - Reuse admin action markers for set/status actions and include meaningful target scope/id for correlation.

### Phase 4 - Validation and QA evidence

Acceptance criteria: static checks pass; deterministic QA confirms happy path + invalid input handling for chat and communication controls.

- [Done] P4.1 - Static validation.
  - Run PHP lint on all touched plugin files.
- [Done] P4.2 - Chat-command validation.
  - Validate `pcveto bo`, `pcveto config`, `pcadmin match.series.set`, `pcadmin match.series.status` flows.
  - Verify permission-denied paths and malformed input errors.
- [Done] P4.3 - Communication-path validation.
  - Validate `PixelControl.Admin.ExecuteAction` (`match.series.set/status`) and `PixelControl.VetoDraft.Status` snapshot fields.
  - Re-run relevant QA helpers (`qa-admin-payload-sim.sh`, `qa-veto-payload-sim.sh`) with evidence capture.
- [Done] P4.4 - Regression safety.
  - Confirm existing veto start/action/cancel and existing admin actions are unchanged.

Phase-4 execution notes:

- Static validation passed with `php -l` on all touched plugin PHP files.
- Deterministic communication QA executed:
  - `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh matrix` -> pass with expected placeholder-driven failures only on target/map-specific actions.
  - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh matrix` -> pass across matchmaking/tournament flow steps.
- Fresh revalidation (2026-02-22) executed for this scope:
  - initial `qa-admin-payload-sim.sh matrix` attempt failed fast with `Service 'shootmania' is not running`; after `docker compose up -d --build` from `pixel-sm-server/`, rerun passed with summary `pixel-sm-server/logs/qa/admin-payload-sim-20260222-004714/summary.md`.
  - `qa-veto-payload-sim.sh matrix` rerun passed with summary `pixel-sm-server/logs/qa/veto-payload-sim-20260222-004758/summary.md`.
- Series-control action verification executed additively:
  - `match.series.status` returned baseline snapshot,
  - `match.series.set maps=5 score_a=7 score_b=6 bo=7` returned success,
  - follow-up `match.series.status` confirmed persisted runtime snapshot.
- BO precedence validation executed:
  - tournament start without explicit `best_of` produced sequence `best_of=7` after policy update.
- Chat-command behavior cannot be fully replayed headlessly in this environment; parser/handler paths are covered through the same delegated action/services and require in-game/manual confirmation for end-user UX messaging and permission-denied chat responses.

### Phase 5 - Documentation and TODO closure

Acceptance criteria: docs/contracts reflect new control surface and TODO items are checked only after verified implementation.

- [Done] P5.1 - Update plugin feature docs.
  - `pixel-control-plugin/FEATURES.md` (new controls, settings, command/API surface).
- [Done] P5.2 - Update contract docs.
  - `pixel-control-plugin/docs/event-contract.md` and `API_CONTRACT.md` for additive fields/actions.
  - Update schema/catalog artifacts only if payload shape is extended.
- [Done] P5.3 - Update TODO checklist after acceptance.
  - In `TODO.md`, check:
    - `Ajouter aussi des fonctionnaltiées au Pixel Control Plugin pour set le BO (BO3, BO5, BO7, ...)`
    - `Ajouter les commandes pour set le nombre de maps et le score pour chaque équipes`

## Validation strategy

- Static checks:
  - PHP lint for all changed plugin files (`php -l <file>` on touched files).
- Functional QA (local stack):
  - Run chat command matrix manually in active dev server session.
  - Run payload simulations:
    - `bash pixel-sm-server/scripts/qa-admin-payload-sim.sh matrix`
    - `bash pixel-sm-server/scripts/qa-veto-payload-sim.sh matrix`
- Acceptance assertions:
  - BO can be set once and reused by tournament starts without explicit `best_of` each time.
  - Map-count target and per-team score target are settable/readable via both chat and communication.
  - Existing veto/admin actions keep prior behavior.

## Rollback and risk notes

- Rollback path:
  - Revert series-control additions while keeping legacy `best_of` start parameter path intact.
  - If needed, temporarily hide new control commands/actions from help/list while preserving binary compatibility.
- Risks:
  - Ambiguity between "target" values and live dedicated-server scoring capabilities.
  - Active-session mutation could create inconsistent operator expectations.
  - Communication channel still uses temporary trusted mode; new actions increase blast radius if exposed externally.
- Mitigations:
  - Keep targets explicitly labeled as plugin-side control metadata unless native apply is confirmed.
  - Return explicit status/error codes for unsupported mode/capability.
  - Keep payload changes additive and documented.

## Documentation update checklist

- [Done] `pixel-control-plugin/FEATURES.md`
- [Done] `pixel-control-plugin/docs/event-contract.md`
- [Done] `API_CONTRACT.md`
- [Done] `pixel-control-plugin/docs/schema/*` (only if new payload fields are introduced)
- [Done] `TODO.md` (final checkbox update after implementation acceptance)

## Success criteria

- Operators can set BO defaults and series targets (map-count + team score targets) from chat and communication surfaces.
- Tournament start honors explicit `best_of` override and otherwise uses operator-set default.
- New fields/actions are additive, documented, and validated with deterministic QA evidence.
- The two requested TODO items are checked only after implementation and verification complete.
