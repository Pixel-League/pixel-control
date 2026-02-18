# Project Memory (Local AGENTS)

## Project overview
- Pixel Control is designed as a two-part system to orchestrate ManiaPlanet/ShootMania servers: a ManiaControl plugin per game server and a central API server.
- A third stream now exists: `pixel-sm-server/` as a first-party, easily deployable ShootMania dev server package (Docker-based) to test plugin behavior in real game conditions.
- Current repository state is early-stage and mostly documentation/reference; first-party plugin/server code is not scaffolded yet.
- Product direction confirmed with the user: plugin-first execution, multi-mode ShootMania support, local-first developer workflow, and `ressources/` used as reference only.

## Tech stack
- Plugin side (planned): PHP plugin on top of ManiaControl.
- Server side (planned): Laravel API (README-level intention, not yet implemented in this repo).
- Dev server infra (planned in first-party `pixel-sm-server/`): Docker + Docker Compose around ManiaPlanet dedicated server + ManiaControl + MySQL.
- Platform dependencies: ManiaPlanet dedicated server XML-RPC + script callbacks, ManiaControl, MySQL.
- Optional ecosystem integration: ManiaPlanet Web Services OAuth2 (`ressources/oauth2-maniaplanet`).

## Repo layout
- `pixel-control-plugin/`: target location for first-party ManiaControl plugin code (currently README only).
- `pixel-control-server/`: target location for first-party backend API code (currently README only).
- `pixel-sm-server/`: target location for first-party Dockerized ShootMania dev server stack (currently README only).
- `ressources/ManiaControl/`: upstream ManiaControl code, runtime scripts, core/plugins, configs.
- `ressources/ManiaControl-Plugin-Examples/`: sample plugins to copy patterns from.
- `ressources/oauth2-maniaplanet/`: official PHP OAuth2 provider package.
- `ressources/maniaplanet-server-with-docker/`: imported dockerized ShootMania+ManiaControl reference stack.
- `ROADMAP.md`: canonical feature roadmap (when user says `ROADMAP`, this file is implied).

## Common commands
- Root checks:
  - `git rev-parse --show-toplevel`
  - `git status`
- ManiaControl reference workflow (from `ressources/ManiaControl`):
  - `cp configs/server.default.xml configs/server.xml`
  - `php ManiaControl.php` (foreground/debug)
  - `sh ManiaControl.sh` (background, writes `ManiaControl.log` + `ManiaControl.pid`)
  - `sh install_db.sh` (helper script for MySQL setup)
- Dedicated server reference startup (from official docs):
  - `ManiaPlanetServer /nodaemon /dedicated_cfg=dedicated_cfg.txt /game_settings=MatchSettings/matchsettings.txt`
- Dockerized ShootMania reference stack (from `ressources/maniaplanet-server-with-docker`):
  - `docker compose up --build`
  - `docker compose down`
  - `docker compose logs -f`
- Reference tests (if phpunit is available in PATH):
  - `phpunit -c phpunittests/phpunit.xml` (from `ressources/ManiaControl`)

## Conventions
- Treat `ressources/` as read-only reference code unless explicitly requested to edit it.
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
- `pixel-control-plugin/` and `pixel-control-server/` are placeholders today; avoid assuming runnable app code exists there.
- `pixel-sm-server/` is also placeholder-level today; treat `ressources/maniaplanet-server-with-docker/` as the behavior reference.
- ManiaControl requires valid server + MySQL credentials in `ressources/ManiaControl/configs/server.xml`; never commit credentials.
- `ressources/maniaplanet-server-with-docker/docker-compose.yml` and related XML files include hardcoded credentials; treat them as insecure samples and rotate/rewrite before any real deployment.
- ManiaPlanet XML-RPC port (default 5000) is sensitive and should stay private.
- The imported Docker stack uses `network_mode: host`; published `ports:` are ignored in host mode and Docker Desktop host networking must be enabled (Desktop 4.34+).
- In the imported Dockerfile, `TITLE_PACK` is configurable but `/game_settings` is hardcoded to `MatchSettings/.txt`; multi-mode support needs explicit matchsettings parametrization.
- `ressources/maniaplanet-server-with-docker/TitlePacks/` is not copied by the current Dockerfile (`COPY server .` only), so custom title packs are not auto-included yet.
- `https://www.maniacontrol.com` currently returns an expired certificate in this environment; prefer official docs and GitHub mirrors for references.
- `google_search` tool may return account-validation 403 in this environment; use direct `webfetch` URLs as fallback for web research.
