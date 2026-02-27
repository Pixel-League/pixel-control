#!/usr/bin/env bash
# qa-p0-smoke.sh — P0 foundation endpoint smoke tests
# Usage: bash scripts/qa-p0-smoke.sh [API_BASE]
# Default API_BASE: http://localhost:3000/v1
#
# Requires: curl, jq
# The API server must be running and PostgreSQL must be accessible.
set -euo pipefail

API_BASE="${1:-http://localhost:3000/v1}"
PASS=0
FAIL=0
SERVER_LOGIN="smoke-test-$(date +%s)"
TMP_BODY=$(mktemp)

# -----------------------------------------------------------------------
# Helpers
# -----------------------------------------------------------------------

green() { printf '\033[0;32m%s\033[0m\n' "$*"; }
red()   { printf '\033[0;31m%s\033[0m\n' "$*"; }

pass() {
  PASS=$((PASS + 1))
  green "  [PASS] $1"
}

fail() {
  FAIL=$((FAIL + 1))
  red "  [FAIL] $1"
  red "         Expected: $2"
  red "         Got:      $3"
}

# curl_req <method> <url> [body] [extra-headers...]
# Sets HTTP_CODE and writes body to TMP_BODY
curl_req() {
  local method="$1"
  local url="$2"
  local body="${3:-}"
  local extra_header="${4:-}"

  local args=(-s -o "$TMP_BODY" -w "%{http_code}" -X "$method" "$url")
  args+=(-H 'Content-Type: application/json')

  if [ -n "$extra_header" ]; then
    args+=(-H "$extra_header")
  fi

  if [ -n "$body" ]; then
    args+=(-d "$body")
  fi

  HTTP_CODE=$(curl "${args[@]}")
}

assert_field_eq() {
  local label="$1" field="$2" expected="$3"
  local actual
  actual=$(jq -r "$field" "$TMP_BODY" 2>/dev/null || echo "PARSE_ERROR")
  if [ "$actual" = "$expected" ]; then
    pass "$label: $field == $expected"
  else
    fail "$label: $field" "$expected" "$actual"
  fi
}

assert_http_code() {
  local label="$1" expected="$2"
  if [ "$HTTP_CODE" = "$expected" ]; then
    pass "$label: HTTP $expected"
  else
    fail "$label: HTTP code" "$expected" "$HTTP_CODE"
  fi
}

# -----------------------------------------------------------------------
# Pre-flight: server reachable
# -----------------------------------------------------------------------
echo ""
echo "=== Smoke Test: P0 Foundation Endpoints ==="
echo "  API_BASE: $API_BASE"
echo "  Server Login: $SERVER_LOGIN"
echo ""

echo "--- Pre-flight: health check ---"
curl_req "GET" "${API_BASE}"
assert_http_code "Health check (GET /v1)" "200"

# -----------------------------------------------------------------------
# P0.1 — PUT /servers/:serverLogin/link/registration (new server)
# -----------------------------------------------------------------------
echo ""
echo "--- P0.1: Register new server ---"
curl_req "PUT" "${API_BASE}/servers/${SERVER_LOGIN}/link/registration" \
  "{\"server_name\": \"Smoke Test Server\", \"game_mode\": \"Elite\", \"title_id\": \"SMStormElite@nadeolabs\"}"

assert_http_code "P0.1 new registration" "200"
assert_field_eq "P0.1" ".server_login" "$SERVER_LOGIN"
assert_field_eq "P0.1" ".registered" "true"

LINK_TOKEN=$(jq -r '.link_token // empty' "$TMP_BODY")
if [ -n "$LINK_TOKEN" ]; then
  pass "P0.1: link_token returned on first registration"
else
  fail "P0.1: link_token returned on first registration" "non-empty string" "empty"
fi

# -----------------------------------------------------------------------
# P0.1 — PUT /servers/:serverLogin/link/registration (update)
# -----------------------------------------------------------------------
echo ""
echo "--- P0.1: Update existing server ---"
curl_req "PUT" "${API_BASE}/servers/${SERVER_LOGIN}/link/registration" \
  '{"server_name": "Updated Name"}'

assert_http_code "P0.1 update" "200"
assert_field_eq "P0.1 update" ".registered" "true"

UPDATE_TOKEN=$(jq -r '.link_token // empty' "$TMP_BODY")
if [ -z "$UPDATE_TOKEN" ]; then
  pass "P0.1 update: no link_token returned on update (correct)"
else
  fail "P0.1 update: no link_token returned on update" "empty" "$UPDATE_TOKEN"
fi

# -----------------------------------------------------------------------
# P0.2 — POST /servers/:serverLogin/link/token (no rotate)
# -----------------------------------------------------------------------
echo ""
echo "--- P0.2: Get existing token (no rotate) ---"
curl_req "POST" "${API_BASE}/servers/${SERVER_LOGIN}/link/token" '{}'

assert_http_code "P0.2 get token" "201"
assert_field_eq "P0.2 get token" ".server_login" "$SERVER_LOGIN"
assert_field_eq "P0.2 get token" ".rotated" "false"

EXISTING_TOKEN=$(jq -r '.link_token // empty' "$TMP_BODY")
if [ "$EXISTING_TOKEN" = "$LINK_TOKEN" ]; then
  pass "P0.2: existing token matches first-time token"
else
  fail "P0.2: existing token matches first-time token" "$LINK_TOKEN" "$EXISTING_TOKEN"
fi

# -----------------------------------------------------------------------
# P0.2 — POST /servers/:serverLogin/link/token (rotate=true)
# -----------------------------------------------------------------------
echo ""
echo "--- P0.2: Rotate token ---"
curl_req "POST" "${API_BASE}/servers/${SERVER_LOGIN}/link/token" '{"rotate": true}'

assert_http_code "P0.2 rotate token" "201"
assert_field_eq "P0.2 rotate token" ".rotated" "true"

NEW_TOKEN=$(jq -r '.link_token // empty' "$TMP_BODY")
if [ -n "$NEW_TOKEN" ] && [ "$NEW_TOKEN" != "$LINK_TOKEN" ]; then
  pass "P0.2: rotated token is new and different"
else
  fail "P0.2: rotated token is new and different" "new UUID != $LINK_TOKEN" "$NEW_TOKEN"
fi

# -----------------------------------------------------------------------
# P0.3 — GET /servers/:serverLogin/link/auth-state (before heartbeat)
# -----------------------------------------------------------------------
echo ""
echo "--- P0.3: Auth state (before heartbeat) ---"
curl_req "GET" "${API_BASE}/servers/${SERVER_LOGIN}/link/auth-state"

assert_http_code "P0.3 auth-state" "200"
assert_field_eq "P0.3" ".linked" "true"
assert_field_eq "P0.3" ".online" "false"
assert_field_eq "P0.3" ".last_heartbeat" "null"

# -----------------------------------------------------------------------
# P0.4 — GET /servers/:serverLogin/link/access
# -----------------------------------------------------------------------
echo ""
echo "--- P0.4: Access check ---"
curl_req "GET" "${API_BASE}/servers/${SERVER_LOGIN}/link/access"

assert_http_code "P0.4 access" "200"
assert_field_eq "P0.4" ".access_granted" "true"
assert_field_eq "P0.4" ".linked" "true"

# -----------------------------------------------------------------------
# P0.5 — POST /plugin/events (registration event)
# -----------------------------------------------------------------------
echo ""
echo "--- P0.5: Ingest registration event ---"
evt_idem="pc-idem-smoke-reg-${SERVER_LOGIN}"
TS=$(date +%s)

curl -s -o "$TMP_BODY" -w "%{http_code}" \
  -X POST "${API_BASE}/plugin/events" \
  -H 'Content-Type: application/json' \
  -H "X-Pixel-Server-Login: ${SERVER_LOGIN}" \
  -H 'X-Pixel-Plugin-Version: 1.0.0' \
  -d "{
    \"event_name\": \"pixel_control.connectivity.plugin_registration\",
    \"schema_version\": \"2026-02-20.1\",
    \"event_id\": \"pc-evt-smoke-reg-1\",
    \"event_category\": \"connectivity\",
    \"source_callback\": \"PixelControl.PluginRegistration\",
    \"source_sequence\": 1,
    \"source_time\": ${TS},
    \"idempotency_key\": \"${evt_idem}\",
    \"payload\": {
      \"type\": \"plugin_registration\",
      \"context\": {
        \"server\": { \"login\": \"${SERVER_LOGIN}\", \"title_id\": \"SMStormElite@nadeolabs\", \"game_mode\": \"Elite\" },
        \"players\": { \"active\": 0, \"total\": 0, \"spectators\": 0 }
      },
      \"timestamp\": ${TS}
    },
    \"metadata\": { \"signal_kind\": \"registration\" }
  }" > /dev/null
HTTP_CODE=$(curl -s -o "$TMP_BODY" -w "%{http_code}" \
  -X POST "${API_BASE}/plugin/events" \
  -H 'Content-Type: application/json' \
  -H "X-Pixel-Server-Login: ${SERVER_LOGIN}" \
  -H 'X-Pixel-Plugin-Version: 1.0.0' \
  -d "{
    \"event_name\": \"pixel_control.connectivity.plugin_registration\",
    \"schema_version\": \"2026-02-20.1\",
    \"event_id\": \"pc-evt-smoke-reg-1b\",
    \"event_category\": \"connectivity\",
    \"source_callback\": \"PixelControl.PluginRegistration\",
    \"source_sequence\": 2,
    \"source_time\": ${TS},
    \"idempotency_key\": \"${evt_idem}-b\",
    \"payload\": {
      \"type\": \"plugin_registration\",
      \"context\": {
        \"server\": { \"login\": \"${SERVER_LOGIN}\", \"title_id\": \"SMStormElite@nadeolabs\", \"game_mode\": \"Elite\" }
      },
      \"timestamp\": ${TS}
    },
    \"metadata\": {}
  }")

assert_http_code "P0.5 registration event" "200"
assert_field_eq "P0.5" ".ack.status" "accepted"

# Verify auth-state updated
echo ""
echo "--- P0.3: Auth state (after registration event) ---"
curl_req "GET" "${API_BASE}/servers/${SERVER_LOGIN}/link/auth-state"
assert_field_eq "P0.3 post-event" ".online" "true"
assert_field_eq "P0.3 post-event" ".plugin_version" "1.0.0"

HB=$(jq -r '.last_heartbeat // empty' "$TMP_BODY")
if [ -n "$HB" ]; then
  pass "P0.3 post-event: last_heartbeat is set ($HB)"
else
  fail "P0.3 post-event: last_heartbeat is set" "ISO timestamp" "null"
fi

# -----------------------------------------------------------------------
# P0.5 — Duplicate event (same idempotency_key)
# -----------------------------------------------------------------------
echo ""
echo "--- P0.5: Duplicate event detection ---"
HTTP_CODE=$(curl -s -o "$TMP_BODY" -w "%{http_code}" \
  -X POST "${API_BASE}/plugin/events" \
  -H 'Content-Type: application/json' \
  -H "X-Pixel-Server-Login: ${SERVER_LOGIN}" \
  -d "{
    \"event_name\": \"pixel_control.connectivity.plugin_registration\",
    \"schema_version\": \"2026-02-20.1\",
    \"event_id\": \"pc-evt-smoke-reg-1\",
    \"event_category\": \"connectivity\",
    \"source_callback\": \"PixelControl.PluginRegistration\",
    \"source_sequence\": 1,
    \"source_time\": ${TS},
    \"idempotency_key\": \"${evt_idem}\",
    \"payload\": { \"type\": \"plugin_registration\" },
    \"metadata\": {}
  }")

assert_http_code "P0.5 duplicate" "200"
assert_field_eq "P0.5 duplicate" ".ack.status" "accepted"
assert_field_eq "P0.5 duplicate" ".ack.disposition" "duplicate"

# -----------------------------------------------------------------------
# P0.5 — Malformed envelope (missing event_name)
# -----------------------------------------------------------------------
echo ""
echo "--- P0.5: Malformed envelope rejection ---"
curl_req "POST" "${API_BASE}/plugin/events" \
  '{"payload": {}}' \
  "X-Pixel-Server-Login: ${SERVER_LOGIN}"

assert_http_code "P0.5 malformed" "400"
assert_field_eq "P0.5 malformed" ".ack.status" "rejected"
assert_field_eq "P0.5 malformed" ".ack.code" "invalid_envelope"

# -----------------------------------------------------------------------
# P0.5 — Missing server-login header
# -----------------------------------------------------------------------
echo ""
echo "--- P0.5: Missing server-login header ---"
UNIQUE_IDEM="unique-no-hdr-$(date +%s)"
curl_req "POST" "${API_BASE}/plugin/events" \
  "{\"event_name\": \"test\", \"schema_version\": \"2026-02-20.1\", \"event_id\": \"x\", \"event_category\": \"connectivity\", \"source_callback\": \"cb\", \"source_sequence\": 1, \"source_time\": 100, \"idempotency_key\": \"${UNIQUE_IDEM}\", \"payload\": {}}"

assert_http_code "P0.5 missing header" "400"
assert_field_eq "P0.5 missing header" ".ack.status" "rejected"
assert_field_eq "P0.5 missing header" ".ack.code" "missing_server_login"

# -----------------------------------------------------------------------
# P0.6 — GET /servers (list all)
# -----------------------------------------------------------------------
echo ""
echo "--- P0.6: List servers ---"
curl_req "GET" "${API_BASE}/servers"

assert_http_code "P0.6 list" "200"

SERVER_COUNT=$(jq 'length' "$TMP_BODY")
if [ "$SERVER_COUNT" -ge 1 ]; then
  pass "P0.6: at least one server in list ($SERVER_COUNT)"
else
  fail "P0.6: at least one server in list" ">= 1" "$SERVER_COUNT"
fi

# -----------------------------------------------------------------------
# P0.6 — GET /servers?status=linked
# -----------------------------------------------------------------------
echo ""
echo "--- P0.6: Filter by status=linked ---"
curl_req "GET" "${API_BASE}/servers?status=linked"

assert_http_code "P0.6 linked filter" "200"
LINKED_COUNT=$(jq 'length' "$TMP_BODY")
if [ "$LINKED_COUNT" -ge 1 ]; then
  pass "P0.6 linked filter: returned linked servers ($LINKED_COUNT)"
else
  fail "P0.6 linked filter: returned linked servers" ">= 1" "$LINKED_COUNT"
fi

# -----------------------------------------------------------------------
# P0.3/P0.4 — 404 for unknown server
# -----------------------------------------------------------------------
echo ""
echo "--- 404: Unknown server ---"
curl_req "GET" "${API_BASE}/servers/definitely-does-not-exist-xyz/link/auth-state"
assert_http_code "404 unknown server auth-state" "404"

curl_req "POST" "${API_BASE}/servers/definitely-does-not-exist-xyz/link/token" '{}'
assert_http_code "404 unknown server token" "404"

curl_req "GET" "${API_BASE}/servers/definitely-does-not-exist-xyz/link/access"
assert_http_code "404 unknown server access" "404"

# -----------------------------------------------------------------------
# Cleanup
# -----------------------------------------------------------------------
rm -f "$TMP_BODY"

# -----------------------------------------------------------------------
# Summary
# -----------------------------------------------------------------------
echo ""
echo "==========================================="
echo "  Results: ${PASS} passed, ${FAIL} failed"
echo "==========================================="
echo ""

if [ "$FAIL" -gt 0 ]; then
  exit 1
fi
