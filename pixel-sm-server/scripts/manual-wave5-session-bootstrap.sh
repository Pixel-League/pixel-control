#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

MANUAL_DATE="${PIXEL_SM_MANUAL_DATE:-$(date +%Y%m%d)}"
SESSION_ID="${PIXEL_SM_MANUAL_SESSION_ID:-session-001}"
SESSION_FOCUS="${PIXEL_SM_MANUAL_SESSION_FOCUS:-baseline}"
MANUAL_BASE_DIR="${PIXEL_SM_MANUAL_BASE_DIR:-${PROJECT_DIR}/logs/manual}"
FORCE_OVERWRITE="${PIXEL_SM_MANUAL_FORCE_OVERWRITE:-0}"

log() {
  printf '[pixel-sm-manual-wave5] %s\n' "$1"
}

usage() {
  cat <<'EOF'
Usage:
  bash scripts/manual-wave5-session-bootstrap.sh [--date YYYYMMDD] [--session-id ID] [--focus TEXT] [--force]

Options:
  --date        Manual evidence date folder suffix (default: today, YYYYMMDD)
  --session-id  Session identifier used in template files (default: session-001)
  --focus       Short scenario focus note for INDEX row (default: baseline)
  --force       Overwrite existing template files
EOF
}

normalize_session_id() {
  raw_value="$1"
  normalized_value="$(printf '%s' "$raw_value" | tr '[:upper:]' '[:lower:]' | tr -cs 'a-z0-9_-' '-')"
  normalized_value="${normalized_value#-}"
  normalized_value="${normalized_value%-}"

  if [ -z "$normalized_value" ]; then
    normalized_value="session-001"
  fi

  printf '%s' "$normalized_value"
}

write_template() {
  target_file="$1"
  if [ -f "$target_file" ] && [ "$FORCE_OVERWRITE" != "1" ]; then
    log "Keeping existing file: ${target_file}"
    return
  fi

  cat > "$target_file"
  log "Wrote template: ${target_file}"
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --date)
      if [ "$#" -lt 2 ]; then
        log "Missing value for --date"
        exit 1
      fi
      MANUAL_DATE="$2"
      shift 2
      ;;
    --session-id)
      if [ "$#" -lt 2 ]; then
        log "Missing value for --session-id"
        exit 1
      fi
      SESSION_ID="$2"
      shift 2
      ;;
    --focus)
      if [ "$#" -lt 2 ]; then
        log "Missing value for --focus"
        exit 1
      fi
      SESSION_FOCUS="$2"
      shift 2
      ;;
    --force)
      FORCE_OVERWRITE=1
      shift
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      log "Unknown option: $1"
      usage
      exit 1
      ;;
  esac
done

SESSION_ID="$(normalize_session_id "$SESSION_ID")"
MANUAL_DIR="${MANUAL_BASE_DIR}/wave5-real-client-${MANUAL_DATE}"

README_FILE="${MANUAL_DIR}/README.md"
INDEX_FILE="${MANUAL_DIR}/INDEX.md"
MATRIX_FILE="${MANUAL_DIR}/MANUAL-TEST-MATRIX.md"
SESSION_NOTES_FILE="${MANUAL_DIR}/SESSION-${SESSION_ID}-notes.md"
SESSION_PAYLOAD_FILE="${MANUAL_DIR}/SESSION-${SESSION_ID}-payload.ndjson"
SESSION_EVIDENCE_FILE="${MANUAL_DIR}/SESSION-${SESSION_ID}-evidence.md"

mkdir -p "$MANUAL_DIR"

write_template "$README_FILE" <<EOF
# Wave 5 Real-Client Manual Evidence (${MANUAL_DATE})

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

PIXEL_SM_QA_TELEMETRY_INJECT_FIXTURES=0 bash scripts/replay-extended-telemetry-wave4.sh

## Suggested operator workflow

1. Start or refresh stack:
   - bash scripts/dev-plugin-sync.sh
2. Start ACK stub capture for this session:
   - bash scripts/manual-wave5-ack-stub.sh --output "${MANUAL_DIR}/SESSION-${SESSION_ID}-payload.ndjson"
3. Point plugin transport at local ACK stub and re-sync plugin:
   - PIXEL_CONTROL_API_BASE_URL=http://host.docker.internal:18080 bash scripts/dev-plugin-sync.sh
4. Run real-client gameplay scenarios and collect screenshot/video references into session evidence notes.
5. Export logs for this session:
   - bash scripts/manual-wave5-log-export.sh --manual-dir "${MANUAL_DIR}" --session-id ${SESSION_ID}
6. Update INDEX.md status for ${SESSION_ID} and fill all scenario rows in SESSION-${SESSION_ID}-evidence.md.
7. Validate evidence completeness:
   - bash scripts/manual-wave5-evidence-check.sh --manual-dir ${MANUAL_DIR}

Optional plugin-only dedicated-action trace (fixture-off):

- PIXEL_SM_QA_TELEMETRY_INJECT_FIXTURES=0 bash scripts/replay-extended-telemetry-wave4.sh

## Deterministic scenario mapping

- Scenario ids W5-M01 through W5-M10 are defined in MANUAL-TEST-MATRIX.md.
- For session ${SESSION_ID}, use:
  - notes anchors in SESSION-${SESSION_ID}-notes.md
  - scenario rows in SESSION-${SESSION_ID}-evidence.md
  - media names: SESSION-${SESSION_ID}-<scenario-id>-<timestamp>.<ext>
EOF

write_template "$MATRIX_FILE" <<EOF
# Wave 5 Manual Test Matrix (${MANUAL_DATE})

Use this matrix for real-client validation. Run all scenarios before marking wave-5 manual closure complete.

## Common prerequisites

- CP-1: bash scripts/validate-dev-stack-launch.sh passed recently.
- CP-2: local ACK stub capture is running for the target session payload file.
- CP-3: plugin transport targets the stub (PIXEL_CONTROL_API_BASE_URL=http://host.docker.internal:18080).
- CP-4: stack is running in a mode compatible with the scenario (veto/draft scenarios require mode+map flow exposing map rotation callbacks).
- CP-5: required operators are present (at least one admin actor and one or more gameplay clients).

## Scenario matrix

| Scenario ID | Group | Prerequisites | Client/admin actions | Expected payload/log fields | Pass/fail criteria | Evidence destination |
| --- | --- | --- | --- | --- | --- | --- |
| W5-M01 | stack-join-baseline | CP-1, CP-2, CP-3 | Start stack, join one client, wait for player connect/info callbacks. | envelope identity tuple (event_name, event_id, idempotency_key), player identity fields, plugin load marker in ManiaControl log. | Pass if join emits deterministic identity fields and no malformed-envelope drops; fail on missing player join payload or identity drift. | SESSION-<id>-notes.md#w5-m01-stack-join-baseline + SESSION-<id>-evidence.md row W5-M01 |
| W5-M02 | admin-flow-actions | CP-1, CP-2, CP-3, CP-5 | Trigger admin actions (for example force spectator/team or map restart) through ManiaControl/admin flow. | lifecycle.admin_action context, player.transition/admin correlation fields, queue health markers if retries occur. | Pass if admin actions are reflected in payloads/logs with clear actor/action semantics; fail on missing/ambiguous admin context. | SESSION-<id>-notes.md#w5-m02-admin-flow-actions + SESSION-<id>-evidence.md row W5-M02 |
| W5-M03 | live-combat-counters | CP-1, CP-2, CP-3, CP-5 | Run short duel/skirmish producing shots, hits, misses, and kills. | combat.player_counters (shots, hits, misses, kills, deaths, accuracy), dimensions.weapon_id/damage/distance, player references. | Pass if counters are non-zero where expected and dimensions are populated with deterministic fallback semantics; fail on stale/zero-only counters despite confirmed combat. | SESSION-<id>-notes.md#w5-m03-live-combat-counters + SESSION-<id>-evidence.md row W5-M03 |
| W5-M04 | reconnect-continuity | CP-1, CP-2, CP-3, CP-5 | Disconnect and reconnect same client login during active session. | player.reconnect_continuity (identity_key, session_id, session_ordinal, transition_state), ordering fields. | Pass if reconnect chain increments deterministically and links to same identity key; fail on broken chain or missing reconnect semantics. | SESSION-<id>-notes.md#w5-m04-reconnect-continuity + SESSION-<id>-evidence.md row W5-M04 |
| W5-M05 | side-team-transitions | CP-1, CP-2, CP-3, CP-5 | Perform side/team switch via gameplay/admin path. | player.side_change (previous/current team+side, transition_kind, detected/team_changed/side_changed, dedupe_key). | Pass if side/team change is captured once per transition with coherent before/after values; fail on missing or contradictory side/team projection. | SESSION-<id>-notes.md#w5-m05-side-team-transitions + SESSION-<id>-evidence.md row W5-M05 |
| W5-M06 | team-aggregates | CP-1, CP-2, CP-3, CP-5 | Complete at least one round/map boundary with gameplay events. | lifecycle.aggregate_stats.team_counters_delta, team_summary, boundary window metadata. | Pass if aggregate deltas align with observed round/map activity and coverage markers are present; fail on empty or inconsistent team aggregates. | SESSION-<id>-notes.md#w5-m06-team-aggregates + SESSION-<id>-evidence.md row W5-M06 |
| W5-M07 | win-context | CP-1, CP-2, CP-3, CP-5 | Reach a boundary producing winner/tie context. | lifecycle.aggregate_stats.win_context (result_state, winning_side, winning_reason, fallback markers). | Pass if win context matches observed outcome (win/loss/tie) with deterministic reason fields; fail on wrong side/result mapping. | SESSION-<id>-notes.md#w5-m07-win-context + SESSION-<id>-evidence.md row W5-M07 |
| W5-M08 | veto-draft-actions | CP-1, CP-2, CP-3, CP-4, CP-5 | Run map veto/draft sequence with explicit admin/player actions where supported. | lifecycle.map_rotation.veto_draft_actions entries (action type, actor, order, fallback markers). | Pass if veto/pick/pass/lock actions are emitted in deterministic order (or explicit fallback semantics when unavailable); fail on silent action loss. | SESSION-<id>-notes.md#w5-m08-veto-draft-actions + SESSION-<id>-evidence.md row W5-M08 |
| W5-M09 | veto-result | CP-1, CP-2, CP-3, CP-4, CP-5 | Complete veto/draft flow until selected map result is known. | lifecycle.map_rotation.veto_result (status, selected map metadata, partial/unavailable semantics). | Pass if final veto result status matches observed flow and selected map projection is coherent; fail on mismatched result status or missing final projection. | SESSION-<id>-notes.md#w5-m09-veto-result + SESSION-<id>-evidence.md row W5-M09 |
| W5-M10 | outage-recovery-replay | CP-1, CP-2, CP-3 | Stop ACK stub to force transport outage, perform actions, restart stub, verify flush/recovery. | queue/outage telemetry (outage_entered, retry_scheduled, outage_recovered, recovery_flush_complete), queue depth + dropped counters. | Pass if outage markers appear in order and backlog flush completes after recovery; fail if queue does not recover or markers are missing/out-of-order. | SESSION-<id>-notes.md#w5-m10-outage-recovery-replay + SESSION-<id>-evidence.md row W5-M10 |

## Completion rule

- Mark a scenario pass only after payload, logs, and media evidence references are all present in session files.
- If a scenario cannot be executed in current runtime mode, mark fail or blocked with explicit reason and rerun instructions.
EOF

write_template "$INDEX_FILE" <<EOF
# Wave 5 Manual Evidence Index (${MANUAL_DATE})

Use this table to register each real-client session.
Manual scenario definitions and expected fields live in MANUAL-TEST-MATRIX.md.

| Session ID | Scenario Focus | Payload File | Notes File | Evidence File | Status |
| --- | --- | --- | --- | --- | --- |
| ${SESSION_ID} | ${SESSION_FOCUS} | SESSION-${SESSION_ID}-payload.ndjson | SESSION-${SESSION_ID}-notes.md | SESSION-${SESSION_ID}-evidence.md | planned |

Status values: planned, in_progress, passed, failed, blocked.

When a session status is passed or failed, all W5-M01..W5-M10 scenario rows in SESSION-${SESSION_ID}-evidence.md must be filled.
EOF

write_template "$SESSION_NOTES_FILE" <<EOF
# Session ${SESSION_ID} Notes

- Date: ${MANUAL_DATE}
- Scenario focus: ${SESSION_FOCUS}
- Operator:

## Prerequisites

- Stack healthy (bash scripts/validate-dev-stack-launch.sh passed recently)
- Local ACK capture target configured
- Real ShootMania client connected to the local server
- Matrix loaded from MANUAL-TEST-MATRIX.md

## W5-M01 stack-join-baseline

- Client/admin actions:
- Expected payload/log keys:
- Observed artifacts:
- Verdict:

## W5-M02 admin-flow-actions

- Client/admin actions:
- Expected payload/log keys:
- Observed artifacts:
- Verdict:

## W5-M03 live-combat-counters

- Client/admin actions:
- Expected payload/log keys:
- Observed artifacts:
- Verdict:

## W5-M04 reconnect-continuity

- Client/admin actions:
- Expected payload/log keys:
- Observed artifacts:
- Verdict:

## W5-M05 side-team-transitions

- Client/admin actions:
- Expected payload/log keys:
- Observed artifacts:
- Verdict:

## W5-M06 team-aggregates

- Client/admin actions:
- Expected payload/log keys:
- Observed artifacts:
- Verdict:

## W5-M07 win-context

- Client/admin actions:
- Expected payload/log keys:
- Observed artifacts:
- Verdict:

## W5-M08 veto-draft-actions

- Client/admin actions:
- Expected payload/log keys:
- Observed artifacts:
- Verdict:

## W5-M09 veto-result

- Client/admin actions:
- Expected payload/log keys:
- Observed artifacts:
- Verdict:

## W5-M10 outage-recovery-replay

- Client/admin actions:
- Expected payload/log keys:
- Observed artifacts:
- Verdict:

## Mismatches / follow-up

- [Record any unexpected behavior and reproduction details]
EOF

write_template "$SESSION_PAYLOAD_FILE" <<EOF
EOF

write_template "$SESSION_EVIDENCE_FILE" <<EOF
# Session ${SESSION_ID} Evidence

## Payload capture

- NDJSON file: SESSION-${SESSION_ID}-payload.ndjson

## Log references

- ManiaControl log excerpt:
- Shootmania container log excerpt:

## Screenshot / video references

- Naming format: SESSION-${SESSION_ID}-<scenario-id>-<timestamp>.<ext>
- [Add local file paths or filenames for each scenario checkpoint]

## Scenario verdicts

| Scenario ID | Payload evidence (line refs or filters) | Log evidence | Screenshot/video refs | Verdict |
| --- | --- | --- | --- | --- |
| W5-M01 | | | | |
| W5-M02 | | | | |
| W5-M03 | | | | |
| W5-M04 | | | | |
| W5-M05 | | | | |
| W5-M06 | | | | |
| W5-M07 | | | | |
| W5-M08 | | | | |
| W5-M09 | | | | |
| W5-M10 | | | | |
EOF

log "Manual wave-5 scaffolding ready: ${MANUAL_DIR}"
log "Manual test matrix: ${MATRIX_FILE}"
log "Session templates: SESSION-${SESSION_ID}-{notes.md,payload.ndjson,evidence.md}"
log "Fixture-off deterministic capture: PIXEL_SM_QA_TELEMETRY_INJECT_FIXTURES=0 bash scripts/replay-extended-telemetry-wave4.sh"
