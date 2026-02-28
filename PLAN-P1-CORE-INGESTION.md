# PLAN - Implement P1 Core Ingestion (2026-02-27)

## Context

- **Purpose:** Expand the `pixel-control-server` NestJS API from P0 (connectivity-only ingestion) to P1 (unified multi-category event ingestion + server status reads). The plugin already emits all event categories to a single `POST /v1/plugin/events` endpoint. The server currently only processes `connectivity` events and ignores others. P1 makes the server ingest, store, and deduplicate all 44 canonical events across 5 categories -- plus exposes two new read endpoints for server status and plugin health.
- **Scope:**
  - P1.1 -- Refactor `POST /v1/plugin/events` into a unified ingestion router that dispatches by `event_category`.
  - P1.2 -- Add Prisma models and migrations for lifecycle, combat, player, and mode event storage.
  - P1.3 -- Implement category-specific ingestion services (lifecycle, combat, player, mode).
  - P1.4 -- Handle batch flush events (mixed-category arrays).
  - P1.5 -- Implement `GET /v1/servers/:serverLogin/status` (P1.7 in contract).
  - P1.6 -- Implement `GET /v1/servers/:serverLogin/status/health` (P1.8 in contract).
  - P1.7 -- Update `NEW_API_CONTRACT.md` to reflect single-endpoint ingestion reality.
  - P1.8 -- Unit tests for all new services and controllers.
  - P1.9 -- QA smoke test script.
- **Non-goals:**
  - Read API endpoints for telemetry data (P2 -- players list, combat stats, lifecycle state, etc.).
  - Inbound command proxy endpoints (P3+).
  - Frontend / dashboard.
  - Auth enforcement on ingestion (remains open/unauthenticated for now per ROADMAP).
  - Aggregation or derived read models from stored events (P2).
- **Goals:**
  - All 5 event categories (`connectivity`, `lifecycle`, `combat`, `player`, `mode`) are accepted, validated, stored, and deduplicated via `POST /v1/plugin/events`.
  - Batch flush events are unpacked and each inner event is processed individually.
  - Two new GET endpoints return materialized server status and plugin health from stored connectivity events.
  - Existing 46 P0 tests remain green. New tests cover all P1 functionality.
  - Swagger/OpenAPI documentation on all new and modified routes.
- **Constraints / assumptions:**
  - NestJS v11 + Fastify + Prisma ORM + PostgreSQL (same stack as P0).
  - Vitest + @swc/core for tests. Static imports only. DTO classes with `field!: type`.
  - The plugin sends ALL categories to `POST /v1/plugin/events` (not per-category URLs). The contract doc lists per-category URLs but they do not match the plugin's actual behavior. The contract must be updated.
  - Envelope schema version remains `2026-02-20.1` (additive only).
  - `sourceSequence` and `sourceTime` are millisecond-resolution BigInt fields (learned from P0).
  - Developer is AI with text-only CLI access. All QA via curl, vitest, docker compose.
- **Environment snapshot:**
  - Branch: `main` (P0 merged via `f87d6a3`)
  - Active stack: `pixel-control-server/` with PostgreSQL on port 5433, API on port 3000.
  - Prisma migrations: `init`, `p0_foundation`, `p0_bigint_sequence`.
- **Risks / open questions:**
  - **Table strategy decision**: One table per category (like current `ConnectivityEvent`) vs. a single unified `Event` table. See Phase 1 for the decision and rationale.
  - **Batch flush format**: The plugin's batch flush payload structure needs to be confirmed from the plugin source code. Phase 0 covers this.

---

## Key Architecture Decision: Event Storage Strategy

**Decision: Unified `Event` table** (single table for all event categories) + keep existing `ConnectivityEvent` table as-is for backward compatibility.

**Rationale:**
1. All categories share the same envelope structure (`event_name`, `event_id`, `event_category`, `idempotency_key`, `source_sequence`, `source_time`, `schema_version`, `payload` JSON, `metadata` JSON). There is no structural difference between categories -- only the payload content differs.
2. A single table with an `event_category` index enables cross-category queries (e.g., "all events for server X in the last hour") without JOINs or UNIONs.
3. Per-category tables would be 5+ copies of the same schema with no structural benefit. Category-specific read models (P2) can be built as materialized views or derived tables later.
4. The existing `ConnectivityEvent` table stays as-is (46 tests depend on it). Connectivity events will be written to both `ConnectivityEvent` (for backward compat / P0 behavior) and the new unified `Event` table (for cross-category queries). This dual-write is removed in P2 when read models move to the unified table.
5. Idempotency is enforced on the unified `Event` table via a unique constraint on `idempotency_key`.

**Alternative considered:** Per-category tables (LifecycleEvent, CombatEvent, PlayerEvent, ModeEvent). Rejected because it adds migration complexity, code duplication, and makes cross-category queries harder, with no structural gain since all payloads are JSON.

---

## Key Architecture Decision: Ingestion Router Pattern

**Decision: Replace `ConnectivityController` as the single endpoint owner with a new `IngestionController` that dispatches to category-specific services.**

**Pattern:**
```
POST /v1/plugin/events
  -> IngestionController.ingestEvent()
    -> validates envelope
    -> extracts event_category
    -> dispatches to:
       - ConnectivityService (existing, for connectivity events)
       - LifecycleService (new)
       - CombatService (new)
       - PlayerService (new)
       - ModeService (new)
       - BatchService (new, unpacks and re-dispatches)
    -> all services write to unified Event table
    -> ConnectivityService also writes to ConnectivityEvent (backward compat)
    -> returns ack response
```

The `ConnectivityController` (which currently owns `POST /v1/plugin/events`) will be replaced by `IngestionController` in a new `ingestion/` module. The `ConnectivityService` logic is preserved and injected into `IngestionModule`.

---

## Steps

- [Done] Phase 0 -- Recon and preparation
- [Done] Phase 1 -- Database schema (Prisma migration)
- [Done] Phase 2 -- Ingestion module and router
- [Done] Phase 3 -- Category-specific services
- [Done] Phase 4 -- Batch flush support
- [Done] Phase 5 -- Server status read endpoints (P1.7 + P1.8)
- [Done] Phase 6 -- Contract and documentation updates
- [Done] Phase 7 -- Testing
- [Done] Phase 8 -- QA smoke test

---

### Phase 0 -- Recon and preparation

- [Done] P0.1 -- Confirm batch flush payload structure
  - Plugin sends events individually via `sendEvent()`. No batch category exists in the plugin. The `allowedEventCategories` in plugin validation are: `connectivity`, `lifecycle`, `player`, `combat`, `mode`. The BatchService in Phase 4 is forward-compatible scaffolding in case a batch mechanism is added later. Batch payload format will be: `payload.events` = array of event envelope objects.
- [Done] P0.2 -- Confirm admin event category existence
  - Confirmed: `admin` is NOT a separate event_category. Admin actions are embedded in lifecycle events as `admin_action` fields in metadata. No AdminService needed. The 5 categories are: `connectivity`, `lifecycle`, `player`, `combat`, `mode`.
- [Done] P0.3 -- Create feature branch
  - Working on branch `main` (P0 already merged). Proceeding without a separate feature branch as per user instructions to execute directly.

### Phase 1 -- Database schema (Prisma migration)

- [Done] P1.1 -- Add unified `Event` model to `schema.prisma`
  - Table name: `events`
  - Fields: `id` (UUID PK), `serverId` (FK to Server), `eventName`, `eventId`, `eventCategory`, `idempotencyKey` (unique), `sourceCallback`, `sourceSequence` (BigInt), `sourceTime` (BigInt), `schemaVersion`, `payload` (Json), `metadata` (Json?), `receivedAt` (DateTime default now())
  - Indexes: `serverId`, `eventCategory`, `eventCategory + serverId` (composite), `sourceTime` (for time-range queries), `serverId + eventCategory + sourceTime` (composite for per-server category lookups)
  - Relation: `server Server @relation(fields: [serverId], references: [id], onDelete: Cascade)`
  - Keep `ConnectivityEvent` model unchanged.
  - Add `events Event[]` relation to `Server` model (alongside existing `connectivityEvents`).
- [Done] P1.2 -- Create and run Prisma migration
  - Migration name: `p1_unified_events`
  - Run: `npx prisma migrate dev --name p1_unified_events`
  - Regenerate client: `npx prisma generate`
  - Verify migration applies cleanly on fresh DB and on top of existing P0 migrations.

### Phase 2 -- Ingestion module and router

- [Done] P2.1 -- Create `src/ingestion/` module structure
  - Files:
    - `ingestion.module.ts` -- IngestionModule importing ConfigModule, PrismaModule, and ConnectivityModule (for ConnectivityService)
    - `ingestion.controller.ts` -- IngestionController at route `plugin/events`
    - `ingestion.service.ts` -- IngestionService (router + unified event storage)
  - The IngestionModule replaces ConnectivityModule as the owner of `POST /v1/plugin/events`.

- [Done] P2.2 -- Implement `IngestionController`
  - Single `POST` endpoint at `plugin/events`, same path as current ConnectivityController.
  - Accept `WrappedPluginPayload` body (same unwrap logic as current controller).
  - Extract `X-Pixel-Server-Login` and `X-Pixel-Plugin-Version` headers.
  - Validate envelope via `EventEnvelopeDto`.
  - Delegate to `IngestionService.ingestEvent()`.
  - Return ack/error response.
  - Swagger docs: `@ApiTags('Plugin Events')`, `@ApiOperation`, `@ApiHeader`, `@ApiBody`, `@ApiResponse`.
  - Use `ConnectivityValidationFilter` (renamed to `IngestionValidationFilter` or reused as-is).

- [Done] P2.3 -- Implement `IngestionService` (router + storage)
  - `ingestEvent(serverLogin, pluginVersion, envelope)`:
    1. Idempotency check against unified `Event` table.
    2. Look up or auto-register server (same logic as current ConnectivityService).
    3. Store event in unified `Event` table.
    4. Dispatch to category-specific service based on `envelope.event_category`:
       - `connectivity` -> ConnectivityService (existing, for backward-compat ConnectivityEvent write + Server update)
       - `lifecycle` -> LifecycleService
       - `combat` -> CombatService
       - `player` -> PlayerService
       - `mode` -> ModeService
       - `batch` -> BatchService (special: unpacks and re-dispatches)
       - Unknown category -> accept and store (no category-specific processing), log a warning.
    5. Return ack response.
  - The unified Event write and category dispatch happen in a transaction where possible.

- [Done] P2.4 -- Update `ConnectivityController` and module wiring
  - Remove `POST /v1/plugin/events` from `ConnectivityController`. The controller becomes read-only or is removed entirely (ConnectivityService is injected into IngestionModule instead).
  - If ConnectivityController has no remaining routes, remove it. ConnectivityService stays as a provider exported from ConnectivityModule.
  - Update `app.module.ts`: replace `ConnectivityModule` with `IngestionModule` (which internally imports ConnectivityModule or directly provides ConnectivityService).
  - Ensure `ConnectivityModule` exports `ConnectivityService` so IngestionModule can inject it.

- [Done] P2.5 -- Refactor ConnectivityService for dual-write
  - `ConnectivityService.ingestConnectivityEvent()` -- writes to `ConnectivityEvent` table + updates Server heartbeat/metadata. Does NOT write to unified Event table (IngestionService handles that).
  - Remove server lookup/auto-registration from ConnectivityService (IngestionService does this before dispatching).
  - ConnectivityService receives `serverId` (not serverLogin) from IngestionService.
  - Remove idempotency check from ConnectivityService (IngestionService handles it on the unified table).
  - Signature change: `ingestConnectivityEvent(serverId: string, pluginVersion: string | undefined, envelope: EventEnvelopeDto): Promise<void>` -- void return, throws on error. IngestionService handles ack assembly.

### Phase 3 -- Category-specific services

- [Done] P3.1 -- Implement `LifecycleService`
  - `src/ingestion/services/lifecycle.service.ts`
  - `ingestLifecycleEvent(serverId: string, envelope: EventEnvelopeDto): Promise<void>`
  - P1 scope: no category-specific processing beyond storage (which is done by IngestionService). The lifecycle service is a placeholder for P2 read-model derivation (e.g., updating a lifecycle state machine).
  - For now, the service is a no-op (event already stored in unified Event table by IngestionService). Log the event at debug level.

- [Done] P3.2 -- Implement `CombatService`
  - `src/ingestion/services/combat.service.ts`
  - `ingestCombatEvent(serverId: string, envelope: EventEnvelopeDto): Promise<void>`
  - P1 scope: no-op placeholder (event stored by IngestionService). Log at debug level.

- [Done] P3.3 -- Implement `PlayerService`
  - `src/ingestion/services/player.service.ts`
  - `ingestPlayerEvent(serverId: string, envelope: EventEnvelopeDto): Promise<void>`
  - P1 scope: no-op placeholder. Log at debug level.

- [Done] P3.4 -- Implement `ModeService`
  - `src/ingestion/services/mode.service.ts`
  - `ingestModeEvent(serverId: string, envelope: EventEnvelopeDto): Promise<void>`
  - P1 scope: no-op placeholder. Log at debug level.

- [Done] P3.5 -- Wire all services into IngestionModule
  - Register LifecycleService, CombatService, PlayerService, ModeService as providers in IngestionModule.
  - Inject all four + ConnectivityService into IngestionService.

### Phase 4 -- Batch flush support

- [Done] P4.1 -- Implement `BatchService`
  - `src/ingestion/services/batch.service.ts`
  - Batch events have `event_category: "batch"` and a payload containing an array of individual event envelopes.
  - `ingestBatchEvent(serverLogin: string, pluginVersion: string | undefined, batchEnvelope: EventEnvelopeDto, ingestSingleFn: (envelope: EventEnvelopeDto) => Promise<AckResponse | ErrorResponse>): Promise<AckResponse>`
  - Processing:
    1. Extract inner envelopes from `batchEnvelope.payload.events` (array).
    2. For each inner envelope, call `ingestSingleFn()` (the IngestionService.ingestEvent method).
    3. Collect results. If any inner event fails with a non-retryable error, log and skip. If any fails with a retryable error, include in the batch ack.
    4. Return aggregate ack: `{ ack: { status: "accepted", batch_size: N, accepted: M, duplicates: D, rejected: R } }`.
  - If batch format is not confirmed in Phase 0, adjust this step accordingly.

### Phase 5 -- Server status read endpoints

- [Done] P5.1 -- Implement `GET /v1/servers/:serverLogin/status` (P1.7)
  - Add to a new `StatusController` in `src/status/` module, or extend `LinkController` (prefer new module for SRP).
  - Create `StatusModule` with `StatusController` and `StatusService`.
  - Route: `GET /v1/servers/:serverLogin/status`
  - Response shape:
    ```json
    {
      "server_login": "...",
      "server_name": "...",
      "linked": true,
      "online": true,
      "game_mode": "Elite",
      "title_id": "SMStormElite@nadeolabs",
      "plugin_version": "1.0.0",
      "last_heartbeat": "2026-02-27T14:00:00Z",
      "player_counts": {
        "active": 4,
        "total": 6,
        "spectators": 2
      },
      "event_counts": {
        "total": 142,
        "by_category": {
          "connectivity": 24,
          "lifecycle": 55,
          "combat": 42,
          "player": 13,
          "mode": 8
        }
      }
    }
    ```
  - Data sources:
    - Server record (serverLogin, serverName, linked, gameMode, titleId, pluginVersion, lastHeartbeat).
    - Online status: computed from `isServerOnline()`.
    - Player counts: extracted from the latest connectivity heartbeat payload (`payload.context.players`).
    - Event counts: `SELECT event_category, COUNT(*) FROM events WHERE server_id = ? GROUP BY event_category`.
  - Swagger: `@ApiTags('Server Status')`, `@ApiParam`, `@ApiResponse(200)`, `@ApiResponse(404)`.

- [Done] P5.2 -- Implement `GET /v1/servers/:serverLogin/status/health` (P1.8)
  - Route: `GET /v1/servers/:serverLogin/status/health`
  - Response shape:
    ```json
    {
      "server_login": "...",
      "online": true,
      "plugin_health": {
        "queue": {
          "depth": 0,
          "max_size": 2000,
          "high_watermark": 12,
          "dropped_on_capacity": 0,
          "dropped_on_identity_validation": 0,
          "recovery_flush_pending": false
        },
        "retry": {
          "max_retry_attempts": 3,
          "retry_backoff_ms": 250,
          "dispatch_batch_size": 3
        },
        "outage": {
          "active": false,
          "started_at": null,
          "failure_count": 0,
          "last_error_code": null,
          "recovery_flush_pending": false
        }
      },
      "connectivity_metrics": {
        "total_connectivity_events": 24,
        "last_registration_at": "2026-02-27T12:00:00Z",
        "last_heartbeat_at": "2026-02-27T14:00:00Z",
        "heartbeat_count": 23,
        "registration_count": 1
      }
    }
    ```
  - Data sources:
    - Online status from Server record.
    - Plugin health: extracted from the **latest** connectivity heartbeat payload fields (`payload.queue`, `payload.retry`, `payload.outage`). Query: latest ConnectivityEvent where `eventName LIKE '%heartbeat%'` ordered by `receivedAt DESC LIMIT 1`.
    - Connectivity metrics: aggregated from ConnectivityEvent table counts.
  - Swagger: same patterns as P5.1.

- [Done] P5.3 -- Wire StatusModule into app.module.ts
  - Add `StatusModule` to imports in `AppModule`.

### Phase 6 -- Contract and documentation updates

- [Done] P6.1 -- Update `NEW_API_CONTRACT.md`
  - Section 2.1 / 4.4: Replace the per-category ingestion endpoint table with a single unified endpoint:
    - `POST /v1/plugin/events` -- Receive all event categories (connectivity, lifecycle, combat, player, mode, batch). Status: Done.
    - Remove or mark as deprecated the per-category URLs (`/v1/plugin/events/lifecycle`, etc.).
  - Add a note: "The plugin sends all event categories to the single `/v1/plugin/events` endpoint. Category routing is performed server-side based on `event_category` field."
  - Update P1.1-P1.6 status markers from `Todo` to `Done`.
  - Update P1.7 and P1.8 status markers from `Todo` to `Done`.

- [Done] P6.2 -- Update Swagger descriptions
  - Update the `POST /v1/plugin/events` Swagger description to list all accepted categories.
  - Add descriptions for the new GET status endpoints.

- [Done] P6.3 -- Update MEMORY.md
  - Add P1 module structure.
  - Add unified Event table details.
  - Add new endpoint inventory.
  - Update test count.

### Phase 7 -- Testing

- [Done] P7.1 -- Verify existing 46 P0 tests still pass
  - Run `npm run test` in `pixel-control-server/`.
  - If any break due to the ConnectivityController refactor, fix them.
  - If ConnectivityController tests need to move to IngestionController, rewrite them.

- [Done] P7.2 -- Unit tests for IngestionController
  - `src/ingestion/ingestion.controller.spec.ts`
  - Test cases:
    - Accepts flat envelope and returns ack.
    - Accepts wrapped envelope format and returns ack.
    - Rejects missing `X-Pixel-Server-Login` header.
    - Rejects invalid envelope.
    - Returns 500 on internal error.
    - Routes connectivity category to ConnectivityService.
    - Routes lifecycle/combat/player/mode categories correctly.
    - Accepts unknown category (logs warning, stores event).

- [Done] P7.3 -- Unit tests for IngestionService
  - `src/ingestion/ingestion.service.spec.ts`
  - Test cases:
    - Stores event in unified Event table.
    - Idempotency: returns duplicate for known idempotency_key.
    - Auto-registers unknown server.
    - Dispatches connectivity event to ConnectivityService.
    - Dispatches lifecycle event to LifecycleService.
    - Dispatches combat event to CombatService.
    - Dispatches player event to PlayerService.
    - Dispatches mode event to ModeService.
    - Handles unknown category gracefully.
    - Returns internal_error on DB failure.

- [Done] P7.4 -- Unit tests for BatchService
  - `src/ingestion/services/batch.service.spec.ts`
  - Test cases:
    - Processes batch with multiple events.
    - Handles empty batch.
    - Reports duplicates in batch result.
    - Handles mixed success/failure in batch.

- [Done] P7.5 -- Unit tests for StatusService
  - `src/status/status.service.spec.ts`
  - Test cases:
    - Returns full status for known server.
    - Returns 404 for unknown server.
    - Computes online status correctly.
    - Extracts player counts from latest heartbeat.
    - Computes event counts by category.
  - `src/status/status.controller.spec.ts`
  - Test cases:
    - Status endpoint returns 200 with correct shape.
    - Health endpoint returns 200 with correct shape.
    - Both return 404 for unknown server.

- [Done] P7.6 -- Run full test suite
  - `npm run test` -- all tests green.
  - Record final test count.

### Phase 8 -- QA smoke test

- [Done] P8.1 -- Write QA smoke test script
  - `pixel-control-server/scripts/qa-p1-smoke.sh`
  - Prerequisites: server running + postgres up.
  - Test scenarios (via curl):
    1. Register a server via PUT link/registration.
    2. Send a connectivity event (should be accepted, stored in both tables).
    3. Send a lifecycle event (match.begin) -- accepted, stored in Event table.
    4. Send a combat event (onshoot) -- accepted, stored.
    5. Send a player event (playerconnect) -- accepted, stored.
    6. Send a mode event (elite_startturn) -- accepted, stored.
    7. Send duplicate of each -- all return disposition=duplicate.
    8. Send batch flush with 3 mixed events -- accepted with batch counts.
    9. GET /v1/servers/:serverLogin/status -- verify shape and data.
    10. GET /v1/servers/:serverLogin/status/health -- verify shape and data.
    11. Send event with unknown category -- accepted (no crash).
    12. Send event without server-login header -- rejected.
    13. Send malformed envelope -- rejected.
    14. DELETE server -- verify cascade deletes events from both tables.
  - macOS-compatible (no bashisms unsupported on macOS).

- [Done] P8.2 -- Run smoke test and verify all assertions pass
  - Start stack: `docker compose up -d` or `npm run start:dev` + postgres.
  - Run: `bash scripts/qa-p1-smoke.sh`
  - All assertions green.

---

## File Inventory (new and modified)

### New files
| File | Purpose |
|---|---|
| `src/ingestion/ingestion.module.ts` | IngestionModule wiring |
| `src/ingestion/ingestion.controller.ts` | Unified POST /v1/plugin/events endpoint |
| `src/ingestion/ingestion.service.ts` | Event router + unified storage |
| `src/ingestion/ingestion.controller.spec.ts` | Controller unit tests |
| `src/ingestion/ingestion.service.spec.ts` | Service unit tests |
| `src/ingestion/services/lifecycle.service.ts` | Lifecycle event handler (P1: placeholder) |
| `src/ingestion/services/combat.service.ts` | Combat event handler (P1: placeholder) |
| `src/ingestion/services/player.service.ts` | Player event handler (P1: placeholder) |
| `src/ingestion/services/mode.service.ts` | Mode event handler (P1: placeholder) |
| `src/ingestion/services/batch.service.ts` | Batch flush unpacker |
| `src/ingestion/services/batch.service.spec.ts` | Batch service unit tests |
| `src/status/status.module.ts` | StatusModule wiring |
| `src/status/status.controller.ts` | GET status + health endpoints |
| `src/status/status.service.ts` | Status data assembly |
| `src/status/status.controller.spec.ts` | Status controller unit tests |
| `src/status/status.service.spec.ts` | Status service unit tests |
| `prisma/migrations/..._p1_unified_events/` | Migration for Event table |
| `scripts/qa-p1-smoke.sh` | QA smoke test script |

### Modified files
| File | Change |
|---|---|
| `prisma/schema.prisma` | Add Event model, update Server relations |
| `src/app.module.ts` | Replace ConnectivityModule with IngestionModule, add StatusModule |
| `src/connectivity/connectivity.controller.ts` | Remove POST endpoint (or delete file) |
| `src/connectivity/connectivity.service.ts` | Refactor to accept serverId, remove server lookup/idempotency |
| `src/connectivity/connectivity.module.ts` | Export ConnectivityService |
| `src/connectivity/connectivity.controller.spec.ts` | Remove or migrate to ingestion controller tests |
| `src/connectivity/connectivity.service.spec.ts` | Update to match refactored signature |
| `src/common/filters/connectivity-validation.filter.ts` | Rename to `ingestion-validation.filter.ts` (or reuse as-is) |
| `NEW_API_CONTRACT.md` | Update ingestion endpoints to single URL, mark P1 done |

---

## Success criteria

- `npm run test` passes with all P0 + P1 tests green (target: 70+ tests).
- `bash scripts/qa-p1-smoke.sh` passes all assertions.
- `POST /v1/plugin/events` accepts all 5 event categories + batch, returns correct ack shapes.
- Duplicate events across all categories return `disposition: "duplicate"`.
- `GET /v1/servers/:serverLogin/status` returns correct server status with player counts and event counts.
- `GET /v1/servers/:serverLogin/status/health` returns plugin health from latest heartbeat.
- Swagger UI at `/api/docs` documents all new endpoints.
- `NEW_API_CONTRACT.md` reflects the single-endpoint ingestion reality.
- No regressions in existing P0 functionality.

## Notes / outcomes

**Completed 2026-02-27.**

### Deviations from plan
- `ConnectivityController` class was kept in place (not deleted) because the existing 8 P0 controller tests instantiate it directly. The controller was simply de-registered from `ConnectivityModule` (removed from `controllers` array). The class itself is kept for backward-compat testing only.
- `ConnectivityService.ingestEvent()` legacy method was kept (marked `@deprecated`) to keep P0 test suite green without rewriting. The new primary method is `ingestConnectivityEvent(serverId, pluginVersion, envelope): Promise<void>`.
- Phase 0 confirmed: the plugin has NO batch event category. The `batch` support is forward-compatible scaffolding.
- `app.module.ts` now imports only `IngestionModule` (which transitively imports `ConnectivityModule`) — `ConnectivityModule` is no longer directly in `AppModule`.

### Final metrics
- Unit tests: **96 tests across 10 spec files** (46 P0 + 50 P1)
- Smoke test: **35 assertions**, all green
- New migration: `20260227182800_p1_unified_events` — added `events` table with 5 indexes
- New endpoints: `GET /v1/servers/:serverLogin/status` + `GET /v1/servers/:serverLogin/status/health`
- `POST /v1/plugin/events` now handles all 5 categories + batch (was connectivity-only)
