#!/usr/bin/env bash
#
# qa-p1-smoke.sh — P1 Core Ingestion QA smoke test
#
# Prerequisites:
#   - pixel-control-server running on http://localhost:3000
#   - PostgreSQL accessible (docker compose up -d postgres)
#
# Usage:
#   cd pixel-control-server
#   npm run start:dev &   # or docker compose up -d
#   bash scripts/qa-p1-smoke.sh
#

set -euo pipefail

API="http://localhost:3000/v1"
SERVER_LOGIN="qa-p1-smoke-server"
PASS=0
FAIL=0

# macOS-compatible color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_pass() {
  PASS=$((PASS + 1))
  printf "${GREEN}[PASS]${NC} %s\n" "$1"
}

log_fail() {
  FAIL=$((FAIL + 1))
  printf "${RED}[FAIL]${NC} %s\n" "$1"
  printf "       Response: %s\n" "$2"
}

log_info() {
  printf "${YELLOW}[INFO]${NC} %s\n" "$1"
}

assert_contains() {
  local label="$1"
  local response="$2"
  local expected="$3"
  if echo "$response" | grep -q "$expected"; then
    log_pass "$label"
  else
    log_fail "$label" "$response"
  fi
}

assert_not_contains() {
  local label="$1"
  local response="$2"
  local unexpected="$3"
  if echo "$response" | grep -q "$unexpected"; then
    log_fail "$label (found unexpected: $unexpected)" "$response"
  else
    log_pass "$label"
  fi
}

# Unique idempotency keys to avoid duplicate collisions across runs
TIMESTAMP=$(date +%s)

###############################################################################
# 0. Wait for server
###############################################################################
log_info "Waiting for server to be ready..."
for i in $(seq 1 10); do
  if curl -sf "$API/servers" > /dev/null 2>&1; then
    log_pass "Server is ready"
    break
  fi
  if [ "$i" -eq 10 ]; then
    log_fail "Server not ready after 10 attempts" "timeout"
    exit 1
  fi
  sleep 2
done

###############################################################################
# 1. Register a server via PUT link/registration
###############################################################################
log_info "--- Phase 1: Server registration ---"

REG_RESP=$(curl -sf -X PUT "$API/servers/$SERVER_LOGIN/link/registration" \
  -H "Content-Type: application/json" \
  -d '{"server_name":"QA P1 Smoke Server","game_mode":"Elite","title_id":"SMStormElite@nadeolabs"}' 2>&1 || echo '{"error":"curl_failed"}')

assert_contains "PUT link/registration returns token" "$REG_RESP" '"link_token"'

###############################################################################
# 2. Send a connectivity event (stored in both tables)
###############################################################################
log_info "--- Phase 2: Connectivity event ---"

CONN_IDEM="pc-idem-smoke-conn-${TIMESTAMP}"
CONN_RESP=$(curl -sf -X POST "$API/plugin/events" \
  -H "Content-Type: application/json" \
  -H "X-Pixel-Server-Login: $SERVER_LOGIN" \
  -H "X-Pixel-Plugin-Version: 1.0.0" \
  -d "{
    \"event_name\": \"pixel_control.connectivity.plugin_heartbeat\",
    \"schema_version\": \"2026-02-20.1\",
    \"event_id\": \"pc-evt-connectivity-plugin_heartbeat-${TIMESTAMP}\",
    \"event_category\": \"connectivity\",
    \"source_callback\": \"plugin.heartbeat\",
    \"source_sequence\": ${TIMESTAMP},
    \"source_time\": ${TIMESTAMP},
    \"idempotency_key\": \"${CONN_IDEM}\",
    \"payload\": {
      \"type\": \"plugin_heartbeat\",
      \"queue\": {\"depth\": 0, \"max_size\": 2000, \"high_watermark\": 5, \"dropped_on_capacity\": 0, \"dropped_on_identity_validation\": 0, \"recovery_flush_pending\": false},
      \"retry\": {\"max_retry_attempts\": 3, \"retry_backoff_ms\": 250, \"dispatch_batch_size\": 3},
      \"outage\": {\"active\": false, \"started_at\": null, \"failure_count\": 0, \"last_error_code\": null, \"recovery_flush_pending\": false},
      \"context\": {\"server\": {\"login\": \"$SERVER_LOGIN\", \"game_mode\": \"Elite\"}, \"players\": {\"active\": 4, \"total\": 6, \"spectators\": 2}},
      \"timestamp\": ${TIMESTAMP}
    }
  }" 2>&1 || echo '{"error":"curl_failed"}')

assert_contains "POST connectivity event returns accepted" "$CONN_RESP" '"accepted"'
assert_not_contains "POST connectivity event no error field" "$CONN_RESP" '"error"'

###############################################################################
# 3. Send a lifecycle event
###############################################################################
log_info "--- Phase 3: Lifecycle event ---"

LC_TS=$((TIMESTAMP + 1))
LC_RESP=$(curl -sf -X POST "$API/plugin/events" \
  -H "Content-Type: application/json" \
  -H "X-Pixel-Server-Login: $SERVER_LOGIN" \
  -d "{
    \"event_name\": \"pixel_control.lifecycle.smbeginmap\",
    \"schema_version\": \"2026-02-20.1\",
    \"event_id\": \"pc-evt-lifecycle-smbeginmap-${LC_TS}\",
    \"event_category\": \"lifecycle\",
    \"source_callback\": \"SmBeginMap\",
    \"source_sequence\": ${LC_TS},
    \"source_time\": ${LC_TS},
    \"idempotency_key\": \"pc-idem-smoke-lifecycle-${TIMESTAMP}\",
    \"payload\": {\"map\": \"TestMap\", \"round\": 1}
  }" 2>&1 || echo '{"error":"curl_failed"}')

assert_contains "POST lifecycle event returns accepted" "$LC_RESP" '"accepted"'

###############################################################################
# 4. Send a combat event
###############################################################################
log_info "--- Phase 4: Combat event ---"

CB_TS=$((TIMESTAMP + 2))
CB_RESP=$(curl -sf -X POST "$API/plugin/events" \
  -H "Content-Type: application/json" \
  -H "X-Pixel-Server-Login: $SERVER_LOGIN" \
  -d "{
    \"event_name\": \"pixel_control.combat.smshoot\",
    \"schema_version\": \"2026-02-20.1\",
    \"event_id\": \"pc-evt-combat-smshoot-${CB_TS}\",
    \"event_category\": \"combat\",
    \"source_callback\": \"SmShoot\",
    \"source_sequence\": ${CB_TS},
    \"source_time\": ${CB_TS},
    \"idempotency_key\": \"pc-idem-smoke-combat-${TIMESTAMP}\",
    \"payload\": {\"shooter\": \"player1\", \"victim\": \"player2\", \"weapon\": \"Laser\"}
  }" 2>&1 || echo '{"error":"curl_failed"}')

assert_contains "POST combat event returns accepted" "$CB_RESP" '"accepted"'

###############################################################################
# 5. Send a player event
###############################################################################
log_info "--- Phase 5: Player event ---"

PL_TS=$((TIMESTAMP + 3))
PL_RESP=$(curl -sf -X POST "$API/plugin/events" \
  -H "Content-Type: application/json" \
  -H "X-Pixel-Server-Login: $SERVER_LOGIN" \
  -d "{
    \"event_name\": \"pixel_control.player.smplayerconnect\",
    \"schema_version\": \"2026-02-20.1\",
    \"event_id\": \"pc-evt-player-smplayerconnect-${PL_TS}\",
    \"event_category\": \"player\",
    \"source_callback\": \"SmPlayerConnect\",
    \"source_sequence\": ${PL_TS},
    \"source_time\": ${PL_TS},
    \"idempotency_key\": \"pc-idem-smoke-player-${TIMESTAMP}\",
    \"payload\": {\"login\": \"player1\", \"nickname\": \"Player One\"}
  }" 2>&1 || echo '{"error":"curl_failed"}')

assert_contains "POST player event returns accepted" "$PL_RESP" '"accepted"'

###############################################################################
# 6. Send a mode event
###############################################################################
log_info "--- Phase 6: Mode event ---"

MD_TS=$((TIMESTAMP + 4))
MD_RESP=$(curl -sf -X POST "$API/plugin/events" \
  -H "Content-Type: application/json" \
  -H "X-Pixel-Server-Login: $SERVER_LOGIN" \
  -d "{
    \"event_name\": \"pixel_control.mode.smmodescriptelitestartturn\",
    \"schema_version\": \"2026-02-20.1\",
    \"event_id\": \"pc-evt-mode-smmodescriptelitestartturn-${MD_TS}\",
    \"event_category\": \"mode\",
    \"source_callback\": \"SmModeScriptEliteStartTurn\",
    \"source_sequence\": ${MD_TS},
    \"source_time\": ${MD_TS},
    \"idempotency_key\": \"pc-idem-smoke-mode-${TIMESTAMP}\",
    \"payload\": {\"turn\": 1, \"attacker\": \"team_a\"}
  }" 2>&1 || echo '{"error":"curl_failed"}')

assert_contains "POST mode event returns accepted" "$MD_RESP" '"accepted"'

###############################################################################
# 7. Duplicate detection — resend each event with same idempotency_key
###############################################################################
log_info "--- Phase 7: Duplicate detection ---"

DUP_CONN_RESP=$(curl -sf -X POST "$API/plugin/events" \
  -H "Content-Type: application/json" \
  -H "X-Pixel-Server-Login: $SERVER_LOGIN" \
  -d "{
    \"event_name\": \"pixel_control.connectivity.plugin_heartbeat\",
    \"schema_version\": \"2026-02-20.1\",
    \"event_id\": \"pc-evt-connectivity-plugin_heartbeat-${TIMESTAMP}\",
    \"event_category\": \"connectivity\",
    \"source_callback\": \"plugin.heartbeat\",
    \"source_sequence\": ${TIMESTAMP},
    \"source_time\": ${TIMESTAMP},
    \"idempotency_key\": \"${CONN_IDEM}\",
    \"payload\": {\"type\": \"plugin_heartbeat\"}
  }" 2>&1 || echo '{"error":"curl_failed"}')

assert_contains "Duplicate connectivity event returns disposition=duplicate" "$DUP_CONN_RESP" '"duplicate"'

DUP_LC_RESP=$(curl -sf -X POST "$API/plugin/events" \
  -H "Content-Type: application/json" \
  -H "X-Pixel-Server-Login: $SERVER_LOGIN" \
  -d "{
    \"event_name\": \"pixel_control.lifecycle.smbeginmap\",
    \"schema_version\": \"2026-02-20.1\",
    \"event_id\": \"pc-evt-lifecycle-smbeginmap-${LC_TS}\",
    \"event_category\": \"lifecycle\",
    \"source_callback\": \"SmBeginMap\",
    \"source_sequence\": ${LC_TS},
    \"source_time\": ${LC_TS},
    \"idempotency_key\": \"pc-idem-smoke-lifecycle-${TIMESTAMP}\",
    \"payload\": {\"map\": \"TestMap\"}
  }" 2>&1 || echo '{"error":"curl_failed"}')

assert_contains "Duplicate lifecycle event returns disposition=duplicate" "$DUP_LC_RESP" '"duplicate"'

###############################################################################
# 8. Batch flush with 3 mixed events
###############################################################################
log_info "--- Phase 8: Batch flush ---"

BT_TS=$((TIMESTAMP + 10))
BT_RESP=$(curl -sf -X POST "$API/plugin/events" \
  -H "Content-Type: application/json" \
  -H "X-Pixel-Server-Login: $SERVER_LOGIN" \
  -d "{
    \"event_name\": \"pixel_control.batch.flush\",
    \"schema_version\": \"2026-02-20.1\",
    \"event_id\": \"pc-evt-batch-flush-${BT_TS}\",
    \"event_category\": \"batch\",
    \"source_callback\": \"BatchFlush\",
    \"source_sequence\": ${BT_TS},
    \"source_time\": ${BT_TS},
    \"idempotency_key\": \"pc-idem-smoke-batch-${TIMESTAMP}\",
    \"payload\": {
      \"events\": [
        {
          \"event_name\": \"pixel_control.lifecycle.smbeginmap\",
          \"schema_version\": \"2026-02-20.1\",
          \"event_id\": \"pc-evt-lifecycle-smbeginmap-$((BT_TS+1))\",
          \"event_category\": \"lifecycle\",
          \"source_callback\": \"SmBeginMap\",
          \"source_sequence\": $((BT_TS+1)),
          \"source_time\": $((BT_TS+1)),
          \"idempotency_key\": \"pc-idem-smoke-batch-inner1-${TIMESTAMP}\",
          \"payload\": {\"map\": \"BatchMap1\"}
        },
        {
          \"event_name\": \"pixel_control.combat.smshoot\",
          \"schema_version\": \"2026-02-20.1\",
          \"event_id\": \"pc-evt-combat-smshoot-$((BT_TS+2))\",
          \"event_category\": \"combat\",
          \"source_callback\": \"SmShoot\",
          \"source_sequence\": $((BT_TS+2)),
          \"source_time\": $((BT_TS+2)),
          \"idempotency_key\": \"pc-idem-smoke-batch-inner2-${TIMESTAMP}\",
          \"payload\": {\"shooter\": \"p1\", \"victim\": \"p2\"}
        },
        {
          \"event_name\": \"pixel_control.player.smplayerconnect\",
          \"schema_version\": \"2026-02-20.1\",
          \"event_id\": \"pc-evt-player-smplayerconnect-$((BT_TS+3))\",
          \"event_category\": \"player\",
          \"source_callback\": \"SmPlayerConnect\",
          \"source_sequence\": $((BT_TS+3)),
          \"source_time\": $((BT_TS+3)),
          \"idempotency_key\": \"pc-idem-smoke-batch-inner3-${TIMESTAMP}\",
          \"payload\": {\"login\": \"p3\"}
        }
      ]
    }
  }" 2>&1 || echo '{"error":"curl_failed"}')

assert_contains "POST batch flush returns accepted" "$BT_RESP" '"accepted"'
assert_contains "POST batch flush returns batch_size=3" "$BT_RESP" '"batch_size":3'
assert_contains "POST batch flush returns accepted=3" "$BT_RESP" '"accepted":3'

###############################################################################
# 9. GET /v1/servers/:serverLogin/status
###############################################################################
log_info "--- Phase 9: Server status endpoint ---"

STATUS_RESP=$(curl -sf "$API/servers/$SERVER_LOGIN/status" 2>&1 || echo '{"error":"curl_failed"}')

assert_contains "GET status returns server_login" "$STATUS_RESP" "\"server_login\""
assert_contains "GET status returns linked field" "$STATUS_RESP" "\"linked\""
assert_contains "GET status returns online field" "$STATUS_RESP" "\"online\""
assert_contains "GET status returns player_counts" "$STATUS_RESP" "\"player_counts\""
assert_contains "GET status returns event_counts" "$STATUS_RESP" "\"event_counts\""
assert_contains "GET status returns by_category" "$STATUS_RESP" "\"by_category\""
assert_contains "GET status has connectivity events" "$STATUS_RESP" "\"connectivity\""

###############################################################################
# 10. GET /v1/servers/:serverLogin/status/health
###############################################################################
log_info "--- Phase 10: Plugin health endpoint ---"

HEALTH_RESP=$(curl -sf "$API/servers/$SERVER_LOGIN/status/health" 2>&1 || echo '{"error":"curl_failed"}')

assert_contains "GET health returns server_login" "$HEALTH_RESP" "\"server_login\""
assert_contains "GET health returns plugin_health" "$HEALTH_RESP" "\"plugin_health\""
assert_contains "GET health returns queue field" "$HEALTH_RESP" "\"queue\""
assert_contains "GET health returns retry field" "$HEALTH_RESP" "\"retry\""
assert_contains "GET health returns outage field" "$HEALTH_RESP" "\"outage\""
assert_contains "GET health returns connectivity_metrics" "$HEALTH_RESP" "\"connectivity_metrics\""
assert_contains "GET health returns total_connectivity_events" "$HEALTH_RESP" "\"total_connectivity_events\""

###############################################################################
# 11. Unknown category — accepted, no crash
###############################################################################
log_info "--- Phase 11: Unknown category ---"

UK_TS=$((TIMESTAMP + 20))
UK_RESP=$(curl -sf -X POST "$API/plugin/events" \
  -H "Content-Type: application/json" \
  -H "X-Pixel-Server-Login: $SERVER_LOGIN" \
  -d "{
    \"event_name\": \"pixel_control.unknown_category.some_event\",
    \"schema_version\": \"2026-02-20.1\",
    \"event_id\": \"pc-evt-unknown_category-some_event-${UK_TS}\",
    \"event_category\": \"unknown_category\",
    \"source_callback\": \"SomeUnknownCallback\",
    \"source_sequence\": ${UK_TS},
    \"source_time\": ${UK_TS},
    \"idempotency_key\": \"pc-idem-smoke-unknown-${TIMESTAMP}\",
    \"payload\": {\"data\": \"test\"}
  }" 2>&1 || echo '{"error":"curl_failed"}')

assert_contains "POST unknown category event returns accepted" "$UK_RESP" '"accepted"'
assert_not_contains "POST unknown category event no crash (no 500 error)" "$UK_RESP" '"internal_error"'

###############################################################################
# 12. Missing X-Pixel-Server-Login header — rejected
###############################################################################
log_info "--- Phase 12: Missing server-login header ---"

NO_HEADER_RESP=$(curl -s -X POST "$API/plugin/events" \
  -H "Content-Type: application/json" \
  -d "{
    \"event_name\": \"pixel_control.connectivity.plugin_heartbeat\",
    \"schema_version\": \"2026-02-20.1\",
    \"event_id\": \"pc-evt-connectivity-plugin_heartbeat-999\",
    \"event_category\": \"connectivity\",
    \"source_callback\": \"plugin.heartbeat\",
    \"source_sequence\": 999,
    \"source_time\": 999,
    \"idempotency_key\": \"pc-idem-no-header-999\",
    \"payload\": {\"type\": \"plugin_heartbeat\"}
  }" 2>&1 || echo '{"error":"curl_failed"}')

assert_contains "POST without server-login header returns rejected" "$NO_HEADER_RESP" '"rejected"'
assert_contains "POST without server-login header returns missing_server_login" "$NO_HEADER_RESP" '"missing_server_login"'

###############################################################################
# 13. Malformed envelope — rejected
###############################################################################
log_info "--- Phase 13: Malformed envelope ---"

MALFORMED_RESP=$(curl -s -X POST "$API/plugin/events" \
  -H "Content-Type: application/json" \
  -H "X-Pixel-Server-Login: $SERVER_LOGIN" \
  -d '{"some_garbage": "data", "no_valid_fields": true}' 2>&1 || echo '{"error":"curl_failed"}')

assert_contains "POST malformed envelope returns rejected" "$MALFORMED_RESP" '"rejected"'
assert_contains "POST malformed envelope returns invalid_envelope" "$MALFORMED_RESP" '"invalid_envelope"'

###############################################################################
# 14. DELETE server — cascade deletes events from both tables
###############################################################################
log_info "--- Phase 14: Server deletion cascade ---"

DEL_RESP=$(curl -sf -X DELETE "$API/servers/$SERVER_LOGIN" 2>&1 || echo '{"error":"curl_failed"}')
assert_contains "DELETE server returns success" "$DEL_RESP" '"deleted"'

# After delete, status should return 404
STATUS_404=$(curl -s "$API/servers/$SERVER_LOGIN/status" 2>&1 || echo '{"error":"curl_failed"}')
assert_contains "GET status after DELETE returns 404 content" "$STATUS_404" '"statusCode":404'

###############################################################################
# Summary
###############################################################################
printf "\n"
printf "${YELLOW}========================================${NC}\n"
printf "${YELLOW}  P1 Smoke Test Results${NC}\n"
printf "${YELLOW}========================================${NC}\n"
printf "${GREEN}  PASSED: %d${NC}\n" "$PASS"
if [ "$FAIL" -gt 0 ]; then
  printf "${RED}  FAILED: %d${NC}\n" "$FAIL"
  printf "${YELLOW}========================================${NC}\n"
  exit 1
else
  printf "${GREEN}  FAILED: 0${NC}\n"
  printf "${YELLOW}========================================${NC}\n"
  printf "${GREEN}  All assertions passed!${NC}\n"
fi
