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

## Pixel Control Plugin

### Connectivity
- [ ] P0🔥 Build API client with retry/backoff/timeout policies
- [ ] P0🔥 Add plugin registration handshake with capability payload
- [ ] P0🔥 Add server heartbeat (mode/map/player-count/health)
- [ ] P1 Add local event queue for temporary API outages
- [ ] P1 Version plugin payload schemas

### Match lifecycle
- [ ] P0🔥 Emit warmup, match start/end, map start/end, round start/end events
- [ ] P0🔥 Include context metadata (server login/name, title, mode, match settings)
- [ ] P1 Emit administrative actions that alter match flow

### Stats
- [ ] P1 Capture player stats: kills, deaths, hits, shots, misses, rockets, lasers, accuracy
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
- [ ] P0🔥 Define canonical event naming and JSON schemas
- [ ] P1 Define compatibility matrix (plugin version <-> server API version)
- [ ] P0🔥 Define shared error format and retry semantics

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
- [ ] P0🔥 Scaffold first-party Docker stack in `pixel-sm-server/` from reference behavior
- [ ] P0🔥 Add `.env.example` (env names only) for server, database, XML-RPC, and mode configuration
- [ ] P0🔥 Implement deterministic startup sequence (DB ready -> XML templating -> ManiaControl -> dedicated server)
- [ ] P1 Add healthchecks/readiness probes for MySQL, ManiaControl, and XML-RPC

### Mode orchestration
- [ ] P0🔥 Replace hardcoded `/game_settings=MatchSettings/.txt` with configurable matchsettings input
- [ ] P0🔥 Make title pack fully configurable and validate pack availability at startup
- [ ] P1 Add mode presets (Elite, Siege, Battle, Joust, Custom) with default matchsettings templates
- [ ] P1 Support custom maps/matchsettings/titlepacks via mounted volumes
- [ ] P2 Add multi-instance local support with deterministic port offsets

### Pixel Control Plugin bundling
- [ ] P0🔥 Auto-bundle/install `Pixel Control Plugin` into ManiaControl in the dev server image
- [ ] P1 Inject plugin runtime config from env (Pixel Control Server URL, auth mode, retry policy)
- [ ] P1 Add startup verification that the plugin is loaded and active
- [ ] P2 Add local-dev plugin sync workflow optimized for quick iteration

### Developer experience
- [ ] P1 Document one-command workflows in `pixel-sm-server/README.md` (`up`, `down`, `logs`, `rebuild`)
- [ ] P1 Add smoke checks for DB connectivity, XML-RPC reachability, and ManiaControl boot
- [ ] P2 Add mode smoke checks for at least Elite, Siege, and Battle
- [ ] P3 Add CI image build + security scanning for `pixel-sm-server`

### Security and hardening
- [ ] P0🔥 Remove hardcoded credentials from first-party templates and use secure placeholders
- [ ] P1 Document `network_mode: host` caveats and provide a bridge-network alternative profile
- [ ] P2 Define persisted volume strategy (server data, mysql data, logs) and backup procedure

## Open decisions
- [ ] P3 Final authentication model between plugin and server
- [ ] P2 Initial production rollout order across ShootMania modes
- [ ] P2 Data retention policy for raw events vs aggregates
