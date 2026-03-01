# PLAN - Elite Enrichment: Plugin Cleanup + Deep Elite-Specific Telemetry (2026-03-01)

## Context

- **Purpose**: (1) Strip the plugin down to Elite-only telemetry by removing five unused subsystems (~12,800 lines), then (2) enrich all combat events with Elite turn context, emit consolidated turn summaries, detect clutch situations, and expose new API endpoints. This produces a lean, focused codebase optimized for competitive Elite play.
- **Scope**: Plugin-side (PHP 7.4 trait architecture) and API-side (NestJS/Prisma/Vitest). Cleanup phases remove non-Elite subsystems first; enrichment phases build on the clean codebase.
- **Background / Findings**:
  - The plugin has ~20,016 lines but only ~34% is telemetry-relevant for Elite mode. Five subsystems are dead weight: VetoDraft (~5,400 lines), Admin Control (~3,701 lines), Access Control + Vote Policy (~1,632 lines), Series Control (~832 lines), Team Control (~1,247 lines), plus ~28 lines of minor dead code.
  - `EliteRoundTrackingTrait.php` already tracks `$eliteRoundActive`, attacker login, defender logins, and per-round hits/deaths in `PlayerCombatStatsStore.php`. The store's `openEliteRound()` / `closeEliteRound()` lifecycle is well-defined.
  - `CombatDomainTrait.php` builds combat payloads via `buildCombatPayload()` and calls `updateCombatStatsCounters()` per callback. No Elite context is injected into the event payload today.
  - `PipelineDomainTrait.php` enqueues events via `enqueueEnvelope()` with category + sourceCallback. Combat metadata currently only sets `stats_snapshot => 'player_combat_runtime'`.
  - The API's `CombatService` is a P1 placeholder (debug log only); actual stats reading is in `StatsReadService` which extracts `player_counters` from raw combat event payloads. The P2 read API on branch `feat/p2-read-api` has full per-map, per-series, and per-player endpoints.
  - Turn number is NOT currently tracked anywhere. Must be added as a new counter in `PlayerCombatStatsStore`.
  - `OnEliteStartTurnStructure` provides: attacker (Player), defender logins. `OnEliteEndTurnStructure` provides: victoryType.
  - Victory types: 1=time_limit, 2=capture, 3=attacker_eliminated, 4=defenders_eliminated.
  - **Subsystem dependency chain** (verified from source):
    - `TeamControlDomainTrait` imports `AdminActionCatalog` and `AdminActionResult` from Admin, so Admin must be removed before or simultaneously with TeamControl.
    - `LifecycleDomainTrait.buildLifecyclePayload()` calls `observePauseStateFromLifecycle()` (defined in `AdminControlIngressTrait`) and `buildAdminActionPayload()` (defined in `LifecycleDomainTrait` itself but depends on `resolveAdminActionDefinition()` -- also in `LifecycleDomainTrait`, so self-contained).
    - `MatchVetoRotationTrait.buildLifecycleMapRotationTelemetry()` calls `resolveAuthoritativeVetoDraftSnapshots()` (VetoDraft), `getSeriesControlSnapshot()` (SeriesControl), `buildMatchmakingLifecycleStatusSnapshot()` (VetoDraft), and reads `$vetoDraftMatchmakingReadyArmed` (VetoDraft state).
    - `ConnectivityDomainTrait.buildCapabilitiesPayload()` calls `buildAdminControlCapabilitiesPayload()` (Admin).
    - `CoreDomainTrait` is the central dispatch hub with explicit call sites for all subsystems.
  - **Test file impact**: `10StateModuleTest.php` tests WhitelistState, SeriesControlState, TeamRosterState, VotePolicyState (all being removed). `11VetoSessionStateTest.php` tests VetoDraft. `21AdminLinkAuthTest.php` tests Admin. `31AccessControlSourceOfTruthTest.php` tests AccessControl. `30OrchestrationSeamTest.php` tests VetoDraft/Series orchestration. All five test files must be deleted. Test harnesses in `Harnesses.php` and `Fakes.php` have classes for removed subsystems that must also be cleaned up.
  - `resolveTeamAssignmentForLogin()` in `MatchAggregateTelemetryTrait` uses ManiaControl's player manager directly (NOT the TeamControl subsystem), so aggregate telemetry is NOT affected by TeamControl removal.
- **Goals**:
  - Remove ~12,800 lines of non-Elite code across 5 subsystems.
  - Every combat event during an Elite turn carries `elite_context` in its payload (nullable for non-Elite).
  - A new `elite_turn_summary` event is emitted at end of each turn with consolidated per-turn stats, outcome, duration, and map context.
  - Clutch detection: identify when a single remaining defender wins the round.
  - API exposes new endpoints to query per-turn data, including clutch stats.
  - All existing server smoke tests continue to pass after cleanup.
- **Non-goals**:
  - "First blood" detection (explicitly excluded).
  - Changes to non-Elite game modes (we are removing non-Elite mode support).
  - Breaking changes to existing event envelope schema.
  - Changes to the ingestion pipeline (POST /v1/plugin/events already handles all categories).
- **Constraints / assumptions**:
  - PHP 7.4+ compatibility required (no typed properties, no union types, no enums).
  - Envelope schema `2026-02-20.1` -- new fields are additive only. `elite_context` is null/absent for non-Elite events.
  - Plugin trait architecture: new logic goes in existing traits or new traits, composed into `PixelControlPlugin.php`.
  - Mode detection uses `isEliteModeActive()` (env var `PIXEL_SM_MODE`). After Cleanup 6 the guard is removed (always Elite).
  - All Swagger/OpenAPI decorators mandatory on new NestJS routes.
  - Vitest for server tests, PHP CLI harness for plugin tests.
  - Server-side code is NOT affected by plugin cleanup phases (server already handles all event categories generically).
- **Environment snapshot**:
  - Branch: `feat/p2-read-api` (active development).
  - P0 + P1 merged to `main`. P2 read API in progress on feature branch.
  - Plugin already tracks per-round hits/rocket_hits/deaths but does NOT reset per-turn shots/misses/kills.
- **Risks / open questions**:
  - Q1: Should the `elite_turn_summary` event use category `combat` (reuses existing pipeline) or a new `elite` category? **Recommendation**: use `combat` category with a distinctive `event_kind: elite_turn_summary` to avoid modifying the allowed-categories whitelist in `validateEnvelopeIdentity()`.
  - Q2: Per-turn stats tracking requires resetting additional counters (shots, misses, kills) at turn start -- currently only hits/rocket_hits/deaths are tracked per-round. Need to expand the tracking arrays in `PlayerCombatStatsStore`.
  - Q3: The `elite_context.phase` field ("attack" or "defense" relative to the event's player) requires knowing which player the event relates to. For `onshoot`/`onhit`/`onnearmiss`, the shooter is the relevant player; for `onarmorempty`, both shooter and victim have distinct phases.
  - Q4: Five PHP test files (`10StateModuleTest.php`, `11VetoSessionStateTest.php`, `21AdminLinkAuthTest.php`, `30OrchestrationSeamTest.php`, `31AccessControlSourceOfTruthTest.php`) and their harness support classes must be deleted during cleanup. The `00HarnessSmokeTest.php` and `20IngressNormalizationTest.php` files should be checked for dependencies on removed classes.

---

## Steps

- [Done] Phase 1 - Remove VetoDraft subsystem (~5,400 lines)
- [Done] Phase 2 - Remove Admin Control subsystem (~3,701 lines)
- [Done] Phase 3 - Remove Access Control + Vote Policy (~1,632 lines)
- [Done] Phase 4 - Remove Series Control (~832 lines)
- [Done] Phase 5 - Remove Team Control (~1,247 lines)
- [Done] Phase 6 - Minor dead code removal + test cleanup
- [Done] Phase 7 - Enrich combat events with Elite turn context (Priority 1)
- [Done] Phase 8 - Emit `elite_turn_summary` event at turn end (Priority 2)
- [Done] Phase 9 - Clutch detection (Priority 3)
- [Done] Phase 10 - API-side Elite turn endpoints
- [Done] Phase 11 - Plugin PHP QA
- [Done] Phase 12 - Server unit tests
- [Done] Phase 13 - Smoke test regression suite
- [Done] Phase 14 - New Elite enrichment smoke test

---

### Phase 1 - Remove VetoDraft subsystem

**Goal**: Delete all VetoDraft source files, domain traits, and remove all VetoDraft call sites from the core dispatch and lifecycle layers. Preserve map pool/history tracking in `MatchVetoRotationTrait.php`.

- [Todo] P1.1 - Delete all VetoDraft source files
  - Delete directory: `pixel-control-plugin/src/VetoDraft/` (10 files):
    - `ManiaControlMapRuntimeAdapter.php`
    - `MapPoolService.php`
    - `MapRuntimeAdapterInterface.php`
    - `MatchmakingLifecycleCatalog.php`
    - `MatchmakingVoteSession.php`
    - `TournamentDraftSession.php`
    - `TournamentSequenceBuilder.php`
    - `VetoDraftCatalog.php`
    - `VetoDraftCoordinator.php`
    - `VetoDraftQueueApplier.php`
  - Delete directory: `pixel-control-plugin/src/Domain/VetoDraft/` (5 files):
    - `VetoDraftDomainTrait.php`
    - `VetoDraftBootstrapTrait.php`
    - `VetoDraftIngressTrait.php`
    - `VetoDraftLifecycleTrait.php`
    - `VetoDraftAutostartTrait.php`

- [Todo] P1.2 - Strip veto-specific logic from `MatchVetoRotationTrait.php`
  - File: `pixel-control-plugin/src/Domain/Match/MatchVetoRotationTrait.php`
  - **DELETE** the following methods entirely:
    - `resetVetoDraftActions()` (lines 9-12)
    - `recordVetoDraftActionFromLifecycle()` (lines 15-99)
    - `normalizeVetoActionKind()` (lines 102-117)
    - `buildVetoDraftActionSnapshot()` (lines 120-150)
    - `buildVetoResultSnapshot()` (lines 153-205)
  - **MODIFY** `buildLifecycleMapRotationTelemetry()` (lines 208-312):
    - Remove the `$vetoDraftActions = $this->buildVetoDraftActionSnapshot(...)` call (line 247).
    - Remove the `$vetoResult = $this->buildVetoResultSnapshot(...)` call (line 248).
    - Remove `$vetoDraftMode` variable and its assignment (line 249).
    - Remove `$vetoDraftSessionStatus` variable and its assignment (line 250).
    - Remove `$seriesTargets = $this->getSeriesControlSnapshot()` call (line 251) -- replaced in Phase 4 cleanup.
    - Remove `$matchmakingLifecycle = $this->buildMatchmakingLifecycleStatusSnapshot()` call (line 252).
    - Remove `$matchmakingReadyArmed = (bool) $this->vetoDraftMatchmakingReadyArmed` (line 253).
    - Remove the entire `$authoritativeVetoSnapshots` block (lines 255-267).
    - Remove from `$fieldAvailability`: `veto_draft_actions`, `veto_result`, `veto_draft_mode`, `veto_draft_session_status`, `matchmaking_ready_armed`, `matchmaking_lifecycle` keys.
    - Remove from the return array: `veto_draft_mode`, `veto_draft_session_status`, `matchmaking_ready_armed`, `veto_draft_actions`, `veto_result`, `matchmaking_lifecycle` keys.
    - **KEEP**: `$currentMapSnapshot`, `$mapPool`, `$currentMapIndex`, `$nextMaps`, `$this->playedMapHistory`, and all map pool/rotation methods (`buildMapPoolSnapshot`, `buildMapIdentityFromObject`, `recordPlayedMapOrderEntry`).
  - After this step the method returns a simplified map rotation telemetry object with: `variant`, `map_pool_size`, `current_map`, `current_map_index`, `next_maps`, `map_pool`, `played_map_count`, `played_map_order`, `field_availability`, `missing_fields`.

- [Todo] P1.3 - Remove VetoDraft dispatch calls from `CoreDomainTrait.php`
  - File: `pixel-control-plugin/src/Domain/Core/CoreDomainTrait.php`
  - In `load()` method: remove `$this->initializeVetoDraftFeature()` call (line 51).
  - In `unload()` method: remove `$this->unregisterVetoDraftEntryPoints()` call (line 75).
  - In `unload()` method: remove all VetoDraft state resets (lines 117-139: `$this->vetoDraftActions` through `$this->vetoDraftMatchmakingLifecycleLastSnapshot`).
  - In `handleLifecycleCallback()`: remove `$this->handleMatchmakingLifecycleFromCallback($callbackArguments)` call (line 149).
  - In `initializeSettings()`: remove `$this->initializeVetoDraftSettings()` call (line 219).
  - In `initializeEventPipeline()`: remove VetoDraft state resets (lines 275-276: `$this->vetoDraftActions = array()`, `$this->vetoDraftActionSequence = 0`).
  - In `registerPeriodicTimers()`: remove `$timerManager->registerTimerListening($this, 'handleVetoDraftTimerTick', 1000)` call (line 302) and update the log message (line 304) to remove `veto_tick=1s`.

- [Todo] P1.4 - Remove VetoDraft from `LifecycleDomainTrait.php`
  - File: `pixel-control-plugin/src/Domain/Lifecycle/LifecycleDomainTrait.php`
  - In `buildLifecyclePayload()`: remove `$this->resetVetoDraftActions()` call inside the `if ($variant === 'match.begin')` block (lines 15-17).
  - In `buildLifecyclePayload()`: remove `$this->recordVetoDraftActionFromLifecycle(...)` call (line 37).

- [Todo] P1.5 - Remove VetoDraft from `PixelControlPlugin.php`
  - File: `pixel-control-plugin/src/PixelControlPlugin.php`
  - Remove `use` trait import: `use VetoDraftDomainTrait;` (line 51).
  - Remove `use` imports at file top:
    - `use PixelControl\Domain\VetoDraft\VetoDraftDomainTrait;` (line 30)
    - `use PixelControl\VetoDraft\MapPoolService;` (line 33)
    - `use PixelControl\VetoDraft\VetoDraftCoordinator;` (line 34)
    - `use PixelControl\VetoDraft\VetoDraftQueueApplier;` (line 35)
  - Remove all VetoDraft settings constants:
    - `SETTING_VETO_DRAFT_ENABLED` through `SETTING_VETO_DRAFT_LAUNCH_IMMEDIATELY` (lines 81-88).
  - Remove all VetoDraft instance properties:
    - `$vetoDraftActions` through `$vetoDraftMatchmakingLifecycleHistoryLimit` (lines 226-275).

- [Todo] P1.6 - Verify PHP syntax after VetoDraft removal
  - Run: `php -l pixel-control-plugin/src/PixelControlPlugin.php`
  - Run: `php -l pixel-control-plugin/src/Domain/Match/MatchVetoRotationTrait.php`
  - Run: `php -l pixel-control-plugin/src/Domain/Core/CoreDomainTrait.php`
  - Run: `php -l pixel-control-plugin/src/Domain/Lifecycle/LifecycleDomainTrait.php`
  - All must report "No syntax errors detected".

---

### Phase 2 - Remove Admin Control subsystem

**Goal**: Delete all Admin source files, domain traits, and remove all Admin call sites from lifecycle/pipeline/connectivity layers. The `buildAdminActionPayload()` and related methods in `LifecycleDomainTrait.php` are fully self-contained there -- they depend on `resolveAdminActionDefinition()` (also in the same trait) and generic helpers. They produce `admin_action` metadata for lifecycle events describing warmup/pause/round/map/match boundary actions. **These are NOT part of the NativeAdminGateway subsystem** (which provides the `//pcadmin` command and active admin control features). We keep the lifecycle admin action enrichment but delete the NativeAdminGateway and its domain traits.

- [Todo] P2.1 - Delete Admin source files
  - Delete files in `pixel-control-plugin/src/Admin/`:
    - `NativeAdminGateway.php` (~66,347 bytes)
    - `AdminActionCatalog.php` (~19,388 bytes)
    - `AdminActionResult.php` (~1,620 bytes)
  - Delete directory: `pixel-control-plugin/src/Domain/Admin/` (4 files):
    - `AdminControlDomainTrait.php` (aggregator)
    - `AdminControlBootstrapTrait.php` (~4,384 bytes)
    - `AdminControlExecutionTrait.php` (~20,484 bytes)
    - `AdminControlIngressTrait.php` (~31,086 bytes -- contains `observePauseStateFromLifecycle()` and `buildAdminControlCapabilitiesPayload()`)

- [Todo] P2.2 - Handle `observePauseStateFromLifecycle()` migration
  - This method is defined in `AdminControlIngressTrait.php` (line 883) but called from `LifecycleDomainTrait.php` (line 13 of `buildLifecyclePayload()`).
  - **Approach**: Remove the call `$this->observePauseStateFromLifecycle($variant, $callbackArguments)` from `LifecycleDomainTrait.php` line 13. The pause state tracking was used by the Admin Control subsystem; with Admin removed, it serves no purpose.
  - Also remove the `$this->adminControlPauseActive`, `$this->adminControlPauseObservedAt` property references from the `unload()` method in `CoreDomainTrait.php` (already done as part of state cleanup).

- [Todo] P2.3 - Handle `buildAdminControlCapabilitiesPayload()` in connectivity
  - File: `pixel-control-plugin/src/Domain/Connectivity/ConnectivityDomainTrait.php`
  - In `buildCapabilitiesPayload()` (line 80): remove or replace the line `'admin_control' => $this->buildAdminControlCapabilitiesPayload()`.
  - **Approach**: Remove the `'admin_control'` key entirely from the capabilities payload. The admin control feature no longer exists.

- [Todo] P2.4 - Remove Admin metadata from `PipelineDomainTrait.php`
  - File: `pixel-control-plugin/src/Domain/Pipeline/PipelineDomainTrait.php`
  - In `buildEnvelopeMetadata()`, inside the `if ($eventCategory === 'lifecycle')` block (lines 58-74):
    - Remove the entire admin action metadata block that sets `$adminAction` and the `if ($adminAction !== null)` block that writes `metadata['admin_action_name']`, `metadata['admin_action_target']`, `metadata['admin_action_domain']`, `metadata['admin_action_type']`, `metadata['admin_action_phase']`, `metadata['admin_action_target_scope']`, `metadata['admin_action_target_id']`, `metadata['admin_action_initiator_kind']`.
    - Note: The `buildAdminActionPayload()` method itself stays in `LifecycleDomainTrait.php` since it provides the `admin_action` field in the lifecycle payload (which describes lifecycle boundary actions like warmup/pause/round transitions, NOT the `//pcadmin` NativeAdminGateway features). Wait -- **re-examine this**: `buildAdminActionPayload()` calls `resolveAdminActionDefinition()` which recognizes warmup/pause/round/map/match callbacks and labels them as admin actions. This is actually lifecycle enrichment data, not NativeAdminGateway.
    - **Decision**: We KEEP `buildAdminActionPayload()` and `resolveAdminActionDefinition()` in `LifecycleDomainTrait.php` (they describe lifecycle boundary events, not admin commands). We ALSO keep the metadata keys in `PipelineDomainTrait.php` since they describe the same lifecycle boundaries. HOWEVER, the `$adminAction` fallback on line 62 calls `$this->buildAdminActionPayload($sourceCallback, array())` -- this is a redundant re-computation. We should only use the `$adminAction` from the payload.
    - **Final approach**: Keep the admin metadata block as-is in `PipelineDomainTrait.php` since it describes lifecycle boundary metadata (not NativeAdminGateway). The data comes from `LifecycleDomainTrait.buildAdminActionPayload()` which is self-contained.

- [Todo] P2.5 - Remove Admin dispatch calls from `CoreDomainTrait.php`
  - File: `pixel-control-plugin/src/Domain/Core/CoreDomainTrait.php`
  - In `load()`: remove `$this->initializeAdminDelegationLayer()` call (line 50).
  - In `unload()`: remove `$this->unregisterAdminControlEntryPoints()` call (line 74).
  - In `unload()`: remove Admin state resets:
    - `$this->nativeAdminGateway = null;` (line 77)
    - `$this->adminControlEnabled = false;` (line 92)
    - `$this->adminControlCommandName = 'pcadmin';` (line 93)
    - `$this->adminControlPauseActive = null;` (line 94)
    - `$this->adminControlPauseObservedAt = 0;` (line 95)
    - `$this->adminControlPauseStateMaxAgeSeconds = 120;` (line 96)
    - `$this->recentAdminActionContexts = array();` (line 103)
  - In `initializeSettings()`: remove `$this->initializeAdminControlSettings()` call (line 218).
  - In `initializeEventPipeline()`: remove `$this->recentAdminActionContexts = array();` (line 261).

- [Todo] P2.6 - Remove `trackRecentAdminActionContext` and related methods from `LifecycleDomainTrait.php`
  - File: `pixel-control-plugin/src/Domain/Lifecycle/LifecycleDomainTrait.php`
  - Delete methods:
    - `trackRecentAdminActionContext()` (lines 455-488)
    - `pruneRecentAdminActionContexts()` (lines 490-511)
  - These tracked admin action correlation for player events. With admin control removed, this correlation is no longer needed.

- [Todo] P2.7 - Remove admin tracking from `PipelineDomainTrait.php`
  - File: `pixel-control-plugin/src/Domain/Pipeline/PipelineDomainTrait.php`
  - In `queueCallbackEvent()` (line 21-23): remove the `if ($eventCategory === 'lifecycle')` block that calls `$this->trackRecentAdminActionContext(...)`.

- [Todo] P2.8 - Remove Admin from `PixelControlPlugin.php`
  - File: `pixel-control-plugin/src/PixelControlPlugin.php`
  - Remove `use` trait import: `use AdminControlDomainTrait;` (line 41).
  - Remove `use` imports at file top:
    - `use PixelControl\Admin\NativeAdminGateway;` (line 11)
    - `use PixelControl\Domain\Admin\AdminControlDomainTrait;` (line 15)
  - Remove Admin settings constants:
    - `SETTING_ADMIN_CONTROL_ENABLED` (line 78)
    - `SETTING_ADMIN_CONTROL_COMMAND` (line 79)
    - `SETTING_ADMIN_CONTROL_PAUSE_STATE_MAX_AGE_SECONDS` (line 80)
  - Remove Admin instance properties:
    - `$nativeAdminGateway` (line 117)
    - `$adminControlEnabled` (line 154)
    - `$adminControlCommandName` (line 156)
    - `$adminControlPauseActive` (line 158)
    - `$adminControlPauseObservedAt` (line 160)
    - `$adminControlPauseStateMaxAgeSeconds` (line 162)
    - `$recentAdminActionContexts` (line 188)
    - `$adminCorrelationWindowSeconds` (line 190)
    - `$adminCorrelationHistoryLimit` (line 192)

- [Todo] P2.9 - Verify PHP syntax after Admin removal
  - Run `php -l` on all modified files:
    - `pixel-control-plugin/src/PixelControlPlugin.php`
    - `pixel-control-plugin/src/Domain/Core/CoreDomainTrait.php`
    - `pixel-control-plugin/src/Domain/Lifecycle/LifecycleDomainTrait.php`
    - `pixel-control-plugin/src/Domain/Pipeline/PipelineDomainTrait.php`
    - `pixel-control-plugin/src/Domain/Connectivity/ConnectivityDomainTrait.php`
  - All must report "No syntax errors detected".

---

### Phase 3 - Remove Access Control + Vote Policy

**Goal**: Delete all AccessControl and VoteControl source files and their domain trait, remove all call sites from `CoreDomainTrait`.

- [Todo] P3.1 - Delete Access Control + Vote Control source files
  - Delete files in `pixel-control-plugin/src/AccessControl/`:
    - `WhitelistState.php`
    - `WhitelistCatalog.php`
    - `WhitelistStateInterface.php`
  - Delete files in `pixel-control-plugin/src/VoteControl/`:
    - `VotePolicyState.php`
    - `VotePolicyCatalog.php`
    - `VotePolicyStateInterface.php`
  - Delete file: `pixel-control-plugin/src/Domain/AccessControl/AccessControlDomainTrait.php`
  - Delete the now-empty directories: `src/AccessControl/`, `src/VoteControl/`, `src/Domain/AccessControl/`.

- [Todo] P3.2 - Remove Access Control dispatch calls from `CoreDomainTrait.php`
  - File: `pixel-control-plugin/src/Domain/Core/CoreDomainTrait.php`
  - In `load()`: remove `$this->initializeAccessControlState()` call (line 48).
  - In `handlePlayerCallback()`: remove `$this->handleAccessControlPlayerCallback($callbackArguments)` call (line 154).
  - In `handleVoteCallback()`: remove `$this->handleVotePolicyCallback($callbackArguments)` call (line 160). This leaves `handleVoteCallback()` with an empty body -- keep the method signature (vote callbacks still get registered by `CallbackRegistry`), but the body becomes empty (just return).
  - In `handleDispatchTimerTick()`: remove `$this->handleAccessControlPolicyTick()` call (line 188).
  - In `handleHeartbeatTimerTick()`: remove `$this->handleAccessControlPolicyTick()` call (line 194).
  - In `unload()`: remove Access Control/Vote Policy state resets:
    - `$this->whitelistState = null;` (line 79)
    - `$this->votePolicyState = null;` (line 80)
    - `$this->whitelistRecentDeniedAt = array();` (line 81)
    - `$this->whitelistLastReconcileAt = 0;` (line 82)
    - `$this->whitelistGuestListLastSyncHash = '';` (line 83)
    - `$this->whitelistGuestListLastSyncAt = 0;` (line 84)
    - `$this->votePolicyLastCallVoteTimeoutMs = 0;` (line 85)
    - `$this->votePolicyStrictRuntimeApplied = false;` (line 86)
  - In `initializeSettings()`: remove `$this->initializeAccessControlSettings()` call (line 216).

- [Todo] P3.3 - Remove Access Control from `PixelControlPlugin.php`
  - File: `pixel-control-plugin/src/PixelControlPlugin.php`
  - Remove `use` trait import: `use AccessControlDomainTrait;` (line 40).
  - Remove `use` imports at file top:
    - `use PixelControl\AccessControl\WhitelistStateInterface;` (line 12)
    - `use PixelControl\Domain\AccessControl\AccessControlDomainTrait;` (line 15)
    - `use PixelControl\VoteControl\VotePolicyStateInterface;` (line 36)
  - Remove settings constants:
    - `SETTING_WHITELIST_ENABLED` (line 72)
    - `SETTING_WHITELIST_LOGINS` (line 73)
    - `SETTING_VOTE_POLICY_MODE` (line 74)
  - Remove instance properties:
    - `$whitelistState` (line 119)
    - `$votePolicyState` (line 120)
    - `$whitelistRecentDeniedAt` (line 124)
    - `$whitelistDenyCooldownSeconds` (line 126)
    - `$whitelistReconcileIntervalSeconds` (line 128)
    - `$whitelistLastReconcileAt` (line 130)
    - `$whitelistGuestListLastSyncHash` (line 132)
    - `$whitelistGuestListLastSyncAt` (line 134)
    - `$votePolicyLastCallVoteTimeoutMs` (line 136)
    - `$votePolicyStrictRuntimeApplied` (line 138)

- [Todo] P3.4 - Verify PHP syntax after Access Control removal
  - Run `php -l` on all modified files.
  - All must report "No syntax errors detected".

---

### Phase 4 - Remove Series Control

**Goal**: Delete all SeriesControl source files and domain trait, remove call sites from `CoreDomainTrait` and `MatchVetoRotationTrait`.

- [Todo] P4.1 - Delete Series Control source files
  - Delete files in `pixel-control-plugin/src/SeriesControl/`:
    - `SeriesControlState.php`
    - `SeriesControlCatalog.php`
    - `SeriesControlStateInterface.php`
  - Delete file: `pixel-control-plugin/src/Domain/SeriesControl/SeriesControlDomainTrait.php`
  - Delete the now-empty directories: `src/SeriesControl/`, `src/Domain/SeriesControl/`.

- [Todo] P4.2 - Remove Series Control from `MatchVetoRotationTrait.php`
  - File: `pixel-control-plugin/src/Domain/Match/MatchVetoRotationTrait.php`
  - Note: After Phase 1, the `$seriesTargets` reference in `buildLifecycleMapRotationTelemetry()` should already have been removed along with all other veto/series/matchmaking references. If it was only partially removed (left as a stub), now fully remove it.
  - Verify that the return array no longer contains `'series_targets'` and `$fieldAvailability` no longer contains `'series_targets'`.

- [Todo] P4.3 - Remove Series Control dispatch calls from `CoreDomainTrait.php`
  - File: `pixel-control-plugin/src/Domain/Core/CoreDomainTrait.php`
  - In `load()`: remove `$this->initializeSeriesControlState()` call (line 47).
  - In `unload()`: remove `$this->seriesControlState = null;` (line 135).
  - In `initializeSettings()`: remove `$this->initializeSeriesControlSettings()` call (line 220).

- [Todo] P4.4 - Remove Series Control from `PixelControlPlugin.php`
  - File: `pixel-control-plugin/src/PixelControlPlugin.php`
  - Remove `use` trait import: `use SeriesControlDomainTrait;` (line 49).
  - Remove `use` imports at file top:
    - `use PixelControl\Domain\SeriesControl\SeriesControlDomainTrait;` (line 28)
    - `use PixelControl\SeriesControl\SeriesControlStateInterface;` (line 31)
  - Remove settings constants:
    - `SETTING_SERIES_CONTROL_MAPS_SCORE_TEAM_A` (line 89)
    - `SETTING_SERIES_CONTROL_MAPS_SCORE_TEAM_B` (line 90)
    - `SETTING_SERIES_CONTROL_CURRENT_MAP_SCORE_TEAM_A` (line 91)
    - `SETTING_SERIES_CONTROL_CURRENT_MAP_SCORE_TEAM_B` (line 92)
    - Note: `SETTING_VETO_DRAFT_DEFAULT_BEST_OF` (line 87) was used by both VetoDraft and SeriesControl. It should already be removed in Phase 1. If not, remove it now.
  - Remove instance property:
    - `$seriesControlState` (line 264)

- [Todo] P4.5 - Verify PHP syntax after Series Control removal
  - Run `php -l` on all modified files.
  - All must report "No syntax errors detected".

---

### Phase 5 - Remove Team Control

**Goal**: Delete all TeamControl source files and domain trait, remove call sites from `CoreDomainTrait`. Note: `AdminActionCatalog` and `AdminActionResult` are imported by `TeamControlDomainTrait` but were already deleted in Phase 2. Since the entire `TeamControlDomainTrait` is being deleted, this is not a concern.

- [Todo] P5.1 - Delete Team Control source files
  - Delete files in `pixel-control-plugin/src/TeamControl/`:
    - `TeamRosterState.php`
    - `TeamRosterCatalog.php`
    - `TeamRosterStateInterface.php`
  - Delete file: `pixel-control-plugin/src/Domain/TeamControl/TeamControlDomainTrait.php`
  - Delete the now-empty directories: `src/TeamControl/`, `src/Domain/TeamControl/`.

- [Todo] P5.2 - Remove Team Control dispatch calls from `CoreDomainTrait.php`
  - File: `pixel-control-plugin/src/Domain/Core/CoreDomainTrait.php`
  - In `load()`: remove `$this->initializeTeamControlState()` call (line 49).
  - In `handleLifecycleCallback()`: remove `$this->handleTeamControlLifecycleCallback($callbackArguments)` call (line 148).
  - In `handlePlayerCallback()`: remove `$this->handleTeamControlPlayerCallback($callbackArguments)` call (line 155).
  - In `handleDispatchTimerTick()`: remove `$this->handleTeamControlPolicyTick()` call (line 189).
  - In `handleHeartbeatTimerTick()`: remove `$this->handleTeamControlPolicyTick()` call (line 195).
  - In `unload()`: remove Team Control state resets:
    - `$this->teamRosterState = null;` (line 81 -- note: was `$this->teamRosterState`)
    - `$this->teamControlForcedTeamsState = null;` (line 87)
    - `$this->teamControlLastRuntimeApplyAt = 0;` (line 88)
    - `$this->teamControlLastRuntimeApplySource = 'bootstrap';` (line 89)
    - `$this->teamControlRecentForcedAt = array();` (line 90)
    - `$this->teamControlLastReconcileAt = 0;` (line 91)
  - In `initializeSettings()`: remove `$this->initializeTeamControlSettings()` call (line 217).

- [Todo] P5.3 - Remove Team Control from `PixelControlPlugin.php`
  - File: `pixel-control-plugin/src/PixelControlPlugin.php`
  - Remove `use` trait import: `use TeamControlDomainTrait;` (line 50).
  - Remove `use` imports at file top:
    - `use PixelControl\Domain\TeamControl\TeamControlDomainTrait;` (line 29)
    - `use PixelControl\TeamControl\TeamRosterStateInterface;` (line 32 -- note: check exact import name)
  - Remove settings constants:
    - `SETTING_TEAM_POLICY_ENABLED` (line 75)
    - `SETTING_TEAM_SWITCH_LOCK_ENABLED` (line 76)
    - `SETTING_TEAM_ROSTER_ASSIGNMENTS` (line 77)
  - Remove instance properties:
    - `$teamRosterState` (line 123)
    - `$teamControlForcedTeamsState` (line 140)
    - `$teamControlLastRuntimeApplyAt` (line 142)
    - `$teamControlLastRuntimeApplySource` (line 144)
    - `$teamControlRecentForcedAt` (line 146)
    - `$teamControlForceCooldownSeconds` (line 148)
    - `$teamControlReconcileIntervalSeconds` (line 150)
    - `$teamControlLastReconcileAt` (line 152)

- [Todo] P5.4 - Verify PHP syntax after Team Control removal
  - Run `php -l` on all modified files.
  - All must report "No syntax errors detected".

---

### Phase 6 - Minor dead code removal + test cleanup

**Goal**: Remove remaining dead code and clean up test files that tested removed subsystems.

- [Todo] P6.1 - Delete `NoopRetryPolicy.php`
  - Delete file: `pixel-control-plugin/src/Retry/NoopRetryPolicy.php` (22 lines, never instantiated).

- [Todo] P6.2 - Remove dead mode callbacks from `CallbackRegistry.php`
  - File: `pixel-control-plugin/src/Callbacks/CallbackRegistry.php`
  - In `$modeCallbacks` array (lines 66-81): remove the `'joust'` and `'royal'` entries, keeping only `'elite'`.
  - After:
    ```php
    private static $modeCallbacks = array(
        'elite' => array(
            Callbacks::SM_ELITE_STARTTURN,
            Callbacks::SM_ELITE_ENDTURN,
        ),
    );
    ```

- [Todo] P6.3 - Remove `isEliteModeActive()` guard from `EliteRoundTrackingTrait.php`
  - File: `pixel-control-plugin/src/Domain/Combat/EliteRoundTrackingTrait.php`
  - In `processEliteRoundTracking()` (lines 17-39): remove the `isEliteModeActive()` guard check (lines 22-24) since in an Elite-only build, this is always true.
  - Delete the `isEliteModeActive()` method entirely (lines 45-48).

- [Todo] P6.4 - Delete test files for removed subsystems
  - Delete: `pixel-control-plugin/tests/cases/10StateModuleTest.php` (tests WhitelistState, SeriesControlState, TeamRosterState, VotePolicyState -- all removed).
  - Delete: `pixel-control-plugin/tests/cases/11VetoSessionStateTest.php` (tests VetoDraft session state).
  - Delete: `pixel-control-plugin/tests/cases/21AdminLinkAuthTest.php` (tests Admin link auth -- uses `AdminActionCatalog`, `AdminLinkAuthHarness`).
  - Delete: `pixel-control-plugin/tests/cases/30OrchestrationSeamTest.php` (tests VetoDraft/Series orchestration -- uses `FakeVetoCoordinator`, `FakeMapPoolService`, `SeriesPersistenceHarness`, `VetoReadyLifecyclePermissionHarness`).
  - Delete: `pixel-control-plugin/tests/cases/31AccessControlSourceOfTruthTest.php` (tests AccessControl -- uses `AccessControlSourceOfTruthHarness`).

- [Todo] P6.5 - Clean up test harness files
  - File: `pixel-control-plugin/tests/Support/Harnesses.php`
  - Delete harness classes that reference removed subsystems:
    - `AdminVetoNormalizationHarness` (references VetoDraft/Admin)
    - `AdminLinkAuthHarness` (references Admin)
    - `AccessControlSourceOfTruthHarness` (references AccessControl)
    - `VetoReadyLifecyclePermissionHarness` (references VetoDraft)
    - `SeriesPersistenceHarness` (references SeriesControl)
  - File: `pixel-control-plugin/tests/Support/Fakes.php`
  - Delete fake classes that reference removed subsystems:
    - `FakeMapPoolService` (references VetoDraft MapPoolService)
    - `FakeVetoCoordinator` (references VetoDraft Coordinator)
    - `FakeLinkApiClient` (check if it references Admin; if not, keep it -- link auth is still in use)
  - Review `00HarnessSmokeTest.php` and `20IngressNormalizationTest.php` for any imports of removed classes. If they reference removed classes, update or remove those specific test cases.

- [Todo] P6.6 - Final PHP syntax and quality check
  - Run: `bash pixel-control-plugin/scripts/check-quality.sh`
  - This runs PHP lint on all plugin source files. Every file must pass.
  - Run: `php pixel-control-plugin/tests/run.php`
  - Remaining test files (`00HarnessSmokeTest.php`, `20IngressNormalizationTest.php`) must pass. If any fail due to references to removed code, fix them.

---

### Phase 7 - Enrich combat events with Elite turn context

**Goal**: Every combat event emitted during an active Elite turn carries an `elite_context` object in its payload. Non-Elite events or events outside a turn carry `elite_context: null`.

- [Todo] P7.1 - Add turn number tracking to `PlayerCombatStatsStore`
  - File: `pixel-control-plugin/src/Stats/PlayerCombatStatsStore.php`
  - Add `private $eliteTurnNumber = 0;` (incremented in `openEliteRound()`).
  - Add `private $eliteAttackerTeamId = null;` to store attacker's team_id.
  - Add `public function getEliteTurnNumber(): int`
  - Add `public function getEliteAttackerLogin(): ?string` (expose existing `$eliteRoundAttackerLogin`).
  - Add `public function getEliteDefenderLogins(): array` (expose existing `$eliteRoundDefenderLogins`).
  - Add `public function getEliteAttackerTeamId(): ?int`
  - Increment `$eliteTurnNumber` at the top of `openEliteRound()`.
  - Reset `$eliteTurnNumber` to 0 in `reset()`.

- [Todo] P7.2 - Pass attacker team_id into `openEliteRound()`
  - File: `pixel-control-plugin/src/Domain/Combat/EliteRoundTrackingTrait.php`
  - In `handleEliteStartTurn()`: extract `$attacker->teamId` and pass it to `openEliteRound($attackerLogin, $defenderLogins, $attackerTeamId)`.
  - Update `PlayerCombatStatsStore::openEliteRound()` signature to accept optional `$attackerTeamId = null` and store it in `$this->eliteAttackerTeamId`.

- [Todo] P7.3 - Build `elite_context` helper method
  - File: `pixel-control-plugin/src/Domain/Combat/CombatDomainTrait.php`
  - Add `private function buildEliteContext(array $dimensions): ?array` that returns:
    ```php
    array(
      'turn_number' => $this->playerCombatStatsStore->getEliteTurnNumber(),
      'attacker_login' => $this->playerCombatStatsStore->getEliteAttackerLogin(),
      'defender_logins' => $this->playerCombatStatsStore->getEliteDefenderLogins(),
      'attacker_team_id' => $this->playerCombatStatsStore->getEliteAttackerTeamId(),
      'phase' => $this->resolveElitePhase($dimensions),
    )
    ```
  - Returns `null` if `!$this->playerCombatStatsStore->isEliteRoundActive()`.
  - Note: After Phase 6, `isEliteModeActive()` is removed (always Elite). The guard here is on the store's `isEliteRoundActive()` which tracks whether a turn is currently in progress.
  - Add `private function resolveElitePhase(array $dimensions): ?string`:
    - Extract shooter_login and victim_login from `$dimensions`.
    - Compare against `getEliteAttackerLogin()`.
    - For `onshoot`/`onhit`/`onnearmiss`: if shooter is attacker, phase="attack"; if shooter is defender, phase="defense".
    - For `onarmorempty`: return `null` (both attack and defense are relevant; the context carries enough info for the consumer to infer).
    - For `scores`/`oncapture`: return `null`.

- [Todo] P7.4 - Inject `elite_context` into combat payload
  - File: `pixel-control-plugin/src/Domain/Combat/CombatDomainTrait.php`
  - In `buildCombatPayload()`, after building the base `$payload` array and before `return $payload`, add:
    ```php
    $payload['elite_context'] = $this->buildEliteContext($dimensions);
    ```
  - This is additive: the field is `null` when not applicable, preserving backward compatibility.

- [Todo] P7.5 - Add `elite_context` to combat event metadata
  - File: `pixel-control-plugin/src/Domain/Pipeline/PipelineDomainTrait.php`
  - In `buildEnvelopeMetadata()`, inside the `if ($eventCategory === 'combat')` block, add:
    ```php
    if (isset($payload['elite_context']) && is_array($payload['elite_context'])) {
      $metadata['elite_turn_number'] = $payload['elite_context']['turn_number'];
      $metadata['elite_attacker_login'] = $payload['elite_context']['attacker_login'];
      $metadata['elite_attacker_team_id'] = $payload['elite_context']['attacker_team_id'];
    }
    ```

- [Todo] P7.6 - Add PHP tests for Elite context injection
  - File: `pixel-control-plugin/tests/cases/40EliteContextTest.php` (new file)
  - Test `PlayerCombatStatsStore`: turn number increments on each `openEliteRound()`, resets on `reset()`.
  - Test getters expose correct state during an active round.
  - Test that `buildEliteContext` returns null when no Elite round is active.

---

### Phase 8 - Emit `elite_turn_summary` event at turn end

**Goal**: When `OnEliteEndTurn` fires, a new event with `event_kind: elite_turn_summary` is dispatched, carrying consolidated per-turn stats, outcome, and map context.

- [Todo] P8.1 - Expand per-turn tracking in `PlayerCombatStatsStore`
  - File: `pixel-control-plugin/src/Stats/PlayerCombatStatsStore.php`
  - Add new per-turn tracking arrays (reset in `openEliteRound()`):
    - `$eliteRoundShots` (per-player shot count)
    - `$eliteRoundMisses` (per-player miss count)
    - `$eliteRoundKills` (per-player kill count)
    - `$eliteRoundDamageDealt` (per-player total damage dealt) -- requires passing damage to recording methods
  - Add `trackEliteRoundShot($login)` called from `recordShot()` when round active.
  - Add `trackEliteRoundMiss($login)` called from `recordMiss()` when round active.
  - Add `trackEliteRoundKill($login)` called from `recordKill()` for the killer when round active.
  - Add `public function snapshotEliteRoundStats(): array` that returns all per-turn tracking arrays as a structured snapshot:
    ```php
    array(
      'per_player' => array(
        $login => array(
          'kills' => ..., 'deaths' => ..., 'hits' => ..., 'shots' => ..., 'misses' => ...,
          'rocket_hits' => ..., 'damage_dealt' => 0, // damage_dealt deferred if not available from callbacks
        ),
      ),
      'turn_number' => $this->eliteTurnNumber,
      'attacker_login' => $this->eliteRoundAttackerLogin,
      'defender_logins' => $this->eliteRoundDefenderLogins,
      'attacker_team_id' => $this->eliteAttackerTeamId,
    )
    ```
  - Store turn start timestamp: `$eliteRoundStartedAt = 0;` set to `time()` in `openEliteRound()`.

- [Todo] P8.2 - Build turn summary payload in `EliteRoundTrackingTrait`
  - File: `pixel-control-plugin/src/Domain/Combat/EliteRoundTrackingTrait.php`
  - Add `private function buildEliteTurnSummaryPayload(int $victoryType): array` called before `closeEliteRound()`:
    - Gather `snapshotEliteRoundStats()` from the store.
    - Resolve outcome: map victoryType to string ("attacker_capture", "attacker_eliminated", "defenders_eliminated", "time_limit").
    - Compute `duration_seconds` from store's `$eliteRoundStartedAt` to `time()`.
    - Evaluate `defense_success`: `true` if victoryType is 1 (time_limit) or 3 (attacker_eliminated).
    - Include map context from `$this->maniaControl->getMapManager()->getCurrentMap()` (uid, name).
    - Return structured array with: `event_kind`, `turn_number`, `attacker_login`, `defender_logins`, `attacker_team_id`, `outcome`, `duration_seconds`, `defense_success`, `per_player_stats`, `map_uid`, `map_name`.

- [Todo] P8.3 - Dispatch turn summary as a combat event
  - File: `pixel-control-plugin/src/Domain/Combat/EliteRoundTrackingTrait.php`
  - In `handleEliteEndTurn()`, BEFORE calling `$this->playerCombatStatsStore->closeEliteRound()`:
    1. Build summary payload via `buildEliteTurnSummaryPayload($victoryType)`.
    2. Enqueue via `$this->enqueueEnvelope('combat', 'elite_turn_summary', $summaryPayload, $metadata)`.
       - `sourceCallback` = `'elite_turn_summary'` so the event_name becomes `pixel_control.combat.elite_turn_summary`.
       - Add relevant metadata: `elite_turn_number`, `elite_outcome`, `elite_defense_success`.
  - Then call `closeEliteRound()` as before (which resets transient state).

- [Todo] P8.4 - Add PHP tests for turn summary emission
  - File: `pixel-control-plugin/tests/cases/41EliteTurnSummaryTest.php` (new file)
  - Test `snapshotEliteRoundStats()` returns correct per-turn counters.
  - Test `buildEliteTurnSummaryPayload()` produces correct outcome strings for each victoryType.
  - Test that shot/miss/kill tracking resets between turns.
  - Test `defense_success` logic: true for time_limit (1) and attacker_eliminated (3), false for capture (2) and defenders_eliminated (4).

---

### Phase 9 - Clutch detection

**Goal**: Detect when a single remaining defender wins the round and include clutch info in the turn summary.

- [Todo] P9.1 - Track alive defenders in `PlayerCombatStatsStore`
  - File: `pixel-control-plugin/src/Stats/PlayerCombatStatsStore.php`
  - Add `private $eliteRoundAliveDefenders = array();` -- initialized from `$defenderLogins` in `openEliteRound()`.
  - In `trackEliteRoundDeath()`: if the dying player is in `$eliteRoundAliveDefenders`, remove them from the array.
  - Add `public function getAliveDefenderCount(): int` -- returns `count($this->eliteRoundAliveDefenders)`.
  - Add `public function getAliveDefenderLogins(): array`.
  - Reset `$eliteRoundAliveDefenders` in `openEliteRound()` and in `reset()`.

- [Todo] P9.2 - Evaluate clutch at turn end
  - File: `pixel-control-plugin/src/Domain/Combat/EliteRoundTrackingTrait.php`
  - In `buildEliteTurnSummaryPayload()`, after computing `defense_success`:
    - `$aliveCount = $this->playerCombatStatsStore->getAliveDefenderCount()`.
    - `$totalDefenders = count($this->playerCombatStatsStore->getEliteDefenderLogins())`.
    - `$isClutch = $defenseSuccess && $aliveCount === 1 && $totalDefenders > 1`.
    - `$clutchPlayerLogin = $isClutch ? $this->playerCombatStatsStore->getAliveDefenderLogins()[0] : null`.
    - Add to summary: `'clutch' => array('is_clutch' => $isClutch, 'clutch_player_login' => $clutchPlayerLogin, 'alive_defenders_at_end' => $aliveCount, 'total_defenders' => $totalDefenders)`.

- [Todo] P9.3 - Add PHP tests for clutch detection
  - File: `pixel-control-plugin/tests/cases/42EliteClutchTest.php` (new file)
  - Scenario: 3 defenders, 2 die via `onarmorempty`, time_limit victory -> clutch detected for the survivor.
  - Scenario: 3 defenders, 0 die, defense success -> NOT a clutch (all alive).
  - Scenario: 3 defenders, 2 die, attacker captures -> NOT a clutch (defense failed).
  - Scenario: 1 defender total, 0 die, defense success -> NOT a clutch (`$totalDefenders > 1` check).
  - Scenario: 2 defenders, 1 dies, attacker eliminated (victoryType=3) -> clutch detected.

---

### Phase 10 - API-side Elite turn endpoints

**Goal**: The NestJS API stores turn summary events (already handled by unified Event table ingestion) and exposes read endpoints for per-turn data, including clutch stats.

- [Todo] P10.1 - Add `EliteContext` TypeScript interface
  - File: `pixel-control-server/src/common/interfaces/elite-context.interface.ts` (new file)
  - Define:
    ```typescript
    export interface EliteContext {
      turn_number: number;
      attacker_login: string;
      defender_logins: string[];
      attacker_team_id: number;
      phase: string | null;
    }

    export interface EliteTurnSummary {
      event_kind: 'elite_turn_summary';
      turn_number: number;
      attacker_login: string;
      defender_logins: string[];
      attacker_team_id: number;
      outcome: 'attacker_capture' | 'attacker_eliminated' | 'defenders_eliminated' | 'time_limit';
      duration_seconds: number;
      defense_success: boolean;
      per_player_stats: Record<string, EliteTurnPlayerStats>;
      map_uid: string;
      map_name: string;
      clutch: EliteClutchInfo;
    }

    export interface EliteTurnPlayerStats {
      kills: number;
      deaths: number;
      hits: number;
      shots: number;
      misses: number;
      rocket_hits: number;
    }

    export interface EliteClutchInfo {
      is_clutch: boolean;
      clutch_player_login: string | null;
      alive_defenders_at_end: number;
      total_defenders: number;
    }
    ```

- [Todo] P10.2 - Add `elite-stats-read.service.ts`
  - File: `pixel-control-server/src/stats/elite-stats-read.service.ts` (new file)
  - Inject `PrismaService` and `ServerResolverService`.
  - Method `getEliteTurns(serverLogin, limit, offset, since?, until?)`: query `Event` table for `eventCategory='combat'` where `payload.event_kind = 'elite_turn_summary'`, ordered by `sourceTime desc`. Extract turn summary from payload. Return paginated list.
  - Method `getEliteTurnByNumber(serverLogin, turnNumber)`: find the specific turn event. Return 404 if not found.
  - Method `getPlayerClutchStats(serverLogin, playerLogin)`: aggregate across all `elite_turn_summary` events where `clutch.is_clutch = true` and `clutch.clutch_player_login = playerLogin`. Return `{ clutch_count, total_defense_rounds, clutch_rate, clutch_turns: [...] }`.
  - Method `getElitePlayerTurnHistory(serverLogin, playerLogin, limit, offset)`: filter turn summaries that include the player in `per_player_stats`. Return per-turn counters for that player.

- [Todo] P10.3 - Add `elite-stats-read.controller.ts`
  - File: `pixel-control-server/src/stats/elite-stats-read.controller.ts` (new file)
  - `@ApiTags('Stats - Elite')`, `@Controller('servers')`
  - Endpoints:
    - `GET :serverLogin/stats/combat/turns` -- List turn summaries (paginated, time-range).
    - `GET :serverLogin/stats/combat/turns/:turnNumber` -- Single turn by number.
    - `GET :serverLogin/stats/combat/players/:login/clutches` -- Clutch stats for a player.
    - `GET :serverLogin/stats/combat/players/:login/turns` -- Per-turn history for a player.
  - Full Swagger decorators on all routes (`@ApiOperation`, `@ApiParam`, `@ApiQuery`, `@ApiResponse`).

- [Todo] P10.4 - Register Elite stats module
  - Add `EliteStatsReadService` and `EliteStatsReadController` to `StatsReadModule` (or create a new `EliteStatsModule` if SRP calls for it -- prefer extending existing `StatsReadModule` since it shares the same concerns).
  - Verify module imports: `PrismaModule`, `ServerResolverModule`.

- [Todo] P10.5 - Update `RawCombatPayload` interface in `stats-read.service.ts`
  - File: `pixel-control-server/src/stats/stats-read.service.ts`
  - Add `elite_context?: EliteContext | null` to `RawCombatPayload` interface.
  - This enables existing endpoints to expose `elite_context` when present in combat events.

- [Todo] P10.6 - Update `NEW_API_CONTRACT.md`
  - Document new endpoints under the Combat / Stats section:
    - `GET /v1/servers/:serverLogin/stats/combat/turns` -- P2.12
    - `GET /v1/servers/:serverLogin/stats/combat/turns/:turnNumber` -- P2.13
    - `GET /v1/servers/:serverLogin/stats/combat/players/:login/clutches` -- P2.14
    - `GET /v1/servers/:serverLogin/stats/combat/players/:login/turns` -- P2.15
  - Document the `elite_context` payload shape in the Combat event payload section.
  - Document the `elite_turn_summary` event kind.

---

### Phase 11 - Plugin PHP QA

**Goal**: Verify all PHP code is syntactically valid and all remaining + new tests pass after cleanup and enrichment.

- [Todo] P11.1 - Run PHP syntax lint on all plugin source files
  - Command: `bash pixel-control-plugin/scripts/check-quality.sh`
  - All source files must pass `php -l` with "No syntax errors detected".

- [Todo] P11.2 - Run the full plugin test suite
  - Command: `php pixel-control-plugin/tests/run.php`
  - Remaining surviving test files must pass:
    - `00HarnessSmokeTest.php`
    - `20IngressNormalizationTest.php`
  - New Elite test files must pass:
    - `40EliteContextTest.php`
    - `41EliteTurnSummaryTest.php`
    - `42EliteClutchTest.php`

---

### Phase 12 - Server unit tests

**Goal**: Verify all existing server tests still pass and new Elite stats tests pass.

- [Todo] P12.1 - Run full Vitest suite
  - Command: `cd pixel-control-server && npm run test`
  - All existing tests must still pass (21 spec files, 240+ tests).

- [Todo] P12.2 - Add Vitest unit tests for Elite stats service
  - File: `pixel-control-server/src/stats/elite-stats-read.service.spec.ts` (new file)
  - Test `getEliteTurns()` with mock Event data containing `elite_turn_summary` payloads.
  - Test `getPlayerClutchStats()` aggregation logic.
  - Test `getElitePlayerTurnHistory()` filters correctly.
  - Test edge cases: no turn summaries, player not in any turn, non-Elite server.

- [Todo] P12.3 - Add Vitest unit tests for Elite stats controller
  - File: `pixel-control-server/src/stats/elite-stats-read.controller.spec.ts` (new file)
  - Test route wiring, parameter passing, 404 scenarios.

- [Todo] P12.4 - Re-run full Vitest suite after adding new tests
  - Command: `cd pixel-control-server && npm run test`
  - All existing + new tests must pass, no regressions.

---

### Phase 13 - Smoke test regression suite

**Goal**: Run ALL existing smoke scripts to ensure no regressions from plugin cleanup or enrichment changes. Server-side code was not modified in cleanup phases, but the smoke scripts exercise the full plugin-to-server pipeline.

- [Todo] P13.1 - Start server infrastructure
  - Command: `cd pixel-control-server && npm run docker:up`
  - Wait for postgres and API to be ready.
  - Verify: `curl -s http://localhost:3000/v1/servers | jq .` returns a valid response.

- [Todo] P13.2 - Run P0 smoke test
  - Command: `cd pixel-control-server && bash scripts/qa-p0-smoke.sh`
  - Expected: 43 assertions, all pass.

- [Todo] P13.3 - Run P1 smoke test
  - Command: `cd pixel-control-server && bash scripts/qa-p1-smoke.sh`
  - Expected: 35 assertions, all pass.

- [Todo] P13.4 - Run P2 smoke test
  - Command: `cd pixel-control-server && bash scripts/qa-p2-smoke.sh`
  - Expected: 94 assertions, all pass.

- [Todo] P13.5 - Run P2.5 smoke test
  - Command: `cd pixel-control-server && bash scripts/qa-p2.5-smoke.sh`
  - Expected: 59 assertions, all pass.

- [Todo] P13.6 - Run P2.6 smoke test
  - Command: `cd pixel-control-server && bash scripts/qa-p2.6-smoke.sh`
  - Expected: 29 assertions, all pass.

- [Todo] P13.7 - Run P2.6 Elite smoke test
  - Command: `cd pixel-control-server && bash scripts/qa-p2.6-elite-smoke.sh`
  - Expected: 21 assertions, all pass.

- [Todo] P13.8 - Run full integration test
  - Command: `cd pixel-control-server && bash scripts/qa-full-integration.sh`
  - Expected: 255 assertions, all pass.

---

### Phase 14 - New Elite enrichment smoke test

**Goal**: Create and run a comprehensive smoke test for the new Elite enrichment endpoints.

- [Todo] P14.1 - Create `qa-elite-enrichment-smoke.sh`
  - File: `pixel-control-server/scripts/qa-elite-enrichment-smoke.sh` (new file)
  - **Setup**:
    - Register a test server via `PUT /v1/servers/:serverLogin/link/registration`.
    - Send a connectivity heartbeat event.
  - **Elite context in combat events**:
    - POST a series of combat events WITH `elite_context` fields (turn_number, attacker_login, defender_logins, attacker_team_id, phase).
    - Verify events are accepted (200 ack).
    - GET combat events and verify `elite_context` fields appear in the response payloads.
    - POST a combat event WITHOUT `elite_context` (backward compatibility).
    - Verify it is accepted and existing combat endpoints still work.
  - **Turn summary events**:
    - POST multiple `elite_turn_summary` events with different outcomes (capture, time_limit, attacker_eliminated, defenders_eliminated).
    - Each carries: turn_number, attacker_login, defender_logins, attacker_team_id, outcome, duration_seconds, defense_success, per_player_stats, map_uid, map_name, clutch.
  - **Turn list endpoint**:
    - `GET /v1/servers/:serverLogin/stats/combat/turns` -- verify returns all posted turn summaries, paginated.
    - Test `?limit=2` returns only 2 results.
    - Test `?since=...&until=...` time range filtering.
  - **Single turn endpoint**:
    - `GET /v1/servers/:serverLogin/stats/combat/turns/:turnNumber` -- verify correct turn data.
    - Test with non-existent turn number -- verify 404 response.
  - **Clutch stats endpoint**:
    - `GET /v1/servers/:serverLogin/stats/combat/players/:login/clutches` -- verify clutch count, rate, and list of clutch turns.
    - Test with player who has no clutches -- verify zeroed response.
    - Test with unknown player login -- verify appropriate response.
  - **Player turn history endpoint**:
    - `GET /v1/servers/:serverLogin/stats/combat/players/:login/turns` -- verify per-turn stats for a specific player.
    - Test pagination with `?limit=1&offset=1`.
    - Test with player not in any turn.
  - **Edge cases**:
    - Query endpoints on a server with no turn summaries.
    - Verify response shapes match API contract.
  - **Target**: 40-60 assertions.
  - **Cleanup**: Delete the test server at the end.

- [Todo] P14.2 - Run the new smoke test
  - Command: `cd pixel-control-server && bash scripts/qa-elite-enrichment-smoke.sh`
  - All assertions must pass.

- [Todo] P14.3 - Tear down server infrastructure
  - Command: `cd pixel-control-server && npm run docker:down`

---

## Evidence / Artifacts

- **Cleanup artifacts** (deleted files -- tracked via git):
  - `pixel-control-plugin/src/VetoDraft/` (10 files deleted)
  - `pixel-control-plugin/src/Domain/VetoDraft/` (5 files deleted)
  - `pixel-control-plugin/src/Admin/` (3 files deleted)
  - `pixel-control-plugin/src/Domain/Admin/` (4 files deleted)
  - `pixel-control-plugin/src/AccessControl/` (3 files deleted)
  - `pixel-control-plugin/src/VoteControl/` (3 files deleted)
  - `pixel-control-plugin/src/Domain/AccessControl/` (1 file deleted)
  - `pixel-control-plugin/src/SeriesControl/` (3 files deleted)
  - `pixel-control-plugin/src/Domain/SeriesControl/` (1 file deleted)
  - `pixel-control-plugin/src/TeamControl/` (3 files deleted)
  - `pixel-control-plugin/src/Domain/TeamControl/` (1 file deleted)
  - `pixel-control-plugin/src/Retry/NoopRetryPolicy.php` (deleted)
  - `pixel-control-plugin/tests/cases/10StateModuleTest.php` (deleted)
  - `pixel-control-plugin/tests/cases/11VetoSessionStateTest.php` (deleted)
  - `pixel-control-plugin/tests/cases/21AdminLinkAuthTest.php` (deleted)
  - `pixel-control-plugin/tests/cases/30OrchestrationSeamTest.php` (deleted)
  - `pixel-control-plugin/tests/cases/31AccessControlSourceOfTruthTest.php` (deleted)
- **Enrichment artifacts** (new files):
  - `pixel-control-plugin/tests/cases/40EliteContextTest.php`
  - `pixel-control-plugin/tests/cases/41EliteTurnSummaryTest.php`
  - `pixel-control-plugin/tests/cases/42EliteClutchTest.php`
  - `pixel-control-server/src/common/interfaces/elite-context.interface.ts`
  - `pixel-control-server/src/stats/elite-stats-read.service.ts`
  - `pixel-control-server/src/stats/elite-stats-read.controller.ts`
  - `pixel-control-server/src/stats/elite-stats-read.service.spec.ts`
  - `pixel-control-server/src/stats/elite-stats-read.controller.spec.ts`
  - `pixel-control-server/scripts/qa-elite-enrichment-smoke.sh`

## Success criteria

- **Cleanup criteria**:
  - All 37 source files across 5 subsystems are deleted.
  - 5 test files for removed subsystems are deleted.
  - Test harness classes for removed subsystems are cleaned up.
  - `PixelControlPlugin.php` no longer imports or uses any removed trait/class.
  - `CoreDomainTrait.php` no longer calls any removed subsystem method.
  - `MatchVetoRotationTrait.php` returns a simplified map rotation telemetry (no veto/series/matchmaking keys).
  - `CallbackRegistry.php` only registers Elite mode callbacks.
  - `EliteRoundTrackingTrait.php` has no `isEliteModeActive()` guard.
  - `php -l` passes on every plugin source file.
  - `php pixel-control-plugin/tests/run.php` passes all remaining test files.
  - `bash pixel-control-plugin/scripts/check-quality.sh` passes.
- **Enrichment criteria**:
  - All existing plugin tests pass (no regressions).
  - All existing server tests pass (no regressions).
  - New PHP tests (40/41/42) pass: Elite context injection, turn summary, clutch detection.
  - New Vitest tests pass: Elite stats service + controller.
  - `elite_context` field is present in combat events during Elite turns, null otherwise.
  - `elite_turn_summary` events are emitted at each turn end with correct outcome, duration, and per-player stats.
  - Clutch detection correctly identifies single-survivor defense wins.
  - All 4 new API endpoints return correct data shapes.
  - `NEW_API_CONTRACT.md` is updated with the new endpoints and payload shapes.
  - No breaking changes to existing event envelope format or API responses.
- **QA criteria**:
  - All 7 existing smoke scripts pass with their expected assertion counts.
  - Full integration test passes (255 assertions).
  - New Elite enrichment smoke script passes (40-60 assertions).

## Notes / outcomes

- (To be filled after execution.)
