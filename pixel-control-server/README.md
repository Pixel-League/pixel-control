# Pixel Control Server

Central NestJS REST API for orchestrating ManiaPlanet/ShootMania game servers. Receives plugin telemetry, manages server linking, and exposes game data through a unified API.

## Tech Stack

| Layer       | Technology                            |
| ----------- | ------------------------------------- |
| Framework   | NestJS v11 (Fastify)                  |
| Database    | PostgreSQL 17 (via Prisma ORM)        |
| Validation  | class-validator + class-transformer   |
| Tests       | Vitest + SWC                          |
| Docs        | Swagger / OpenAPI (`@nestjs/swagger`) |
| Container   | Docker (Node 22 Alpine, multi-stage)  |

## Quick Start

### Prerequisites

- Node.js >= 22
- Docker & Docker Compose
- npm

### 1. Environment

```bash
cp .env.example .env
```

Default values:

```env
DATABASE_URL="postgresql://pixel:pixel@localhost:5433/pixel_control?schema=public"
ONLINE_THRESHOLD_SECONDS=360
```

### 2. Start with Docker (recommended)

```bash
npm run docker:up       # postgres:5433 + api:3000
```

This builds the API image, starts PostgreSQL, runs migrations, and serves the API on port 3000.

### 3. Start locally (dev mode)

```bash
npm install
npm run docker:up       # start postgres only, or run your own
npm run prisma:migrate  # apply migrations
npm run prisma:generate # generate Prisma client
npm run start:dev       # watch mode on port 3000
```

### 4. Verify

```bash
curl http://localhost:3000/v1 | jq .
# → { "status": "ok", "service": "pixel-control-server" }
```

## API Documentation

Swagger UI is available at **http://localhost:3000/api/docs** when the server is running.

JSON schema: `http://localhost:3000/api/docs-json`

All endpoints are prefixed with `/v1`.

## Architecture

```
src/
├── main.ts                         # Bootstrap (Fastify, prefix /v1, Swagger, ValidationPipe)
├── app.module.ts                   # Root module
├── app.controller.ts               # GET / health check
├── common/
│   ├── dto/                        # Shared DTOs (envelope, ack, link, servers query)
│   ├── filters/                    # Exception filters (connectivity validation)
│   └── utils/                      # Utilities (isServerOnline)
├── link/
│   ├── link.module.ts
│   ├── link.controller.ts          # Server link management + list servers
│   └── link.service.ts
├── connectivity/
│   ├── connectivity.module.ts
│   ├── connectivity.controller.ts  # Plugin event ingestion
│   └── connectivity.service.ts
└── prisma/
    ├── prisma.module.ts            # Global PrismaModule
    └── prisma.service.ts
```

### Modules

| Module             | Responsibility                                              |
| ------------------ | ----------------------------------------------------------- |
| `PrismaModule`     | Database access (global)                                    |
| `LinkModule`       | Server registration, token management, auth state, list     |
| `ConnectivityModule` | Ingest connectivity events from plugin (heartbeat, registration) |

## Endpoints

### Link Management (P0)

| Method | Endpoint                                    | Description                          |
| ------ | ------------------------------------------- | ------------------------------------ |
| `PUT`  | `/v1/servers/:serverLogin/link/registration` | Register or update a server         |
| `POST` | `/v1/servers/:serverLogin/link/token`       | Generate or rotate link token        |
| `GET`  | `/v1/servers/:serverLogin/link/auth-state`  | Check link & online state            |
| `GET`  | `/v1/servers/:serverLogin/link/access`      | Check server access/permissions      |
| `GET`  | `/v1/servers`                               | List all servers (`?status=linked\|all\|offline`) |

### Event Ingestion (P0)

| Method | Endpoint                          | Description                 |
| ------ | --------------------------------- | --------------------------- |
| `POST` | `/v1/plugin/events/connectivity`  | Receive connectivity events |

The plugin sends events wrapped in a standard envelope (see `NEW_API_CONTRACT.md`). The server validates the envelope, checks idempotency via `idempotency_key`, auto-registers unknown servers, and updates server state (heartbeat, version, metadata).

**Expected response:**

```jsonc
// Accepted
{ "ack": { "status": "accepted" } }

// Duplicate (same idempotency_key)
{ "ack": { "status": "accepted", "disposition": "duplicate" } }

// Invalid envelope
{ "ack": { "status": "rejected", "code": "invalid_envelope", "retryable": false } }
```

**Required headers:**

| Header                   | Required | Description            |
| ------------------------ | -------- | ---------------------- |
| `X-Pixel-Server-Login`   | yes      | Dedicated server login |
| `X-Pixel-Plugin-Version` | no       | Plugin version string  |

## Database

### Models

**Server** — registered game server identity and link state.

| Column          | Type      | Description                     |
| --------------- | --------- | ------------------------------- |
| `server_login`  | string    | Unique server identifier        |
| `server_name`   | string?   | Display name                    |
| `link_token`    | string?   | Shared bearer token             |
| `linked`        | boolean   | Whether token exists            |
| `game_mode`     | string?   | Last known game mode            |
| `title_id`      | string?   | Last known title pack           |
| `plugin_version`| string?   | Last known plugin version       |
| `last_heartbeat`| timestamp?| Last event received             |
| `online`        | boolean   | Heartbeat within threshold      |

**ConnectivityEvent** — stored plugin telemetry events.

| Column           | Type     | Description                        |
| ---------------- | -------- | ---------------------------------- |
| `event_name`     | string   | Full event name                    |
| `event_id`       | string   | Plugin-assigned event ID           |
| `idempotency_key`| string   | Unique, prevents duplicate storage |
| `payload`        | JSONB    | Full event payload                 |
| `metadata`       | JSONB?   | Transport metadata                 |

### Migrations

```bash
npm run prisma:migrate  # apply pending migrations
npm run prisma:generate # regenerate Prisma client
npm run prisma:studio   # open Prisma Studio GUI
```

## Testing

### Unit tests

```bash
npm run test            # single run
npm run test:watch      # watch mode
```

40 tests covering:
- `isServerOnline` utility
- `LinkService` (register, token, auth-state, access, list)
- `LinkController` (route wiring, status codes)
- `ConnectivityService` (ingest, idempotency, auto-register)
- `ConnectivityController` (header extraction, error handling)

### QA smoke tests

```bash
# Requires: running API + PostgreSQL, curl, jq
bash scripts/qa-p0-smoke.sh [API_BASE]
# Default: http://localhost:3000/v1
```

43 assertions covering all P0 endpoints: registration, token generation/rotation, auth state, access check, server listing with filters, event ingestion, duplicate detection, and error cases.

## Commands Reference

| Command                | Description                          |
| ---------------------- | ------------------------------------ |
| `npm run start:dev`    | Dev mode with watch                  |
| `npm run build`        | Compile TypeScript                   |
| `npm run start:prod`   | Production runner (`node dist/main`) |
| `npm run test`         | Run vitest                           |
| `npm run test:watch`   | Vitest in watch mode                 |
| `npm run prisma:migrate` | Apply database migrations          |
| `npm run prisma:generate`| Regenerate Prisma client           |
| `npm run prisma:studio`| Open Prisma Studio                   |
| `npm run docker:up`    | Start Docker stack (postgres + api)  |
| `npm run docker:down`  | Stop Docker stack                    |

## Docker

### Development

```bash
npm run docker:up    # build + start
npm run docker:down  # stop + clean
```

Services:
- **postgres** — PostgreSQL 17 on port `5433` (credentials: `pixel:pixel`, db: `pixel_control`)
- **api** — NestJS server on port `3000`

### Production build

The Dockerfile uses a multi-stage build (Node 22 Alpine):
1. **build** — install deps, generate Prisma client, compile TypeScript
2. **production** — copy compiled output + production deps only

## Related

- [`NEW_API_CONTRACT.md`](../NEW_API_CONTRACT.md) — Full API contract and implementation roadmap
- [`pixel-control-plugin/`](../pixel-control-plugin/) — PHP ManiaControl plugin (telemetry source)
- [`pixel-sm-server/`](../pixel-sm-server/) — Docker dev stack (ShootMania + ManiaControl)
