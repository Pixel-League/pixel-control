#!/usr/bin/env bash
# qa-p2-smoke.sh — P2 Read API smoke test (enhanced with deep jq assertions)
# Prerequisites: server running on localhost:3000, PostgreSQL on :5433, jq installed
# Usage: bash scripts/qa-p2-smoke.sh [API_BASE]
#   FAIL_FAST=1 bash scripts/qa-p2-smoke.sh  — exit on first failure
set -uo pipefail

API="${1:-http://localhost:3000/v1}"
SERVER_LOGIN="qa-p2-live"
PASS=0
FAIL=0
FAIL_FAST="${FAIL_FAST:-0}"
# Run-unique timestamp prefix to avoid idempotency key collisions across runs
RUN_TS=$(date +%s)

# ──────────────────────────────────────────────────────────────────────────────
# Prerequisite checks
# ──────────────────────────────────────────────────────────────────────────────
if ! command -v curl &>/dev/null; then
  echo "ERROR: curl is required but not found in PATH" >&2
  exit 1
fi
if ! command -v jq &>/dev/null; then
  echo "ERROR: jq is required but not found in PATH (brew install jq)" >&2
  exit 1
fi

# ──────────────────────────────────────────────────────────────────────────────
# Cleanup trap
# ──────────────────────────────────────────────────────────────────────────────
cleanup() {
  echo ""
  echo "Cleaning up test server..."
  curl -s -o /dev/null -X DELETE "${API}/servers/${SERVER_LOGIN}" || true
  rm -f "$_SEQ_FILE" 2>/dev/null || true
}
trap cleanup EXIT

# ──────────────────────────────────────────────────────────────────────────────
# Server readiness wait loop
# ──────────────────────────────────────────────────────────────────────────────
echo "Waiting for API to be ready..."
READY=0
for i in $(seq 1 15); do
  if curl -sf "${API}/servers" -o /dev/null 2>/dev/null; then
    READY=1
    break
  fi
  echo "  Attempt $i/15 — not ready yet, waiting 2s..."
  sleep 2
done

if [ "$READY" -eq 0 ]; then
  echo "ERROR: API did not become ready after 30s. Is the server running?" >&2
  exit 1
fi
echo "  API is ready."

# ──────────────────────────────────────────────────────────────────────────────
# Helpers
# ──────────────────────────────────────────────────────────────────────────────
green() { printf '\033[32m%s\033[0m\n' "$*"; }
red()   { printf '\033[31m%s\033[0m\n' "$*"; }

fail_check() {
  if [ "$FAIL_FAST" = "1" ]; then
    echo ""
    red "FAIL_FAST=1 — aborting after first failure."
    exit 1
  fi
}

assert_status() {
  local label="$1" expected="$2" actual="$3"
  if [ "$actual" -eq "$expected" ]; then
    green "  PASS [$label] — HTTP $actual"
    PASS=$((PASS + 1))
  else
    red  "  FAIL [$label] — expected HTTP $expected, got $actual"
    FAIL=$((FAIL + 1))
    fail_check
  fi
}

assert_contains() {
  local label="$1" needle="$2" haystack="$3"
  if echo "$haystack" | grep -q "$needle"; then
    green "  PASS [$label] — found '$needle'"
    PASS=$((PASS + 1))
  else
    red  "  FAIL [$label] — '$needle' not found in response"
    FAIL=$((FAIL + 1))
    fail_check
  fi
}

# assert_jq: assert that jq expression applied to JSON string equals expected value
# Usage: assert_jq "label" "expected_string" "$json_body" ".jq_expression"
assert_jq() {
  local label="$1" expected="$2" json="$3" expr="$4"
  local actual
  actual=$(echo "$json" | jq -r "$expr" 2>/dev/null)
  if [ "$actual" = "$expected" ]; then
    green "  PASS [$label] — $expr = $expected"
    PASS=$((PASS + 1))
  else
    red  "  FAIL [$label] — $expr: expected='$expected', got='$actual'"
    FAIL=$((FAIL + 1))
    fail_check
  fi
}

# assert_jq_gte: assert that jq expression >= expected integer
assert_jq_gte() {
  local label="$1" expected="$2" json="$3" expr="$4"
  local actual
  actual=$(echo "$json" | jq -r "$expr" 2>/dev/null)
  if [ -n "$actual" ] && [ "$actual" != "null" ] && [ "$actual" -ge "$expected" ] 2>/dev/null; then
    green "  PASS [$label] — $expr = $actual (>= $expected)"
    PASS=$((PASS + 1))
  else
    red  "  FAIL [$label] — $expr: expected >= $expected, got='$actual'"
    FAIL=$((FAIL + 1))
    fail_check
  fi
}

# assert_jq_not_null: assert that jq expression is not null/empty
assert_jq_not_null() {
  local label="$1" json="$2" expr="$3"
  local actual
  actual=$(echo "$json" | jq -r "$expr" 2>/dev/null)
  if [ -n "$actual" ] && [ "$actual" != "null" ]; then
    green "  PASS [$label] — $expr is not null ($actual)"
    PASS=$((PASS + 1))
  else
    red  "  FAIL [$label] — $expr is null or empty"
    FAIL=$((FAIL + 1))
    fail_check
  fi
}

# Build common plugin event fields
# Use a temp file for the counter so it persists across subshell boundaries
_SEQ_FILE=$(mktemp)
echo "0" > "$_SEQ_FILE"
next_seq() {
  local val
  val=$(cat "$_SEQ_FILE")
  val=$((val + 1))
  echo "$val" > "$_SEQ_FILE"
  echo "$val"
}

make_envelope() {
  local category="$1" callback="$2" payload_json="$3"
  local s
  s=$(next_seq)
  # Use RUN_TS * 1000 + seq to guarantee strict ordering of events even within same second
  local ts=$(( RUN_TS * 1000 + s ))
  local event_name="pixel_control.${category}.${callback}"
  local event_id="pc-evt-${category}-${callback}-${RUN_TS}-${s}"
  local idem_key="pc-idem-${RUN_TS}-${s}-$(printf '%s' "$event_id" | shasum | cut -c1-8)"
  cat <<EOF
{
  "event_name": "${event_name}",
  "schema_version": "2026-02-20.1",
  "event_id": "${event_id}",
  "event_category": "${category}",
  "source_callback": "${callback}",
  "source_sequence": ${s},
  "source_time": ${ts},
  "idempotency_key": "${idem_key}",
  "payload": ${payload_json}
}
EOF
}

send_event() {
  local category="$1" callback="$2" payload_json="$3"
  local body
  body=$(make_envelope "$category" "$callback" "$payload_json")
  curl -s -o /dev/null -w "%{http_code}" -X POST \
    "${API}/plugin/events" \
    -H "Content-Type: application/json" \
    -H "X-Pixel-Server-Login: ${SERVER_LOGIN}" \
    -H "X-Pixel-Plugin-Version: 1.0.0-smoke" \
    -d "$body"
}

get_json() {
  curl -s "${API}/$1"
}

get_status() {
  curl -s -o /dev/null -w "%{http_code}" "${API}/$1"
}

echo ""
echo "════════════════════════════════════════════════════════"
echo "  Pixel Control — P2 Read API Smoke Test (Enhanced)"
echo "  API: ${API}"
echo "  Server login: ${SERVER_LOGIN}"
echo "  Run timestamp: ${RUN_TS}"
echo "════════════════════════════════════════════════════════"
echo ""

# ──────────────────────────────────────────────────────────────────────────────
# SECTION 0 — Seed data (register + events)
# ──────────────────────────────────────────────────────────────────────────────
echo "▶ Section 0: Seeding test data"

# Register server
# Use separate files to avoid macOS head -n limitation
_reg_body_file=$(mktemp)
sc=$(curl -s -o "$_reg_body_file" -w "%{http_code}" -X PUT \
  "${API}/servers/${SERVER_LOGIN}/link/registration" \
  -H "Content-Type: application/json" \
  -d '{"server_name":"QA P2 Live Server","game_mode":"Elite","title_id":"SMStormElite@nadeolabs"}')
reg_body=$(cat "$_reg_body_file")
rm -f "$_reg_body_file"
assert_status "server-registration" 200 "$sc"
assert_jq_not_null "server-registration-link-token" "$reg_body" ".link_token"

# Seed connectivity — registration event with capabilities
sc=$(send_event "connectivity" "plugin_registration" '{
  "event_kind": "registration",
  "capabilities": {
    "admin_control": {"enabled": true},
    "queue": {"max_size": 2000},
    "transport": {"mode": "bearer"},
    "callbacks": {"connectivity": true, "lifecycle": true}
  },
  "context": {"players": {"active": 2, "total": 3, "spectators": 1}}
}')
assert_status "connectivity-registration-event" 200 "$sc"

# Seed connectivity — heartbeat event
sc=$(send_event "connectivity" "plugin_heartbeat" '{
  "event_kind": "heartbeat",
  "context": {"players": {"active": 2, "total": 3, "spectators": 1}},
  "queue": {"depth": 0, "max_size": 2000, "high_watermark": 0, "dropped_on_capacity": 0, "dropped_on_identity_validation": 0, "recovery_flush_pending": false},
  "retry": {"max_retry_attempts": 3, "retry_backoff_ms": 250, "dispatch_batch_size": 3},
  "outage": {"active": false, "started_at": null, "failure_count": 0, "last_error_code": null, "recovery_flush_pending": false}
}')
assert_status "connectivity-heartbeat-event" 200 "$sc"

# Seed player events — live-player-1 connects
sc=$(send_event "player" "player_connect" '{
  "event_kind": "player.connect",
  "player": {"login": "live-player-1", "nickname": "Live One", "team_id": 0, "is_spectator": false, "is_connected": true, "has_joined_game": true, "auth_level": 0, "auth_name": "player"},
  "state_delta": {"connectivity_state": "connected", "readiness_state": "ready", "eligibility_state": "eligible"},
  "permission_signals": {"can_play": true},
  "roster_state": {"slot": 0}
}')
assert_status "player-connect-1" 200 "$sc"

# Seed player events — live-player-2 connects
sc=$(send_event "player" "player_connect" '{
  "event_kind": "player.connect",
  "player": {"login": "live-player-2", "nickname": "Live Two", "team_id": 1, "is_spectator": false, "is_connected": true, "has_joined_game": true, "auth_level": 0, "auth_name": "player"},
  "state_delta": {"connectivity_state": "connected", "readiness_state": "ready", "eligibility_state": "eligible"}
}')
assert_status "player-connect-2" 200 "$sc"

# Seed player events — live-player-2 disconnects
sc=$(send_event "player" "player_disconnect" '{
  "event_kind": "player.disconnect",
  "player": {"login": "live-player-2", "nickname": "Live Two", "is_connected": false}
}')
assert_status "player-disconnect-2" 200 "$sc"

# Seed combat events — onshoot (initial counters)
sc=$(send_event "combat" "onshoot" '{
  "event_kind": "onshoot",
  "player_counters": {
    "live-player-1": {"kills": 5, "deaths": 2, "hits": 20, "shots": 100, "misses": 80, "rockets": 50, "lasers": 50}
  }
}')
assert_status "combat-onshoot" 200 "$sc"

# Seed combat events — onarmorempty (updated cumulative counters)
sc=$(send_event "combat" "onarmorempty" '{
  "event_kind": "onarmorempty",
  "player_counters": {
    "live-player-1": {"kills": 8, "deaths": 3, "hits": 30, "shots": 150, "misses": 120, "rockets": 75, "lasers": 75}
  }
}')
assert_status "combat-onarmorempty" 200 "$sc"

# Seed combat events — scores snapshot
sc=$(send_event "combat" "scores" '{
  "event_kind": "scores",
  "player_counters": {
    "live-player-1": {"kills": 8, "deaths": 3, "hits": 30, "shots": 150, "misses": 120, "rockets": 75, "lasers": 75}
  },
  "scores_section": "EndRound",
  "scores_snapshot": {"teams": [{"id": 0, "score": 3}, {"id": 1, "score": 1}], "players": [{"login": "live-player-1", "score": 8}]},
  "scores_result": {"result_state": "team_win", "winning_side": "team_a", "winning_reason": "score_limit"}
}')
assert_status "combat-scores" 200 "$sc"

# Seed lifecycle — match.begin with map_rotation (2 maps)
sc=$(send_event "lifecycle" "sm_begin_match" '{
  "variant": "match.begin",
  "map_rotation": {
    "map_pool": [{"uid": "uid-arena", "name": "Arena", "file": "Arena.Gbx"}, {"uid": "uid-coliseum", "name": "Coliseum", "file": "Coliseum.Gbx"}],
    "map_pool_size": 2,
    "current_map": {"uid": "uid-arena", "name": "Arena", "file": "Arena.Gbx"},
    "current_map_index": 0,
    "next_maps": [{"uid": "uid-coliseum"}],
    "played_map_order": [],
    "played_map_count": 0,
    "series_targets": {"best_of": 3},
    "veto_draft_mode": "tournament_draft",
    "veto_draft_session_status": "completed"
  }
}')
assert_status "lifecycle-match-begin" 200 "$sc"

# Seed lifecycle — map.begin (reuse same map_rotation)
sc=$(send_event "lifecycle" "sm_begin_map" '{
  "variant": "map.begin",
  "map_rotation": {
    "map_pool": [{"uid": "uid-arena", "name": "Arena", "file": "Arena.Gbx"}, {"uid": "uid-coliseum", "name": "Coliseum", "file": "Coliseum.Gbx"}],
    "map_pool_size": 2,
    "current_map": {"uid": "uid-arena", "name": "Arena", "file": "Arena.Gbx"},
    "current_map_index": 0,
    "next_maps": [],
    "played_map_order": [],
    "played_map_count": 0,
    "series_targets": {"best_of": 3}
  }
}')
assert_status "lifecycle-map-begin" 200 "$sc"

# Seed lifecycle — round.begin
sc=$(send_event "lifecycle" "sm_begin_round" '{"variant": "round.begin"}')
assert_status "lifecycle-round-begin" 200 "$sc"

# Seed lifecycle — round.end with aggregate_stats
sc=$(send_event "lifecycle" "sm_end_round" '{
  "variant": "round.end",
  "aggregate_stats": {
    "scope": "round",
    "counter_scope": "combat_delta",
    "player_counters_delta": {"live-player-1": {"kills": 8, "deaths": 3}},
    "totals": {"total_kills": 8},
    "team_counters_delta": [{"team_id": 0, "score": 1}],
    "team_summary": {"winner": 0},
    "tracked_player_count": 1,
    "window": {"start": 1000000, "end": 2000000, "duration": 1000},
    "win_context": {"result_state": "team_win", "winning_side": "team_a", "winning_reason": "score_limit"}
  }
}')
assert_status "lifecycle-round-end-aggregate" 200 "$sc"

# Seed mode event — elite startturn
sc=$(send_event "mode" "sm_elite_startturn" '{
  "raw_callback_summary": {"turn_number": 1, "attacker": "live-player-1", "defenders": ["live-player-2"]}
}')
assert_status "mode-elite-startturn" 200 "$sc"

echo ""
echo "▶ Section 1: P2.1 — GET /players (player list)"

resp=$(get_json "servers/${SERVER_LOGIN}/players")
sc=$(get_status "servers/${SERVER_LOGIN}/players")
assert_status "GET-players-status" 200 "$sc"
assert_jq_gte "GET-players-data-length" 2 "$resp" ".data | length"
# live-player-1 should be connected
assert_jq "GET-players-player1-connected" "true" "$resp" '.data[] | select(.login == "live-player-1") | .is_connected'
# live-player-2 should be disconnected (is_connected: false after disconnect event)
assert_jq "GET-players-player2-disconnected" "false" "$resp" '.data[] | select(.login == "live-player-2") | .is_connected'
assert_jq_not_null "GET-players-pagination" "$resp" ".pagination"
assert_jq_gte "GET-players-pagination-total" 2 "$resp" ".pagination.total"

echo ""
echo "▶ Section 2: P2.1 — Pagination validation"

resp_pg1=$(get_json "servers/${SERVER_LOGIN}/players?limit=1")
sc=$(get_status "servers/${SERVER_LOGIN}/players?limit=1")
assert_status "GET-players-limit1-status" 200 "$sc"
assert_jq "GET-players-limit1-length" "1" "$resp_pg1" ".data | length"

resp_pg2=$(get_json "servers/${SERVER_LOGIN}/players?limit=1&offset=1")
sc=$(get_status "servers/${SERVER_LOGIN}/players?limit=1&offset=1")
assert_status "GET-players-limit1-offset1-status" 200 "$sc"
assert_jq "GET-players-limit1-offset1-length" "1" "$resp_pg2" ".data | length"

echo ""
echo "▶ Section 3: P2.2 — GET /players/live-player-1 (single player detail)"

resp=$(get_json "servers/${SERVER_LOGIN}/players/live-player-1")
sc=$(get_status "servers/${SERVER_LOGIN}/players/live-player-1")
assert_status "GET-player-detail-status" 200 "$sc"
assert_jq "GET-player-detail-login" "live-player-1" "$resp" ".login"
assert_jq_not_null "GET-player-detail-last-event-id" "$resp" ".last_event_id"
assert_jq_not_null "GET-player-detail-permission-signals" "$resp" ".permission_signals"
assert_jq "GET-player-detail-is-connected" "true" "$resp" ".is_connected"

echo ""
echo "▶ Section 4: P2.2 — GET /players/unknown-xyz (404)"

sc=$(get_status "servers/${SERVER_LOGIN}/players/unknown-xyz")
assert_status "GET-player-unknown-404" 404 "$sc"

echo ""
echo "▶ Section 5: P2.3 — GET /stats/combat"

resp=$(get_json "servers/${SERVER_LOGIN}/stats/combat")
sc=$(get_status "servers/${SERVER_LOGIN}/stats/combat")
assert_status "GET-stats-combat-status" 200 "$sc"
assert_jq_not_null "GET-stats-combat-summary" "$resp" ".combat_summary"
assert_jq_gte "GET-stats-combat-total-events" 3 "$resp" ".combat_summary.total_events"
# Latest combat event with counters has kills:8 for live-player-1
assert_jq "GET-stats-combat-total-kills" "8" "$resp" ".combat_summary.total_kills"
assert_jq "GET-stats-combat-tracked-players" "1" "$resp" ".combat_summary.tracked_player_count"
assert_jq_gte "GET-stats-combat-event-kinds-count" 2 "$resp" ".combat_summary.event_kinds | length"
assert_jq_not_null "GET-stats-combat-time-range" "$resp" ".time_range"

echo ""
echo "▶ Section 6: P2.4 — GET /stats/combat/players"

resp=$(get_json "servers/${SERVER_LOGIN}/stats/combat/players")
sc=$(get_status "servers/${SERVER_LOGIN}/stats/combat/players")
assert_status "GET-combat-players-status" 200 "$sc"
assert_jq_not_null "GET-combat-players-data" "$resp" ".data"
# Only live-player-1 has counters in our seed
assert_jq_gte "GET-combat-players-data-length" 1 "$resp" ".data | length"
# The kills from the latest event (onarmorempty or scores both have kills:8)
assert_jq "GET-combat-players-player1-kills" "8" "$resp" '.data[] | select(.login == "live-player-1") | .kills'
assert_jq_not_null "GET-combat-players-pagination" "$resp" ".pagination"

echo ""
echo "▶ Section 7: P2.5 — GET /stats/combat/players/live-player-1"

resp=$(get_json "servers/${SERVER_LOGIN}/stats/combat/players/live-player-1")
sc=$(get_status "servers/${SERVER_LOGIN}/stats/combat/players/live-player-1")
assert_status "GET-combat-player-detail-status" 200 "$sc"
assert_jq "GET-combat-player-detail-login" "live-player-1" "$resp" ".login"
assert_jq "GET-combat-player-detail-kills" "8" "$resp" ".counters.kills"
assert_jq "GET-combat-player-detail-shots" "150" "$resp" ".counters.shots"
assert_jq_not_null "GET-combat-player-detail-last-updated" "$resp" ".last_updated"

echo ""
echo "▶ Section 8: P2.5 — GET /stats/combat/players/unknown-xyz (404)"

sc=$(get_status "servers/${SERVER_LOGIN}/stats/combat/players/unknown-xyz")
assert_status "GET-combat-player-unknown-404" 404 "$sc"

echo ""
echo "▶ Section 9: P2.6 — GET /stats/scores"

resp=$(get_json "servers/${SERVER_LOGIN}/stats/scores")
sc=$(get_status "servers/${SERVER_LOGIN}/stats/scores")
assert_status "GET-scores-status" 200 "$sc"
assert_jq "GET-scores-section" "EndRound" "$resp" ".scores_section"
assert_jq "GET-scores-result-state" "team_win" "$resp" ".scores_result.result_state"
assert_jq_not_null "GET-scores-event-id" "$resp" ".event_id"
assert_jq_not_null "GET-scores-snapshot" "$resp" ".scores_snapshot"

echo ""
echo "▶ Section 10: P2.7 — GET /lifecycle"

resp=$(get_json "servers/${SERVER_LOGIN}/lifecycle")
sc=$(get_status "servers/${SERVER_LOGIN}/lifecycle")
assert_status "GET-lifecycle-status" 200 "$sc"
assert_jq_not_null "GET-lifecycle-current-phase" "$resp" ".current_phase"
# Latest event is round.end → current_phase should be "round"
assert_jq "GET-lifecycle-current-phase-value" "round" "$resp" ".current_phase"
assert_jq "GET-lifecycle-match-variant" "match.begin" "$resp" ".match.variant"
assert_jq "GET-lifecycle-map-variant" "map.begin" "$resp" ".map.variant"
assert_jq_not_null "GET-lifecycle-warmup" "$resp" ".warmup"
# No warmup events in our seed → active should be false
assert_jq "GET-lifecycle-warmup-inactive" "false" "$resp" ".warmup.active"

echo ""
echo "▶ Section 11: P2.8 — GET /lifecycle/map-rotation"

resp=$(get_json "servers/${SERVER_LOGIN}/lifecycle/map-rotation")
sc=$(get_status "servers/${SERVER_LOGIN}/lifecycle/map-rotation")
assert_status "GET-map-rotation-status" 200 "$sc"
assert_jq_gte "GET-map-rotation-pool-length" 2 "$resp" ".map_pool | length"
assert_jq "GET-map-rotation-current-map-uid" "uid-arena" "$resp" ".current_map.uid"
assert_jq_not_null "GET-map-rotation-event-id" "$resp" ".event_id"

echo ""
echo "▶ Section 12: P2.9 — GET /lifecycle/aggregate-stats"

resp=$(get_json "servers/${SERVER_LOGIN}/lifecycle/aggregate-stats")
sc=$(get_status "servers/${SERVER_LOGIN}/lifecycle/aggregate-stats")
assert_status "GET-aggregate-stats-status" 200 "$sc"
assert_jq_gte "GET-aggregate-stats-length" 1 "$resp" ".aggregates | length"
assert_jq "GET-aggregate-stats-scope" "round" "$resp" ".aggregates[0].scope"

echo ""
echo "▶ Section 13: P2.9 — GET /lifecycle/aggregate-stats?scope=round"

resp=$(get_json "servers/${SERVER_LOGIN}/lifecycle/aggregate-stats?scope=round")
sc=$(get_status "servers/${SERVER_LOGIN}/lifecycle/aggregate-stats?scope=round")
assert_status "GET-aggregate-stats-round-status" 200 "$sc"
assert_jq_gte "GET-aggregate-stats-round-length" 1 "$resp" ".aggregates | length"
assert_jq "GET-aggregate-stats-round-win-context" "team_win" "$resp" '.aggregates[] | select(.scope == "round") | .win_context.result_state'

echo ""
echo "▶ Section 14: P2.10 — GET /status/capabilities"

resp=$(get_json "servers/${SERVER_LOGIN}/status/capabilities")
sc=$(get_status "servers/${SERVER_LOGIN}/status/capabilities")
assert_status "GET-capabilities-status" 200 "$sc"
assert_jq_not_null "GET-capabilities-field" "$resp" ".capabilities"
assert_jq "GET-capabilities-admin-enabled" "true" "$resp" ".capabilities.admin_control.enabled"
assert_jq "GET-capabilities-source" "plugin_registration" "$resp" ".source"

echo ""
echo "▶ Section 15: P2.11 — GET /maps"

resp=$(get_json "servers/${SERVER_LOGIN}/maps")
sc=$(get_status "servers/${SERVER_LOGIN}/maps")
assert_status "GET-maps-status" 200 "$sc"
assert_jq_gte "GET-maps-array-length" 2 "$resp" ".maps | length"
assert_jq "GET-maps-count" "2" "$resp" ".map_count"
assert_jq "GET-maps-current-map-uid" "uid-arena" "$resp" ".current_map.uid"

echo ""
echo "▶ Section 16: P2.12 — GET /mode"

resp=$(get_json "servers/${SERVER_LOGIN}/mode")
sc=$(get_status "servers/${SERVER_LOGIN}/mode")
assert_status "GET-mode-status" 200 "$sc"
assert_jq "GET-mode-game-mode" "Elite" "$resp" ".game_mode"
assert_jq_gte "GET-mode-total-events" 1 "$resp" ".total_mode_events"
assert_jq_not_null "GET-mode-recent-events" "$resp" ".recent_mode_events"
assert_jq "GET-mode-turn-number" "1" "$resp" ".recent_mode_events[0].raw_callback_summary.turn_number"

echo ""
echo "▶ Section 17: Cross-cutting 404s for unknown server"

sc=$(get_status "servers/nonexistent-server-xyz/players")
assert_status "GET-players-unknown-server-404" 404 "$sc"

sc=$(get_status "servers/nonexistent-server-xyz/stats/combat")
assert_status "GET-combat-unknown-server-404" 404 "$sc"

sc=$(get_status "servers/nonexistent-server-xyz/stats/scores")
assert_status "GET-scores-unknown-server-404" 404 "$sc"

sc=$(get_status "servers/nonexistent-server-xyz/lifecycle")
assert_status "GET-lifecycle-unknown-server-404" 404 "$sc"

sc=$(get_status "servers/nonexistent-server-xyz/lifecycle/map-rotation")
assert_status "GET-map-rotation-unknown-server-404" 404 "$sc"

sc=$(get_status "servers/nonexistent-server-xyz/lifecycle/aggregate-stats")
assert_status "GET-aggregate-stats-unknown-server-404" 404 "$sc"

sc=$(get_status "servers/nonexistent-server-xyz/status/capabilities")
assert_status "GET-capabilities-unknown-server-404" 404 "$sc"

sc=$(get_status "servers/nonexistent-server-xyz/maps")
assert_status "GET-maps-unknown-server-404" 404 "$sc"

sc=$(get_status "servers/nonexistent-server-xyz/mode")
assert_status "GET-mode-unknown-server-404" 404 "$sc"

echo ""
echo "▶ Section 18: Cleanup"

sc=$(curl -s -o /dev/null -w "%{http_code}" -X DELETE \
  "${API}/servers/${SERVER_LOGIN}")
assert_status "cleanup-delete-server" 200 "$sc"

# Disable trap cleanup since we already cleaned up
trap - EXIT

# ──────────────────────────────────────────────────────────────────────────────
# Summary
# ──────────────────────────────────────────────────────────────────────────────
echo ""
echo "════════════════════════════════════════════════════════"
TOTAL=$((PASS + FAIL))
if [ "$FAIL" -eq 0 ]; then
  green "  ALL $PASS/$TOTAL ASSERTIONS PASSED"
else
  red   "  $FAIL/$TOTAL ASSERTIONS FAILED (passed: $PASS)"
fi
echo "════════════════════════════════════════════════════════"
echo ""

[ "$FAIL" -eq 0 ]
