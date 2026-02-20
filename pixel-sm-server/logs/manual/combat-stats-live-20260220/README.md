# Combat Stats Live Manual Evidence (20260220)

This directory stores canonical manual-client validation artifacts for live combat stats QA.

## Scope

- Rocket shots (`rockets`)
- Laser shots (`lasers`)
- Total shots (`shots`)
- Hits (`hits`)
- Warmup exclusion: counters must not increase during warmup.

## Required artifact contract per session

- `MANUAL-TEST-MATRIX.md`
- `INDEX.md`
- `SESSION-<id>-notes.md`
- `SESSION-<id>-payload.ndjson`
- `SESSION-<id>-evidence.md`
- `SESSION-<id>-maniacontrol.log`
- `SESSION-<id>-shootmania.log`

## Suggested operator workflow

1. Start or refresh stack:
   - `bash scripts/dev-plugin-sync.sh`
2. Start ACK stub capture for this session payload file:
   - `bash scripts/manual-wave5-ack-stub.sh --output "/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-sm-server/logs/manual/combat-stats-live-20260220/SESSION-combat-stats-001-payload.ndjson"`
3. Point plugin transport at local ACK stub and sync plugin:
   - `PIXEL_CONTROL_API_BASE_URL=http://host.docker.internal:18080 bash scripts/dev-plugin-sync.sh`
4. Execute matrix scenarios from `MANUAL-TEST-MATRIX.md` with a remote real client on LAN.
5. Export logs for the session:
   - `bash scripts/manual-wave5-log-export.sh --manual-dir "/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-sm-server/logs/manual/combat-stats-live-20260220" --session-id combat-stats-001`
6. Update session evidence and index statuses.
7. Validate evidence completeness:
   - `bash scripts/manual-wave5-evidence-check.sh --manual-dir /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-sm-server/logs/manual/combat-stats-live-20260220`

## Deterministic scenario mapping

- Scenario ids CSM-M01 through CSM-M06 are defined in `MANUAL-TEST-MATRIX.md`.
- Session `combat-stats-001` uses:
  - notes anchors in `SESSION-combat-stats-001-notes.md`
  - verdict rows in `SESSION-combat-stats-001-evidence.md`
  - media naming: `SESSION-combat-stats-001-<scenario-id>-<timestamp>.<ext>`
