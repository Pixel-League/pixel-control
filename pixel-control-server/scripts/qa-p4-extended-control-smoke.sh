#!/usr/bin/env bash
# qa-p4-extended-control-smoke.sh — P4 Extended Control smoke test
#
# Tests all 13 P4 extended control endpoints:
#   P4.1--P4.5: VetoDraft flow (PixelControl.VetoDraft.* socket methods)
#   P4.6--P4.8: Player management (PixelControl.Admin.ExecuteAction)
#   P4.9--P4.13: Team control (PixelControl.Admin.ExecuteAction)
#
# These endpoints proxy REST calls to the ManiaControl communication socket
# (AES-192-CBC TCP). When the Docker dev stack is not running, proxy calls
# return 502/503. The script handles two modes:
#
#   Default (no-socket):
#     - Registers a fresh test server and validates HTTP routing is correct.
#     - Validates DTO enforcement: missing required fields -> 400.
#     - Validates unknown servers -> 404.
#     - Accepts 502 or 503 for actual proxy calls (socket unavailable is expected).
#     - Validates the response body shape when 502 is returned.
#
#   Live socket mode (LIVE_SOCKET=1 or --live flag):
#     - Expects 200 for all proxy calls.
#     - Validates response shape.
#
# Usage:
#   bash scripts/qa-p4-extended-control-smoke.sh [--live] [API_BASE_URL]
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
SERVER_LOGIN="qa-p4-smoke-${RUN_TS}"

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
# ---------------------------------------------------------------------------
assert_proxy_call() {
  local label="$1"
  local body="$2"
  local actual_code
  actual_code=$(get_http_code)

  if [ "$LIVE_SOCKET" = "1" ]; then
    assert_status "$label" "200" "$actual_code"
    if [ "$actual_code" = "200" ]; then
      assert_jq_not_null "$label: success is present" "$body" ".success"
      assert_jq_not_null "$label: code is present"    "$body" ".code"
      assert_jq_not_null "$label: message is present" "$body" ".message"
    fi
  else
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
    if [ "$actual_code" = "502" ] || [ "$actual_code" = "503" ]; then
      assert_contains "$label: error body has statusCode" '"statusCode"' "$body"
      assert_contains "$label: error body has message"   '"message"'    "$body"
    fi
  fi
}

assert_get_proxy_call() {
  local label="$1"
  local body="$2"
  local actual_code
  actual_code=$(get_http_code)

  if [ "$LIVE_SOCKET" = "1" ]; then
    assert_status "$label" "200" "$actual_code"
    if [ "$actual_code" = "200" ]; then
      assert_jq_not_null "$label: success is present" "$body" ".success"
      assert_jq_not_null "$label: code is present"    "$body" ".code"
      assert_jq_not_null "$label: message is present" "$body" ".message"
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

log_section "P4 Extended Control Smoke Test"
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

SETUP_BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/link/registration" \
  '{"server_name":"P4 Smoke Test Server","game_mode":"Elite","title_id":"SMStormElite@nadeolabs"}')
SETUP_CODE=$(get_http_code)
assert_status "Register test server" "200" "$SETUP_CODE"

LINK_TOKEN=$(echo "$SETUP_BODY" | jq -r '.link_token // empty' 2>/dev/null || true)
assert_not_null "link_token returned on registration" "$LINK_TOKEN"
log_info "Registered server: ${SERVER_LOGIN} (token: ${LINK_TOKEN:0:8}...)"

# ---------------------------------------------------------------------------
# P4.1 — Veto Status
# ---------------------------------------------------------------------------
log_section "P4.1 — Veto Status"

BODY=$(do_get "${API_BASE_URL}/servers/${SERVER_LOGIN}/veto/status")
assert_get_proxy_call "GET /veto/status (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P4.2 — Arm Ready
# ---------------------------------------------------------------------------
log_section "P4.2 — Arm Ready"

BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/veto/ready" '{}')
assert_proxy_call "POST /veto/ready (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P4.3 — Start Veto Session
# ---------------------------------------------------------------------------
log_section "P4.3 — Start Veto Session"

# Missing mode must return 400.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/veto/start" '{}')
CODE=$(get_http_code)
assert_status "POST /veto/start without mode -> 400" "400" "$CODE"
assert_contains "400 body has message field" '"message"' "$BODY"

# With valid mode (matchmaking_vote): proxy call.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/veto/start" \
  '{"mode":"matchmaking_vote","duration_seconds":60}')
assert_proxy_call "POST /veto/start with mode=matchmaking_vote (proxy)" "$BODY"

# With tournament_draft mode and captains: proxy call.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/veto/start" \
  '{"mode":"tournament_draft","captain_a":"cap.a.login","captain_b":"cap.b.login","best_of":3}')
assert_proxy_call "POST /veto/start with mode=tournament_draft (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P4.4 — Submit Veto Action
# ---------------------------------------------------------------------------
log_section "P4.4 — Submit Veto Action"

# Missing actor_login must return 400.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/veto/action" '{}')
CODE=$(get_http_code)
assert_status "POST /veto/action without actor_login -> 400" "400" "$CODE"
assert_contains "400 body has message field" '"message"' "$BODY"

# With valid actor_login: proxy call.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/veto/action" \
  '{"actor_login":"cap.a.login","map":"test-map-uid-001","operation":"ban"}')
assert_proxy_call "POST /veto/action with valid body (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P4.5 — Cancel Veto Session
# ---------------------------------------------------------------------------
log_section "P4.5 — Cancel Veto Session"

BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/veto/cancel" '{}')
assert_proxy_call "POST /veto/cancel (proxy)" "$BODY"

# Cancel with optional reason.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/veto/cancel" \
  '{"reason":"Admin cancelled for testing"}')
assert_proxy_call "POST /veto/cancel with reason (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P4.6 — Force Team
# ---------------------------------------------------------------------------
log_section "P4.6 — Force Team"

# Missing team must return 400.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/players/test.player.login/force-team" '{}')
CODE=$(get_http_code)
assert_status "POST /players/:login/force-team without team -> 400" "400" "$CODE"
assert_contains "400 body has message field" '"message"' "$BODY"

# With valid team: proxy call.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/players/test.player.login/force-team" \
  '{"team":"team_a"}')
assert_proxy_call "POST /players/:login/force-team with team=team_a (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P4.7 — Force Play
# ---------------------------------------------------------------------------
log_section "P4.7 — Force Play"

BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/players/test.player.login/force-play" '{}')
assert_proxy_call "POST /players/:login/force-play (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P4.8 — Force Spec
# ---------------------------------------------------------------------------
log_section "P4.8 — Force Spec"

BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/players/test.player.login/force-spec" '{}')
assert_proxy_call "POST /players/:login/force-spec (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P4.9 — Set Team Policy
# ---------------------------------------------------------------------------
log_section "P4.9 — Set Team Policy"

# Missing enabled must return 400.
BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/teams/policy" '{}')
CODE=$(get_http_code)
assert_status "PUT /teams/policy without enabled -> 400" "400" "$CODE"
assert_contains "400 body has message field" '"message"' "$BODY"

# With valid enabled=true: proxy call.
BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/teams/policy" \
  '{"enabled":true,"switch_lock":false}')
assert_proxy_call "PUT /teams/policy with enabled=true (proxy)" "$BODY"

# With enabled=false: proxy call.
BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/teams/policy" \
  '{"enabled":false}')
assert_proxy_call "PUT /teams/policy with enabled=false (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P4.10 — Get Team Policy
# ---------------------------------------------------------------------------
log_section "P4.10 — Get Team Policy"

BODY=$(do_get "${API_BASE_URL}/servers/${SERVER_LOGIN}/teams/policy")
assert_get_proxy_call "GET /teams/policy (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P4.11 — Assign Roster
# ---------------------------------------------------------------------------
log_section "P4.11 — Assign Roster"

# Missing target_login must return 400.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/teams/roster" '{}')
CODE=$(get_http_code)
assert_status "POST /teams/roster without body -> 400" "400" "$CODE"
assert_contains "400 body has message field" '"message"' "$BODY"

# Missing team only must return 400.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/teams/roster" \
  '{"target_login":"player.one"}')
CODE=$(get_http_code)
assert_status "POST /teams/roster without team -> 400" "400" "$CODE"

# With valid body: proxy call.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/teams/roster" \
  '{"target_login":"player.one","team":"team_a"}')
assert_proxy_call "POST /teams/roster with valid body (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P4.12 — Unassign Roster
# ---------------------------------------------------------------------------
log_section "P4.12 — Unassign Roster"

BODY=$(do_delete "${API_BASE_URL}/servers/${SERVER_LOGIN}/teams/roster/player.one")
assert_proxy_call "DELETE /teams/roster/:login (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P4.13 — List Roster
# ---------------------------------------------------------------------------
log_section "P4.13 — List Roster"

BODY=$(do_get "${API_BASE_URL}/servers/${SERVER_LOGIN}/teams/roster")
assert_get_proxy_call "GET /teams/roster (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# Error cases — nonexistent server must return 404
# ---------------------------------------------------------------------------
log_section "Error Cases — Nonexistent Server"

BODY=$(do_get "${API_BASE_URL}/servers/nonexistent-server-xyz/veto/status")
CODE=$(get_http_code)
assert_status "GET /veto/status with nonexistent server -> 404" "404" "$CODE"

BODY=$(do_post "${API_BASE_URL}/servers/nonexistent-server-xyz/veto/ready" '{}')
CODE=$(get_http_code)
assert_status "POST /veto/ready with nonexistent server -> 404" "404" "$CODE"

BODY=$(do_post "${API_BASE_URL}/servers/nonexistent-server-xyz/veto/start" \
  '{"mode":"matchmaking_vote"}')
CODE=$(get_http_code)
assert_status "POST /veto/start with nonexistent server -> 404" "404" "$CODE"

BODY=$(do_post "${API_BASE_URL}/servers/nonexistent-server-xyz/players/player.one/force-team" \
  '{"team":"team_a"}')
CODE=$(get_http_code)
assert_status "POST /players/:login/force-team with nonexistent server -> 404" "404" "$CODE"

BODY=$(do_post "${API_BASE_URL}/servers/nonexistent-server-xyz/players/player.one/force-play" '{}')
CODE=$(get_http_code)
assert_status "POST /players/:login/force-play with nonexistent server -> 404" "404" "$CODE"

BODY=$(do_put "${API_BASE_URL}/servers/nonexistent-server-xyz/teams/policy" \
  '{"enabled":true}')
CODE=$(get_http_code)
assert_status "PUT /teams/policy with nonexistent server -> 404" "404" "$CODE"

BODY=$(do_get "${API_BASE_URL}/servers/nonexistent-server-xyz/teams/policy")
CODE=$(get_http_code)
assert_status "GET /teams/policy with nonexistent server -> 404" "404" "$CODE"

BODY=$(do_post "${API_BASE_URL}/servers/nonexistent-server-xyz/teams/roster" \
  '{"target_login":"p","team":"team_a"}')
CODE=$(get_http_code)
assert_status "POST /teams/roster with nonexistent server -> 404" "404" "$CODE"

BODY=$(do_get "${API_BASE_URL}/servers/nonexistent-server-xyz/teams/roster")
CODE=$(get_http_code)
assert_status "GET /teams/roster with nonexistent server -> 404" "404" "$CODE"

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
