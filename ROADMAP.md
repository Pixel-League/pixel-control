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

## Current execution thread (session: 2026-02-19)

Active plan file:
- `PLAN-immediate-pixel-control-execution.md` (resume marker currently on `P7.16`)

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
- [x] `Checkpoint S` (Owner: Executor) - Defined at-least-once server ingestion contract baseline (dedupe/idempotency acknowledgment semantics) in `pixel-control-server/` docs with request/response schema artifacts.
- [ ] `Checkpoint T` (Owner: Executor, next) - Scaffold first server-side ingestion implementation slice (endpoint stubs + dedupe receipt persistence plan) aligned with the new ingestion contract baseline.

Resume-from-here (single next action):
- Start `Checkpoint T` by scaffolding minimal server endpoint/documented persistence slice implementing the ingestion contract baseline.
- Handoff note: keep current mode script + title-pack + map compatibility fail-fast checks, networking profile docs, plugin fast-sync workflow, lifecycle variant naming, and mode smoke matrix as acceptance guards.

## Pixel Control Plugin

### Connectivity
- [x] P0🔥 Build API client with retry/backoff/timeout policies
- [x] P0🔥 Add plugin registration handshake with capability payload
- [x] P0🔥 Add server heartbeat (mode/map/player-count/health)
- [x] P0🔥 Emit monotonic local event sequence numbers for ordering/replay diagnostics
- [ ] P1 Add local event queue for temporary API outages
- [x] P1 Version plugin payload schemas

### Match lifecycle
- [x] P0🔥 Emit warmup, match start/end, map start/end, round start/end events
- [x] P0🔥 Include context metadata (server login/name, title, mode, match settings)
- [x] P0🔥 Normalize ManiaPlanet + script callbacks into a single internal lifecycle event bus
- [ ] P1 Emit administrative actions that alter match flow

### Stats
- [ ] P1 Capture player stats: kills, deaths, hits, shots, misses, rockets, lasers, accuracy
- [ ] P1 Include combat dimensions (weapon id, damage, distance, shooter/victim positions)
- [ ] P2 Capture per-round and per-map aggregates
- [ ] P2 Capture team-side aggregates and win-condition context
- [ ] P1 Add event idempotency keys to avoid duplicate processing

### Players
- [ ] P1 Sync roster state (connected, spectator, team, readiness)
- [ ] P1 Sync eligibility and permission state for who can play
- [ ] P2 Handle reconnects and side changes deterministically
- [ ] P2 Add constraints for forced teams and slot policies

### Maps
- [ ] P1 Sync map pack and map rotation metadata
- [ ] P1 Export veto/draft actions (ban/pick actor, order, timestamps)
- [ ] P1 Export final veto result and played map order
- [ ] P2 Sync map identifiers (uid, name, optional MX id)

### Admin UX
- [ ] P2 Add health/debug command surface for admins
- [ ] P2 Add match-control commands (start/cancel/sync)
- [ ] P5 Add clear chat/manialink notifications for workflow states

## Pixel Control Server

### Platform and DX (local-first)
- [ ] P0🔥 Scaffold Laravel API with local-first workflow
- [ ] P0🔥 Define domain modules (Stats, Players, Maps, Match)
- [ ] P1 Publish OpenAPI/Swagger docs for all endpoints
- [ ] P1 Add structured logs and request correlation ids

### Ingestion API
- [ ] P0🔥 Registration endpoint for plugin capability negotiation
- [ ] P0🔥 Heartbeat endpoint for server liveness
- [ ] P0🔥 Lifecycle event ingestion endpoint
- [ ] P1 Stats ingestion endpoint (single + batch)
- [ ] P1 Maps/veto ingestion endpoint
- [ ] P0🔥 Idempotency support for all write endpoints

### Domain: Stats
- [ ] P1 Store raw events and derived aggregates
- [ ] P2 Build per-player/per-team/per-match read models
- [ ] P2 Expose stats query endpoints for dashboards and tooling
- [ ] P4 Add recalculation/backfill jobs

### Domain: Players
- [ ] P1 Persist per-server player eligibility and permissions
- [ ] P1 Expose APIs to manage allowed rosters
- [ ] P2 Add audit trail for permission changes

### Domain: Maps
- [ ] P1 Persist map pools, map packs, veto sessions, map order
- [ ] P2 Expose APIs for map-pool management
- [ ] P5 Add map metadata enrichment workflow (MX ids, aliases)

### Domain: Match
- [ ] P1 Build canonical match timeline from lifecycle events
- [ ] P1 Add explicit match state machine (pending/active/ended/cancelled)
- [ ] P2 Expose match assignment APIs for plugin execution

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
- [ ] P0🔥 Guarantee at-least-once delivery with server-side dedupe
- [ ] P2 Define replay/backfill strategy after outages
- [ ] P2 Define recovery flow after partial desynchronization

### Cross-domain workflows
- [ ] P2 Match assignment flow (server decision -> plugin ack)
- [ ] P1 Player eligibility flow (server policy -> plugin enforcement)
- [ ] P1 Maps/veto flow (shared ownership + conflict resolution)

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
- [ ] P1 Add mode presets (Elite, Siege, Battle, Joust, Custom) with default matchsettings templates
- [x] P1 Support custom maps/matchsettings/titlepacks via mounted volumes
- [ ] P2 Add multi-instance local support with deterministic port offsets

### Pixel Control Plugin bundling
- [x] P0🔥 Auto-bundle/install `Pixel Control Plugin` into ManiaControl in the dev server image
- [x] P1 Inject plugin runtime config from env (Pixel Control Server URL, auth mode, retry policy)
- [x] P1 Add startup verification that the plugin is loaded and active
- [x] P2 Add local-dev plugin sync workflow optimized for quick iteration

### Developer experience
- [x] P1 Document one-command workflows in `pixel-sm-server/README.md` (`up`, `down`, `logs`, `rebuild`)
- [ ] P1 Add a clear ShootMania title-pack name list in `pixel-sm-server/README.md` (for example Elite/Siege/Battle/Joust/Royal)
- [x] P1 Add smoke checks for DB connectivity, XML-RPC reachability, and ManiaControl boot
- [x] P2 Add mode smoke checks for at least Elite, Siege, and Battle
- [ ] P3 Add CI image build + security scanning for `pixel-sm-server`

### Security and hardening
- [x] P0🔥 Remove hardcoded credentials from first-party templates and use secure placeholders
- [x] P1 Document `network_mode: host` caveats and provide a bridge-network alternative profile
- [ ] P2 Define persisted volume strategy (server data, mysql data, logs) and backup procedure

## Open decisions
- [ ] P3 Final authentication model between plugin and server
- [ ] P2 Initial production rollout order across ShootMania modes
- [ ] P2 Data retention policy for raw events vs aggregates
