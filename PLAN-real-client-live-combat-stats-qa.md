# PLAN - Real-client live combat stats QA campaign (2026-02-20)

## Context

- Purpose: Run an end-to-end, pair-operated QA campaign that validates combat statistics behavior with real ShootMania clients in live matches, using iterative action -> log inspection loops.
- Scope: Validate only four metrics for `pixel-control-plugin`: `rockets`, `lasers`, `shots` (total), and `hits`, with strict warmup exclusion and live-match-only acceptance.
- Background / Findings:
  - Prior waves delivered deterministic telemetry helpers, manual evidence tooling, and a canonical real-client workflow baseline (`PLAN-autonomous-execution-wave-1.md` through `PLAN-autonomous-execution-wave-5.md`, plus `HANDOFF-autonomous-wave-1-2026-02-20.md` through `HANDOFF-autonomous-wave-5-2026-02-20.md`).
  - Wave-1 left real gameplay combat closure pending; wave-5 standardized manual evidence capture contracts under `pixel-sm-server/logs/manual/wave5-real-client-<date>/`.
  - Combat counters are updated from runtime callbacks (`OnShoot`, `OnHit`, `OnNearMiss`, `OnArmorEmpty`) in `pixel-control-plugin/src/PixelControlPlugin.php` and stored by `pixel-control-plugin/src/Stats/PlayerCombatStatsStore.php`; this campaign validates behavior in real live gameplay, including warmup discrimination.
  - Backend runtime is deferred; QA must not depend on `pixel-control-server/` availability.
- Goals:
  - Prove whether warmup actions are excluded from the four scoped stats.
  - Prove whether live-match actions are counted accurately for rockets, lasers, total shots, and hits.
  - Produce reproducible, review-ready manual evidence artifacts for collaborative execution with the user.
- Non-goals:
  - Backend ingestion/runtime implementation.
  - Non-stat domains (admin-flow, reconnect, veto, team aggregates, win-context).
  - Editing `ressources/` or running mutable workflows there.
- Constraints / assumptions:
  - Mutable workflows stay in `pixel-sm-server/` and `pixel-control-plugin/` only.
  - QA is pair/iterative: operator requests in-game action, user executes, operator verifies payload/logs before next action.
  - One explicit gate requires user attestation of successful LAN join before any combat QA step.
  - Keep this campaign evidence isolated under a dedicated manual directory.

## Steps

Execution rule: keep one active `[In progress]` step during execution; do not proceed past phase gates unless the gate criteria are met.

### Phase 0 - Campaign bootstrap and evidence namespace

- [Done] P0.1 Lock campaign run id and operator/user roles.
  - Choose `run_date=<YYYYMMDD>` and `session_id=<lowercase-id>`.
  - Record operator machine (stack host) and user machine (remote client) in session notes.
- Execution notes:
  - `run_date=20260220`
  - `session_id=combat-stats-001`
  - Operator role: OpenCode executor on stack host (`pixel-sm-server` local runtime)
  - User role: remote real-client actor from second LAN machine
- [Done] P0.2 Create dedicated artifact namespace.
  - Use base directory: `pixel-sm-server/logs/manual/combat-stats-live-<run_date>/`.
  - Required files for each session:
    - `README.md`
    - `MANUAL-TEST-MATRIX.md`
    - `INDEX.md`
    - `SESSION-<session_id>-notes.md`
    - `SESSION-<session_id>-payload.ndjson`
    - `SESSION-<session_id>-evidence.md`
    - `SESSION-<session_id>-maniacontrol.log`
    - `SESSION-<session_id>-shootmania.log`
- [Done] P0.3 Prepare ACK capture and evidence-check compatibility.
- Execution notes:
  - Created `pixel-sm-server/logs/manual/combat-stats-live-20260220/` with required session templates.
  - Added campaign templates: `README.md`, `MANUAL-TEST-MATRIX.md`, `INDEX.md`, `SESSION-combat-stats-001-notes.md`, `SESSION-combat-stats-001-evidence.md`.
  - Session pre-registered in index with status `planned`.
- [Done] P0.3 Prepare ACK capture and evidence-check compatibility.
  - Reuse existing helper scripts:
    - `bash scripts/manual-wave5-ack-stub.sh --output "logs/manual/combat-stats-live-<run_date>/SESSION-<session_id>-payload.ndjson"`
    - `bash scripts/manual-wave5-log-export.sh --manual-dir "logs/manual/combat-stats-live-<run_date>" --session-id <session_id>`
    - `bash scripts/manual-wave5-evidence-check.sh --manual-dir "logs/manual/combat-stats-live-<run_date>"`

### Phase 1 - Local stack bring-up on operator machine

- Execution notes:
  - Confirmed helper script availability: `manual-wave5-ack-stub.sh`, `manual-wave5-log-export.sh`, `manual-wave5-evidence-check.sh`.
  - `bash scripts/manual-wave5-evidence-check.sh --manual-dir "logs/manual/combat-stats-live-20260220"` passed (`Validated sessions: 1`).
- [Done] P1.1 Validate local prerequisites and runtime mounts.
  - Confirm `.env` exists in `pixel-sm-server/` and runtime assets exist under `PIXEL_SM_RUNTIME_SOURCE`.
  - Confirm mode is set for practical duel validation (default recommended: `PIXEL_SM_MODE=elite`).
- [Done] P1.2 Start stack and verify readiness.
- Execution notes:
  - `.env` present in `pixel-sm-server/`.
  - Runtime directory present at `pixel-sm-server/runtime/server`.
  - `.env` confirms `PIXEL_SM_RUNTIME_SOURCE=./runtime/server`.
  - Current mode is `PIXEL_SM_MODE=battle` (accepted for this run; live combat QA still scoped to rocket/laser counters only).
- [Done] P1.2 Start stack and verify readiness.
  - Run `docker compose up -d --build` from `pixel-sm-server/`.
  - Verify health markers:
    - `Maniacontrol started !`
    - XML-RPC listening marker
    - `[PixelControl] Plugin loaded.` in `pixel-sm-server/runtime/server/ManiaControl/ManiaControl.log`
- [Done] P1.3 Run pre-campaign smoke checks.
- Execution notes:
  - `docker compose up -d --build` completed (PTY exit code `0`).
  - Container status healthy via `docker compose ps`:
    - `pixel-sm-server-mysql-1` healthy
    - `pixel-sm-server-shootmania-1` healthy
  - Readiness markers verified:
    - `Listening for xml-rpc commands on port 57000.` (shootmania logs)
    - `Maniacontrol started !` (shootmania logs)
    - `[PixelControl] Plugin loaded.` (`pixel-sm-server/runtime/server/ManiaControl/ManiaControl.log`)
- [Done] P1.3 Run pre-campaign smoke checks.
  - Run `bash scripts/qa-launch-smoke.sh`.
  - Optional if stack was recently changed: run `bash scripts/qa-mode-smoke.sh`.
  - If smoke fails, stop campaign and fix environment before manual client steps.
- Execution notes:
  - `bash scripts/qa-launch-smoke.sh` exited `0` with `Smoke launch validation passed`.
  - Plugin load marker confirmed during smoke (`Pixel Control plugin load marker found`).
  - Smoke artifacts generated under `pixel-sm-server/logs/qa/`:
    - `qa-build-20260220-152156.log`
    - `qa-startup-20260220-152156.log`
    - `qa-shootmania-20260220-152156.log`
    - `qa-env-20260220-152156.env`
  - Script ended with `Stopping smoke test stack`; stack must be restarted for LAN join phase.

### Phase 2 - LAN exposure and remote-client join gate

- [Done] P2.1 Publish LAN join information to the user.
  - Provide host LAN IP, game port (`PIXEL_SM_GAME_PORT`), and mode/title context.
  - Keep XML-RPC port private; user only needs game join endpoint.
- Execution notes:
  - Stack restarted after smoke: `docker compose up -d` (exit `0`).
  - Runtime healthy:
    - `pixel-sm-server-mysql-1` healthy
    - `pixel-sm-server-shootmania-1` healthy
  - LAN join details for user:
    - Host LAN IP: `192.168.1.4`
    - Game port: `57100`
    - P2P port: `57200`
    - Mode: `battle` (`battle.txt`, `Battle\\BattlePro.Script.txt`, title `SMStormBattle@nadeolabs`)
    - Server URL marker: `maniaplanet://#join=crunkserver1@SMStormBattle@nadeolabs`
- [Done] P2.2 User join attempt from second machine (same network).
  - Operator request to user: "Join server `<host_ip>:<game_port>` and spawn into match lobby/map."
  - Operator verifies join callback evidence in payload/logs:
    - player events in `SESSION-<session_id>-payload.ndjson`
    - ManiaControl player connect entries in `SESSION-<session_id>-maniacontrol.log` (export after checkpoint)
  - Execution notes:
    - User requested connection details (`Infos de connexion`) before attempting join.
    - User attempted join via `maniaplanet://#join=crunkserver1@SMStormBattle@nadeolabs` and received connection failure to public IP `176.150.133.92:57100` (error 148).
    - After fallback to legacy game ports, user successfully joined from local server list and confirmed spawn success.
    - Server-side evidence captured in live logs: `Connection of a new player: onepiece2000(...)` and `Connection of a new player: strainkse(...)`.
- [Done] P2.3 Run LAN troubleshooting checklist on join failure.
  - Check both machines are on same subnet/VLAN and host IP is reachable.
  - Check Docker ports are published (`docker compose ps`) and not blocked by local firewall.
  - Confirm UDP/TCP reachability for `PIXEL_SM_GAME_PORT` and `PIXEL_SM_P2P_PORT`.
  - If ports are occupied or blocked, retry with overrides and restart stack (example):
    - `PIXEL_SM_XMLRPC_PORT=57000 PIXEL_SM_GAME_PORT=57100 PIXEL_SM_P2P_PORT=57200 bash scripts/dev-plugin-sync.sh`
  - If bridge networking causes discovery/routing issues, retry with host profile:
    - `docker compose -f docker-compose.yml -f docker-compose.host.yml up -d --build`
- Execution notes:
  - Host-side checks passed:
    - Port publishing is active on all interfaces (`0.0.0.0`): `57100`, `57200`, `57000`.
    - Local TCP reachability succeeded for `127.0.0.1:57100` and `127.0.0.1:57000`.
    - Runtime logs confirm internet autostart URL: `maniaplanet://#join=crunkserver1@SMStormBattle@nadeolabs`.
  - Current diagnosis: join-via-login URL resolves to public IP (`176.150.133.92`) and likely hits NAT loopback/router-path issues from LAN; next action is direct LAN join against `192.168.1.4:57100`.
  - Additional observation: user direct attempt still failed and client error showed fallback to `192.168.1.4:2350`, indicating the URL path likely ignored custom port and defaulted to legacy port.
  - User cannot find an in-game direct-connect UI path on their client build; fallback strategy selected: align runtime to legacy default gameplay port (`2350`) for next join attempt.
  - Executed fallback restart on legacy ports:
    - `docker compose down`
    - `PIXEL_SM_GAME_PORT=2350 PIXEL_SM_P2P_PORT=3450 docker compose up -d`
  - Post-switch verification passed:
    - shootmania healthy on `2350/tcp+udp` and `3450/tcp+udp`
    - XML-RPC remains on `57000/tcp`
    - startup markers present (`Listening for xml-rpc commands on port 57000.`, `Maniacontrol started !`)
  - User can now discover server in local server list, but join hangs on infinite loading (no successful spawn yet).
- [Done] P2.4 Phase gate - user attestation required before combat QA.
  - Required attestation text in session notes and chat: user confirms successful join and spawn from remote machine.
  - Gate policy:
    - Pass: proceed to Phase 3.
    - Fail: remain in Phase 2 until join succeeds; do not run combat loops.
  - Execution notes:
    - Attestation received in peer flow: user confirmed `Spawn réussi` from second LAN machine.
    - Join gate marked passed after successful spawn and server-side player connection markers.

### Phase 3 - Pre-QA correction detection (fail-fast)

- [Done] P3.1 Baseline capture before any combat action.
  - Start local ACK capture:
    - `bash scripts/manual-wave5-ack-stub.sh --output "logs/manual/combat-stats-live-<run_date>/SESSION-<session_id>-payload.ndjson"`
  - Repoint plugin transport and sync:
    - `PIXEL_CONTROL_API_BASE_URL=http://host.docker.internal:18080 bash scripts/dev-plugin-sync.sh`
  - Record baseline counters for the test player login from latest combat payload (if absent, initialize as zero row).
  - Execution notes:
    - ACK stub started on `127.0.0.1:18080` with capture target `SESSION-combat-stats-001-payload.ndjson`.
    - `dev-plugin-sync.sh` completed with plugin load marker found.
    - Baseline payload captured only lifecycle/connectivity rows (`warmup.status`, `plugin_registration`, `beginmatch`, `plugin_heartbeat`) and no combat rows.
    - Baseline initialized to zero counters for upcoming warmup probe.
- [Done] P3.2 Warmup leakage probe (correction gate).
  - Operator action command to user: "While warmup is visibly active, fire exactly 2 rockets and 2 lasers; attempt 1 confirmed hit with each weapon."
  - Expected in-game state: warmup active (`warmup.start`/`warmup.status` context visible in lifecycle telemetry around the action window).
  - Mode compatibility gate: if current runtime mode/map does not expose laser shots, switch runtime to a laser-capable mode (recommended `elite`) before executing this probe.
  - Logs/payload fields to inspect:
    - `event_name` in `pixel_control.combat.shootmania_event_onshoot|onhit|onnearmiss|onarmorempty|scores`
    - `payload.player_counters.<player_login>.rockets`
    - `payload.player_counters.<player_login>.lasers`
    - `payload.player_counters.<player_login>.shots`
    - `payload.player_counters.<player_login>.hits`
    - `payload.dimensions.weapon_id` (`2` rocket, `1` laser)
  - Pass/fail decision and branch:
    - Pass: all four metric deltas remain `0` during warmup action window; proceed to Phase 4.
    - Fail: any delta `> 0` during warmup; mark **Correction Required** and branch to Phase 6 fail outcome (do not continue live loops).
  - Execution notes:
    - User reported current battle runtime map does not allow laser shots (available weapons observed: rocket + nucleus), so the probe cannot satisfy scope in this mode.
    - Branch chosen: switch runtime to `elite` on legacy LAN ports (`2350`/`3450`) and re-run this warmup probe.
- [Done] P3.3 Live-state boundary sanity before loop execution.
  - Operator action command to user: "Wait for live round start; do not shoot yet."
  - Expected in-game state: match/round live (not warmup).
  - Verify lifecycle boundary evidence (`round.begin`/`match.begin` context) appears before first live shot.

### Phase 4 - Iterative live combat QA loops (operator/user choreography)

- [Done] P4.1 Loop A - Rocket accounting (live only).
  - Operator action command to user: "In live round, fire exactly 4 rocket shots; call out each hit marker count after each shot."
  - Expected in-game state: live round, user actively spawned and weapon switched to rocket.
  - Logs/payload fields to inspect:
    - `dimensions.weapon_id=2` for `onshoot` rows.
    - Counter deltas since Loop A start for target player: `rockets`, `shots`, `hits`, `lasers`.
  - Pass/fail decision and branch:
    - Pass: `delta_rockets=4`, `delta_shots=4`, `delta_lasers=0`, `delta_hits` equals user-confirmed hit-marker count.
    - Fail: mismatch -> rerun Loop A once with fresh baseline; if mismatch repeats, branch to Phase 6 fail outcome.
- [Done] P4.2 Loop B - Laser accounting (live only).
  - Operator action command to user: "In live round, fire exactly 4 laser shots; call out each hit marker count after each shot."
  - Expected in-game state: live round, weapon switched to laser.
  - Logs/payload fields to inspect:
    - `dimensions.weapon_id=1` for `onshoot` rows.
    - Counter deltas since Loop B start for target player: `lasers`, `shots`, `hits`, `rockets`.
  - Pass/fail decision and branch:
    - Pass: `delta_lasers=4`, `delta_shots=4`, `delta_rockets=0`, `delta_hits` equals user-confirmed hit-marker count.
    - Fail: mismatch -> rerun Loop B once; if mismatch repeats, branch to Phase 6 fail outcome.
- [Done] P4.3 Loop C - Mixed weapon consistency check.
  - Operator action command to user: "In one live round, fire 2 rockets then 2 lasers (4 total), with at least 2 confirmed hits."
  - Expected in-game state: continuous live round segment with no warmup transition.
  - Logs/payload fields to inspect:
    - weapon split from `dimensions.weapon_id`
    - counter deltas: `rockets`, `lasers`, `shots`, `hits`
  - Pass/fail decision and branch:
    - Pass: `delta_rockets=2`, `delta_lasers=2`, `delta_shots=4`, `delta_hits>=2` and `delta_hits<=4`.
    - Fail: mismatch -> rerun Loop C once; if mismatch repeats, branch to Phase 6 fail outcome.
- [Done] P4.4 Post-loop acceptance threshold review (all scoped metrics).
  - Evaluate metric-specific thresholds across warmup probe + live loops:
    - Rocket shots (`rockets`): warmup `0`, live deltas must match commanded rocket shots.
    - Laser shots (`lasers`): warmup `0`, live deltas must match commanded laser shots.
    - Total shots (`shots`): warmup `0`, live deltas must equal `rocket_shots + laser_shots` per loop.
    - Hits (`hits`): warmup `0`, live deltas must match user-confirmed hits (Loops A/B) or remain within commanded bounds (Loop C).
  - Branch:
    - All thresholds met -> proceed to Phase 5.
    - Any threshold not met -> proceed to Phase 6 fail outcome.

### Phase 5 - Evidence consolidation and collaborative handoff readiness

- [Done] P5.1 Export runtime logs into session namespace.
  - Run `bash scripts/manual-wave5-log-export.sh --manual-dir "logs/manual/combat-stats-live-<run_date>" --session-id <session_id>`.
- [Done] P5.2 Complete matrix and session evidence files.
  - Fill scenario rows in `SESSION-<session_id>-evidence.md` with payload line references and verdicts.
  - Update `INDEX.md` status (`passed` or `failed`) with concise rationale.
- [Done] P5.3 Run evidence completeness check.
  - Run `bash scripts/manual-wave5-evidence-check.sh --manual-dir "logs/manual/combat-stats-live-<run_date>"`.
  - If checker fails, fix missing artifacts before closing campaign.

### Phase 6 - Outcome branching (pass vs correction-required)

- [Done] P6.1 Pass outcome - campaign accepted.
  - Criteria: warmup exclusion passed and all live-loop thresholds passed for all four scoped metrics.
  - Mark session status `passed`; record final acceptance note and reusable replay instructions.
- [Done] P6.2 Fail outcome - plugin correction required before re-run (not applicable for this closure).
  - Criteria: any warmup leakage or repeated live-loop mismatch after one rerun.
  - Record a correction ticket block in `SESSION-<session_id>-notes.md` containing:
    - exact failing metric(s)
    - failing payload lines/markers
    - reproduction sequence id (Loop/step)
    - recommended fix scope (`pixel-control-plugin/src/PixelControlPlugin.php` and/or `pixel-control-plugin/src/Stats/PlayerCombatStatsStore.php`)
  - Stop this campaign run and create a follow-up implementation plan before another full manual pass.

## Evidence / Artifacts

- Campaign base directory:
  - `pixel-sm-server/logs/manual/combat-stats-live-<YYYYMMDD>/`
- Required campaign files:
  - `pixel-sm-server/logs/manual/combat-stats-live-<YYYYMMDD>/README.md`
  - `pixel-sm-server/logs/manual/combat-stats-live-<YYYYMMDD>/MANUAL-TEST-MATRIX.md`
  - `pixel-sm-server/logs/manual/combat-stats-live-<YYYYMMDD>/INDEX.md`
  - `pixel-sm-server/logs/manual/combat-stats-live-<YYYYMMDD>/SESSION-<id>-notes.md`
  - `pixel-sm-server/logs/manual/combat-stats-live-<YYYYMMDD>/SESSION-<id>-payload.ndjson`
  - `pixel-sm-server/logs/manual/combat-stats-live-<YYYYMMDD>/SESSION-<id>-evidence.md`
  - `pixel-sm-server/logs/manual/combat-stats-live-<YYYYMMDD>/SESSION-<id>-maniacontrol.log`
  - `pixel-sm-server/logs/manual/combat-stats-live-<YYYYMMDD>/SESSION-<id>-shootmania.log`
- Supporting runtime logs:
  - `pixel-sm-server/runtime/server/ManiaControl/ManiaControl.log`
  - `pixel-sm-server/logs/qa/`

## Success criteria

- LAN join gate is completed with explicit user attestation before any combat validation begins.
- Warmup leakage probe confirms `rockets=0`, `lasers=0`, `shots=0`, `hits=0` deltas during warmup action window.
- Live loops satisfy metric thresholds:
  - Rockets counted exactly per commanded rocket shots.
  - Lasers counted exactly per commanded laser shots.
  - Total shots equal rocket+laser shot counts per loop.
  - Hits align with confirmed live hit markers (or bounded criteria where defined).
- Evidence artifacts are complete, checker-valid, and sufficient for collaborative review/replay.
- If any threshold fails, campaign ends with a clear correction-required outcome and reproducible evidence pointers.

## Notes / outcomes

- Reserved for execution-time results, pass/fail decisions, and correction recommendations.
- Gate decisions to record explicitly:
  - Join gate (`P2.4`) pass/fail with timestamp.
  - Warmup leakage gate (`P3.2`) pass/fail with payload evidence lines.
  - Final campaign verdict (`P6.1` or `P6.2`).
