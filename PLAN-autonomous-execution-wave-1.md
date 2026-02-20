# PLAN - First autonomous execution wave (2026-02-20)

## Context

- Purpose: Deliver the first autonomous implementation wave focused on plugin reliability + gameplay telemetry and ShootMania mode preset usability for local development.
- Scope: This plan covers exactly five deliverables: (1) plugin local queue behavior for temporary API outages, (2) plugin emission of administrative match-flow actions, (3) plugin capture of player/combat stats with required counters and combat dimensions, (4) `pixel-sm-server` mode presets for Elite/Siege/Battle/Joust/Custom with default matchsettings workflow updates, and (5) `pixel-sm-server/README.md` title-pack name list.
- Background / Findings: Core plugin queue/retry scaffolding, lifecycle normalization, event envelope schema, and Elite/Siege/Battle mode smoke baseline already exist from `PLAN-immediate-pixel-control-execution.md`; this wave extends that baseline without mixing in broader server-ingestion implementation work.
- Goals:
  - Harden plugin behavior under temporary API transport outages without breaking callback-loop stability.
  - Export actionable admin-flow and combat/stat signals needed by downstream ingestion and analytics.
  - Make local mode selection deterministic and discoverable for all requested presets.
  - Keep docs and QA evidence synchronized so execution can be resumed or audited quickly.
- Non-goals: Implementing full `pixel-control-server/` ingestion features, adding CI/release pipelines, modifying reference assets under `ressources/`, or expanding into unrelated roadmap domains.
- Constraints / assumptions:
  - Treat `pixel-control-plugin/`, `pixel-control-server/`, and `pixel-sm-server/` as separate first-party projects with explicit interfaces.
  - Treat `ressources/` as read-only reference input only; no mutable runtime workflows there.
  - Keep local-first defaults and secret hygiene (no committed credentials).
  - Keep exactly one `[In progress]` step while executing this plan.

## Steps

Execution rule: update statuses live as work advances; keep only one active `[In progress]` step.

### Phase 0 - Recon and wave lock

- [Done] P0.1 Reconcile current baseline against this wave's five requested deliverables.
  - Inspect current plugin callback/queue paths and docs (`pixel-control-plugin/src/PixelControlPlugin.php`, `pixel-control-plugin/src/Callbacks/CallbackRegistry.php`, `pixel-control-plugin/src/Queue/*`, `pixel-control-plugin/docs/*`).
  - Inspect current mode workflow/files and docs (`pixel-sm-server/scripts/bootstrap.sh`, `pixel-sm-server/templates/matchsettings/*`, `pixel-sm-server/.env.example`, `pixel-sm-server/README.md`).
  - Produce a concrete file-touch map for implementation and QA artifacts before coding.
- [Done] P0.2 Lock contract slices and naming before edits.
  - Freeze queue outage semantics (retry window, queue-pressure behavior, recovery/flush markers).
  - Freeze admin-action event taxonomy (event names, action types, actor/source metadata, match-flow target).
  - Freeze stats payload semantics (counter keys, per-event dimensions, "where feasible" fallback when callback fields are absent).

### Phase 1 - Plugin outage queue behavior

- [Done] P1.1 Harden temporary API outage handling in local queue flow.
  - Ensure transport failures keep events locally queued/retryable during outages (no silent drop before retry policy exhaustion).
  - Ensure queue processing remains bounded and callback-safe under prolonged outage pressure.
- [Done] P1.2 Add explicit outage/recovery observability markers.
  - Emit deterministic log markers for queue growth, retry scheduling, drop-on-capacity, and successful recovery flush.
  - Surface queue depth and retry context in heartbeat/connectivity payload metadata when available.
- [Done] P1.3 Sync queue-behavior docs and config references.
  - Document any new/updated queue knobs and expected outage behavior in plugin docs/README contract sections.

### Phase 2 - Plugin administrative match-flow actions

- [Done] P2.1 Identify callback sources representing administrative match-flow changes.
  - Map relevant ManiaControl/ManiaPlanet/script callbacks to admin-intent actions (for example: forced map/round/match transitions, warmup control, or equivalent control-plane events exposed by callbacks).
- [Done] P2.2 Implement admin-action event emission.
  - Emit normalized admin-action envelopes with consistent action name, source callback, context snapshot, and actor metadata when available.
  - Keep emission compatible with existing envelope/idempotency conventions.
- [Done] P2.3 Extend schema/catalog docs for admin-action payloads.
  - Update event naming catalog and JSON schema references to include new admin-action payload structure.

### Phase 3 - Plugin player/combat stats capture

- [Done] P3.1 Add stats aggregation primitives for required counters.
  - Capture per-player counters for kills, deaths, hits, shots, misses, rockets, lasers, and computed accuracy.
- [Done] P3.2 Map combat callbacks to structured stats events.
  - Extract weapon id, damage, distance, shooter/victim identifiers, and positions where callback payload data provides those fields.
  - Preserve graceful fallback markers when specific fields are unavailable for a callback variant.
- [Done] P3.3 Emit stats payloads in envelope pipeline.
  - Publish structured stats events without breaking existing lifecycle/combat/mode event flows.
  - Keep event names/schema versions aligned with plugin contract conventions.
- [Done] P3.4 Update stats contract docs and schema artifacts.
  - Synchronize `pixel-control-plugin/docs/event-contract.md` and `pixel-control-plugin/docs/schema/*` with new stats payload structure.

### Phase 4 - Pixel SM mode presets and workflow

- [Done] P4.1 Add/normalize default matchsettings templates for full preset matrix.
  - Ensure templates cover `elite`, `siege`, `battle`, `joust`, and `custom` with sane defaults.
  - Keep file naming and script/title fields consistent with current bootstrap expectations.
- [Done] P4.2 Update mode preset resolution workflow in bootstrap.
  - Preserve precedence: explicit `PIXEL_SM_MATCHSETTINGS` override first, then mode preset fallback.
  - Add fail-fast guidance when selected preset lacks mode-compatible map/script/title-pack assets.
- [Done] P4.3 Align env defaults and mode smoke workflow.
  - Update `.env.example` and QA helper expectations so preset behavior is deterministic and reproducible for local runs.

### Phase 5 - Documentation synchronization

- [Done] P5.1 Update `pixel-sm-server/README.md` with a clear ShootMania title-pack list.
  - Include practical names/IDs for Elite, Siege, Battle, Joust, and Custom guidance (plus when title-pack assets are required locally).
- [Done] P5.2 Update plugin docs for outage queue + admin actions + stats payload changes.
  - Keep naming, schema version references, and payload examples coherent across docs.
- [Done] P5.3 Synchronize execution memory docs after implementation.
  - Update `ROADMAP.md` checkpoint/task lines touched by this wave and local `AGENTS.md` status pointers for next-session continuity.

### Phase 6 - QA and verification

- [Done] P6.1 Run static/syntax checks for touched code and templates.
  - PHP syntax lint for modified plugin files, shell syntax checks for updated scripts, and XML sanity validation for matchsettings templates.
- [Done] P6.2 Run automated stack smoke checks.
  - Execute `pixel-sm-server/scripts/qa-launch-smoke.sh` and updated `pixel-sm-server/scripts/qa-mode-smoke.sh` coverage relevant to preset changes.
- [Done] P6.3 Validate outage/recovery behavior under controlled transport failure.
   - Run with an intentionally unreachable API endpoint, trigger gameplay callbacks, confirm queue/retry markers, then restore a reachable endpoint/stub and confirm backlog drain markers.
- [Done] P6.4 Validate admin-action + stats emission evidence.
   - Confirm expected event markers/payload fields in runtime logs for admin-flow changes and combat/stat activity.

### Phase 7 - Handoff and manual validation

- [Done] P7.1 Prepare executor handoff summary with exact changed files and rerun commands.
- [Todo] P7.2 Run concrete game-driven manual validation checklist.
   - Manual test A (stack + join): launch stack, connect from ShootMania client, verify plugin load + active matchsettings markers.
   - Manual test B (admin action): trigger a match-flow admin action (for example map/round transition control available in runtime tooling), verify corresponding admin-action event marker in plugin/runtime logs.
   - Manual test C (combat stats): perform multiple shoots/hits/misses and at least one kill/death exchange, verify counters and combat dimensions (weapon id/damage/distance/positions where available) appear in emitted payload/log evidence.
   - Manual test D (outage recovery): temporarily point plugin API target to an unavailable endpoint during gameplay, verify queue growth/retry markers, then restore endpoint and verify flush/drain markers.
- [Todo] P7.3 Capture manual evidence references and final acceptance notes.
  - Record evidence file paths and expected log markers so the next executor can replay validation without re-discovery.

## Evidence / Artifacts

- Planned implementation targets:
  - `pixel-control-plugin/src/PixelControlPlugin.php`
  - `pixel-control-plugin/src/Callbacks/CallbackRegistry.php`
  - `pixel-control-plugin/src/Queue/*`
  - `pixel-control-plugin/docs/event-contract.md`
  - `pixel-control-plugin/docs/schema/*`
  - `pixel-sm-server/scripts/bootstrap.sh`
  - `pixel-sm-server/templates/matchsettings/*.txt`
  - `pixel-sm-server/.env.example`
  - `pixel-sm-server/scripts/qa-mode-smoke.sh`
  - `pixel-sm-server/README.md`
  - `ROADMAP.md`
  - `AGENTS.md`
- Expected QA/manual evidence paths:
  - `pixel-sm-server/logs/qa/`
  - `pixel-sm-server/logs/dev/`
  - `pixel-sm-server/runtime/server/ManiaControl/ManiaControl.log`
  - `pixel-sm-server/runtime/server/Logs/`

## Success criteria

- Plugin keeps events locally queued through temporary API outages, retries deterministically, and exposes explicit outage/recovery log evidence.
- Plugin emits administrative match-flow action events with normalized naming and contextual metadata.
- Plugin emits player/combat stats covering required counters (`kills`, `deaths`, `hits`, `shots`, `misses`, `rockets`, `lasers`, `accuracy`) plus combat dimensions (`weapon_id`, `damage`, `distance`, shooter/victim positions) where callbacks provide data.
- `pixel-sm-server` supports mode presets `Elite`, `Siege`, `Battle`, `Joust`, and `Custom` through default templates and deterministic bootstrap resolution.
- `pixel-sm-server/README.md` includes a clear ShootMania title-pack name list and guidance aligned with runtime behavior.
- Automated smoke + manual game-driven checks produce reproducible evidence in documented artifact paths.

## Notes / outcomes

- Reserved for execution-time findings, blockers, and follow-up tasks.
- P0.1 file-touch implementation map (confirmed before coding):
  - Plugin runtime: `pixel-control-plugin/src/PixelControlPlugin.php`, `pixel-control-plugin/src/Callbacks/CallbackRegistry.php`, `pixel-control-plugin/src/Queue/*`.
  - Plugin contract/docs: `pixel-control-plugin/docs/event-contract.md`, `pixel-control-plugin/docs/schema/event-name-catalog-2026-02-19.1.json`, `pixel-control-plugin/docs/schema/envelope-2026-02-19.1.schema.json`.
  - Pixel SM presets/workflow: `pixel-sm-server/scripts/bootstrap.sh`, `pixel-sm-server/templates/matchsettings/*.txt`, `pixel-sm-server/.env.example`, `pixel-sm-server/scripts/qa-mode-smoke.sh`, `pixel-sm-server/README.md`.
  - Execution memory/docs sync: `ROADMAP.md`, `AGENTS.md`, and a dedicated handoff artifact for this wave.
- P0.2 contract freeze for this wave:
  - Queue outage semantics: callback-time dispatch stays bounded by `dispatch_batch_size`; retryable transport failures remain queued until retry budget exhaustion; queue pressure drops oldest entries only at capacity with explicit markers; outage state enters on first retryable transport failure and exits only after the first successful delivery.
  - Admin-action taxonomy (implemented as normalized payload on lifecycle/script callbacks): `action_name` values map to warmup/match/map/round control transitions (`warmup.start|end|status`, `match.start|end`, `map.loading.start|end`, `map.unloading.start|end`, `round.start|end`); `target` identifies match-flow scope; `actor` is emitted as `unknown` fallback unless callback payload exposes actor details.
  - Stats semantics: per-player counters include `kills`, `deaths`, `hits`, `shots`, `misses`, `rockets`, `lasers`, and computed `accuracy`; combat dimensions include `weapon_id`, `damage`, `distance`, `shooter`/`victim` identities, and shooter/victim positions when callback structures expose them; unavailable fields are reported via explicit `field_availability` markers.
- P6.3 outage/recovery validation evidence (2026-02-20):
  - Runtime outage markers confirmed in `pixel-sm-server/runtime/server/ManiaControl/ManiaControl.log` (`outage_entered`, repeated `retry_scheduled`, then `outage_recovered` and `recovery_flush_complete` after API stub restoration).
  - Recovery capture stored in `pixel-sm-server/logs/dev/wave1-api-capture-20260220-025449.ndjson`.
- P6.4 admin/stats emission validation evidence (2026-02-20):
  - API capture stub evidence in `pixel-sm-server/logs/dev/wave1-api-capture-20260220-024802.ndjson`.
  - Admin-flow `admin_action` payloads confirmed for script lifecycle transitions (`warmup.*`, `map.loading.*`, `round.start|end`, `match.start`).
  - Combat/stats payload confirmed via captured `pixel_control.combat.*` envelope including required counter keys (`kills`, `deaths`, `hits`, `shots`, `misses`, `rockets`, `lasers`, `accuracy`) and dimension/availability keys.
- P7.1 handoff artifact created: `HANDOFF-autonomous-wave-1-2026-02-20.md`.
- P7.2 current blocker:
  - Manual test C still needs real client gameplay to generate non-zero shot/hit/miss/kill/death counters. Fake-player automation produced `OnScores` stats payload structure, but not live combat exchanges.
- P7.2 user decision (2026-02-20):
  - User will run manual gameplay tests later (`"I'll do the tests later"`). Keep Manual test C and final acceptance closure pending until user-provided gameplay evidence is available.
