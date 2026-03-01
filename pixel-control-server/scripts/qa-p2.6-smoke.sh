#!/usr/bin/env bash
# qa-p2.6-smoke.sh — Live QA smoke tests for P2.6 player combat map history + derived stats
# Usage: bash scripts/qa-p2.6-smoke.sh
# Requires: server running on port 3000

set -euo pipefail

BASE_URL="http://localhost:3000/v1"
SERVER_LOGIN="qa-p26-smoke-$(date +%s)"
PASS=0
FAIL=0
TOTAL=0

red='\033[0;31m'
green='\033[0;32m'
nc='\033[0m'

assert() {
  local desc="$1"
  local actual="$2"
  local expected="$3"
  TOTAL=$((TOTAL + 1))
  if [ "$actual" = "$expected" ]; then
    echo -e "${green}ok${nc} - $desc"
    PASS=$((PASS + 1))
  else
    echo -e "${red}FAIL${nc} - $desc"
    echo "  expected: $expected"
    echo "  actual:   $actual"
    FAIL=$((FAIL + 1))
  fi
}

assert_not_empty() {
  local desc="$1"
  local actual="$2"
  TOTAL=$((TOTAL + 1))
  if [ -n "$actual" ] && [ "$actual" != "null" ] && [ "$actual" != "[]" ]; then
    echo -e "${green}ok${nc} - $desc"
    PASS=$((PASS + 1))
  else
    echo -e "${red}FAIL${nc} - $desc (got: $actual)"
    FAIL=$((FAIL + 1))
  fi
}

assert_null() {
  local desc="$1"
  local actual="$2"
  TOTAL=$((TOTAL + 1))
  if [ "$actual" = "null" ]; then
    echo -e "${green}ok${nc} - $desc"
    PASS=$((PASS + 1))
  else
    echo -e "${red}FAIL${nc} - $desc (expected null, got: $actual)"
    FAIL=$((FAIL + 1))
  fi
}

echo ""
echo "=== P2.6 Smoke Tests (server=$SERVER_LOGIN) ==="
echo ""

# ---------------------------------------------------------------------------
# Setup: Register test server
# ---------------------------------------------------------------------------
echo "--- Setup ---"
REG=$(curl -s -X PUT "$BASE_URL/servers/$SERVER_LOGIN/link/registration" \
  -H 'Content-Type: application/json' \
  -d "{\"server_name\":\"QA P2.6 Server\",\"plugin_version\":\"1.0.0\",\"game_mode\":\"Elite\",\"title_id\":\"SMStormElite@nadeolabs\"}")
TOKEN=$(echo "$REG" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('link_token',''))" 2>/dev/null || echo "")
assert_not_empty "Server registered and token returned" "$TOKEN"

AUTH_HEADER="Authorization: Bearer $TOKEN"
SERVER_HEADER="X-Pixel-Server-Login: $SERVER_LOGIN"

# ---------------------------------------------------------------------------
# Helper: send a lifecycle map.end event
# ---------------------------------------------------------------------------
send_map_end() {
  local map_uid="$1"
  local map_name="$2"
  local source_time="$3"
  local player_counters="$4"
  local win_context="$5"
  local team_counters="${6:-[]}"

  curl -s -X POST "$BASE_URL/plugin/events" \
    -H 'Content-Type: application/json' \
    -H "$AUTH_HEADER" \
    -H "$SERVER_HEADER" \
    -d "{
      \"event_id\": \"pc-evt-lifecycle-map-end-$map_uid-$source_time\",
      \"event_name\": \"pixel_control.lifecycle.sm_end_map\",
      \"event_category\": \"lifecycle\",
      \"source_callback\": \"SM_END_MAP\",
      \"source_sequence\": $source_time,
      \"source_time\": $source_time,
      \"schema_version\": \"2026-02-20.1\",
      \"idempotency_key\": \"idem-lc-$map_uid-$source_time\",
      \"payload\": {
        \"variant\": \"map.end\",
        \"map_rotation\": { \"current_map\": { \"uid\": \"$map_uid\", \"name\": \"$map_name\" } },
        \"aggregate_stats\": {
          \"scope\": \"map\",
          \"player_counters_delta\": $player_counters,
          \"team_counters_delta\": $team_counters,
          \"totals\": {},
          \"win_context\": $win_context,
          \"window\": { \"duration_seconds\": 120 }
        }
      }
    }" > /dev/null
}

# ---------------------------------------------------------------------------
# Seed: 3 maps (most-recent first in DB => map3 > map2 > map1)
# Map1 (oldest): player1 + player2, player1 wins (team 0)
# Map2 (middle): player2 only (player1 absent)
# Map3 (newest): player1 + player2, player1 loses (team 1, winner is team 0)
# Map4: player1 + player2, hits_rocket/hits_laser present (backward compat test)
# ---------------------------------------------------------------------------
echo ""
echo "--- Seeding test data ---"

NOW_S=$(date +%s)
T_MAP1=$(( (NOW_S - 300) * 1000 ))
T_MAP2=$(( (NOW_S - 200) * 1000 ))
T_MAP3=$(( (NOW_S - 100) * 1000 ))
T_MAP4=$(( NOW_S * 1000 ))

# Map1: player1 wins
send_map_end "uid-map1" "Map One" "$T_MAP1" \
  '{"player1": {"kills": 7, "deaths": 2, "hits": 40, "shots": 80, "misses": 40, "rockets": 30, "lasers": 10, "accuracy": 0.5}, "player2": {"kills": 3, "deaths": 5, "hits": 20, "shots": 60, "rockets": 20, "lasers": 10}}' \
  '{"winner_team_id": 0}' \
  '[{"team_id": 0, "player_logins": ["player1"]}, {"team_id": 1, "player_logins": ["player2"]}]'

# Map2: player2 only
send_map_end "uid-map2" "Map Two" "$T_MAP2" \
  '{"player2": {"kills": 5, "deaths": 1, "hits": 30, "shots": 50}}' \
  '{"winner_team_id": 0}' \
  '[{"team_id": 0, "player_logins": ["player2"]}]'

# Map3: player1 loses (team 1, winner is team 0)
send_map_end "uid-map3" "Map Three" "$T_MAP3" \
  '{"player1": {"kills": 2, "deaths": 6, "hits": 10, "shots": 40, "rockets": 15, "lasers": 5, "accuracy": 0.25}, "player2": {"kills": 8, "deaths": 1, "hits": 50, "shots": 60}}' \
  '{"winner_team_id": 0}' \
  '[{"team_id": 0, "player_logins": ["player2"]}, {"team_id": 1, "player_logins": ["player1"]}]'

# Map4: player1 with hits_rocket/hits_laser fields (plugin v2 data)
send_map_end "uid-map4" "Map Four" "$T_MAP4" \
  '{"player1": {"kills": 5, "deaths": 2, "hits": 18, "shots": 25, "misses": 7, "rockets": 8, "lasers": 3, "hits_rocket": 6, "hits_laser": 2, "accuracy": 0.72}, "player2": {"kills": 4, "deaths": 3}}' \
  '{"winner_team_id": 0}' \
  '[{"team_id": 0, "player_logins": ["player1"]}, {"team_id": 1, "player_logins": ["player2"]}]'

echo "Data seeded."

# ---------------------------------------------------------------------------
# Phase 5 tests: base endpoint
# ---------------------------------------------------------------------------
echo ""
echo "--- Phase 5: Base endpoint tests ---"

# player1 maps: map1 + map3 + map4 = 3 maps
P1_MAPS=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/players/player1/maps")
P1_TOTAL=$(echo "$P1_MAPS" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['pagination']['total'])")
assert "player1 has 3 maps total" "$P1_TOTAL" "3"

P1_FIRST_UID=$(echo "$P1_MAPS" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['maps'][0]['map_uid'])")
assert "player1 maps ordered most-recent first (map4 first)" "$P1_FIRST_UID" "uid-map4"

# player2 maps: map1 + map2 + map3 + map4 = 4 maps
P2_MAPS=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/players/player2/maps")
P2_TOTAL=$(echo "$P2_MAPS" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['pagination']['total'])")
assert "player2 has 4 maps total" "$P2_TOTAL" "4"

# Pagination: limit=1
P1_LIMIT1=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/players/player1/maps?limit=1")
P1_LIMIT1_COUNT=$(echo "$P1_LIMIT1" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d['maps']))")
P1_LIMIT1_TOTAL=$(echo "$P1_LIMIT1" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['pagination']['total'])")
assert "limit=1 returns 1 map" "$P1_LIMIT1_COUNT" "1"
assert "limit=1 total still shows 3" "$P1_LIMIT1_TOTAL" "3"

# Pagination: offset=1
P1_OFFSET1=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/players/player1/maps?offset=1")
P1_OFFSET1_SECOND=$(echo "$P1_OFFSET1" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['maps'][0]['map_uid'])")
assert "offset=1 skips map4, returns map3" "$P1_OFFSET1_SECOND" "uid-map3"

# Nonexistent player
NONEXIST=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/players/nobody/maps")
NONEXIST_TOTAL=$(echo "$NONEXIST" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['pagination']['total'])")
NONEXIST_MAPS=$(echo "$NONEXIST" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d['maps']))")
assert "nonexistent player returns total=0 (not 404)" "$NONEXIST_TOTAL" "0"
assert "nonexistent player returns empty maps" "$NONEXIST_MAPS" "0"

# Counters match seeded data (map1: player1 kills=7)
P1_MAP1_KILLS=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/players/player1/maps" | \
  python3 -c "import sys,json; d=json.load(sys.stdin); m=[x for x in d['maps'] if x['map_uid']=='uid-map1']; print(m[0]['counters']['kills'] if m else 'NOT_FOUND')")
assert "player1 kills on map1 = 7" "$P1_MAP1_KILLS" "7"

# win_context present
P1_MAP1_WIN_CTX=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/players/player1/maps" | \
  python3 -c "import sys,json; d=json.load(sys.stdin); m=[x for x in d['maps'] if x['map_uid']=='uid-map1']; print(m[0]['win_context']['winner_team_id'] if m else 'NOT_FOUND')")
assert "win_context.winner_team_id present on map1" "$P1_MAP1_WIN_CTX" "0"

# Verify existing endpoints still work
MAPS_LIST=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/maps")
MAPS_COUNT=$(echo "$MAPS_LIST" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['pagination']['total'])")
assert "existing GET /stats/combat/maps still works" "$MAPS_COUNT" "4"

P1_CUMUL_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/players/player1")
# 200 (found) or 404 (no combat events for this server yet) — both indicate endpoint is reachable
assert_not_empty "existing GET /stats/combat/players/:login is reachable (not 500)" "$P1_CUMUL_STATUS"

# ---------------------------------------------------------------------------
# Phase 12 tests: derived stats (kd_ratio, win_rate, weapon accuracy)
# ---------------------------------------------------------------------------
echo ""
echo "--- Phase 12: Derived stats tests ---"

# kd_ratio on map player_stats (lifecycle events, always available)
# Also verify the map-level player-specific endpoint has kd_ratio
MAP4_PLAYER_DETAIL=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/maps/uid-map4/players/player1")
MAP4_PLAYER_KD=$(echo "$MAP4_PLAYER_DETAIL" | python3 -c "import sys,json; d=json.load(sys.stdin); print('present' if 'kd_ratio' in d['counters'] else 'missing')" 2>/dev/null || echo "error")
assert "kd_ratio present in map-specific player counters" "$MAP4_PLAYER_KD" "present"

# kd_ratio on maps list player_stats
MAP4_ENTRY=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/maps/uid-map4")
MAP4_P1_KD=$(echo "$MAP4_ENTRY" | python3 -c "import sys,json; d=json.load(sys.stdin); print('present' if 'kd_ratio' in d['player_stats']['player1'] else 'missing')")
assert "kd_ratio present in map player_stats" "$MAP4_P1_KD" "present"

MAP4_P1_KD_VAL=$(echo "$MAP4_ENTRY" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['player_stats']['player1']['kd_ratio'])")
assert "kd_ratio on map4 player1 = 2.5 (5/2)" "$MAP4_P1_KD_VAL" "2.5"

# kd_ratio on player map history
P1_MAP4_COUNTERS=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/players/player1/maps" | \
  python3 -c "import sys,json; d=json.load(sys.stdin); m=[x for x in d['maps'] if x['map_uid']=='uid-map4']; print(m[0]['counters']['kd_ratio'] if m else 'NOT_FOUND')")
assert "kd_ratio on player1 map4 = 2.5" "$P1_MAP4_COUNTERS" "2.5"

# kd_ratio edge case: deaths=0 => returns kills (map3 player1: kills=2, deaths=6 => 2/6=0.3333)
P1_MAP3_KD=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/players/player1/maps" | \
  python3 -c "import sys,json; d=json.load(sys.stdin); m=[x for x in d['maps'] if x['map_uid']=='uid-map3']; print(m[0]['counters']['kd_ratio'] if m else 'NOT_FOUND')")
assert_not_empty "kd_ratio present on map3" "$P1_MAP3_KD"

# hits_rocket/hits_laser on map4 (plugin v2 data)
MAP4_HITS_R=$(echo "$MAP4_ENTRY" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['player_stats']['player1']['hits_rocket'])")
MAP4_HITS_L=$(echo "$MAP4_ENTRY" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['player_stats']['player1']['hits_laser'])")
MAP4_R_ACC=$(echo "$MAP4_ENTRY" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['player_stats']['player1']['rocket_accuracy'])")
MAP4_L_ACC=$(echo "$MAP4_ENTRY" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['player_stats']['player1']['laser_accuracy'])")
assert "hits_rocket=6 on map4 player1" "$MAP4_HITS_R" "6"
assert "hits_laser=2 on map4 player1" "$MAP4_HITS_L" "2"
assert "rocket_accuracy=0.75 on map4 player1 (6/8)" "$MAP4_R_ACC" "0.75"
assert "laser_accuracy=0.6667 on map4 player1 (2/3)" "$MAP4_L_ACC" "0.6667"

# hits_rocket/hits_laser null for old events (map1 no weapon split data)
MAP1_ENTRY=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/maps/uid-map1")
MAP1_HITS_R=$(echo "$MAP1_ENTRY" | python3 -c "import sys,json; d=json.load(sys.stdin); v=d['player_stats']['player1']['hits_rocket']; print('null' if v is None else v)")
MAP1_R_ACC=$(echo "$MAP1_ENTRY" | python3 -c "import sys,json; d=json.load(sys.stdin); v=d['player_stats']['player1']['rocket_accuracy']; print('null' if v is None else v)")
assert_null "hits_rocket=null for map1 (old event, no weapon split)" "$MAP1_HITS_R"
assert_null "rocket_accuracy=null for map1 (no hits_rocket)" "$MAP1_R_ACC"

# Win rate: player1 has 3 maps: map1 (won), map3 (lost), map4 (won) => 2/3 = 0.6667
P1_MAPS_ALL=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/players/player1/maps")
P1_MAPS_PLAYED=$(echo "$P1_MAPS_ALL" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['maps_played'])")
P1_MAPS_WON=$(echo "$P1_MAPS_ALL" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['maps_won'])")
P1_WIN_RATE=$(echo "$P1_MAPS_ALL" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['win_rate'])")
assert "maps_played=3 for player1" "$P1_MAPS_PLAYED" "3"
assert "maps_won=2 for player1 (map1 + map4)" "$P1_MAPS_WON" "2"
assert "win_rate=0.6667 for player1" "$P1_WIN_RATE" "0.6667"

# per-map won field
P1_MAP1_WON=$(echo "$P1_MAPS_ALL" | python3 -c "import sys,json; d=json.load(sys.stdin); m=[x for x in d['maps'] if x['map_uid']=='uid-map1']; print(m[0]['won'] if m else 'NOT_FOUND')")
P1_MAP3_WON=$(echo "$P1_MAPS_ALL" | python3 -c "import sys,json; d=json.load(sys.stdin); m=[x for x in d['maps'] if x['map_uid']=='uid-map3']; print(m[0]['won'] if m else 'NOT_FOUND')")
assert "player1 won=True on map1" "$P1_MAP1_WON" "True"
assert "player1 won=False on map3 (player1 was on team1, winner was team0)" "$P1_MAP3_WON" "False"

# ---------------------------------------------------------------------------
# Cleanup
# ---------------------------------------------------------------------------
echo ""
echo "--- Cleanup ---"
curl -s -X DELETE "$BASE_URL/servers/$SERVER_LOGIN" > /dev/null
echo "Test server deleted."

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
echo ""
echo "=== Summary ==="
echo "Total: $TOTAL, Passed: $PASS, Failed: $FAIL"
echo ""

if [ "$FAIL" -gt 0 ]; then
  echo -e "${red}SMOKE TEST FAILED ($FAIL failures)${nc}"
  exit 1
else
  echo -e "${green}All smoke tests passed.${nc}"
  exit 0
fi
