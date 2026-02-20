# Session combat-stats-001 Notes

- Date: 20260220
- Scenario focus: live-combat-stats
- Operator: OpenCode executor
- User actor: remote real client on second LAN machine

## Prerequisites

- Stack healthy (`bash scripts/qa-launch-smoke.sh` passed recently)
- Local ACK capture target configured
- Real ShootMania client connected to local server from second LAN machine
- Matrix loaded from `MANUAL-TEST-MATRIX.md`

## Join endpoint for this session

- Host LAN IP: `192.168.1.4`
- Game port (initial): `57100`
- P2P port (initial): `57200`
- Game port (fallback active): `2350`
- P2P port (fallback active): `3450`
- Runtime mode: `battle`
- URL marker: `maniaplanet://#join=crunkserver1@SMStormBattle@nadeolabs`

## CSM-M01 stack-join-baseline

- Client/admin actions:
  - Attempted join via URL marker `maniaplanet://#join=crunkserver1@SMStormBattle@nadeolabs` from second LAN machine.
- Expected payload/log keys:
- Observed artifacts:
  - Client error dialog: connection to `176.150.133.92:57100` failed/disconnected (error 148).
  - Host diagnostics: ports are correctly published and local reachability is OK (`57100`/`57000`).
  - Runtime still advertises login-based public URL; this likely routes remote client through non-working NAT loopback path.
  - Second attempt (direct LAN target) failed with fallback error: `Connection to 192.168.1.4:2350 failed or disconnected from server (1073741824)`.
  - Troubleshooting fallback applied: runtime switched to legacy ports (`2350`/`3450`) and stack restarted healthy.
  - Latest user observation: server appears in local server list, but join flow remains stuck in infinite loading and never reaches spawn.
  - Retry with fallback runtime succeeded: user joined from local list and reached spawn.
  - Live server log markers during successful join:
    - `Connection of a new player: onepiece2000(169.254.85.143:2350)`
    - `Connection of a new player: strainkse(169.254.85.143:2351)`
- Verdict:
  - Pass (after fallback troubleshooting)

## CSM-M02 warmup-leakage-probe

- Client/admin actions:
  - User feedback: current battle runtime does not allow laser shots on this map (only rocket + nucleus visible), blocking scoped laser validation.
- Expected payload/log keys:
- Observed artifacts:
  - Baseline capture initialized before manual actions:
    - ACK stub active on `127.0.0.1:18080`.
    - Baseline payload rows contain lifecycle/connectivity only (`warmup.status`, `plugin_registration`, `beginmatch`, `plugin_heartbeat`).
    - No combat rows present yet; counters treated as zero baseline.
- Verdict:
  - In progress (mode-switch required)

## CSM-M03 live-rocket-loop

- Client/admin actions:
- Expected payload/log keys:
- Observed artifacts:
- Verdict:

## CSM-M04 live-laser-loop

- Client/admin actions:
- Expected payload/log keys:
- Observed artifacts:
- Verdict:

## CSM-M05 live-mixed-loop

- Client/admin actions:
- Expected payload/log keys:
- Observed artifacts:
- Verdict:

## CSM-M06 threshold-review

- Client/admin actions:
- Expected payload/log keys:
- Observed artifacts:
- Verdict:

## Mismatches / follow-up

- [Record unexpected behavior, reproduction steps, and correction hints]
