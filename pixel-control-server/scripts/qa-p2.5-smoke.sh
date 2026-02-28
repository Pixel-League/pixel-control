#!/usr/bin/env bash
# qa-p2.5-smoke.sh — P2.5 Per-Map / Per-Series Combat Stats smoke test
# Prerequisites: server running on localhost:3000, PostgreSQL on :5433, jq installed
# Usage: bash scripts/qa-p2.5-smoke.sh [API_BASE]
#   FAIL_FAST=1 bash scripts/qa-p2.5-smoke.sh  — exit on first failure
set -uo pipefail

API="${1:-http://localhost:3000/v1}"
SERVER_LOGIN="qa-p25-live"
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

# Sequence counter (persisted across subshell boundaries)
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
echo "  Pixel Control — P2.5 Per-Map/Series Combat Stats Smoke Test"
echo "  API: ${API}"
echo "  Server login: ${SERVER_LOGIN}"
echo "  Run timestamp: ${RUN_TS}"
echo "════════════════════════════════════════════════════════"
echo ""

# ──────────────────────────────────────────────────────────────────────────────
# SECTION 0 — Seed data
# ──────────────────────────────────────────────────────────────────────────────
echo "▶ Section 0: Seeding test data"

# Register server
_reg_body_file=$(mktemp)
sc=$(curl -s -o "$_reg_body_file" -w "%{http_code}" -X PUT \
  "${API}/servers/${SERVER_LOGIN}/link/registration" \
  -H "Content-Type: application/json" \
  -d '{"server_name":"QA P2.5 Server","game_mode":"Elite","title_id":"SMStormElite@nadeolabs"}')
reg_body=$(cat "$_reg_body_file")
rm -f "$_reg_body_file"
assert_status "server-registration" 200 "$sc"
assert_jq_not_null "server-registration-link-token" "$reg_body" ".link_token"

# --- Series 1: match.begin → map 1 (uid-alpha) → map 2 (uid-bravo) → match.end ---

# match.begin
sc=$(send_event "lifecycle" "sm_begin_match" '{
  "variant": "match.begin",
  "map_rotation": {
    "map_pool": [
      {"uid": "uid-alpha", "name": "Alpha Arena", "file": "Alpha.Gbx"},
      {"uid": "uid-bravo", "name": "Bravo Stadium", "file": "Bravo.Gbx"},
      {"uid": "uid-charlie", "name": "Charlie Grounds", "file": "Charlie.Gbx"}
    ],
    "map_pool_size": 3,
    "current_map": {"uid": "uid-alpha", "name": "Alpha Arena", "file": "Alpha.Gbx"},
    "current_map_index": 0,
    "next_maps": [{"uid": "uid-bravo"}, {"uid": "uid-charlie"}],
    "played_map_order": [],
    "played_map_count": 0,
    "series_targets": {"best_of": 3}
  }
}')
assert_status "lifecycle-match-begin" 200 "$sc"

# map.begin (map 1: uid-alpha)
sc=$(send_event "lifecycle" "sm_begin_map" '{
  "variant": "map.begin",
  "map_rotation": {
    "current_map": {"uid": "uid-alpha", "name": "Alpha Arena", "file": "Alpha.Gbx"},
    "current_map_index": 0
  }
}')
assert_status "lifecycle-map-begin-alpha" 200 "$sc"

# map.end (map 1: uid-alpha) with aggregate_stats scope=map
sc=$(send_event "lifecycle" "sm_end_map" '{
  "variant": "map.end",
  "aggregate_stats": {
    "scope": "map",
    "counter_scope": "combat_delta",
    "player_counters_delta": {
      "player-alpha": {"kills": 10, "deaths": 3, "hits": 50, "shots": 100, "misses": 50, "rockets": 30, "lasers": 20, "accuracy": 0.50},
      "player-beta":  {"kills": 6,  "deaths": 5, "hits": 30, "shots": 80,  "misses": 50, "rockets": 20, "lasers": 10, "accuracy": 0.375}
    },
    "team_counters_delta": [
      {"team_id": 0, "totals": {"kills": 10, "deaths": 3}, "player_logins": ["player-alpha"]},
      {"team_id": 1, "totals": {"kills": 6,  "deaths": 5}, "player_logins": ["player-beta"]}
    ],
    "totals": {"kills": 16, "deaths": 8, "hits": 80, "shots": 180},
    "win_context": {"result_state": "determined", "winner_team_id": 0},
    "window": {"started_at": 1000000, "ended_at": 1120000, "duration_seconds": 120}
  },
  "map_rotation": {
    "current_map": {"uid": "uid-alpha", "name": "Alpha Arena", "file": "Alpha.Gbx"},
    "played_map_order": ["uid-alpha"]
  }
}')
assert_status "lifecycle-map-end-alpha" 200 "$sc"

# map.begin (map 2: uid-bravo)
sc=$(send_event "lifecycle" "sm_begin_map" '{
  "variant": "map.begin",
  "map_rotation": {
    "current_map": {"uid": "uid-bravo", "name": "Bravo Stadium", "file": "Bravo.Gbx"},
    "current_map_index": 1
  }
}')
assert_status "lifecycle-map-begin-bravo" 200 "$sc"

# map.end (map 2: uid-bravo)
sc=$(send_event "lifecycle" "sm_end_map" '{
  "variant": "map.end",
  "aggregate_stats": {
    "scope": "map",
    "counter_scope": "combat_delta",
    "player_counters_delta": {
      "player-alpha": {"kills": 8,  "deaths": 4, "hits": 40, "shots": 90,  "misses": 50, "rockets": 25, "lasers": 15, "accuracy": 0.444},
      "player-beta":  {"kills": 12, "deaths": 2, "hits": 60, "shots": 110, "misses": 50, "rockets": 40, "lasers": 20, "accuracy": 0.545}
    },
    "team_counters_delta": [
      {"team_id": 0, "totals": {"kills": 8, "deaths": 4}, "player_logins": ["player-alpha"]},
      {"team_id": 1, "totals": {"kills": 12, "deaths": 2}, "player_logins": ["player-beta"]}
    ],
    "totals": {"kills": 20, "deaths": 6, "hits": 100, "shots": 200},
    "win_context": {"result_state": "determined", "winner_team_id": 1},
    "window": {"started_at": 1200000, "ended_at": 1320000, "duration_seconds": 120}
  },
  "map_rotation": {
    "current_map": {"uid": "uid-bravo", "name": "Bravo Stadium", "file": "Bravo.Gbx"},
    "played_map_order": ["uid-alpha", "uid-bravo"]
  }
}')
assert_status "lifecycle-map-end-bravo" 200 "$sc"

# match.end
sc=$(send_event "lifecycle" "sm_end_match" '{
  "variant": "match.end",
  "aggregate_stats": {
    "win_context": {"result_state": "determined", "winner_team_id": 1}
  }
}')
assert_status "lifecycle-match-end" 200 "$sc"

echo ""
echo "▶ Section 1: GET /stats/combat/maps — list all maps"

resp=$(get_json "servers/${SERVER_LOGIN}/stats/combat/maps")
sc=$(get_status "servers/${SERVER_LOGIN}/stats/combat/maps")
assert_status "GET-combat-maps-status" 200 "$sc"
assert_jq "GET-combat-maps-count" "2" "$resp" ".maps | length"
assert_jq "GET-combat-maps-pagination-total" "2" "$resp" ".pagination.total"
assert_jq_not_null "GET-combat-maps-server-login" "$resp" ".server_login"
# Most recent map (uid-bravo) should come first
assert_jq "GET-combat-maps-first-uid" "uid-bravo" "$resp" ".maps[0].map_uid"
assert_jq "GET-combat-maps-second-uid" "uid-alpha" "$resp" ".maps[1].map_uid"
# Verify player_stats present for first map
assert_jq_not_null "GET-combat-maps-first-player-stats" "$resp" '.maps[0].player_stats."player-beta"'
assert_jq "GET-combat-maps-first-player-beta-kills" "12" "$resp" '.maps[0].player_stats."player-beta".kills'
# Verify totals
assert_jq "GET-combat-maps-first-totals-kills" "20" "$resp" ".maps[0].totals.kills"
assert_jq "GET-combat-maps-second-totals-kills" "16" "$resp" ".maps[1].totals.kills"

echo ""
echo "▶ Section 2: GET /stats/combat/maps — pagination"

resp_pg1=$(get_json "servers/${SERVER_LOGIN}/stats/combat/maps?limit=1")
sc=$(get_status "servers/${SERVER_LOGIN}/stats/combat/maps?limit=1")
assert_status "GET-combat-maps-limit1-status" 200 "$sc"
assert_jq "GET-combat-maps-limit1-count" "1" "$resp_pg1" ".maps | length"
assert_jq "GET-combat-maps-limit1-pagination-total" "2" "$resp_pg1" ".pagination.total"

resp_pg2=$(get_json "servers/${SERVER_LOGIN}/stats/combat/maps?limit=1&offset=1")
sc=$(get_status "servers/${SERVER_LOGIN}/stats/combat/maps?limit=1&offset=1")
assert_status "GET-combat-maps-limit1-offset1-status" 200 "$sc"
assert_jq "GET-combat-maps-limit1-offset1-uid" "uid-alpha" "$resp_pg2" ".maps[0].map_uid"

echo ""
echo "▶ Section 3: GET /stats/combat/maps/:mapUid — single map lookup"

resp=$(get_json "servers/${SERVER_LOGIN}/stats/combat/maps/uid-alpha")
sc=$(get_status "servers/${SERVER_LOGIN}/stats/combat/maps/uid-alpha")
assert_status "GET-combat-map-uid-alpha-status" 200 "$sc"
assert_jq "GET-combat-map-uid-alpha-uid" "uid-alpha" "$resp" ".map_uid"
assert_jq "GET-combat-map-uid-alpha-name" "Alpha Arena" "$resp" ".map_name"
assert_jq_not_null "GET-combat-map-uid-alpha-player-stats" "$resp" '.player_stats."player-alpha"'
assert_jq "GET-combat-map-uid-alpha-player-alpha-kills" "10" "$resp" '.player_stats."player-alpha".kills'
assert_jq_not_null "GET-combat-map-uid-alpha-win-context" "$resp" ".win_context"
assert_jq "GET-combat-map-uid-alpha-winner" "0" "$resp" ".win_context.winner_team_id"
assert_jq "GET-combat-map-uid-alpha-duration" "120" "$resp" ".duration_seconds"
assert_jq_not_null "GET-combat-map-uid-alpha-event-id" "$resp" ".event_id"

# Unknown UID → 404
sc=$(get_status "servers/${SERVER_LOGIN}/stats/combat/maps/uid-unknown-xyz")
assert_status "GET-combat-map-unknown-uid-404" 404 "$sc"

echo ""
echo "▶ Section 4: GET /stats/combat/maps/:mapUid/players/:login — single player on map"

resp=$(get_json "servers/${SERVER_LOGIN}/stats/combat/maps/uid-alpha/players/player-alpha")
sc=$(get_status "servers/${SERVER_LOGIN}/stats/combat/maps/uid-alpha/players/player-alpha")
assert_status "GET-combat-map-player-status" 200 "$sc"
assert_jq "GET-combat-map-player-login" "player-alpha" "$resp" ".player_login"
assert_jq "GET-combat-map-player-map-uid" "uid-alpha" "$resp" ".map_uid"
assert_jq "GET-combat-map-player-kills" "10" "$resp" ".counters.kills"
assert_jq "GET-combat-map-player-deaths" "3" "$resp" ".counters.deaths"
assert_jq "GET-combat-map-player-shots" "100" "$resp" ".counters.shots"
assert_jq_not_null "GET-combat-map-player-played-at" "$resp" ".played_at"

# Unknown player on known map → 404
sc=$(get_status "servers/${SERVER_LOGIN}/stats/combat/maps/uid-alpha/players/unknown-player-xyz")
assert_status "GET-combat-map-unknown-player-404" 404 "$sc"

# Valid player on unknown map → 404
sc=$(get_status "servers/${SERVER_LOGIN}/stats/combat/maps/uid-unknown-xyz/players/player-alpha")
assert_status "GET-combat-map-player-unknown-map-404" 404 "$sc"

echo ""
echo "▶ Section 5: GET /stats/combat/series — series breakdown"

resp=$(get_json "servers/${SERVER_LOGIN}/stats/combat/series")
sc=$(get_status "servers/${SERVER_LOGIN}/stats/combat/series")
assert_status "GET-combat-series-status" 200 "$sc"
assert_jq "GET-combat-series-count" "1" "$resp" ".series | length"
assert_jq "GET-combat-series-pagination-total" "1" "$resp" ".pagination.total"
assert_jq "GET-combat-series-total-maps" "2" "$resp" ".series[0].total_maps_played"
assert_jq "GET-combat-series-maps-count" "2" "$resp" ".series[0].maps | length"
assert_jq_not_null "GET-combat-series-match-started-at" "$resp" ".series[0].match_started_at"
assert_jq_not_null "GET-combat-series-match-ended-at" "$resp" ".series[0].match_ended_at"
# series_totals should sum both maps: kills = 16 + 20 = 36
assert_jq "GET-combat-series-totals-kills" "36" "$resp" ".series[0].series_totals.kills"
assert_jq "GET-combat-series-totals-deaths" "14" "$resp" ".series[0].series_totals.deaths"
# Map order within series (sorted by sourceTime asc in query; maps are in series in chronological order)
assert_jq_not_null "GET-combat-series-map-0-uid" "$resp" ".series[0].maps[0].map_uid"
assert_jq_not_null "GET-combat-series-map-1-uid" "$resp" ".series[0].maps[1].map_uid"
assert_jq_not_null "GET-combat-series-pagination" "$resp" ".pagination"

echo ""
echo "▶ Section 6: GET /stats/combat/maps — 404 for unknown server"

sc=$(get_status "servers/nonexistent-server-xyz/stats/combat/maps")
assert_status "GET-combat-maps-unknown-server-404" 404 "$sc"

sc=$(get_status "servers/nonexistent-server-xyz/stats/combat/maps/uid-alpha")
assert_status "GET-combat-map-uid-unknown-server-404" 404 "$sc"

sc=$(get_status "servers/nonexistent-server-xyz/stats/combat/maps/uid-alpha/players/player-alpha")
assert_status "GET-combat-map-player-unknown-server-404" 404 "$sc"

sc=$(get_status "servers/nonexistent-server-xyz/stats/combat/series")
assert_status "GET-combat-series-unknown-server-404" 404 "$sc"

echo ""
echo "▶ Section 7: Cleanup"

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
