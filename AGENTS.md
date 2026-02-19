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
- `pixel-control-server/`: target location for first-party backend API code (currently docs-contract baseline, implementation pending).
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
- First-party Pixel SM dev stack (from `pixel-sm-server`):
  - `cp .env.example .env`
  - `bash scripts/import-reference-runtime.sh` (optional, copies reference runtime into `pixel-sm-server/runtime/server`)
  - `docker compose up --build`
  - `docker compose down`
  - `docker compose logs -f`
  - `bash scripts/dev-plugin-sync.sh`
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
- Build new product code in `pixel-control-plugin/` and `pixel-control-server/`.
- Build first-party dev server automation in `pixel-sm-server/` (do not evolve the imported Docker reference directly unless explicitly requested).
- For plugin implementation, follow ManiaControl plugin contract conventions:
  - implement `ManiaControl\Plugins\Plugin` methods (`prepare`, `load`, `unload`, metadata getters),
  - wire callbacks/commands via managers,
  - keep settings centralized via `SettingManager`.
- Keep plugin/server contract explicit and versioned (payload schema, idempotency keys, retries, compatibility matrix).
- Multi-mode support is required (do not hardcode Elite-only architecture).
- Authentication choice between plugin and server is not fixed yet; document options in `ROADMAP.md` before locking implementation.
- For Docker/dev-server work, document env var names only (no secret values) and keep secure defaults in `.env.example` style templates.

## CI / release
- No CI workflow files found at repo root (`.github/workflows` absent, `.gitlab-ci.yml` absent).
- No release/versioning process defined yet for first-party Pixel Control code.

## Gotchas
- `pixel-control-server/` is still placeholder-level; `pixel-control-plugin/` now has a runnable skeleton but no production business logic yet.
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
- QA launch smoke supports multi-file compose selection through `PIXEL_SM_QA_COMPOSE_FILES` (CSV, default `docker-compose.yml`).
- Plugin fast-sync helper (`pixel-sm-server/scripts/dev-plugin-sync.sh`) supports multi-file compose selection through `PIXEL_SM_DEV_COMPOSE_FILES` (CSV, default `docker-compose.yml`) and leaves stack running for iteration.
- Plugin transport runtime now also supports queue/dispatch/heartbeat knobs through `PIXEL_CONTROL_QUEUE_MAX_SIZE`, `PIXEL_CONTROL_DISPATCH_BATCH_SIZE`, and `PIXEL_CONTROL_HEARTBEAT_INTERVAL_SECONDS`.
- If default local ports are occupied during dev fast sync, override runtime ports inline (for example `PIXEL_SM_XMLRPC_PORT=57000 PIXEL_SM_GAME_PORT=57100 PIXEL_SM_P2P_PORT=57200 bash scripts/dev-plugin-sync.sh`).

## Current execution status (2026-02-19)
- Active execution plan: `PLAN-immediate-pixel-control-execution.md` (resume marker kept on step `P7.16`).
- Plugin scaffold completed in `pixel-control-plugin/src/` with full Plugin contract, callback groups (lifecycle/player/combat + mode stubs), async envelope client shell, and queue/retry contracts.
- Dev stack baseline completed in `pixel-sm-server/` with deterministic bootstrap sequence (`DB readiness -> templating -> ManiaControl -> dedicated server`), mode-aware matchsettings resolution, runtime map auto-injection guard, and runtime healthcheck script.
- Plugin runtime transport config now supports env-driven base URL, path, timeout/retry knobs, and auth mode/header/value via `PIXEL_CONTROL_*` variables.
- Plugin connectivity P0 is now in place: monotonic source sequences, bounded queue/dispatch defaults, exponential retry policy, startup registration envelope, and periodic heartbeat envelope.
- Lifecycle normalization P0 is now in place for ManiaPlanet lifecycle callbacks: warmup/match/map/round variants with shared runtime context metadata in outbound envelopes.
- Lifecycle event bus now also includes script callback lifecycle signals (`Start/EndMatch`, `Loading/UnloadingMap`, `Start/EndRound`) with unified lifecycle variants and source channel tagging.
- Envelope fields now include explicit `event_id` + `schema_version` plus existing source callback/time/sequence metadata for contract consistency.
- Delivery error contract now defines shared retry semantics through typed `DeliveryError` payloads (`code`, `message`, `retryable`, `retry_after_seconds`) and ack/error parsing rules.
- Canonical contract baseline is now in place for `Checkpoint R` with machine-readable event catalog + JSON schema artifacts in `pixel-control-plugin/docs/schema/` (`event-name-catalog-2026-02-19.1.json`, `envelope-2026-02-19.1.schema.json`, `lifecycle-payload-2026-02-19.1.schema.json`, `delivery-error-2026-02-19.1.schema.json`).
- At-least-once ingestion contract baseline is now in place for `Checkpoint S` in `pixel-control-server/docs/` with request/response schema artifacts (`ingestion-contract.md`, `schema/ingestion-request-2026-02-19.1.schema.json`, `schema/ingestion-response-2026-02-19.1.schema.json`).
- Roadmap thread now has checkpoints `A/B/C/D/E/F/G/H/I/J/K/L/M/N/O/P/Q/R/S` done and `T` as the single next step.
- QA smoke now passes via `pixel-sm-server/scripts/qa-launch-smoke.sh` with markers: plugin sync, title-pack validation, mode script validation, matchsettings load, shootmania healthcheck, XML-RPC TCP readiness, and `[PixelControl] Plugin loaded.` in ManiaControl logs.
- Mode smoke matrix now passes with `pixel-sm-server/scripts/qa-mode-smoke.sh` (Elite + Siege + Battle).
- First-party networking path now documented with bridge-default and optional host override profile in `pixel-sm-server/README.md` + `pixel-sm-server/docker-compose.host.yml`.
- Plugin-only iteration path now uses `pixel-sm-server/scripts/dev-plugin-sync.sh` (restart-only sync, no image rebuild) with logs in `pixel-sm-server/logs/dev/`.
- Where we stopped: after completing roadmap `Checkpoint S` by shipping server-side ingestion contract + request/response schema baseline artifacts.
- Exact next action: implement roadmap `Checkpoint T` by scaffolding first server-side ingestion implementation slice (endpoint stubs + dedupe receipt persistence plan).
- Handoff note: keep `bash scripts/qa-mode-smoke.sh` as regression gate, and use `PIXEL_SM_QA_COMPOSE_FILES`/`PIXEL_SM_DEV_COMPOSE_FILES` when validating non-default compose profiles.
