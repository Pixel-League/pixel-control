# Project Memory (Local AGENTS)

## Project overview
- Pixel Control is designed as a two-part system to orchestrate ManiaPlanet/ShootMania servers: a ManiaControl plugin per game server and a central API server.
- A third stream now exists: `pixel-sm-server/` as a first-party, easily deployable ShootMania dev server package (Docker-based) to test plugin behavior in real game conditions.
- Current repository state is still early-stage for full features, but first-party plugin and dev-server skeletons now exist and are ready for incremental implementation.
- Product direction confirmed with the user: plugin-first execution, multi-mode ShootMania support, local-first developer workflow, and `ressources/` used as reference only.

## Tech stack
- Plugin side (planned): PHP plugin on top of ManiaControl.
- Server side (planned): Laravel API (README-level intention, not yet implemented in this repo).
- Dev server infra (scaffolded in first-party `pixel-sm-server/`): Docker + Docker Compose around ManiaPlanet dedicated server + ManiaControl + MySQL.
- Platform dependencies: ManiaPlanet dedicated server XML-RPC + script callbacks, ManiaControl, MySQL.
- Optional ecosystem integration: ManiaPlanet Web Services OAuth2 (`ressources/oauth2-maniaplanet`).

## Repo layout
- `pixel-control-plugin/`: first-party ManiaControl plugin skeleton (`src/PixelControlPlugin.php`, callback registry, async API shell, queue/retry contracts).
- `pixel-control-server/`: reserved workspace for future backend/API implementation (currently deferred by user; keep only lightweight contract references until backend phase starts).
- `pixel-sm-server/`: first-party Dockerized ShootMania dev stack baseline (`docker-compose.yml`, `Dockerfile`, `scripts/bootstrap.sh`, templates, `.env.example`).
- `.local-dev/`: gitignored local sandbox for mutable ManiaControl/ShootMania experiments outside first-party project trees.
- `ressources/ManiaControl/`: upstream ManiaControl code, runtime scripts, core/plugins, configs.
- `ressources/ManiaControl-Plugin-Examples/`: sample plugins to copy patterns from.
- `ressources/oauth2-maniaplanet/`: official PHP OAuth2 provider package.
- `ressources/maniaplanet-server-with-docker/`: imported dockerized ShootMania+ManiaControl reference stack.
- `ROADMAP.md`: canonical feature roadmap (when user says `ROADMAP`, this file is implied).

## Common commands
- Root checks:
  - `git rev-parse --show-toplevel`
  - `git status`
- Pixel Control backend/API is currently deferred; no server runtime command is canonical for this phase.
- First-party Pixel SM dev stack (from `pixel-sm-server`):
  - `cp .env.example .env`
  - `bash scripts/import-reference-runtime.sh` (optional, copies reference runtime into `pixel-sm-server/runtime/server`)
  - `docker compose up --build`
  - `docker compose down`
  - `docker compose logs -f`
  - `bash scripts/dev-plugin-sync.sh`
  - `bash scripts/qa-wave3-telemetry-replay.sh`
  - `bash scripts/qa-wave4-telemetry-replay.sh`
  - `bash scripts/qa-admin-stats-replay.sh`
- ManiaControl sandbox workflow (copy reference first, then run from `.local-dev/`):
  - `mkdir -p .local-dev`
  - `cp -R ressources/ManiaControl .local-dev/maniacontrol`
  - `cp .local-dev/maniacontrol/configs/server.default.xml .local-dev/maniacontrol/configs/server.xml`
  - `php .local-dev/maniacontrol/ManiaControl.php` (foreground/debug)
- Dedicated server reference startup (from official docs):
  - `ManiaPlanetServer /nodaemon /dedicated_cfg=dedicated_cfg.txt /game_settings=MatchSettings/matchsettings.txt`
- Dockerized ShootMania reference stack (from `ressources/maniaplanet-server-with-docker`):
  - `docker compose up --build`
  - `docker compose down`
  - `docker compose logs -f`
- Reference tests (if phpunit is available in PATH):
  - `phpunit -c phpunittests/phpunit.xml` (from `.local-dev/maniacontrol`)

## Conventions
- Treat `ressources/` as immutable read-only reference code unless explicitly requested to edit it.
- Never run mutable workflows inside `ressources/` (no runtime launches, no log/pid generation, no config rewrites).
- Treat `pixel-sm-server/`, `pixel-control-plugin/`, and `pixel-control-server/` as separate first-party projects inside a monorepo.
- If reference assets are needed by a first-party project, copy/import them into that project directory first (do not bind mutable flows directly to `ressources/`).
- For local experimentation outside first-party projects, use `.local-dev/` (gitignored) as scratch workspace.
- If project-local imports need independent source tracking, initialize them as nested standalone repos inside the target project folder (empty git root + explicit import commit), never under `ressources/`.
- Build new product code in `pixel-control-plugin/` and `pixel-sm-server/` for now; keep `pixel-control-server/` implementation-deferred until explicitly resumed.
- Build first-party dev server automation in `pixel-sm-server/` (do not evolve the imported Docker reference directly unless explicitly requested).
- Keep `API_CONTRACT.md` (repo root) updated whenever plugin->API route expectations change.
- Keep `pixel-control-plugin/FEATURES.md` updated whenever plugin capabilities change.
- For plugin implementation, follow ManiaControl plugin contract conventions:
  - implement `ManiaControl\Plugins\Plugin` methods (`prepare`, `load`, `unload`, metadata getters),
  - wire callbacks/commands via managers,
  - keep settings centralized via `SettingManager`.
- Keep plugin/server contract explicit and versioned (payload schema, idempotency keys, retries, compatibility matrix).
- Multi-mode support is required (do not hardcode Elite-only architecture).
- Authentication choice between plugin and server is not fixed yet; document options in `ROADMAP.md` before locking implementation.
- For Docker/dev-server work, document env var names only (no secret values) and keep secure defaults in `.env.example` style templates.
- After each resolved blocker, append a concise incident memory in this local `AGENTS.md` with: symptom, root cause, applied fix, and validation signal so future runs avoid repeating the same failure.

## CI / release
- No CI workflow files found at repo root (`.github/workflows` absent, `.gitlab-ci.yml` absent).
- No release/versioning process defined yet for first-party Pixel Control code.

## Gotchas
- `pixel-control-server/` implementation is intentionally deferred for this phase; avoid adding backend runtime code there until user re-opens backend work.
- `pixel-sm-server/` now has a first-party baseline, but still requires local runtime assets in `pixel-sm-server/runtime/server/` (dedicated binary + ManiaControl runtime).
- `PIXEL_SM_RUNTIME_SOURCE` and title pack mutable sources must stay outside `ressources/`; helper scripts now fail fast when pointed at reference paths.
- ManiaControl requires valid server + MySQL credentials in `ressources/ManiaControl/configs/server.xml`; never commit credentials.
- `ressources/maniaplanet-server-with-docker/docker-compose.yml` and related XML files include hardcoded credentials; treat them as insecure samples and rotate/rewrite before any real deployment.
- ManiaPlanet XML-RPC port (default 5000) is sensitive and should stay private.
- The imported Docker stack uses `network_mode: host`; published `ports:` are ignored in host mode and Docker Desktop host networking must be enabled (Desktop 4.34+).
- In the imported Dockerfile, `TITLE_PACK` is configurable but `/game_settings` is hardcoded to `MatchSettings/.txt`; multi-mode support needs explicit matchsettings parametrization.
- `ressources/maniaplanet-server-with-docker/TitlePacks/` is not copied by the current Dockerfile (`COPY server .` only), so custom title packs are not auto-included yet.
- `https://www.maniacontrol.com` currently returns an expired certificate in this environment; prefer official docs and GitHub mirrors for references.
- `google_search` tool may return account-validation 403 in this environment; use direct `webfetch` URLs as fallback for web research.
- ManiaControl in the imported runtime crashes on PHP 8.x (`libs/curl-easy/cURL/RequestsQueue.php` Countable signature + AsyncHttpRequest fatal); keep first-party `pixel-sm-server/Dockerfile` on Ubuntu 20.04/PHP 7.4 unless compatibility is explicitly addressed.
- Matchsettings templates without explicit `<map>` entries can trigger `ERROR: Empty playlist`; first-party bootstrap now injects available runtime `.Map.Gbx` entries into `active-matchsettings.txt` to fail-safe local launches.
- Runtime map auto-injection is now restricted to Elite-compatible flows; for Siege/Battle/Joust/Royal scripts, bootstrap fails early with explicit map guidance when `<map>` entries are missing.
- Battle mode in current workflow expects `SMStormBattle@nadeolabs` + `Battle\BattlePro.Script.txt`; using `ShootMania\Battle\Mode.Script.txt` fails in this runtime.
- Official battle title pack download endpoint: `https://maniaplanet.com/ingame/public/titles/download/SMStormBattle@nadeolabs.Title.Pack.gbx`.

## Plugin implementation playbook
- Startup order: `ManiaControl::run()` triggers `Callbacks::ONINIT`, then plugin loading (`PluginManager::loadPlugins()`), then `Callbacks::AFTERINIT`.
- Script callbacks are enabled during connection (`connect()` -> `Server::getScriptManager()->enableScriptCallbacks()`), before plugin load.
- Plugin class requirements: implement `ManiaControl\Plugins\Plugin`, keep file basename equal to class basename, and return `getId() > 0` (unless `DEV_MODE` is enabled).
- Automatic cleanup on unload only applies to implemented listener interfaces (`CallbackListener`, `CommandListener`, `TimerListener`, `ManialinkPageAnswerListener`, `SidebarMenuEntryListener`, `CommunicationListener`, `EchoListener`).
- For typed ShootMania events, use `registerCallbackListener(Callbacks::SM_*, ...)`; this yields typed structures via `ShootManiaCallbacks`.
- Use `registerScriptCallbackListener(...)` only when raw script callback payloads are explicitly needed.
- Keep callback handlers lightweight: ManiaControl loop targets a tight tick (`~2.5ms` sleep budget), so heavy work should be buffered and flushed on timers.
- Prefer async outbound HTTP (`AsyncHttpRequest`) and keep retry/idempotency logic in Pixel Control plugin code, not in callback hot-paths.

## Callback shortlist for Pixel Control P0
- Lifecycle: `CallbackManager::CB_MP_BEGINMATCH`, `CB_MP_ENDMATCH`, `CB_MP_BEGINMAP`, `CB_MP_ENDMAP`, `CB_MP_BEGINROUND`, `CB_MP_ENDROUND`.
- Player state: `PlayerManager::CB_PLAYERCONNECT`, `CB_PLAYERDISCONNECT`, `CB_PLAYERINFOCHANGED`, `CB_PLAYERINFOSCHANGED`.
- Combat/stats: `Callbacks::SM_ONSHOOT`, `SM_ONHIT`, `SM_ONNEARMISS`, `SM_ONARMOREMPTY`, `SM_ONCAPTURE`, `SM_SCORES`.
- Mode-specific extensions: `Callbacks::SM_ELITE_STARTTURN`, `SM_ELITE_ENDTURN`, `SM_JOUST_*`, `SM_ROYAL_*`.
- Useful payload details from typed structures: weapon IDs (`Laser=1`, `Rocket=2`, `Nucleus=3`, `Arrow=5`), damage/distance, shooter/victim positions, capture players and score snapshots.

## Pixel SM server implementation notes
- Reference Docker image copies only `server/` (`COPY server .`), so `TitlePacks/` are missing unless explicitly copied or mounted.
- Reference startup command hardcodes `/game_settings=MatchSettings/.txt`; first-party stack must parameterize matchsettings to unlock multi-mode support.
- First-party networking profiles: bridge-default in `pixel-sm-server/docker-compose.yml`, optional host override in `pixel-sm-server/docker-compose.host.yml`.
- With `network_mode: host`, published `ports:` are ignored; host profile also requires Docker Desktop host networking support (Desktop 4.34+) and free host ports.
- Treat all credentials in imported samples as insecure placeholders and replace them with `.env.example`-style variables only.
- First-party compose now wires `shootmania` healthcheck to `scripts/healthcheck.sh` (DB ping + plugin load marker + XML-RPC TCP probe); keep smoke checks aligned with this readiness contract.
- Mode map pool is mounted via `PIXEL_SM_MAPS_SOURCE` and synced into runtime `UserData/Maps/PixelControl/` at bootstrap.
- Mode matrix smoke helper: `pixel-sm-server/scripts/qa-mode-smoke.sh` validates Elite + Siege + Battle flows.
- Wave-2 replay helper: `pixel-sm-server/scripts/qa-admin-stats-replay.sh` posts deterministic admin/player/combat envelopes into local server ingestion; treat as legacy while backend runtime remains paused.
- Wave-2 replay helper fails fast with `curl: (7)` when backend ingestion is not running on `127.0.0.1:8080`; keep this expected while backend runtime remains paused.
- Wave-3 replay helper: `pixel-sm-server/scripts/qa-wave3-telemetry-replay.sh` runs a local ACK stub, starts the stack with deterministic QA ports, replays admin/player/map actions in-container, validates required telemetry markers, and stores artifacts under `pixel-sm-server/logs/qa/wave3-telemetry-<timestamp>-*`.
- Wave-3 helper now supports deterministic marker fixtures (`PIXEL_SM_QA_TELEMETRY_INJECT_FIXTURES=1` by default) and host-side stub posting through `PIXEL_SM_QA_TELEMETRY_STUB_LOCAL_URL` (default `http://127.0.0.1:18080`) to avoid local `host.docker.internal` DNS issues.
- Wave-4 replay helper: `pixel-sm-server/scripts/qa-wave4-telemetry-replay.sh` validates reconnect/side-change/team-aggregate/win-context/veto markers, stores artifacts under `pixel-sm-server/logs/qa/wave4-telemetry-<timestamp>-*`, and indexes canonical evidence in `pixel-sm-server/logs/qa/wave4-evidence-index-20260220.md`.
- In current battle-mode runtime, direct fake-player `forcePlayerTeam(...)` calls can intermittently return `UnknownPlayer`; wave-4 replay now treats those as non-fatal warnings and relies on deterministic fixture envelopes for required marker closure.
- Wave-5 manual evidence helpers are now first-party: `pixel-sm-server/scripts/manual-wave5-session-bootstrap.sh`, `pixel-sm-server/scripts/manual-wave5-ack-stub.sh`, `pixel-sm-server/scripts/manual-wave5-log-export.sh`, and `pixel-sm-server/scripts/manual-wave5-evidence-check.sh`.
- Recommended manual payload capture flow: run local ACK stub on host `127.0.0.1:18080`, then restart plugin transport with `PIXEL_CONTROL_API_BASE_URL=http://host.docker.internal:18080 bash scripts/dev-plugin-sync.sh` before real-client scenarios.
- QA launch smoke supports multi-file compose selection through `PIXEL_SM_QA_COMPOSE_FILES` (CSV, default `docker-compose.yml`).
- Plugin fast-sync helper (`pixel-sm-server/scripts/dev-plugin-sync.sh`) supports multi-file compose selection through `PIXEL_SM_DEV_COMPOSE_FILES` (CSV, default `docker-compose.yml`) and leaves stack running for iteration.
- Plugin hot-sync helper (`pixel-sm-server/scripts/dev-plugin-hot-sync.sh`) now supports plugin-only refresh without shootmania container restart: it copies plugin source into runtime and restarts ManiaControl process only (dedicated server PID should remain unchanged).
- Plugin transport runtime now also supports queue/dispatch/heartbeat knobs through `PIXEL_CONTROL_QUEUE_MAX_SIZE`, `PIXEL_CONTROL_DISPATCH_BATCH_SIZE`, and `PIXEL_CONTROL_HEARTBEAT_INTERVAL_SECONDS`.
- If default local ports are occupied during dev fast sync, override runtime ports inline (for example `PIXEL_SM_XMLRPC_PORT=57000 PIXEL_SM_GAME_PORT=57100 PIXEL_SM_P2P_PORT=57200 bash scripts/dev-plugin-sync.sh`).
- For LAN peer-play sessions where login-based join URLs resolve to public IP and fail/hang, use local server list join and, if needed, restart with legacy gameplay ports (`PIXEL_SM_GAME_PORT=2350 PIXEL_SM_P2P_PORT=3450`) for compatibility.
- LAN local-server-list discovery is now considered stable on native gameplay ports; keep `.env` defaults on `PIXEL_SM_GAME_PORT=2350` and `PIXEL_SM_P2P_PORT=3450` unless a session explicitly needs temporary overrides.
- Incident memory (2026-02-20, server not visible/reachable on LAN):
  - symptom: server missing from local list or join failures/hangs while using non-native gameplay ports.
  - root cause: gameplay ports were moved away from native defaults and login URL resolution could route to public IP path.
  - fix: pin gameplay ports back to native values (`PIXEL_SM_GAME_PORT=2350`, `PIXEL_SM_P2P_PORT=3450`) and join through local server list.
  - validation: server appears in local list and remote client reaches spawn successfully.
- Host-side direct XML-RPC clients against published port can be reset by server access policy; reliable automation is to run dedicated API calls from inside the `shootmania` container using ManiaControl's bundled PHP client (`Maniaplanet\DedicatedServer\Connection::factory('127.0.0.1', $PIXEL_SM_XMLRPC_PORT, ...)`).
- For plugin payload evidence capture, a local ACK stub on host port `18080` works with container target `host.docker.internal:18080`; writing raw envelopes as NDJSON in `pixel-sm-server/logs/dev/` enables deterministic inspection of emitted payload fields.
- Outage/recovery QA is reproducible by toggling that local ACK stub: stop stub -> observe `outage_entered`/`retry_scheduled` markers; restart stub -> observe `outage_recovered` + `recovery_flush_complete` in `pixel-sm-server/runtime/server/ManiaControl/ManiaControl.log`.
- `connectFakePlayer` + forced map transitions (`restartMap`/`nextMap`) can trigger admin-flow callbacks and at least `OnScores` combat envelope shape validation, but real client gameplay is still required for non-zero shot/hit/miss/kill counters.
- Manual combat observability logs are now emitted with prefix `[Pixel Plugin]` for `OnShoot`/`OnHit`/`OnNearMiss`/`OnArmorEmpty`/`OnCapture`/`OnScores`; monitor with `tail -f pixel-sm-server/runtime/server/ManiaControl/ManiaControl.log | grep --line-buffered -E '\[Pixel Plugin\]'`.
- Incident memory (2026-02-20, long-lived in-memory combat stats retention):
  - symptom: concern that combat counters persisted in plugin memory for the full server uptime and could accumulate unnecessary player rows.
  - root cause: `PlayerCombatStatsStore` was runtime-only but reset only on plugin reload/restart, so retention window matched long-lived process lifetime.
  - fix: reset combat counter store + aggregate baselines on `match.begin` and `map.begin` lifecycle boundaries to keep retention bounded to active match/map windows.
  - validation: `MatchDomainTrait::buildLifecycleAggregateTelemetry()` now calls reset helper on `match.begin|map.begin`, and `FEATURES.md`/`docs/event-contract.md` describe bounded non-persistent retention.

## Current execution status (2026-02-20)
- Active execution direction: plugin-first and dev-server-first; backend/API implementation is paused by user for now.
- Plugin schema baseline remains `2026-02-20.1`; wave-5 keeps version unchanged and continues additive optional fields only.
- Wave-5 plugin hardening delivered:
  - deterministic identity validation on enqueue/dispatch (`drop_identity_invalid` warning + queue counter `dropped_on_identity_validation`),
  - additive player constraint telemetry (`constraint_signals`) for forced-team/slot-policy context with deterministic availability/fallback reasons,
  - callback hot-path safety via dedicated-policy cache refresh on load/heartbeat and cached reads in player callbacks.
- Wave-5 deterministic QA indexing is complete:
  - canonical index: `pixel-sm-server/logs/qa/wave5-evidence-index-20260220.md`,
  - strict replay closure artifact set: `pixel-sm-server/logs/qa/wave4-telemetry-20260220-143317-*`,
  - fixture-off plugin-only baseline artifact set: `pixel-sm-server/logs/qa/wave4-telemetry-20260220-143433-*`,
  - fixture-off pre-profile strict failure retained for traceability: `pixel-sm-server/logs/qa/wave4-telemetry-20260220-143020-*`.
- Wave-5 manual evidence contract is standardized:
  - canonical directory: `pixel-sm-server/logs/manual/wave5-real-client-20260220/`,
  - matrix file: `pixel-sm-server/logs/manual/wave5-real-client-20260220/MANUAL-TEST-MATRIX.md` (`W5-M01..W5-M10`),
  - required per-session templates: `SESSION-<id>-{notes.md,payload.ndjson,evidence.md}`,
  - index + completeness checker flow: `INDEX.md` + `bash scripts/manual-wave5-evidence-check.sh --manual-dir ...` (now requires matrix presence).
- Final wave-5 handoff artifact is published: `HANDOFF-autonomous-wave-5-2026-02-20.md`.
- Wave-1 manual gameplay closure remains pending by user choice:
  - user said manual gameplay tests will be run later,
  - keep `PLAN-autonomous-execution-wave-1.md` manual `P7.2/P7.3` evidence closure pending until user provides gameplay captures.
- Wave-4/wave-5 real-client gameplay validation remains pending for plugin-only evidence (non-fixture reconnect/side-change/veto actor behavior under real player flow).
- Backend note:
  - `pixel-control-server/` code changes were rolled back on user request,
  - future API behavior must be tracked in `API_CONTRACT.md` until backend work is re-opened.
- Where we stopped: autonomous wave-5 implementation is complete; only user-run real-client matrix execution and evidence status updates remain.
