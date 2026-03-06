# PLAN - Server Configuration Templates (2026-03-06)

## Context

- **Purpose**: The project already persists plugin runtime state per server via `ServerState` (JSON blob). Operators need the ability to define reusable configuration templates that can be linked to one or more servers. When a server with a linked template has no saved state yet, `GET /state` transparently returns the template config as if it were a saved state — the plugin doesn't know the difference.
- **Scope**:
  - Server side: new `ConfigTemplate` Prisma model, new `ConfigTemplateModule` (CRUD + link/unlink + apply), migration, DTOs, unit tests.
  - Modify `ServerStateService.getState()` to fall back to the linked template when no saved state exists.
  - Add `configTemplateId` foreign key to `Server` model (nullable, many-to-one).
  - UI: two new pages — template list/CRUD and template detail/edit — plus server-template association UI.
  - API contract update, smoke tests, regression tests.
  - **Out of scope**: Plugin PHP changes (plugin continues to `GET /state` normally), template versioning/history, template import/export, partial templates.
- **Goals**:
  - Full CRUD for configuration templates (create, read, list, update, delete).
  - Each template stores a complete `AdminStateDto` structure (all fields required).
  - Many-to-one: multiple servers can reference the same template.
  - `GET /v1/servers/:serverLogin/state` transparently returns template config when no `ServerState` row exists, with a `source: 'template' | 'saved' | 'default'` field in the response.
  - Explicit `POST /v1/servers/:serverLogin/state/apply-template` endpoint to force-write the template config as the server's saved state.
  - Delete template returns 409 Conflict if servers are still linked.
  - UI pages for template management and server-template association.
- **Non-goals**:
  - Partial templates (all `AdminStateDto` fields are required).
  - Template inheritance or composition (no template-of-templates).
  - Auto-push to live servers via socket (templates only affect the state endpoint; the plugin picks up changes on next restart/sync).
  - Plugin-side changes.
- **Constraints / assumptions**:
  - Template config structure matches `AdminStateDto` exactly (same 10 fields: `current_best_of`, `team_maps_score`, `team_round_score`, `team_policy_enabled`, `team_switch_lock`, `team_roster`, `whitelist_enabled`, `whitelist`, `vote_policy`, `vote_ratios`).
  - The `GET /state` response gains a new optional `source` field. The plugin ignores unknown fields, so this is backwards-compatible.
  - When building the fallback snapshot from a template, the server wraps it in the standard `ServerStateSnapshotDto` envelope (`state_version: '1.0'`, `captured_at: <now>`, `admin: <template_config>`, `veto_draft: <defaults>`).
  - Templates are global (not per-server, not per-user). No auth beyond what the API already provides.
  - Existing `ServerState` rows are unaffected — template only kicks in when `ServerState` is null.
- **Environment snapshot**:
  - Branch: `main` (clean).
  - Current test counts: 405 server unit tests (2 pre-existing failures), 123 PHP plugin tests.
  - Current smoke tests: qa-p0 through qa-state-sync (all passing).

---

## Steps

- [Done] Phase 1 - Prisma schema + migration
- [Done] Phase 2 - ConfigTemplateModule (CRUD service, controller, DTOs)
- [Done] Phase 3 - Server-template association endpoints
- [Done] Phase 4 - ServerStateService template fallback + apply-template endpoint
- [Done] Phase 5 - Server unit tests
- [Done] Phase 6 - UI: API client + template pages
- [Done] Phase 7 - UI: server-template association
- [Done] Phase 8 - API contract update
- [Done] Phase 9 - Smoke test script
- [Done] Phase 10 - Regression testing

---

### Phase 1 - Prisma schema + migration

Add a `ConfigTemplate` model and a foreign key on `Server`.

- [Done] P1.1 - Add `ConfigTemplate` model to `pixel-control-server/prisma/schema.prisma`
  - Model shape:
    ```prisma
    model ConfigTemplate {
      id          String   @id @default(uuid())
      name        String   @unique
      description String?
      config      Json
      createdAt   DateTime @default(now()) @map("created_at")
      updatedAt   DateTime @updatedAt @map("updated_at")

      servers Server[]

      @@map("config_templates")
    }
    ```
  - `config` stores the full `AdminStateDto` structure as JSON.
  - `name` is unique — used as the human-readable identifier.
  - `servers` is the reverse relation (one template → many servers).

- [Done] P1.2 - Add `configTemplateId` to `Server` model
  - New nullable field:
    ```prisma
    configTemplateId String? @map("config_template_id")
    configTemplate   ConfigTemplate? @relation(fields: [configTemplateId], references: [id])
    ```
  - Many-to-one: each server can optionally reference one template.

- [Done] P1.3 - Generate and run Prisma migration
  - `cd pixel-control-server && npx prisma migrate dev --name add-config-templates`
  - Verify migration SQL creates `config_templates` table and adds FK column to `servers`.

- [Done] P1.4 - Regenerate Prisma client
  - `npm run prisma:generate`

### Phase 2 - ConfigTemplateModule (CRUD service, controller, DTOs)

Create a new NestJS module for template CRUD.

- [Done] P2.1 - Create DTOs: `pixel-control-server/src/config-template/dto/config-template.dto.ts`
  - `CreateConfigTemplateDto`:
    - `name!: string` (@IsString, @IsNotEmpty)
    - `description?: string` (@IsString, @IsOptional)
    - `config!: AdminConfigDto` (@ValidateNested, @Type)
  - `UpdateConfigTemplateDto`:
    - `name?: string` (@IsString, @IsOptional)
    - `description?: string` (@IsString, @IsOptional)
    - `config?: AdminConfigDto` (@ValidateNested, @IsOptional, @Type)
  - `AdminConfigDto` — reuse shape from `AdminStateDto` in `server-state/dto/server-state.dto.ts`:
    - `current_best_of!: number`
    - `team_maps_score!: Record<string, number>`
    - `team_round_score!: Record<string, number>`
    - `team_policy_enabled!: boolean`
    - `team_switch_lock!: boolean`
    - `team_roster!: Record<string, string>`
    - `whitelist_enabled!: boolean`
    - `whitelist!: string[]`
    - `vote_policy!: string`
    - `vote_ratios!: Record<string, number>`
  - Response interfaces:
    - `ConfigTemplateResponse` — `{ id, name, description, config, server_count, created_at, updated_at }`
    - `ConfigTemplateListResponse` — `ConfigTemplateResponse[]`
  - NOTE: `AdminConfigDto` should be extracted from or aligned with `AdminStateDto` to avoid duplication. If possible, import/re-export `AdminStateDto` from `server-state/dto` and alias it. If the import creates a circular dependency, create a shared DTO file in `common/dto/` or duplicate with a comment referencing the source.

- [Done] P2.2 - Create service: `pixel-control-server/src/config-template/config-template.service.ts`
  - Inject `PrismaService`.
  - `create(dto: CreateConfigTemplateDto)`: insert new template, return `ConfigTemplateResponse`.
  - `findAll()`: list all templates with `_count.servers`, return `ConfigTemplateListResponse`.
  - `findOne(id: string)`: get single template with `_count.servers`, throw 404 if not found.
  - `update(id: string, dto: UpdateConfigTemplateDto)`: partial update, throw 404 if not found. If `name` is being updated and conflicts, let Prisma unique constraint propagate (catch and throw 409).
  - `remove(id: string)`: check if any servers are linked (`_count.servers > 0`), throw 409 Conflict if so. Otherwise delete. Throw 404 if not found.

- [Done] P2.3 - Create controller: `pixel-control-server/src/config-template/config-template.controller.ts`
  - `@Controller('config-templates')` with `@ApiTags('Config Templates')`.
  - Endpoints:
    - `POST /v1/config-templates` — create template (201).
    - `GET /v1/config-templates` — list all templates (200).
    - `GET /v1/config-templates/:id` — get single template (200, 404).
    - `PUT /v1/config-templates/:id` — update template (200, 404, 409).
    - `DELETE /v1/config-templates/:id` — delete template (200, 404, 409).

- [Done] P2.4 - Create module: `pixel-control-server/src/config-template/config-template.module.ts`
  - Imports: `CommonModule`.
  - Controllers: `ConfigTemplateController`.
  - Providers: `ConfigTemplateService`.
  - Exports: `ConfigTemplateService` (needed by `ServerStateModule` in Phase 4).

- [Done] P2.5 - Register module in `pixel-control-server/src/app.module.ts`
  - Add `ConfigTemplateModule` to imports array.

### Phase 3 - Server-template association endpoints

Add endpoints to link/unlink a server to a template.

- [Done] P3.1 - Add association endpoints to the config-template controller (or a dedicated controller)
  - Option: add to `ConfigTemplateController` as sub-routes, OR add new endpoints on `ServerStateController`. Decision: add to `ConfigTemplateController` since they operate on the template→server relationship.
  - Endpoints:
    - `PUT /v1/servers/:serverLogin/config-template` — link server to template. Body: `{ template_id: string }`. Returns `{ linked: true, template_id, template_name }`. Throws 404 if server or template not found.
    - `DELETE /v1/servers/:serverLogin/config-template` — unlink server from template. Returns `{ unlinked: true }`. Throws 404 if server not found.
    - `GET /v1/servers/:serverLogin/config-template` — get linked template for server. Returns `{ template: ConfigTemplateResponse | null }`. Throws 404 if server not found.
  - These endpoints modify the `Server.configTemplateId` column.

- [Done] P3.2 - Add service methods for association
  - In `ConfigTemplateService` or a new `ServerConfigTemplateService`:
    - `linkServerToTemplate(serverLogin: string, templateId: string)`: resolve server via `ServerResolverService`, verify template exists, update `server.configTemplateId`. Return linked template info.
    - `unlinkServer(serverLogin: string)`: resolve server, set `configTemplateId` to null.
    - `getServerTemplate(serverLogin: string)`: resolve server, return linked template or null.

- [Done] P3.3 - Create a separate controller for server-template association: `pixel-control-server/src/config-template/server-config-template.controller.ts`
  - `@Controller('servers')` with `@ApiTags('Server Config Template')`.
  - This avoids routing conflicts with the existing `ServerStateController` which also uses `@Controller('servers')`.
  - Endpoints use paths like `:serverLogin/config-template` which don't conflict with `:serverLogin/state`.

### Phase 4 - ServerStateService template fallback + apply-template

Modify `GET /state` to fall back to template config, and add an apply endpoint.

- [Done] P4.1 - Modify `ServerStateService.getState()` to fall back to linked template
  - After finding no `ServerState` row, check if the server has a `configTemplateId`.
  - If yes, load the template's `config` and wrap it in a `ServerStateSnapshotDto`-like envelope:
    ```typescript
    {
      state_version: '1.0',
      captured_at: Math.floor(Date.now() / 1000),
      admin: template.config,  // AdminStateDto-compatible
      veto_draft: {
        session: null,
        matchmaking_ready_armed: false,
        votes: {},
      },
    }
    ```
  - Return it as the `state` field in the response.
  - Add `source: 'template' | 'saved' | 'default'` to `GetStateResponse`:
    - `'saved'` when a `ServerState` row exists.
    - `'template'` when falling back to template config.
    - `'default'` when neither state nor template exist (state is null).

- [Done] P4.2 - Update `GetStateResponse` interface
  - Add `source` field:
    ```typescript
    export interface GetStateResponse {
      state: Record<string, unknown> | null;
      updated_at: string | null;
      source: 'saved' | 'template' | 'default';
    }
    ```

- [Done] P4.3 - Add apply-template endpoint
  - `POST /v1/servers/:serverLogin/state/apply-template` — takes the linked template's config, builds a full `ServerStateSnapshotDto`, and saves it as the server's `ServerState` (like a manual `POST /state`).
  - No body required — uses the server's currently linked template.
  - Returns `{ applied: true, template_id, template_name, updated_at }`.
  - Throws 404 if server not found, 400 if server has no linked template.
  - This is useful when an operator wants to force-reset a server's state to the template defaults.

- [Done] P4.4 - Wire template resolution in `ServerStateService`
  - The service needs to query the `Server` with its `configTemplate` relation.
  - Modify the `resolve()` call or add an include for `configTemplate` when checking template fallback.
  - Import `ConfigTemplateService` or directly query Prisma with an include.

### Phase 5 - Server unit tests

- [Done] P5.1 - Create `pixel-control-server/src/config-template/config-template.controller.spec.ts`
  - Test CRUD: create returns 201, list returns array, get returns single, update works, delete works.
  - Test delete with linked servers returns 409.
  - Test 404 for nonexistent template.
  - Test name uniqueness constraint on create/update.
  - Target: ~12–15 tests.

- [Done] P5.2 - Create `pixel-control-server/src/config-template/server-config-template.controller.spec.ts`
  - Test link server to template (happy path).
  - Test unlink server.
  - Test get server template (linked and unlinked cases).
  - Test 404 for nonexistent server or template.
  - Target: ~8–10 tests.

- [Done] P5.3 - Update `pixel-control-server/src/server-state/server-state.controller.spec.ts`
  - Add tests for template fallback behavior:
    - `getState` returns template config with `source: 'template'` when no saved state and template is linked.
    - `getState` returns `source: 'saved'` when saved state exists (even if template is linked).
    - `getState` returns `source: 'default'` when neither state nor template exist.
  - Add tests for apply-template endpoint.
  - Target: ~6–8 new tests.

- [Done] P5.4 - Run full server test suite
  - `cd pixel-control-server && npm run test`
  - Confirm all existing tests still pass plus new tests.

### Phase 6 - UI: API client + template pages

- [Done] P6.1 - Create API client: `pixel-control-ui/src/api/configTemplates.ts`
  - `listConfigTemplates()` — `GET /config-templates`
  - `getConfigTemplate(id)` — `GET /config-templates/:id`
  - `createConfigTemplate(body)` — `POST /config-templates`
  - `updateConfigTemplate(id, body)` — `PUT /config-templates/:id`
  - `deleteConfigTemplate(id)` — `DELETE /config-templates/:id`
  - `getServerConfigTemplate(serverLogin)` — `GET /servers/:serverLogin/config-template`
  - `linkServerToTemplate(serverLogin, templateId)` — `PUT /servers/:serverLogin/config-template`
  - `unlinkServerFromTemplate(serverLogin)` — `DELETE /servers/:serverLogin/config-template`
  - `applyTemplateToServer(serverLogin)` — `POST /servers/:serverLogin/state/apply-template`

- [Done] P6.2 - Create template list page: `pixel-control-ui/src/pages/ConfigTemplateList.tsx`
  - Route: `/config-templates`
  - Displays all templates in a table (name, description, server count, created date).
  - "Create Template" button navigates to create/edit page.
  - Click row navigates to detail/edit page.
  - Delete button with `ConfirmModal` (shows error if 409).

- [Done] P6.3 - Create template detail/edit page: `pixel-control-ui/src/pages/ConfigTemplateDetail.tsx`
  - Route: `/config-templates/:id`
  - Form to edit template name, description, and all admin config fields.
  - Pre-fills with sensible defaults for new templates (best_of=3, scores=0, etc.).
  - "Save" button calls create or update API.
  - "Linked Servers" section showing which servers use this template.
  - "Apply to All Linked Servers" button with confirmation.

- [Done] P6.4 - Create template create page: `pixel-control-ui/src/pages/ConfigTemplateCreate.tsx`
  - Route: `/config-templates/new`
  - Same form as detail/edit but for creating a new template.
  - Alternatively, combine with `ConfigTemplateDetail.tsx` using a conditional (if `id === 'new'` → create mode, else → edit mode). Decision: use a single component with dual mode to reduce duplication.

- [Done] P6.5 - Register routes in `pixel-control-ui/src/App.tsx`
  - Add routes:
    ```tsx
    <Route path="config-templates" element={<ConfigTemplateList />} />
    <Route path="config-templates/new" element={<ConfigTemplateDetail />} />
    <Route path="config-templates/:id" element={<ConfigTemplateDetail />} />
    ```

- [Done] P6.6 - Add navigation entries in `pixel-control-ui/src/layouts/MainLayout.tsx`
  - Add new nav section "Configuration" (or add to existing "Admin" section):
    ```typescript
    { to: '/config-templates', label: 'Config Templates', icon: '📋' }
    ```

### Phase 7 - UI: server-template association

- [Done] P7.1 - Add template selector to `ServerDetail.tsx` page
  - Show current linked template (or "None").
  - Dropdown to select a template from the list.
  - "Link" / "Unlink" button.
  - "Apply Template" button (calls apply-template endpoint, with confirmation).

- [Done] P7.2 - Update `ServerList.tsx` to show template column
  - Add a "Template" column showing the linked template name (or "—").

- [Done] P7.3 - Verify UI build passes
  - `cd pixel-control-ui && npm run build`
  - Zero errors.

### Phase 8 - API contract update

- [Done] P8.1 - Add config template endpoints to `NEW_API_CONTRACT.md`
  - New section "4.6 Configuration Templates" with:
    - `POST /v1/config-templates` — create template.
    - `GET /v1/config-templates` — list all templates.
    - `GET /v1/config-templates/:id` — get single template.
    - `PUT /v1/config-templates/:id` — update template.
    - `DELETE /v1/config-templates/:id` — delete template (409 if servers linked).
    - `PUT /v1/servers/:serverLogin/config-template` — link server to template.
    - `DELETE /v1/servers/:serverLogin/config-template` — unlink server.
    - `GET /v1/servers/:serverLogin/config-template` — get linked template.
    - `POST /v1/servers/:serverLogin/state/apply-template` — apply template as server state.
  - Document the `source` field added to `GET /state` response.

- [Done] P8.2 - Update `GetStateResponse` documentation in state sync section
  - Add `source` field to the documented response shape.

### Phase 9 - Smoke test script

- [Done] P9.1 - Create `pixel-control-server/scripts/qa-config-templates-smoke.sh`
  - Prerequisites: server running on port 3000.
  - Test cases:
    1. `POST /config-templates` — create template → 201.
    2. `GET /config-templates` — list includes created template.
    3. `GET /config-templates/:id` — returns template with server_count=0.
    4. `PUT /config-templates/:id` — update name/config → 200.
    5. `GET /config-templates/:id` — reflects update.
    6. Register a test server.
    7. `PUT /servers/:serverLogin/config-template` — link server → 200.
    8. `GET /servers/:serverLogin/config-template` — returns linked template.
    9. `GET /servers/:serverLogin/state` — returns template config with `source=template` (no prior state saved).
    10. `POST /servers/:serverLogin/state/apply-template` — apply template → 200.
    11. `GET /servers/:serverLogin/state` — returns saved state with `source=saved`.
    12. `DELETE /servers/:serverLogin/config-template` — unlink → 200.
    13. `GET /config-templates/:id` — server_count=0 after unlink.
    14. `DELETE /config-templates/:id` — delete template → 200.
    15. `GET /config-templates/:id` — 404 after delete.
    16. Create template, link server, try `DELETE /config-templates/:id` → 409.
    17. `POST /config-templates` with duplicate name → 409.
    18. `GET /config-templates/nonexistent` → 404.
    19. `PUT /servers/:serverLogin/config-template` with nonexistent template → 404.
  - Follow existing smoke test patterns (counter-based assertions, colored output).
  - Target: ~30–40 assertions.

- [Done] P9.2 - Run smoke test and verify all assertions pass.

### Phase 10 - Regression testing

- [Done] P10.1 - Run ALL existing server smoke tests
  - `bash pixel-control-server/scripts/qa-state-sync-smoke.sh`
  - All other qa-p0 through qa-p5 smoke scripts.
  - Confirm zero regressions — especially state sync behavior for servers WITHOUT templates.

- [Done] P10.2 - Run full server unit test suite
  - `cd pixel-control-server && npm run test`
  - Confirm all tests pass (existing + new).

- [Done] P10.3 - Verify builds succeed
  - `cd pixel-control-server && npm run build` — zero errors.
  - `cd pixel-control-ui && npm run build` — zero errors.

- [Done] P10.4 - Run PHP plugin test suite (regression check)
  - `bash pixel-control-plugin/scripts/check-quality.sh`
  - Confirm no regressions (plugin is unchanged but verify lint/tests still pass).

---

## Evidence / Artifacts

- `pixel-control-server/src/config-template/` — new module directory (service, controller, DTOs, specs, module).
- `pixel-control-server/prisma/migrations/*add-config-templates/` — migration file.
- `pixel-control-server/scripts/qa-config-templates-smoke.sh` — new smoke test.
- `pixel-control-ui/src/api/configTemplates.ts` — new API client.
- `pixel-control-ui/src/pages/ConfigTemplateList.tsx` — new page.
- `pixel-control-ui/src/pages/ConfigTemplateDetail.tsx` — new page (create + edit).

## Success criteria

- Full CRUD for config templates works (create, read, list, update, delete) with proper validation and error handling.
- Server-template association (link/unlink/get) works correctly.
- `GET /v1/servers/:serverLogin/state` returns template config as fallback when no saved state exists, with `source: 'template'`.
- `GET /v1/servers/:serverLogin/state` returns `source: 'saved'` when saved state exists (template linked or not).
- `GET /v1/servers/:serverLogin/state` returns `source: 'default'` when neither state nor template exist.
- Apply-template endpoint correctly writes template config as server state.
- Delete template returns 409 when servers are still linked.
- UI pages allow full template management and server-template association.
- All existing smoke tests pass with zero regressions.
- All existing unit tests pass plus new tests.
- API contract is updated with all new endpoints.
- UI builds with zero errors.

## Notes / outcomes

**Execution completed 2026-03-06.**

### Artifacts created
- `pixel-control-server/prisma/migrations/20260306184518_add_config_templates/migration.sql` -- Prisma migration (config_templates table + FK on servers)
- `pixel-control-server/src/config-template/` -- new module directory:
  - `dto/config-template.dto.ts` -- DTOs (CreateConfigTemplateDto, UpdateConfigTemplateDto, LinkServerToTemplateDto, AdminConfigDto, response interfaces)
  - `config-template.service.ts` -- CRUD + server association + template resolution for state fallback
  - `config-template.controller.ts` -- CRUD endpoints (POST/GET/PUT/DELETE /config-templates)
  - `server-config-template.controller.ts` -- server association endpoints (PUT/DELETE/GET /servers/:serverLogin/config-template)
  - `config-template.module.ts` -- exports ConfigTemplateService
  - `config-template.controller.spec.ts` -- 16 unit tests
  - `server-config-template.controller.spec.ts` -- 10 unit tests
- `pixel-control-server/src/server-state/` -- modified:
  - `server-state.service.ts` -- template fallback in getState(), applyTemplate() method
  - `server-state.controller.ts` -- apply-template endpoint added
  - `server-state.module.ts` -- imports ConfigTemplateModule
  - `dto/server-state.dto.ts` -- GetStateResponse gains `source` field
  - `server-state.controller.spec.ts` -- 17 tests (4 new for template fallback + apply)
- `pixel-control-server/scripts/qa-config-templates-smoke.sh` -- 19 test cases, ~45 assertions
- `pixel-control-ui/src/api/configTemplates.ts` -- API client (9 functions)
- `pixel-control-ui/src/pages/ConfigTemplateList.tsx` -- template list page
- `pixel-control-ui/src/pages/ConfigTemplateDetail.tsx` -- create/edit dual-mode page
- `pixel-control-ui/src/pages/ServerDetail.tsx` -- updated with template association section

### Test counts
- Server unit tests: 436 passed (2 pre-existing failures, unchanged)
- PHP plugin tests: 123 passed, 41 files lint clean (no regressions)
- Server build: zero errors
- UI build: zero errors, 110 modules, 411KB JS

### New endpoints (9 total)
1. `POST /v1/config-templates` -- create template (201)
2. `GET /v1/config-templates` -- list all templates
3. `GET /v1/config-templates/:id` -- get single template
4. `PUT /v1/config-templates/:id` -- update template
5. `DELETE /v1/config-templates/:id` -- delete template (409 if servers linked)
6. `PUT /v1/servers/:serverLogin/config-template` -- link server to template
7. `DELETE /v1/servers/:serverLogin/config-template` -- unlink server
8. `GET /v1/servers/:serverLogin/config-template` -- get linked template
9. `POST /v1/servers/:serverLogin/state/apply-template` -- apply template as server state

### Modified endpoints
- `GET /v1/servers/:serverLogin/state` -- now returns `source` field (`saved`/`template`/`default`) and falls back to linked template config when no saved state exists
