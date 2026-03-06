#!/usr/bin/env bash
# qa-config-templates-smoke.sh — Config Templates smoke test
#
# Tests configuration template CRUD, server-template association, template
# fallback in GET /state, apply-template, and error handling.
#
# Test cases:
#   1.  POST /config-templates — create template → 201
#   2.  GET /config-templates — list includes created template
#   3.  GET /config-templates/:id — returns template with server_count=0
#   4.  PUT /config-templates/:id — update name/config → 200
#   5.  GET /config-templates/:id — reflects update
#   6.  Register a test server
#   7.  PUT /servers/:serverLogin/config-template — link server → 200
#   8.  GET /servers/:serverLogin/config-template — returns linked template
#   9.  GET /servers/:serverLogin/state — returns template config with source=template
#  10.  POST /servers/:serverLogin/state/apply-template — apply template → 200
#  11.  GET /servers/:serverLogin/state — returns saved state with source=saved
#  12.  DELETE /servers/:serverLogin/config-template — unlink → 200
#  13.  GET /config-templates/:id — server_count=0 after unlink
#  14.  DELETE /config-templates/:id — delete template → 200
#  15.  GET /config-templates/:id — 404 after delete
#  16.  Create template, link server, try DELETE → 409
#  17.  POST /config-templates with duplicate name → 409
#  18.  GET /config-templates/nonexistent → 404
#  19.  PUT /servers/:serverLogin/config-template with nonexistent template → 404
#
# Usage:
#   bash scripts/qa-config-templates-smoke.sh [API_BASE_URL]
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

# Unique IDs per run to avoid collisions
RUN_TS=$(date +%s)
SERVER_LOGIN="qa-tpl-${RUN_TS}"
TPL_NAME="Smoke Template ${RUN_TS}"
TPL_NAME_UPDATED="Updated Template ${RUN_TS}"
TPL_NAME_2="Conflict Template ${RUN_TS}"

# Template IDs (populated during test)
TPL_ID=""
TPL_ID_2=""

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
  # Unlink server from templates
  curl -s -o /dev/null -X DELETE "${API_BASE_URL}/servers/${SERVER_LOGIN}/config-template" 2>/dev/null || true
  # Delete test templates
  if [ -n "$TPL_ID" ]; then
    curl -s -o /dev/null -X DELETE "${API_BASE_URL}/config-templates/${TPL_ID}" 2>/dev/null || true
  fi
  if [ -n "$TPL_ID_2" ]; then
    curl -s -o /dev/null -X DELETE "${API_BASE_URL}/servers/${SERVER_LOGIN}/config-template" 2>/dev/null || true
    curl -s -o /dev/null -X DELETE "${API_BASE_URL}/config-templates/${TPL_ID_2}" 2>/dev/null || true
  fi
  # Delete test server
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

# ===========================================================================
# MAIN
# ===========================================================================

log_section "Config Templates Smoke Test"
log_info "API:         ${API_BASE_URL}"
log_info "Server:      ${SERVER_LOGIN}"
log_info "Template:    ${TPL_NAME}"
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

# ===========================================================================
# Test 1 — POST /config-templates — create template
# ===========================================================================
log_section "Test 1 — Create template"

CONFIG_JSON='{"current_best_of":5,"team_maps_score":{"team_a":0,"team_b":0},"team_round_score":{"team_a":0,"team_b":0},"team_policy_enabled":true,"team_switch_lock":false,"team_roster":{},"whitelist_enabled":false,"whitelist":["admin.login"],"vote_policy":"strict","vote_ratios":{"skip":0.7}}'

BODY=$(do_post "${API_BASE_URL}/config-templates" \
  "{\"name\":\"${TPL_NAME}\",\"description\":\"Smoke test template\",\"config\":${CONFIG_JSON}}")
CODE=$(get_http_code)
assert_status "POST /config-templates returns 201" "201" "$CODE"
TPL_ID=$(echo "$BODY" | jq -r '.id // empty' 2>/dev/null || true)
assert_not_null "Template ID returned" "$TPL_ID"
assert_jq "Template name matches" "$TPL_NAME" "$BODY" ".name"
assert_jq "Template description matches" "Smoke test template" "$BODY" ".description"
assert_jq "Template config.current_best_of=5" "5" "$BODY" ".config.current_best_of"
assert_jq "Template server_count=0 on create" "0" "$BODY" ".server_count"

log_info "Created template: ${TPL_ID}"

# ===========================================================================
# Test 2 — GET /config-templates — list all
# ===========================================================================
log_section "Test 2 — List templates"

BODY=$(do_get "${API_BASE_URL}/config-templates")
CODE=$(get_http_code)
assert_status "GET /config-templates returns 200" "200" "$CODE"
assert_contains "List contains template name" "$TPL_NAME" "$BODY"

# ===========================================================================
# Test 3 — GET /config-templates/:id — single template
# ===========================================================================
log_section "Test 3 — Get single template"

BODY=$(do_get "${API_BASE_URL}/config-templates/${TPL_ID}")
CODE=$(get_http_code)
assert_status "GET /config-templates/:id returns 200" "200" "$CODE"
assert_jq "Single template name matches" "$TPL_NAME" "$BODY" ".name"
assert_jq "Single template server_count=0" "0" "$BODY" ".server_count"

# ===========================================================================
# Test 4 — PUT /config-templates/:id — update
# ===========================================================================
log_section "Test 4 — Update template"

BODY=$(do_put "${API_BASE_URL}/config-templates/${TPL_ID}" \
  "{\"name\":\"${TPL_NAME_UPDATED}\",\"config\":{\"current_best_of\":7,\"team_maps_score\":{\"team_a\":0,\"team_b\":0},\"team_round_score\":{\"team_a\":0,\"team_b\":0},\"team_policy_enabled\":false,\"team_switch_lock\":true,\"team_roster\":{},\"whitelist_enabled\":true,\"whitelist\":[],\"vote_policy\":\"default\",\"vote_ratios\":{}}}")
CODE=$(get_http_code)
assert_status "PUT /config-templates/:id returns 200" "200" "$CODE"
assert_jq "Updated name matches" "$TPL_NAME_UPDATED" "$BODY" ".name"
assert_jq "Updated config.current_best_of=7" "7" "$BODY" ".config.current_best_of"

# ===========================================================================
# Test 5 — GET /config-templates/:id — reflects update
# ===========================================================================
log_section "Test 5 — Verify update"

BODY=$(do_get "${API_BASE_URL}/config-templates/${TPL_ID}")
CODE=$(get_http_code)
assert_status "GET after update returns 200" "200" "$CODE"
assert_jq "Name reflects update" "$TPL_NAME_UPDATED" "$BODY" ".name"
assert_jq "Config reflects update: best_of=7" "7" "$BODY" ".config.current_best_of"
assert_jq "Config reflects update: team_switch_lock=true" "true" "$BODY" ".config.team_switch_lock"

# ===========================================================================
# Test 6 — Register test server
# ===========================================================================
log_section "Test 6 — Register test server"

SETUP_BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/link/registration" \
  '{"server_name":"Config Template Smoke Test Server","game_mode":"Elite","title_id":"SMStormElite@nadeolabs"}')
SETUP_CODE=$(get_http_code)
assert_status "Register test server" "200" "$SETUP_CODE"
LINK_TOKEN=$(echo "$SETUP_BODY" | jq -r '.link_token // empty' 2>/dev/null || true)
assert_not_null "link_token returned on registration" "$LINK_TOKEN"
log_info "Registered server: ${SERVER_LOGIN} (token: ${LINK_TOKEN:0:8}...)"

# ===========================================================================
# Test 7 — PUT /servers/:serverLogin/config-template — link server
# ===========================================================================
log_section "Test 7 — Link server to template"

BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/config-template" \
  "{\"template_id\":\"${TPL_ID}\"}")
CODE=$(get_http_code)
assert_status "PUT /servers/:serverLogin/config-template returns 200" "200" "$CODE"
assert_jq "Link response: linked=true" "true" "$BODY" ".linked"
assert_jq "Link response: template_id matches" "$TPL_ID" "$BODY" ".template_id"

# ===========================================================================
# Test 8 — GET /servers/:serverLogin/config-template — get linked template
# ===========================================================================
log_section "Test 8 — Get linked template for server"

BODY=$(do_get "${API_BASE_URL}/servers/${SERVER_LOGIN}/config-template")
CODE=$(get_http_code)
assert_status "GET /servers/:serverLogin/config-template returns 200" "200" "$CODE"
assert_jq "Template is not null" "$TPL_ID" "$BODY" ".template.id"
assert_jq "Template name matches" "$TPL_NAME_UPDATED" "$BODY" ".template.name"

# ===========================================================================
# Test 9 — GET /servers/:serverLogin/state — template fallback
# ===========================================================================
log_section "Test 9 — GET /state with template fallback (no saved state)"

BODY=$(do_get "${API_BASE_URL}/servers/${SERVER_LOGIN}/state")
CODE=$(get_http_code)
assert_status "GET /state returns 200" "200" "$CODE"
assert_jq "GET /state: source=template" "template" "$BODY" ".source"
assert_jq "GET /state: state is not null" "1.0" "$BODY" ".state.state_version"
assert_jq "GET /state: admin.current_best_of=7 (from template)" "7" "$BODY" ".state.admin.current_best_of"
assert_jq "GET /state: admin.team_switch_lock=true (from template)" "true" "$BODY" ".state.admin.team_switch_lock"
assert_jq "GET /state: veto_draft.matchmaking_ready_armed=false (default)" "false" "$BODY" ".state.veto_draft.matchmaking_ready_armed"

# ===========================================================================
# Test 10 — POST /servers/:serverLogin/state/apply-template
# ===========================================================================
log_section "Test 10 — Apply template as server state"

BODY=$(do_post "${API_BASE_URL}/servers/${SERVER_LOGIN}/state/apply-template" '{}')
CODE=$(get_http_code)
assert_status "POST /state/apply-template returns 200" "200" "$CODE"
assert_jq "Apply response: applied=true" "true" "$BODY" ".applied"
assert_jq "Apply response: template_id matches" "$TPL_ID" "$BODY" ".template_id"
assert_jq_not_null "Apply response: updated_at present" "$BODY" ".updated_at"

# ===========================================================================
# Test 11 — GET /servers/:serverLogin/state — saved state after apply
# ===========================================================================
log_section "Test 11 — GET /state after apply (should be source=saved)"

BODY=$(do_get "${API_BASE_URL}/servers/${SERVER_LOGIN}/state")
CODE=$(get_http_code)
assert_status "GET /state after apply returns 200" "200" "$CODE"
assert_jq "GET /state after apply: source=saved" "saved" "$BODY" ".source"
assert_jq "GET /state after apply: state_version=1.0" "1.0" "$BODY" ".state.state_version"
assert_jq "GET /state after apply: admin.current_best_of=7" "7" "$BODY" ".state.admin.current_best_of"
assert_jq_not_null "GET /state after apply: updated_at present" "$BODY" ".updated_at"

# ===========================================================================
# Test 12 — DELETE /servers/:serverLogin/config-template — unlink
# ===========================================================================
log_section "Test 12 — Unlink server from template"

BODY=$(do_delete "${API_BASE_URL}/servers/${SERVER_LOGIN}/config-template")
CODE=$(get_http_code)
assert_status "DELETE /servers/:serverLogin/config-template returns 200" "200" "$CODE"
assert_jq "Unlink response: unlinked=true" "true" "$BODY" ".unlinked"

# ===========================================================================
# Test 13 — GET /config-templates/:id — server_count=0 after unlink
# ===========================================================================
log_section "Test 13 — Verify server_count=0 after unlink"

BODY=$(do_get "${API_BASE_URL}/config-templates/${TPL_ID}")
CODE=$(get_http_code)
assert_status "GET /config-templates/:id returns 200" "200" "$CODE"
assert_jq "server_count=0 after unlink" "0" "$BODY" ".server_count"

# ===========================================================================
# Test 14 — DELETE /config-templates/:id — delete template
# ===========================================================================
log_section "Test 14 — Delete template"

BODY=$(do_delete "${API_BASE_URL}/config-templates/${TPL_ID}")
CODE=$(get_http_code)
assert_status "DELETE /config-templates/:id returns 200" "200" "$CODE"
assert_jq "Delete response: deleted=true" "true" "$BODY" ".deleted"

# ===========================================================================
# Test 15 — GET /config-templates/:id — 404 after delete
# ===========================================================================
log_section "Test 15 — Get deleted template -> 404"

BODY=$(do_get "${API_BASE_URL}/config-templates/${TPL_ID}")
CODE=$(get_http_code)
assert_status "GET deleted template -> 404" "404" "$CODE"
# Clear TPL_ID so cleanup doesn't try to delete again
TPL_ID=""

# ===========================================================================
# Test 16 — Create template, link server, try DELETE → 409
# ===========================================================================
log_section "Test 16 — Delete template with linked server -> 409"

# Create a new template
BODY=$(do_post "${API_BASE_URL}/config-templates" \
  "{\"name\":\"${TPL_NAME_2}\",\"config\":${CONFIG_JSON}}")
CODE=$(get_http_code)
assert_status "Create second template for conflict test" "201" "$CODE"
TPL_ID_2=$(echo "$BODY" | jq -r '.id // empty' 2>/dev/null || true)
assert_not_null "Second template ID returned" "$TPL_ID_2"

# Link server to this template
BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/config-template" \
  "{\"template_id\":\"${TPL_ID_2}\"}")
CODE=$(get_http_code)
assert_status "Link server to second template" "200" "$CODE"

# Try to delete — should get 409
BODY=$(do_delete "${API_BASE_URL}/config-templates/${TPL_ID_2}")
CODE=$(get_http_code)
assert_status "DELETE template with linked server -> 409" "409" "$CODE"
assert_contains "409 message mentions servers linked" "linked" "$BODY"

# ===========================================================================
# Test 17 — POST /config-templates with duplicate name → 409
# ===========================================================================
log_section "Test 17 — Create template with duplicate name -> 409"

BODY=$(do_post "${API_BASE_URL}/config-templates" \
  "{\"name\":\"${TPL_NAME_2}\",\"config\":${CONFIG_JSON}}")
CODE=$(get_http_code)
assert_status "POST with duplicate name -> 409" "409" "$CODE"

# ===========================================================================
# Test 18 — GET /config-templates/nonexistent → 404
# ===========================================================================
log_section "Test 18 — Get nonexistent template -> 404"

BODY=$(do_get "${API_BASE_URL}/config-templates/nonexistent-uuid-xyz")
CODE=$(get_http_code)
assert_status "GET nonexistent template -> 404" "404" "$CODE"

# ===========================================================================
# Test 19 — PUT /servers/:serverLogin/config-template with nonexistent template → 404
# ===========================================================================
log_section "Test 19 — Link to nonexistent template -> 404"

BODY=$(do_put "${API_BASE_URL}/servers/${SERVER_LOGIN}/config-template" \
  '{"template_id":"nonexistent-template-uuid-xyz"}')
CODE=$(get_http_code)
assert_status "PUT with nonexistent template_id -> 404" "404" "$CODE"

# ===========================================================================
# Summary
# ===========================================================================
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
