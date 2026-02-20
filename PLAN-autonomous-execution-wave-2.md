# PLAN - Autonomous execution wave 2: admin + stats foundation (2026-02-20)

## Context

- Purpose: Advance the first real admin + stats implementation slice after wave 1, using league.paragon-esports.com capability patterns as product inspiration while staying aligned with current repo maturity.
- Scope: This wave delivers seven bounded outputs: (1) plugin admin telemetry enrichment for clearer actor/target/action semantics, (2) plugin player state/role-change telemetry surface suitable for admin workflows, (3) first executable server ingestion slice for contract `POST /plugin/events`, (4) server-side admin contract baseline for settings/server/cup/user-role operations, (5) stats aggregation contract baseline for overall metrics with mode/cup segmentation, (6) lightweight local validation tooling/scripts for deterministic replay and evidence capture, and (7) roadmap/status synchronization artifacts.
- Background / Findings:
  - `PLAN-autonomous-execution-wave-1.md` delivered queue/outage resilience, lifecycle `admin_action` payloads, and combat runtime counters, but left manual real-client gameplay closure pending.
  - `pixel-control-server/` is still contract-first (docs + JSON schema) without executable ingestion implementation.
  - Observed Paragon league capabilities to mirror at baseline level: overall statistics view with aggregate + segmented metrics, and admin modules (settings, server pool/registry controls, cup lifecycle controls, user role/permission controls, and linked domains bans/teams/mappacks/polls).
- Goals:
  - Move from contract-only server docs to a minimal runnable ingestion baseline aligned with current schemas and dedupe semantics.
  - Make admin telemetry payloads more explicit so downstream processing can reliably classify intent, actor type, and target scope.
  - Establish a first stats aggregate contract that can back an "overall statistics" page pattern (global + per-mode + per-cup dimensions).
  - Produce reproducible QA/evidence workflows so future waves can extend behavior without re-discovery.
- Non-goals: Full Laravel platform bootstrap, production auth model finalization, full admin UI implementation, complete cup/team/ban/mappack/poll feature implementation, changes under `ressources/`, or endless roadmap expansion.
- Constraints / assumptions:
  - Treat `pixel-control-plugin/`, `pixel-control-server/`, and `pixel-sm-server/` as first-party boundaries; keep `ressources/` read-only.
  - Keep the wave finite and execution-ready, with clear done criteria and verifiable artifacts.
  - Keep exactly one `[In progress]` step at a time during execution.
  - Maintain compatibility with schema baseline conventions and explicit versioning when contract semantics change.

## Steps

Execution rule: update statuses live while executing; keep only one active `[In progress]` step.

### Phase 0 - Recon and wave lock

- [Done] P0.1 Lock wave-2 file-touch map from current repo state and Paragon-inspired capability mapping.
  - Confirm exact plugin/server/docs/scripts touch points needed for admin telemetry enrichment, ingestion baseline, and aggregate stats baseline.
  - Freeze a capability-to-deliverable mapping so this wave remains finite (what is implemented now vs documented as deferred).
- [Done] P0.2 Freeze contract/versioning strategy for this wave.
  - Decide whether changes stay in `2026-02-19.1` with additive compatibility or require a version bump.
  - Record versioning decision in plugin/server contract docs before implementation edits.

### Phase 1 - Plugin admin telemetry enrichment

- [Done] P1.1 Enrich lifecycle `admin_action` semantics in plugin payloads.
  - Add normalized semantics for `action_domain`, `action_type`, `action_phase`, `target_scope`, `target_id`, and `initiator_kind` where callback data allows.
  - Keep deterministic fallback behavior (`unknown`/missing-field markers) so ingestion logic remains stable under partial callback payloads.
- [Done] P1.2 Add structured player state/role transition payloads for admin-relevant events.
  - Use player callbacks to emit normalized state deltas (connectivity, spectator/team changes, and permission-like signals when available).
  - Keep this as telemetry baseline (not enforcement) and align naming with existing event catalog conventions.
- [Done] P1.3 Synchronize plugin event contract artifacts.
  - Update `pixel-control-plugin/docs/event-contract.md` and relevant schema/catalog files under `pixel-control-plugin/docs/schema/` to cover new fields and semantics.

### Phase 2 - Server ingestion implementation slice (roadmap Checkpoint T closure path)

- [Done] P2.1 Scaffold a minimal runnable ingestion slice in `pixel-control-server/` aligned with existing contract docs.
  - Implement a local-first endpoint surface for `POST /plugin/events` (non-Laravel bootstrap acceptable for this wave) with typed acknowledgment behavior.
  - Preserve at-least-once dedupe semantics (`dedupe_scope` + `idempotency_key` -> unique receipt).
- [Done] P2.2 Persist ingestion receipts and raw envelopes for replay/debug.
  - Store receipt metadata (`processed|duplicate|rejected`, timestamps, duplicate counters) and raw envelope payload snapshots.
  - Keep storage simple and deterministic for local development (schema-first, migration-light baseline).
- [Done] P2.3 Add deterministic duplicate-handling verification hooks.
  - Provide a repeatable way to submit duplicate payloads and confirm `ack.disposition=duplicate` without re-running side effects.

### Phase 3 - Admin domain contract baseline (settings/server/cup/user)

- [Done] P3.1 Author server-side admin action contract document for management operations inspired by Paragon admin modules.
  - Cover settings updates, server pool start/shutdown + registry operations, cup start/end lifecycle, and user role/permission changes.
  - Explicitly classify bans/teams/mappacks/polls as linked domains with baseline contract placeholders (not fully implemented modules in this wave).
- [Done] P3.2 Add machine-readable schema artifacts for admin action payload projections.
  - Define required/optional fields for actor, target, action, state transition, and audit metadata.
  - Ensure schema references remain compatible with plugin envelope contract conventions.
- [Done] P3.3 Add compatibility and rollout notes.
  - Document how enriched plugin telemetry maps into server admin contracts and what remains deferred to later waves.

### Phase 4 - Stats aggregation contract baseline (overall + segmentation)

- [Done] P4.1 Define baseline aggregate model for an "overall statistics" view.
  - Include core totals (kills/deaths/hits/shots/misses/accuracy and event volume) with clear source-of-truth definitions.
  - Define time-window handling and update cadence assumptions.
- [Done] P4.2 Define segmentation dimensions and query contract.
  - Baseline dimensions: `mode`, `cup`, `server`, and `player` slices with explicit null/default semantics when context is unavailable.
  - Add schema/docs for aggregate query inputs/outputs (contract-first).
- [Done] P4.3 Wire first aggregate derivation path in the ingestion slice.
  - Derive and persist minimal aggregate snapshots from ingested combat/lifecycle events to prove end-to-end feasibility.

### Phase 5 - Local validation tooling and QA evidence

- [Done] P5.1 Add lightweight local tooling/scripts for admin + stats validation.
  - Add reproducible script flows to replay captured envelopes and inspect ingestion receipts/aggregate snapshots.
  - Keep tooling local-first and aligned with existing `pixel-sm-server/scripts/*` QA style.
- [Done] P5.2 Execute deterministic QA matrix for this wave.
  - Validate: schema acceptance/rejection, dedupe behavior, admin-action semantic coverage, and mode/cup segmented aggregate outputs.
  - Keep manual real-client gameplay checks as optional additive evidence when available; do not block deterministic baseline checks.
- [Done] P5.3 Capture evidence artifacts and acceptance notes.
  - Store logs/reports under explicit paths and reference expected markers for replay.

### Phase 6 - Roadmap and project-memory synchronization

- [Done] P6.1 Synchronize `ROADMAP.md` checkpoint thread.
  - Mark completed checkpoint(s) from this wave (including `Checkpoint T` when criteria are met).
  - Add the single next checkpoint for follow-up admin/stats expansion with explicit owner-ready wording.
- [Done] P6.2 Synchronize local `AGENTS.md` execution status.
  - Update "current execution status", "where we stopped", and "exact next action" to reflect wave-2 outcomes.
- [Done] P6.3 Produce wave-2 handoff artifact.
  - Add a dedicated `HANDOFF-*.md` with changed-file map, rerun commands, QA markers, and pending manual follow-ups.

## Evidence / Artifacts

- Planned implementation targets:
  - `pixel-control-plugin/src/PixelControlPlugin.php`
  - `pixel-control-plugin/src/Callbacks/CallbackRegistry.php`
  - `pixel-control-plugin/docs/event-contract.md`
  - `pixel-control-plugin/docs/schema/*`
  - `pixel-control-server/docs/ingestion-contract.md`
  - `pixel-control-server/docs/*` (new admin/stats contract docs)
  - `pixel-control-server/docs/schema/*` (new/updated schema artifacts)
  - `pixel-control-server/*` (new minimal ingestion implementation slice)
  - `pixel-sm-server/scripts/*` (new/updated local validation helpers)
  - `ROADMAP.md`
  - `AGENTS.md`
- Expected QA/evidence outputs:
  - `pixel-sm-server/logs/qa/`
  - `pixel-sm-server/logs/dev/`
  - `pixel-control-server/logs/` (or equivalent local evidence directory created by this wave)
  - `HANDOFF-autonomous-wave-2-*.md`

## Success criteria

- Plugin emits enriched admin telemetry with explicit actor/target/action semantics and deterministic missing-field handling.
- A runnable server ingestion slice exists in `pixel-control-server/` and returns contract-aligned `accepted|duplicate|rejected` acknowledgments.
- Server contract/docs include a concrete baseline for admin actions (settings/server/cup/user role/permission changes) plus linked-domain placeholders (bans/teams/mappacks/polls).
- Stats aggregation contract baseline exists for overall metrics and mode/cup segmentation, with at least one end-to-end derivation path validated from ingested events.
- Local validation tooling can deterministically replay/admin-stats payloads and produce reproducible evidence artifacts.
- `ROADMAP.md`, `AGENTS.md`, and wave handoff artifacts are synchronized with a single unambiguous next action.

## Notes / outcomes

- Reserved for execution-time findings, blockers, and follow-up decisions.
- P0.1 file-touch map + capability freeze (confirmed before edits):
  - Plugin runtime implementation: `pixel-control-plugin/src/PixelControlPlugin.php` (admin-action semantics enrichment + player transition telemetry payloads).
  - Plugin contract artifacts: `pixel-control-plugin/docs/event-contract.md` and `pixel-control-plugin/docs/schema/*` (catalog + envelope + lifecycle payload schema updates for admin/player semantics).
  - Server executable ingestion slice: new runtime files under `pixel-control-server/src/*`, `pixel-control-server/public/index.php`, and local storage under `pixel-control-server/var/*`.
  - Server contracts/schemas: `pixel-control-server/docs/ingestion-contract.md`, new admin/stats docs under `pixel-control-server/docs/`, and schema updates under `pixel-control-server/docs/schema/*`.
  - Local replay/QA tooling: new scripts in `pixel-sm-server/scripts/` to replay payloads against `POST /plugin/events` and inspect receipts/aggregate snapshots in deterministic logs.
  - Synchronization artifacts: `ROADMAP.md`, root `AGENTS.md`, and a dedicated `HANDOFF-autonomous-wave-2-*.md`.
  - Deferred but documented as linked placeholders this wave (not implemented modules): bans, teams, mappacks, polls domain execution logic/UI.
- P0.2 contract/versioning freeze:
  - Wave-2 uses schema version `2026-02-20.1` (version bump) because payload semantics change for lifecycle `admin_action` and player telemetry.
  - `2026-02-19.1` is retained as historical compatibility/reference baseline; implementation and docs in this wave move to `2026-02-20.1` as active contract.
- Phase 1 implementation outcome:
  - Plugin lifecycle `admin_action` payloads now include normalized semantics (`action_domain`, `action_type`, `action_phase`, `target_scope`, `target_id`, `initiator_kind`) with deterministic `unknown` fallbacks and updated metadata mirrors.
  - Player callbacks now emit structured transition telemetry (`event_kind`, `transition_kind`, `player`/`previous_player`, `state_delta`, `permission_signals`, availability markers) instead of callback-summary-only payloads.
  - New plugin contract artifacts added for `2026-02-20.1` under `pixel-control-plugin/docs/schema/` and `pixel-control-plugin/docs/event-contract.md` updated to the wave-2 active baseline.
- Phase 2-5 deterministic QA evidence (2026-02-20):
  - Replay smoke summary: `pixel-sm-server/logs/qa/wave2-admin-stats-20260220-121545-summary.md`.
  - Replay responses: `pixel-sm-server/logs/qa/wave2-admin-stats-20260220-121545-*.json` (processed admin/player/combat, duplicate replay, and `schema_version_unsupported` rejection markers).
  - Duplicate hook report: `pixel-control-server/var/ingestion/dedupe-check-20260220-121541.json`.
  - Ingestion storage evidence: `pixel-control-server/var/ingestion/receipts.json`, `pixel-control-server/var/ingestion/envelopes.ndjson`, `pixel-control-server/var/ingestion/aggregates.json`, `pixel-control-server/var/ingestion/rejections.ndjson`.
  - Additional assertions executed from CLI:
    - admin-action semantic fields persisted in envelopes (`action_domain`, `action_type`, `action_phase`, `target_scope`, `target_id`, `initiator_kind`),
    - cup segmentation includes explicit keyed cup and `none` fallback,
    - duplicate receipt counters increment on replayed idempotency keys.
- Phase 6 closure:
  - Roadmap checkpoint thread synchronized (`Checkpoint T` marked done, `Checkpoint U` introduced as next single action).
  - Local memory synchronized in `AGENTS.md` (wave-2 outcomes + next action + evidence pointers).
  - Wave-2 handoff artifact created: `HANDOFF-autonomous-wave-2-2026-02-20.md`.
