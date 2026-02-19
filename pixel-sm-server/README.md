# Pixel SM Server

`pixel-sm-server` is the first-party local development stack for running a ShootMania dedicated server with ManiaControl and MySQL.

It is designed for plugin and integration development in the Pixel Control monorepo, with deterministic startup, smoke checks, and safe local workflows.

## What you get

- Dockerized local stack (`mysql` + `shootmania`)
- Automatic runtime bootstrap (config rendering, mode/matchsettings resolution, plugin sync)
- ManiaControl startup with Pixel Control plugin auto-sync from source
- Healthcheck that validates DB access, plugin load marker, and XML-RPC readiness
- Smoke helpers for launch validation and multi-mode checks (Elite, Siege, Battle)

## Repository layout

- `docker-compose.yml`: default bridge networking stack
- `docker-compose.host.yml`: optional host-network override
- `Dockerfile`: runtime image (Ubuntu 20.04, PHP 7.4 compatibility baseline)
- `scripts/bootstrap.sh`: runtime startup orchestration
- `scripts/healthcheck.sh`: readiness probe used by Compose
- `scripts/dev-plugin-sync.sh`: fast plugin iteration workflow
- `scripts/qa-launch-smoke.sh`: single launch smoke validation
- `scripts/qa-mode-smoke.sh`: Elite/Siege/Battle smoke matrix
- `scripts/fetch-titlepack.sh`: title pack downloader helper
- `scripts/import-reference-runtime.sh`: copy reference runtime into local `runtime/server/`
- `runtime/server/`: local dedicated server + ManiaControl runtime
- `TitlePacks/`: local title pack assets
- `maps/`: local mode map pools (mounted into runtime)
- `.env.example`: environment template

## Prerequisites

- Docker Desktop (or Docker Engine) with Compose v2
- A local ShootMania runtime containing:
  - `ManiaPlanetServer`
  - `ManiaControl/`
- Free local ports for XML-RPC/game/P2P (defaults in `.env.example`)
- On Apple Silicon/ARM hosts, keep `PIXEL_SM_RUNTIME_PLATFORM=linux/amd64`

## Quick start

1) Create local environment file:

```bash
cp .env.example .env
```

2) Prepare runtime assets in `runtime/server/`:

- Option A: copy your own runtime manually
- Option B: import from local references

```bash
bash scripts/import-reference-runtime.sh
```

3) (Recommended once) provision Battle title pack if you plan to run Battle mode:

```bash
bash scripts/fetch-titlepack.sh SMStormBattle@nadeolabs
```

4) Start the stack:

```bash
docker compose up -d --build
```

5) Verify services and health:

```bash
docker compose ps
docker compose logs --tail=100 shootmania
```

Look for:

- `Step 4/5: starting ManiaControl`
- `Maniacontrol started !`
- `Listening for xml-rpc commands on port ...`
- `[PixelControl] Plugin loaded.` in `runtime/server/ManiaControl/ManiaControl.log`

6) Stop the stack:

```bash
docker compose down
```

## Day-to-day workflows

### Fast plugin iteration

After editing `../pixel-control-plugin/src`, re-sync without rebuilding the image:

```bash
bash scripts/dev-plugin-sync.sh
```

This restarts only `shootmania`, waits for health, and validates plugin load markers.

### Smoke validation

Single launch smoke:

```bash
bash scripts/qa-launch-smoke.sh
```

Mode matrix smoke (Elite, Siege, Battle):

```bash
bash scripts/qa-mode-smoke.sh
```

Smoke artifacts are saved under `logs/qa/`. Dev-sync artifacts are saved under `logs/dev/`.

## Key configuration

Most users only need to adjust these variables in `.env`:

- Runtime mounts:
  - `PIXEL_SM_RUNTIME_SOURCE`
  - `PIXEL_SM_TITLEPACKS_SOURCE`
  - `PIXEL_SM_MAPS_SOURCE`
  - `PIXEL_CONTROL_PLUGIN_SOURCE`
- Server mode:
  - `PIXEL_SM_MODE`
  - `PIXEL_SM_MATCHSETTINGS`
  - `PIXEL_SM_TITLE_PACK`
- Ports:
  - `PIXEL_SM_XMLRPC_PORT`
  - `PIXEL_SM_GAME_PORT`
  - `PIXEL_SM_P2P_PORT`
- Optional plugin transport:
  - `PIXEL_CONTROL_API_BASE_URL`
  - `PIXEL_CONTROL_API_EVENT_PATH`
  - `PIXEL_CONTROL_AUTH_MODE` / `PIXEL_CONTROL_AUTH_VALUE`

All available variables and defaults are documented in `.env.example`.

## Networking profiles

- Default: `docker-compose.yml` (bridge networking, recommended)
- Optional: host networking profile

```bash
docker compose -f docker-compose.yml -f docker-compose.host.yml up -d --build
```

Use host mode only if you need parity with host-network setups and understand its port constraints.

## Important repository boundary rule

`ressources/` is reference-only in this monorepo.

- Do not run mutable runtime workflows directly from `ressources/`
- Import/copy assets into `pixel-sm-server/` first (for example with `scripts/import-reference-runtime.sh`)
- Helpers in this package intentionally fail fast when mutable paths point to `ressources/`

## Troubleshooting

- Port already in use: change `PIXEL_SM_XMLRPC_PORT`, `PIXEL_SM_GAME_PORT`, `PIXEL_SM_P2P_PORT`
- Battle mode fails: ensure `SMStormBattle@nadeolabs.Title.Pack.gbx` exists in `TitlePacks/`
- Plugin marker missing: check `runtime/server/ManiaControl/ManiaControl.log`
- Container unhealthy: inspect `docker compose logs shootmania` and verify runtime paths in `.env`

## Security notes

- Keep real credentials only in local `.env`
- Never commit populated `.env` files
- Replace all `CHANGE_ME_*` placeholders before running the stack
