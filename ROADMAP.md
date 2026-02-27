# ROADMAP

Planning baseline for Pixel Control. This file is the source of truth when discussing `ROADMAP`.

Principles:
- Plugin-first delivery
- Multi-mode ShootMania support
- Local-first developer experience
- `ressources/` used as reference, not first-party product code

Priority scale:
- `P0🔥`: mandatory for first end-to-end usable iteration
- `P1`: high priority, should follow immediately after P0
- `P2`: important, can ship in second wave
- `P3`: medium priority, structuring but not blocking early delivery
- `P4`: low priority, optimization/hardening later
- `P5`: backlog/nice-to-have

## Current execution thread (session: 2026-02-24)

Active plan files:
- `PLAN-autonomous-execution-wave-5.md` (plugin/dev-server closure complete; real-client manual gameplay evidence remains user-run)
- `PLAN-pixel-control-server-nestjs-mvp.md` (backend reopened and implemented as NestJS MVP in `pixel-control-server/`)
- `PLAN-pixel-control-server-nestjs-wave2-no-db.md` (wave-2 no-DB backend expansion completed: additive read models/endpoints + diagnostics/logging + write-compat guardrails)
- `PLAN-pixel-control-server-nestjs-wave3-control-workflow-no-db.md` (wave-3 no-DB control/workflow APIs completed: eligibility policy, map-pool policy, assignment intents, desired-state, bounded control audit, write-compat guardrails)
- `PLAN-pixel-control-server-prisma-raw-traceability-foundation.md` (wave-4 Prisma SQLite persistence foundation for raw lineage facts, additive/non-breaking write-route behavior)

Checkpoint status (owner-ready):
- [x] `Checkpoint A` (Owner: Executor) - Scaffolded first-party plugin foundation in `pixel-control-plugin/` with Plugin contract, callback registry stubs, async API shell, and queue/retry contracts.
- [x] `Checkpoint B` (Owner: Executor) - Scaffolded first-party local dev stack baseline in `pixel-sm-server/` with Docker Compose, deterministic bootstrap script, templates, and secure placeholder env surface.
- [x] `Checkpoint C` (Owner: Executor) - Added mode/matchsettings parameterization (`PIXEL_SM_MODE` + `PIXEL_SM_MATCHSETTINGS`) and removed hardcoded `MatchSettings/.txt` behavior from first-party startup flow.
- [x] `Checkpoint D` (Owner: Executor) - Bundled `PixelControlPlugin` into `pixel-sm-server` bootstrap flow, added fail-fast install guards, and validated load marker through `scripts/qa-launch-smoke.sh`.
- [x] `Checkpoint E` (Owner: Executor) - Injected plugin runtime config from env and added readiness probes (MySQL + ManiaControl/plugin + XML-RPC) in first-party workflows.
- [x] `Checkpoint F` (Owner: Executor) - Added startup validation for `PIXEL_SM_TITLE_PACK` asset availability and fail-fast logs listing available runtime title pack assets.
- [x] `Checkpoint G` (Owner: Executor) - Added mode-aware map compatibility guardrails so non-Elite modes fail early with explicit map guidance instead of late dedicated launch errors.
- [x] `Checkpoint H` (Owner: Executor) - Wired mode-compatible Siege/Battle map pools/templates, validated script compatibility, and passed Elite/Siege/Battle smoke matrix.
- [x] `Checkpoint I` (Owner: Executor) - Hardened battle title-pack provisioning for clean setups (helper flow/defaults) so battle smoke works without ad-hoc local path overrides.
- [x] `Checkpoint J` (Owner: Executor) - Added host-network caveat documentation plus a first-party bridge-default/host-override profile path for teams that cannot rely on host networking defaults.
- [x] `Checkpoint K` (Owner: Executor) - Added local-dev plugin fast-sync workflow (`scripts/dev-plugin-sync.sh`) that restarts only `shootmania`, re-syncs plugin code, and validates plugin load markers without rebuilding images.
- [x] `Checkpoint L` (Owner: Executor) - Implemented plugin connectivity P0 core in `pixel-control-plugin/`: monotonic source sequencing, resilient bounded dispatch/retry defaults, startup registration envelope, and periodic heartbeat envelope.
- [x] `Checkpoint M` (Owner: Executor) - Implemented lifecycle normalization P0 by emitting explicit warmup/match/map/round variants plus runtime context metadata (server/title/mode/map snapshot) through the callback envelope pipeline.
- [x] `Checkpoint N` (Owner: Executor) - Extended lifecycle normalization to include script-callback lifecycle signals on the same lifecycle event bus and aligned variant naming across ManiaPlanet + script channels.
- [x] `Checkpoint O` (Owner: Executor) - Added payload schema versioning + canonical envelope metadata (`schema_version` + `event_id`) so plugin/server contract evolution is explicit and backward-compatible.
- [x] `Checkpoint P` (Owner: Executor) - Defined first compatibility matrix baseline in plugin docs (plugin version <-> envelope schema version) for safe contract rollout.
- [x] `Checkpoint Q` (Owner: Executor) - Defined shared delivery error envelope + retry semantics contract (typed `DeliveryError`, retryable flags, retry-after hints, and ack parsing semantics).
- [x] `Checkpoint R` (Owner: Executor) - Defined canonical event naming catalog + JSON schema baseline for envelope payloads and lifecycle variants.
- [x] `Checkpoint S` (Owner: Executor) - Defined contract baseline for plugin->API ingestion and idempotency semantics.
- [x] `Checkpoint T` (Owner: Executor) - Runnable backend/API ingestion MVP is implemented in `pixel-control-server/` (NestJS + typed contract ACK/rejected/error + compatibility routes).
- [x] `Checkpoint Y` (Owner: Executor) - NestJS wave-2 no-DB backend expansion is complete (additive players/stats/maps/match/registration/diagnostics reads, structured ingestion outcome logging, and write-contract compatibility evidence under `pixel-control-server/logs/nestjs-wave2-no-db/`).
- [ ] `Checkpoint U` (Owner: Executor, active) - Extend backend beyond MVP to durable storage and production auth hardening; wave-4 Prisma raw-traceability SQLite foundation is delivered as additive with write-route compatibility preserved.
- [x] `Checkpoint V` (Owner: Executor) - Continued plugin-first execution with wave-3 telemetry expansion (roster/eligibility/admin-correlation + round/map aggregate + map-rotation baseline), deterministic local QA replay helper, and contract/doc synchronization.
- [x] `Checkpoint W` (Owner: Executor) - Delivered wave-4 additive telemetry closure for team-side aggregate + win-context semantics, reconnect/side-change deterministic payloads, veto action/result export fallback semantics, and deterministic QA evidence indexing.
- [x] `Checkpoint X` (Owner: Executor) - Delivered wave-5 plugin/dev-server hardening with deterministic identity validation drops, forced-team/slot-policy constraint signaling (telemetry-only), wave-5 manual evidence scaffolding/checkers, and final-wave handoff synchronization.

Resume-from-here (single next action):
- Plugin/dev-server thread: user-run real-client gameplay matrix execution remains open using `pixel-sm-server/logs/manual/wave5-real-client-20260220/MANUAL-TEST-MATRIX.md` plus `INDEX.md` evidence tracking.
- Backend thread: move from in-memory wave-3 backend to persistent storage + production auth model + plugin assignment/policy handshake while keeping plugin write-route contract additive.
- Handoff note: keep route expectations in `API_CONTRACT.md` and plugin capability inventory in `pixel-control-plugin/README.md` synchronized for every additive telemetry change.

## Pixel Control Plugin

### Connectivity
- [x] P0🔥 Build API client with retry/backoff/timeout policies
- [x] P0🔥 Add plugin registration handshake with capability payload
- [x] P0🔥 Add server heartbeat (mode/map/player-count/health)
- [x] P0🔥 Emit monotonic local event sequence numbers for ordering/replay diagnostics
- [x] P1 Add local event queue for temporary API outages
- [x] P1 Version plugin payload schemas

### Match lifecycle
- [x] P0🔥 Emit warmup, match start/end, map start/end, round start/end events
- [x] P0🔥 Include context metadata (server login/name, title, mode, match settings)
- [x] P0🔥 Normalize ManiaPlanet + script callbacks into a single internal lifecycle event bus
- [x] P1 Emit administrative actions that alter match flow

### Stats
- [x] P1 Capture player stats: kills, deaths, hits, shots, misses, rockets, lasers, accuracy
- [x] P1 Include combat dimensions (weapon id, damage, distance, shooter/victim positions)
- [x] P2 Capture per-round and per-map aggregates
- [x] P2 Capture team-side aggregates and win-condition context
- [x] P1 Add event idempotency keys to avoid duplicate processing

### Players
- [x] P1 Sync roster state (connected, spectator, team, readiness)
- [x] P1 Sync eligibility and permission state for who can play
- [x] P2 Handle reconnects and side changes deterministically
- [x] P2 Add constraints for forced teams and slot policies

Player constraint note: wave-5 implementation exposes forced-team/slot-policy state as deterministic telemetry signals (`constraint_signals`) with explicit availability/fallback reasons; enforcement remains external/runtime-driven (no plugin-side authority rewrite).

### Maps
- [x] P1 Sync map pack and map rotation metadata
- [x] P1 Export veto/draft actions (ban/pick actor, order, timestamps)
- [x] P1 Export final veto result and played map order
- [x] P2 Sync map identifiers (uid, name, optional MX id)

Note: wave-5 keeps schema `2026-02-20.1` additive and adds identity-validation guardrails plus `player.constraint_signals` (forced-team/slot-policy telemetry + deterministic fallback markers), with deterministic evidence under `pixel-sm-server/logs/qa/wave5-evidence-index-20260220.md` and final execution handoff in `HANDOFF-autonomous-wave-5-2026-02-20.md`; real-client gameplay evidence capture remains open until user-run sessions are completed.

### Admin UX
- [ ] P2 Add health/debug command surface for admins
- [ ] P2 Add match-control commands (start/cancel/sync)
- [ ] P5 Add clear chat/manialink notifications for workflow states

## Pixel Control Server

### Platform and DX (local-first)
- [x] P0🔥 Scaffold NestJS API with local-first workflow
- [x] P0🔥 Define MVP modules (`Ingestion`, `ReadModel`, `Auth`, `Health`)
- [x] P1 Publish OpenAPI/Swagger docs for implemented endpoints
- [x] P1 Add request correlation ids for contract responses
- [x] P2 Add structured operational logging and sink strategy

### Ingestion API
- [x] P0🔥 Connectivity ingestion endpoint for registration + heartbeat envelopes
- [x] P0🔥 Lifecycle event ingestion endpoint
- [x] P1 Stats ingestion endpoint (single + batch)
- [x] P1 Admin/player/mode ingestion endpoints + compatibility bridge (`/plugin/events`, `/v1/plugin/events`)
- [x] P0🔥 Idempotency support for all write endpoints
- [ ] P2 Durable receipt/event storage (restart-safe)

### Domain: Stats
- [x] P1 In-memory raw-event journal + aggregate counters for ingestion/read-model MVP
- [x] P1 Add no-DB stats read endpoints (`/v1/servers/:serverLogin/stats/{summary,players}`)
- [ ] P2 Build durable per-player/per-team/per-match read models
- [ ] P2 Expose extended stats query endpoints for dashboards and tooling
- [ ] P4 Add recalculation/backfill jobs

### Domain: Players
- [x] P1 Add no-DB per-server player snapshot read endpoints (`/v1/servers/:serverLogin/players*`)
- [x] P1 Add no-DB per-server player eligibility policy API (`/v1/servers/:serverLogin/control/player-eligibility-policy`)
- [ ] P1 Persist per-server player eligibility and permissions
- [ ] P1 Add plugin enforcement handshake for eligibility policies
- [ ] P2 Add audit trail for permission changes

### Domain: Maps
- [x] P1 Add no-DB map/veto state read endpoint (`/v1/servers/:serverLogin/maps/state`)
- [x] P1 Add no-DB map-pool policy API (`/v1/servers/:serverLogin/control/map-pool-policy`)
- [ ] P1 Persist map pools, map packs, veto sessions, map order
- [ ] P2 Add plugin enforcement handshake for map-pool policies
- [ ] P5 Add map metadata enrichment workflow (MX ids, aliases)

### Domain: Match
- [x] P1 Build canonical in-memory timeline from lifecycle events
- [x] P1 Add explicit no-DB match state machine read endpoint (`/v1/servers/:serverLogin/match/state`)
- [x] P2 Add no-DB match assignment intent APIs (`/v1/servers/:serverLogin/control/match-assignment-intents*`) with desired-state/audit projection
- [ ] P2 Add plugin execution handshake for assignment intent ack/cancel flow

### Auth and security (decision pending)
- [ ] P1 Document candidate auth models: API key per server, OAuth2-based, HMAC signatures
- [ ] P2 Define replay-protection and key rotation strategy per model
- [ ] P3 Select one model only after practical validation

## Pixel Control Plugin + Server

### Shared contract
- [x] P0🔥 Define canonical event naming and JSON schemas
- [x] P1 Define standard envelope fields (`event_id`, `source_callback`, `source_time`, `source_sequence`)
- [x] P1 Define compatibility matrix (plugin version <-> server API version)
- [x] P0🔥 Define shared error format and retry semantics

### Reliability
- [x] P0🔥 Guarantee at-least-once delivery with server-side dedupe (MVP in-memory implementation)
- [ ] P2 Define replay/backfill strategy after outages
- [ ] P2 Define recovery flow after partial desynchronization

### Cross-domain workflows
- [ ] P2 Match assignment flow handshake (server decision -> plugin ack)
- [ ] P1 Player eligibility policy handshake (server policy -> plugin enforcement)
- [ ] P1 Maps policy handshake (server policy -> runtime conflict resolution)

### Test strategy
- [ ] P1 Build callback simulation harness for XML-RPC and script callbacks
- [ ] P1 Add plugin/server contract tests
- [ ] P0🔥 Add end-to-end dev test harness using `pixel-sm-server` (dedicated server + ManiaControl + plugin + API)
- [ ] P2 Add multi-mode acceptance tests (Elite, Siege, Battle, etc.)
- [ ] P4 Add performance tests for stats ingestion and queries

### Operations
- [ ] P3 Define deployment topology and environment split
- [ ] P2 Define retention and backup policy for stats/match data
- [ ] P3 Define runbooks for outages and degraded modes

## Pixel SM Server

### Foundation
- [x] P0🔥 Scaffold first-party Docker stack in `pixel-sm-server/` from reference behavior
- [x] P0🔥 Add `.env.example` (env names only) for server, database, XML-RPC, and mode configuration
- [x] P0🔥 Implement deterministic startup sequence (DB ready -> XML templating -> ManiaControl -> dedicated server)
- [x] P1 Add healthchecks/readiness probes for MySQL, ManiaControl, and XML-RPC

### Mode orchestration
- [x] P0🔥 Replace hardcoded `/game_settings=MatchSettings/.txt` with configurable matchsettings input
- [x] P0🔥 Make title pack fully configurable and validate pack availability at startup
- [x] P0🔥 Ensure selected title pack assets are available in runtime (copy or mount `TitlePacks/`)
- [x] P1 Add mode presets (Elite, Siege, Battle, Joust, Custom) with default matchsettings templates
- [x] P1 Support custom maps/matchsettings/titlepacks via mounted volumes
- [ ] P2 Add multi-instance local support with deterministic port offsets

### Pixel Control Plugin bundling
- [x] P0🔥 Auto-bundle/install `Pixel Control Plugin` into ManiaControl in the dev server image
- [x] P1 Inject plugin runtime config from env (Pixel Control Server URL, auth mode, retry policy)
- [x] P1 Add startup verification that the plugin is loaded and active
- [x] P2 Add local-dev plugin sync workflow optimized for quick iteration

### Developer experience
- [x] P1 Document one-command workflows in `pixel-sm-server/README.md` (`up`, `down`, `logs`, `rebuild`)
- [x] P1 Add a clear ShootMania title-pack name list in `pixel-sm-server/README.md` (for example Elite/Siege/Battle/Joust/Royal)
- [x] P1 Add smoke checks for DB connectivity, XML-RPC reachability, and ManiaControl boot
- [x] P2 Add mode smoke checks for at least Elite, Siege, and Battle
- [x] P2 Add deterministic telemetry replay helper for plugin admin/player/aggregate/map evidence capture
- [ ] P3 Add CI image build + security scanning for `pixel-sm-server`

### Security and hardening
- [x] P0🔥 Remove hardcoded credentials from first-party templates and use secure placeholders
- [x] P1 Document `network_mode: host` caveats and provide a bridge-network alternative profile
- [ ] P2 Define persisted volume strategy (server data, mysql data, logs) and backup procedure

## Open decisions
- [ ] P3 Final authentication model between plugin and server
- [ ] P2 Initial production rollout order across ShootMania modes
- [ ] P2 Data retention policy for raw events vs aggregates
