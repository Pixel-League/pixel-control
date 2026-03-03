#!/usr/bin/env bash
# qa-p3-admin-commands-smoke.sh — P3 Admin Commands smoke test
#
# Tests all 16 P3 admin command endpoints.
# These endpoints proxy REST calls to the ManiaControl communication socket
# (AES-192-CBC TCP). When the Docker dev stack is not running, proxy calls
# return 502/503. The script handles two modes:
#
#   Default (no-socket):
#     - Registers a fresh test server and validates HTTP routing is correct.
#     - Validates DTO enforcement: missing required fields → 400.
#     - Validates unknown servers → 404.
#     - Accepts 502 or 503 for actual proxy calls (socket unavailable is expected).
#     - Validates the response body shape when 502 is returned.
#
#   Live socket mode (LIVE_SOCKET=1 or --live flag):
#     - Expects 200 for all proxy calls.
#     - Validates AdminActionResponse shape: action_name, success, code, message.
#
# Usage:
#   bash scripts/qa-p3-admin-commands-smoke.sh [--live] [API_BASE_URL]
#
# Environment variables:
#   API_BASE_URL   Default: http://localhost:3000/v1
#   LIVE_SOCKET    Set to 1 to enable live-socket mode (same as --live flag)
#   FAIL_FAST      Set to 1 to exit on first failure
#
# Requires: curl, jq
# The API server must be running before running this script.

set -uo pipefail

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------

LIVE_SOCKET="${LIVE_SOCKET:-0}"
FAIL_FAST="${FAIL_FAST:-0}"
API_BASE_URL="${API_BASE_URL:-http://localhost:3000/v1}"

for arg in "$@"; do
  case "$arg" in
    --live) LIVE_SOCKET=1 ;;
    http://*|https://*) API_BASE_URL="$arg" ;;
  esac
done

# Test server login — unique per run to avoid state conflicts
RUN_TS=$(date +%s)
SERVER_LOGIN="qa-p3-smoke-${RUN_TS}"

# Counters
PASS=0
FAIL=0
TOTAL=0

# Temp files (subshell-safe pattern: write to file, read with cat)
_BODY=$(mktemp)
_CODE=$(mktemp)

# ---------------------------------------------------------------------------
# Colors
# ---------------------------------------------------------------------------
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

# ---------------------------------------------------------------------------
# Cleanup trap
# ---------------------------------------------------------------------------
cleanup() {
  curl -s -o /dev/null -X DELETE "${API_BASE_URL}/servers/${SERVER_LOGIN}" 2>/dev/null || true
  rm -f "$_BODY" "$_CODE" 2>/dev/null || true
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
# Logging helpers
# ---------------------------------------------------------------------------
log_section() {
  echo ""
  printf "${BOLD}${CYAN}══════════════════════════════════════════════════${NC}\n"
  printf "${BOLD}${CYAN}  %s${NC}\n" "$1"
  printf "${BOLD}${CYAN}══════════════════════════════════════════════════${NC}\n"
}

log_info()  { printf "${YELLOW}  [info]${NC} %s\n" "$1"; }
log_pass()  { printf "${GREEN}  [PASS]${NC} %s\n" "$1"; }
log_fail()  { printf "${RED}  [FAIL]${NC} %s\n" "$1"; }

# ---------------------------------------------------------------------------
# Assertion helpers
# ---------------------------------------------------------------------------
_assert() {
  local label="$1" expected="$2" actual="$3"
  TOTAL=$((TOTAL + 1))
  if [ "$actual" = "$expected" ]; then
    PASS=$((PASS + 1))
    log_pass "$label"
  else
    FAIL=$((FAIL + 1))
    log_fail "$label"
    printf "         ${RED}expected:${NC} %s\n" "$expected"
    printf "         ${RED}actual:${NC}   %s\n" "$actual"
    if [ "$FAIL_FAST" = "1" ]; then
      echo ""
      printf "${RED}FAIL_FAST=1 — aborting after first failure.${NC}\n"
      exit 1
    fi
  fi
}

assert_eq()     { _assert "$1" "$2" "$3"; }
assert_status() { _assert "HTTP $1" "$2" "$3"; }

assert_contains() {
  local label="$1" substring="$2" text="$3"
  TOTAL=$((TOTAL + 1))
  if echo "$text" | grep -qF "$substring" 2>/dev/null; then
    PASS=$((PASS + 1))
    log_pass "$label"
  else
    FAIL=$((FAIL + 1))
    log_fail "$label"
    printf "         ${RED}expected to contain:${NC} %s\n" "$substring"
    printf "         ${RED}actual (truncated):${NC} %.200s\n" "$text"
    if [ "$FAIL_FAST" = "1" ]; then exit 1; fi
  fi
}

assert_not_null() {
  local label="$1" actual="$2"
  TOTAL=$((TOTAL + 1))
  if [ -n "$actual" ] && [ "$actual" != "null" ]; then
    PASS=$((PASS + 1))
    log_pass "$label"
  else
    FAIL=$((FAIL + 1))
    log_fail "$label (expected non-null, got: $actual)"
    if [ "$FAIL_FAST" = "1" ]; then exit 1; fi
  fi
}

# jq-based field assertion: assert_jq "label" "expected" "$json" ".path"
assert_jq() {
  local label="$1" expected="$2" json="$3" expr="$4"
  local actual
  actual=$(echo "$json" | jq -r "$expr" 2>/dev/null || echo "JQ_ERROR")
  _assert "$label" "$expected" "$actual"
}

assert_jq_not_null() {
  local label="$1" json="$2" expr="$3"
  local actual
  actual=$(echo "$json" | jq -r "$expr" 2>/dev/null || echo "")
  assert_not_null "$label" "$actual"
}

# ---------------------------------------------------------------------------
# HTTP helpers
# All write body to $_BODY and HTTP code to $_CODE.
# Read HTTP code back with: get_http_code
# ---------------------------------------------------------------------------

do_get() {
  local url="$1"
  local code
  code=$(curl -s -o "$_BODY" -w "%{http_code}" "$url")
  echo "$code" > "$_CODE"
  cat "$_BODY"
}

do_post() {
  local url="$1"
  local body="$2"
  if [ -z "$body" ]; then body='{}'; fi
  local code
  code=$(curl -s -o "$_BODY" -w "%{http_code}" \
    -X POST -H 'Content-Type: application/json' \
    -d "$body" "$url")
  echo "$code" > "$_CODE"
  cat "$_BODY"
}

do_put() {
  local url="$1"
  local body="$2"
  if [ -z "$body" ]; then body='{}'; fi
  local code
  code=$(curl -s -o "$_BODY" -w "%{http_code}" \
    -X PUT -H 'Content-Type: application/json' \
    -d "$body" "$url")
  echo "$code" > "$_CODE"
  cat "$_BODY"
}

do_delete() {
  local url="$1"
  local code
  code=$(curl -s -o "$_BODY" -w "%{http_code}" \
    -X DELETE "$url")
  echo "$code" > "$_CODE"
  cat "$_BODY"
}

get_http_code() { cat "$_CODE" 2>/dev/null || echo "000"; }

# ---------------------------------------------------------------------------
# Mode-aware proxy assertion
#
# In no-socket mode: accept 502 or 503 as valid outcomes for proxy calls.
# In live-socket mode: expect exactly 200 and validate response shape.
# ---------------------------------------------------------------------------
assert_proxy_call() {
  local label="$1"
  local body="$2"
  local actual_code
  actual_code=$(get_http_code)

  if [ "$LIVE_SOCKET" = "1" ]; then
    assert_status "$label" "200" "$actual_code"
    if [ "$actual_code" = "200" ]; then
      assert_jq_not_null "$label: action_name is present" "$body" ".action_name"
      assert_jq_not_null "$label: code is present"        "$body" ".code"
      assert_jq_not_null "$label: message is present"     "$body" ".message"
    fi
  else
    # Accept 200 (socket live), 502 (socket write failure), or 503 (socket unavailable).
    if [ "$actual_code" = "200" ] || [ "$actual_code" = "502" ] || [ "$actual_code" = "503" ]; then
      TOTAL=$((TOTAL + 1))
      PASS=$((PASS + 1))
      log_pass "$label [HTTP $actual_code — proxy call accepted]"
    else
      TOTAL=$((TOTAL + 1))
      FAIL=$((FAIL + 1))
      log_fail "$label [HTTP $actual_code — expected 200/502/503 for proxy call]"
      if [ "$FAIL_FAST" = "1" ]; then exit 1; fi
    fi
    # When 502/503, response must still be valid JSON with a message field.
    if [ "$actual_code" = "502" ] || [ "$actual_code" = "503" ]; then
      assert_contains "$label: error body has statusCode" '"statusCode"' "$body"
      assert_contains "$label: error body has message"   '"message"'    "$body"
    fi
  fi
}

# ---------------------------------------------------------------------------
# GET proxy assertion (read endpoints that also use 503 when socket unavailable)
# ---------------------------------------------------------------------------
assert_get_proxy_call() {
  local label="$1"
  local body="$2"
  local actual_code
  actual_code=$(get_http_code)

  if [ "$LIVE_SOCKET" = "1" ]; then
    assert_status "$label" "200" "$actual_code"
    if [ "$actual_code" = "200" ]; then
      assert_jq_not_null "$label: action_name is present" "$body" ".action_name"
      assert_jq_not_null "$label: code is present"        "$body" ".code"
      assert_jq_not_null "$label: message is present"     "$body" ".message"
    fi
  else
    if [ "$actual_code" = "200" ] || [ "$actual_code" = "502" ] || [ "$actual_code" = "503" ]; then
      TOTAL=$((TOTAL + 1))
      PASS=$((PASS + 1))
      log_pass "$label [HTTP $actual_code — proxy GET accepted]"
    else
      TOTAL=$((TOTAL + 1))
      FAIL=$((FAIL + 1))
      log_fail "$label [HTTP $actual_code — expected 200/502/503 for proxy GET]"
      if [ "$FAIL_FAST" = "1" ]; then exit 1; fi
    fi
    if [ "$actual_code" = "502" ] || [ "$actual_code" = "503" ]; then
      assert_contains "$label: error body has statusCode" '"statusCode"' "$body"
      assert_contains "$label: error body has message"   '"message"'    "$body"
    fi
  fi
}

# ===========================================================================
# MAIN
# ===========================================================================

log_section "P3 Admin Commands Smoke Test"
log_info "API:         ${API_BASE_URL}"
log_info "Server:      ${SERVER_LOGIN}"
if [ "$LIVE_SOCKET" = "1" ]; then
  log_info "Mode:        LIVE SOCKET (expects 200 for all proxy calls)"
else
  log_info "Mode:        NO-SOCKET (accepts 502/503 for proxy calls)"
fi
log_info "FAIL_FAST:   ${FAIL_FAST}"

# ---------------------------------------------------------------------------
# Prerequisites
# ---------------------------------------------------------------------------
log_section "Prerequisites"

if ! command -v curl &>/dev/null; then
  echo "ERROR: curl is required but not found in PATH" >&2
  exit 1
fi
if ! command -v jq &>/dev/null; then
  echo "ERROR: jq is required but not found in PATH (brew install jq)" >&2
  exit 1
fi

log_info "Waiting for API at ${API_BASE_URL}..."
READY=0
for i in $(seq 1 15); do
  if curl -sf "${API_BASE_URL}/servers" -o /dev/null 2>/dev/null; then
    READY=1
    break
  fi
  log_info "  Attempt $i/15 — not ready, waiting 2s..."
  sleep 2
done

if [ "$READY" -eq 0 ]; then
  echo "ERROR: API did not become ready after 30s. Is the server running?" >&2
  exit 1
fi
log_info "API is ready."

# ---------------------------------------------------------------------------
# Setup: register test server
# ---------------------------------------------------------------------------
log_section "Setup — Register Test Server"

SETUP_BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/link/registration" \
  '{"server_name":"P3 Smoke Test Server","game_mode":"Elite","title_id":"SMStormElite@nadeolabs"}')
SETUP_CODE=$(get_http_code)
assert_status "Register test server" "200" "$SETUP_CODE"

LINK_TOKEN=$(echo "$SETUP_BODY" | jq -r '.link_token // empty' 2>/dev/null || true)
assert_not_null "link_token returned on registration" "$LINK_TOKEN"
log_info "Registered server: ${SERVER_LOGIN} (token: ${LINK_TOKEN:0:8}...)"

# ---------------------------------------------------------------------------
# P3.1 Skip Map
# ---------------------------------------------------------------------------
log_section "P3.1 — Skip Map"

BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/maps/skip" '{}')
assert_proxy_call "POST /maps/skip (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P3.2 Restart Map
# ---------------------------------------------------------------------------
log_section "P3.2 — Restart Map"

BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/maps/restart" '{}')
assert_proxy_call "POST /maps/restart (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P3.3 Jump to Map
# ---------------------------------------------------------------------------
log_section "P3.3 — Jump to Map"

# Missing map_uid must return 400.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/maps/jump" '{}')
CODE=$(get_http_code)
assert_status "POST /maps/jump without map_uid → 400" "400" "$CODE"
assert_contains "400 body has message field" '"message"' "$BODY"

# With map_uid: proxy call.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/maps/jump" '{"map_uid":"test-map-uid-001"}')
assert_proxy_call "POST /maps/jump with map_uid (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P3.4 Queue Map
# ---------------------------------------------------------------------------
log_section "P3.4 — Queue Map"

# Missing map_uid must return 400.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/maps/queue" '{}')
CODE=$(get_http_code)
assert_status "POST /maps/queue without map_uid → 400" "400" "$CODE"
assert_contains "400 body has message field" '"message"' "$BODY"

# With map_uid: proxy call.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/maps/queue" '{"map_uid":"test-map-uid-002"}')
assert_proxy_call "POST /maps/queue with map_uid (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P3.5 Add Map from ManiaExchange
# ---------------------------------------------------------------------------
log_section "P3.5 — Add Map"

# Missing mx_id must return 400.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/maps" '{}')
CODE=$(get_http_code)
assert_status "POST /maps without mx_id → 400" "400" "$CODE"
assert_contains "400 body has message field" '"message"' "$BODY"

# With mx_id: proxy call.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/maps" '{"mx_id":"99999"}')
assert_proxy_call "POST /maps with mx_id (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P3.6 Remove Map
# ---------------------------------------------------------------------------
log_section "P3.6 — Remove Map"

BODY=$(do_delete "${API_BASE_URL}/servers/${SERVER_LOGIN}/maps/test-map-uid-001")
assert_proxy_call "DELETE /maps/:mapUid (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P3.7 Extend Warmup
# ---------------------------------------------------------------------------
log_section "P3.7 — Extend Warmup"

# Missing seconds must return 400.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/warmup/extend" '{}')
CODE=$(get_http_code)
assert_status "POST /warmup/extend without seconds → 400" "400" "$CODE"
assert_contains "400 body has message field" '"message"' "$BODY"

# seconds=0 must also return 400 (Min(1) constraint).
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/warmup/extend" '{"seconds":0}')
CODE=$(get_http_code)
assert_status "POST /warmup/extend with seconds=0 → 400" "400" "$CODE"

# With valid seconds: proxy call.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/warmup/extend" '{"seconds":30}')
assert_proxy_call "POST /warmup/extend with seconds=30 (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P3.8 End Warmup
# ---------------------------------------------------------------------------
log_section "P3.8 — End Warmup"

BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/warmup/end" '{}')
assert_proxy_call "POST /warmup/end (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P3.9 Start Pause
# ---------------------------------------------------------------------------
log_section "P3.9 — Start Pause"

BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/pause/start" '{}')
assert_proxy_call "POST /pause/start (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P3.10 End Pause
# ---------------------------------------------------------------------------
log_section "P3.10 — End Pause"

BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/pause/end" '{}')
assert_proxy_call "POST /pause/end (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P3.11 Set Best-of
# ---------------------------------------------------------------------------
log_section "P3.11 — Set Best-of"

# Missing best_of must return 400.
BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/match/best-of" '{}')
CODE=$(get_http_code)
assert_status "PUT /match/best-of without best_of → 400" "400" "$CODE"
assert_contains "400 body has message field" '"message"' "$BODY"

# best_of=0 must return 400 (Min(1) constraint).
BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/match/best-of" '{"best_of":0}')
CODE=$(get_http_code)
assert_status "PUT /match/best-of with best_of=0 → 400" "400" "$CODE"

# With valid best_of: proxy call.
BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/match/best-of" '{"best_of":3}')
assert_proxy_call "PUT /match/best-of with best_of=3 (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P3.12 Get Best-of
# ---------------------------------------------------------------------------
log_section "P3.12 — Get Best-of"

BODY=$(do_get "${API_BASE_URL}/servers/${SERVER_LOGIN}/match/best-of")
assert_get_proxy_call "GET /match/best-of (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P3.13 Set Maps Score
# ---------------------------------------------------------------------------
log_section "P3.13 — Set Maps Score"

# Missing required fields must return 400.
BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/match/maps-score" '{}')
CODE=$(get_http_code)
assert_status "PUT /match/maps-score without body → 400" "400" "$CODE"
assert_contains "400 body has message field" '"message"' "$BODY"

# Missing maps_score only must return 400.
BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/match/maps-score" '{"target_team":"team_a"}')
CODE=$(get_http_code)
assert_status "PUT /match/maps-score without maps_score → 400" "400" "$CODE"

# Missing target_team only must return 400.
BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/match/maps-score" '{"maps_score":1}')
CODE=$(get_http_code)
assert_status "PUT /match/maps-score without target_team → 400" "400" "$CODE"

# With both fields: proxy call.
BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/match/maps-score" '{"target_team":"team_a","maps_score":1}')
assert_proxy_call "PUT /match/maps-score with full body (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P3.14 Get Maps Score
# ---------------------------------------------------------------------------
log_section "P3.14 — Get Maps Score"

BODY=$(do_get "${API_BASE_URL}/servers/${SERVER_LOGIN}/match/maps-score")
assert_get_proxy_call "GET /match/maps-score (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P3.15 Set Round Score
# ---------------------------------------------------------------------------
log_section "P3.15 — Set Round Score"

# Missing required fields must return 400.
BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/match/round-score" '{}')
CODE=$(get_http_code)
assert_status "PUT /match/round-score without body → 400" "400" "$CODE"
assert_contains "400 body has message field" '"message"' "$BODY"

# Missing score only must return 400.
BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/match/round-score" '{"target_team":"team_b"}')
CODE=$(get_http_code)
assert_status "PUT /match/round-score without score → 400" "400" "$CODE"

# Missing target_team only must return 400.
BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/match/round-score" '{"score":100}')
CODE=$(get_http_code)
assert_status "PUT /match/round-score without target_team → 400" "400" "$CODE"

# With both fields: proxy call.
BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/match/round-score" '{"target_team":"team_b","score":100}')
assert_proxy_call "PUT /match/round-score with full body (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P3.16 Get Round Score
# ---------------------------------------------------------------------------
log_section "P3.16 — Get Round Score"

BODY=$(do_get "${API_BASE_URL}/servers/${SERVER_LOGIN}/match/round-score")
assert_get_proxy_call "GET /match/round-score (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# Error cases — nonexistent server must return 404
# ---------------------------------------------------------------------------
log_section "Error Cases — Nonexistent Server"

BODY=$(do_post "${API_BASE_URL}/servers/nonexistent-server-xyz/maps/skip" '{}')
CODE=$(get_http_code)
assert_status "POST /maps/skip with nonexistent server → 404" "404" "$CODE"

BODY=$(do_post "${API_BASE_URL}/servers/nonexistent-server-xyz/maps/restart" '{}')
CODE=$(get_http_code)
assert_status "POST /maps/restart with nonexistent server → 404" "404" "$CODE"

BODY=$(do_post "${API_BASE_URL}/servers/nonexistent-server-xyz/warmup/end" '{}')
CODE=$(get_http_code)
assert_status "POST /warmup/end with nonexistent server → 404" "404" "$CODE"

BODY=$(do_post "${API_BASE_URL}/servers/nonexistent-server-xyz/pause/start" '{}')
CODE=$(get_http_code)
assert_status "POST /pause/start with nonexistent server → 404" "404" "$CODE"

BODY=$(do_put "${API_BASE_URL}/servers/nonexistent-server-xyz/match/best-of" '{"best_of":3}')
CODE=$(get_http_code)
assert_status "PUT /match/best-of with nonexistent server → 404" "404" "$CODE"

BODY=$(do_get "${API_BASE_URL}/servers/nonexistent-server-xyz/match/best-of")
CODE=$(get_http_code)
assert_status "GET /match/best-of with nonexistent server → 404" "404" "$CODE"

BODY=$(do_get "${API_BASE_URL}/servers/nonexistent-server-xyz/match/maps-score")
CODE=$(get_http_code)
assert_status "GET /match/maps-score with nonexistent server → 404" "404" "$CODE"

BODY=$(do_get "${API_BASE_URL}/servers/nonexistent-server-xyz/match/round-score")
CODE=$(get_http_code)
assert_status "GET /match/round-score with nonexistent server → 404" "404" "$CODE"

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
log_section "Results"

printf "\n"
printf "${BOLD}  Total:  %d${NC}\n" "$TOTAL"
printf "${GREEN}  Passed: %d${NC}\n" "$PASS"
if [ "$FAIL" -gt 0 ]; then
  printf "${RED}  Failed: %d${NC}\n" "$FAIL"
else
  printf "${GREEN}  Failed: 0${NC}\n"
fi
printf "\n"

if [ "$FAIL" -gt 0 ]; then
  printf "${RED}${BOLD}  SMOKE TEST FAILED${NC}\n\n"
  exit 1
else
  printf "${GREEN}${BOLD}  All assertions passed!${NC}\n\n"
  exit 0
fi
