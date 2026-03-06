#!/usr/bin/env bash
# qa-state-sync-smoke.sh — Server State Sync smoke test
#
# Tests both state sync endpoints:
#   State.1: GET /v1/servers/:serverLogin/state  — returns persisted plugin state snapshot
#   State.2: POST /v1/servers/:serverLogin/state — saves plugin state snapshot
#
# These endpoints are direct Prisma CRUD (no socket proxy). All responses
# are expected to be 200 when the server is running and the server login is valid.
#
# Test cases:
#   1. GET state for newly registered server → 200, state=null
#   2. POST state with full valid snapshot → 200, saved=true
#   3. GET state after save → 200, state matches snapshot
#   4. POST state with updated snapshot (upsert) → 200, saved=true
#   5. GET state again → 200, state matches updated snapshot
#   6. GET state for nonexistent server → 404
#   7. POST state for nonexistent server → 404
#   8. POST state with wrong auth token → 403
#   9. POST state with empty body → 400
#  10. POST state with missing state_version → 400
#  11. GET state: state_version field is preserved as returned
#  12. GET state: admin sub-object is preserved
#  13. GET state: veto_draft sub-object is preserved
#  14. POST state: updated_at field is returned
#  15. GET state: updated_at field is present
#
# Usage:
#   bash scripts/qa-state-sync-smoke.sh [API_BASE_URL]
#
# Environment variables:
#   API_BASE_URL   Default: http://localhost:3000/v1
#   FAIL_FAST      Set to 1 to exit on first failure
#
# Requires: curl, jq
# The API server must be running before running this script.

set -uo pipefail

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------

FAIL_FAST="${FAIL_FAST:-0}"
API_BASE_URL="${API_BASE_URL:-http://localhost:3000/v1}"

for arg in "$@"; do
  case "$arg" in
    http://*|https://*) API_BASE_URL="$arg" ;;
  esac
done

# Test server login — unique per run to avoid state conflicts
RUN_TS=$(date +%s)
SERVER_LOGIN="qa-state-sync-${RUN_TS}"

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

do_get_with_headers() {
  local url="$1"
  shift
  local code
  code=$(curl -s -o "$_BODY" -w "%{http_code}" "$@" "$url")
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

do_post_with_token() {
  local url="$1"
  local body="$2"
  local token="$3"
  if [ -z "$body" ]; then body='{}'; fi
  local code
  code=$(curl -s -o "$_BODY" -w "%{http_code}" \
    -X POST -H 'Content-Type: application/json' \
    -H "Authorization: Bearer ${token}" \
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

# ===========================================================================
# MAIN
# ===========================================================================

log_section "Server State Sync Smoke Test"
log_info "API:         ${API_BASE_URL}"
log_info "Server:      ${SERVER_LOGIN}"
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
  '{"server_name":"State Sync Smoke Test Server","game_mode":"Elite","title_id":"SMStormElite@nadeolabs"}')
SETUP_CODE=$(get_http_code)
assert_status "Register test server" "200" "$SETUP_CODE"

LINK_TOKEN=$(echo "$SETUP_BODY" | jq -r '.link_token // empty' 2>/dev/null || true)
assert_not_null "link_token returned on registration" "$LINK_TOKEN"
log_info "Registered server: ${SERVER_LOGIN} (token: ${LINK_TOKEN:0:8}...)"

# ---------------------------------------------------------------------------
# State.1 — GET state for newly registered server (no prior state)
# ---------------------------------------------------------------------------
log_section "State.1 — GET state (no prior save — expect null state)"

BODY=$(do_get "${API_BASE_URL}/servers/${SERVER_LOGIN}/state")
CODE=$(get_http_code)
assert_status "GET /state returns 200" "200" "$CODE"
assert_jq "GET /state: state field is null" "null" "$BODY" ".state"
assert_jq "GET /state: updated_at is null when no state" "null" "$BODY" ".updated_at"

# ---------------------------------------------------------------------------
# State.2 — POST state with full valid snapshot
# ---------------------------------------------------------------------------
log_section "State.2 — POST state (save full snapshot with auth)"

SNAPSHOT='{"state_version":"1.0","captured_at":1741276800,"admin":{"current_best_of":5,"team_maps_score":{"team_a":2,"team_b":1},"team_round_score":{"team_a":3,"team_b":0},"team_policy_enabled":true,"team_switch_lock":false,"team_roster":{"player.one":"team_a"},"whitelist_enabled":true,"whitelist":["player.one","player.two"],"vote_policy":"strict","vote_ratios":{"skip":0.6}},"veto_draft":{"session":null,"matchmaking_ready_armed":true,"votes":{}}}'

BODY=$(do_post_with_token "${API_BASE_URL}/servers/${SERVER_LOGIN}/state" "$SNAPSHOT" "$LINK_TOKEN")
CODE=$(get_http_code)
assert_status "POST /state with valid snapshot returns 200" "200" "$CODE"
assert_jq "POST /state: saved=true" "true" "$BODY" ".saved"
assert_jq_not_null "POST /state: updated_at is present" "$BODY" ".updated_at"

# ---------------------------------------------------------------------------
# State.3 — GET state after first save
# ---------------------------------------------------------------------------
log_section "State.3 — GET state (after first save)"

BODY=$(do_get "${API_BASE_URL}/servers/${SERVER_LOGIN}/state")
CODE=$(get_http_code)
assert_status "GET /state after save returns 200" "200" "$CODE"
assert_jq "GET /state: state_version=1.0" "1.0" "$BODY" ".state.state_version"
assert_jq "GET /state: admin.current_best_of=5" "5" "$BODY" ".state.admin.current_best_of"
assert_jq "GET /state: admin.vote_policy=strict" "strict" "$BODY" ".state.admin.vote_policy"
assert_jq "GET /state: admin.whitelist_enabled=true" "true" "$BODY" ".state.admin.whitelist_enabled"
assert_jq "GET /state: veto_draft.matchmaking_ready_armed=true" "true" "$BODY" ".state.veto_draft.matchmaking_ready_armed"
assert_jq "GET /state: admin.team_maps_score.team_a=2" "2" "$BODY" ".state.admin.team_maps_score.team_a"
assert_jq_not_null "GET /state: updated_at is present after save" "$BODY" ".updated_at"

# ---------------------------------------------------------------------------
# State.4 — POST state with updated snapshot (upsert)
# ---------------------------------------------------------------------------
log_section "State.4 — POST state (upsert with updated values)"

UPDATED_SNAPSHOT='{"state_version":"1.0","captured_at":1741277000,"admin":{"current_best_of":3,"team_maps_score":{"team_a":0,"team_b":0},"team_round_score":{"team_a":0,"team_b":0},"team_policy_enabled":false,"team_switch_lock":true,"team_roster":{},"whitelist_enabled":false,"whitelist":[],"vote_policy":"default","vote_ratios":{}},"veto_draft":{"session":{"active":true,"mode":"draft","steps":["map_a"]},"matchmaking_ready_armed":false,"votes":{}}}'

BODY=$(do_post_with_token "${API_BASE_URL}/servers/${SERVER_LOGIN}/state" "$UPDATED_SNAPSHOT" "$LINK_TOKEN")
CODE=$(get_http_code)
assert_status "POST /state upsert returns 200" "200" "$CODE"
assert_jq "POST /state upsert: saved=true" "true" "$BODY" ".saved"

# ---------------------------------------------------------------------------
# State.5 — GET state after upsert (verify updated values)
# ---------------------------------------------------------------------------
log_section "State.5 — GET state (after upsert — verify updated values)"

BODY=$(do_get "${API_BASE_URL}/servers/${SERVER_LOGIN}/state")
CODE=$(get_http_code)
assert_status "GET /state after upsert returns 200" "200" "$CODE"
assert_jq "GET /state after upsert: admin.current_best_of=3" "3" "$BODY" ".state.admin.current_best_of"
assert_jq "GET /state after upsert: admin.vote_policy=default" "default" "$BODY" ".state.admin.vote_policy"
assert_jq "GET /state after upsert: veto_draft.session.mode=draft" "draft" "$BODY" ".state.veto_draft.session.mode"
assert_jq "GET /state after upsert: admin.team_switch_lock=true" "true" "$BODY" ".state.admin.team_switch_lock"

# ---------------------------------------------------------------------------
# State.6 — GET state for nonexistent server → 404
# ---------------------------------------------------------------------------
log_section "State.6 — GET state for nonexistent server → 404"

BODY=$(do_get "${API_BASE_URL}/servers/nonexistent-server-xyz/state")
CODE=$(get_http_code)
assert_status "GET /state with nonexistent server -> 404" "404" "$CODE"

# ---------------------------------------------------------------------------
# State.7 — POST state for nonexistent server → 404
# ---------------------------------------------------------------------------
log_section "State.7 — POST state for nonexistent server → 404"

BODY=$(do_post_with_token "${API_BASE_URL}/servers/nonexistent-server-xyz/state" \
  '{"state_version":"1.0","captured_at":1741276800,"admin":{"current_best_of":3,"team_maps_score":{"team_a":0,"team_b":0},"team_round_score":{"team_a":0,"team_b":0},"team_policy_enabled":false,"team_switch_lock":false,"team_roster":{},"whitelist_enabled":false,"whitelist":[],"vote_policy":"default","vote_ratios":{}},"veto_draft":{"session":null,"matchmaking_ready_armed":false,"votes":{}}}' \
  "fake-token-xyz")
CODE=$(get_http_code)
assert_status "POST /state with nonexistent server -> 404" "404" "$CODE"

# ---------------------------------------------------------------------------
# State.8 — POST state with wrong auth token → 403
# ---------------------------------------------------------------------------
log_section "State.8 — POST state with wrong auth token → 403"

BODY=$(do_post_with_token "${API_BASE_URL}/servers/${SERVER_LOGIN}/state" \
  '{"state_version":"1.0","captured_at":1741276800,"admin":{"current_best_of":3,"team_maps_score":{"team_a":0,"team_b":0},"team_round_score":{"team_a":0,"team_b":0},"team_policy_enabled":false,"team_switch_lock":false,"team_roster":{},"whitelist_enabled":false,"whitelist":[],"vote_policy":"default","vote_ratios":{}},"veto_draft":{"session":null,"matchmaking_ready_armed":false,"votes":{}}}' \
  "invalid-token-that-does-not-match")
CODE=$(get_http_code)
assert_status "POST /state with wrong token -> 403" "403" "$CODE"

# ---------------------------------------------------------------------------
# State.9 — POST state with empty body → 400
# ---------------------------------------------------------------------------
log_section "State.9 — POST state with empty body → 400"

BODY=$(do_post_with_token "${API_BASE_URL}/servers/${SERVER_LOGIN}/state" '{}' "$LINK_TOKEN")
CODE=$(get_http_code)
assert_status "POST /state with empty body -> 400" "400" "$CODE"
assert_contains "400 body has message field" '"message"' "$BODY"

# ---------------------------------------------------------------------------
# State.10 — POST state with missing state_version → 400
# ---------------------------------------------------------------------------
log_section "State.10 — POST state with missing state_version → 400"

BODY=$(do_post_with_token "${API_BASE_URL}/servers/${SERVER_LOGIN}/state" \
  '{"captured_at":1741276800,"admin":{"current_best_of":3,"team_maps_score":{"team_a":0,"team_b":0},"team_round_score":{"team_a":0,"team_b":0},"team_policy_enabled":false,"team_switch_lock":false,"team_roster":{},"whitelist_enabled":false,"whitelist":[],"vote_policy":"default","vote_ratios":{}},"veto_draft":{"session":null,"matchmaking_ready_armed":false,"votes":{}}}' \
  "$LINK_TOKEN")
CODE=$(get_http_code)
assert_status "POST /state without state_version -> 400" "400" "$CODE"
assert_contains "400 body has message field" '"message"' "$BODY"

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
