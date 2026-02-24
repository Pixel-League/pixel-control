# Pixel SM Server

`pixel-sm-server` is the first-party ShootMania + ManiaControl + MySQL stack used in the Pixel Control monorepo.

It supports both production-like deployments and local plugin/integration development, with deterministic startup, health checks, and additive deployment templates.

## Production deployment (fast path)

Use this path for deployment-first operation. It keeps existing dev defaults intact and relies on additive production templates.

1) Create a production env file:

```bash
cp .env.production.example .env.production.local
```

2) Edit `.env.production.local` and replace all `CHANGE_ME_*` values before startup.

3) Start services with base + production override:

```bash
docker compose --env-file .env.production.local -f docker-compose.yml -f docker-compose.production.yml up -d --build
```

4) Verify readiness:

```bash
docker compose --env-file .env.production.local -f docker-compose.yml -f docker-compose.production.yml ps
docker compose --env-file .env.production.local -f docker-compose.yml -f docker-compose.production.yml logs --tail=150 shootmania
```

5) Stop services:

```bash
docker compose --env-file .env.production.local -f docker-compose.yml -f docker-compose.production.yml down
```

## Production operations (day-2)

### Update / redeploy

```bash
git pull --ff-only
docker compose --env-file .env.production.local -f docker-compose.yml -f docker-compose.production.yml up -d --build
```

### Rollback

If a deployment regresses, return to a previous known-good revision and redeploy with the same production command path:

```bash
git checkout <known-good-commit-or-tag>
docker compose --env-file .env.production.local -f docker-compose.yml -f docker-compose.production.yml up -d --build
```

Immediate fallback to baseline local/default compose path (no production override):

```bash
docker compose --env-file .env -f docker-compose.yml up -d --build
```

### Logs and health

```bash
docker compose --env-file .env.production.local -f docker-compose.yml -f docker-compose.production.yml logs -f shootmania
docker compose --env-file .env.production.local -f docker-compose.yml -f docker-compose.production.yml logs -f mysql
docker compose --env-file .env.production.local -f docker-compose.yml -f docker-compose.production.yml ps
docker inspect --format='{{json .State.Health}}' "$(docker compose --env-file .env.production.local -f docker-compose.yml -f docker-compose.production.yml ps -q shootmania)"
```

### Known risks and blast radius

- If `.env.production.local` still contains placeholder credentials, `shootmania` can loop unhealthy while `mysql` remains healthy.
- Typical symptom is dedicated runtime master-auth failure in logs (`This player does not exist` / `Connection to master server lost`).
- Blast radius is limited to game service availability; MySQL volume data remains intact unless you explicitly remove volumes.
- Immediate operational fallback is to redeploy with baseline compose path while fixing production env values:

```bash
docker compose --env-file .env -f docker-compose.yml up -d --build
```

## Security guidance

- Never commit populated `.env.production.local` (or `.env`) files.
- Replace every `CHANGE_ME_*` placeholder before exposing the stack on shared/public networks.
- Keep XML-RPC (`PIXEL_SM_XMLRPC_PORT`) restricted at firewall/network level.
- Keep mutable runtime paths outside `../ressources/*`.

## What you get

- Dockerized local stack (`mysql` + `shootmania`)
- Automatic runtime bootstrap (config rendering, mode/matchsettings resolution, plugin sync)
- ManiaControl startup with Pixel Control plugin auto-sync from source
- Healthcheck that validates DB access, plugin load marker, and XML-RPC readiness
- Validation helpers for launch and multi-mode checks (Elite, Siege, Battle, Joust, Custom)

## Repository layout

- `docker-compose.yml`: default bridge networking stack
- `docker-compose.production.yml`: additive production-oriented override
- `docker-compose.host.yml`: optional host-network override
- `Dockerfile`: runtime image (Ubuntu 20.04, PHP 7.4 compatibility baseline)
- `scripts/bootstrap.sh`: runtime startup orchestration
- `scripts/healthcheck.sh`: readiness probe used by Compose
- `scripts/dev-plugin-sync.sh`: fast plugin iteration workflow
- `scripts/dev-mode-compose.sh`: mode profile launcher/relauncher (`.env.<mode>` -> `.env`)
- `scripts/validate-dev-stack-launch.sh`: single launch validation
- `scripts/validate-mode-launch-matrix.sh`: Elite/Siege/Battle/Joust/Custom mode launch matrix
- `scripts/replay-core-telemetry-wave3.sh`: deterministic admin/player/aggregate/map telemetry replay with local ACK capture
- `scripts/replay-extended-telemetry-wave4.sh`: deterministic reconnect/side/team/veto telemetry replay with marker validation
- `scripts/simulate-admin-control-payloads.sh`: simulate server-side admin payloads against `PixelControl.Admin.*` communication methods
- `scripts/simulate-veto-control-payloads.sh`: simulate server-side veto payloads against `PixelControl.VetoDraft.*` communication methods
- `scripts/fetch-titlepack.sh`: title pack downloader helper
- `scripts/import-reference-runtime.sh`: copy reference runtime into local `runtime/server/`
- `runtime/server/`: local dedicated server + ManiaControl runtime
- `TitlePacks/`: local title pack assets
- `maps/`: local mode map pools (mounted into runtime)
- `.env.example`: developer/local environment template
- `.env.production.example`: production deployment environment template

## Prerequisites

- Docker Desktop (or Docker Engine) with Compose v2
- A local ShootMania runtime containing:
  - `ManiaPlanetServer`
  - `ManiaControl/`
- Free local ports for XML-RPC/game/P2P (defaults in `.env.example`)
- On Apple Silicon/ARM hosts, keep `PIXEL_SM_RUNTIME_PLATFORM=linux/amd64`

## Developer quick start

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

## Developer and QA workflows

### Fast plugin iteration

After editing `../pixel-control-plugin/src`, re-sync without rebuilding the image:

```bash
bash scripts/dev-plugin-sync.sh
```

This restarts only `shootmania`, waits for health, and validates plugin load markers.

If you want smoother dev flow and to avoid restarting the dedicated server process, use hot sync:

```bash
bash scripts/dev-plugin-hot-sync.sh
```

This syncs plugin files into the running container and restarts only ManiaControl (dedicated server PID should stay unchanged).

### Mode profile launch/relaunch

If you keep one env profile per mode (`.env.elite`, `.env.joust`, ...), use:

```bash
bash scripts/dev-mode-compose.sh elite
bash scripts/dev-mode-compose.sh joust relaunch
```

Behavior:

- Reads `.env.<mode>` and copies it into `.env`
- Launches/relaunches `mysql` + `shootmania` via `docker-compose.yml`
- Supports compose-file override via `PIXEL_SM_DEV_COMPOSE_FILES`
- Supports optional image rebuild via `PIXEL_SM_DEV_MODE_BUILD_IMAGES=1`

### Validation flows

Single launch validation:

```bash
bash scripts/validate-dev-stack-launch.sh
```

Mode launch matrix validation (Elite, Siege, Battle, Joust, Custom):

```bash
bash scripts/validate-mode-launch-matrix.sh
```

Wave-3 telemetry replay (admin/player correlation + round/map aggregates + map rotation markers):

```bash
bash scripts/replay-core-telemetry-wave3.sh
```

Wave-4 telemetry replay (reconnect/side-change + team aggregate/win-context + veto action/result markers):

```bash
bash scripts/replay-extended-telemetry-wave4.sh
```

Validation artifacts are saved under `logs/qa/`. Dev-sync artifacts are saved under `logs/dev/`.
Wave-3 replay writes deterministic artifacts with prefix `logs/qa/wave3-telemetry-<timestamp>-*`.
Wave-4 replay writes deterministic artifacts with prefix `logs/qa/wave4-telemetry-<timestamp>-*`.
By default it injects deterministic fixture envelopes in addition to captured plugin traffic so required markers can be validated without real client gameplay.

Compatibility note: deprecated `qa-*` script names remain available as wrappers and print migration warnings.

### Automated suite orchestrator (all automatable checks)

Run the top-level orchestrator to execute the current automatable QA coverage in one command:

```bash
bash scripts/test-automated-suite.sh
```

Default behavior:

- Covers `elite,joust` mode profiles.
- Runs launch validation + wave-4 plugin-only replay per mode.
- Runs strict wave-3 and wave-4 replay gate in `elite`.
- Runs admin response assertions, admin link-auth matrix checks (`missing|invalid|mismatch|valid`), admin payload capture assertions, and response/payload correlation checks.
- Exits non-zero when a required check fails.
- Keeps real-client combat callbacks out of automated pass/fail: `OnShoot`, `OnHit`, `OnNearMiss`, `OnArmorEmpty`, `OnCapture`.

Useful variants:

```bash
bash scripts/test-automated-suite.sh --modes elite
bash scripts/test-automated-suite.sh --modes elite,joust --with-mode-matrix-validation
```

Run artifacts are written under:

- `logs/qa/automated-suite-<timestamp>/run-manifest.json`
- `logs/qa/automated-suite-<timestamp>/coverage-inventory.json`
- `logs/qa/automated-suite-<timestamp>/check-results.ndjson`
- `logs/qa/automated-suite-<timestamp>/suite-summary.json`
- `logs/qa/automated-suite-<timestamp>/suite-summary.md`
- `logs/qa/automated-suite-<timestamp>/manual-handoff.md`

### Admin payload simulation (future server -> plugin)

Use this helper to simulate external server payloads sent to delegated admin communication methods:

```bash
bash scripts/simulate-admin-control-payloads.sh list-actions
bash scripts/simulate-admin-control-payloads.sh execute map.skip
bash scripts/simulate-admin-control-payloads.sh execute map.add mx_id=12345
bash scripts/simulate-admin-control-payloads.sh execute map.remove map_uid=SomeMapUid
bash scripts/simulate-admin-control-payloads.sh execute auth.grant target_login=SomePlayer auth_level=admin
bash scripts/simulate-admin-control-payloads.sh matrix target_login=SomePlayer map_uid=SomeMapUid mx_id=12345
bash scripts/simulate-admin-control-payloads.sh matrix link_auth_case=missing
bash scripts/simulate-admin-control-payloads.sh matrix link_auth_case=invalid link_server_login=crunkserver1 link_token=invalid-token
bash scripts/simulate-admin-control-payloads.sh matrix link_auth_case=mismatch link_server_login=crunkserver1 link_token=pixel-control-dev-link-token
bash scripts/simulate-admin-control-payloads.sh matrix link_auth_case=valid link_server_login=crunkserver1 link_token=pixel-control-dev-link-token
```

Behavior notes:

- Sends encrypted socket frames to ManiaControl communication service and calls:
  - `PixelControl.Admin.ListActions`
  - `PixelControl.Admin.ExecuteAction`
- Matrix mode replays the full delegated admin action catalog and writes artifacts under `logs/qa/admin-payload-sim-<timestamp>/`.
- Link-auth matrix options map to communication payload fields (`server_login`, `auth.mode`, `auth.token`) and assert deterministic outcomes via `matrix-validation.json`.
- Link-auth cases are: `missing`, `invalid`, `mismatch`, `valid`.
- Override output root with `PIXEL_SM_ADMIN_SIM_OUTPUT_ROOT` when you need run-local artifact directories.
- If no communication password/port is provided, the helper tries to auto-read socket settings from `mc_settings` and falls back to `127.0.0.1:31501` with empty password.

### Wave-5 manual real-client evidence workflow

Use this flow when running real ShootMania clients and collecting canonical wave-5 evidence.

1) Bootstrap manual evidence folder + session templates:

```bash
bash scripts/manual-wave5-session-bootstrap.sh --date 20260220 --session-id session-001 --focus "stack-join-baseline"
```

This creates:

- `logs/manual/wave5-real-client-20260220/MANUAL-TEST-MATRIX.md`
- `logs/manual/wave5-real-client-20260220/INDEX.md`
- `logs/manual/wave5-real-client-20260220/SESSION-session-001-{notes.md,payload.ndjson,evidence.md}`

2) Start local ACK stub capture for the session payload file:

```bash
bash scripts/manual-wave5-ack-stub.sh --output "logs/manual/wave5-real-client-20260220/SESSION-session-001-payload.ndjson"
```

3) Restart plugin transport targeting the local ACK stub:

```bash
PIXEL_CONTROL_API_BASE_URL=http://host.docker.internal:18080 bash scripts/dev-plugin-sync.sh
```

4) Run manual client scenarios, then export logs into the same session namespace:

```bash
bash scripts/manual-wave5-log-export.sh --manual-dir "logs/manual/wave5-real-client-20260220" --session-id session-001
```

5) Fill matrix scenario evidence rows (`W5-M01` through `W5-M10`) in `SESSION-session-001-evidence.md` and update `INDEX.md` status.

6) Validate manual evidence completeness:

```bash
bash scripts/manual-wave5-evidence-check.sh --manual-dir "logs/manual/wave5-real-client-20260220"
```

Optional dedicated-action trace (fixture-off plugin-only baseline):

```bash
PIXEL_SM_QA_TELEMETRY_INJECT_FIXTURES=0 bash scripts/replay-extended-telemetry-wave4.sh
```

Naming conventions:

- manual directory: `logs/manual/wave5-real-client-<YYYYMMDD>/`
- session id format: lowercase `[a-z0-9_-]` (example: `session-001`)
- required scenario ids in matrix/evidence: `W5-M01` ... `W5-M10`
- required per-session files:
  - `SESSION-<session-id>-notes.md`
  - `SESSION-<session-id>-payload.ndjson`
  - `SESSION-<session-id>-evidence.md`

## Key configuration

Most users only need to adjust these variables in `.env` (developer path) or `.env.production.local` (deployment path):

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
- Optional ManiaControl MasterAdmin login:
  - `PIXEL_SM_MANIACONTROL_MASTERADMIN_LOGIN`
- Optional plugin transport:
  - `PIXEL_CONTROL_API_BASE_URL`
  - `PIXEL_CONTROL_API_EVENT_PATH`
  - `PIXEL_CONTROL_AUTH_MODE` / `PIXEL_CONTROL_AUTH_VALUE`
  - `PIXEL_CONTROL_LINK_SERVER_URL`
  - `PIXEL_CONTROL_LINK_TOKEN`
- Optional replay knobs (`replay-core-telemetry-wave3.sh` and `replay-extended-telemetry-wave4.sh`):
  - `PIXEL_SM_QA_COMPOSE_FILES`
  - `PIXEL_SM_QA_XMLRPC_PORT`, `PIXEL_SM_QA_GAME_PORT`, `PIXEL_SM_QA_P2P_PORT`
  - `PIXEL_SM_QA_TELEMETRY_API_HOST`, `PIXEL_SM_QA_TELEMETRY_API_PORT`, `PIXEL_SM_QA_TELEMETRY_API_BASE_URL`
  - `PIXEL_SM_QA_TELEMETRY_STUB_LOCAL_HOST`, `PIXEL_SM_QA_TELEMETRY_STUB_LOCAL_URL`
  - `PIXEL_SM_QA_TELEMETRY_WAIT_SECONDS`, `PIXEL_SM_QA_TELEMETRY_KEEP_STACK_RUNNING`
  - `PIXEL_SM_QA_TELEMETRY_INJECT_FIXTURES` (`1` by default, set `0` for plugin-only capture)

All available variables and defaults are documented in `.env.example` and `.env.production.example`.

## Mode presets and title packs

Preset resolution order:

1. `PIXEL_SM_MATCHSETTINGS` explicit override (if non-empty)
2. `<PIXEL_SM_MODE>.txt` preset template fallback (`elite`, `siege`, `battle`, `joust`, `custom`)

Title-pack reference list (common ShootMania runtime names):

| Preset | Default matchsettings | Recommended title pack | Notes |
| --- | --- | --- | --- |
| `elite` | `elite.txt` | `SMStormElite@nadeolabs` | Auto-injection can fill maps when playlist is empty. |
| `siege` | `siege.txt` | `SMStorm@nadeo` | Requires explicit Siege-compatible map entries. |
| `battle` | `battle.txt` | `SMStormBattle@nadeolabs` | Requires local `SMStormBattle@nadeolabs.Title.Pack.gbx`. |
| `joust` | `joust.txt` | `SMStorm@nadeo` | Requires explicit Joust-compatible map entries and runtime script availability. |
| `royal` | use `custom.txt` | runtime Royal pack (commonly `SMRoyal@nadeolabs`) | No first-party `royal.txt` preset yet; run Royal via `PIXEL_SM_MODE=custom` with explicit script/maps. |
| `custom` | `custom.txt` | user-defined | Template is intentionally editable; set script/title/maps for your scenario. |

Mounted title-pack guidance:

- Place `.Title.Pack.gbx` assets under `PIXEL_SM_TITLEPACKS_SOURCE` (default `./TitlePacks`).
- Bootstrap copies mounted packs into runtime `runtime/server/Packs/` and validates the selected `PIXEL_SM_TITLE_PACK`.
- For modes without a dedicated preset template (currently Royal), use `custom.txt` + explicit script and map pool entries.

Title-pack asset note:

- Bootstrap validates `PIXEL_SM_TITLE_PACK` against runtime pack assets in `runtime/server/Packs/`.
- If a required pack is missing, startup fails fast with available pack names.
- Battle helper download:

```bash
bash scripts/fetch-titlepack.sh SMStormBattle@nadeolabs
```

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
