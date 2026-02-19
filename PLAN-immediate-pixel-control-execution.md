# PLAN - Immediate Pixel Control Execution Thread (2026-02-19)

## Context

- Purpose: Provide a concrete, session-resumable execution thread for the next implementation cycle after technical findings were consolidated in `ROADMAP.md` and local `AGENTS.md`.
- Scope: Execute four immediate priorities only: plugin skeleton in `pixel-control-plugin/`, Docker/dev baseline in `pixel-sm-server/`, roadmap thread update in `ROADMAP.md`, and status memory update in local `AGENTS.md`.
- Background / Findings: First-party code areas are still mostly placeholder-level; `ressources/` already contains reference implementations and known caveats documented in `AGENTS.md`.
- Goals:
  - Deliver a minimal ManiaControl plugin foundation ready for incremental feature work (callbacks + async API shell + queue/retry abstractions).
  - Deliver a secure, deterministic first-party ShootMania dev stack baseline with mode-parameterized matchsettings.
  - Keep roadmap and project memory synchronized so a fresh session can resume immediately.
- Non-goals: Full feature implementation, production auth selection, end-to-end gameplay validation, or edits inside `ressources/`.
- Constraints / assumptions:
  - Planner authored this file only; implementation is deferred to the Executor agent.
  - No secrets in committed files; only secure placeholders and `.env.example` variable names.
  - Keep exactly one `[In progress]` step at any time during execution.
  - Preserve plugin-first, multi-mode, local-first direction.

## Steps

Execution rule: update statuses live as work advances; do not mark multiple steps `[Done]` in one final sweep.

### Phase 0 - Plan bootstrap and alignment

- [Done] P0.1 Confirm current baseline from `ROADMAP.md` and local `AGENTS.md`.
- [Done] P0.2 Freeze immediate execution scope and ordering for this plan.

### Phase 1 - Scaffold minimal first-party plugin skeleton (`pixel-control-plugin/`)

- [Done] P1.1 Create minimal plugin folder/file structure for first-party code (entry plugin class, callback registration layer, API client shell, queue/retry interfaces, supporting README note if needed).
- [Done] P1.2 Implement a minimal ManiaControl plugin contract skeleton (`prepare`, `load`, `unload`, metadata getters) with no business logic yet.
- [Done] P1.3 Add callback registration skeleton for P0 lifecycle/player/combat hooks (registration only; lightweight stub handlers).
- [Done] P1.4 Add async API client shell abstraction (request envelope model, timeout/retry placeholders, no credentials).
- [Done] P1.5 Add local queue/retry interfaces (contract-only, in-memory or file-backed strategy deferred) and wire them to plugin flow stubs.
- [Done] P1.6 Ensure naming and structure are extensible for multi-mode support (no Elite-only assumptions).

### Phase 2 - Scaffold first-party Docker/dev baseline (`pixel-sm-server/`)

- [Done] P2.1 Create first-party Docker Compose baseline and supporting files using imported stack behavior as reference only.
- [Done] P2.2 Parameterize matchsettings/mode selection (remove hardcoded `MatchSettings/.txt` behavior in first-party scaffold).
- [Done] P2.3 Add `.env.example` with documented variable names for server, MySQL, XML-RPC, title pack, mode, and startup behavior.
- [Done] P2.4 Replace sample credentials with secure placeholders; ensure no real secrets are introduced.
- [Done] P2.5 Add deterministic startup flow documentation/scripts (DB ready check -> config templating -> ManiaControl start -> dedicated server start).

### Phase 3 - Update `ROADMAP.md` execution thread

- [Done] P3.1 Add a concise "Current execution thread" section near top-level priorities.
- [Done] P3.2 Convert immediate tasks into explicit checkpoint bullets with ownership-ready wording and next-action granularity.
- [Done] P3.3 Mark what is started/completed during this session and leave the single next step unambiguous for handoff.

### Phase 4 - Update local `AGENTS.md` session memory

- [Done] P4.1 Add a concise "Current status" note (what exists now, what was scaffolded, what remains).
- [Done] P4.2 Add a "Where we stopped" pointer with exact files/areas touched.
- [Done] P4.3 Add "Next actions" bullets aligned with the next active roadmap step.

### Phase 5 - QA and verification

- [Done] P5.1 Run targeted diff review (`git diff`) and verify only intended first-party files plus `ROADMAP.md` and `AGENTS.md` changed.
- [Done] P5.2 Run file-consistency checks: names/paths referenced in docs match created files and scripts.
- [Done] P5.3 Validate security hygiene: no secrets, only placeholders, `.env.example` contains variable names only.
- [Done] P5.4 Validate plan/roadmap/agents coherence: current step, completed steps, and next step are consistent across documents.
- [Done] P5.5 Add and run a launch-oriented QA smoke step (`pixel-sm-server`) to validate plugin sync + ManiaControl startup logs and capture runtime evidence.

### Phase 6 - Final handoff for fresh-session resume

- [Done] P6.1 Update this plan so completed items are marked and exactly one next actionable step remains `[In progress]`.
- [Done] P6.2 Add a short handoff note in `ROADMAP.md` and local `AGENTS.md` with "resume-from-here" instructions.
- [Done] P6.3 Produce executor-facing summary listing changed files and the first command/check to run next session.

### Phase 7 - Next execution seed

- [Done] P7.1 Start roadmap `Checkpoint E`: inject Pixel Control runtime config from env into plugin startup and add readiness checks (MySQL + ManiaControl/plugin marker + XML-RPC reachability) in first-party `pixel-sm-server` workflow.
- [Done] P7.2 Start roadmap `Checkpoint F`: validate selected title pack availability at startup and hard-fail early when configured title pack assets are missing from runtime.
- [Done] P7.3 Start roadmap `Checkpoint G`: make mode smoke more deterministic by addressing mode-specific map compatibility (current runtime only has Elite map assets, causing Siege/Battle smoke failures).
- [Done] P7.4 Start roadmap `Checkpoint H`: wire mode-compatible map pools/templates so Siege/Battle smoke can pass end-to-end instead of failing early on map compatibility guardrails.
- [Done] P7.5 Start roadmap `Checkpoint I`: harden battle title-pack provisioning for clean environments (optional fetch/helper flow + clearer setup defaults) so battle smoke does not depend on ad-hoc local overrides.
- [Done] P7.6 Start roadmap `Checkpoint J`: add bridge-network alternative profile/documentation path for teams that cannot use host networking defaults.
- [Done] P7.7 Start roadmap `Checkpoint K`: implement plugin-only fast sync workflow for local iteration without full image rebuild.
- [Done] P7.8 Start roadmap `Checkpoint L`: implement plugin monotonic event sequence IDs + resilient envelope dispatch defaults in callback flow.
- [Done] P7.9 Start roadmap `Checkpoint M`: normalize lifecycle callback variants with explicit context metadata envelopes.
- [Done] P7.10 Start roadmap `Checkpoint N`: bridge script-callback lifecycle signals into the same normalized lifecycle event bus.
- [Done] P7.11 Start roadmap `Checkpoint O`: add payload schema versioning metadata to envelope contract and docs.
- [Done] P7.12 Start roadmap `Checkpoint P`: document plugin/server compatibility matrix for schema/version rollout.
- [Done] P7.13 Start roadmap `Checkpoint Q`: define shared error envelope + retry semantics contract.
- [Done] P7.14 Start roadmap `Checkpoint R`: define canonical event naming + JSON schema baseline for envelope payloads.
- [Done] P7.15 Start roadmap `Checkpoint S`: define at-least-once server ingestion contract baseline (dedupe/idempotency acknowledgment semantics) aligned with plugin envelope identifiers.
- [In progress] P7.16 Start roadmap `Checkpoint T`: scaffold first server-side ingestion implementation slice aligned with the documented request/response + dedupe contract baseline.

## Success criteria

- Plugin skeleton exists in `pixel-control-plugin/` with contract, callback registration stubs, async client shell, and queue/retry interfaces.
- First-party dev stack baseline exists in `pixel-sm-server/` with mode-parameterized matchsettings and secure `.env.example` placeholders.
- `ROADMAP.md` and local `AGENTS.md` both contain a precise, synchronized execution thread and resume point.
- QA checks confirm intended diffs, cross-file consistency, and no secret leakage.

## Executor-facing summary (for fresh session resume)

- Key files changed this execution thread:
  - `pixel-control-plugin/src/PixelControlPlugin.php`
  - `pixel-control-plugin/src/Api/*`
  - `pixel-control-plugin/src/Callbacks/*`
  - `pixel-control-plugin/src/Queue/*`
  - `pixel-control-plugin/src/Retry/*`
  - `pixel-control-plugin/docs/event-contract.md`
  - `pixel-control-plugin/docs/schema/*`
  - `pixel-control-server/README.md`
  - `pixel-control-server/docs/ingestion-contract.md`
  - `pixel-control-server/docs/schema/*`
  - `pixel-sm-server/docker-compose.yml`
  - `pixel-sm-server/docker-compose.host.yml`
  - `pixel-sm-server/Dockerfile`
  - `pixel-sm-server/scripts/bootstrap.sh`
  - `pixel-sm-server/scripts/healthcheck.sh`
  - `pixel-sm-server/scripts/dev-plugin-sync.sh`
  - `pixel-sm-server/scripts/qa-launch-smoke.sh`
  - `pixel-sm-server/scripts/qa-mode-smoke.sh`
  - `pixel-sm-server/maps/**/*`
  - `pixel-sm-server/templates/**/*`
  - `pixel-sm-server/.env.example`
  - `pixel-sm-server/README.md`
  - `ROADMAP.md`
  - `AGENTS.md`
- First command/check to run next session:
  - From `pixel-sm-server/`: `bash scripts/qa-mode-smoke.sh`
- Expected success markers:
  - ShootMania log contains `Title pack asset confirmed`, `Match settings loaded`, and `Pixel Control plugin synchronized`.
  - QA output includes shootmania healthcheck pass + XML-RPC TCP readiness wait completion.
  - Mode matrix helper ends with `Elite/Siege/Battle smoke checks passed`.
  - ManiaControl log contains `[PixelControl] Plugin loaded.`
