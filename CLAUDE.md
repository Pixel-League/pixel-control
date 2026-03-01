# Pixel Control — CLAUDE.md

## Project overview
Monorepo to orchestrate ShootMania esport servers: a ManiaControl PHP plugin per game server sends telemetry/events to a central NestJS REST API. A Docker-based dev stack bundles the whole runtime locally for rapid iteration. Goal: modular matchmaking, stats, team/map control, and veto systems for competitive ShootMania play.

## Tech stack
| Layer | Tech |
|---|---|
| Plugin | PHP 7.4+, ManiaControl framework, XML-RPC callbacks |
| API server | TypeScript, NestJS v11, Fastify, Prisma ORM, PostgreSQL |
| Dev stack | Docker Compose, Ubuntu 20.04 image, MySQL (ManiaControl DB), ManiaPlanet dedicated server |
| Server tests | Vitest + @swc/core (SWC transformer) |
| Plugin tests | PHP CLI (custom harness, no PHPUnit) |

## Repo layout
```
pixel-control-plugin/   # PHP ManiaControl plugin (source of truth for telemetry)
  src/                  # Plugin source (PixelControlPlugin.php + Domain/* traits)
  tests/                # PHP test harness (run.php + cases/)
  scripts/              # check-quality.sh, etc.
  docs/                 # event-contract.md + JSON schemas (schema_version=2026-02-20.1)

pixel-control-server/   # NestJS API (TypeScript)
  src/                  # app.module.ts, main.ts, prisma/ (early scaffold on feat/pixel-api)
  prisma/               # schema.prisma (PostgreSQL), migrations/
  docker-compose.yml    # postgres:5433 + api:3000
  vitest.config.ts

pixel-control-ui/       # Dev UI — Vite + React 19 + TypeScript + Tailwind CSS v4 + React Router v7
  src/
    api/            # Typed API client (client.ts + domain modules)
    components/     # Badge, StatCard, JsonViewer, Pagination, CopyButton, ConfirmModal, etc.
    layouts/        # MainLayout, ServerContext
    pages/          # 25+ pages covering all 30+ endpoints
    hooks/          # useApi, useServerContext
    types/          # api.ts (full response types)
    lib/            # config.ts, format.ts

pixel-sm-server/        # Dockerized ShootMania Elite-only dev stack
  docker-compose.yml            # bridge networking (default)
  docker-compose.production.yml # production overrides
  docker-compose.host.yml       # host-network variant
  Dockerfile                    # Ubuntu 20.04, PHP 7.4
  scripts/                      # bootstrap.sh, healthcheck.sh, dev-plugin-sync.sh, etc.
  runtime/server/               # LOCAL ONLY – game binaries + ManiaControl (gitignored)
  TitlePacks/                   # LOCAL ONLY – .Title.Pack.gbx assets

ressources/             # READ-ONLY reference code (ManiaControl, reference Docker stack, etc.)
API_CONTRACT.md         # Plugin→server route contract (source of truth)
ROADMAP.md              # Feature roadmap (source of truth)
AGENTS.md               # Legacy project memory (historical, kept for reference)
```

## Common commands

### pixel-control-ui (Dev UI)
```bash
cd pixel-control-ui
npm install
npm run dev            # Start dev server (port 5173)
npm run build          # Production build (dist/)
# API base URL: VITE_API_BASE_URL=http://localhost:3000/v1 (default)
```

### Plugin
```bash
# Lint + run deterministic test suite
bash pixel-control-plugin/scripts/check-quality.sh
```

### pixel-control-server (NestJS)
```bash
cd pixel-control-server
npm install
npm run start:dev          # watch mode
npm run build              # compile
npm run test               # vitest run
npm run prisma:generate    # regenerate Prisma client
npm run prisma:migrate     # run migrations (dev)
npm run docker:up          # docker compose up --build -d
npm run docker:down
```

### pixel-sm-server (Docker dev stack)
```bash
cd pixel-sm-server
cp .env.example .env                         # first-time setup
bash scripts/import-reference-runtime.sh    # provision game binaries
docker compose up -d --build                # start stack
docker compose logs -f shootmania           # tail logs
docker compose down

# Plugin fast iteration (no image rebuild)
bash scripts/dev-plugin-sync.sh             # restart shootmania service
bash scripts/dev-plugin-hot-sync.sh         # restart ManiaControl only

# QA / validation
bash scripts/validate-dev-stack-launch.sh
bash scripts/test-automated-suite.sh        # full automatable suite (Elite-only)
bash scripts/replay-core-telemetry-wave3.sh
bash scripts/replay-extended-telemetry-wave4.sh
```

## Conventions
- `ressources/` is **immutable reference only** — never run mutable workflows there.
- `API_CONTRACT.md` (repo root) is the **route/contract source of truth** — keep it updated on every plugin→server change.
- `pixel-control-plugin/README.md` is the plugin capability inventory — keep it updated.
- Envelope schema version: `2026-02-20.1` — evolve additively only (no breaking field changes).
- Event naming: `pixel_control.<category>.<normalized_source_callback>`.
- Identity: `event_id = pc-evt-<category>-<callback>-<seq>`, `idempotency_key = pc-idem-<sha1(event_id)>`.
- Plugin domain logic uses **PHP traits** (e.g. `ConnectivityDomainTrait`, `CombatDomainTrait`, `EliteRoundTrackingTrait`).
- ManiaControl plugin pattern: implement `Plugin` interface (`prepare`, `load`, `unload`, metadata getters), wire callbacks via managers, settings via `SettingManager`.
- NestJS server: Fastify platform, modules per domain, Prisma for DB, Vitest for tests.
- Auth model (plugin→server): `link_bearer` token — finalized. API generates UUID tokens on first registration or explicit rotate.
- Plugin is **Elite-only** after PLAN-ELITE-ENRICHMENT (all 14 phases done). VetoDraft, Admin Control, Access Control, Series Control, and Team Control subsystems have been removed. Only Elite mode callbacks remain in `CallbackRegistry.php`.
- No inline TS imports — use static imports at file top.
- `NEW_API_CONTRACT.md` (repo root) is the **route/contract source of truth** — keep it updated on every plugin→server change.

## CI / Release
- **No CI configured** (no `.github/workflows/`, no `.gitlab-ci.yml`).
- No release/versioning process defined yet.
- Current active branch: `feat/p2-read-api` (P2 + P2.5 + P2.6 + Elite enrichment — not yet merged into `main`).

## Gotchas
- **Apple Silicon**: set `PIXEL_SM_RUNTIME_PLATFORM=linux/amd64` — game binaries are x86.
- `pixel-sm-server/runtime/server/` must be manually provisioned (game binaries + ManiaControl); not in git.
- Server API runs on **port 3000**; PostgreSQL exposed locally on **port 5433** (Docker: 5433→5432).
- Plugin check-quality uses PHP CLI syntax-lint (`php -l`) — requires PHP in PATH.
- Keep control-surface names stable: `PixelControl.Admin.*`, `PixelControl.VetoDraft.*`, `//pcadmin`, `/pcveto`.
- `SM_SCORES` is the score/winner source for win-context enrichment in lifecycle aggregates.
- **Admin events** are NOT a separate category — admin actions are embedded in lifecycle events as metadata fields.
- **Plugin sends NO batch events** — it dispatches events individually. BatchService is forward-compatible scaffolding only.
