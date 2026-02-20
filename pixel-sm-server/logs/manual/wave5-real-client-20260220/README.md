# Wave 5 Real-Client Manual Evidence (20260220)

This directory stores canonical manual-client validation artifacts for wave-5 closure.

## Required artifact contract per session

- 'MANUAL-TEST-MATRIX.md'
- 'INDEX.md'
- 'SESSION-<id>-notes.md'
- 'SESSION-<id>-payload.ndjson'
- 'SESSION-<id>-evidence.md'
- linked screenshot/video references from session evidence notes

The manual matrix file defines scenario prerequisites, operator actions, expected payload/log fields,
pass/fail criteria, and deterministic artifact destinations for each scenario id.

## Plugin-only fixture-off capture mode (deterministic baseline)

Run without fixture injection when you need plugin-only envelopes:

PIXEL_SM_QA_TELEMETRY_INJECT_FIXTURES=0 bash scripts/qa-wave4-telemetry-replay.sh

## Suggested operator workflow

1. Start or refresh stack:
   - bash scripts/dev-plugin-sync.sh
2. Start ACK stub capture for this session:
   - bash scripts/manual-wave5-ack-stub.sh --output "/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-sm-server/logs/manual/wave5-real-client-20260220/SESSION-session-001-payload.ndjson"
3. Point plugin transport at local ACK stub and re-sync plugin:
   - PIXEL_CONTROL_API_BASE_URL=http://host.docker.internal:18080 bash scripts/dev-plugin-sync.sh
4. Run real-client gameplay scenarios and collect screenshot/video references into session evidence notes.
5. Export logs for this session:
   - bash scripts/manual-wave5-log-export.sh --manual-dir "/Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-sm-server/logs/manual/wave5-real-client-20260220" --session-id session-001
6. Update INDEX.md status for session-001 and fill all scenario rows in SESSION-session-001-evidence.md.
7. Validate evidence completeness:
   - bash scripts/manual-wave5-evidence-check.sh --manual-dir /Users/louislacoste/Documents/code/freelance/shootmania-esport/pixel-control/pixel-sm-server/logs/manual/wave5-real-client-20260220

Optional plugin-only dedicated-action trace (fixture-off):

- PIXEL_SM_QA_TELEMETRY_INJECT_FIXTURES=0 bash scripts/qa-wave4-telemetry-replay.sh

## Deterministic scenario mapping

- Scenario ids W5-M01 through W5-M10 are defined in MANUAL-TEST-MATRIX.md.
- For session session-001, use:
  - notes anchors in SESSION-session-001-notes.md
  - scenario rows in SESSION-session-001-evidence.md
  - media names: SESSION-session-001-<scenario-id>-<timestamp>.<ext>
