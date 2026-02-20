# Handoff - Autonomous execution wave 1 (2026-02-20)

## Scope delivered

- Plugin outage queue behavior with deterministic outage/recovery markers.
- Lifecycle admin-action payload emission (`admin_action`) for script match-flow callbacks.
- Combat stats payload structure and runtime counters (`kills`, `deaths`, `hits`, `shots`, `misses`, `rockets`, `lasers`, `accuracy`) plus combat dimensions and field availability flags.
- Pixel SM mode preset workflow updates for `elite`, `siege`, `battle`, `joust`, `custom`.
- Pixel SM README mode/title-pack documentation alignment.

## Changed files (wave implementation)

- `pixel-control-plugin/src/PixelControlPlugin.php`
- `pixel-control-plugin/src/Stats/PlayerCombatStatsStore.php`
- `pixel-control-plugin/README.md`
- `pixel-control-plugin/docs/event-contract.md`
- `pixel-control-plugin/docs/schema/envelope-2026-02-19.1.schema.json`
- `pixel-control-plugin/docs/schema/event-name-catalog-2026-02-19.1.json`
- `pixel-control-plugin/docs/schema/lifecycle-payload-2026-02-19.1.schema.json`
- `pixel-sm-server/scripts/bootstrap.sh`
- `pixel-sm-server/scripts/qa-mode-smoke.sh`
- `pixel-sm-server/.env.example`
- `pixel-sm-server/README.md`
- `pixel-sm-server/templates/matchsettings/joust.txt`
- `pixel-sm-server/templates/matchsettings/custom.txt`
- `PLAN-autonomous-execution-wave-1.md`
- `ROADMAP.md`
- `AGENTS.md`

## Verification commands executed

- Static/syntax checks (already completed in this wave):
  - `php -l pixel-control-plugin/src/PixelControlPlugin.php`
  - `php -l pixel-control-plugin/src/Stats/PlayerCombatStatsStore.php`
  - `bash -n pixel-sm-server/scripts/bootstrap.sh`
  - `bash -n pixel-sm-server/scripts/qa-mode-smoke.sh`
  - `xmlstarlet val -w pixel-sm-server/templates/matchsettings/*.txt`
- Smoke checks (already completed in this wave):
  - `bash pixel-sm-server/scripts/qa-launch-smoke.sh`
  - `bash pixel-sm-server/scripts/qa-mode-smoke.sh`
- Controlled outage/recovery validation:
  - Stopped local API stub (port `18080`) to force transport failure.
  - Confirmed queue outage markers in ManiaControl logs.
  - Restarted API stub and confirmed recovery+flush markers.
- Admin/stats payload evidence validation:
  - Ran API capture stub on `0.0.0.0:18080` that returns `{"ack":{"status":"accepted"}}` and stores envelopes.
  - Triggered server-side actions through in-container dedicated API client:
    - `connectFakePlayer`, `restartMap`, `nextMap`, `disconnectFakePlayer('*')`.
  - Confirmed captured lifecycle events containing `admin_action` and captured combat event containing `player_counters` and combat dimensions keys.

## Evidence artifacts

- Outage/recovery markers:
  - `pixel-sm-server/runtime/server/ManiaControl/ManiaControl.log`
  - Key markers present:
    - `[PixelControl][queue][outage_entered]`
    - `[PixelControl][queue][retry_scheduled]`
    - `[PixelControl][queue][outage_recovered]`
    - `[PixelControl][queue][recovery_flush_complete]`
- API payload captures:
  - `pixel-sm-server/logs/dev/wave1-api-capture-20260220-024802.ndjson`
    - Contains lifecycle `admin_action` envelopes and one combat scores envelope with `player_counters` + dimensions/availability keys.
  - `pixel-sm-server/logs/dev/wave1-api-capture-20260220-025449.ndjson`
    - Contains post-outage backlog/recovery connectivity envelopes.
- Prior QA artifacts (wave):
  - `pixel-sm-server/logs/qa/qa-build-20260220-023447.log`
  - `pixel-sm-server/logs/qa/qa-startup-20260220-023447.log`
  - `pixel-sm-server/logs/qa/qa-shootmania-20260220-023447.log`
  - `pixel-sm-server/logs/qa/qa-env-20260220-023447.env`

## Manual checklist status (P7)

- Manual test A (stack + join): automated equivalent validated via smoke and plugin load marker.
- Manual test B (admin action): validated through forced map-flow transitions (`restartMap`/`nextMap`) and captured `admin_action` payloads.
- Manual test C (combat shots/hits/misses + kill/death exchange): **still pending true gameplay interaction** from a real client session.
  - Current evidence includes combat `OnScores` stats payload shape and counters for fake players, but not real shot/hit/kill exchanges.
  - User decision at this checkpoint: gameplay tests will be executed later.
- Manual test D (outage recovery): validated with controlled unreachable endpoint and restored endpoint flush markers.

## Next executor starting point

1. Complete Manual test C with a real ShootMania client session to produce non-zero shot/hit/miss/kill/death counters.
2. Capture evidence path(s) for that session in this handoff file and `PLAN-autonomous-execution-wave-1.md` notes.
3. If desired, normalize combat callback source naming for typed structures (currently observed one combat event name sourced from callback class name).
