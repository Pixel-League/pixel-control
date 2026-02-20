# PLAN - ManiaControl native-admin delegation audit and Pixel plugin refactor (2026-02-20)

## Context

- Purpose: Produce an execution-ready audit and refactor plan so `pixel-control-plugin/` uses ManiaControl native admin capabilities instead of re-implementing overlapping behavior.
- Scope: This plan targets `pixel-control-plugin/` as the implementation surface and includes contract/documentation synchronization in `pixel-control-plugin/FEATURES.md`, `pixel-control-plugin/docs/event-contract.md`, and `API_CONTRACT.md` only if behavior/contract changes.
- Background / Findings:
  - The current plugin is telemetry/transport focused (callbacks -> envelopes -> async queue) and does not expose a dedicated control surface for map/pause/warmup/vote/player-force actions.
  - The current plugin already reads admin/auth-related state for telemetry (`auth_level`, `admin_action`, `constraint_signals`) but does not orchestrate native ManiaControl admin actions.
  - ManiaControl already ships native facilities for auth levels/permissions, map control, warmup/pause control, vote cancellation/ratios, player force/moderation, and server settings.
- Constraints / assumptions:
  - `ressources/` is read-only reference code; no mutable workflows are planned there.
  - Backend runtime work in `pixel-control-server/` remains deferred.
  - Multi-mode support is mandatory (no Elite-only assumptions in admin control logic).
  - Plugin/server contracts must stay explicit; docs must be updated if wire behavior changes.

## Objective And Non-goals

- Objective:
  - Deliver a complete capability audit and gap matrix.
  - Refactor plugin ownership boundaries so native server-control execution delegates to ManiaControl facilities.
  - Keep Pixel plugin focused on telemetry, transport resilience, and explicit contract mapping.
  - Ensure ManiaControl-native admin capabilities are controllable from Pixel plugin via a bounded, permission-gated control layer.
- Non-goals:
  - Implementing backend command ingestion/runtime in `pixel-control-server/`.
  - Modifying or extending code under `ressources/`.
  - Replacing ManiaControl core workflows with custom business logic in Pixel plugin.

## Delegation Boundary (Required)

- MUST remain in Pixel plugin:
  - Event normalization, telemetry enrichment, and envelope mapping (`event_name`, `event_id`, `idempotency_key`, metadata).
  - Queue/retry/outage behavior and async dispatch transport.
  - Contract/version governance and schema/document consistency.
  - Multi-mode-safe capability detection and fallback signaling in payloads/logs.
- MUST be delegated to ManiaControl native facilities:
  - Admin levels and permission enforcement (`AuthenticationManager`).
  - Map control and queue actions (`MapActions`, `MapManager`, `MapQueue`, `MapCommands`).
  - Pause/warmup controls (`ModeScriptEventManager`, `Server\Commands`, `ScriptManager` capability checks).
  - Player force/moderation actions (`PlayerActions`, `PlayerCommands`).
  - Vote cancellation/ratio mechanics (`Server\Commands`, `VoteRatiosMenu`, optional `CustomVotesPlugin` integration surface).
  - Dedicated server command execution wrappers (native client methods already used by ManiaControl core).

## Gap Matrix (Native ManiaControl capability vs Pixel plugin responsibility)

| Capability area | Native ManiaControl facilities (reference) | Current Pixel plugin state | Target ownership decision |
| --- | --- | --- | --- |
| Admin levels + rights | `core/Admin/AuthenticationManager.php`, `core/Admin/AuthCommands.php` | Telemetry only (`auth_level`, role labels) | Delegate execution/rights to native; keep telemetry in Pixel |
| Player force/moderation | `core/Players/PlayerActions.php`, `core/Players/PlayerCommands.php` | No control surface in Pixel | Delegate execution to native; Pixel exposes routed triggers + result telemetry |
| Map skip/restart/jump/queue | `core/Maps/MapActions.php`, `core/Maps/MapManager.php`, `core/Maps/MapQueue.php`, `core/Maps/MapCommands.php` | No control surface in Pixel | Delegate execution to native; Pixel only routes and reports |
| Warmup and pause controls | `core/Script/ModeScriptEventManager.php`, `core/Server/Commands.php`, `core/Script/ScriptManager.php` | Lifecycle telemetry only (`warmup.*`, `pause.*`) | Delegate control to native APIs; keep lifecycle/admin telemetry in Pixel |
| Vote cancellation/ratios/custom votes | `core/Server/Commands.php`, `core/Server/VoteRatiosMenu.php`, `plugins/MCTeam/CustomVotesPlugin.php` | No control surface in Pixel | Delegate to native components; add optional routing hooks in Pixel |
| Server/game-mode settings | `core/Server/Commands.php`, `core/Configurator/GameModeSettings.php` | Server snapshot telemetry only | Delegate setting mutation to native; Pixel logs/reroutes outcomes |
| Auth-level name mapping | `AuthenticationManager::getAuthLevelName()` | Local duplicated mapping in `PlayerDomainTrait` | Remove redundant local mapping; consume native naming source |
| Telemetry envelope + idempotency | `pixel-control-plugin/src/Domain/Pipeline/*` | Implemented | Keep in Pixel |
| Queue/retry/outage resilience | `pixel-control-plugin/src/Domain/Pipeline/*`, `src/Queue/*`, `src/Retry/*` | Implemented | Keep in Pixel |

## Steps

Execution rule: keep one active `[In progress]` step during execution. Do not execute mutable workflows under `ressources/`.

### Phase 0 - Current-state audit checklist

Dependencies: None.
Completion signal: `pixel-control-plugin/docs/audit/maniacontrol-admin-capability-audit-<date>.md` exists with evidence-backed findings and no unresolved inventory gaps.

- [Done] P0.1 Audit Pixel plugin control surfaces and redundancy baseline.
  - Verify absence/presence of `CommandListener`, `CommunicationListener`, and direct admin-control handlers in `pixel-control-plugin/src/`.
  - Record redundant logic candidates (for example local auth-name mapping in `pixel-control-plugin/src/Domain/Player/PlayerDomainTrait.php`).
- [Done] P0.2 Audit current plugin telemetry dependencies on admin/match-flow semantics.
  - Trace `admin_action`, permission signals, and lifecycle variants in `pixel-control-plugin/src/Domain/Lifecycle/LifecycleDomainTrait.php` and `pixel-control-plugin/src/Domain/Pipeline/PipelineDomainTrait.php`.
  - Confirm which payload fields depend on native ManiaControl state and must remain unchanged.
- [Done] P0.3 Produce audit artifact with reproducible references.
  - Capture file-level references to both plugin and ManiaControl reference implementation (`ressources/ManiaControl/**`) without modifying reference files.

### Phase 1 - ManiaControl capability inventory workstream

Dependencies: Phase 0.
Completion signal: Capability inventory table is complete and mapped to explicit native entry points plus permission mechanisms.

- [Done] P1.1 Inventory native auth/permission primitives.
  - Document `AuthenticationManager` auth levels, plugin permission settings (`definePluginPermissionLevel`), and command protection patterns.
- [Done] P1.2 Inventory native map and match-flow control primitives.
  - Document map actions/queue/commands (`MapActions`, `MapQueue`, `MapCommands`, `MapManager`) and warmup/pause primitives (`ModeScriptEventManager`, `Server\Commands`, `ScriptManager`).
- [Done] P1.3 Inventory native player-force and vote primitives.
  - Document `PlayerActions`/`PlayerCommands`, vote cancellation/ratio controls, and optional custom-vote plugin interactions.
- [Done] P1.4 Inventory native communication hooks usable by plugin.
  - Document available communication methods (`CommunicationMethods`) and safe in-process callback usage (`CommunicationManager`).

### Phase 2 - Gap decisions and final ownership matrix

Dependencies: Phase 1.
Completion signal: Final matrix assigns each capability to `Delegate`, `Keep`, or `Mixed`, with rationale and migration action.

- [Done] P2.1 Finalize capability-by-capability ownership decisions.
  - Mark each capability as delegated/native or retained/plugin-owned and justify the decision.
- [Done] P2.2 Define control-action catalog and permission model.
  - Freeze action identifiers, required parameters, and minimum auth levels.
  - Ensure action model is mode-safe (capability checks before execution).
- [Done] P2.3 Define backward-compatibility guardrails.
  - Explicitly list payload fields/event semantics that must remain stable.
  - Define what contract changes are additive vs out-of-scope for this refactor.

### Phase 3 - Refactor work packages (file-level implementation)

Dependencies: Phase 2.
Completion signal: Pixel plugin routes admin controls through native ManiaControl services with no duplicate business rules and explicit permission gating.

- [Done] P3.1 Add a native-admin delegation layer in plugin code.
  - Add `pixel-control-plugin/src/Domain/Admin/AdminControlDomainTrait.php` for control orchestration.
  - Add `pixel-control-plugin/src/Admin/AdminActionCatalog.php` to centralize action-to-native mapping.
  - Add `pixel-control-plugin/src/Admin/NativeAdminGateway.php` to call ManiaControl managers (MapActions, PlayerActions, ModeScriptEventManager, AuthenticationManager, client vote APIs).
  - Add `pixel-control-plugin/src/Admin/AdminActionResult.php` for normalized success/failure output.
- [Done] P3.2 Wire plugin entry points for controllability.
  - Update `pixel-control-plugin/src/PixelControlPlugin.php` to include admin-control domain wiring and required interfaces/properties.
  - Update `pixel-control-plugin/src/Domain/Core/CoreDomainTrait.php` to register/unregister control entry points (admin chat commands and/or communication hooks).
  - Add plugin permission settings via `AuthenticationManager::definePluginPermissionLevel` (no hardcoded bypass logic).
- [Done] P3.3 Implement delegated capability handlers (no reimplementation).
  - Map control actions to native calls for: map skip/restart/jump/queue, warmup extend/end, pause start/end/status-aware toggles, vote cancel, player force actions, auth-level grants/revokes.
  - Enforce mode/capability guards (for example `modeUsesPause`) before invoking mode-specific actions.
- [Done] P3.4 Remove or reduce redundant plugin logic.
  - Refactor `pixel-control-plugin/src/Domain/Player/PlayerDomainTrait.php` to use native auth-level naming sources instead of duplicated local mappings.
  - Remove dead/redundant helpers only after delegated path is verified.
- [Done] P3.5 Preserve telemetry/transport boundaries while adding control observability.
  - Keep queue/retry/outage and envelope identity logic unchanged in `pixel-control-plugin/src/Domain/Pipeline/PipelineDomainTrait.php` unless strictly required.
  - Add deterministic admin-control log markers (for example `[PixelControl][admin][action_requested|action_success|action_failed]`) for QA evidence.

### Phase 4 - Validation strategy (tests/manual checks/log markers)

Dependencies: Phase 3.
Completion signal: Validation matrix passes for permission gating, delegated execution, and telemetry stability.

- [Done] P4.1 Run static/syntax checks on all touched plugin PHP files.
  - Use PHP lint for changed files in `pixel-control-plugin/src/`.
- [Done] P4.2 Execute manual delegated-control matrix in local dev stack.
  - Validate map next/restart, warmup extend/end, pause controls, vote cancel, and player force commands through Pixel control entry points.
  - Verify each action is executed by native ManiaControl services (not by custom duplicated logic).
- [Done] P4.3 Validate permission model and denial paths.
  - Confirm unauthorized users receive deterministic denial responses.
  - Confirm auth-level boundaries align with configured permission settings.
- [Done] P4.4 Validate telemetry and transport regressions.
  - Ensure existing lifecycle/player/combat envelopes remain schema-compatible.
  - Ensure control actions do not introduce queue-identity regressions (`drop_identity_invalid` should not increase unexpectedly).
- [Done] P4.5 Validate multi-mode behavior.
  - Run checks in at least Elite + one non-Elite mode (for example Siege or Battle) and verify capability fallbacks for unsupported controls.

### Phase 5 - Documentation and contract updates

Dependencies: Phase 4.
Completion signal: Docs are synchronized and clearly separate delegated native control from plugin-owned telemetry/transport responsibilities.

- [Done] P5.1 Update `pixel-control-plugin/FEATURES.md`.
  - Document delegated native-admin control capabilities, permission model, and feature toggles.
- [Done] P5.2 Update `pixel-control-plugin/docs/event-contract.md` if payload/metadata semantics changed.
  - Keep additive-only contract evolution and explicit field-availability semantics.
- [Done] P5.3 Update `API_CONTRACT.md` only if plugin-server wire behavior changes.
  - If no wire change: add an explicit note that this refactor is execution-path delegation only.
- [Done] P5.4 Add a dedicated delegation audit document.
  - Add `pixel-control-plugin/docs/admin-capability-delegation.md` summarizing capability mapping, permission levels, and native call routing.

### Phase 6 - Risks, rollback strategy, and acceptance gates

Dependencies: Phase 5.
Completion signal: Rollback path is documented and acceptance criteria are met with evidence links.

- [Done] P6.1 Apply rollout safety controls.
  - Gate new control surface behind a plugin setting/env toggle for controlled activation.
  - Keep default behavior safe for environments that only need telemetry.
- [Done] P6.2 Validate rollback procedure.
  - Verify disabling the feature toggle cleanly reverts to telemetry-only behavior.
  - Document rollback steps and expected verification markers.
- [Done] P6.3 Close acceptance checklist with evidence references.
  - Link audit doc, validation matrix outputs, and updated contracts/features docs.

## Risks And Rollback Strategy

- Risk: command namespace collisions with existing ManiaControl chat commands.
  - Mitigation: use a dedicated namespaced command prefix and central action catalog.
- Risk: mode-specific unsupported actions (pause/warmup) produce inconsistent behavior.
  - Mitigation: capability checks before execution and explicit fallback responses.
- Risk: permission misconfiguration opens privileged actions.
  - Mitigation: enforce plugin permission definitions through `AuthenticationManager`; no bypass paths.
- Risk: telemetry contract drift while adding control observability.
  - Mitigation: additive-only changes; schema/event-catalog updates only when wire shape changes.
- Rollback: disable native-admin control via plugin setting/env toggle and keep telemetry pipeline active.

## Acceptance Criteria

- A complete audit artifact exists with native capability references and a finalized ownership matrix.
- Pixel plugin control actions route through ManiaControl native facilities (no duplicate business-rule reimplementation for admin operations).
- Existing telemetry/transport behavior remains stable (queue, retry, outage, identity validation).
- Permission gating is explicit, testable, and enforced for all privileged actions.
- Multi-mode behavior is validated with capability-aware fallbacks.
- Documentation is synchronized in `pixel-control-plugin/FEATURES.md`, `pixel-control-plugin/docs/event-contract.md`, and `API_CONTRACT.md` when applicable.

## Evidence / Artifacts

- Planned audit artifact: `pixel-control-plugin/docs/audit/maniacontrol-admin-capability-audit-<date>.md`
- Planned delegation matrix doc: `pixel-control-plugin/docs/admin-capability-delegation.md`
- Validation evidence directory (suggested): `pixel-sm-server/logs/qa/admin-delegation-<timestamp>/`

Resolved evidence set (2026-02-20):

- Audit and ownership matrix:
  - `pixel-control-plugin/docs/audit/maniacontrol-admin-capability-audit-2026-02-20.md`
- Delegation routing + rollback documentation:
  - `pixel-control-plugin/docs/admin-capability-delegation.md`
- Delegated action matrix + permission matrix + non-Elite fallback matrix:
  - `pixel-sm-server/logs/qa/admin-delegation-20260220-1926/communication-action-matrix.json`
  - `pixel-sm-server/logs/qa/admin-delegation-20260220-1926/permission-matrix.json`
  - `pixel-sm-server/logs/qa/admin-delegation-20260220-1926/joust-capability-matrix.json`
- Telemetry regression summary and acceptance closure:
  - `pixel-sm-server/logs/qa/admin-delegation-20260220-1926/telemetry-regression-summary.json`
  - `pixel-sm-server/logs/qa/admin-delegation-20260220-1926/acceptance-summary.md`
- Strict marker replay reference:
  - `pixel-sm-server/logs/qa/wave4-telemetry-20260220-193214-markers.json`
  - `pixel-sm-server/logs/qa/wave4-telemetry-20260220-193214-summary.md`
