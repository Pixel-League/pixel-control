#!/usr/bin/env bash
# qa-p5-backlog-smoke.sh — P5 Backlog smoke test
#
# Tests all 14 P5 backlog endpoints:
#   P5.1--P5.2: Auth management (POST/DELETE players/:login/auth)
#   P5.3--P5.9: Whitelist management (enable/disable/add/remove/list/clean/sync)
#   P5.10--P5.14: Vote management (cancel/ratio/custom/policy get/policy set)
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
#   bash scripts/qa-p5-backlog-smoke.sh [--live] [API_BASE_URL]
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
SERVER_LOGIN="qa-p5-smoke-${RUN_TS}"

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

log_section "P5 Backlog Smoke Test"
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
  '{"server_name":"P5 Smoke Test Server","game_mode":"Elite","title_id":"SMStormElite@nadeolabs"}')
SETUP_CODE=$(get_http_code)
assert_status "Register test server" "200" "$SETUP_CODE"

LINK_TOKEN=$(echo "$SETUP_BODY" | jq -r '.link_token // empty' 2>/dev/null || true)
assert_not_null "link_token returned on registration" "$LINK_TOKEN"
log_info "Registered server: ${SERVER_LOGIN} (token: ${LINK_TOKEN:0:8}...)"

# ---------------------------------------------------------------------------
# P5.1 — Grant Auth
# ---------------------------------------------------------------------------
log_section "P5.1 — Grant Auth"

# Missing auth_level must return 400.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/players/test.player.login/auth" '{}')
CODE=$(get_http_code)
assert_status "POST /players/:login/auth without auth_level -> 400" "400" "$CODE"
assert_contains "400 body has message field" '"message"' "$BODY"

# With valid auth_level: proxy call.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/players/test.player.login/auth" \
  '{"auth_level":"admin"}')
assert_proxy_call "POST /players/:login/auth with auth_level=admin (proxy)" "$BODY"

# With moderator level: proxy call.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/players/test.player.login/auth" \
  '{"auth_level":"moderator"}')
assert_proxy_call "POST /players/:login/auth with auth_level=moderator (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P5.2 — Revoke Auth
# ---------------------------------------------------------------------------
log_section "P5.2 — Revoke Auth"

BODY=$(do_delete "${API_BASE_URL}/servers/${SERVER_LOGIN}/players/test.player.login/auth")
assert_proxy_call "DELETE /players/:login/auth (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P5.3 — Enable Whitelist
# ---------------------------------------------------------------------------
log_section "P5.3 — Enable Whitelist"

BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/whitelist/enable" '{}')
assert_proxy_call "POST /whitelist/enable (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P5.4 — Disable Whitelist
# ---------------------------------------------------------------------------
log_section "P5.4 — Disable Whitelist"

BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/whitelist/disable" '{}')
assert_proxy_call "POST /whitelist/disable (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P5.5 — Add to Whitelist
# ---------------------------------------------------------------------------
log_section "P5.5 — Add to Whitelist"

# Missing target_login must return 400.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/whitelist" '{}')
CODE=$(get_http_code)
assert_status "POST /whitelist without target_login -> 400" "400" "$CODE"
assert_contains "400 body has message field" '"message"' "$BODY"

# With valid target_login: proxy call.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/whitelist" \
  '{"target_login":"testplayer.login"}')
assert_proxy_call "POST /whitelist with target_login (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P5.6 — Remove from Whitelist
# ---------------------------------------------------------------------------
log_section "P5.6 — Remove from Whitelist"

BODY=$(do_delete "${API_BASE_URL}/servers/${SERVER_LOGIN}/whitelist/testplayer.login")
assert_proxy_call "DELETE /whitelist/:login (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P5.7 — List Whitelist
# ---------------------------------------------------------------------------
log_section "P5.7 — List Whitelist"

BODY=$(do_get "${API_BASE_URL}/servers/${SERVER_LOGIN}/whitelist")
assert_get_proxy_call "GET /whitelist (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P5.8 — Clean Whitelist (bare DELETE)
# ---------------------------------------------------------------------------
log_section "P5.8 — Clean Whitelist (bare DELETE)"

BODY=$(do_delete "${API_BASE_URL}/servers/${SERVER_LOGIN}/whitelist")
assert_proxy_call "DELETE /whitelist (clean all — proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P5.9 — Sync Whitelist
# ---------------------------------------------------------------------------
log_section "P5.9 — Sync Whitelist"

BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/whitelist/sync" '{}')
assert_proxy_call "POST /whitelist/sync (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P5.10 — Cancel Vote
# ---------------------------------------------------------------------------
log_section "P5.10 — Cancel Vote"

BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/votes/cancel" '{}')
assert_proxy_call "POST /votes/cancel (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P5.11 — Set Vote Ratio
# ---------------------------------------------------------------------------
log_section "P5.11 — Set Vote Ratio"

# Missing command/ratio must return 400.
BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/votes/ratio" '{}')
CODE=$(get_http_code)
assert_status "PUT /votes/ratio without body -> 400" "400" "$CODE"
assert_contains "400 body has message field" '"message"' "$BODY"

# With valid body: proxy call.
BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/votes/ratio" \
  '{"command":"skip","ratio":0.5}')
assert_proxy_call "PUT /votes/ratio with valid body (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P5.12 — Start Custom Vote
# ---------------------------------------------------------------------------
log_section "P5.12 — Start Custom Vote"

# Missing vote_index must return 400.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/votes/custom" '{}')
CODE=$(get_http_code)
assert_status "POST /votes/custom without vote_index -> 400" "400" "$CODE"
assert_contains "400 body has message field" '"message"' "$BODY"

# With valid vote_index: proxy call.
BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/votes/custom" \
  '{"vote_index":1}')
assert_proxy_call "POST /votes/custom with vote_index=1 (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P5.13 — Get Vote Policy
# ---------------------------------------------------------------------------
log_section "P5.13 — Get Vote Policy"

BODY=$(do_get "${API_BASE_URL}/servers/${SERVER_LOGIN}/votes/policy")
assert_get_proxy_call "GET /votes/policy (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# P5.14 — Set Vote Policy
# ---------------------------------------------------------------------------
log_section "P5.14 — Set Vote Policy"

# Missing mode must return 400.
BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/votes/policy" '{}')
CODE=$(get_http_code)
assert_status "PUT /votes/policy without mode -> 400" "400" "$CODE"
assert_contains "400 body has message field" '"message"' "$BODY"

# With valid mode: proxy call.
BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/votes/policy" \
  '{"mode":"strict"}')
assert_proxy_call "PUT /votes/policy with mode=strict (proxy)" "$BODY"

# With lenient mode: proxy call.
BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/votes/policy" \
  '{"mode":"lenient"}')
assert_proxy_call "PUT /votes/policy with mode=lenient (proxy)" "$BODY"

# ---------------------------------------------------------------------------
# Error cases — nonexistent server must return 404
# ---------------------------------------------------------------------------
log_section "Error Cases — Nonexistent Server"

BODY=$(do_post "${API_BASE_URL}/servers/nonexistent-server-xyz/players/player.one/auth" \
  '{"auth_level":"admin"}')
CODE=$(get_http_code)
assert_status "POST /players/:login/auth with nonexistent server -> 404" "404" "$CODE"

BODY=$(do_delete "${API_BASE_URL}/servers/nonexistent-server-xyz/players/player.one/auth")
CODE=$(get_http_code)
assert_status "DELETE /players/:login/auth with nonexistent server -> 404" "404" "$CODE"

BODY=$(do_post "${API_BASE_URL}/servers/nonexistent-server-xyz/whitelist/enable" '{}')
CODE=$(get_http_code)
assert_status "POST /whitelist/enable with nonexistent server -> 404" "404" "$CODE"

BODY=$(do_post "${API_BASE_URL}/servers/nonexistent-server-xyz/whitelist" \
  '{"target_login":"player.one"}')
CODE=$(get_http_code)
assert_status "POST /whitelist with nonexistent server -> 404" "404" "$CODE"

BODY=$(do_get "${API_BASE_URL}/servers/nonexistent-server-xyz/whitelist")
CODE=$(get_http_code)
assert_status "GET /whitelist with nonexistent server -> 404" "404" "$CODE"

BODY=$(do_delete "${API_BASE_URL}/servers/nonexistent-server-xyz/whitelist")
CODE=$(get_http_code)
assert_status "DELETE /whitelist with nonexistent server -> 404" "404" "$CODE"

BODY=$(do_post "${API_BASE_URL}/servers/nonexistent-server-xyz/votes/cancel" '{}')
CODE=$(get_http_code)
assert_status "POST /votes/cancel with nonexistent server -> 404" "404" "$CODE"

BODY=$(do_put "${API_BASE_URL}/servers/nonexistent-server-xyz/votes/ratio" \
  '{"command":"skip","ratio":0.5}')
CODE=$(get_http_code)
assert_status "PUT /votes/ratio with nonexistent server -> 404" "404" "$CODE"

BODY=$(do_get "${API_BASE_URL}/servers/nonexistent-server-xyz/votes/policy")
CODE=$(get_http_code)
assert_status "GET /votes/policy with nonexistent server -> 404" "404" "$CODE"

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
