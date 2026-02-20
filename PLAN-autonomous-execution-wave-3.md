# PLAN - Autonomous execution wave 3: plugin + dev-server admin/player stats expansion (2026-02-20)

## Context

- Purpose: Continue autonomous execution with a plugin-first and `pixel-sm-server`-first wave that expands admin/player stats workflows while backend runtime implementation remains paused.
- Scope: This wave delivers seven bounded outputs: (1) plugin roster/eligibility telemetry expansion for admin/player workflows, (2) plugin per-round/per-map aggregate telemetry for local stats analysis, (3) plugin map rotation/veto outcome telemetry baseline, (4) `pixel-sm-server` QA workflow upgrades for deterministic local evidence, (5) completion of at least one pending roadmap item in the dev-server documentation track, (6) contract/docs synchronization in `ROADMAP.md`, `AGENTS.md`, `API_CONTRACT.md`, and `pixel-control-plugin/FEATURES.md`, and (7) a handoff + manual gameplay validation phase.
- Background / Findings:
  - `PLAN-autonomous-execution-wave-1.md` and `PLAN-autonomous-execution-wave-2.md` established strong lifecycle/admin/combat event coverage and deterministic local QA patterns.
  - User direction is now explicit: backend/API runtime implementation is paused; no runtime backend code work in `pixel-control-server/`.
  - `ROADMAP.md` currently identifies `Checkpoint V` as the active thread and still contains pending plugin + `pixel-sm-server` items relevant to this wave.
  - Paragon-inspired research (roles, lifecycle actions, and aggregates) should inform plugin payload semantics and local dev-server testability, without introducing backend runtime implementation.
- Goals:
  - Expand plugin telemetry so admin/player workflows can be validated locally with richer roster/eligibility/aggregate/map context.
  - Improve `pixel-sm-server` developer workflows and evidence capture for reproducible local QA.
  - Keep contract and execution-memory docs fully synchronized while preserving backend pause boundaries.
- Non-goals:
  - Adding/modifying runtime backend implementation in `pixel-control-server/`.
  - Re-opening deferred backend checkpoints (`Checkpoint T`/`Checkpoint U`) in this wave.
  - Editing anything under `ressources/`.
- Constraints / assumptions:
  - Plugin-first + dev-server-first only; backend runtime remains deferred.
  - `API_CONTRACT.md` remains the contract source of truth for future backend implementation.
  - Keep exactly one active step at a time during execution.
  - Keep schema/version evolution explicit and backward-compatible where possible.

## Steps

Execution rule: update statuses live while executing; keep only one active step status at a time.

### Phase 0 - Recon and wave lock

- [Done] P0.1 Lock wave-3 file-touch map and pending-item mapping.
  - Confirm exact plugin + `pixel-sm-server` touch points for admin/player stats workflow expansion.
  - Confirm concrete pending roadmap item(s) that this wave will close (minimum one), including `pixel-sm-server/README.md` title-pack list completion if still incomplete.
- 2026-02-20 recon lock: implementation touch map confirmed for `pixel-control-plugin/src/PixelControlPlugin.php`, `pixel-control-plugin/src/Callbacks/CallbackRegistry.php`, `pixel-control-plugin/src/Stats/PlayerCombatStatsStore.php`, `pixel-sm-server/scripts/*`, `pixel-sm-server/README.md`, and wave-sync docs/contracts.
- 2026-02-20 roadmap closure target locked: close pending Pixel SM Server developer-experience item "Add a clear ShootMania title-pack name list in `pixel-sm-server/README.md`" by completing/validating mode-to-title-pack matrix coverage (including Royal guidance).
- [Done] P0.2 Freeze wave-3 contract/version strategy.
  - Decide additive-vs-bump schema strategy for new player/aggregate/map telemetry fields before implementation edits.
  - Record compatibility expectation in plugin/API contract docs.
- 2026-02-20 strategy decision: keep `schema_version=2026-02-20.1` and deliver wave-3 telemetry as additive optional fields/events only (no breaking removals/renames); preserve existing route and envelope semantics while extending payload richness.

### Phase 1 - Plugin player/admin workflow telemetry expansion

- [Done] P1.1 Expand roster-state synchronization telemetry.
  - Implement normalized roster state payloads covering connected/spectator/team/readiness transitions aligned with `ROADMAP.md` Players P1 track.
  - Preserve deterministic transition metadata (`previous`, `current`, `delta`, and availability markers).
- Implemented additive player payload enrichment with normalized `roster_state` (`current`/`previous`/`delta`/aggregate), expanded readiness and eligibility state derivation, and deterministic availability/missing markers.
- [Done] P1.2 Expand eligibility/permission telemetry surface.
  - Emit explicit eligibility/permission signals per player when callback data is available, with safe fallback markers when unavailable.
  - Keep naming aligned with existing player/admin payload conventions.
- Implemented explicit eligibility/readiness signals in `permission_signals` (`eligibility_state`, `readiness_state`, `can_join_round`, change flags, and field availability fallback markers).
- [Done] P1.3 Improve admin/player correlation context.
  - Add correlation fields linking admin-triggered lifecycle changes and affected player-state transitions where inferable.
  - Keep correlation additive and non-breaking for existing consumers.
- Added additive `admin_correlation` payload linking player transitions to recent lifecycle `admin_action` contexts (windowed inference with confidence/reason markers and fallback semantics).

### Phase 2 - Plugin stats and map telemetry enrichment

- [Done] P2.1 Add per-round aggregate stats snapshots.
  - Emit aggregate payloads at round boundaries with explicit counter semantics and source coverage notes.
  - Keep aggregation callback-safe and bounded.
- Added lifecycle `aggregate_stats` payload emission at `round.end` with bounded delta counters, totals, source-coverage notes, and field availability/fallback markers.
- [Done] P2.2 Add per-map aggregate stats snapshots and win-context baseline.
  - Emit map-level aggregate payloads at map boundaries with mode-aware context markers.
  - Include explicit fallback semantics when win-condition fields are not exposed by callbacks.
- Added lifecycle `aggregate_stats` payload emission at `map.end` plus `win_context` projection from latest `SM_SCORES` callback with explicit unavailable fallback when score/winner fields are missing.
- [Done] P2.3 Add map-rotation/veto-result telemetry baseline.
  - Export map pool/rotation metadata and final played-map order context where runtime data exposes these fields.
  - Keep map identifiers normalized (`uid`, `name`, and optional external identifiers when available).
- Added lifecycle `map_rotation` payload snapshots on `map.begin`/`map.end` (map pool, next maps, played order history, normalized identifiers with optional MX ids) plus explicit veto-result unavailable baseline.

### Phase 3 - Pixel SM server workflow upgrades and pending roadmap closure

- [Done] P3.1 Complete pending title-pack documentation checkpoint in `pixel-sm-server/README.md`.
  - Add/verify a clear ShootMania title-pack name matrix (for example Elite/Siege/Battle/Joust/Royal) with practical runtime notes.
  - Align the README content with bootstrap expectations and mounted asset guidance.
- Closed pending title-pack doc checkpoint by extending README matrix with explicit Royal guidance and mounted title-pack runtime notes.
- [Done] P3.2 Add deterministic QA helper flow for admin/player stats workflows.
  - Add or extend `pixel-sm-server/scripts/*` helpers to replay or simulate player/admin/stat sequences and capture evidence locally.
  - Ensure helper output paths are deterministic and documented.
- Added `pixel-sm-server/scripts/qa-wave3-telemetry-replay.sh` with local ACK stub capture, deterministic action replay, marker validation, and stable `logs/qa/wave3-telemetry-<timestamp>-*` artifacts.
- [Done] P3.3 Align local workflow docs and env references for the new QA flow.
  - Update `pixel-sm-server` docs/env guidance so reruns are one-command and reproducible.
- Updated `pixel-sm-server/README.md` and `.env.example` with one-command wave-3 replay usage and QA env knob references.

### Phase 4 - Contract and project-memory synchronization

- [Done] P4.1 Synchronize `API_CONTRACT.md` with new plugin telemetry fields/routes usage.
  - Update player/admin/stats/mode route payload expectations and additive field semantics without introducing backend runtime code.
  - Keep route stability guarantees explicit.
- Updated `API_CONTRACT.md` with wave-3 additive lifecycle/player telemetry semantics while preserving deferred backend/runtime boundaries and route stability.
- [Done] P4.2 Synchronize plugin capability docs and schemas.
  - Update `pixel-control-plugin/FEATURES.md`, `pixel-control-plugin/docs/event-contract.md`, and related schema catalog files for wave-3 payload additions.
  - Keep compatibility/version references coherent across docs.
- Updated wave-3 plugin docs/schemas (`FEATURES.md`, `event-contract.md`, `envelope-2026-02-20.1.schema.json`, `lifecycle-payload-2026-02-20.1.schema.json`, `event-name-catalog-2026-02-20.1.json`) with additive roster/eligibility/admin-correlation + aggregate/map telemetry.
- [Done] P4.3 Synchronize roadmap and execution-memory state.
  - Update `ROADMAP.md` (including `Checkpoint V` progress and any newly closed pending item) and root `AGENTS.md` current-status fields.
  - Reiterate backend/runtime pause boundaries for future execution continuity.
- Updated `ROADMAP.md` + root `AGENTS.md` with wave-3 completion state, closed title-pack roadmap item, new QA replay helper, and backend pause continuity notes.

### Phase 5 - Local QA and evidence capture

- [Done] P5.1 Run static/sanity checks on touched plugin and script files.
  - Validate PHP syntax for modified plugin files and shell syntax for modified scripts.
  - Confirm schema/doc artifacts remain internally consistent.
- Completed sanity checks: `php -l pixel-control-plugin/src/PixelControlPlugin.php`, `bash -n pixel-sm-server/scripts/qa-wave3-telemetry-replay.sh`, and JSON parse checks for updated schema/catalog files.
- [Done] P5.2 Run deterministic local QA matrix.
  - Execute existing smoke flows (`qa-launch-smoke.sh`, `qa-mode-smoke.sh`, `qa-admin-stats-replay.sh`) plus any new wave-3 helper flow.
  - Verify expected markers for roster transitions, eligibility signals, admin/player correlation, per-round/per-map aggregates, and map-rotation telemetry.
- QA matrix outcomes (2026-02-20):
  - Passed: `bash pixel-sm-server/scripts/qa-launch-smoke.sh`
  - Passed: `bash pixel-sm-server/scripts/qa-mode-smoke.sh`
  - Passed: `bash pixel-sm-server/scripts/qa-wave3-telemetry-replay.sh` (with deterministic fixture marker injection enabled by default)
  - Expected failure in backend-paused context: `bash pixel-sm-server/scripts/qa-admin-stats-replay.sh` (`curl: (7) Failed to connect to 127.0.0.1:8080`), kept as legacy backend-ingestion replay helper.
- [Done] P5.3 Capture and index evidence artifacts.
  - Store QA summaries/logs under stable paths (for example `pixel-sm-server/logs/qa/` and `pixel-sm-server/logs/dev/`) and record expected markers.
- Evidence index recorded at `pixel-sm-server/logs/qa/wave3-evidence-index-20260220.md` with primary successful replay (`wave3-telemetry-20260220-131821-*`), smoke-matrix logs, and known troubleshooting attempts.

### Phase 6 - Handoff and manual gameplay validation

- [Done] P6.1 Produce wave-3 handoff artifact.
  - Create `HANDOFF-autonomous-wave-3-<date>.md` with changed-file map, rerun commands, QA markers, and unresolved follow-ups.
- Created `HANDOFF-autonomous-wave-3-2026-02-20.md` with changed-file map, rerun commands, QA outcomes, evidence pointers, and follow-up items.
- [Todo] P6.2 Run manual gameplay checklist for final confidence.
  - Validate real-client scenarios for player transitions, admin-triggered lifecycle actions, and non-zero combat aggregates across at least one full map cycle.
  - Validate map-rotation/veto context fields with real flow evidence where possible.
- Manual gameplay remains pending due real-client requirement (not runnable from current headless automation loop); keep checklist open for next executor/user cycle with real client availability.
- [Done] P6.3 Record final acceptance and next-action pointer.
  - If manual items remain pending, document exact pending evidence requirements and single next action for the next executor cycle.
- Recorded pending requirements and next action in `HANDOFF-autonomous-wave-3-2026-02-20.md` and `AGENTS.md` (wave-4 pointer: real-client gameplay evidence + veto/team aggregate closure while backend remains paused).

## Evidence / Artifacts

- Planned implementation targets:
  - `pixel-control-plugin/src/PixelControlPlugin.php`
  - `pixel-control-plugin/src/Callbacks/CallbackRegistry.php`
  - `pixel-control-plugin/docs/event-contract.md`
  - `pixel-control-plugin/docs/schema/*`
  - `pixel-control-plugin/FEATURES.md`
  - `pixel-sm-server/scripts/*`
  - `pixel-sm-server/README.md`
  - `API_CONTRACT.md`
  - `ROADMAP.md`
  - `AGENTS.md`
- Expected QA/evidence outputs:
  - `pixel-sm-server/logs/qa/`
  - `pixel-sm-server/logs/dev/`
  - `pixel-sm-server/runtime/server/ManiaControl/ManiaControl.log`
  - `HANDOFF-autonomous-wave-3-*.md`

## Success criteria

- Plugin telemetry covers richer roster/eligibility/admin correlation plus per-round/per-map aggregate and map-rotation context without breaking existing envelope compatibility.
- At least one concrete pending roadmap item in the plugin/`pixel-sm-server` track is closed and reflected in `ROADMAP.md`.
- `pixel-sm-server` has deterministic local QA flows for the new telemetry surfaces with reproducible evidence outputs.
- `ROADMAP.md`, root `AGENTS.md`, `API_CONTRACT.md`, and `pixel-control-plugin/FEATURES.md` are synchronized with wave-3 outcomes.
- No runtime backend code is added/modified in `pixel-control-server/`.

## Notes / outcomes

- Reserved for execution-time findings, blockers, and follow-up decisions.
