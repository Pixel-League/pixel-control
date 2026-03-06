# ROADMAP

Planning baseline for Pixel Control. This file is the source of truth when discussing `ROADMAP`.

Principles:
- Plugin-first delivery
- Elite-only ShootMania support (non-Elite modes removed 2026-03-01 — PLAN-SM-SERVER-ELITE-ONLY)
- Local-first developer experience
- `ressources/` used as reference, not first-party product code

Priority scale:
- `P0🔥`: mandatory for first end-to-end usable iteration
- `P1`: high priority, should follow immediately after P0
- `P2`: important, can ship in second wave
- `P3`: medium priority, structuring but not blocking early delivery
- `P4`: low priority, optimization/hardening later
- `P5`: backlog/nice-to-have

## Current execution thread (updated: 2026-03-06)

**Status: P0–P5 complete. All planned feature tiers delivered. Active branch merged to `main`.**

Completed plan files (in order):
- `PLAN-P0` — plugin scaffolding, heartbeat, lifecycle, sequencing
- `PLAN-P1` — stats, roster, eligibility, combat telemetry
- `PLAN-P2` — aggregates, team-side, win-context, map rotation/veto
- `PLAN-P2.5` / `PLAN-P2.6` — NestJS MVP ingestion + Read API (PostgreSQL + Prisma)
- `PLAN-QA-INTEGRATION-TESTS` — end-to-end dev test harness
- `PLAN-ELITE-ENRICHMENT` — Elite-only focus, all 14 enrichment phases
- `PLAN-API-TEST-UI` — pixel-control-ui (Vite + React 19 + Tailwind CSS v4, 34 pages, 60+ endpoints)
- `PLAN-EVENT-INJECTOR-SUBTEMPLATES` — EventInjector 3-card sub-event selector (24 templates)
- `PLAN-P3-ADMIN-COMMANDS` — 16 admin command endpoints via ManiaControl socket proxy
- `PLAN-P4-P5-EXTENDED-CONTROL` — 27 endpoints across P4 (VetoDraft, players, teams) and P5 (auth, whitelist, votes)

Active deliverables on `main`:
- 394 server unit tests, 109 PHP plugin tests — all green
- 3 smoke suites for P3/P4/P5 (qa-p3: 78/78, qa-p4: 70/70, qa-p5: 69/69 assertions)
- Full manual test playbook completed: `PLAYBOOK-TESTS-MANUELS-P3-P5.md`

Next focus areas (see sections below for detail):
- Durable stats/match storage (events currently held in-memory)
- Policy enforcement loops (server decisions flowing back to plugin for runtime enforcement)
- Ops runbooks and deployment topology

Checkpoint status (historical, owner-ready):
- [x] `Checkpoint A` — Scaffolded first-party plugin foundation in `pixel-control-plugin/`
- [x] `Checkpoint B` — Scaffolded first-party local dev stack baseline in `pixel-sm-server/`
- [x] `Checkpoint C` — Mode/matchsettings parameterization
- [x] `Checkpoint D` — PixelControlPlugin bundled into pixel-sm-server bootstrap
- [x] `Checkpoint E` — Plugin runtime config from env + readiness probes
- [x] `Checkpoint F` — Startup validation for title pack assets
- [x] `Checkpoint G` — Mode-aware map compatibility guardrails
- [x] `Checkpoint H` — Siege/Battle map pools, validated smoke matrix
- [x] `Checkpoint I` — Battle title-pack provisioning hardened
- [x] `Checkpoint J` — Host-network caveat docs + bridge-default/host-override profile
- [x] `Checkpoint K` — Plugin fast-sync workflow (`scripts/dev-plugin-sync.sh`)
- [x] `Checkpoint L` — Plugin connectivity P0: monotonic sequencing, resilient dispatch/retry, registration, heartbeat
- [x] `Checkpoint M` — Lifecycle normalization P0: warmup/match/map/round variants + runtime context metadata
- [x] `Checkpoint N` — Script-callback lifecycle signals aligned with ManiaPlanet channel
- [x] `Checkpoint O` — Payload schema versioning + canonical envelope metadata (`schema_version`, `event_id`)
- [x] `Checkpoint P` — Compatibility matrix: plugin version ↔ envelope schema version
- [x] `Checkpoint Q` — Shared delivery error envelope + retry semantics contract
- [x] `Checkpoint R` — Canonical event naming catalog + JSON schema baseline
- [x] `Checkpoint S` — Plugin→API ingestion and idempotency contract baseline
- [x] `Checkpoint T` — Runnable NestJS backend/API ingestion MVP
- [x] `Checkpoint U` — Durable PostgreSQL storage via Prisma (schema + migrations)
- [x] `Checkpoint V` — Wave-3 telemetry expansion (roster/eligibility/admin-correlation + aggregates)
- [x] `Checkpoint W` — Wave-4 additive telemetry (team-side, win-context, reconnect/side-change)
- [x] `Checkpoint X` — Wave-5 plugin hardening + constraint signals + final handoff

---

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

Player constraint note: wave-5 implementation exposes forced-team/slot-policy state as deterministic telemetry signals (`constraint_signals`) with explicit availability/fallback reasons; enforcement remains external/runtime-driven.

### Maps
- [x] P1 Sync map pack and map rotation metadata
- [x] P1 Export veto/draft actions (ban/pick actor, order, timestamps)
- [x] P1 Export final veto result and played map order
- [x] P2 Sync map identifiers (uid, name, optional MX id)

### Admin command surface (P3–P5)
- [x] P2 Add health/debug command surface for admins (16 P3 + 8 P4 + 14 P5 = 38 actions total)
- [x] P2 Add match-control commands: map skip/restart/jump/queue/add/remove, warmup extend/end, pause start/end, best-of/maps-score/round-score get+set
- [x] P4 Add player management commands: force-team, force-play, force-spec
- [x] P4 Add team control commands: policy get/set, roster assign/unassign/list
- [x] P5 Add auth management commands: auth.grant, auth.revoke
- [x] P5 Add whitelist commands: enable, disable, add, remove, list, clean, sync
- [x] P5 Add vote management commands: cancel, set_ratio, custom_start, policy.get, policy.set
- [ ] P5 Add clear chat/manialink notifications for workflow states

Implementation: `AdminCommandTrait.php` (38 actions) + `VetoDraftCommandTrait.php` (5 communication listeners). Wired in `CoreDomainTrait.load()`.

---

## Pixel Control Server

### Platform and DX (local-first)
- [x] P0🔥 Scaffold NestJS API with local-first workflow (Fastify platform)
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
- [ ] P2 Durable receipt/event storage (events currently held in-memory — restart drops all data)

### Domain: Stats
- [x] P1 In-memory raw-event journal + aggregate counters for ingestion/read-model MVP
- [x] P1 Add no-DB stats read endpoints (`/v1/servers/:serverLogin/stats/{summary,players}`)
- [ ] P2 Build durable per-player/per-team/per-match read models
- [ ] P2 Expose extended stats query endpoints for dashboards and tooling
- [ ] P4 Add recalculation/backfill jobs

### Domain: Players
- [x] P1 Add per-server player snapshot read endpoints (`/v1/servers/:serverLogin/players*`)
- [x] P4 Add player management admin endpoints: force-team, force-play, force-spec
- [x] P5 Add auth management admin endpoints: grant/revoke per player
- [ ] P1 Persist per-server player eligibility and permissions (durable storage)
- [ ] P2 Add audit trail for permission changes

### Domain: Maps
- [x] P1 Add map/veto state read endpoint (`/v1/servers/:serverLogin/maps/state`)
- [x] P3 Add map admin endpoints: skip, restart, jump, queue, add (MX id), remove (map uid)
- [ ] P1 Persist map pools, map packs, veto sessions, map order (durable storage)

### Domain: Match
- [x] P1 Build canonical in-memory timeline from lifecycle events
- [x] P1 Add match state machine read endpoint (`/v1/servers/:serverLogin/match/state`)
- [x] P3 Add match config admin endpoints: best-of get/set, maps-score get/set, round-score get/set
- [x] P3 Add warmup/pause admin endpoints: warmup extend/end, pause start/end

### Domain: VetoDraft (P4)
- [x] P4 Add VetoDraft endpoints: GET status, POST ready/start/action/cancel
- [x] P4 VetoDraftProxyModule: socket proxy for `PixelControl.VetoDraft.*` methods (distinct from AdminProxy)

### Domain: Whitelist (P5)
- [x] P5 Add whitelist admin endpoints: enable/disable, add, remove, list, clean-all, sync

### Domain: Votes (P5)
- [x] P5 Add vote admin endpoints: cancel, set-ratio, custom-start, get-policy, set-policy

### Auth and security — FINALIZED
- [x] P1 Auth model selected and implemented: **`link_bearer` token**
  - Server generates a UUID token on first registration or explicit rotation via `/v1/servers/:serverLogin/auth/rotate`
  - The token is stored in DB (PostgreSQL via Prisma) and returned to the caller
  - Plugin includes the token in every socket command call; `AdminProxyService` resolves the server by `serverLogin`, fetches the `linkToken` from DB, and injects `auth: { mode: 'link_bearer', token }` into every outgoing socket frame
  - ManiaControl's `PixelControl.Admin.ExecuteAction` listener validates the token before executing any action
  - This IS the plugin↔server authentication handshake — no separate auth step is needed per command
- [x] P1 Link-auth validation in plugin (per-request, in `AdminCommandTrait.validateLinkAuth()`)
- [x] P5 Runtime auth grant/revoke for player permissions via admin command surface

**What remains is NOT missing auth but missing policy enforcement loops** — see Cross-domain Workflows section below.

---

## Pixel Control Plugin + Server

### Shared contract
- [x] P0🔥 Define canonical event naming and JSON schemas
- [x] P1 Define standard envelope fields (`event_id`, `source_callback`, `source_time`, `source_sequence`)
- [x] P1 Define compatibility matrix (plugin version ↔ server API version)
- [x] P0🔥 Define shared error format and retry semantics

### Reliability
- [x] P0🔥 Guarantee at-least-once delivery with server-side dedupe (in-memory implementation)
- [ ] P2 Define replay/backfill strategy after outages (once durable storage is in place)
- [ ] P2 Define recovery flow after partial desynchronization

### Cross-domain policy enforcement loops

The auth channel (link_bearer) is fully operational. The remaining open work is about **policy enforcement loops**: the server makes decisions (eligibility, map pools, match assignments) but those decisions don't yet flow back to the plugin for runtime enforcement. The plugin currently enforces whitelist locally (in-memory state in `AdminCommandTrait`), but the enforcement round-trip is missing for other policies.

The pattern for each loop is:
1. Operator sets policy via API (e.g. POST `/v1/servers/:s/whitelist`)
2. Server stores and acks policy
3. **Missing**: server pushes policy delta to plugin via socket, or plugin polls and receives the policy
4. **Missing**: plugin enforces the policy at the appropriate ManiaControl callback (e.g. `PlayerConnect`, map change, round start)
5. **Missing**: plugin reports enforcement outcome back to server (e.g. kicked player, blocked map)

- [ ] P1 Player eligibility enforcement loop (who can join/play, currently telemetry-only)
- [ ] P1 Map pool enforcement loop (restrict rotation to allowed maps, currently advisory)
- [ ] P2 Match assignment enforcement loop (server assigns players/teams → plugin ack/nack)
- [ ] P3 Veto outcome enforcement loop (ensure post-veto map order is respected by the dedicated server)

### Test strategy
- [x] P0🔥 End-to-end dev test harness using `pixel-sm-server` (dedicated server + ManiaControl + plugin + API)
- [x] P1 PHP plugin test harness (`tests/run.php` — 109 tests, 5 spec files, deterministic)
- [x] P1 Server unit tests (394 Vitest tests across 35 spec files)
- [x] P1 Smoke test suites per feature tier (P3: 78 assertions, P4: 70, P5: 69)
- [x] P1 Manual test playbook: `PLAYBOOK-TESTS-MANUELS-P3-P5.md` (Parts 1–9 validated)
- [ ] P2 Automated multi-round acceptance tests with real ManiaControl callbacks
- [ ] P4 Performance tests for stats ingestion and queries

### Operations
- [ ] P2 Define retention and backup policy for stats/match data
- [ ] P3 Define deployment topology and environment split (prod vs staging)
- [ ] P3 Define runbooks for outages and degraded modes (socket disconnect, API unreachable, DB down)
- [ ] P4 Add CI pipeline (build, unit tests, lint) — currently no CI configured

---

## Pixel Control UI

Dev UI for testing and debugging all API endpoints. Not a production-facing product.

- [x] P0🔥 Scaffold Vite + React 19 + TypeScript + Tailwind CSS v4 + React Router v7
- [x] P0🔥 Typed API client (`src/api/`) covering all 60+ endpoints
- [x] P1 34 page components across all feature domains (servers, stats, players, maps, match, admin)
- [x] P1 Admin pages P3: MapControl (/admin/maps), WarmupPause (/admin/warmup-pause), MatchConfig (/admin/match)
- [x] P1 Admin pages P4: VetoDraft (/admin/veto), PlayerManagement (/admin/players), TeamControl (/admin/teams)
- [x] P1 Admin pages P5: AuthManagement (/admin/auth), WhitelistManagement (/admin/whitelist), VoteManagement (/admin/votes)
- [x] P2 Shared components: Badge, StatCard, JsonViewer, Pagination, CopyButton, ConfirmModal, VetoMapCard, VetoTimeline, etc.
- [x] P2 EventInjector with 3-card sub-event selector (24 templates, Category → Event Type → JSON Body)
- [ ] P3 Add form validation and better UX for admin command forms
- [ ] P5 Extract as standalone deployable tool (currently dev-only)

---

## Pixel Design System

React component library following the Pixel Series Gen 5 artistic direction. Integrated as a monorepo subdirectory (`pixel-design-system/`). Targets the main esport platform UI, not pixel-control-ui.

- [x] P1 26 components: Button, Input, Textarea, Select, Checkbox, Radio, Switch, FormField, FileInput, Alert, Toast, Badge, Progress, Skeleton, Card, Table, Avatar, Divider, Bracket, Tabs, Breadcrumb, Pagination, TopNav, Modal, Tooltip, DropdownMenu
- [x] P1 Neumorphic design system: dual outer shadows, 0px border-radius, dark/light theme via ThemeProvider
- [x] P1 Typography: Karantina (display, uppercase, 3px tracking) + Poppins (body, 0.75px tracking)
- [x] P1 Brand palette: electric indigo `#2C12D9` primary, `#E02020` error, `#00C853` success, `#FFB020` warning
- [x] P1 Tailwind CSS v3 config with custom `nm-*` / `px-*` tokens + shadow utilities
- [x] P1 Storybook 8 docs + interaction demos
- [x] P2 Vitest 2 + @testing-library/react test suite
- [x] P2 Esport double-elimination Bracket component with SVG connectors + ResizeObserver
- [x] P2 Integrated into monorepo (`.git` removed, root `.gitignore` scoped for build artifacts)
- [ ] P3 Publish as npm package (currently private, `@pixel-series/design-system-neumorphic`)
- [ ] P3 Integrate design system into pixel-control-ui (currently separate palettes and stacks)
- [ ] P4 Migrate pixel-control-ui from Tailwind v4 to use design system tokens and components

---

## Pixel SM Server

### Foundation
- [x] P0🔥 Scaffold first-party Docker stack in `pixel-sm-server/` from reference behavior
- [x] P0🔥 Add `.env.example` (env names only) for server, database, XML-RPC, and mode configuration
- [x] P0🔥 Implement deterministic startup sequence (DB ready → XML templating → ManiaControl → dedicated server)
- [x] P1 Add healthchecks/readiness probes for MySQL, ManiaControl, and XML-RPC

### Mode orchestration
- [x] P0🔥 Replace hardcoded matchsettings with configurable input
- [x] P0🔥 Make title pack fully configurable with startup availability validation
- [x] P0🔥 Ensure selected title pack assets are available in runtime
- [x] P1 Add mode presets with default matchsettings templates
- [x] P1 Support custom maps/matchsettings/titlepacks via mounted volumes
- [x] P2 Elite-only mode (non-Elite modes removed — PLAN-SM-SERVER-ELITE-ONLY, 2026-03-01)
- [ ] P4 Add multi-instance local support with deterministic port offsets (deprioritized — Elite-only focus)

### Pixel Control Plugin bundling
- [x] P0🔥 Auto-bundle/install plugin into ManiaControl in the dev server image
- [x] P1 Inject plugin runtime config from env (API URL, auth mode, retry policy)
- [x] P1 Add startup verification that the plugin is loaded and active
- [x] P2 Add local-dev plugin sync workflow (`dev-plugin-sync.sh`, `dev-plugin-hot-sync.sh`)
- [x] P3 Expose ManiaControl socket port 31501 for admin proxy (Docker compose updated)

### Developer experience
- [x] P1 Document one-command workflows in `pixel-sm-server/README.md`
- [x] P1 Add smoke checks for DB connectivity, XML-RPC reachability, ManiaControl boot
- [x] P2 Add deterministic telemetry replay helpers (wave3, wave4 replay scripts)
- [x] P2 Add automated Elite test suite (`scripts/test-automated-suite.sh`)
- [ ] P3 Add CI image build + security scanning

### Security and hardening
- [x] P0🔥 Remove hardcoded credentials, use secure placeholders
- [x] P1 Document `network_mode: host` caveats + bridge-network alternative profile
- [ ] P2 Define persisted volume strategy (server data, mysql data, logs) and backup procedure

---

## Open decisions

- [ ] P2 Durable event/stats storage strategy: what gets persisted, what stays in-memory, retention windows
- [ ] P2 Policy enforcement loop implementation: push (server → plugin socket) vs pull (plugin polling API)
- [ ] P2 Data retention policy: raw events vs aggregates, per-match vs per-player vs per-season
- [ ] P3 Deployment topology: single-server vs multi-server, prod vs staging environment split
- [ ] P3 npm publish strategy for pixel-design-system (registry, versioning, changelogs)
- [ ] P4 pixel-control-ui integration with pixel-design-system (React 18 vs 19, Tailwind v3 vs v4)
