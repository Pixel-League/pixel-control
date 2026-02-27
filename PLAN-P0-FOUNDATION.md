# PLAN - Implement P0 Foundation Endpoints (2026-02-27)

## Context

- **Purpose:** Implement all six P0 (Foundation) endpoints from `NEW_API_CONTRACT.md` in the `pixel-control-server` NestJS application. These endpoints establish server identity, link authentication, connectivity event ingestion, and server listing -- nothing else in the API works without them.
- **Scope:** P0.1 through P0.6 only. No P1+ endpoints, no inbound socket-proxy commands, no frontend.
  - P0.1 -- `PUT /v1/servers/:serverLogin/link/registration` (register/update server)
  - P0.2 -- `POST /v1/servers/:serverLogin/link/token` (generate/rotate link token)
  - P0.3 -- `GET /v1/servers/:serverLogin/link/auth-state` (check link + auth validity)
  - P0.4 -- `GET /v1/servers/:serverLogin/link/access` (check server access/permissions)
  - P0.5 -- `POST /v1/plugin/events/connectivity` (receive connectivity events from plugin)
  - P0.6 -- `GET /v1/servers` (list all registered servers)
- **Non-goals:**
  - Inbound command proxy (socket) endpoints (P3+).
  - Read API endpoints for telemetry data (P2+).
  - Plugin event ingestion beyond connectivity (P1+).
  - Frontend / dashboard.
- **Constraints / assumptions:**
  - Developer is AI with text-only access. All QA must use CLI tools (curl, vitest, docker compose logs, bash scripts).
  - The existing `pixel-sm-server` Docker dev stack is the integration test environment. The plugin already sends `plugin_registration` on startup and `plugin_heartbeat` every 120s to the configured API URL.
  - Auth model finalized here: `link_bearer` token (shared secret between API and plugin). The API generates the token; the plugin is configured with it.
  - Envelope schema version: `2026-02-20.1` (additive only).
  - No inline TS imports -- use static imports at file top.
  - NestJS v11, Fastify platform, Prisma ORM, PostgreSQL, Vitest for tests.
  - All routes scoped under `/v1`.
- **Environment snapshot:**
  - Branch: `feat/pixel-api`
  - Current server state: minimal scaffold -- `GET /` health check, `PrismaModule` wired, no domain modules. Prisma schema has only `HealthCheck` model.
  - Database: PostgreSQL via Docker (port 5433 locally, 5432 inside container). Credentials: `pixel:pixel`, DB: `pixel_control`.
  - Plugin env vars for link: `PIXEL_CONTROL_LINK_SERVER_URL`, `PIXEL_CONTROL_LINK_TOKEN`, `PIXEL_CONTROL_API_BASE_URL`, `PIXEL_CONTROL_API_EVENT_PATH=/plugin/events`.
- **Dependencies / stakeholders:**
  - `NEW_API_CONTRACT.md` is the source of truth for all endpoint shapes.
  - `pixel-control-plugin` is the primary client (sends connectivity events, will use link tokens for auth).
  - `pixel-sm-server` Docker stack is the integration test bed.
- **Risks / open questions:**
  - The plugin currently sends events to `PIXEL_CONTROL_API_BASE_URL + PIXEL_CONTROL_API_EVENT_PATH + /connectivity` (i.e., `http://host:port/plugin/events/connectivity`). The API route is `POST /v1/plugin/events/connectivity`. The plugin's `PIXEL_CONTROL_API_BASE_URL` must include the `/v1` prefix, OR the API must also mount routes without it. Resolved: the contract says API base path is `/v1`, so the plugin config should point at `http://host:3000/v1` and the event path is `/plugin/events`. Verify during integration QA.
  - "Online" status: a server is considered online if its last heartbeat was received within a configurable threshold (e.g., 3x heartbeat interval = 360s). This threshold must be defined as a server-side config.

---

## Steps

- [Done] Phase 1 - Prisma schema and migration
- [Done] Phase 2 - Shared infrastructure (guards, DTOs, envelope validation)
- [Done] Phase 3 - LinkModule (P0.1, P0.2, P0.3, P0.4)
- [Done] Phase 4 - ConnectivityModule (P0.5)
- [Done] Phase 5 - ServersModule (P0.6)
- [Done] Phase 6 - Unit tests (vitest)
- [Done] Phase 7 - Integration QA with pixel-sm-server Docker stack
- [Done] Phase 8 - Add Swagger/OpenAPI descriptions to all P0 controllers
- [Done] Phase 9 - Diagnose and fix "server never online" bug
- [Done] Phase 10 - Live verification with both Docker stacks

---

### Phase 1 - Prisma schema and migration

**Goal:** Define the `Server` and `ConnectivityEvent` models in Prisma, run migration, verify DB tables exist.

- [Done] P1.1 - Add `Server` model to `pixel-control-server/prisma/schema.prisma`

  Fields (derived from contract responses and link flow):

  ```prisma
  model Server {
    id              String   @id @default(uuid())
    serverLogin     String   @unique @map("server_login")
    serverName      String?  @map("server_name")
    linkToken       String?  @map("link_token")
    linked          Boolean  @default(false)
    gameMode        String?  @map("game_mode")
    titleId         String?  @map("title_id")
    pluginVersion   String?  @map("plugin_version")
    lastHeartbeat   DateTime? @map("last_heartbeat")
    online          Boolean  @default(false)
    createdAt       DateTime @default(now()) @map("created_at")
    updatedAt       DateTime @updatedAt @map("updated_at")

    connectivityEvents ConnectivityEvent[]

    @@map("servers")
  }
  ```

  Key design notes:
  - `serverLogin` is the natural key (unique, used in all routes as `:serverLogin`).
  - `linkToken` is nullable (null before first token generation).
  - `linked` is `true` when `linkToken` is non-null (derived, but stored for fast queries).
  - `online` is a derived flag updated on heartbeat ingestion (true if last heartbeat within threshold).

- [Done] P1.2 - Add `ConnectivityEvent` model to `pixel-control-server/prisma/schema.prisma`

  Fields (from event envelope + connectivity payload):

  ```prisma
  model ConnectivityEvent {
    id              String   @id @default(uuid())
    serverId        String   @map("server_id")
    eventName       String   @map("event_name")
    eventId         String   @map("event_id")
    eventCategory   String   @map("event_category")
    idempotencyKey  String   @unique @map("idempotency_key")
    sourceCallback  String   @map("source_callback")
    sourceSequence  Int      @map("source_sequence")
    sourceTime      Int      @map("source_time")
    schemaVersion   String   @map("schema_version")
    payload         Json
    metadata        Json?
    receivedAt      DateTime @default(now()) @map("received_at")

    server Server @relation(fields: [serverId], references: [id])

    @@index([serverId])
    @@index([eventCategory])
    @@map("connectivity_events")
  }
  ```

  Key design notes:
  - `idempotencyKey` is unique to support duplicate detection.
  - `payload` and `metadata` stored as JSON (full fidelity, no schema coupling).
  - Relation to `Server` via `serverId`.

- [Done] P1.3 - Run migration and verify

  ```bash
  cd /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server
  npm run docker:up          # ensure postgres is running
  npm run prisma:migrate     # create migration
  npm run prisma:generate    # regenerate client
  ```

  Verify: `npx prisma db pull` should show both models. Check migration SQL file was created.

**Acceptance criteria:**
- `servers` and `connectivity_events` tables exist in PostgreSQL.
- Prisma client is regenerated and compiles without errors.
- `npm run build` succeeds.

---

### Phase 2 - Shared infrastructure (guards, DTOs, envelope validation)

**Goal:** Create reusable building blocks that multiple modules depend on: global route prefix, DTO validation, event envelope types, and a link-auth guard.

- [Done] P2.1 - Set global API prefix `/v1` in `pixel-control-server/src/main.ts`

  Add `app.setGlobalPrefix('v1')` before `app.listen()`. This ensures all routes are automatically prefixed with `/v1`.

- [Done] P2.2 - Install and configure `class-validator` and `class-transformer`

  ```bash
  cd /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server
  npm install class-validator class-transformer
  ```

  Enable global validation pipe in `main.ts`:
  ```ts
  app.useGlobalPipes(new ValidationPipe({ whitelist: true, transform: true }));
  ```

- [Done] P2.3 - Create shared DTOs directory: `pixel-control-server/src/common/dto/`

  Create event envelope DTO (`event-envelope.dto.ts`):
  - Validate required envelope fields: `event_name`, `schema_version`, `event_id`, `event_category`, `source_callback`, `source_sequence`, `source_time`, `idempotency_key`, `payload`.
  - Use `class-validator` decorators.

  Create ack response DTO (`ack-response.dto.ts`):
  - Shape: `{ ack: { status: 'accepted' | 'rejected', disposition?: 'duplicate', code?: string, retryable?: boolean } }`

- [Done] P2.4 - Create link registration DTO (`link-registration.dto.ts`)

  Fields: `server_name?` (string, optional), `game_mode?` (string, optional), `title_id?` (string, optional).

- [Done] P2.5 - Create link token DTO (`link-token.dto.ts`)

  Fields: `rotate?` (boolean, optional).

- [Done] P2.6 - Create online-status helper utility: `pixel-control-server/src/common/utils/online-status.util.ts`

  A function `isServerOnline(lastHeartbeat: Date | null, thresholdSeconds: number): boolean` that returns `true` if `lastHeartbeat` is within `thresholdSeconds` of now. Default threshold: 360 seconds (3x the 120s heartbeat interval).

  This utility is used by P0.3, P0.6, and P0.5 when updating server state.

- [Done] P2.7 - Add `ONLINE_THRESHOLD_SECONDS` to environment config

  Add to `.env`: `ONLINE_THRESHOLD_SECONDS=360`.
  Load via `ConfigService` in modules that need it.

**Acceptance criteria:**
- `npm run build` succeeds with all new DTOs and utilities.
- Global prefix `/v1` is set (verify: `GET /v1` returns health check, `GET /` returns 404 or is unchanged).
- Validation pipe is active globally.

---

### Phase 3 - LinkModule (P0.1, P0.2, P0.3, P0.4)

**Goal:** Implement the four link management endpoints as a `LinkModule` under `pixel-control-server/src/link/`.

Module structure:
```
src/link/
  link.module.ts
  link.controller.ts
  link.service.ts
```

- [Done] P3.1 - Create `LinkModule`, `LinkController`, `LinkService`

  `LinkModule` imports `PrismaModule` (already global) and `ConfigModule`.
  Register the module in `AppModule.imports`.

- [Done] P3.2 - Implement P0.1: `PUT /servers/:serverLogin/link/registration`

  **Controller:** `@Put('servers/:serverLogin/link/registration')`
  **Service logic:**
  1. Upsert server by `serverLogin` (create if not exists, update if exists).
  2. If creating for the first time, generate a random link token (crypto.randomUUID or similar), set `linked = true`.
  3. If updating, merge provided fields (`server_name`, `game_mode`, `title_id`) into existing record. Do NOT regenerate token on update.
  4. Return: `{ server_login, registered: true, link_token }` (include `link_token` only on first registration).

  **Edge cases:**
  - If the server already exists and no body fields are provided, return current state without changes.
  - `server_login` comes from the URL param, not the body.

- [Done] P3.3 - Implement P0.2: `POST /servers/:serverLogin/link/token`

  **Controller:** `@Post('servers/:serverLogin/link/token')`
  **Service logic:**
  1. Find server by `serverLogin`. Return 404 if not found.
  2. If `rotate === true` or server has no token: generate a new token, save, set `linked = true`.
  3. If `rotate` is falsy and token already exists: return existing token.
  4. Return: `{ server_login, link_token, rotated: <bool> }`.

- [Done] P3.4 - Implement P0.3: `GET /servers/:serverLogin/link/auth-state`

  **Controller:** `@Get('servers/:serverLogin/link/auth-state')`
  **Service logic:**
  1. Find server by `serverLogin`. Return 404 if not found.
  2. Compute `online` using `isServerOnline(server.lastHeartbeat, thresholdSeconds)`.
  3. Return: `{ server_login, linked, last_heartbeat, plugin_version, online }`.

- [Done] P3.5 - Implement P0.4: `GET /servers/:serverLogin/link/access`

  **Controller:** `@Get('servers/:serverLogin/link/access')`
  **Service logic:**
  1. Find server by `serverLogin`. Return 404 if not found.
  2. Return access/permissions state. For P0, this is a simplified check: `{ server_login, access_granted: <linked>, linked, online }`.
  3. Future tiers may add granular permission checks here.

**Acceptance criteria:**
- All four endpoints respond correctly (verified via curl).
- Registration creates a server record with link token.
- Token rotation generates a new token and invalidates the old one.
- Auth-state reflects real DB values.
- 404 for unknown server logins on token/auth-state/access.

---

### Phase 4 - ConnectivityModule (P0.5)

**Goal:** Implement the connectivity event ingestion endpoint as a `ConnectivityModule` under `pixel-control-server/src/connectivity/`.

Module structure:
```
src/connectivity/
  connectivity.module.ts
  connectivity.controller.ts
  connectivity.service.ts
```

- [Done] P4.1 - Create `ConnectivityModule`, `ConnectivityController`, `ConnectivityService`

  `ConnectivityModule` imports `PrismaModule` (already global) and `ConfigModule`.
  Register the module in `AppModule.imports`.

- [Done] P4.2 - Implement P0.5: `POST /plugin/events/connectivity`

  **Controller:** `@Post('plugin/events/connectivity')`

  Extract headers:
  - `X-Pixel-Server-Login` (required -- reject with 400 if missing)
  - `X-Pixel-Plugin-Version` (optional)
  - `Authorization` / `X-Pixel-Control-Api-Key` (for future auth; log but do not enforce in P0)

  **Service logic:**
  1. Validate the event envelope (shape, required fields, `schema_version`).
  2. Check `idempotency_key` uniqueness: if already stored, return `{ ack: { status: "accepted", disposition: "duplicate" } }`.
  3. Look up or auto-register the server by `X-Pixel-Server-Login`:
     - If the server does not exist in DB, create a minimal record (`serverLogin` only, `linked = false`). This handles the case where the plugin sends events before explicit registration.
  4. Store the event in `connectivity_events` table.
  5. Update the `Server` record:
     - Set `lastHeartbeat = now()` for both registration and heartbeat events.
     - Set `pluginVersion` from header if provided.
     - If payload type is `plugin_registration`, also update `serverName`, `gameMode`, `titleId` from `payload.context.server` if present.
     - Recompute `online = true` (just received a heartbeat).
  6. Return: `{ ack: { status: "accepted" } }`.

  **Error handling:**
  - Malformed envelope (missing required fields): return `{ ack: { status: "rejected", code: "invalid_envelope", retryable: false } }` with HTTP 400.
  - Missing `X-Pixel-Server-Login` header: return HTTP 400 with `{ ack: { status: "rejected", code: "missing_server_login", retryable: false } }`.
  - Unexpected server errors: return HTTP 500 with `{ error: { code: "internal_error", retryable: true, retry_after_seconds: 5 } }`.

**Acceptance criteria:**
- Endpoint accepts valid connectivity event envelopes and returns `accepted` ack.
- Duplicate events (same `idempotency_key`) return `accepted` with `disposition: "duplicate"`.
- Server record is updated with `lastHeartbeat`, `pluginVersion`, and context fields on registration events.
- Invalid envelopes are rejected with appropriate codes.
- Auto-registration works for unknown server logins.

---

### Phase 5 - ServersModule (P0.6)

**Goal:** Implement the server listing endpoint. This can be placed in the `LinkModule` (since it is related to link/server management) or in a dedicated `ServersModule`. Decision: add it to `LinkController` since it shares the same data model and service.

- [Done] P5.1 - Implement P0.6: `GET /servers`

  **Controller:** `@Get('servers')` on `LinkController` (route prefix: none -- `/v1/servers` via global prefix).

  Note: Since `LinkController` is scoped under `servers/:serverLogin/link`, this endpoint needs a separate controller or the controller needs to handle both route shapes. Best approach: create a lightweight `ServersController` in the link module, or put it on `AppController`.

  Decision: add a `ServersController` in `pixel-control-server/src/servers/` that uses `LinkService` or its own `ServersService` with a shared Prisma dependency.

  **Alternative (simpler):** Add `GET /servers` directly to a new `ServersController` in the link module directory since the data access is identical.

  **Service logic:**
  1. Query all servers from DB.
  2. Apply `status` query filter:
     - `all` (default): return all servers.
     - `linked`: return only servers where `linked === true`.
     - `offline`: return only servers where computed `online === false`.
  3. For each server, compute `online` dynamically using `isServerOnline()`.
  4. Return array of: `{ server_login, server_name, linked, online, last_heartbeat, plugin_version, game_mode, title_id }`.

  **Query param validation:** Accept `status` as optional enum query param. Default to `all`.

**Acceptance criteria:**
- `GET /v1/servers` returns an array of all registered servers.
- `?status=linked` filters to linked servers only.
- `?status=offline` filters to offline servers only.
- `online` field is dynamically computed (not stale from DB).
- Empty array returned when no servers exist (not 404).

---

### Phase 6 - Unit tests (vitest)

**Goal:** Write unit tests for all services and controllers. Tests use vitest with the existing configuration.

Test directory structure (mirror source):
```
src/link/link.service.spec.ts
src/link/link.controller.spec.ts
src/connectivity/connectivity.service.spec.ts
src/connectivity/connectivity.controller.spec.ts
src/common/utils/online-status.util.spec.ts
```

- [Done] P6.1 - Test `isServerOnline` utility

  Cases:
  - Returns `true` when `lastHeartbeat` is within threshold.
  - Returns `false` when `lastHeartbeat` is older than threshold.
  - Returns `false` when `lastHeartbeat` is `null`.

- [Done] P6.2 - Test `LinkService`

  Mock `PrismaService` with vitest mocks.
  Cases for each method:
  - `registerServer`: creates new server with token on first call; updates without new token on subsequent calls.
  - `generateToken`: returns existing token; rotates when `rotate=true`; 404 for unknown server.
  - `getAuthState`: returns correct shape; computes online correctly; 404 for unknown.
  - `checkAccess`: returns access state; 404 for unknown.

- [Done] P6.3 - Test `ConnectivityService`

  Mock `PrismaService`.
  Cases:
  - Accepts valid registration event, stores it, updates server fields.
  - Accepts valid heartbeat event, stores it, updates `lastHeartbeat`.
  - Rejects duplicate `idempotency_key` with `disposition: "duplicate"`.
  - Rejects malformed envelope.
  - Auto-registers unknown server on first event.

- [Done] P6.4 - Test `LinkController` and `ConnectivityController` (optional controller-level tests)

  Use `@nestjs/testing` `Test.createTestingModule` to verify route wiring, status codes, and response shapes. Mock the service layer.

- [Done] P6.5 - Run full test suite and verify green

  ```bash
  cd /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server
  npm run test
  ```

**Acceptance criteria:**
- All unit tests pass (`npm run test` exits 0).
- Coverage of happy path and key error paths for all P0 endpoints.
- No regressions on existing health check.

---

### Phase 7 - Integration QA with pixel-sm-server Docker stack

**Goal:** Verify the full flow end-to-end: plugin sends real connectivity events to the NestJS API, data is stored, and all P0 endpoints return correct responses. All QA is CLI-based (curl, docker compose logs, bash scripts).

**Pre-requisites:**
- `pixel-control-server` Docker stack is running (`npm run docker:up` in `pixel-control-server/`).
- `pixel-sm-server` Docker stack is running and the plugin is configured to point at the NestJS API.

- [Done] P7.1 - Configure plugin to point at the NestJS API (skipped per instructions — requires Docker stack)

  Update `pixel-sm-server/.env` (local, not committed):
  ```
  PIXEL_CONTROL_API_BASE_URL=http://host.docker.internal:3000/v1
  PIXEL_CONTROL_API_EVENT_PATH=/plugin/events
  PIXEL_CONTROL_LINK_SERVER_URL=http://host.docker.internal:3000/v1
  PIXEL_CONTROL_LINK_TOKEN=<token-from-registration>
  ```

  Note: `host.docker.internal` allows the ShootMania container to reach the host where the NestJS API is running. On Linux, use `172.17.0.1` or Docker's host gateway.

  Restart the plugin:
  ```bash
  cd /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-sm-server
  bash scripts/dev-plugin-hot-sync.sh
  ```

- [Done] P7.2 - Manual curl verification of link endpoints

  Run these from the host machine against `http://localhost:3000/v1`:

  ```bash
  # P0.1 - Register a server
  curl -s -X PUT http://localhost:3000/v1/servers/test-server-login/link/registration \
    -H 'Content-Type: application/json' \
    -d '{"server_name": "Test Server", "game_mode": "Elite", "title_id": "SMStormElite@nadeolabs"}' | jq .

  # Verify: response has server_login, registered=true, link_token (first time)

  # P0.2 - Get existing token (no rotate)
  curl -s -X POST http://localhost:3000/v1/servers/test-server-login/link/token \
    -H 'Content-Type: application/json' \
    -d '{}' | jq .

  # P0.2 - Rotate token
  curl -s -X POST http://localhost:3000/v1/servers/test-server-login/link/token \
    -H 'Content-Type: application/json' \
    -d '{"rotate": true}' | jq .

  # Verify: new token returned, different from original

  # P0.3 - Auth state
  curl -s http://localhost:3000/v1/servers/test-server-login/link/auth-state | jq .

  # Verify: linked=true, online=false (no heartbeat yet), plugin_version=null

  # P0.4 - Access check
  curl -s http://localhost:3000/v1/servers/test-server-login/link/access | jq .

  # Verify: access_granted=true (linked), linked=true

  # P0.6 - List servers
  curl -s http://localhost:3000/v1/servers | jq .

  # Verify: array with the test server

  # P0.6 - Filter by status
  curl -s 'http://localhost:3000/v1/servers?status=linked' | jq .
  curl -s 'http://localhost:3000/v1/servers?status=offline' | jq .

  # 404 for unknown server
  curl -s http://localhost:3000/v1/servers/nonexistent/link/auth-state | jq .
  # Verify: 404 response
  ```

- [Done] P7.3 - Manual curl verification of connectivity ingestion

  ```bash
  # P0.5 - Send a registration event
  curl -s -X POST http://localhost:3000/v1/plugin/events/connectivity \
    -H 'Content-Type: application/json' \
    -H 'X-Pixel-Server-Login: test-server-login' \
    -H 'X-Pixel-Plugin-Version: 1.0.0' \
    -d '{
      "event_name": "pixel_control.connectivity.plugin_registration",
      "schema_version": "2026-02-20.1",
      "event_id": "pc-evt-connectivity-plugin_registration-1",
      "event_category": "connectivity",
      "source_callback": "PixelControl.PluginRegistration",
      "source_sequence": 1,
      "source_time": 1740000000,
      "idempotency_key": "pc-idem-test-reg-001",
      "payload": {
        "type": "plugin_registration",
        "plugin": { "id": 1, "name": "PixelControlPlugin", "version": "1.0.0" },
        "context": {
          "server": { "login": "test-server-login", "title_id": "SMStormElite@nadeolabs", "game_mode": "Elite" },
          "players": { "active": 0, "total": 0, "spectators": 0 }
        },
        "timestamp": 1740000000
      },
      "metadata": { "signal_kind": "registration" }
    }' | jq .

  # Verify: { "ack": { "status": "accepted" } }

  # Check auth-state was updated
  curl -s http://localhost:3000/v1/servers/test-server-login/link/auth-state | jq .
  # Verify: last_heartbeat is set, plugin_version="1.0.0", online=true

  # Send duplicate (same idempotency_key)
  curl -s -X POST http://localhost:3000/v1/plugin/events/connectivity \
    -H 'Content-Type: application/json' \
    -H 'X-Pixel-Server-Login: test-server-login' \
    -d '{
      "event_name": "pixel_control.connectivity.plugin_registration",
      "schema_version": "2026-02-20.1",
      "event_id": "pc-evt-connectivity-plugin_registration-1",
      "event_category": "connectivity",
      "source_callback": "PixelControl.PluginRegistration",
      "source_sequence": 1,
      "source_time": 1740000000,
      "idempotency_key": "pc-idem-test-reg-001",
      "payload": { "type": "plugin_registration" },
      "metadata": {}
    }' | jq .

  # Verify: { "ack": { "status": "accepted", "disposition": "duplicate" } }

  # Send heartbeat event
  curl -s -X POST http://localhost:3000/v1/plugin/events/connectivity \
    -H 'Content-Type: application/json' \
    -H 'X-Pixel-Server-Login: test-server-login' \
    -H 'X-Pixel-Plugin-Version: 1.0.0' \
    -d '{
      "event_name": "pixel_control.connectivity.plugin_heartbeat",
      "schema_version": "2026-02-20.1",
      "event_id": "pc-evt-connectivity-plugin_heartbeat-2",
      "event_category": "connectivity",
      "source_callback": "PixelControl.PluginHeartbeat",
      "source_sequence": 2,
      "source_time": 1740000120,
      "idempotency_key": "pc-idem-test-hb-002",
      "payload": {
        "type": "plugin_heartbeat",
        "queue_depth": 0,
        "context": {
          "server": { "login": "test-server-login" },
          "players": { "active": 2, "total": 3, "spectators": 1 }
        },
        "timestamp": 1740000120
      },
      "metadata": { "signal_kind": "heartbeat" }
    }' | jq .

  # Verify: accepted ack, auth-state shows updated last_heartbeat

  # Send malformed envelope (missing event_name)
  curl -s -X POST http://localhost:3000/v1/plugin/events/connectivity \
    -H 'Content-Type: application/json' \
    -H 'X-Pixel-Server-Login: test-server-login' \
    -d '{"payload": {}}' | jq .

  # Verify: rejected ack with code "invalid_envelope"
  ```

- [Done] P7.4 - Integration test with live plugin (pixel-sm-server stack) (skipped per instructions — requires Docker stacks running)

  This step verifies the plugin's real connectivity events are received by the API.

  1. Ensure both Docker stacks are running:
     ```bash
     # Terminal 1: NestJS API
     cd /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server
     npm run docker:up

     # Terminal 2: ShootMania + ManiaControl + Plugin
     cd /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-sm-server
     docker compose up -d --build
     ```

  2. Register the game server's login with the API (get the login from the `.env` or `docker compose logs`):
     ```bash
     SERVER_LOGIN=$(grep PIXEL_SM_DEDICATED_LOGIN /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-sm-server/.env | cut -d= -f2)
     curl -s -X PUT "http://localhost:3000/v1/servers/${SERVER_LOGIN}/link/registration" \
       -H 'Content-Type: application/json' \
       -d '{}' | jq .
     ```

  3. Configure the plugin with the generated link token:
     ```bash
     LINK_TOKEN=$(curl -s -X POST "http://localhost:3000/v1/servers/${SERVER_LOGIN}/link/token" \
       -H 'Content-Type: application/json' -d '{}' | jq -r '.link_token')
     echo "Link token: ${LINK_TOKEN}"
     ```

     Update `pixel-sm-server/.env`:
     ```
     PIXEL_CONTROL_API_BASE_URL=http://host.docker.internal:3000/v1
     PIXEL_CONTROL_LINK_TOKEN=<LINK_TOKEN>
     ```

     Hot-sync the plugin:
     ```bash
     cd /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-sm-server
     bash scripts/dev-plugin-hot-sync.sh
     ```

  4. Wait for the plugin to send a registration event (immediate on startup) and verify:
     ```bash
     # Check API logs for received event
     cd /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server
     docker compose logs -f api --tail=50

     # Check auth-state
     curl -s "http://localhost:3000/v1/servers/${SERVER_LOGIN}/link/auth-state" | jq .
     # Verify: online=true, plugin_version set, last_heartbeat recent

     # Check server list
     curl -s http://localhost:3000/v1/servers | jq .
     # Verify: server appears with online=true
     ```

  5. Wait 120+ seconds for a heartbeat event, then verify:
     ```bash
     curl -s "http://localhost:3000/v1/servers/${SERVER_LOGIN}/link/auth-state" | jq .
     # Verify: last_heartbeat updated to a more recent time
     ```

  6. Verify `connectivity_events` table has records:
     ```bash
     # Via psql inside the docker container
     docker compose exec postgres psql -U pixel -d pixel_control \
       -c "SELECT id, event_name, idempotency_key, received_at FROM connectivity_events ORDER BY received_at DESC LIMIT 10;"
     ```

- [Done] P7.5 - Create a reusable QA smoke-test script

  Create `pixel-control-server/scripts/qa-p0-smoke.sh` that automates the curl calls from P7.2 and P7.3 with assertions (check HTTP status codes and response shapes). This script can be re-run after any code changes.

  Structure:
  ```bash
  #!/usr/bin/env bash
  set -euo pipefail
  API_BASE="http://localhost:3000/v1"
  PASS=0; FAIL=0
  # ... test functions that curl and assert ...
  ```

**Acceptance criteria:**
- All six P0 endpoints return correct responses via curl.
- Duplicate idempotency detection works.
- Plugin's real registration and heartbeat events are ingested successfully.
- Server `online` status reflects actual heartbeat recency.
- `connectivity_events` table accumulates records.
- Smoke-test script passes end-to-end.

---

### Phase 8 - Add Swagger/OpenAPI descriptions to all P0 controllers

**Goal:** Every P0 route displayed in Swagger UI (`/api/docs`) has meaningful descriptions, parameter docs, and response schemas. Descriptions are sourced from `NEW_API_CONTRACT.md`.

Swagger is already bootstrapped in `pixel-control-server/src/main.ts` (DocumentBuilder + SwaggerModule). Routes appear in the UI but lack descriptions.

- [Done] P8.1 - AppController (`src/app.controller.ts`)

  Add to the controller and its single route:

  ```ts
  @ApiTags('Health')
  @Controller()
  export class AppController { ... }

  @ApiOperation({ summary: 'Health check', description: 'Returns API health status. Use this to verify the server is running.' })
  @ApiResponse({ status: 200, description: 'API is healthy.' })
  @Get()
  healthCheck() { ... }
  ```

- [Done] P8.2 - LinkController (`src/link/link.controller.ts`) -- all 5 routes

  Add `@ApiTags('Link')` on the controller.

  For each route, add `@ApiOperation`, `@ApiParam`, `@ApiResponse`, and `@ApiBody`/`@ApiQuery` as appropriate:

  1. **PUT /servers/:serverLogin/link/registration**
     - `@ApiOperation({ summary: 'Register or update a server identity', description: 'Creates the server record in the API database on first call. Subsequent calls update server_name, game_mode, and title_id. A link token is generated automatically on first registration.' })`
     - `@ApiParam({ name: 'serverLogin', description: 'Dedicated server login (unique identifier of the game server)' })`
     - `@ApiBody({ type: LinkRegistrationDto, description: 'Optional server metadata to register or update' })`
     - `@ApiResponse({ status: 200, description: 'Server registered or updated successfully. Returns server_login, registered flag, and link_token (only on first registration).' })`

  2. **POST /servers/:serverLogin/link/token**
     - `@ApiOperation({ summary: 'Generate or rotate the link token', description: 'Returns the existing link token, or generates a new one if rotate=true or no token exists. The link token is the shared secret between API and plugin for link_bearer auth.' })`
     - `@ApiParam({ name: 'serverLogin', description: 'Dedicated server login' })`
     - `@ApiBody({ type: LinkTokenDto, description: 'Set rotate=true to force token rotation' })`
     - `@ApiResponse({ status: 200, description: 'Returns server_login, link_token, and rotated flag.' })`
     - `@ApiResponse({ status: 404, description: 'Server not found.' })`

  3. **GET /servers/:serverLogin/link/auth-state**
     - `@ApiOperation({ summary: 'Check if server is linked and auth is valid', description: 'Returns the current link status, last heartbeat timestamp, plugin version, and computed online status. A server is online if its last heartbeat was received within the configured threshold (default 360s).' })`
     - `@ApiParam({ name: 'serverLogin', description: 'Dedicated server login' })`
     - `@ApiResponse({ status: 200, description: 'Returns server_login, linked, last_heartbeat, plugin_version, and online.' })`
     - `@ApiResponse({ status: 404, description: 'Server not found.' })`

  4. **GET /servers/:serverLogin/link/access**
     - `@ApiOperation({ summary: 'Check server access and permissions', description: 'Returns whether the server has access granted (currently equivalent to linked status), along with link and online state. Future tiers may add granular permission checks.' })`
     - `@ApiParam({ name: 'serverLogin', description: 'Dedicated server login' })`
     - `@ApiResponse({ status: 200, description: 'Returns server_login, access_granted, linked, and online.' })`
     - `@ApiResponse({ status: 404, description: 'Server not found.' })`

  5. **GET /servers**
     - `@ApiOperation({ summary: 'List all registered servers', description: 'Returns an array of all registered servers with their link status, online state, and metadata. Online status is dynamically computed from heartbeat recency. Filter by status query parameter.' })`
     - `@ApiQuery({ name: 'status', required: false, enum: ServerStatusFilter, description: 'Filter servers: all (default), linked (only linked), offline (only offline)' })`
     - `@ApiResponse({ status: 200, description: 'Array of server summaries with server_login, server_name, linked, online, last_heartbeat, plugin_version, game_mode, and title_id.' })`

- [Done] P8.3 - ConnectivityController (`src/connectivity/connectivity.controller.ts`)

  Add `@ApiTags('Plugin Events')` on the controller.

  **POST /plugin/events/connectivity**
  - `@ApiOperation({ summary: 'Receive connectivity events from plugin', description: 'Ingests plugin_registration and plugin_heartbeat events. Updates server state (last heartbeat, plugin version, online status). Supports idempotent delivery via idempotency_key -- duplicates are accepted with disposition "duplicate". Auto-registers unknown servers on first event.' })`
  - `@ApiHeader({ name: 'X-Pixel-Server-Login', required: true, description: 'Dedicated server login sending the event' })`
  - `@ApiHeader({ name: 'X-Pixel-Plugin-Version', required: false, description: 'Plugin version string (e.g. "1.0.0")' })`
  - `@ApiBody({ type: EventEnvelopeDto, description: 'Standard event envelope with connectivity payload (plugin_registration or plugin_heartbeat)' })`
  - `@ApiResponse({ status: 200, description: 'Event accepted. Returns { ack: { status: "accepted" } } or { ack: { status: "accepted", disposition: "duplicate" } } for duplicates.' })`
  - `@ApiResponse({ status: 400, description: 'Rejected -- missing X-Pixel-Server-Login header or invalid envelope. Returns { ack: { status: "rejected", code: "missing_server_login"|"invalid_envelope", retryable: false } }.' })`
  - `@ApiResponse({ status: 500, description: 'Internal error. Returns { error: { code: "internal_error", retryable: true, retry_after_seconds: 5 } }.' })`

- [Done] P8.4 - Build verification and visual check

  1. Run `npm run build` in `pixel-control-server/` -- must succeed with no errors.
  2. Run `npm run test` -- all existing tests must still pass (Swagger decorators are metadata-only and should not affect behavior).
  3. Start the server (`npm run start:dev` or `npm run docker:up`) and open `http://localhost:3000/api/docs` -- verify:
     - Three tag groups visible: "Health", "Link", "Plugin Events".
     - Every route shows a summary and description.
     - Path parameters (`:serverLogin`) are documented.
     - Query parameters (`?status`) show enum values.
     - Request bodies show DTO fields.
     - Response codes (200, 400, 404, 500) are listed with descriptions.

**Acceptance criteria:**
- All 7 P0 routes have `@ApiOperation` with summary and description.
- `@ApiParam` on every `:serverLogin` route.
- `@ApiQuery` on `GET /servers` with enum values.
- `@ApiHeader` on the connectivity route for custom headers.
- `@ApiBody` on routes that accept a request body.
- `@ApiResponse` for all documented status codes per route.
- `@ApiTags` on all three controllers for Swagger UI grouping.
- `npm run build` succeeds. `npm run test` passes. No behavioral regressions.

---

### Phase 9 - Diagnose and fix "server never online" bug

**Goal:** Identify and fix why linked servers never appear as `online: true` in `GET /auth-state` and `GET /servers`, even when the plugin is running and sending heartbeats every 15 seconds. Both Docker stacks are currently stopped.

**Background / Findings (from code audit):**

Two root-cause bugs were identified by reading the plugin and server source code:

1. **Payload wrapping mismatch (CRITICAL):** The plugin's `AsyncPixelControlApiClient::sendEvent()` (line 56-64 of `pixel-control-plugin/src/Api/AsyncPixelControlApiClient.php`) wraps the envelope inside a transport wrapper:
   ```json
   { "envelope": { ...envelope fields... }, "transport": { "attempt": 1, ... } }
   ```
   But the NestJS `ConnectivityController` expects the envelope fields **at the top level** of the JSON body, validated by `EventEnvelopeDto`. The `ValidationPipe` with `whitelist: true` strips unrecognized keys (`envelope`, `transport`), leaving an empty body. Validation then fails, returning a 400 `invalid_envelope` rejection. Even if validation were lenient, `envelope.event_name` etc. would be `undefined`.

2. **URL path mismatch (CRITICAL):** The plugin sends ALL events (connectivity, lifecycle, player, combat, mode) to a single endpoint: `baseUrl + eventPath` = `http://host.docker.internal:3000/v1/plugin/events`. The NestJS API expects connectivity events at `POST /v1/plugin/events/connectivity` (controller prefix `plugin/events`, route `connectivity`). The plugin hits `/v1/plugin/events` which has no route handler, resulting in a 404.

**Additional observations (non-blocking but relevant):**
- `ONLINE_THRESHOLD_SECONDS` is not passed through in `pixel-control-server/docker-compose.yml`. The API service defaults to 360 via `ConfigService.get() ?? 360`, which works but should be explicit, especially for testing with a 15-second heartbeat.
- The stored `online` field in the DB (set by `ConnectivityService.ingestEvent()`) is always `true` at write time because it calls `isServerOnline(now, threshold)`. This is harmless since `getAuthState()` and `listServers()` recompute dynamically from `lastHeartbeat`, but it is misleading in the DB.

**Decision on fix approach:**

The fix must be on the **API server side**, since the plugin is the established client and its payload format + single-endpoint design are the stable contract. The API must adapt to receive what the plugin sends.

- [Done] P9.1 - Fix URL routing: accept events at `POST /v1/plugin/events` (single endpoint)

  **Problem:** The plugin sends all event categories to `POST /v1/plugin/events`. The API currently only has `POST /v1/plugin/events/connectivity`.

  **Fix:** Change the `ConnectivityController` route from `@Post('connectivity')` to `@Post()` so it handles `POST /v1/plugin/events` directly. The controller prefix is already `plugin/events`.

  Alternatively, add a catch-all route at `POST /v1/plugin/events` that dispatches to the appropriate service based on `event_category` in the envelope. For P0, only `connectivity` events need handling; other categories can return `{ ack: { status: "accepted" } }` as a no-op acknowledgment (so the plugin does not retry).

  **Recommended approach:** Rename the route to `@Post()` on the existing controller (simplest), and add an `event_category` check in the service to handle only `connectivity` events. If the category is not `connectivity`, return accepted (no-op) for now.

  **Files to change:**
  - `pixel-control-server/src/connectivity/connectivity.controller.ts` -- change `@Post('connectivity')` to `@Post()`.

  **Verification:** `curl -X POST http://localhost:3000/v1/plugin/events ...` should return 200 instead of 404.

- [Done] P9.2 - Fix payload unwrapping: extract envelope from transport wrapper

  **Problem:** The plugin wraps the envelope inside `{ "envelope": {...}, "transport": {...} }`. The API expects flat envelope fields at the body root.

  **Fix options (pick one):**

  **(A) Unwrap in the controller before validation (Recommended):** Create a new DTO or use a raw body approach. Change the controller method to accept a `PluginEventPayloadDto` that has `envelope` (type `EventEnvelopeDto`) and `transport` (optional object). Then pass `body.envelope` to the service. This preserves the existing `EventEnvelopeDto` validation.

  **(B) Unwrap in middleware/interceptor:** Create a NestJS interceptor that checks if the body has an `envelope` key and lifts it to the root. This is more magical and harder to debug.

  **(C) Support both formats:** Check if the body has an `envelope` key; if so, unwrap. If not, treat the body as a flat envelope (backward compatible with curl testing from Phase 7).

  **Recommended approach:** Option (C) -- support both formats. Implement in the controller: if `body.envelope` exists and is an object, use `body.envelope` as the envelope; otherwise use the body directly. This preserves backward compatibility with the smoke tests and curl commands from Phase 7.

  **Files to change:**
  - `pixel-control-server/src/connectivity/connectivity.controller.ts` -- add unwrapping logic before calling `connectivityService.ingestEvent()`.
  - Optionally create `pixel-control-server/src/common/dto/plugin-event-wrapper.dto.ts` for the wrapper shape.
  - Update or add unit tests for the new unwrapping behavior.

  **Verification:** Sending the plugin's wrapped format should now return `{ ack: { status: "accepted" } }` instead of `{ ack: { status: "rejected", code: "invalid_envelope" } }`.

- [Done] P9.3 - Pass `ONLINE_THRESHOLD_SECONDS` through Docker Compose

  **Problem:** `pixel-control-server/docker-compose.yml` does not pass `ONLINE_THRESHOLD_SECONDS` to the `api` service. It defaults to 360 which is fine for production (120s heartbeat x 3), but for testing with a 15s heartbeat interval, a lower threshold (e.g., 60s) would make it easier to observe online/offline transitions.

  **Fix:**
  - Add `ONLINE_THRESHOLD_SECONDS: ${ONLINE_THRESHOLD_SECONDS:-360}` to the `api` service `environment` in `pixel-control-server/docker-compose.yml`.
  - Verify `pixel-control-server/.env` already has `ONLINE_THRESHOLD_SECONDS=360` (it does).

  **Files to change:**
  - `pixel-control-server/docker-compose.yml`

- [Done] P9.4 - Update unit tests for new routing and unwrapping

  **What to test:**
  - Controller-level test: sending the wrapped format `{ "envelope": {...}, "transport": {...} }` results in accepted ack.
  - Controller-level test: sending the flat format (existing tests) still works.
  - Service-level test: no changes needed (service receives an `EventEnvelopeDto` regardless of wrapping).
  - Verify non-connectivity `event_category` returns accepted no-op (if P9.1 implements category filtering).

  **Files to change:**
  - `pixel-control-server/src/connectivity/connectivity.controller.spec.ts`
  - `pixel-control-server/src/connectivity/connectivity.service.spec.ts` (if category filtering added)

  **Verification:** `npm run test` passes.

- [Done] P9.5 - Build and lint check

  ```bash
  cd /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server
  npm run build
  npm run test
  ```

  Both must succeed with no errors.

**Acceptance criteria (Phase 9):**
- `POST /v1/plugin/events` returns 200 (not 404).
- Plugin's wrapped payload format `{ "envelope": {...}, "transport": {...} }` is correctly unwrapped and processed.
- Flat envelope format (curl-style) still works for backward compatibility.
- `ONLINE_THRESHOLD_SECONDS` is passed through Docker Compose.
- All unit tests pass, including new tests for both payload formats.
- `npm run build` succeeds.

---

### Phase 10 - Live verification with both Docker stacks

**Goal:** Restart both Docker stacks, verify the plugin's heartbeats are received by the API, and confirm `online: true` appears in the auth-state and server list endpoints.

**Pre-requisites:** Phase 9 fixes are applied. Both stacks are currently stopped.

- [Done] P10.1 - Start the pixel-control-server Docker stack

  ```bash
  cd /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server
  npm run docker:up
  ```

  Wait for the `api` and `postgres` services to be healthy. Verify:
  ```bash
  curl -s http://localhost:3000/v1 | jq .
  ```

- [Done] P10.2 - Start the pixel-sm-server Docker stack

  ```bash
  cd /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-sm-server
  docker compose up -d --build
  ```

  Verify the stack is running:
  ```bash
  docker compose ps
  ```

- [Done] P10.3 - Verify plugin .env config is correct

  Read `pixel-sm-server/.env` and confirm:
  - `PIXEL_CONTROL_API_BASE_URL=http://host.docker.internal:3000/v1`
  - `PIXEL_CONTROL_API_EVENT_PATH=/plugin/events`
  - `PIXEL_CONTROL_LINK_TOKEN=<valid token from registration>`
  - `PIXEL_CONTROL_HEARTBEAT_INTERVAL_SECONDS=15`

  If `PIXEL_CONTROL_API_BASE_URL` is wrong (e.g., does not include `/v1`, or points at `127.0.0.1` instead of `host.docker.internal`), fix it and restart the plugin:
  ```bash
  cd /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-sm-server
  bash scripts/dev-plugin-hot-sync.sh
  ```

- [Done] P10.4 - Tail API logs and confirm heartbeats arrive

  ```bash
  cd /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server
  docker compose logs -f api --tail=50
  ```

  Look for log lines from `ConnectivityService` indicating event ingestion (e.g., `Auto-registering unknown server` or Prisma query logs).

  If no events appear after 30 seconds:
  1. Check SM stack logs: `cd /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-sm-server && docker compose logs -f shootmania --tail=100`
  2. Look for `[PixelControl] Event delivery failed` lines indicating transport errors.
  3. Check if the plugin can reach the API: `docker compose exec shootmania curl -s http://host.docker.internal:3000/v1`
  4. If `host.docker.internal` does not resolve (Linux without Docker Desktop), use the Docker bridge gateway IP (`172.17.0.1`) instead.

- [Done] P10.5 - Verify online status via auth-state and server list

  ```bash
  # Get the server login
  SERVER_LOGIN=$(grep PIXEL_SM_DEDICATED_LOGIN /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-sm-server/.env | cut -d= -f2)

  # Check auth-state
  curl -s "http://localhost:3000/v1/servers/${SERVER_LOGIN}/link/auth-state" | jq .
  # Expected: online=true, last_heartbeat recent, plugin_version set

  # Check server list
  curl -s http://localhost:3000/v1/servers | jq .
  # Expected: server appears with online=true

  # Check DB directly
  cd /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server
  docker compose exec postgres psql -U pixel -d pixel_control \
    -c "SELECT server_login, linked, online, last_heartbeat, plugin_version FROM servers;"
  ```

- [Done] P10.6 - Verify connectivity_events table has records

  ```bash
  cd /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server
  docker compose exec postgres psql -U pixel -d pixel_control \
    -c "SELECT id, event_name, event_category, received_at FROM connectivity_events ORDER BY received_at DESC LIMIT 10;"
  ```

  Confirm both `plugin_registration` and `plugin_heartbeat` events are stored.

- [Done] P10.7 - Wait for multiple heartbeat cycles and re-check

  Wait 30-45 seconds (2-3 heartbeat cycles at 15s interval) and then re-query:
  ```bash
  curl -s "http://localhost:3000/v1/servers/${SERVER_LOGIN}/link/auth-state" | jq .
  ```

  Confirm `last_heartbeat` keeps updating with each cycle.

- [Done] P10.8 - Run smoke test script for regression check

  ```bash
  cd /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-control-server
  bash scripts/qa-p0-smoke.sh
  ```

  All existing assertions should still pass (verifying backward compatibility with flat envelope format).

**Acceptance criteria (Phase 10):**
- Both Docker stacks start successfully.
- API logs show connectivity events arriving from the plugin.
- `GET /v1/servers/:serverLogin/link/auth-state` returns `online: true` with a recent `last_heartbeat`.
- `GET /v1/servers` returns the server with `online: true`.
- `connectivity_events` table has both `plugin_registration` and `plugin_heartbeat` records.
- `last_heartbeat` updates with each heartbeat cycle.
- Smoke test script passes (no regressions).

---

## Evidence / Artifacts

- `pixel-control-server/prisma/migrations/<timestamp>_p0_foundation/migration.sql` -- migration file for Server + ConnectivityEvent.
- `pixel-control-server/scripts/qa-p0-smoke.sh` -- reusable QA smoke-test script.
- Docker compose logs from integration testing.

## Success criteria

- All six P0 endpoints (P0.1--P0.6) are implemented and return contract-compliant responses.
- Prisma schema includes `Server` and `ConnectivityEvent` models with correct relations.
- Unit tests pass for all services and utilities (`npm run test` exits 0).
- Integration test with live plugin confirms real connectivity events are ingested.
- Duplicate events are correctly handled (idempotency).
- Server `online` status is dynamically computed from heartbeat recency.
- `NEW_API_CONTRACT.md` P0 endpoint dev statuses can be updated from `Todo` to `Done`.
- `npm run build` succeeds with no type errors.
- Code follows project conventions: static imports only, modules per domain, Prisma for DB, Fastify platform.
- **(Phase 9+)** Plugin heartbeat events are successfully received by the API via `POST /v1/plugin/events`.
- **(Phase 9+)** Both the wrapped `{ "envelope": {...}, "transport": {...} }` and flat envelope formats are accepted.
- **(Phase 9+)** Servers appear as `online: true` in auth-state and server list when heartbeats are flowing.

## Notes / outcomes

**Completed 2026-02-27**

All six P0 endpoints are implemented and verified:

- **Phase 1**: Prisma schema extended with `Server` and `ConnectivityEvent` models. Migration `20260227140813_p0_foundation` created and applied.
- **Phase 2**: Global `/v1` prefix set; `class-validator`/`class-transformer` installed; all shared DTOs and `isServerOnline()` utility created.
- **Phase 3**: `LinkModule` implements P0.1–P0.4 in `LinkController` + `LinkService`. Includes `GET /servers` (P0.6).
- **Phase 4**: `ConnectivityModule` implements P0.5. Idempotency detection, auto-registration, server state updates, and `ConnectivityValidationFilter` for contract-compliant error responses.
- **Phase 5**: `GET /servers` is part of `LinkController` with `ServersQueryDto` enum validation for `?status=all|linked|offline`.
- **Phase 6**: 40 unit tests across 5 test files — all green. Coverage: utility, service (happy + error paths), and controller layer.
- **Phase 7**: curl verification confirms all contract responses. Smoke script (`scripts/qa-p0-smoke.sh`) runs 43 assertions — all pass. P7.1 and P7.4 (live plugin Docker integration) skipped per execution instructions.

- **Phase 8**: Swagger decorators added to all controllers. All routes fully documented.
- **Phase 9**: Two bugs diagnosed and fixed: (1) route path mismatch -- plugin sends to `POST /v1/plugin/events` (single endpoint) but API had `POST /v1/plugin/events/connectivity`; fixed by changing `@Post('connectivity')` to `@Post()` in `ConnectivityController`. (2) payload wrapper mismatch -- plugin wraps envelope in `{ "envelope": {...}, "transport": {...} }`; fixed in controller with Option C (support both wrapped and flat formats). Also: `ONLINE_THRESHOLD_SECONDS` added to docker-compose.yml env. Additional bug found during live testing: `sourceSequence`/`sourceTime` fields were `Int` (32-bit PostgreSQL) but plugin uses millisecond timestamps (up to ~1.77 trillion) -- fixed by migrating to `BigInt`. Tests updated: 46 total (up from 40).
- **Phase 10**: Full live integration verified. Plugin heartbeats arrive at `POST /v1/plugin/events` every 15s. Auth-state shows `online: true`, `last_heartbeat` updates every cycle. `connectivity_events` table accumulates both `plugin_registration` and `plugin_heartbeat` records. Smoke test 43/43 passed after updating script URLs from `/plugin/events/connectivity` to `/plugin/events`. Also: `PIXEL_CONTROL_LINK_SERVER_URL` env var needed in `pixel-sm-server/.env` to override old DB setting.

**Key decisions:**
- `GET /servers` placed in `LinkController` (not a separate module) since it shares `LinkService` data access.
- `ConnectivityValidationFilter` added to convert class-validator 400 errors into `{ ack: { status: "rejected", code: "invalid_envelope" } }` shape per contract.
- Colima used for Docker (Docker Desktop not running); postgres started via colima for migration.
- `NEW_API_CONTRACT.md` P0 endpoint Dev Status updated to `Done ✅` (12 table entries).
- Plugin uses single endpoint for all event categories (`POST /v1/plugin/events`); API now accepts all categories at this endpoint (connectivity events update server state; other categories accepted as no-op).
- Plugin's `PIXEL_CONTROL_LINK_SERVER_URL` DB setting persists across container restarts and overrides `PIXEL_CONTROL_API_BASE_URL` -- must set both (or set `PIXEL_CONTROL_LINK_SERVER_URL` explicitly in .env to override stale DB value).
- `sourceSequence`/`sourceTime` are millisecond Unix timestamps (~1.77T), must be `BigInt` in Prisma schema; cast with `BigInt()` in service layer.
