#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
SERVER_URL="${PIXEL_CONTROL_SERVER_URL:-http://127.0.0.1:8080}"
QA_ARTIFACT_DIR="${PIXEL_SM_QA_ARTIFACT_DIR:-${PROJECT_DIR}/logs/qa}"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
ARTIFACT_PREFIX="wave2-admin-stats-${TIMESTAMP}"

mkdir -p "$QA_ARTIFACT_DIR"

log() {
  printf '[replay-admin-player-combat-telemetry] %s\n' "$1"
}

build_idempotency_key() {
  event_id="$1"
  hash_value="$(printf '%s' "$event_id" | shasum -a 1 | cut -d' ' -f1)"
  printf 'pc-idem-%s' "$hash_value"
}

post_payload() {
  payload="$1"
  response_file="$2"

  curl -sS -X POST "${SERVER_URL}/plugin/events" -H "Content-Type: application/json" --data "$payload" > "$response_file"
}

assert_ack_disposition() {
  response_file="$1"
  expected="$2"

  disposition="$(php -r '$data=json_decode(file_get_contents($argv[1]),true); if(!is_array($data)||!isset($data["ack"]["disposition"])) {exit(2);} echo $data["ack"]["disposition"];' "$response_file")"
  if [ "$disposition" != "$expected" ]; then
    log "Expected disposition '${expected}' but got '${disposition}' in ${response_file}"
    exit 1
  fi
}

assert_ack_rejected() {
  response_file="$1"
  expected_code="$2"

  code="$(php -r '$data=json_decode(file_get_contents($argv[1]),true); if(!is_array($data)||!isset($data["ack"]["status"])||!isset($data["ack"]["code"])) {exit(2);} if($data["ack"]["status"] !== "rejected") {exit(3);} echo $data["ack"]["code"];' "$response_file")"
  if [ "$code" != "$expected_code" ]; then
    log "Expected rejection code '${expected_code}' but got '${code}' in ${response_file}"
    exit 1
  fi
}

BASE_SEQUENCE="$(date +%s)"

ADMIN_EVENT_ID="pc-evt-lifecycle-maniaplanet_loadingmap_start-${BASE_SEQUENCE}"
ADMIN_IDEMPOTENCY_KEY="$(build_idempotency_key "$ADMIN_EVENT_ID")"
ADMIN_PAYLOAD="$(cat <<EOF
{"envelope":{"event_name":"pixel_control.lifecycle.maniaplanet_loadingmap_start","schema_version":"2026-02-20.1","event_id":"${ADMIN_EVENT_ID}","event_category":"lifecycle","source_callback":"ManiaPlanet.LoadingMap_Start","source_sequence":${BASE_SEQUENCE},"source_time":${BASE_SEQUENCE},"idempotency_key":"${ADMIN_IDEMPOTENCY_KEY}","payload":{"variant":"map.begin","phase":"map","state":"begin","source_channel":"script","raw_source_callback":"ManiaPlanet.LoadingMap_Start","raw_callback_summary":{"arguments_count":1,"arguments":[{"type":"array","value":"size:2"}]},"script_callback":{"name":"ManiaPlanet.LoadingMap_Start","payload_summary":{"arguments_count":1,"arguments":[{"type":"array","value":"size:3"}]},"payload":{"map_uid":"MapUid-Wave2"}},"admin_action":{"action_name":"map.loading.start","action_domain":"match_flow","action_type":"map_loading","action_phase":"start","target":"map","target_scope":"map","target_id":"MapUid-Wave2","initiator_kind":"system","source_callback":"ManiaPlanet.LoadingMap_Start","source_channel":"script","actor":{"type":"unknown","login":"","nickname":"","team_id":-1},"context":{"server":{"login":"srv-wave2","configured_mode":"elite"},"players":{"active":2,"total":2,"spectators":0}},"field_availability":{"target_id":true},"missing_fields":[]}},"metadata":{"plugin_version":"0.1.0-dev","schema_version":"2026-02-20.1","mode_family":"multi-mode","signal_kind":"callback","lifecycle_variant":"map.begin","admin_action_name":"map.loading.start","admin_action_domain":"match_flow","admin_action_type":"map_loading","admin_action_phase":"start","admin_action_target_scope":"map","admin_action_target_id":"MapUid-Wave2","admin_action_initiator_kind":"system","context":{"server":{"login":"srv-wave2","configured_mode":"elite","cup_id":"cup-wave2"},"players":{"active":2,"total":2,"spectators":0}}}},"transport":{"attempt":1,"max_attempts":3,"retry_backoff_ms":250,"auth_mode":"none"}}
EOF
)"

PLAYER_EVENT_SEQUENCE="$((BASE_SEQUENCE + 1))"
PLAYER_EVENT_ID="pc-evt-player-playermanagercallback_playerinfochanged-${PLAYER_EVENT_SEQUENCE}"
PLAYER_IDEMPOTENCY_KEY="$(build_idempotency_key "$PLAYER_EVENT_ID")"
PLAYER_PAYLOAD="$(cat <<EOF
{
  "envelope": {
    "event_name": "pixel_control.player.playermanagercallback_playerinfochanged",
    "schema_version": "2026-02-20.1",
    "event_id": "${PLAYER_EVENT_ID}",
    "event_category": "player",
    "source_callback": "PlayerManagerCallback.PlayerInfoChanged",
    "source_sequence": ${PLAYER_EVENT_SEQUENCE},
    "source_time": ${PLAYER_EVENT_SEQUENCE},
    "idempotency_key": "${PLAYER_IDEMPOTENCY_KEY}",
    "payload": {
      "event_kind": "player.info_changed",
      "transition_kind": "state_change",
      "source_callback": "PlayerManagerCallback.PlayerInfoChanged",
      "player": {
        "login": "player-one",
        "nickname": "Player One",
        "team_id": 1,
        "is_spectator": false,
        "is_connected": true,
        "has_joined_game": true,
        "auth_level": 2,
        "auth_name": "Admin",
        "auth_role": "admin",
        "is_referee": false,
        "has_player_slot": true,
        "is_server": false,
        "is_fake": false,
        "connectivity_state": "connected"
      },
      "previous_player": {
        "login": "player-one",
        "nickname": "Player One",
        "team_id": 2,
        "is_spectator": true,
        "is_connected": true,
        "has_joined_game": true,
        "auth_level": 1,
        "auth_name": "Moderator",
        "auth_role": "moderator",
        "is_referee": false,
        "has_player_slot": true,
        "is_server": false,
        "is_fake": false,
        "connectivity_state": "connected"
      },
      "state_delta": {
        "connectivity": {"before": "connected", "after": "connected", "changed": false},
        "spectator": {"before": true, "after": false, "changed": true},
        "team_id": {"before": 2, "after": 1, "changed": true},
        "auth_level": {"before": 1, "after": 2, "changed": true},
        "auth_role": {"before": "moderator", "after": "admin", "changed": true},
        "is_referee": {"before": false, "after": false, "changed": false},
        "has_player_slot": {"before": true, "after": true, "changed": false}
      },
      "permission_signals": {
        "auth_level": 2,
        "auth_name": "Admin",
        "auth_role": "admin",
        "is_referee": false,
        "has_player_slot": true,
        "can_admin_actions": true,
        "auth_level_changed": true,
        "role_changed": true
      },
      "roster_snapshot": {"active": 2, "total": 2, "spectators": 0},
      "tracked_player_cache_size": 1,
      "field_availability": {
        "player": true,
        "player_login": true,
        "previous_player": true,
        "team_id": true,
        "is_spectator": true,
        "auth_level": true,
        "is_referee": true
      },
      "missing_fields": [],
      "raw_callback_summary": {
        "arguments_count": 1,
        "arguments": [{"type": "object", "value": "PlayerObject"}]
      }
    },
    "metadata": {
      "plugin_version": "0.1.0-dev",
      "schema_version": "2026-02-20.1",
      "mode_family": "multi-mode",
      "signal_kind": "callback",
      "player_event_kind": "player.info_changed",
      "player_transition_kind": "state_change",
      "context": {
        "server": {"login": "srv-wave2", "configured_mode": "elite", "cup_id": "cup-wave2"},
        "players": {"active": 2, "total": 2, "spectators": 0}
      }
    }
  },
  "transport": {
    "attempt": 1,
    "max_attempts": 3,
    "retry_backoff_ms": 250,
    "auth_mode": "none"
  }
}
EOF
)"

COMBAT_EVENT_SEQUENCE="$((BASE_SEQUENCE + 2))"
COMBAT_EVENT_ID="pc-evt-combat-shootmania_event_onshoot-${COMBAT_EVENT_SEQUENCE}"
COMBAT_IDEMPOTENCY_KEY="$(build_idempotency_key "$COMBAT_EVENT_ID")"
COMBAT_PAYLOAD="$(cat <<EOF
{"envelope":{"event_name":"pixel_control.combat.shootmania_event_onshoot","schema_version":"2026-02-20.1","event_id":"${COMBAT_EVENT_ID}","event_category":"combat","source_callback":"ShootMania.Event.OnShoot","source_sequence":${COMBAT_EVENT_SEQUENCE},"source_time":${COMBAT_EVENT_SEQUENCE},"idempotency_key":"${COMBAT_IDEMPOTENCY_KEY}","payload":{"event_kind":"shootmania_event_onshoot","counter_scope":"runtime_session","player_counters":{},"tracked_player_count":1,"dimensions":{"weapon_id":1,"damage":null,"distance":null,"event_time":12345,"shooter":{"login":"player-one","nickname":"Player One","team_id":1,"is_spectator":false},"victim":null,"shooter_position":null,"victim_position":null},"field_availability":{"weapon_id":true},"missing_dimensions":[],"raw_callback_summary":{"arguments_count":0,"arguments":[]}},"metadata":{"plugin_version":"0.1.0-dev","schema_version":"2026-02-20.1","mode_family":"multi-mode","signal_kind":"callback","context":{"server":{"login":"srv-wave2","configured_mode":"elite","cup_id":"cup-wave2"},"players":{"active":2,"total":2,"spectators":0}}}},"transport":{"attempt":1,"max_attempts":3,"retry_backoff_ms":250,"auth_mode":"none"}}
EOF
)"

INVALID_EVENT_SEQUENCE="$((BASE_SEQUENCE + 3))"
INVALID_EVENT_ID="pc-evt-lifecycle-maniaplanet_beginmatch-${INVALID_EVENT_SEQUENCE}"
INVALID_IDEMPOTENCY_KEY="$(build_idempotency_key "$INVALID_EVENT_ID")"
INVALID_PAYLOAD="$(cat <<EOF
{"envelope":{"event_name":"pixel_control.lifecycle.maniaplanet_beginmatch","schema_version":"2026-02-99.1","event_id":"${INVALID_EVENT_ID}","event_category":"lifecycle","source_callback":"ManiaPlanet.BeginMatch","source_sequence":${INVALID_EVENT_SEQUENCE},"source_time":${INVALID_EVENT_SEQUENCE},"idempotency_key":"${INVALID_IDEMPOTENCY_KEY}","payload":{},"metadata":{}},"transport":{"attempt":1,"max_attempts":3,"retry_backoff_ms":250,"auth_mode":"none"}}
EOF
)"

ADMIN_RESPONSE_FILE="${QA_ARTIFACT_DIR}/${ARTIFACT_PREFIX}-admin.json"
PLAYER_RESPONSE_FILE="${QA_ARTIFACT_DIR}/${ARTIFACT_PREFIX}-player.json"
COMBAT_RESPONSE_FILE="${QA_ARTIFACT_DIR}/${ARTIFACT_PREFIX}-combat.json"
COMBAT_DUPLICATE_RESPONSE_FILE="${QA_ARTIFACT_DIR}/${ARTIFACT_PREFIX}-combat-duplicate.json"
INVALID_RESPONSE_FILE="${QA_ARTIFACT_DIR}/${ARTIFACT_PREFIX}-invalid-schema.json"
RECEIPTS_FILE="${QA_ARTIFACT_DIR}/${ARTIFACT_PREFIX}-receipts.json"
AGGREGATES_FILE="${QA_ARTIFACT_DIR}/${ARTIFACT_PREFIX}-aggregates.json"
SUMMARY_FILE="${QA_ARTIFACT_DIR}/${ARTIFACT_PREFIX}-summary.md"

log "Posting lifecycle admin-action payload"
post_payload "$ADMIN_PAYLOAD" "$ADMIN_RESPONSE_FILE"
assert_ack_disposition "$ADMIN_RESPONSE_FILE" "processed"

log "Posting player transition payload"
post_payload "$PLAYER_PAYLOAD" "$PLAYER_RESPONSE_FILE"
assert_ack_disposition "$PLAYER_RESPONSE_FILE" "processed"

log "Posting combat payload"
post_payload "$COMBAT_PAYLOAD" "$COMBAT_RESPONSE_FILE"
assert_ack_disposition "$COMBAT_RESPONSE_FILE" "processed"

log "Replaying same combat payload to validate duplicate semantics"
post_payload "$COMBAT_PAYLOAD" "$COMBAT_DUPLICATE_RESPONSE_FILE"
assert_ack_disposition "$COMBAT_DUPLICATE_RESPONSE_FILE" "duplicate"

log "Posting invalid schema payload to validate rejection semantics"
post_payload "$INVALID_PAYLOAD" "$INVALID_RESPONSE_FILE"
assert_ack_rejected "$INVALID_RESPONSE_FILE" "schema_version_unsupported"

curl -sS "${SERVER_URL}/ingestion/receipts" > "$RECEIPTS_FILE"
curl -sS "${SERVER_URL}/stats/aggregates" > "$AGGREGATES_FILE"

if ! php -r '$receipts=json_decode(file_get_contents($argv[1]),true); $idem=$argv[2]; if(!is_array($receipts)||!isset($receipts["receipts"])||!is_array($receipts["receipts"])) {exit(2);} foreach($receipts["receipts"] as $receipt){ if(isset($receipt["idempotency_key"]) && $receipt["idempotency_key"] === $idem){ $duplicateCount=isset($receipt["duplicate_count"]) ? (int)$receipt["duplicate_count"] : 0; if($duplicateCount >= 1){ exit(0);} } } exit(1);' "$RECEIPTS_FILE" "$COMBAT_IDEMPOTENCY_KEY"; then
  log "Unable to confirm duplicate_count >= 1 for replayed combat payload"
  exit 1
fi

if ! php -r '$snapshot=json_decode(file_get_contents($argv[1]),true); if(!is_array($snapshot) || !isset($snapshot["segments"]["mode"]) || !is_array($snapshot["segments"]["mode"])) {exit(1);} if(!isset($snapshot["segments"]["mode"]["elite"])) {exit(1);} exit(0);' "$AGGREGATES_FILE"; then
  log "Unable to confirm mode-segment aggregate marker for elite"
  exit 1
fi

cat > "$SUMMARY_FILE" <<EOF
# Wave 2 admin+stats replay summary (${TIMESTAMP})

- Server URL: ${SERVER_URL}
- Lifecycle admin payload response: ${ADMIN_RESPONSE_FILE}
- Player transition payload response: ${PLAYER_RESPONSE_FILE}
- Combat payload response: ${COMBAT_RESPONSE_FILE}
- Combat duplicate response: ${COMBAT_DUPLICATE_RESPONSE_FILE}
- Invalid schema response: ${INVALID_RESPONSE_FILE}
- Receipts snapshot: ${RECEIPTS_FILE}
- Aggregates snapshot: ${AGGREGATES_FILE}

Expected markers validated:

- processed admin/player/combat acknowledgments
- duplicate acknowledgment on replayed combat envelope
- rejected acknowledgment with code 'schema_version_unsupported'
- duplicate_count >= 1 on replayed idempotency key
- mode segmented aggregate presence ('elite')
EOF

log "Wave-2 admin/stats replay checks passed"
log "Summary: ${SUMMARY_FILE}"
