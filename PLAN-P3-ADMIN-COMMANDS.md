# PLAN - Implement P3 Admin Command Endpoints (2026-03-02)

## Context

- **Purpose:** Implement the P3 "Essential admin" tier -- the first write/command endpoints in the Pixel Control API. These endpoints proxy REST API calls through a ManiaControl CommunicationManager socket (AES-192-CBC encrypted TCP) to the plugin, which executes admin actions on the game server and returns responses. This is a significant architectural milestone: it transitions the API from a pure read layer to a bidirectional control plane.

- **Scope:** 16 new endpoints (P3.1--P3.16) organized across three functional groups:
  - **Map management** (P3.1--P3.6): skip, restart, jump, queue, add, remove maps
  - **Warmup and pause** (P3.7--P3.10): extend/end warmup, start/end pause
  - **Match/series configuration** (P3.11--P3.16): set/get best-of, maps score, round score
  - **Shared infrastructure**: ManiaControl socket client, link-auth injection, error mapping

  - **Dev UI** (`pixel-control-ui/`): new pages and API client methods for all 16 P3 admin endpoints

  Out of scope: P4 veto/draft endpoints, P5 whitelist/vote/auth endpoints, new plugin telemetry.

- **Goals:**
  - All 16 P3 endpoints are implemented, tested with unit tests, and documented with Swagger/OpenAPI.
  - A reusable ManiaControl communication socket client is built as shared NestJS infrastructure (`AdminProxyModule`).
  - The API server automatically injects link-auth credentials (server_login + link_bearer token from DB) when proxying to the plugin.
  - Plugin response codes are mapped to appropriate HTTP status codes (2xx, 4xx, 5xx).
  - Plugin-side `PixelControl.Admin.ExecuteAction` communication listener is re-implemented (stripped during Elite cleanup) for the P3 action subset only.
  - Plugin-side `series_targets` state is re-added to connectivity payloads to support the 3 read endpoints (P3.12, P3.14, P3.16).
  - QA smoke test script validates all 16 endpoints via curl.
  - `NEW_API_CONTRACT.md` is updated with P3 status markers.
  - Dev UI (`pixel-control-ui/`) covers all 16 P3 endpoints with interactive pages matching the existing dark theme and component patterns.
  - Existing 272 unit tests remain green. No regressions.

- **Non-goals:**
  - Full admin control surface restoration (no vote management, whitelist, auth grant/revoke, player force-team).
  - Veto/draft endpoints (P4).
  - Auth enforcement on API endpoints themselves (remains open/unauthenticated per ROADMAP; the link-auth is between API and plugin socket only).
  - Restore removed admin subsystems (VetoDraft, Team Control, Access Control, Series Control) -- only the action execution framework and P3-scoped actions are re-added.

- **Constraints / assumptions:**
  - NestJS v11 + Fastify + Prisma ORM + PostgreSQL (same stack).
  - Vitest + @swc/core for tests. Static imports only (no inline `import()`).
  - ManiaControl CommunicationManager socket protocol: AES-192-CBC encrypted TCP with `length\n<encrypted_payload>` framing. IV is the constant `kZ2Kt0CzKUjN2MJX`. Encryption key = socket password from ManiaControl settings.
  - The API server needs the ManiaControl socket connection details (host, port, password) to connect. These must be configurable via environment variables or the Server model.
  - The plugin currently has NO `PixelControl.Admin.*` communication handlers (removed during PLAN-ELITE-ENRICHMENT). These need to be re-implemented for the P3 action subset.
  - The plugin currently has NO `series_targets` in connectivity payloads (removed during Elite cleanup). Needs re-adding for P3 read endpoints.
  - The `GET /v1/servers/:serverLogin/maps` (P2.11) already exists in `MapsReadModule`. The new `POST /v1/servers/:serverLogin/maps` (P3.5) and `DELETE .../maps/:mapUid` (P3.6) need route registration that does not conflict.

- **Environment snapshot:**
  - Branch: `feat/p3-admin-commands` (based on `feat/p2-read-api`)
  - Active stack: `pixel-control-server/` with PostgreSQL on port 5433, API on port 3000
  - Tests: 272 unit tests across 23 spec files, 255 integration assertions
  - Plugin: 35 PHP source files, 29 PHP test cases

- **Dependencies / stakeholders:** None external.

- **Risks / open questions:**
  - **R1 - Socket connectivity**: The API server needs to reach the ManiaControl socket (inside Docker or on localhost). For local dev, the socket is inside the `pixel-sm-server` Docker container. The API must be configured with the socket host/port/password. This may require exposing the ManiaControl socket port in `pixel-sm-server/docker-compose.yml`.
  - **R2 - Socket connection lifecycle**: The ManiaControl `Communication` class uses `fsockopen` and async `tick()`-based reads (PHP event loop). In TypeScript (NestJS), we use Node.js `net.Socket` for TCP with Promises. Must handle connection timeouts, read timeouts, and connection pooling/reuse.
  - **R3 - Encryption key source**: The socket password is in ManiaControl's MySQL `mc_settings` table. The API server needs this password configured via env var (not read from MySQL). The password must match what ManiaControl uses.
  - **R4 - Plugin action handler restoration**: The admin control subsystem was fully removed. We need a minimal, targeted re-implementation that only handles the P3 actions, not the full 20+ action catalog. Must not re-introduce the removed subsystems.
  - **R5 - Route conflicts**: `POST /v1/servers/:serverLogin/maps` (add map) vs `GET /v1/servers/:serverLogin/maps` (list maps). These are different HTTP methods on the same path, so no conflict -- but they live in different modules (`AdminMapsModule` vs `MapsReadModule`). NestJS handles this fine as long as both modules are imported.

---

## Architecture Decisions

### D1: New `AdminProxyModule` as shared socket infrastructure

Create a new `src/admin-proxy/` module that encapsulates:
- `ManiaControlSocketClient` -- a low-level service handling TCP connection, AES-192-CBC encryption/decryption, framing, and request/response lifecycle.
- `AdminProxyService` -- a high-level service that resolves the server's link token from DB, builds the `PixelControl.Admin.ExecuteAction` payload with injected link-auth, sends it through the socket client, and maps the plugin response to an HTTP-shaped result.

All P3 command endpoint modules (maps, warmup, pause, match) depend on `AdminProxyModule` for proxying.

### D2: Socket configuration via per-server env vars (Phase 1) with DB-stored config (future)

For P3, socket connection details are configured via environment variables:
- `MC_SOCKET_HOST` (default: `127.0.0.1`)
- `MC_SOCKET_PORT` (default: `31501`)
- `MC_SOCKET_PASSWORD` (default: empty string)

These are global for the single-server use case. Future multi-server support can store per-server socket config in the `Server` model (new Prisma fields). Not in P3 scope.

### D3: Domain command modules import `AdminProxyModule` + `CommonModule`

New domain modules for P3 commands:
- **AdminMapsModule** (`src/admin-maps/`) -- P3.1--P3.6 (map skip, restart, jump, queue, add, remove)
- **AdminWarmupPauseModule** (`src/admin-warmup-pause/`) -- P3.7--P3.10
- **AdminMatchModule** (`src/admin-match/`) -- P3.11--P3.16

Each module imports `CommonModule` (for `ServerResolverService`) and `AdminProxyModule` (for `AdminProxyService`).

The 3 read endpoints (P3.12, P3.14, P3.16) can either read from connectivity telemetry (like existing read endpoints) or proxy a `match.bo.get` / `match.maps.get` / `match.score.get` socket call to get live data. Decision: **proxy to socket for live data** (more accurate than potentially stale telemetry). If the socket is unreachable, fall back to connectivity telemetry.

### D4: Plugin re-implements minimal admin communication handler

Re-create a lightweight `AdminCommandTrait` in the plugin (`src/Domain/Admin/AdminCommandTrait.php`) that:
- Registers `PixelControl.Admin.ExecuteAction` as a CommunicationManager listener.
- Validates link-auth (server_login + token match).
- Dispatches only the P3 action subset: `map.skip`, `map.restart`, `map.jump`, `map.queue`, `map.add`, `map.remove`, `warmup.extend`, `warmup.end`, `pause.start`, `pause.end`, `match.bo.get`, `match.bo.set`, `match.maps.get`, `match.maps.set`, `match.score.get`, `match.score.set`.
- Returns standardized `{ action_name, success, code, message, details }` responses.

This is a focused, self-contained trait -- not a restoration of the full admin control subsystem.

### D5: Plugin re-adds `series_targets` to connectivity payloads

The 3 read endpoints (P3.12, P3.14, P3.16) need `series_targets` data. Two approaches:
1. Read from connectivity telemetry (requires plugin to re-add `series_targets` to registration/heartbeat payloads).
2. Proxy a socket GET call (requires live socket connection).

Decision: **Approach 2 (proxy to socket)** for write+read consistency. The PUT endpoints (P3.11, P3.13, P3.15) modify state via socket, so the GET endpoints should read from the same source. The socket `match.bo.get`, `match.maps.get`, `match.score.get` actions return live values.

Optionally, also re-add `series_targets` to connectivity payloads as a secondary source for status/observability -- but this is non-blocking for P3.

### D6: HTTP status code mapping from plugin response

| Plugin response | HTTP status |
|---|---|
| `success: true` | 200 OK |
| `success: false`, code is a known client error (`map_not_found`, `invalid_parameter`, etc.) | 400 Bad Request |
| `success: false`, code is auth error (`link_auth_missing`, `link_auth_invalid`, `link_server_mismatch`, `admin_command_unauthorized`) | 403 Forbidden |
| `success: false`, code is `action_not_found` | 404 Not Found |
| Socket connection error / timeout | 502 Bad Gateway |
| Plugin returns `error: true` at communication level | 502 Bad Gateway |
| Unexpected error | 500 Internal Server Error |

### D7: ManiaControl socket must be enabled and accessible

The `pixel-sm-server` Docker stack must have the ManiaControl CommunicationManager socket enabled. The `.env.example` already has `PIXEL_CONTROL_ADMIN_CONTROL_ENABLED=1`. The socket port (default `31501`) must be exposed to the API server. For local dev (API runs on host, ManiaControl in Docker), this means mapping the socket port in `pixel-sm-server/docker-compose.yml`.

---

## Steps

- [Done] Phase 1 -- ManiaControl socket client infrastructure (server-side)
- [Done] Phase 2 -- Plugin admin command handler (plugin-side)
- [Done] Phase 3 -- Map management command endpoints (P3.1--P3.6)
- [Done] Phase 4 -- Warmup and pause command endpoints (P3.7--P3.10)
- [Done] Phase 5 -- Match/series configuration endpoints (P3.11--P3.16)
- [Done] Phase 6 -- Unit tests
- [Done] Phase 7 -- Integration/smoke tests and QA
- [Done] Phase 8 -- Dev UI: API client and types
- [Done] Phase 9 -- Dev UI: Admin pages (maps, warmup/pause, match)
- [Done] Phase 10 -- Dev UI: Navigation, routing, and build verification
- [Done] Phase 11 -- Contract docs and cleanup

---

### Phase 1 -- ManiaControl socket client infrastructure (server-side)

Build the shared TCP socket client in `pixel-control-server/src/admin-proxy/`.

- [Todo] P1.1 -- Create `ManiaControlSocketClient` service
  - New file: `src/admin-proxy/maniacontrol-socket.client.ts`
  - Implements AES-192-CBC encrypted TCP communication using Node.js `net.Socket` and `crypto`.
  - Protocol: `{ "method": "<name>", "data": { ... } }` JSON frame, encrypted with `openssl_encrypt` equivalent.
  - Framing: send `<encrypted_length>\n<encrypted_data>`, receive `<length>\n<encrypted_response>`.
  - Constants: `ENCRYPTION_METHOD = 'aes-192-cbc'`, `ENCRYPTION_IV = 'kZ2Kt0CzKUjN2MJX'`.
  - Method: `async sendCommand(host, port, password, method, data): Promise<{ error: boolean, data: unknown }>`.
  - Handles connection timeout (5s), read timeout (10s), and socket cleanup.
  - No connection pooling in Phase 1 (new connection per request); pooling deferred.

- [Todo] P1.2 -- Create `AdminProxyService`
  - New file: `src/admin-proxy/admin-proxy.service.ts`
  - Depends on: `ManiaControlSocketClient`, `ServerResolverService`, `ConfigService`.
  - Reads socket config from env vars: `MC_SOCKET_HOST`, `MC_SOCKET_PORT`, `MC_SOCKET_PASSWORD`.
  - Method: `async executeAction(serverLogin, actionName, parameters?): Promise<AdminActionResponse>`.
  - Internally:
    1. Resolves server via `ServerResolverService` (404 if not found).
    2. Reads `linkToken` from the resolved `Server` record.
    3. Builds the `PixelControl.Admin.ExecuteAction` payload: `{ action, parameters, server_login, auth: { mode: 'link_bearer', token } }`.
    4. Sends via `ManiaControlSocketClient.sendCommand(host, port, password, 'PixelControl.Admin.ExecuteAction', payload)`.
    5. Maps the plugin response to `AdminActionResponse`.
    6. Maps error codes to appropriate HTTP exceptions (see D6).
  - Method: `async queryAction(serverLogin, actionName, parameters?): Promise<AdminActionResponse>` -- same as `executeAction` but does not throw on `success: false` (used for GET queries like `match.bo.get`).

- [Todo] P1.3 -- Create DTOs and interfaces
  - New file: `src/admin-proxy/dto/admin-action.dto.ts`
  - `AdminActionResponse` interface: `{ action_name, success, code, message, details }`.
  - Request body DTOs (with `class-validator` decorators) for each endpoint group:
    - `MapJumpDto`: `{ map_uid: string }` (required)
    - `MapQueueDto`: `{ map_uid: string }` (required)
    - `MapAddDto`: `{ mx_id: string }` (required)
    - `WarmupExtendDto`: `{ seconds: number }` (required, positive integer)
    - `MatchBestOfDto`: `{ best_of: number }` (required, odd positive integer)
    - `MatchMapsScoreDto`: `{ target_team: string, maps_score: number }` (required)
    - `MatchRoundScoreDto`: `{ target_team: string, score: number }` (required)

- [Todo] P1.4 -- Create `AdminProxyModule`
  - New file: `src/admin-proxy/admin-proxy.module.ts`
  - Imports: `CommonModule`, `ConfigModule`.
  - Providers: `ManiaControlSocketClient`, `AdminProxyService`.
  - Exports: `AdminProxyService`.

- [Todo] P1.5 -- Register `AdminProxyModule` in `AppModule`
  - Add import to `src/app.module.ts`.

---

### Phase 2 -- Plugin admin command handler (plugin-side)

Re-implement a minimal admin command communication listener in the plugin.

- [Todo] P2.1 -- Create `AdminCommandTrait`
  - New file: `pixel-control-plugin/src/Domain/Admin/AdminCommandTrait.php`
  - Properties: `$adminCommandActionsRegistry` (array mapping action names to handler methods).
  - Method `registerAdminCommandListener()`: calls `$this->maniaControl->getCommunicationManager()->registerCommunicationListener('PixelControl.Admin.ExecuteAction', $this, 'handleAdminExecuteAction')`.
  - Method `handleAdminExecuteAction($data)`: validates link-auth fields, dispatches to action handler, returns `CommunicationAnswer`.

- [Todo] P2.2 -- Implement link-auth validation
  - In `AdminCommandTrait::handleAdminExecuteAction()`:
    - Checks `server_login` matches the local server login.
    - Checks `auth.mode === 'link_bearer'` and `auth.token` matches the stored link token.
    - Returns appropriate error codes: `link_auth_missing`, `link_auth_invalid`, `link_server_mismatch`.

- [Todo] P2.3 -- Implement map management action handlers
  - `handleMapSkip()`: calls `$this->maniaControl->getMapManager()->getMapActions()->skipMap()`. Returns `{ action_name: 'map.skip', success: true, code: 'map_skipped', message: '...' }`.
  - `handleMapRestart()`: calls `$this->maniaControl->getMapManager()->getMapActions()->restartMap()`.
  - `handleMapJump($data)`: validates `map_uid` parameter, calls the map jump logic.
  - `handleMapQueue($data)`: validates `map_uid`, queues the map for next.
  - `handleMapAdd($data)`: validates `mx_id`, calls ManiaExchange map add.
  - `handleMapRemove($data)`: validates `map_uid`, calls map removal.

- [Todo] P2.4 -- Implement warmup and pause action handlers
  - `handleWarmupExtend($data)`: validates `seconds` parameter, calls warmup extend via `$this->maniaControl->getClient()->triggerModeScriptEvent(...)` or equivalent ManiaPlanet XML-RPC method.
  - `handleWarmupEnd()`: ends warmup.
  - `handlePauseStart()`: pauses the match via the appropriate XML-RPC method.
  - `handlePauseEnd()`: resumes from pause.

- [Todo] P2.5 -- Implement match/series action handlers
  - `handleMatchBestOfGet()`: reads current best-of from runtime settings. Returns in `details`.
  - `handleMatchBestOfSet($data)`: validates `best_of` (odd positive integer), sets it.
  - `handleMatchMapsGet()`: reads current maps score state.
  - `handleMatchMapsSet($data)`: validates `target_team` and `maps_score`, sets maps score.
  - `handleMatchScoreGet()`: reads current round score state.
  - `handleMatchScoreSet($data)`: validates `target_team` and `score`, sets round score.

- [Todo] P2.6 -- Wire trait into `PixelControlPlugin`
  - Add `use AdminCommandTrait;` to `PixelControlPlugin.php`.
  - Add `use PixelControl\Domain\Admin\AdminCommandTrait;` import.
  - Call `$this->registerAdminCommandListener()` in `load()`.
  - Call cleanup in `unload()`.

- [Todo] P2.7 -- Plugin PHP tests for admin command handling
  - New test file: `pixel-control-plugin/tests/cases/50AdminCommandTest.php`
  - Test link-auth validation (missing, invalid, mismatch).
  - Test action routing (known action dispatched, unknown action rejected).
  - Test parameter validation for each action type.

---

### Phase 3 -- Map management command endpoints (P3.1--P3.6)

- [Todo] P3.1 -- Create `AdminMapsModule`
  - New directory: `src/admin-maps/`
  - Files: `admin-maps.module.ts`, `admin-maps.controller.ts`, `admin-maps.service.ts`
  - Module imports: `AdminProxyModule`, `CommonModule`.

- [Todo] P3.2 -- Implement map command endpoints
  - `POST /v1/servers/:serverLogin/maps/skip` (P3.1): calls `adminProxy.executeAction(serverLogin, 'map.skip')`.
  - `POST /v1/servers/:serverLogin/maps/restart` (P3.2): calls `adminProxy.executeAction(serverLogin, 'map.restart')`.
  - `POST /v1/servers/:serverLogin/maps/jump` (P3.3): body `MapJumpDto`, calls `adminProxy.executeAction(serverLogin, 'map.jump', { map_uid })`.
  - `POST /v1/servers/:serverLogin/maps/queue` (P3.4): body `MapQueueDto`, calls `adminProxy.executeAction(serverLogin, 'map.queue', { map_uid })`.
  - `POST /v1/servers/:serverLogin/maps` (P3.5): body `MapAddDto`, calls `adminProxy.executeAction(serverLogin, 'map.add', { mx_id })`.
  - `DELETE /v1/servers/:serverLogin/maps/:mapUid` (P3.6): calls `adminProxy.executeAction(serverLogin, 'map.remove', { map_uid: mapUid })`.

- [Todo] P3.3 -- Swagger/OpenAPI decorators on all map command endpoints
  - `@ApiTags('Admin - Maps')`, `@ApiOperation`, `@ApiParam`, `@ApiBody`, `@ApiResponse` for 200, 400, 403, 404, 502.

- [Todo] P3.4 -- Verify no route conflicts with existing `MapsReadModule`
  - Existing `GET :serverLogin/maps` is in `MapsReadController`.
  - New `POST :serverLogin/maps` and `POST :serverLogin/maps/skip|restart|jump|queue` and `DELETE :serverLogin/maps/:mapUid` are in `AdminMapsController`.
  - Different HTTP methods, no conflict. Verify registration order in `AppModule`.

---

### Phase 4 -- Warmup and pause command endpoints (P3.7--P3.10)

- [Todo] P4.1 -- Create `AdminWarmupPauseModule`
  - New directory: `src/admin-warmup-pause/`
  - Files: `admin-warmup-pause.module.ts`, `admin-warmup-pause.controller.ts`, `admin-warmup-pause.service.ts`
  - Module imports: `AdminProxyModule`, `CommonModule`.

- [Todo] P4.2 -- Implement warmup/pause command endpoints
  - `POST /v1/servers/:serverLogin/warmup/extend` (P3.7): body `WarmupExtendDto`, calls `adminProxy.executeAction(serverLogin, 'warmup.extend', { seconds })`.
  - `POST /v1/servers/:serverLogin/warmup/end` (P3.8): calls `adminProxy.executeAction(serverLogin, 'warmup.end')`.
  - `POST /v1/servers/:serverLogin/pause/start` (P3.9): calls `adminProxy.executeAction(serverLogin, 'pause.start')`.
  - `POST /v1/servers/:serverLogin/pause/end` (P3.10): calls `adminProxy.executeAction(serverLogin, 'pause.end')`.

- [Todo] P4.3 -- Swagger/OpenAPI decorators on warmup/pause endpoints
  - `@ApiTags('Admin - Warmup/Pause')` with operation descriptions, params, bodies, and response codes.

---

### Phase 5 -- Match/series configuration endpoints (P3.11--P3.16)

- [Todo] P5.1 -- Create `AdminMatchModule`
  - New directory: `src/admin-match/`
  - Files: `admin-match.module.ts`, `admin-match.controller.ts`, `admin-match.service.ts`
  - Module imports: `AdminProxyModule`, `CommonModule`.

- [Todo] P5.2 -- Implement match write endpoints (P3.11, P3.13, P3.15)
  - `PUT /v1/servers/:serverLogin/match/best-of` (P3.11): body `MatchBestOfDto`, calls `adminProxy.executeAction(serverLogin, 'match.bo.set', { best_of })`.
  - `PUT /v1/servers/:serverLogin/match/maps-score` (P3.13): body `MatchMapsScoreDto`, calls `adminProxy.executeAction(serverLogin, 'match.maps.set', { target_team, maps_score })`.
  - `PUT /v1/servers/:serverLogin/match/round-score` (P3.15): body `MatchRoundScoreDto`, calls `adminProxy.executeAction(serverLogin, 'match.score.set', { target_team, score })`.

- [Todo] P5.3 -- Implement match read endpoints (P3.12, P3.14, P3.16)
  - `GET /v1/servers/:serverLogin/match/best-of` (P3.12): calls `adminProxy.queryAction(serverLogin, 'match.bo.get')`. Returns the `details` field containing `{ best_of, ... }`.
  - `GET /v1/servers/:serverLogin/match/maps-score` (P3.14): calls `adminProxy.queryAction(serverLogin, 'match.maps.get')`. Returns maps score state from `details`.
  - `GET /v1/servers/:serverLogin/match/round-score` (P3.16): calls `adminProxy.queryAction(serverLogin, 'match.score.get')`. Returns round score state from `details`.
  - Each GET endpoint: if socket unreachable (502), attempt fallback to connectivity telemetry `series_targets` (if available). If neither source has data, return 503 Service Unavailable with a descriptive message.

- [Todo] P5.4 -- Swagger/OpenAPI decorators on match endpoints
  - `@ApiTags('Admin - Match')` with operation descriptions, params, bodies, and response codes.

---

### Phase 6 -- Unit tests

- [Todo] P6.1 -- `ManiaControlSocketClient` unit tests
  - New file: `src/admin-proxy/maniacontrol-socket.client.spec.ts`
  - Test AES-192-CBC encryption/decryption round-trip.
  - Test frame encoding (length prefix + newline + encrypted data).
  - Test timeout handling (mock socket that never responds).
  - Test malformed response handling.

- [Todo] P6.2 -- `AdminProxyService` unit tests
  - New file: `src/admin-proxy/admin-proxy.service.spec.ts`
  - Mock `ManiaControlSocketClient` and `ServerResolverService`.
  - Test link-auth injection (server_login + token from DB).
  - Test successful action proxying.
  - Test error code to HTTP exception mapping (400, 403, 404, 502).
  - Test server not found (404).
  - Test server not linked / no link token (403).

- [Todo] P6.3 -- `AdminMapsController` unit tests
  - New file: `src/admin-maps/admin-maps.controller.spec.ts`
  - Mock `AdminProxyService`.
  - Test all 6 map endpoints with expected action names and parameters.
  - Test DTO validation (missing required fields).

- [Todo] P6.4 -- `AdminWarmupPauseController` unit tests
  - New file: `src/admin-warmup-pause/admin-warmup-pause.controller.spec.ts`
  - Mock `AdminProxyService`.
  - Test all 4 warmup/pause endpoints.

- [Todo] P6.5 -- `AdminMatchController` unit tests
  - New file: `src/admin-match/admin-match.controller.spec.ts`
  - Mock `AdminProxyService`.
  - Test all 6 match endpoints (3 write, 3 read).
  - Test DTO validation (best_of must be odd, target_team validation, etc.).

---

### Phase 7 -- Integration/smoke tests and QA

- [Todo] P7.1 -- Create QA smoke test script
  - New file: `pixel-control-server/scripts/qa-p3-admin-commands-smoke.sh`
  - Requires running API server + running ManiaControl with socket enabled.
  - Validates all 16 P3 endpoints with expected HTTP status codes and response shapes.
  - Tests error cases: missing parameters (400), invalid server (404), socket unreachable (502).

- [Todo] P7.2 -- Verify existing tests remain green
  - Run `npm run test` in `pixel-control-server/` -- all 272+ existing tests pass.
  - Run `bash pixel-control-plugin/scripts/check-quality.sh` -- all PHP lint + tests pass.

- [Todo] P7.3 -- Plugin integration test with socket
  - If the Docker dev stack is available, manually test the full proxy chain: API endpoint -> socket client -> ManiaControl -> plugin handler -> response.
  - Document any socket configuration gotchas.

---

### Phase 8 -- Dev UI: API client and types

Add P3 admin command support to the `pixel-control-ui` typed API client layer and TypeScript types.

- [Todo] P8.1 -- Add `AdminActionResponse` type to `src/types/api.ts`
  - New section `// --- Admin Commands ---` after the existing `Maps & Mode` section.
  - `AdminActionResponse`: `{ action_name: string; success: boolean; code: string; message: string; details?: Record<string, unknown> }`.
  - `MatchBestOfResponse`: `{ best_of: number; [key: string]: unknown }` -- shape returned by `match.bo.get` details.
  - `MatchMapsScoreResponse`: `{ team_a_maps: number; team_b_maps: number; [key: string]: unknown }` -- shape returned by `match.maps.get` details.
  - `MatchRoundScoreResponse`: `{ team_a_score: number; team_b_score: number; [key: string]: unknown }` -- shape returned by `match.score.get` details.

- [Todo] P8.2 -- Create `src/api/admin.ts` API client module
  - New file covering all 16 P3 endpoints. Uses `apiPost`, `apiPut`, `apiDelete`, `apiGet` from `client.ts`.
  - Map management functions:
    - `skipMap(serverLogin)` -- `POST /servers/:serverLogin/maps/skip`
    - `restartMap(serverLogin)` -- `POST /servers/:serverLogin/maps/restart`
    - `jumpToMap(serverLogin, mapUid)` -- `POST /servers/:serverLogin/maps/jump`, body `{ map_uid }`
    - `queueMap(serverLogin, mapUid)` -- `POST /servers/:serverLogin/maps/queue`, body `{ map_uid }`
    - `addMap(serverLogin, mxId)` -- `POST /servers/:serverLogin/maps`, body `{ mx_id }`
    - `removeMap(serverLogin, mapUid)` -- `DELETE /servers/:serverLogin/maps/:mapUid`
  - Warmup/pause functions:
    - `extendWarmup(serverLogin, seconds)` -- `POST /servers/:serverLogin/warmup/extend`, body `{ seconds }`
    - `endWarmup(serverLogin)` -- `POST /servers/:serverLogin/warmup/end`
    - `startPause(serverLogin)` -- `POST /servers/:serverLogin/pause/start`
    - `endPause(serverLogin)` -- `POST /servers/:serverLogin/pause/end`
  - Match config functions:
    - `setBestOf(serverLogin, bestOf)` -- `PUT /servers/:serverLogin/match/best-of`, body `{ best_of }`
    - `getBestOf(serverLogin)` -- `GET /servers/:serverLogin/match/best-of`
    - `setMapsScore(serverLogin, targetTeam, mapsScore)` -- `PUT /servers/:serverLogin/match/maps-score`, body `{ target_team, maps_score }`
    - `getMapsScore(serverLogin)` -- `GET /servers/:serverLogin/match/maps-score`
    - `setRoundScore(serverLogin, targetTeam, score)` -- `PUT /servers/:serverLogin/match/round-score`, body `{ target_team, score }`
    - `getRoundScore(serverLogin)` -- `GET /servers/:serverLogin/match/round-score`
  - All functions return `Promise<ApiResult<AdminActionResponse>>` for write endpoints. Read endpoints return their specific response type.

---

### Phase 9 -- Dev UI: Admin pages (maps, warmup/pause, match)

Create new page components for the P3 admin commands. Follow existing write-page patterns:
- `useState` for form fields and loading/error/result state (not `useApi` hook, which is for auto-fetching reads).
- `handleSubmit` or `handleAction` async handler that calls the API and stores result/error.
- `ConfirmModal` for destructive actions (map remove, end warmup).
- `JsonViewer` for raw response toggle on every result.
- `Badge` for success/failure status in result display.
- `EmptyState` when no server is selected.
- All pages use `max-w-4xl` or `max-w-xl` container, `page-title`, `card`, `section-title`, `label`, `input-field`, `btn-primary`, `btn-secondary`, `btn-danger` CSS classes.
- Response history pattern (like EventInjector) for command actions that may be invoked repeatedly.

- [Todo] P9.1 -- Create `src/pages/AdminMapControl.tsx`
  - Single page for all 6 map commands (P3.1--P3.6).
  - Layout: two-column grid (commands on left, response history on right) -- same as EventInjector pattern.
  - Left column: 6 action cards, each in its own `card` div:
    - **Skip Map** (P3.1): single `btn-primary` "Skip to next map". No form fields.
    - **Restart Map** (P3.2): single `btn-primary` "Restart current map". No form fields.
    - **Jump to Map** (P3.3): `input-field` for `map_uid` + `btn-primary` "Jump".
    - **Queue Map** (P3.4): `input-field` for `map_uid` + `btn-primary` "Queue".
    - **Add from MX** (P3.5): `input-field` for `mx_id` (ManiaExchange ID) + `btn-primary` "Add Map".
    - **Remove Map** (P3.6): `input-field` for `map_uid` + `btn-danger` "Remove". Triggers `ConfirmModal` before executing.
  - Right column: response history list (same pattern as EventInjector -- capped at 10 entries, newest first).
  - Each history entry shows: `Badge` (green if `success`, red if not), `code`, `message`, timestamp, and a `JsonViewer` for full response.
  - All actions call the corresponding function from `src/api/admin.ts`.

- [Todo] P9.2 -- Create `src/pages/AdminWarmupPause.tsx`
  - Single page for all 4 warmup/pause commands (P3.7--P3.10).
  - Layout: two-section card layout (warmup section + pause section) above response history.
  - **Warmup section** (`card`):
    - `section-title` "Warmup Control"
    - Extend warmup: `input-field` for `seconds` (type number, min 1) + `btn-primary` "Extend Warmup".
    - End warmup: `btn-danger` "End Warmup" with `ConfirmModal` ("This will end the warmup phase immediately").
  - **Pause section** (`card`):
    - `section-title` "Pause Control"
    - Start pause: `btn-primary` "Pause Match".
    - End pause: `btn-primary` "Resume Match".
  - Response history below (same pattern, capped at 10 entries).

- [Todo] P9.3 -- Create `src/pages/AdminMatchConfig.tsx`
  - Single page for all 6 match/series endpoints (P3.11--P3.16).
  - Layout: three `card` sections (best-of, maps score, round score), each containing a read display + write form.
  - **Best-of section** (`card`):
    - `section-title` "Best-of Configuration"
    - Read: auto-fetch current best-of on mount via `getBestOf()` using `useApi` hook. Display as `StatCard` with accent `orange`.
    - Write: `input-field` for `best_of` (type number, min 1, odd validation hint) + `btn-primary` "Set Best-of".
    - Refetch read data after successful write.
  - **Maps Score section** (`card`):
    - `section-title` "Maps Score"
    - Read: auto-fetch via `getMapsScore()`. Display team A and team B maps as two `StatCard`s side by side.
    - Write: `select` for `target_team` (options: `team_a`, `team_b`) + `input-field` for `maps_score` (type number, min 0) + `btn-primary` "Set Maps Score".
    - Refetch after successful write.
  - **Round Score section** (`card`):
    - `section-title` "Round Score"
    - Read: auto-fetch via `getRoundScore()`. Display team A and team B scores as two `StatCard`s.
    - Write: `select` for `target_team` + `input-field` for `score` (type number, min 0) + `btn-primary` "Set Round Score".
    - Refetch after successful write.
  - Each write operation shows success/error inline with `Badge` and includes `JsonViewer` for response.
  - Error handling: if GET endpoints return 502 (socket unreachable), display a `ErrorBanner` with a "Socket unavailable" message and Retry button.

---

### Phase 10 -- Dev UI: Navigation, routing, and build verification

- [Todo] P10.1 -- Add routes to `src/App.tsx`
  - Import the 3 new page components.
  - Add routes inside the `<Route path="/" element={<MainLayout />}>` block:
    - `<Route path="admin/maps" element={<AdminMapControl />} />`
    - `<Route path="admin/warmup-pause" element={<AdminWarmupPause />} />`
    - `<Route path="admin/match" element={<AdminMatchConfig />} />`

- [Todo] P10.2 -- Add navigation section to `MainLayout.tsx`
  - Add a new nav section to the `navSections` array in `MainLayout.tsx`:
    ```
    {
      title: 'Admin',
      items: [
        { to: '/admin/maps', label: 'Map Control', icon: '...' },
        { to: '/admin/warmup-pause', label: 'Warmup / Pause', icon: '...' },
        { to: '/admin/match', label: 'Match Config', icon: '...' },
      ],
    }
    ```
  - Position this section after "Resources" and before "Tools" in the sidebar.
  - Use appropriate text icons or unicode symbols consistent with the existing icon style (emoji-based).

- [Todo] P10.3 -- Verify `npm run build` succeeds with zero errors
  - Run `cd pixel-control-ui && npm run build` to confirm TypeScript compilation and Vite bundling succeed.
  - Fix any import errors or type mismatches.

- [Todo] P10.4 -- Manual smoke test of UI pages
  - Start the dev server (`npm run dev`).
  - Navigate to each of the 3 new admin pages.
  - Verify that pages render correctly, forms display, and the dark theme is consistent.
  - If the API server is running, test actual command execution via the UI.
  - Verify response history displays correctly and `JsonViewer` toggles work.

---

### Phase 11 -- Contract docs and cleanup

- [Todo] P11.1 -- Update `NEW_API_CONTRACT.md`
  - Change all 16 P3 endpoint rows from `Todo` to `Done`.

- [Todo] P11.2 -- Update `CLAUDE.md`
  - Add the new modules to the "Module structure" section.
  - Add socket configuration env vars to the gotchas/conventions.
  - Note that admin commands are now available (P3 tier).

- [Todo] P11.3 -- Update memory files
  - Update `MEMORY.md` with new modules, endpoints, test counts, and key decisions.
  - Note the `AdminProxyModule` pattern for future P4/P5 reuse.
  - Add the new UI pages to the `pixel-control-ui` section.

- [Todo] P11.4 -- Update `.env.example` files
  - Add `MC_SOCKET_HOST`, `MC_SOCKET_PORT`, `MC_SOCKET_PASSWORD` to `pixel-control-server/.env.example` (create if missing) and `pixel-control-server/docker-compose.yml`.

---

## New files summary

### Server (`pixel-control-server/src/`)

| File | Purpose |
|---|---|
| `admin-proxy/admin-proxy.module.ts` | Shared admin proxy NestJS module |
| `admin-proxy/admin-proxy.service.ts` | High-level action proxy (auth injection, error mapping) |
| `admin-proxy/maniacontrol-socket.client.ts` | Low-level AES TCP socket client |
| `admin-proxy/dto/admin-action.dto.ts` | Shared response interface + request DTOs |
| `admin-proxy/admin-proxy.service.spec.ts` | Unit tests for proxy service |
| `admin-proxy/maniacontrol-socket.client.spec.ts` | Unit tests for socket client |
| `admin-maps/admin-maps.module.ts` | Map command module |
| `admin-maps/admin-maps.controller.ts` | Map command controller (P3.1--P3.6) |
| `admin-maps/admin-maps.service.ts` | Map command service |
| `admin-maps/admin-maps.controller.spec.ts` | Unit tests |
| `admin-warmup-pause/admin-warmup-pause.module.ts` | Warmup/pause command module |
| `admin-warmup-pause/admin-warmup-pause.controller.ts` | Warmup/pause controller (P3.7--P3.10) |
| `admin-warmup-pause/admin-warmup-pause.service.ts` | Warmup/pause service |
| `admin-warmup-pause/admin-warmup-pause.controller.spec.ts` | Unit tests |
| `admin-match/admin-match.module.ts` | Match config module |
| `admin-match/admin-match.controller.ts` | Match controller (P3.11--P3.16) |
| `admin-match/admin-match.service.ts` | Match config service |
| `admin-match/admin-match.controller.spec.ts` | Unit tests |
| `scripts/qa-p3-admin-commands-smoke.sh` | QA smoke test |

### Plugin (`pixel-control-plugin/src/`)

| File | Purpose |
|---|---|
| `Domain/Admin/AdminCommandTrait.php` | Communication listener + action handlers |
| `tests/cases/50AdminCommandTest.php` | PHP tests for admin commands |

### Dev UI (`pixel-control-ui/src/`)

| File | Purpose |
|---|---|
| `api/admin.ts` | API client module for all 16 P3 admin endpoints |
| `types/api.ts` (updated) | `AdminActionResponse`, `MatchBestOfResponse`, `MatchMapsScoreResponse`, `MatchRoundScoreResponse` types |
| `pages/AdminMapControl.tsx` | Map management command page (P3.1--P3.6) |
| `pages/AdminWarmupPause.tsx` | Warmup/pause control page (P3.7--P3.10) |
| `pages/AdminMatchConfig.tsx` | Match/series config page (P3.11--P3.16) |
| `App.tsx` (updated) | 3 new routes under `/admin/*` |
| `layouts/MainLayout.tsx` (updated) | "Admin" nav section in sidebar |

---

## Success criteria

- All 16 P3 endpoints respond with correct HTTP status codes and response shapes.
- `ManiaControlSocketClient` correctly encrypts/decrypts AES-192-CBC frames and handles timeouts.
- `AdminProxyService` automatically injects link-auth from DB when proxying.
- Plugin `PixelControl.Admin.ExecuteAction` handler processes all 16 actions and validates link-auth.
- All new unit tests pass. All 272 existing unit tests remain green.
- QA smoke test passes for all 16 endpoints (or documents which require live ManiaControl).
- `NEW_API_CONTRACT.md` shows all P3 endpoints as "Done".
- No regressions in existing P0/P1/P2 functionality.
- Dev UI builds with zero errors (`npm run build` in `pixel-control-ui/`).
- All 3 new admin pages render correctly with the existing dark theme.
- Admin sidebar navigation section is present and routes work.
- Map control page covers all 6 map actions with response history.
- Warmup/pause page covers all 4 warmup/pause actions.
- Match config page shows live read data and allows write updates for all 3 config groups.

---

## Notes / outcomes

**Executed 2026-03-03. All 11 phases complete.**

- 16 new P3 admin command endpoints implemented and tested.
- `AdminProxyModule` provides reusable ManiaControl socket infrastructure for future command tiers.
- ManiaControl AES-192-CBC socket client handles connection timeouts (5s), read timeouts (10s), and error mapping.
- PHP plugin `AdminCommandTrait` re-implements `PixelControl.Admin.ExecuteAction` for P3 scope (16 actions, link-auth validation).
- 326 unit tests across 28 spec files — all green (was 272 across 23 before P3).
- 50 PHP plugin tests across 4 spec files — all green (was 29 across 3 before P3).
- QA smoke test: `qa-p3-admin-commands-smoke.sh` — 28/28 passed (no live socket required; 502/503 accepted as valid for proxy-through endpoints).
- Dev UI: 3 new admin pages, 16 new API client functions, `AdminActionResponse` type added.
- Build: 98 modules, 351KB JS, zero TypeScript errors.
- All 16 P3 rows in `NEW_API_CONTRACT.md` updated to Done.
- Key gotcha: bash `${2:-{}}` default triggers brace expansion — use explicit `if [ -z "$body" ]` in smoke test helpers.
- Key gotcha: `POST /v1/servers/:serverLogin/maps` (P3.5 add) coexists with `GET` (P2.11 list) because different HTTP methods on same path don't conflict in NestJS/Fastify.
