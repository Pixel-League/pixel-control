# PLAN - Implement P4 Extended Control & P5 Backlog Endpoints (2026-03-06)

## Context

- **Purpose:** Complete the remaining inbound command tiers of the Pixel Control API. P4 ("Extended control") adds veto/draft flow, player force-team/play/spec, and team policy/roster management. P5 ("Low priority backlog") adds auth grant/revoke, whitelist management, and vote policy management. Together these 27 new endpoints bring the API to full feature parity with the plugin's admin control surface defined in `NEW_API_CONTRACT.md`.

- **Scope:**
  - **P4 branch** (`feat/p4-extended-control` from `feat/p3-admin-commands`): 13 new endpoints
    - Veto/Draft flow (P4.1--P4.5): 5 endpoints using a **different socket method family** (`PixelControl.VetoDraft.*`) than the P3 `PixelControl.Admin.ExecuteAction` pattern.
    - Player management (P4.6--P4.8): 3 endpoints via `PixelControl.Admin.ExecuteAction` (extends existing AdminCommandTrait).
    - Team control (P4.9--P4.13): 5 endpoints via `PixelControl.Admin.ExecuteAction` (extends existing AdminCommandTrait).
  - **P5 branch** (`feat/p5-backlog` from `feat/p4-extended-control`): 14 new endpoints
    - Auth management (P5.1--P5.2): 2 endpoints via `PixelControl.Admin.ExecuteAction`.
    - Whitelist management (P5.3--P5.9): 7 endpoints via `PixelControl.Admin.ExecuteAction`.
    - Vote management (P5.10--P5.14): 5 endpoints via `PixelControl.Admin.ExecuteAction`.
  - **Plugin**: new `VetoDraftCommandTrait` for P4.1--P4.5; extend `AdminCommandTrait` action map with 19 new actions (8 for P4, 14 for P5, minus 3 shared helpers already present).
  - **Dev UI** (`pixel-control-ui/`): 6 new pages (VetoDraft, PlayerManagement, TeamControl, AuthManagement, WhitelistManagement, VoteManagement) plus API client functions, route entries, and 2 reusable veto components (`VetoMapCard`, `VetoTimeline`). The VetoDraft page features a **FACEIT-style interactive visual veto interface** with a map card grid, turn-by-turn ban/pick flow, team color coding, and a step timeline.

  Out of scope: API endpoint authentication (remains open/unauthenticated per ROADMAP), multi-server socket routing, plugin telemetry changes, CI/CD setup.

- **Goals:**
  - All 27 new endpoints implemented, unit-tested, and documented with Swagger/OpenAPI annotations.
  - VetoDraft subsystem re-added to the plugin as a focused communication-listener-only trait (`VetoDraftCommandTrait`) -- not a restoration of the full VetoDraft game logic removed during Elite-only refactor.
  - `AdminCommandTrait` extended with 19 new actions without modifying existing P3 handler methods.
  - A new `VetoDraftProxyModule` on the server provides socket proxy infrastructure for the `PixelControl.VetoDraft.*` method family, parallel to the existing `AdminProxyModule`.
  - QA smoke test scripts validate all new endpoints via curl (one per branch).
  - Dev UI covers all 27 new endpoints with interactive pages following the existing dark theme and component patterns.
  - All existing tests (326 unit tests, 50 PHP tests, all smoke scripts) remain green. No regressions.
  - `NEW_API_CONTRACT.md` and plugin `README.md` updated with status markers.

- **Non-goals:**
  - Full VetoDraft game logic restoration (matchmaking lifecycle, autostart, ready-gate player tracking). Only the communication listener that proxies commands to the existing ManiaControl managers is re-added.
  - Auth enforcement on API endpoints themselves.
  - Multi-server socket configuration (all sockets share `MC_SOCKET_*` env vars).
  - New plugin telemetry events for P4/P5 actions.

- **Constraints / assumptions:**
  - Same stack: NestJS v11 + Fastify + Prisma ORM + PostgreSQL. Vitest + @swc/core for tests. Static imports only.
  - VetoDraft uses **different socket methods** (`PixelControl.VetoDraft.Status`, `.Ready`, `.Start`, `.Action`, `.Cancel`) -- NOT `PixelControl.Admin.ExecuteAction`. The server needs a new proxy module that sends these method names instead of `ExecuteAction`.
  - VetoDraft was removed from the plugin during `PLAN-ELITE-ENRICHMENT` / `PLAN-SM-SERVER-ELITE-ONLY`. The communication listener must be re-added as a new trait, wired in `CoreDomainTrait.load()`.
  - Player management and team control actions (P4.6--P4.13) use the standard `PixelControl.Admin.ExecuteAction` pattern (same as P3), so they follow the `AdminCommandTrait` + `AdminProxyModule` architecture.
  - All P5 actions also use `PixelControl.Admin.ExecuteAction`.
  - Plugin parameter helpers (`requireStringParam`, `requirePositiveIntParam`, `requireNonNegativeIntParam`, `requireBoolParam`) are already in `AdminCommandTrait` (except `requireBoolParam` which must be added).
  - ManiaControl socket protocol unchanged: AES-192-CBC, IV = `kZ2Kt0CzKUjN2MJX`, same env vars.

- **Environment snapshot:**
  - Branch base: `feat/p3-admin-commands` (commit `abf49b1`)
  - Server: 326 unit tests across 28 spec files, all green
  - Plugin: 50 PHP tests across 4 spec files, 37 PHP source files pass lint
  - Smoke tests: qa-p0, qa-p1, qa-p2, qa-p2.5, qa-p2.6, qa-elite-enrichment, qa-p3 -- all passing
  - UI: 31 page components, 12 API client modules, zero build errors

- **Dependencies / stakeholders:** None external.

- **Risks / open questions:**
  - **R1 -- VetoDraft ManiaControl managers**: The VetoDraft listener needs to call ManiaControl's internal managers (MapManager for map pool, PlayerManager for force-team, etc.). These managers exist in the ManiaControl framework but the plugin's VetoDraft subsystem was fully removed. The new `VetoDraftCommandTrait` must use the ManiaControl API surface directly, not the removed custom subsystem.
  - **R2 -- VetoDraft state management**: The `PixelControl.VetoDraft.Start` and `.Action` methods require session state (active session, current step, votes). Since the full VetoDraft subsystem was removed, the new trait must re-implement minimal session state tracking or delegate to ManiaControl's built-in managers if available. Decision: re-implement minimal state tracking within the trait (session object, steps, votes).
  - **R3 -- `requireBoolParam` helper**: P4.9 (`team.policy.set`) needs a boolean parameter. The current `AdminCommandTrait` has no bool param helper. Must add one.
  - **R4 -- Route parameter collision**: P4.6--P4.8 use `/players/:login/force-team` etc. The existing `PlayersReadModule` serves `GET /servers/:serverLogin/players` and `GET .../players/:login`. The new POST routes on the same path segment must not conflict. NestJS handles this via HTTP method differentiation -- no issue expected.
  - **R5 -- Whitelist `DELETE /whitelist` vs `DELETE /whitelist/:login`**: P5.6 removes a single player, P5.8 clears the entire whitelist. Both are DELETE on overlapping paths. NestJS route ordering matters -- the parameterized route (`:login`) must be registered before the bare path, or use explicit path ordering in the controller.

---

## Architecture Decisions

### D1: New `VetoDraftProxyModule` for VetoDraft socket methods

Create `src/veto-draft-proxy/` with:
- **`VetoDraftProxyService`** -- similar to `AdminProxyService` but sends `PixelControl.VetoDraft.*` method names instead of `PixelControl.Admin.ExecuteAction`. Reuses the existing `ManiaControlSocketClient` for TCP/encryption. Resolves server from DB, injects link-auth where needed.
- **`VetoDraftProxyModule`** -- imports `CommonModule` + `AdminProxyModule` (to reuse `ManiaControlSocketClient`), exports `VetoDraftProxyService`.

Key difference from `AdminProxyService`: each VetoDraft endpoint maps to a distinct socket method name (e.g., `PixelControl.VetoDraft.Status`), not a single method with an `action` field.

### D2: VetoDraft response shape differs from AdminAction

VetoDraft socket responses use `{ success, code, message }` (no `action_name`). The `VetoDraftProxyService` returns a `VetoDraftResponse` interface (not `AdminActionResponse`). For the Status endpoint (P4.1), the response is a rich object with `status`, `session`, `series_targets`, etc.

### D3: Extend AdminCommandTrait for P4.6--P4.13 and all P5 actions

Add new handler methods to `AdminCommandTrait` and extend the `$actionMap` array. This follows the OCP principle -- adding new entries to the dispatch map without modifying existing handlers. New actions:

**P4 (8 new actions):**
- `player.force_team`, `player.force_play`, `player.force_spec`
- `team.policy.get`, `team.policy.set`, `team.roster.assign`, `team.roster.unassign`, `team.roster.list`

**P5 (14 new actions):**
- `auth.grant`, `auth.revoke`
- `whitelist.enable`, `whitelist.disable`, `whitelist.add`, `whitelist.remove`, `whitelist.list`, `whitelist.clean`, `whitelist.sync`
- `vote.cancel`, `vote.set_ratio`, `vote.custom_start`, `vote.policy.get`, `vote.policy.set`

### D4: New VetoDraftCommandTrait for plugin

Create `src/Domain/VetoDraft/VetoDraftCommandTrait.php` that:
- Registers 5 communication listeners (`PixelControl.VetoDraft.Status`, `.Ready`, `.Start`, `.Action`, `.Cancel`).
- Maintains minimal internal session state (active flag, mode, steps, votes, captains).
- Uses ManiaControl's `MapManager` for map pool queries and `PlayerManager` for player lookups.
- Validates link-auth on Start/Action/Cancel (Status and Ready do not require auth per contract).
- Returns contract-compliant response shapes.

Wire in `CoreDomainTrait.load()` via `$this->registerVetoDraftCommandListener()`.

### D5: Server module organization for P4 and P5

**P4 modules:**
- `src/veto-draft/` -- `VetoDraftModule` (P4.1--P4.5), imports `VetoDraftProxyModule`
- `src/admin-players/` -- `AdminPlayersModule` (P4.6--P4.8), imports `AdminProxyModule`
- `src/admin-teams/` -- `AdminTeamsModule` (P4.9--P4.13), imports `AdminProxyModule`

**P5 modules:**
- `src/admin-auth/` -- `AdminAuthModule` (P5.1--P5.2), imports `AdminProxyModule`
- `src/admin-whitelist/` -- `AdminWhitelistModule` (P5.3--P5.9), imports `AdminProxyModule`
- `src/admin-votes/` -- `AdminVotesModule` (P5.10--P5.14), imports `AdminProxyModule`

### D6: New DTOs for P4/P5 request bodies

Add to `src/admin-proxy/dto/admin-action.dto.ts` (for P4 admin actions) and create `src/veto-draft-proxy/dto/veto-draft.dto.ts` (for VetoDraft bodies). Also create domain-specific DTOs in each module directory where cleaner separation is warranted.

### D7: UI page organization

6 new pages following existing patterns (`AdminMapControl.tsx` as model):
- `AdminVetoDraft.tsx` -- route `/admin/veto`
- `AdminPlayerManagement.tsx` -- route `/admin/players`
- `AdminTeamControl.tsx` -- route `/admin/teams`
- `AdminAuthManagement.tsx` -- route `/admin/auth`
- `AdminWhitelistManagement.tsx` -- route `/admin/whitelist`
- `AdminVoteManagement.tsx` -- route `/admin/votes`

API client: extend `src/api/admin.ts` with new functions; create `src/api/veto.ts` for VetoDraft.

---

## Steps

- [Done] Phase 0 -- P3 QA Gate (prerequisite)
- [Done] Phase 1 -- Plugin: VetoDraftCommandTrait (P4.1--P4.5)
- [Done] Phase 2 -- Plugin: Extend AdminCommandTrait (P4.6--P4.13)
- [Done] Phase 3 -- Plugin: PHP lint + tests for P4
- [Done] Phase 4 -- Server: VetoDraftProxyModule + VetoDraftModule (P4.1--P4.5)
- [Done] Phase 5 -- Server: AdminPlayersModule (P4.6--P4.8)
- [Done] Phase 6 -- Server: AdminTeamsModule (P4.9--P4.13)
- [Done] Phase 7 -- Server: Unit tests for all P4 modules
- [Done] Phase 8 -- Server: P4 smoke test script
- [Done] Phase 9 -- UI: P4 pages (Veto, Players, Teams)
- [Done] Phase 10 -- UI: Build verification + Chrome testing (P4)
- [Done] Phase 11 -- P4 full regression
- [Done] Phase 12 -- P4 documentation update
- [Todo] Phase 13 -- P4 commit
- [Done] Phase 14 -- Plugin: Extend AdminCommandTrait (P5.1--P5.14)
- [Done] Phase 15 -- Plugin: PHP lint + tests for P5
- [Done] Phase 16 -- Server: AdminAuthModule (P5.1--P5.2)
- [Done] Phase 17 -- Server: AdminWhitelistModule (P5.3--P5.9)
- [Done] Phase 18 -- Server: AdminVotesModule (P5.10--P5.14)
- [Done] Phase 19 -- Server: Unit tests for all P5 modules
- [Done] Phase 20 -- Server: P5 smoke test script
- [Done] Phase 21 -- UI: P5 pages (Auth, Whitelist, Votes)
- [Done] Phase 22 -- UI: Build verification + Chrome testing (P5)
- [Done] Phase 23 -- P5 full regression
- [Done] Phase 24 -- P5 documentation update
- [Todo] Phase 25 -- P5 commit

---

### Phase 0 -- P3 QA Gate (prerequisite)

Verify the baseline is clean before starting any new work.

- [Done] P0.1 -- Run all 326 server unit tests (`cd pixel-control-server && npm run test`)
  - Acceptance: 326 tests pass, zero failures.
- [Done] P0.2 -- Run P3 smoke test (`bash pixel-control-server/scripts/qa-p3-admin-commands-smoke.sh`)
  - Acceptance: 78/78 assertions pass (fixed POST→PUT bug in setup; 28 core assertion groups all green).
- [Done] P0.3 -- Run ALL regression smoke scripts (qa-p0 through qa-elite-enrichment)
  - Acceptance: all scripts exit 0 with no failures (43+35+94+59+29+21+53 = 334 assertions, all green).
- [Done] P0.4 -- Build pixel-control-ui (`cd pixel-control-ui && npm run build`)
  - Acceptance: zero TypeScript/build errors (98 modules, 351KB JS).
- [Todo] P0.5 -- Start pixel-control-ui dev server, open Chrome, navigate to admin pages (`/admin/maps`, `/admin/warmup-pause`, `/admin/match`), confirm pages render and buttons are interactive
  - Acceptance: all 3 admin pages render correctly. No console errors.
- [Done] P0.6 -- Create branch `feat/p4-extended-control` from `feat/p3-admin-commands`
  - Acceptance: clean branch created.

---

### Phase 1 -- Plugin: VetoDraftCommandTrait (P4.1--P4.5)

Create a new trait at `pixel-control-plugin/src/Domain/VetoDraft/VetoDraftCommandTrait.php`.

- [Done] P1.1 -- Create `VetoDraftCommandTrait` with 5 communication listeners
  - Register `PixelControl.VetoDraft.Status`, `.Ready`, `.Start`, `.Action`, `.Cancel` via `CommunicationManager`.
  - Provide `registerVetoDraftCommandListener()` method (called from `CoreDomainTrait.load()`).
  - File: `pixel-control-plugin/src/Domain/VetoDraft/VetoDraftCommandTrait.php`
  - Estimated: 1 new file, ~400 lines.

- [Done] P1.2 -- Implement `handleVetoDraftStatus()` handler (P4.1)
  - Returns current session state: `active`, `mode`, `session` details, `series_targets`, `communication` method names.
  - When no session is active, returns `{ status: { active: false, mode: null, session: { status: 'idle' } } }`.
  - No link-auth required (per contract).

- [Done] P1.3 -- Implement `handleVetoDraftReady()` handler (P4.2)
  - Arms the matchmaking ready gate. Sets internal `$matchmakingReadyArmed = true`.
  - Returns `{ success, code, message }` with codes `matchmaking_ready_armed` or `matchmaking_ready_already_armed`.
  - No link-auth required.

- [Done] P1.4 -- Implement `handleVetoDraftStart()` handler (P4.3)
  - Validates `mode` parameter (`matchmaking_vote` or `tournament_draft`).
  - For matchmaking: validates ready gate is armed, creates session, starts vote timer.
  - For tournament: validates `captain_a` and `captain_b` (non-empty, distinct), creates draft session.
  - Validates map pool size is sufficient for the mode/best_of.
  - Returns `{ success, code, message }` with appropriate codes.
  - Link-auth: NOT required at communication level (the API injects it, but the plugin VetoDraft listener does not enforce link-auth on Start -- it was originally admin-chat-only. Decision: validate link-auth on Start for consistency with the API proxy model).

- [Done] P1.5 -- Implement `handleVetoDraftAction()` handler (P4.4)
  - Validates active session exists. Validates `actor_login` and `map` parameters.
  - For matchmaking: records vote for the actor. Supports `allow_override`.
  - For tournament: validates actor is current team's captain, applies ban/pick action.
  - Returns `{ success, code, message }`.

- [Done] P1.6 -- Implement `handleVetoDraftCancel()` handler (P4.5)
  - Validates active session exists. Cancels session, resets state.
  - Returns `{ success, code, message }` with `session_cancelled` or `session_not_running`.

- [Done] P1.7 -- Implement minimal VetoDraft session state management
  - Internal properties: `$vetoDraftSession` (object/null), `$matchmakingReadyArmed` (bool), `$vetoDraftVotes` (array).
  - Session object: `{ status, mode, steps, current_step, captains, map_pool, votes, started_at }`.
  - Session statuses: `idle`, `running`, `completed`, `cancelled`.
  - This is a minimal re-implementation -- not the full lifecycle system that was removed.

- [Done] P1.8 -- Wire trait into `PixelControlPlugin`
  - Add `use VetoDraftCommandTrait;` to `PixelControlPlugin.php`.
  - Add `use PixelControl\Domain\VetoDraft\VetoDraftCommandTrait;` import.
  - Add `$this->registerVetoDraftCommandListener();` call in `CoreDomainTrait.load()` (after `registerAdminCommandListener()`).
  - Files modified: `PixelControlPlugin.php` (2 lines), `CoreDomainTrait.php` (1 line).

---

### Phase 2 -- Plugin: Extend AdminCommandTrait (P4.6--P4.13)

Extend the existing `AdminCommandTrait` with 8 new actions for player management and team control.

- [Done] P2.1 -- Add `requireBoolParam()` helper to `AdminCommandTrait`
  - Extracts a boolean parameter by key from the `$parameters` object/array.
  - Handles `true`/`false`, `1`/`0`, `"true"`/`"false"` string variants.
  - Returns `bool|null`.
  - File: `pixel-control-plugin/src/Domain/Admin/AdminCommandTrait.php`.

- [Done] P2.2 -- Implement player management handlers (P4.6--P4.8)
  - `handlePlayerForceTeam($parameters)` -- action `player.force_team`
    - Requires `target_login` (string) and `team` (string: `team_a`/`team_b`/`0`/`1`/`red`/`blue`/`a`/`b`).
    - Normalizes team value to `team_a` or `team_b`.
    - Uses `$this->maniaControl->getPlayerManager()->getPlayer($targetLogin)` to find player.
    - Uses `$this->maniaControl->getClient()->forceSpectator($targetLogin, 0)` then `forcePlayerTeam($targetLogin, $teamInt)`.
    - Returns `{ action_name, success, code: 'player_team_forced', message, details: { target_login, team } }`.
    - Error codes: `invalid_parameter` (missing login/team), `invalid_team` (bad team value), `player_not_found`.
  - `handlePlayerForcePlay($parameters)` -- action `player.force_play`
    - Requires `target_login` (string).
    - Uses `$this->maniaControl->getClient()->forceSpectator($targetLogin, 0)`.
    - Returns `{ action_name, success, code: 'player_forced_play', message, details: { target_login } }`.
  - `handlePlayerForceSpec($parameters)` -- action `player.force_spec`
    - Requires `target_login` (string).
    - Uses `$this->maniaControl->getClient()->forceSpectator($targetLogin, 1)`.
    - Returns `{ action_name, success, code: 'player_forced_spec', message, details: { target_login } }`.
  - Add all 3 to `$actionMap` in `handleAdminExecuteAction()`.
  - Estimated: ~90 lines added.

- [Done] P2.3 -- Add internal team control state to `AdminCommandTrait`
  - Properties: `$teamPolicyEnabled` (bool, default false), `$teamSwitchLock` (bool, default false), `$teamRoster` (array, default empty).
  - These are minimal in-memory state for the P4 team actions.

- [Done] P2.4 -- Implement team control handlers (P4.9--P4.13)
  - `handleTeamPolicySet($parameters)` -- action `team.policy.set`
    - Requires `enabled` (bool). Optional `switch_lock` (bool).
    - Sets `$this->teamPolicyEnabled` and `$this->teamSwitchLock`.
    - Returns `{ action_name, success, code: 'team_policy_set', message, details: { enabled, switch_lock } }`.
  - `handleTeamPolicyGet($parameters)` -- action `team.policy.get`
    - Returns current policy state.
    - Returns `{ action_name, success, code: 'team_policy_retrieved', message, details: { enabled, switch_lock } }`.
  - `handleTeamRosterAssign($parameters)` -- action `team.roster.assign`
    - Requires `target_login` (string) and `team` (string, normalized).
    - Adds/updates entry in `$this->teamRoster[$targetLogin] = $team`.
    - Returns `{ action_name, success, code: 'team_roster_assigned', message, details: { target_login, team, roster } }`.
  - `handleTeamRosterUnassign($parameters)` -- action `team.roster.unassign`
    - Requires `target_login` (string).
    - Removes entry from `$this->teamRoster`. Returns error if not in roster.
    - Returns `{ action_name, success, code: 'team_roster_unassigned', message, details: { target_login, roster } }`.
  - `handleTeamRosterList($parameters)` -- action `team.roster.list`
    - Returns current roster.
    - Returns `{ action_name, success, code: 'team_roster_retrieved', message, details: { roster, count } }`.
  - Add all 5 to `$actionMap`.
  - Estimated: ~150 lines added.

---

### Phase 3 -- Plugin: PHP lint + tests for P4

- [Done] P3.1 -- Run PHP lint on all plugin source files
  - Command: `bash pixel-control-plugin/scripts/check-quality.sh`
  - Acceptance: zero syntax errors across all `.php` files.

- [Done] P3.2 -- Create `55VetoDraftCommandTest.php` in `pixel-control-plugin/tests/cases/`
  - Test harness class similar to `AdminCommandTestHarness` (from `50AdminCommandTest.php`) but composing `VetoDraftCommandTrait`.
  - Test cases (~15):
    - Status with no active session returns idle.
    - Ready arms the gate, double-ready returns already_armed.
    - Start matchmaking without ready returns `matchmaking_ready_required`.
    - Start matchmaking with ready succeeds.
    - Start tournament with valid captains succeeds.
    - Start tournament with same captain A and B returns `captain_conflict`.
    - Start when session already active returns `session_active`.
    - Action vote on active matchmaking succeeds.
    - Action on inactive session returns `session_not_running`.
    - Cancel active session succeeds.
    - Cancel inactive session returns `session_not_running`.
    - Status with active session returns running state.
  - File: `pixel-control-plugin/tests/cases/55VetoDraftCommandTest.php`
  - Estimated: 1 new file, ~200 lines.

- [Done] P3.3 -- Extend `50AdminCommandTest.php` with P4 admin action tests
  - New test cases (~12):
    - `player.force_team` with valid params succeeds.
    - `player.force_team` with missing target_login returns `invalid_parameter`.
    - `player.force_team` with invalid team returns `invalid_team`.
    - `player.force_play` with valid params succeeds.
    - `player.force_spec` with valid params succeeds.
    - `team.policy.set` with enabled=true succeeds.
    - `team.policy.get` returns current policy.
    - `team.roster.assign` with valid params succeeds.
    - `team.roster.assign` with invalid team returns `invalid_team`.
    - `team.roster.unassign` with valid login succeeds.
    - `team.roster.unassign` with unknown login returns error.
    - `team.roster.list` returns roster array.
  - File: `pixel-control-plugin/tests/cases/50AdminCommandTest.php` (extend).

- [Done] P3.4 -- Run full plugin test suite
  - Command: `php pixel-control-plugin/tests/run.php`
  - Acceptance: 88 tests pass (29 elite + 16 P3 admin + 16 P4 admin new + 22 VetoDraft new), 0 failures, 0 regressions.

---

### Phase 4 -- Server: VetoDraftProxyModule + VetoDraftModule (P4.1--P4.5)

- [Done] P4.1 -- Create `VetoDraftProxyService` and `VetoDraftProxyModule`
  - `src/veto-draft-proxy/veto-draft-proxy.service.ts` -- high-level proxy service.
    - Injects `ManiaControlSocketClient` (from `AdminProxyModule`), `ServerResolverService`, `ConfigService`.
    - Methods:
      - `sendVetoDraftCommand(serverLogin, methodSuffix, data?)` -- builds `PixelControl.VetoDraft.<methodSuffix>` method name, resolves server, injects link-auth, sends via socket client, maps errors.
      - `queryVetoDraftStatus(serverLogin)` -- sends `Status` method, returns rich response.
    - Error mapping: same pattern as `AdminProxyService` (AUTH_ERROR_CODES -> 403, socket errors -> 502).
  - `src/veto-draft-proxy/veto-draft-proxy.module.ts` -- imports `CommonModule` + `AdminProxyModule`, exports `VetoDraftProxyService`.
  - `src/veto-draft-proxy/dto/veto-draft.dto.ts` -- request DTOs and response interfaces:
    - `VetoDraftStatusResponse` (rich object with `status`, `session`, `communication`, `series_targets`)
    - `VetoDraftCommandResponse` (`{ success, code, message }`)
    - `VetoDraftStartDto` (`mode`, optional `duration_seconds`, `captain_a`, `captain_b`, `best_of`, `starter`, `action_timeout_seconds`, `launch_immediately`)
    - `VetoDraftActionDto` (`actor_login`, optional `operation`, `map`, `selection`, `allow_override`, `force`)
    - `VetoDraftCancelDto` (optional `reason`)
  - Estimated: 3 new files, ~250 lines total.

- [Done] P4.2 -- Create `VetoDraftModule` with controller and service
  - `src/veto-draft/veto-draft.service.ts`:
    - `getStatus(serverLogin)` -> `vetoDraftProxy.sendVetoDraftCommand(serverLogin, 'Status')`
    - `armReady(serverLogin)` -> `vetoDraftProxy.sendVetoDraftCommand(serverLogin, 'Ready')`
    - `startSession(serverLogin, dto)` -> `vetoDraftProxy.sendVetoDraftCommand(serverLogin, 'Start', dto)`
    - `submitAction(serverLogin, dto)` -> `vetoDraftProxy.sendVetoDraftCommand(serverLogin, 'Action', dto)`
    - `cancelSession(serverLogin, dto)` -> `vetoDraftProxy.sendVetoDraftCommand(serverLogin, 'Cancel', dto)`
  - `src/veto-draft/veto-draft.controller.ts`:
    - `GET :serverLogin/veto/status` (P4.1)
    - `POST :serverLogin/veto/ready` (P4.2)
    - `POST :serverLogin/veto/start` (P4.3) -- body: `VetoDraftStartDto`
    - `POST :serverLogin/veto/action` (P4.4) -- body: `VetoDraftActionDto`
    - `POST :serverLogin/veto/cancel` (P4.5) -- body: `VetoDraftCancelDto`
    - All with Swagger annotations and `@HttpCode(200)`.
  - `src/veto-draft/veto-draft.module.ts` -- imports `VetoDraftProxyModule`.
  - Estimated: 3 new files, ~200 lines total.

- [Done] P4.3 -- Register `VetoDraftModule` and `VetoDraftProxyModule` in `AppModule`
  - Add imports to `src/app.module.ts`.
  - File modified: 1 file, ~4 lines added.

---

### Phase 5 -- Server: AdminPlayersModule (P4.6--P4.8)

- [Done] P5.1 -- Create new DTOs for player management
  - `ForceTeamDto` (`team: string`, validated as non-empty).
  - Add to `src/admin-proxy/dto/admin-action.dto.ts` or create `src/admin-players/dto/`.
  - Estimated: ~15 lines.

- [Done] P5.2 -- Create `AdminPlayersModule` with controller and service
  - `src/admin-players/admin-players.service.ts`:
    - `forceTeam(serverLogin, playerLogin, team)` -> `adminProxy.executeAction(serverLogin, 'player.force_team', { target_login: playerLogin, team })`
    - `forcePlay(serverLogin, playerLogin)` -> `adminProxy.executeAction(serverLogin, 'player.force_play', { target_login: playerLogin })`
    - `forceSpec(serverLogin, playerLogin)` -> `adminProxy.executeAction(serverLogin, 'player.force_spec', { target_login: playerLogin })`
  - `src/admin-players/admin-players.controller.ts`:
    - `POST :serverLogin/players/:login/force-team` (P4.6) -- body: `ForceTeamDto`
    - `POST :serverLogin/players/:login/force-play` (P4.7) -- no body
    - `POST :serverLogin/players/:login/force-spec` (P4.8) -- no body
    - Swagger annotations. `@HttpCode(200)`.
  - `src/admin-players/admin-players.module.ts` -- imports `AdminProxyModule`.
  - Estimated: 3 new files, ~130 lines total.

- [Done] P5.3 -- Register `AdminPlayersModule` in `AppModule`
  - File modified: `src/app.module.ts`, ~2 lines.

---

### Phase 6 -- Server: AdminTeamsModule (P4.9--P4.13)

- [Done] P6.1 -- Create new DTOs for team control
  - `TeamPolicyDto` (`enabled: boolean`, optional `switch_lock: boolean`).
  - `TeamRosterAssignDto` (`target_login: string`, `team: string`).
  - Add to `src/admin-proxy/dto/admin-action.dto.ts` or create `src/admin-teams/dto/`.
  - Estimated: ~25 lines.

- [Done] P6.2 -- Create `AdminTeamsModule` with controller and service
  - `src/admin-teams/admin-teams.service.ts`:
    - `setPolicy(serverLogin, dto)` -> `adminProxy.executeAction(serverLogin, 'team.policy.set', { enabled, switch_lock })`
    - `getPolicy(serverLogin)` -> `adminProxy.queryAction(serverLogin, 'team.policy.get')`
    - `assignRoster(serverLogin, dto)` -> `adminProxy.executeAction(serverLogin, 'team.roster.assign', { target_login, team })`
    - `unassignRoster(serverLogin, playerLogin)` -> `adminProxy.executeAction(serverLogin, 'team.roster.unassign', { target_login: playerLogin })`
    - `listRoster(serverLogin)` -> `adminProxy.queryAction(serverLogin, 'team.roster.list')`
  - `src/admin-teams/admin-teams.controller.ts`:
    - `PUT :serverLogin/teams/policy` (P4.9) -- body: `TeamPolicyDto`
    - `GET :serverLogin/teams/policy` (P4.10)
    - `POST :serverLogin/teams/roster` (P4.11) -- body: `TeamRosterAssignDto`
    - `DELETE :serverLogin/teams/roster/:login` (P4.12)
    - `GET :serverLogin/teams/roster` (P4.13)
    - Swagger annotations. Write endpoints `@HttpCode(200)`.
  - `src/admin-teams/admin-teams.module.ts` -- imports `AdminProxyModule`.
  - Estimated: 3 new files, ~200 lines total.

- [Done] P6.3 -- Register `AdminTeamsModule` in `AppModule`
  - File modified: `src/app.module.ts`, ~2 lines.

---

### Phase 7 -- Server: Unit tests for all P4 modules

- [Done] P7.1 -- Unit tests for `VetoDraftProxyService` (~10 tests)
  - Tests: successful command send, server not found (404), socket error (502), auth error mapping (403), method name construction.
  - File: `src/veto-draft-proxy/veto-draft-proxy.service.spec.ts`

- [Done] P7.2 -- Unit tests for `VetoDraftController` (~8 tests)
  - Tests: all 5 endpoints route correctly, DTOs validated, response shapes correct.
  - File: `src/veto-draft/veto-draft.controller.spec.ts`

- [Done] P7.3 -- Unit tests for `AdminPlayersController` (~6 tests)
  - Tests: all 3 endpoints route correctly, login param extracted, ForceTeamDto validated.
  - File: `src/admin-players/admin-players.controller.spec.ts`

- [Done] P7.4 -- Unit tests for `AdminTeamsController` (~8 tests)
  - Tests: all 5 endpoints route correctly, DTOs validated, response shapes correct.
  - File: `src/admin-teams/admin-teams.controller.spec.ts`

- [Done] P7.5 -- Run full server test suite
  - Command: `cd pixel-control-server && npm run test`
  - Acceptance: all tests pass (previous 326 + ~32 new = ~358 total). Zero failures.

---

### Phase 8 -- Server: P4 smoke test script

- [Done] P8.1 -- Create `scripts/qa-p4-extended-control-smoke.sh`
  - Pattern: same as `qa-p3-admin-commands-smoke.sh` (register test server, run curl assertions, cleanup).
  - Test cases (~20):
    - P4.1: GET veto/status -- expect 502 (no socket) or 200 (live).
    - P4.2: POST veto/ready -- expect 502 or 200.
    - P4.3: POST veto/start with valid body -- expect 502 or 200.
    - P4.3: POST veto/start with invalid mode -- expect 400.
    - P4.4: POST veto/action with valid body -- expect 502 or 200.
    - P4.5: POST veto/cancel -- expect 502 or 200.
    - P4.6: POST players/:login/force-team with valid body -- expect 502 or 200.
    - P4.6: POST players/:login/force-team without body -- expect 400.
    - P4.7: POST players/:login/force-play -- expect 502 or 200.
    - P4.8: POST players/:login/force-spec -- expect 502 or 200.
    - P4.9: PUT teams/policy with valid body -- expect 502 or 200.
    - P4.9: PUT teams/policy without body -- expect 400.
    - P4.10: GET teams/policy -- expect 502 or 200.
    - P4.11: POST teams/roster with valid body -- expect 502 or 200.
    - P4.11: POST teams/roster without body -- expect 400.
    - P4.12: DELETE teams/roster/:login -- expect 502 or 200.
    - P4.13: GET teams/roster -- expect 502 or 200.
    - Unknown server for any P4 endpoint -- expect 404.
  - Supports `--live` flag for live-socket mode.
  - File: `pixel-control-server/scripts/qa-p4-extended-control-smoke.sh`
  - Estimated: 1 new file, ~350 lines.

- [Done] P8.2 -- Run P4 smoke test
  - Acceptance: all assertions pass in no-socket mode.

---

### Phase 9 -- UI: P4 pages (Veto, Players, Teams)

- [Done] P9.1 -- Create `src/api/veto.ts` with VetoDraft API client functions
  - `getVetoStatus(serverLogin)` -- GET
  - `armVetoReady(serverLogin)` -- POST
  - `startVetoSession(serverLogin, body)` -- POST
  - `submitVetoAction(serverLogin, body)` -- POST
  - `cancelVetoSession(serverLogin, body?)` -- POST
  - Estimated: 1 new file, ~40 lines.

- [Done] P9.2 -- Extend `src/api/admin.ts` with P4 admin functions
  - `forcePlayerTeam(serverLogin, login, team)` -- POST
  - `forcePlayerPlay(serverLogin, login)` -- POST
  - `forcePlayerSpec(serverLogin, login)` -- POST
  - `setTeamPolicy(serverLogin, enabled, switchLock?)` -- PUT
  - `getTeamPolicy(serverLogin)` -- GET
  - `assignTeamRoster(serverLogin, targetLogin, team)` -- POST
  - `unassignTeamRoster(serverLogin, login)` -- DELETE
  - `listTeamRoster(serverLogin)` -- GET
  - Estimated: ~60 lines added.

- [Done] P9.3 -- Create `AdminVetoDraft.tsx` page — **interactive visual veto interface (FACEIT-style)**
  - **Goal**: A visual, interactive map veto/draft experience similar to FACEIT/CS2 matchmaking sites. Two teams alternate banning and picking maps from a visual pool, with real-time state feedback.
  - **Layout — 3 zones**:
    1. **Header / Config zone** (top):
       - Mode selector: `matchmaking_vote` vs `tournament_draft` (radio/toggle).
       - Series format selector: BO1 / BO3 / BO5 (clickable badges, sets `best_of`).
       - Captain assignment: two input fields (Team A captain login, Team B captain login) — only for tournament mode.
       - Optional fields: `duration_seconds` (matchmaking), `action_timeout_seconds` (tournament), `starter` selector (`team_a` / `team_b` / `random`).
       - **Start Session** button (calls `POST /veto/start`), **Arm Ready** button (calls `POST /veto/ready`).
       - **Cancel** button with `ConfirmModal` (calls `POST /veto/cancel`).
    2. **Map Pool zone** (center — main visual area):
       - Grid of **map cards** (fetched from `GET /servers/:serverLogin/maps` if available, or from veto status response).
       - Each card shows: map name, map UID (truncated), and a visual state indicator:
         - `available` — neutral card (dark border, subtle glow).
         - `banned_team_a` — red overlay / strikethrough, Team A badge.
         - `banned_team_b` — blue overlay / strikethrough, Team B badge.
         - `picked_team_a` — orange highlight border, Team A badge, pick order number.
         - `picked_team_b` — cyan highlight border, Team B badge, pick order number.
         - `decider` — gold/yellow highlight for the last remaining map (BO1) or auto-decider.
       - Clicking an available card when it's your team's turn triggers `POST /veto/action` with the map UID and the current actor (captain login).
       - Non-clickable cards for maps already banned/picked (cursor: not-allowed, opacity reduced).
       - **Turn indicator** banner above the grid: "Team A's turn — BAN" / "Team B's turn — PICK" with team color coding, or "Waiting to start" / "Veto complete".
    3. **Result / Timeline zone** (bottom):
       - Step-by-step timeline showing each action taken (ban/pick) in chronological order, with team badge, map name, and action type.
       - **Final map order** display when veto is complete: ordered list of picked maps with "Map 1", "Map 2", etc. labels.
       - Veto session status badge: `idle`, `running`, `completed`, `cancelled`.
       - Auto-refresh toggle (polls `GET /veto/status` every 2s when enabled).
  - **Matchmaking mode variant**: Instead of captain ban/pick, show map cards with vote buttons. Each card has a vote count badge. Players vote by clicking cards. Timer countdown displayed.
  - **State management**: Local React state mirrors the veto status response. On each action or poll, update card states from the API response. Optimistic UI updates on click (revert on error).
  - Dark theme: `#0a0a0f` bg, orange (`#f97316`) for Team A, cyan (`#06b6d4`) for Team B, gold (`#eab308`) for decider. Uses existing components (Badge, EmptyState, JsonViewer, ConfirmModal, LoadingSpinner).
  - Route: `/admin/veto`
  - File: `pixel-control-ui/src/pages/AdminVetoDraft.tsx`
  - Estimated: 1 new file, ~600 lines.

- [Done] P9.3b -- Create reusable `VetoMapCard` component
  - `pixel-control-ui/src/components/VetoMapCard.tsx`
  - Props: `mapName`, `mapUid`, `status` (`available`|`banned_team_a`|`banned_team_b`|`picked_team_a`|`picked_team_b`|`decider`), `pickOrder?`, `onClick?`, `disabled`.
  - Visual states: color-coded borders/overlays per status, team badge (A/B), pick order number, hover effect on available cards.
  - Responsive: grid adapts from 3 to 5 columns depending on viewport.
  - Estimated: 1 new file, ~120 lines.

- [Done] P9.3c -- Create `VetoTimeline` component
  - `pixel-control-ui/src/components/VetoTimeline.tsx`
  - Props: `steps` (array of `{ team, action, map_name, map_uid, step_number }`), `sessionStatus`.
  - Renders a vertical timeline with team-colored dots, action labels (BAN/PICK), and map names.
  - Shows final map order when session is completed.
  - Estimated: 1 new file, ~80 lines.

- [Done] P9.4 -- Create `AdminPlayerManagement.tsx` page
  - Sections: Force Team form (player login input, team selector), Force Play button (player login), Force Spec button (player login).
  - Action history list.
  - Route: `/admin/players`
  - File: `pixel-control-ui/src/pages/AdminPlayerManagement.tsx`
  - Estimated: 1 new file, ~200 lines.

- [Done] P9.5 -- Create `AdminTeamControl.tsx` page
  - Sections: Policy form (enabled toggle, switch_lock toggle, GET/SET buttons), Roster management (assign form with login + team, unassign by login, list button), Roster display table.
  - Action history list.
  - Route: `/admin/teams`
  - File: `pixel-control-ui/src/pages/AdminTeamControl.tsx`
  - Estimated: 1 new file, ~280 lines.

- [Done] P9.6 -- Register routes in `App.tsx` and add nav items in `MainLayout.tsx`
  - Add imports and `<Route>` entries for all 3 new pages.
  - Add nav items to the "Admin" section in `MainLayout.tsx`:
    - `{ to: '/admin/veto', label: 'Veto / Draft', icon: '...' }`
    - `{ to: '/admin/players', label: 'Player Mgmt', icon: '...' }`
    - `{ to: '/admin/teams', label: 'Team Control', icon: '...' }`
  - Files modified: `App.tsx` (~6 lines), `MainLayout.tsx` (~3 lines).

---

### Phase 10 -- UI: Build verification + Chrome testing (P4)

- [Done] P10.1 -- Build pixel-control-ui
  - Command: `cd pixel-control-ui && npm run build`
  - Acceptance: zero TypeScript/build errors.

- [Done] P10.2 -- Chrome UI testing for P4 pages (skipped per parent instruction)
  - Start dev server (`npm run dev`).
  - Open Chrome via claude-in-chrome MCP.
  - Navigate to `/admin/veto` -- verify:
    - Config zone renders: mode toggle (matchmaking/tournament), BO selector (BO1/BO3/BO5), captain inputs, Start/Ready/Cancel buttons.
    - Map pool grid renders with map cards (at least placeholder cards if no maps loaded).
    - `VetoMapCard` components display correctly with neutral state styling.
    - Turn indicator banner shows "Waiting to start" in idle state.
    - Timeline zone renders empty state.
    - Mode switching between matchmaking and tournament updates the config form (captain fields appear/disappear).
  - Navigate to `/admin/players` -- verify page renders, force-team form has login and team inputs.
  - Navigate to `/admin/teams` -- verify page renders, policy form has toggles, roster section has assign/list controls.
  - Verify sidebar nav shows all 3 new items under "Admin" section.
  - Acceptance: all 3 pages render correctly, no console errors, forms and map cards are interactive.

---

### Phase 11 -- P4 full regression

- [Done] P11.1 -- Run all server unit tests (364/364 pass)
  - Acceptance: all ~358 tests pass.

- [Done] P11.2 -- Run ALL smoke scripts (qa-p0 through qa-p4) — all pass
  - Scripts: qa-p0-smoke, qa-p1-smoke, qa-p2-smoke, qa-p2.5-smoke, qa-p2.6-smoke, qa-p2.6-elite-smoke, qa-elite-enrichment-smoke, qa-p3-admin-commands-smoke, qa-p4-extended-control-smoke.
  - Acceptance: all scripts exit 0.

- [Done] P11.3 -- Run plugin test suite (88/88 pass)
  - Command: `php pixel-control-plugin/tests/run.php`
  - Acceptance: all ~77 tests pass.

- [Done] P11.4 -- Run plugin lint (39 files, zero errors)
  - Acceptance: all PHP files pass `php -l`.

---

### Phase 12 -- P4 documentation update

- [Done] P12.1 -- Update `NEW_API_CONTRACT.md` (P4.1--P4.13 → Done)
  - Change P4.1--P4.13 status from `Todo` to `Done`.
  - Estimated: 13 line changes.

- [Done] P12.2 -- Update `pixel-control-plugin/README.md`
  - Add VetoDraftCommandTrait and P4 admin actions to capability inventory.

- [Done] P12.3 -- Update project `CLAUDE.md` if needed
  - Add P4 module listing, new conventions, new gotchas.

---

### Phase 13 -- P4 commit

- [Todo] P13.1 -- Stage and commit all P4 changes
  - Commit message: `feat(P4): implement 13 extended control endpoints — veto/draft, player management, team control`
  - Acceptance: clean commit on `feat/p4-extended-control` branch.

---

### Phase 14 -- Plugin: Extend AdminCommandTrait (P5.1--P5.14)

Create branch `feat/p5-backlog` from `feat/p4-extended-control`, then extend `AdminCommandTrait` with 14 new actions.

- [Done] P14.0 -- Create branch `feat/p5-backlog` from `feat/p4-extended-control`
  - Acceptance: clean branch created.

- [Done] P14.1 -- Add internal whitelist and vote policy state to `AdminCommandTrait`
  - Properties: `$whitelistEnabled` (bool), `$whitelist` (array of logins), `$votePolicy` (string), `$voteRatios` (assoc array).
  - Estimated: ~10 lines.

- [Done] P14.2 -- Implement auth management handlers (P5.1--P5.2)
  - `handleAuthGrant($parameters)` -- action `auth.grant`
    - Requires `target_login` (string) and `auth_level` (string: `player`/`moderator`/`admin`/`superadmin`).
    - Uses ManiaControl's `AuthenticationManager` to set auth level.
    - Returns `{ action_name, success, code: 'auth_granted', message, details }`.
  - `handleAuthRevoke($parameters)` -- action `auth.revoke`
    - Requires `target_login` (string).
    - Revokes auth level (sets to player).
    - Returns `{ action_name, success, code: 'auth_revoked', message, details }`.
  - Add to `$actionMap`.
  - Estimated: ~60 lines.

- [Done] P14.3 -- Implement whitelist management handlers (P5.3--P5.9)
  - `handleWhitelistEnable($parameters)` -- action `whitelist.enable`
  - `handleWhitelistDisable($parameters)` -- action `whitelist.disable`
  - `handleWhitelistAdd($parameters)` -- action `whitelist.add`, requires `target_login`
  - `handleWhitelistRemove($parameters)` -- action `whitelist.remove`, requires `target_login`
  - `handleWhitelistList($parameters)` -- action `whitelist.list`
  - `handleWhitelistClean($parameters)` -- action `whitelist.clean`
  - `handleWhitelistSync($parameters)` -- action `whitelist.sync`
  - Add all 7 to `$actionMap`.
  - Estimated: ~160 lines.

- [Done] P14.4 -- Implement vote management handlers (P5.10--P5.14)
  - `handleVoteCancel($parameters)` -- action `vote.cancel`
  - `handleVoteSetRatio($parameters)` -- action `vote.set_ratio`, requires `command` (string) and `ratio` (float 0.0--1.0)
  - `handleVoteCustomStart($parameters)` -- action `vote.custom_start`, requires `vote_index` (int)
  - `handleVotePolicyGet($parameters)` -- action `vote.policy.get`
  - `handleVotePolicySet($parameters)` -- action `vote.policy.set`, requires `mode` (string)
  - Add all 5 to `$actionMap`.
  - Estimated: ~120 lines.

- [Done] P14.5 -- Add `requireFloatParam()` helper to `AdminCommandTrait`
  - For `vote.set_ratio` which needs a float `ratio` parameter (0.0--1.0).
  - Estimated: ~15 lines.

---

### Phase 15 -- Plugin: PHP lint + tests for P5

- [Done] P15.1 -- Run PHP lint on all plugin source files
  - Acceptance: zero syntax errors.

- [Done] P15.2 -- Extend `50AdminCommandTest.php` with P5 admin action tests
  - New test cases (~20):
    - `auth.grant` with valid params succeeds.
    - `auth.grant` with missing target_login returns `invalid_parameter`.
    - `auth.grant` with invalid auth_level returns `invalid_parameter`.
    - `auth.revoke` with valid params succeeds.
    - `whitelist.enable` succeeds.
    - `whitelist.disable` succeeds.
    - `whitelist.add` with valid login succeeds.
    - `whitelist.add` with missing login returns `invalid_parameter`.
    - `whitelist.remove` with valid login succeeds.
    - `whitelist.list` returns current whitelist.
    - `whitelist.clean` clears whitelist.
    - `whitelist.sync` succeeds.
    - `vote.cancel` succeeds.
    - `vote.set_ratio` with valid params succeeds.
    - `vote.set_ratio` with invalid ratio returns `invalid_parameter`.
    - `vote.custom_start` with valid index succeeds.
    - `vote.policy.get` returns current policy.
    - `vote.policy.set` with valid mode succeeds.
    - `vote.policy.set` with missing mode returns `invalid_parameter`.
  - File: `pixel-control-plugin/tests/cases/50AdminCommandTest.php` (extend).

- [Done] P15.3 -- Run full plugin test suite
  - Acceptance: 109 tests pass (88 previous + 21 new P5 tests), 0 failures.

---

### Phase 16 -- Server: AdminAuthModule (P5.1--P5.2)

- [Done] P16.1 -- Create DTOs for auth management
  - `AuthGrantDto` (`auth_level: string`, validated).
  - Estimated: ~10 lines.

- [Done] P16.2 -- Create `AdminAuthModule` with controller and service
  - `src/admin-auth/admin-auth.service.ts`:
    - `grantAuth(serverLogin, playerLogin, authLevel)` -> `adminProxy.executeAction(serverLogin, 'auth.grant', { target_login, auth_level })`
    - `revokeAuth(serverLogin, playerLogin)` -> `adminProxy.executeAction(serverLogin, 'auth.revoke', { target_login })`
  - `src/admin-auth/admin-auth.controller.ts`:
    - `POST :serverLogin/players/:login/auth` (P5.1) -- body: `AuthGrantDto`
    - `DELETE :serverLogin/players/:login/auth` (P5.2)
    - Swagger annotations.
  - `src/admin-auth/admin-auth.module.ts` -- imports `AdminProxyModule`.
  - Estimated: 3 new files, ~100 lines total.

- [Done] P16.3 -- Register `AdminAuthModule` in `AppModule`
  - File modified: `src/app.module.ts`, ~2 lines.

---

### Phase 17 -- Server: AdminWhitelistModule (P5.3--P5.9)

- [Done] P17.1 -- Create DTOs for whitelist management
  - `WhitelistAddDto` (`target_login: string`).
  - Estimated: ~10 lines.

- [Done] P17.2 -- Create `AdminWhitelistModule` with controller and service
  - `src/admin-whitelist/admin-whitelist.service.ts`:
    - `enableWhitelist(serverLogin)` -> `adminProxy.executeAction(serverLogin, 'whitelist.enable')`
    - `disableWhitelist(serverLogin)` -> `adminProxy.executeAction(serverLogin, 'whitelist.disable')`
    - `addToWhitelist(serverLogin, targetLogin)` -> `adminProxy.executeAction(serverLogin, 'whitelist.add', { target_login })`
    - `removeFromWhitelist(serverLogin, login)` -> `adminProxy.executeAction(serverLogin, 'whitelist.remove', { target_login })`
    - `listWhitelist(serverLogin)` -> `adminProxy.queryAction(serverLogin, 'whitelist.list')`
    - `cleanWhitelist(serverLogin)` -> `adminProxy.executeAction(serverLogin, 'whitelist.clean')`
    - `syncWhitelist(serverLogin)` -> `adminProxy.executeAction(serverLogin, 'whitelist.sync')`
  - `src/admin-whitelist/admin-whitelist.controller.ts`:
    - `POST :serverLogin/whitelist/enable` (P5.3)
    - `POST :serverLogin/whitelist/disable` (P5.4)
    - `POST :serverLogin/whitelist` (P5.5) -- body: `WhitelistAddDto`
    - `DELETE :serverLogin/whitelist/:login` (P5.6)
    - `GET :serverLogin/whitelist` (P5.7)
    - `DELETE :serverLogin/whitelist` (P5.8) -- NOTE: must be registered AFTER the `:login` route to avoid param capture conflict. Use `@Delete(':serverLogin/whitelist/all')` if NestJS routing is ambiguous, or carefully order methods in the controller (specific routes first).
    - `POST :serverLogin/whitelist/sync` (P5.9)
    - Swagger annotations.
  - `src/admin-whitelist/admin-whitelist.module.ts` -- imports `AdminProxyModule`.
  - **Route ordering note**: `DELETE /whitelist/:login` and `DELETE /whitelist` -- the bare DELETE clears the entire whitelist. In NestJS/Fastify, the parameterized route will match first if `:login` is present. For the bare DELETE (no login), the route without param must be distinguishable. Options:
    - Use `DELETE :serverLogin/whitelist` with no param for clean-all (Fastify matches exact paths before parameterized ones).
    - Or rename to `DELETE :serverLogin/whitelist/all` for clarity.
    - Decision: follow the API contract exactly (`DELETE /whitelist` for clean-all). Place the `@Delete(':serverLogin/whitelist')` method BEFORE `@Delete(':serverLogin/whitelist/:login')` in the controller -- Fastify matches in declaration order.
  - Estimated: 3 new files, ~220 lines total.

- [Done] P17.3 -- Register `AdminWhitelistModule` in `AppModule`
  - File modified: `src/app.module.ts`, ~2 lines.

---

### Phase 18 -- Server: AdminVotesModule (P5.10--P5.14)

- [Done] P18.1 -- Create DTOs for vote management
  - `VoteSetRatioDto` (`command: string`, `ratio: number`).
  - `VoteCustomStartDto` (`vote_index: number`).
  - `VotePolicySetDto` (`mode: string`).
  - Estimated: ~25 lines.

- [Done] P18.2 -- Create `AdminVotesModule` with controller and service
  - `src/admin-votes/admin-votes.service.ts`:
    - `cancelVote(serverLogin)` -> `adminProxy.executeAction(serverLogin, 'vote.cancel')`
    - `setVoteRatio(serverLogin, command, ratio)` -> `adminProxy.executeAction(serverLogin, 'vote.set_ratio', { command, ratio })`
    - `startCustomVote(serverLogin, voteIndex)` -> `adminProxy.executeAction(serverLogin, 'vote.custom_start', { vote_index })`
    - `getVotePolicy(serverLogin)` -> `adminProxy.queryAction(serverLogin, 'vote.policy.get')`
    - `setVotePolicy(serverLogin, mode)` -> `adminProxy.executeAction(serverLogin, 'vote.policy.set', { mode })`
  - `src/admin-votes/admin-votes.controller.ts`:
    - `POST :serverLogin/votes/cancel` (P5.10)
    - `PUT :serverLogin/votes/ratio` (P5.11) -- body: `VoteSetRatioDto`
    - `POST :serverLogin/votes/custom` (P5.12) -- body: `VoteCustomStartDto`
    - `GET :serverLogin/votes/policy` (P5.13)
    - `PUT :serverLogin/votes/policy` (P5.14) -- body: `VotePolicySetDto`
    - Swagger annotations.
  - `src/admin-votes/admin-votes.module.ts` -- imports `AdminProxyModule`.
  - Estimated: 3 new files, ~180 lines total.

- [Done] P18.3 -- Register `AdminVotesModule` in `AppModule`
  - File modified: `src/app.module.ts`, ~2 lines.

---

### Phase 19 -- Server: Unit tests for all P5 modules

- [Done] P19.1 -- Unit tests for `AdminAuthController` (~5 tests)
  - Tests: both endpoints route correctly, AuthGrantDto validated, login param extracted.
  - File: `src/admin-auth/admin-auth.controller.spec.ts`

- [Done] P19.2 -- Unit tests for `AdminWhitelistController` (~10 tests)
  - Tests: all 7 endpoints route correctly, WhitelistAddDto validated, route ordering for DELETE bare vs DELETE :login.
  - File: `src/admin-whitelist/admin-whitelist.controller.spec.ts`

- [Done] P19.3 -- Unit tests for `AdminVotesController` (~8 tests)
  - Tests: all 5 endpoints route correctly, DTOs validated.
  - File: `src/admin-votes/admin-votes.controller.spec.ts`

- [Done] P19.4 -- Run full server test suite
  - Acceptance: all tests pass (previous ~358 + ~23 new = ~381 total). Zero failures.

---

### Phase 20 -- Server: P5 smoke test script

- [Done] P20.1 -- Create `scripts/qa-p5-backlog-smoke.sh`
  - Pattern: same as P3/P4 smoke scripts.
  - Test cases (~22):
    - P5.1: POST players/:login/auth with valid body -- expect 502 or 200.
    - P5.1: POST players/:login/auth without body -- expect 400.
    - P5.2: DELETE players/:login/auth -- expect 502 or 200.
    - P5.3: POST whitelist/enable -- expect 502 or 200.
    - P5.4: POST whitelist/disable -- expect 502 or 200.
    - P5.5: POST whitelist with valid body -- expect 502 or 200.
    - P5.5: POST whitelist without body -- expect 400.
    - P5.6: DELETE whitelist/:login -- expect 502 or 200.
    - P5.7: GET whitelist -- expect 502 or 200.
    - P5.8: DELETE whitelist -- expect 502 or 200.
    - P5.9: POST whitelist/sync -- expect 502 or 200.
    - P5.10: POST votes/cancel -- expect 502 or 200.
    - P5.11: PUT votes/ratio with valid body -- expect 502 or 200.
    - P5.11: PUT votes/ratio without body -- expect 400.
    - P5.12: POST votes/custom with valid body -- expect 502 or 200.
    - P5.12: POST votes/custom without body -- expect 400.
    - P5.13: GET votes/policy -- expect 502 or 200.
    - P5.14: PUT votes/policy with valid body -- expect 502 or 200.
    - P5.14: PUT votes/policy without body -- expect 400.
    - Unknown server -- expect 404.
  - Supports `--live` flag.
  - File: `pixel-control-server/scripts/qa-p5-backlog-smoke.sh`
  - Estimated: 1 new file, ~400 lines.

- [Done] P20.2 -- Run P5 smoke test
  - Acceptance: all assertions pass in no-socket mode.

---

### Phase 21 -- UI: P5 pages (Auth, Whitelist, Votes)

- [Done] P21.1 -- Extend `src/api/admin.ts` with P5 admin functions
  - Auth: `grantAuth(serverLogin, login, authLevel)`, `revokeAuth(serverLogin, login)`
  - Whitelist: `enableWhitelist`, `disableWhitelist`, `addToWhitelist`, `removeFromWhitelist`, `listWhitelist`, `cleanWhitelist`, `syncWhitelist`
  - Votes: `cancelVote`, `setVoteRatio`, `startCustomVote`, `getVotePolicy`, `setVotePolicy`
  - Estimated: ~80 lines added.

- [Done] P21.2 -- Create `AdminAuthManagement.tsx` page
  - Sections: Grant Auth form (player login, auth level selector), Revoke Auth (player login, confirm modal).
  - Action history list.
  - Route: `/admin/auth`
  - File: `pixel-control-ui/src/pages/AdminAuthManagement.tsx`
  - Estimated: 1 new file, ~180 lines.

- [Done] P21.3 -- Create `AdminWhitelistManagement.tsx` page
  - Sections: Enable/Disable toggle, Add player form, Remove player form, List button with whitelist display, Clean (confirm modal), Sync button.
  - Action history list.
  - Route: `/admin/whitelist`
  - File: `pixel-control-ui/src/pages/AdminWhitelistManagement.tsx`
  - Estimated: 1 new file, ~300 lines.

- [Done] P21.4 -- Create `AdminVoteManagement.tsx` page
  - Sections: Cancel Vote button, Set Ratio form (command + ratio slider/input), Start Custom Vote form (vote_index), Policy display (GET) + Set form (mode selector).
  - Action history list.
  - Route: `/admin/votes`
  - File: `pixel-control-ui/src/pages/AdminVoteManagement.tsx`
  - Estimated: 1 new file, ~250 lines.

- [Done] P21.5 -- Register routes in `App.tsx` and add nav items in `MainLayout.tsx`
  - Add imports and `<Route>` entries for all 3 new pages.
  - Add nav items to the "Admin" section:
    - `{ to: '/admin/auth', label: 'Auth Mgmt', icon: '...' }`
    - `{ to: '/admin/whitelist', label: 'Whitelist', icon: '...' }`
    - `{ to: '/admin/votes', label: 'Vote Policy', icon: '...' }`
  - Files modified: `App.tsx` (~6 lines), `MainLayout.tsx` (~3 lines).

---

### Phase 22 -- UI: Build verification + Chrome testing (P5)

- [Done] P22.1 -- Build pixel-control-ui
  - Command: `cd pixel-control-ui && npm run build`
  - Acceptance: zero TypeScript/build errors.

- [Done] P22.2 -- Chrome UI testing for P5 pages (skipped per parent instruction)
  - Navigate to `/admin/auth` -- verify page renders, grant form has login and level inputs.
  - Navigate to `/admin/whitelist` -- verify page renders, enable/disable buttons, add/remove forms, list/clean/sync controls.
  - Navigate to `/admin/votes` -- verify page renders, cancel button, ratio form, policy GET/SET.
  - Verify sidebar nav shows all 3 new items under "Admin" section.
  - Acceptance: all 3 pages render correctly, no console errors, forms are interactive.

---

### Phase 23 -- P5 full regression

- [Done] P23.1 -- Run all server unit tests (394/394 pass)
  - Acceptance: all ~381 tests pass.

- [Done] P23.2 -- Run ALL smoke scripts (qa-p0 through qa-p5) — all pass
  - Scripts: qa-p0, qa-p1, qa-p2, qa-p2.5, qa-p2.6, qa-p2.6-elite, qa-elite-enrichment, qa-p3, qa-p4, qa-p5.
  - Acceptance: all scripts exit 0.

- [Done] P23.3 -- Run plugin test suite (109/109 pass)
  - Acceptance: all ~97 tests pass.

- [Done] P23.4 -- Run plugin lint (39 files, zero errors)
  - Acceptance: all PHP files pass `php -l`.

---

### Phase 24 -- P5 documentation update

- [Done] P24.1 -- Update `NEW_API_CONTRACT.md` (P5.1–P5.14 → Done, P5.15 remains Todo)
  - Change P5.1--P5.14 status from `Todo` to `Done`.
  - Estimated: 14 line changes.

- [Done] P24.2 -- Update `pixel-control-plugin/README.md` (P5 actions already present from P14-P15 phases)
  - Add P5 admin actions to capability inventory.

- [Done] P24.3 -- Update project `CLAUDE.md` and memory if needed
  - Add P5 module listing, update endpoint counts, new conventions.

---

### Phase 25 -- P5 commit

- [Todo] P25.1 -- Stage and commit all P5 changes
  - Commit message: `feat(P5): implement 14 backlog endpoints — auth, whitelist, vote management`
  - Acceptance: clean commit on `feat/p5-backlog` branch.

---

## Evidence / Artifacts

- `pixel-control-server/scripts/qa-p4-extended-control-smoke.sh` -- P4 smoke test script
- `pixel-control-server/scripts/qa-p5-backlog-smoke.sh` -- P5 smoke test script
- `pixel-control-plugin/tests/cases/55VetoDraftCommandTest.php` -- VetoDraft plugin tests
- `pixel-control-plugin/tests/cases/50AdminCommandTest.php` -- Extended admin action tests

## Success criteria

- All 27 new endpoints respond correctly (routing, DTO validation, socket proxy, error mapping).
- 381+ server unit tests pass with zero failures.
- 97+ plugin PHP tests pass.
- All 10 smoke scripts pass (qa-p0 through qa-p5).
- Dev UI builds with zero errors and all 6 new admin pages render correctly in Chrome. The VetoDraft page displays a FACEIT-style interactive map veto interface with clickable map cards, turn indicator, team color coding, and step timeline.
- `NEW_API_CONTRACT.md` shows all P4 and P5 endpoints as `Done`.
- No regressions in existing P0--P3 functionality.

## Summary table

| Phase | Branch | Component | Scope | New files | Est. lines |
|-------|--------|-----------|-------|-----------|------------|
| 0 | p3 | All | QA gate | 0 | 0 |
| 1 | p4 | Plugin | VetoDraftCommandTrait (5 listeners) | 1 | ~400 |
| 2 | p4 | Plugin | AdminCommandTrait +8 actions | 0 (extend) | ~250 |
| 3 | p4 | Plugin | PHP tests | 1 + extend | ~200 |
| 4 | p4 | Server | VetoDraftProxy + VetoDraft module (5 endpoints) | 6 | ~450 |
| 5 | p4 | Server | AdminPlayers module (3 endpoints) | 3 | ~130 |
| 6 | p4 | Server | AdminTeams module (5 endpoints) | 3 | ~200 |
| 7 | p4 | Server | Unit tests | 4 | ~250 |
| 8 | p4 | Server | Smoke test | 1 | ~350 |
| 9 | p4 | UI | 3 pages + 2 veto components + API client | 6 | ~1,380 |
| 10 | p4 | UI | Build + Chrome test | 0 | 0 |
| 11 | p4 | All | Full regression | 0 | 0 |
| 12 | p4 | Docs | Contract + README | 0 (edit) | ~20 |
| 13 | p4 | Git | Commit | 0 | 0 |
| 14 | p5 | Plugin | AdminCommandTrait +14 actions | 0 (extend) | ~365 |
| 15 | p5 | Plugin | PHP tests | 0 (extend) | ~150 |
| 16 | p5 | Server | AdminAuth module (2 endpoints) | 3 | ~100 |
| 17 | p5 | Server | AdminWhitelist module (7 endpoints) | 3 | ~220 |
| 18 | p5 | Server | AdminVotes module (5 endpoints) | 3 | ~180 |
| 19 | p5 | Server | Unit tests | 3 | ~200 |
| 20 | p5 | Server | Smoke test | 1 | ~400 |
| 21 | p5 | UI | 3 pages + API client | 3 | ~810 |
| 22 | p5 | UI | Build + Chrome test | 0 | 0 |
| 23 | p5 | All | Full regression | 0 | 0 |
| 24 | p5 | Docs | Contract + README | 0 (edit) | ~20 |
| 25 | p5 | Git | Commit | 0 | 0 |
| **Total** | | | **27 endpoints** | **~41 new files** | **~6,075 lines** |

## Notes / outcomes

(To be filled after execution.)
