# ManiaControl Native Admin Capability Audit (2026-02-20)

## Scope

- Audit target: `pixel-control-plugin/src/` current responsibilities vs ManiaControl native admin capabilities.
- Reference target: `ressources/ManiaControl/**` (read-only evidence only).
- Goal: define a strict boundary where Pixel plugin stays telemetry/transport adapter and delegates server-control execution to ManiaControl.

## Phase 0 Findings

### P0.1 Plugin control-surface baseline

Findings:

- No dedicated command/communication admin control entry points currently exist in Pixel plugin runtime wiring.
  - `PixelControl\PixelControlPlugin` currently implements only `CallbackListener`, `TimerListener`, `Plugin` (`pixel-control-plugin/src/PixelControlPlugin.php:22`).
  - `load()` wiring registers callback registry and timers only (`pixel-control-plugin/src/Domain/Core/CoreDomainTrait.php:63`, `pixel-control-plugin/src/Domain/Core/CoreDomainTrait.php:65`).
  - Callback registry only wires lifecycle/player/combat/mode callback listeners (`pixel-control-plugin/src/Callbacks/CallbackRegistry.php:78`, `pixel-control-plugin/src/Callbacks/CallbackRegistry.php:99`).
- Redundant auth-name mapping exists in plugin player telemetry path.
  - Local mapping methods duplicate native auth naming (`pixel-control-plugin/src/Domain/Player/PlayerDomainTrait.php:1070`, `pixel-control-plugin/src/Domain/Player/PlayerDomainTrait.php:1091`).
  - Snapshot builder uses those local methods (`pixel-control-plugin/src/Domain/Player/PlayerDomainTrait.php:194`, `pixel-control-plugin/src/Domain/Player/PlayerDomainTrait.php:208`).

### P0.2 Telemetry dependencies on admin/match-flow semantics

Findings:

- Lifecycle payloads already normalize and emit admin-action telemetry from native script/lifecycle callbacks.
  - Admin-action enrichment entrypoint (`pixel-control-plugin/src/Domain/Lifecycle/LifecycleDomainTrait.php:52`).
  - Normalized action metadata fields and actor/target context (`pixel-control-plugin/src/Domain/Lifecycle/LifecycleDomainTrait.php:108`, `pixel-control-plugin/src/Domain/Lifecycle/LifecycleDomainTrait.php:139`).
- Pipeline metadata derives `admin_action_*` values from lifecycle payloads and must remain stable.
  - Metadata extraction (`pixel-control-plugin/src/Domain/Pipeline/PipelineDomainTrait.php:67`, `pixel-control-plugin/src/Domain/Pipeline/PipelineDomainTrait.php:81`).
- Player-transition correlation depends on recent lifecycle admin-action contexts.
  - Admin context tracking (`pixel-control-plugin/src/Domain/Lifecycle/LifecycleDomainTrait.php:475`).
  - Player admin correlation window (`pixel-control-plugin/src/Domain/Player/PlayerDomainTrait.php:948`, `pixel-control-plugin/src/Domain/Player/PlayerDomainTrait.php:1020`).
- Match/veto inference currently consumes `admin_action` semantics and should not regress.
  - Inferred lock fallback sourced from `admin_action` (`pixel-control-plugin/src/Domain/Match/MatchDomainTrait.php:805`, `pixel-control-plugin/src/Domain/Match/MatchDomainTrait.php:810`).

### P0.3 Evidence completeness check

- Inventory includes plugin-side runtime wiring, lifecycle/pipeline/player telemetry coupling, and all native capability families required by the plan.
- No unresolved audit gaps remain for delegation design.

## Phase 1 Native Capability Inventory

### P1.1 Auth and permission primitives

Native evidence:

- Auth levels and naming source of truth:
  - `AUTH_LEVEL_*` / `AUTH_NAME_*` (`ressources/ManiaControl/core/Admin/AuthenticationManager.php:34`, `ressources/ManiaControl/core/Admin/AuthenticationManager.php:43`).
  - `getAuthLevelName()` (`ressources/ManiaControl/core/Admin/AuthenticationManager.php:126`).
- Permission enforcement APIs:
  - `checkPermission()` (`ressources/ManiaControl/core/Admin/AuthenticationManager.php:426`).
  - `checkPluginPermission()` (`ressources/ManiaControl/core/Admin/AuthenticationManager.php:440`).
  - `definePluginPermissionLevel()` (`ressources/ManiaControl/core/Admin/AuthenticationManager.php:466`).
- Native command protection pattern:
  - rights hierarchy checks in auth commands (`ressources/ManiaControl/core/Admin/AuthCommands.php:52`, `ressources/ManiaControl/core/Admin/AuthCommands.php:167`).

### P1.2 Map and match-flow control primitives

Native evidence:

- Map control execution:
  - `MapActions::skipMap/restartMap/skipToMapByUid/skipToMapByMxId` (`ressources/ManiaControl/core/Maps/MapActions.php:71`, `ressources/ManiaControl/core/Maps/MapActions.php:106`, `ressources/ManiaControl/core/Maps/MapActions.php:130`).
  - Access via `MapManager::getMapActions()` (`ressources/ManiaControl/core/Maps/MapManager.php:153`).
- Map queue control:
  - `MapQueue::serverAddMapToMapQueue` (`ressources/ManiaControl/core/Maps/MapQueue.php:252`).
  - `MapQueue::clearMapQueue` (`ressources/ManiaControl/core/Maps/MapQueue.php:116`).
  - Access via `MapManager::getMapQueue()` (`ressources/ManiaControl/core/Maps/MapManager.php:333`).
- Warmup and pause script controls:
  - `ModeScriptEventManager::extendManiaPlanetWarmup` (`ressources/ManiaControl/core/Script/ModeScriptEventManager.php:310`).
  - `ModeScriptEventManager::stopManiaPlanetWarmup` (`ressources/ManiaControl/core/Script/ModeScriptEventManager.php:319`).
  - `ModeScriptEventManager::startPause/endPause/getPauseStatus` (`ressources/ManiaControl/core/Script/ModeScriptEventManager.php:360`, `ressources/ManiaControl/core/Script/ModeScriptEventManager.php:372`, `ressources/ManiaControl/core/Script/ModeScriptEventManager.php:384`).
  - Pause support detection via `ScriptManager::modeUsesPause` (`ressources/ManiaControl/core/Script/ScriptManager.php:139`).

### P1.3 Player-force and vote primitives

Native evidence:

- Player control execution:
  - `PlayerActions::forcePlayerToTeam/forcePlayerToPlay/forcePlayerToSpectator` (`ressources/ManiaControl/core/Players/PlayerActions.php:165`, `ressources/ManiaControl/core/Players/PlayerActions.php:249`, `ressources/ManiaControl/core/Players/PlayerActions.php:317`).
  - Native auth grant/revoke through player actions (`ressources/ManiaControl/core/Players/PlayerActions.php:755`, `ressources/ManiaControl/core/Players/PlayerActions.php:796`).
- Native command wrappers with permission checks:
  - `PlayerCommands` force commands (`ressources/ManiaControl/core/Players/PlayerCommands.php:241`, `ressources/ManiaControl/core/Players/PlayerCommands.php:304`).
- Vote control:
  - cancel vote command path (`ressources/ManiaControl/core/Server/Commands.php:161`, `ressources/ManiaControl/core/Server/Commands.php:167`).
  - vote-ratio mutation via dedicated client (`ressources/ManiaControl/core/Server/VoteRatiosMenu.php:157`).
- Optional custom vote extension:
  - `MCTeam\CustomVotesPlugin::startVote` and predefined vote mappings (`ressources/ManiaControl/plugins/MCTeam/CustomVotesPlugin.php:166`, `ressources/ManiaControl/plugins/MCTeam/CustomVotesPlugin.php:421`).

### P1.4 Communication hooks for plugin integration

Native evidence:

- Communication method catalog (`ressources/ManiaControl/core/Communication/CommunicationMethods.php:12`).
- In-process registration and callback dispatch:
  - `registerCommunicationListener` (`ressources/ManiaControl/core/Communication/CommunicationManager.php:120`).
  - `triggerCommuncationCallback` (`ressources/ManiaControl/core/Communication/CommunicationManager.php:142`).

## Phase 2 Ownership Decisions

### Final capability ownership matrix

| Capability | Decision | Rationale | Migration action |
| --- | --- | --- | --- |
| Admin auth levels + rights | Delegate | Native auth model and hierarchy are already canonical in `AuthenticationManager` | Pixel defines plugin permission settings and calls native checks |
| Player force/moderation (team/spec/play) | Delegate | `PlayerActions` already enforces native constraints and announces outcomes | Pixel routes action requests to `PlayerActions` only |
| Map skip/restart/jump/queue | Delegate | `MapActions`/`MapQueue` already encapsulate map transition and queue semantics | Pixel maps action identifiers to native map APIs |
| Warmup/pause controls | Mixed | Execution is native; Pixel must guard capability/mode and request semantics | Pixel validates mode support and delegates to script event manager |
| Vote cancel/ratio | Mixed | Vote mechanics are native; Pixel provides bounded trigger surface and parameter validation | Pixel calls native vote APIs and returns normalized results |
| Optional custom vote start | Mixed | Plugin may exist or not depending on runtime | Pixel uses optional plugin lookup and reports unavailable capability deterministically |
| Auth-level telemetry naming | Delegate | Local mapping duplicates native source of truth | Pixel uses `AuthenticationManager::getAuthLevelName()` for `auth_name` |
| Envelope mapping/idempotency/queue/retry/outage | Keep | This is Pixel’s transport/contract responsibility | No behavior changes except additive admin-control observability |

### Control-action catalog (frozen)

Action IDs to expose through Pixel admin-control entry points:

- `map.skip` (no params)
- `map.restart` (no params)
- `map.jump` (`map_uid` or `mx_id`)
- `map.queue` (`map_uid` or `mx_id`)
- `warmup.extend` (`seconds`)
- `warmup.end` (no params)
- `pause.start` (no params)
- `pause.end` (no params)
- `vote.cancel` (no params)
- `vote.set_ratio` (`command`, `ratio`)
- `player.force_team` (`target_login`, `team`)
- `player.force_play` (`target_login`)
- `player.force_spec` (`target_login`)
- `auth.grant` (`target_login`, `auth_level`)
- `auth.revoke` (`target_login`)
- `vote.custom_start` (`vote_index`, optional; only when `MCTeam\\CustomVotesPlugin` is active)

### Backward-compatibility guardrails

Must remain unchanged in this refactor:

- Envelope identity semantics (`event_name`, `event_id`, `idempotency_key`) and identity-drop behavior.
- Queue/retry/outage counters and marker names.
- Existing lifecycle/player/combat payload shapes and existing metadata keys.
- Existing admin-action telemetry extraction from lifecycle callbacks.

Allowed additive changes:

- Connectivity capability flags describing admin-control delegation support/toggle state.
- Operational log markers for delegated control requests and outcomes.

Out-of-scope for this refactor:

- Backend runtime command ingestion in `pixel-control-server/`.
- Any mutable changes under `ressources/`.
