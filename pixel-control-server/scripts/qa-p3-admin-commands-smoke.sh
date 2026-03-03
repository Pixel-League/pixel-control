#!/usr/bin/env bash
# QA smoke test for P3 admin command endpoints.
#
# Tests all 16 P3 endpoints for correct HTTP status codes and response shapes.
# When the ManiaControl socket is unavailable, write endpoints return 502 and
# read endpoints return 503. The script validates both the "no socket" path and
# (if a live server is running) the "live" path.
#
# Usage:
#   bash scripts/qa-p3-admin-commands-smoke.sh [BASE_URL] [SERVER_LOGIN]
#
# Environment variables:
#   API_BASE_URL       Default: http://localhost:3000/v1
#   SERVER_LOGIN       Default: pixel-elite-1.server.local

set -euo pipefail

API_BASE_URL="${1:-${API_BASE_URL:-http://localhost:3000/v1}}"
SERVER_LOGIN="${2:-${SERVER_LOGIN:-pixel-elite-1.server.local}}"

PASSED=0
FAILED=0

# ─── Helpers ─────────────────────────────────────────────────────────────────

assert_status() {
  local label="$1"
  local expected="$2"
  local actual="$3"
  if [ "$actual" = "$expected" ]; then
    echo "ok  [${expected}] ${label}"
    PASSED=$((PASSED + 1))
  else
    echo "FAIL [expected=${expected}, got=${actual}] ${label}"
    FAILED=$((FAILED + 1))
  fi
}

assert_field() {
  local label="$1"
  local expected="$2"
  local actual="$3"
  if [ "$actual" = "$expected" ]; then
    echo "ok  [field=${expected}] ${label}"
    PASSED=$((PASSED + 1))
  else
    echo "FAIL [expected field=${expected}, got=${actual}] ${label}"
    FAILED=$((FAILED + 1))
  fi
}

http_get() {
  local path="$1"
  curl -s -o /dev/null -w "%{http_code}" "${API_BASE_URL}${path}"
}

http_post() {
  local path="$1"
  local body="$2"
  if [ -z "$body" ]; then body='{}'; fi
  curl -s -o /dev/null -w "%{http_code}" -X POST -H 'Content-Type: application/json' \
    -d "$body" "${API_BASE_URL}${path}"
}

http_put() {
  local path="$1"
  local body="$2"
  if [ -z "$body" ]; then body='{}'; fi
  curl -s -o /dev/null -w "%{http_code}" -X PUT -H 'Content-Type: application/json' \
    -d "$body" "${API_BASE_URL}${path}"
}

http_delete() {
  local path="$1"
  curl -s -o /dev/null -w "%{http_code}" -X DELETE "${API_BASE_URL}${path}"
}

http_post_body() {
  local path="$1"
  local body="$2"
  if [ -z "$body" ]; then body='{}'; fi
  curl -s -X POST -H 'Content-Type: application/json' -d "$body" "${API_BASE_URL}${path}"
}

http_put_body() {
  local path="$1"
  local body="$2"
  if [ -z "$body" ]; then body='{}'; fi
  curl -s -X PUT -H 'Content-Type: application/json' -d "$body" "${API_BASE_URL}${path}"
}

http_get_body() {
  local path="$1"
  curl -s "${API_BASE_URL}${path}"
}

# ─── Prerequisites ────────────────────────────────────────────────────────────

echo ""
echo "=== P3 Admin Commands Smoke Tests ==="
echo "API:    ${API_BASE_URL}"
echo "Server: ${SERVER_LOGIN}"
echo ""

# Check server is registered (if not, tests will 404).
STATUS=$(http_get "/servers/${SERVER_LOGIN}/status")
if [ "$STATUS" = "404" ]; then
  echo "WARNING: Server '${SERVER_LOGIN}' not found. Most tests will fail with 404."
  echo "Register the server first with the plugin running."
fi

# ─── P3.1 Skip Map ───────────────────────────────────────────────────────────

echo "--- P3.1 Skip Map ---"
CODE=$(http_post "/servers/${SERVER_LOGIN}/maps/skip" '{}')
# Expect 200 (success) or 502 (socket unavailable) or 404 (no server).
if [ "$CODE" = "200" ] || [ "$CODE" = "502" ] || [ "$CODE" = "404" ]; then
  echo "ok  [${CODE}] POST /maps/skip returned expected code"
  PASSED=$((PASSED + 1))
else
  echo "FAIL [got=${CODE}] POST /maps/skip unexpected code"
  FAILED=$((FAILED + 1))
fi

# ─── P3.2 Restart Map ────────────────────────────────────────────────────────

echo "--- P3.2 Restart Map ---"
CODE=$(http_post "/servers/${SERVER_LOGIN}/maps/restart" '{}')
if [ "$CODE" = "200" ] || [ "$CODE" = "502" ] || [ "$CODE" = "404" ]; then
  echo "ok  [${CODE}] POST /maps/restart returned expected code"
  PASSED=$((PASSED + 1))
else
  echo "FAIL [got=${CODE}] POST /maps/restart unexpected code"
  FAILED=$((FAILED + 1))
fi

# ─── P3.3 Jump to Map — missing body → 400 ───────────────────────────────────

echo "--- P3.3 Jump to Map ---"
CODE=$(http_post "/servers/${SERVER_LOGIN}/maps/jump" '{}')
assert_status "POST /maps/jump without map_uid → 400" "400" "$CODE"

CODE=$(http_post "/servers/${SERVER_LOGIN}/maps/jump" '{"map_uid":"test-uid"}')
if [ "$CODE" = "200" ] || [ "$CODE" = "400" ] || [ "$CODE" = "502" ] || [ "$CODE" = "404" ]; then
  echo "ok  [${CODE}] POST /maps/jump with map_uid returned expected code"
  PASSED=$((PASSED + 1))
else
  echo "FAIL [got=${CODE}] POST /maps/jump unexpected code"
  FAILED=$((FAILED + 1))
fi

# ─── P3.4 Queue Map — missing body → 400 ────────────────────────────────────

echo "--- P3.4 Queue Map ---"
CODE=$(http_post "/servers/${SERVER_LOGIN}/maps/queue" '{}')
assert_status "POST /maps/queue without map_uid → 400" "400" "$CODE"

CODE=$(http_post "/servers/${SERVER_LOGIN}/maps/queue" '{"map_uid":"test-uid"}')
if [ "$CODE" = "200" ] || [ "$CODE" = "400" ] || [ "$CODE" = "502" ] || [ "$CODE" = "404" ]; then
  echo "ok  [${CODE}] POST /maps/queue with map_uid returned expected code"
  PASSED=$((PASSED + 1))
else
  echo "FAIL [got=${CODE}] POST /maps/queue unexpected code"
  FAILED=$((FAILED + 1))
fi

# ─── P3.5 Add Map — missing body → 400 ──────────────────────────────────────

echo "--- P3.5 Add Map ---"
CODE=$(http_post "/servers/${SERVER_LOGIN}/maps" '{}')
assert_status "POST /maps without mx_id → 400" "400" "$CODE"

CODE=$(http_post "/servers/${SERVER_LOGIN}/maps" '{"mx_id":"12345"}')
if [ "$CODE" = "200" ] || [ "$CODE" = "502" ] || [ "$CODE" = "404" ]; then
  echo "ok  [${CODE}] POST /maps with mx_id returned expected code"
  PASSED=$((PASSED + 1))
else
  echo "FAIL [got=${CODE}] POST /maps unexpected code"
  FAILED=$((FAILED + 1))
fi

# ─── P3.6 Remove Map ─────────────────────────────────────────────────────────

echo "--- P3.6 Remove Map ---"
CODE=$(http_delete "/servers/${SERVER_LOGIN}/maps/test-uid")
if [ "$CODE" = "200" ] || [ "$CODE" = "400" ] || [ "$CODE" = "502" ] || [ "$CODE" = "404" ]; then
  echo "ok  [${CODE}] DELETE /maps/:mapUid returned expected code"
  PASSED=$((PASSED + 1))
else
  echo "FAIL [got=${CODE}] DELETE /maps/:mapUid unexpected code"
  FAILED=$((FAILED + 1))
fi

# ─── P3.7 Extend Warmup — missing body → 400 ─────────────────────────────────

echo "--- P3.7 Extend Warmup ---"
CODE=$(http_post "/servers/${SERVER_LOGIN}/warmup/extend" '{}')
assert_status "POST /warmup/extend without seconds → 400" "400" "$CODE"

CODE=$(http_post "/servers/${SERVER_LOGIN}/warmup/extend" '{"seconds":30}')
if [ "$CODE" = "200" ] || [ "$CODE" = "502" ] || [ "$CODE" = "404" ]; then
  echo "ok  [${CODE}] POST /warmup/extend with seconds returned expected code"
  PASSED=$((PASSED + 1))
else
  echo "FAIL [got=${CODE}] POST /warmup/extend unexpected code"
  FAILED=$((FAILED + 1))
fi

# ─── P3.8 End Warmup ─────────────────────────────────────────────────────────

echo "--- P3.8 End Warmup ---"
CODE=$(http_post "/servers/${SERVER_LOGIN}/warmup/end" '{}')
if [ "$CODE" = "200" ] || [ "$CODE" = "502" ] || [ "$CODE" = "404" ]; then
  echo "ok  [${CODE}] POST /warmup/end returned expected code"
  PASSED=$((PASSED + 1))
else
  echo "FAIL [got=${CODE}] POST /warmup/end unexpected code"
  FAILED=$((FAILED + 1))
fi

# ─── P3.9 Start Pause ────────────────────────────────────────────────────────

echo "--- P3.9 Start Pause ---"
CODE=$(http_post "/servers/${SERVER_LOGIN}/pause/start" '{}')
if [ "$CODE" = "200" ] || [ "$CODE" = "502" ] || [ "$CODE" = "404" ]; then
  echo "ok  [${CODE}] POST /pause/start returned expected code"
  PASSED=$((PASSED + 1))
else
  echo "FAIL [got=${CODE}] POST /pause/start unexpected code"
  FAILED=$((FAILED + 1))
fi

# ─── P3.10 End Pause ─────────────────────────────────────────────────────────

echo "--- P3.10 End Pause ---"
CODE=$(http_post "/servers/${SERVER_LOGIN}/pause/end" '{}')
if [ "$CODE" = "200" ] || [ "$CODE" = "502" ] || [ "$CODE" = "404" ]; then
  echo "ok  [${CODE}] POST /pause/end returned expected code"
  PASSED=$((PASSED + 1))
else
  echo "FAIL [got=${CODE}] POST /pause/end unexpected code"
  FAILED=$((FAILED + 1))
fi

# ─── P3.11 Set Best-of — missing body → 400 ──────────────────────────────────

echo "--- P3.11 Set Best-of ---"
CODE=$(http_put "/servers/${SERVER_LOGIN}/match/best-of" '{}')
assert_status "PUT /match/best-of without best_of → 400" "400" "$CODE"

CODE=$(http_put "/servers/${SERVER_LOGIN}/match/best-of" '{"best_of":3}')
if [ "$CODE" = "200" ] || [ "$CODE" = "502" ] || [ "$CODE" = "404" ]; then
  echo "ok  [${CODE}] PUT /match/best-of with best_of returned expected code"
  PASSED=$((PASSED + 1))
else
  echo "FAIL [got=${CODE}] PUT /match/best-of unexpected code"
  FAILED=$((FAILED + 1))
fi

# ─── P3.12 Get Best-of ───────────────────────────────────────────────────────

echo "--- P3.12 Get Best-of ---"
CODE=$(http_get "/servers/${SERVER_LOGIN}/match/best-of")
if [ "$CODE" = "200" ] || [ "$CODE" = "502" ] || [ "$CODE" = "503" ] || [ "$CODE" = "404" ]; then
  echo "ok  [${CODE}] GET /match/best-of returned expected code"
  PASSED=$((PASSED + 1))
else
  echo "FAIL [got=${CODE}] GET /match/best-of unexpected code"
  FAILED=$((FAILED + 1))
fi

# ─── P3.13 Set Maps Score — missing body → 400 ───────────────────────────────

echo "--- P3.13 Set Maps Score ---"
CODE=$(http_put "/servers/${SERVER_LOGIN}/match/maps-score" '{}')
assert_status "PUT /match/maps-score without body → 400" "400" "$CODE"

CODE=$(http_put "/servers/${SERVER_LOGIN}/match/maps-score" '{"target_team":"team_a","maps_score":1}')
if [ "$CODE" = "200" ] || [ "$CODE" = "502" ] || [ "$CODE" = "404" ]; then
  echo "ok  [${CODE}] PUT /match/maps-score with body returned expected code"
  PASSED=$((PASSED + 1))
else
  echo "FAIL [got=${CODE}] PUT /match/maps-score unexpected code"
  FAILED=$((FAILED + 1))
fi

# ─── P3.14 Get Maps Score ────────────────────────────────────────────────────

echo "--- P3.14 Get Maps Score ---"
CODE=$(http_get "/servers/${SERVER_LOGIN}/match/maps-score")
if [ "$CODE" = "200" ] || [ "$CODE" = "502" ] || [ "$CODE" = "503" ] || [ "$CODE" = "404" ]; then
  echo "ok  [${CODE}] GET /match/maps-score returned expected code"
  PASSED=$((PASSED + 1))
else
  echo "FAIL [got=${CODE}] GET /match/maps-score unexpected code"
  FAILED=$((FAILED + 1))
fi

# ─── P3.15 Set Round Score — missing body → 400 ──────────────────────────────

echo "--- P3.15 Set Round Score ---"
CODE=$(http_put "/servers/${SERVER_LOGIN}/match/round-score" '{}')
assert_status "PUT /match/round-score without body → 400" "400" "$CODE"

CODE=$(http_put "/servers/${SERVER_LOGIN}/match/round-score" '{"target_team":"team_b","score":100}')
if [ "$CODE" = "200" ] || [ "$CODE" = "502" ] || [ "$CODE" = "404" ]; then
  echo "ok  [${CODE}] PUT /match/round-score with body returned expected code"
  PASSED=$((PASSED + 1))
else
  echo "FAIL [got=${CODE}] PUT /match/round-score unexpected code"
  FAILED=$((FAILED + 1))
fi

# ─── P3.16 Get Round Score ───────────────────────────────────────────────────

echo "--- P3.16 Get Round Score ---"
CODE=$(http_get "/servers/${SERVER_LOGIN}/match/round-score")
if [ "$CODE" = "200" ] || [ "$CODE" = "502" ] || [ "$CODE" = "503" ] || [ "$CODE" = "404" ]; then
  echo "ok  [${CODE}] GET /match/round-score returned expected code"
  PASSED=$((PASSED + 1))
else
  echo "FAIL [got=${CODE}] GET /match/round-score unexpected code"
  FAILED=$((FAILED + 1))
fi

# ─── Error cases ─────────────────────────────────────────────────────────────

echo ""
echo "--- Error cases ---"

# Missing server → 404 for all endpoints.
CODE=$(http_post "/servers/nonexistent-server/maps/skip" '{}')
assert_status "POST /maps/skip with nonexistent server → 404" "404" "$CODE"

CODE=$(http_post "/servers/nonexistent-server/warmup/end" '{}')
assert_status "POST /warmup/end with nonexistent server → 404" "404" "$CODE"

CODE=$(http_get "/servers/nonexistent-server/match/best-of")
assert_status "GET /match/best-of with nonexistent server → 404" "404" "$CODE"

# ─── Response body checks ────────────────────────────────────────────────────

echo ""
echo "--- Response body shape checks ---"

BODY=$(http_post_body "/servers/${SERVER_LOGIN}/maps/skip" '{}')
ACTUAL_CODE=$(echo "$BODY" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('code','missing'))" 2>/dev/null || echo "parse_failed")

# On 200 the code should be map_skipped; on 502 the message field should be present.
if echo "$BODY" | python3 -c "import sys,json; d=json.load(sys.stdin); assert 'message' in d or 'error' in d" 2>/dev/null; then
  echo "ok  [body_has_message_or_error] POST /maps/skip response body"
  PASSED=$((PASSED + 1))
else
  echo "FAIL [body_missing_message] POST /maps/skip response body: ${BODY}"
  FAILED=$((FAILED + 1))
fi

# Validate 400 response has validation-error shape (class-validator format).
BODY_400=$(http_post_body "/servers/${SERVER_LOGIN}/maps/jump" '{}')
if echo "$BODY_400" | python3 -c "import sys,json; d=json.load(sys.stdin); assert 'message' in d" 2>/dev/null; then
  echo "ok  [400_has_message] POST /maps/jump 400 response"
  PASSED=$((PASSED + 1))
else
  echo "FAIL [400_missing_message] POST /maps/jump 400 response: ${BODY_400}"
  FAILED=$((FAILED + 1))
fi

# ─── Summary ─────────────────────────────────────────────────────────────────

echo ""
echo "=== P3 Admin Commands Smoke Test Summary ==="
echo "Passed: ${PASSED}"
echo "Failed: ${FAILED}"
echo "Total:  $((PASSED + FAILED))"

if [ "$FAILED" -gt 0 ]; then
  exit 1
fi

exit 0
