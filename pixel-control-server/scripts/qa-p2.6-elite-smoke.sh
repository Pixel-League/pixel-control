#!/usr/bin/env bash
# qa-p2.6-elite-smoke.sh — Live QA smoke tests for P2.6 Elite attack/defense win rate fields
# Usage: bash scripts/qa-p2.6-elite-smoke.sh
# Requires: server running on port 3000

set -euo pipefail

BASE_URL="http://localhost:3000/v1"
SERVER_LOGIN="qa-p26-elite-$(date +%s)"
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
echo "=== P2.6 Elite Win Rate Smoke Tests (server=$SERVER_LOGIN) ==="
echo ""

# ---------------------------------------------------------------------------
# Setup: Register test server
# ---------------------------------------------------------------------------
echo "--- Setup ---"
REG=$(curl -s -X PUT "$BASE_URL/servers/$SERVER_LOGIN/link/registration" \
  -H 'Content-Type: application/json' \
  -d "{\"server_name\":\"QA P2.6 Elite Server\",\"plugin_version\":\"1.0.0\",\"game_mode\":\"Elite\",\"title_id\":\"SMStormElite@nadeolabs\"}")
TOKEN=$(echo "$REG" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('link_token',''))" 2>/dev/null || echo "")
assert_not_empty "Server registered and token returned" "$TOKEN"

AUTH_HEADER="Authorization: Bearer $TOKEN"
SERVER_HEADER="X-Pixel-Server-Login: $SERVER_LOGIN"

# ---------------------------------------------------------------------------
# Helper: send a lifecycle map.end event with Elite fields
# ---------------------------------------------------------------------------
send_elite_map_end() {
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
      \"event_id\": \"pc-evt-lifecycle-elite-$map_uid-$source_time\",
      \"event_name\": \"pixel_control.lifecycle.sm_end_map\",
      \"event_category\": \"lifecycle\",
      \"source_callback\": \"SM_END_MAP\",
      \"source_sequence\": $source_time,
      \"source_time\": $source_time,
      \"schema_version\": \"2026-02-20.1\",
      \"idempotency_key\": \"idem-elite-$map_uid-$source_time\",
      \"payload\": {
        \"variant\": \"map.end\",
        \"map_rotation\": { \"current_map\": { \"uid\": \"$map_uid\", \"name\": \"$map_name\" } },
        \"aggregate_stats\": {
          \"scope\": \"map\",
          \"player_counters_delta\": $player_counters,
          \"team_counters_delta\": $team_counters,
          \"totals\": {},
          \"win_context\": $win_context,
          \"window\": { \"duration_seconds\": 180 }
        }
      }
    }" > /dev/null
}

# ---------------------------------------------------------------------------
# Seed: 3 Elite maps + 1 old map (no Elite fields)
# Map1 (oldest): player_a + player_b with Elite fields, player_a wins
# Map2 (middle): player_a + player_b with Elite fields, player_a wins
# Map3 (newer): player_a + player_b with Elite fields, player_a loses
# Map4 (newest): old event without Elite fields (backward compat test)
#
# player_a Elite totals across Map1+Map2+Map3:
#   attack_rounds_played=15, attack_rounds_won=9 => rate=0.6
#   defense_rounds_played=15, defense_rounds_won=12 => rate=0.8
# player_b Elite totals:
#   attack_rounds_played=15, attack_rounds_won=6 => rate=0.4
#   defense_rounds_played=15, defense_rounds_won=9 => rate=0.6
# ---------------------------------------------------------------------------
echo ""
echo "--- Seeding test data ---"

NOW_S=$(date +%s)
T_MAP1=$(( (NOW_S - 400) * 1000 ))
T_MAP2=$(( (NOW_S - 300) * 1000 ))
T_MAP3=$(( (NOW_S - 200) * 1000 ))
T_MAP4=$(( (NOW_S - 100) * 1000 ))

# Map1: player_a wins (attack_rounds_played=5, attack_rounds_won=3, defense_rounds_played=5, defense_rounds_won=4)
send_elite_map_end "uid-elite1" "Elite Map 1" "$T_MAP1" \
  '{"player_a": {"kills": 7, "deaths": 2, "hits": 40, "shots": 80, "rockets": 30, "lasers": 10, "hits_rocket": 20, "hits_laser": 5, "attack_rounds_played": 5, "attack_rounds_won": 3, "defense_rounds_played": 5, "defense_rounds_won": 4}, "player_b": {"kills": 3, "deaths": 5, "hits": 20, "shots": 60, "rockets": 20, "lasers": 10, "hits_rocket": 10, "hits_laser": 3, "attack_rounds_played": 5, "attack_rounds_won": 2, "defense_rounds_played": 5, "defense_rounds_won": 3}}' \
  '{"winner_team_id": 0}' \
  '[{"team_id": 0, "player_logins": ["player_a"]}, {"team_id": 1, "player_logins": ["player_b"]}]'

# Map2: player_a wins (attack_rounds_played=5, attack_rounds_won=3, defense_rounds_played=5, defense_rounds_won=4)
send_elite_map_end "uid-elite2" "Elite Map 2" "$T_MAP2" \
  '{"player_a": {"kills": 6, "deaths": 3, "hits": 35, "shots": 70, "rockets": 25, "lasers": 8, "hits_rocket": 18, "hits_laser": 4, "attack_rounds_played": 5, "attack_rounds_won": 3, "defense_rounds_played": 5, "defense_rounds_won": 4}, "player_b": {"kills": 4, "deaths": 4, "hits": 25, "shots": 55, "rockets": 18, "lasers": 9, "hits_rocket": 12, "hits_laser": 4, "attack_rounds_played": 5, "attack_rounds_won": 2, "defense_rounds_played": 5, "defense_rounds_won": 3}}' \
  '{"winner_team_id": 0}' \
  '[{"team_id": 0, "player_logins": ["player_a"]}, {"team_id": 1, "player_logins": ["player_b"]}]'

# Map3: player_a loses (attack_rounds_played=5, attack_rounds_won=3, defense_rounds_played=5, defense_rounds_won=4)
send_elite_map_end "uid-elite3" "Elite Map 3" "$T_MAP3" \
  '{"player_a": {"kills": 5, "deaths": 4, "hits": 30, "shots": 65, "rockets": 22, "lasers": 7, "hits_rocket": 15, "hits_laser": 3, "attack_rounds_played": 5, "attack_rounds_won": 3, "defense_rounds_played": 5, "defense_rounds_won": 4}, "player_b": {"kills": 6, "deaths": 3, "hits": 32, "shots": 60, "rockets": 20, "lasers": 8, "hits_rocket": 13, "hits_laser": 5, "attack_rounds_played": 5, "attack_rounds_won": 2, "defense_rounds_played": 5, "defense_rounds_won": 3}}' \
  '{"winner_team_id": 1}' \
  '[{"team_id": 0, "player_logins": ["player_a"]}, {"team_id": 1, "player_logins": ["player_b"]}]'

# Map4: no Elite fields (old event, backward compat test)
send_elite_map_end "uid-elite4" "Old Map (no Elite)" "$T_MAP4" \
  '{"player_a": {"kills": 4, "deaths": 2, "hits": 20, "shots": 40}}' \
  '{"winner_team_id": 0}' \
  '[{"team_id": 0, "player_logins": ["player_a"]}]'

echo "Data seeded."
sleep 1

# ---------------------------------------------------------------------------
# Phase 19 tests: Elite fields on all player counter endpoints
# ---------------------------------------------------------------------------
echo ""
echo "--- Phase 19.3: Elite fields on player counter endpoints ---"

# Test 1: GET .../stats/combat/players/:login/maps - Elite fields per map
P_A_MAPS=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/players/player_a/maps")
P_A_ELITE1=$(echo "$P_A_MAPS" | python3 -c "import sys,json; d=json.load(sys.stdin); m=[x for x in d['maps'] if x['map_uid']=='uid-elite1']; print(m[0]['counters']['attack_rounds_played'] if m else 'NOT_FOUND')")
assert "player_a map1 attack_rounds_played=5" "$P_A_ELITE1" "5"

P_A_ELITE1_WR=$(echo "$P_A_MAPS" | python3 -c "import sys,json; d=json.load(sys.stdin); m=[x for x in d['maps'] if x['map_uid']=='uid-elite1']; print(m[0]['counters']['attack_win_rate'] if m else 'NOT_FOUND')")
assert "player_a map1 attack_win_rate=0.6 (3/5)" "$P_A_ELITE1_WR" "0.6"

P_A_ELITE1_DEF=$(echo "$P_A_MAPS" | python3 -c "import sys,json; d=json.load(sys.stdin); m=[x for x in d['maps'] if x['map_uid']=='uid-elite1']; print(m[0]['counters']['defense_win_rate'] if m else 'NOT_FOUND')")
assert "player_a map1 defense_win_rate=0.8 (4/5)" "$P_A_ELITE1_DEF" "0.8"

# Test 2: old event map4 should have null Elite fields
P_A_MAP4_ATK=$(echo "$P_A_MAPS" | python3 -c "import sys,json; d=json.load(sys.stdin); m=[x for x in d['maps'] if x['map_uid']=='uid-elite4']; v=m[0]['counters']['attack_rounds_played'] if m else 'NOT_FOUND'; print('null' if v is None else v)")
assert_null "old event (map4) attack_rounds_played=null" "$P_A_MAP4_ATK"

P_A_MAP4_ATK_WR=$(echo "$P_A_MAPS" | python3 -c "import sys,json; d=json.load(sys.stdin); m=[x for x in d['maps'] if x['map_uid']=='uid-elite4']; v=m[0]['counters']['attack_win_rate'] if m else 'NOT_FOUND'; print('null' if v is None else v)")
assert_null "old event (map4) attack_win_rate=null" "$P_A_MAP4_ATK_WR"

P_A_MAP4_DEF_WR=$(echo "$P_A_MAPS" | python3 -c "import sys,json; d=json.load(sys.stdin); m=[x for x in d['maps'] if x['map_uid']=='uid-elite4']; v=m[0]['counters']['defense_win_rate'] if m else 'NOT_FOUND'; print('null' if v is None else v)")
assert_null "old event (map4) defense_win_rate=null" "$P_A_MAP4_DEF_WR"

# Test 3: GET .../stats/combat/maps/:mapUid - player_stats includes Elite fields
MAP1_ENTRY=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/maps/uid-elite1")
MAP1_PA_ATK=$(echo "$MAP1_ENTRY" | python3 -c "import sys,json; d=json.load(sys.stdin); print('present' if 'attack_rounds_played' in d['player_stats']['player_a'] else 'missing')")
assert "Elite fields present in map player_stats" "$MAP1_PA_ATK" "present"

MAP1_PA_ATK_WR=$(echo "$MAP1_ENTRY" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['player_stats']['player_a']['attack_win_rate'])")
assert "map1 player_a attack_win_rate=0.6" "$MAP1_PA_ATK_WR" "0.6"

MAP1_PA_DEF_WR=$(echo "$MAP1_ENTRY" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['player_stats']['player_a']['defense_win_rate'])")
assert "map1 player_a defense_win_rate=0.8" "$MAP1_PA_DEF_WR" "0.8"

# Test 4: GET .../stats/combat/maps/:mapUid/players/:login - counters include Elite fields
MAP1_PA_DETAIL=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/maps/uid-elite1/players/player_a")
MAP1_PA_D_ATK=$(echo "$MAP1_PA_DETAIL" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['counters']['attack_rounds_played'])")
assert "map-player detail: attack_rounds_played=5" "$MAP1_PA_D_ATK" "5"

MAP1_PA_D_DEF_WR=$(echo "$MAP1_PA_DETAIL" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['counters']['defense_win_rate'])")
assert "map-player detail: defense_win_rate=0.8" "$MAP1_PA_D_DEF_WR" "0.8"

# Test 5: old event map4 - player_stats Elite fields are null
MAP4_ENTRY=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/maps/uid-elite4")
MAP4_PA_ATK=$(echo "$MAP4_ENTRY" | python3 -c "import sys,json; d=json.load(sys.stdin); v=d['player_stats']['player_a']['attack_rounds_played']; print('null' if v is None else v)")
assert_null "old event map4: attack_rounds_played=null in player_stats" "$MAP4_PA_ATK"

MAP4_PA_WR=$(echo "$MAP4_ENTRY" | python3 -c "import sys,json; d=json.load(sys.stdin); v=d['player_stats']['player_a']['attack_win_rate']; print('null' if v is None else v)")
assert_null "old event map4: attack_win_rate=null in player_stats" "$MAP4_PA_WR"

# Test 6: verify defense_win_rate=0 not null when defense_rounds_played=0
send_elite_map_end "uid-elite5" "Zero Elite Map" "$(( (NOW_S - 50) * 1000 ))" \
  '{"player_z": {"kills": 1, "attack_rounds_played": 0, "attack_rounds_won": 0, "defense_rounds_played": 0, "defense_rounds_won": 0}}' \
  '{}' \
  '[]'
sleep 1

MAP5_ENTRY=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/maps/uid-elite5")
MAP5_ATK_WR=$(echo "$MAP5_ENTRY" | python3 -c "import sys,json; d=json.load(sys.stdin); v=d['player_stats']['player_z']['attack_win_rate']; print('null' if v is None else v)")
assert "zero Elite: attack_win_rate=0 (not null)" "$MAP5_ATK_WR" "0"

MAP5_DEF_WR=$(echo "$MAP5_ENTRY" | python3 -c "import sys,json; d=json.load(sys.stdin); v=d['player_stats']['player_z']['defense_win_rate']; print('null' if v is None else v)")
assert "zero Elite: defense_win_rate=0 (not null)" "$MAP5_DEF_WR" "0"

# ---------------------------------------------------------------------------
# Phase 19.4: Regression — verify existing smoke scripts still pass
# ---------------------------------------------------------------------------
echo ""
echo "--- Phase 19.4: Regression smoke tests ---"

# Verify existing map list endpoint
MAPS_LIST=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/maps")
MAPS_COUNT=$(echo "$MAPS_LIST" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['pagination']['total'])")
assert_not_empty "existing GET /stats/combat/maps still returns data" "$MAPS_COUNT"

# Verify player map history endpoint still works (maps_played, maps_won, win_rate)
P_A_ALL=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/players/player_a/maps")
P_A_PLAYED=$(echo "$P_A_ALL" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['maps_played'])")
# 4 maps total for player_a (elite1+elite2+elite3+elite4+elite5 = 5 actually, but elite5 has player_z)
# Actually: elite1+elite2+elite3+elite4 = 4 maps for player_a
P_A_WIN_RATE=$(echo "$P_A_ALL" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['win_rate'])")
assert_not_empty "player_a maps_played > 0" "$P_A_PLAYED"
assert_not_empty "player_a win_rate computed" "$P_A_WIN_RATE"

# Verify won field is correct: player_a on elite1 (team0, winner0) => won=True
P_A_ELITE1_WON=$(echo "$P_A_ALL" | python3 -c "import sys,json; d=json.load(sys.stdin); m=[x for x in d['maps'] if x['map_uid']=='uid-elite1']; print(m[0]['won'] if m else 'NOT_FOUND')")
assert "player_a won=True on elite1 (team0 wins)" "$P_A_ELITE1_WON" "True"

# player_a on elite3 (team0, but winner is team1) => won=False
P_A_ELITE3_WON=$(echo "$P_A_ALL" | python3 -c "import sys,json; d=json.load(sys.stdin); m=[x for x in d['maps'] if x['map_uid']=='uid-elite3']; print(m[0]['won'] if m else 'NOT_FOUND')")
assert "player_a won=False on elite3 (team1 wins, player_a on team0)" "$P_A_ELITE3_WON" "False"

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
  echo -e "${green}All Elite smoke tests passed.${nc}"
  exit 0
fi
