#!/usr/bin/env bash
# qa-elite-enrichment-smoke.sh — Smoke tests for Elite enrichment endpoints (P2.12–P2.15)
# Tests: elite_turn_summary events ingestion, turn list, single turn, clutch stats, player turn history
# Usage: bash scripts/qa-elite-enrichment-smoke.sh
# Requires: server running on port 3000

set -euo pipefail

BASE_URL="http://localhost:3000/v1"
SERVER_LOGIN="qa-elite-enrich-$(date +%s)"
PASS=0
FAIL=0
TOTAL=0

red='\033[0;31m'
green='\033[0;32m'
yellow='\033[0;33m'
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

assert_http() {
  local desc="$1"
  local actual="$2"
  local expected="$3"
  TOTAL=$((TOTAL + 1))
  if [ "$actual" = "$expected" ]; then
    echo -e "${green}ok${nc} - $desc (HTTP $actual)"
    PASS=$((PASS + 1))
  else
    echo -e "${red}FAIL${nc} - $desc (expected HTTP $expected, got HTTP $actual)"
    FAIL=$((FAIL + 1))
  fi
}

echo ""
echo "=== Elite Enrichment Smoke Tests (P2.12–P2.15) ==="
echo "    server=$SERVER_LOGIN"
echo ""

# ---------------------------------------------------------------------------
# Setup: Register test server
# ---------------------------------------------------------------------------
echo "--- Setup: Register server ---"
REG=$(curl -s -X PUT "$BASE_URL/servers/$SERVER_LOGIN/link/registration" \
  -H 'Content-Type: application/json' \
  -d '{"server_name":"QA Elite Enrichment Server","plugin_version":"1.0.0","game_mode":"Elite","title_id":"SMStormElite@nadeolabs"}')
TOKEN=$(echo "$REG" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('link_token',''))" 2>/dev/null || echo "")
assert_not_empty "Server registered and token returned" "$TOKEN"

AUTH_HEADER="Authorization: Bearer $TOKEN"
SERVER_HEADER="X-Pixel-Server-Login: $SERVER_LOGIN"

# ---------------------------------------------------------------------------
# Helper: POST an elite_turn_summary event
# ---------------------------------------------------------------------------
send_elite_turn_summary() {
  local turn_num="$1"
  local outcome="$2"
  local defense_success="$3"
  local is_clutch="$4"
  local clutch_login="$5"
  local alive_at_end="$6"
  local total_defs="$7"
  local source_time="$8"

  local clutch_login_json
  if [ "$clutch_login" = "null" ]; then
    clutch_login_json="null"
  else
    clutch_login_json="\"$clutch_login\""
  fi

  curl -s -X POST "$BASE_URL/plugin/events" \
    -H 'Content-Type: application/json' \
    -H "$AUTH_HEADER" \
    -H "$SERVER_HEADER" \
    -d "{
      \"event_id\": \"pc-evt-combat-elite-turn-$turn_num-$source_time\",
      \"event_name\": \"pixel_control.combat.elite_turn_summary\",
      \"event_category\": \"combat\",
      \"source_callback\": \"SM_ELITE_END_TURN\",
      \"source_sequence\": $source_time,
      \"source_time\": $source_time,
      \"schema_version\": \"2026-02-20.1\",
      \"idempotency_key\": \"idem-elite-turn-$turn_num-$source_time\",
      \"payload\": {
        \"event_kind\": \"elite_turn_summary\",
        \"turn_number\": $turn_num,
        \"attacker_login\": \"attacker1\",
        \"defender_logins\": [\"def1\", \"def2\", \"def3\"],
        \"attacker_team_id\": 0,
        \"outcome\": \"$outcome\",
        \"duration_seconds\": 45,
        \"defense_success\": $defense_success,
        \"per_player_stats\": {
          \"attacker1\": {\"kills\": 2, \"deaths\": 0, \"hits\": 4, \"shots\": 6, \"misses\": 2, \"rocket_hits\": 2},
          \"def1\": {\"kills\": 0, \"deaths\": 1, \"hits\": 0, \"shots\": 1, \"misses\": 1, \"rocket_hits\": 0},
          \"def2\": {\"kills\": 0, \"deaths\": 1, \"hits\": 1, \"shots\": 2, \"misses\": 1, \"rocket_hits\": 0},
          \"def3\": {\"kills\": 0, \"deaths\": 0, \"hits\": 0, \"shots\": 1, \"misses\": 1, \"rocket_hits\": 0}
        },
        \"map_uid\": \"uid-alpha\",
        \"map_name\": \"Alpha Arena\",
        \"clutch\": {
          \"is_clutch\": $is_clutch,
          \"clutch_player_login\": $clutch_login_json,
          \"alive_defenders_at_end\": $alive_at_end,
          \"total_defenders\": $total_defs
        }
      }
    }" > /dev/null
}

# ---------------------------------------------------------------------------
# Helper: POST a regular combat event WITH elite_context (backward compat test)
# ---------------------------------------------------------------------------
send_combat_with_elite_context() {
  local turn_num="$1"
  local source_time="$2"
  curl -s -X POST "$BASE_URL/plugin/events" \
    -H 'Content-Type: application/json' \
    -H "$AUTH_HEADER" \
    -H "$SERVER_HEADER" \
    -d "{
      \"event_id\": \"pc-evt-combat-onshoot-$turn_num-$source_time\",
      \"event_name\": \"pixel_control.combat.onshoot\",
      \"event_category\": \"combat\",
      \"source_callback\": \"SM_ONSHOOT\",
      \"source_sequence\": $source_time,
      \"source_time\": $source_time,
      \"schema_version\": \"2026-02-20.1\",
      \"idempotency_key\": \"idem-combat-ctx-$turn_num-$source_time\",
      \"payload\": {
        \"event_kind\": \"onshoot\",
        \"elite_context\": {
          \"turn_number\": $turn_num,
          \"attacker_login\": \"attacker1\",
          \"defender_logins\": [\"def1\", \"def2\", \"def3\"],
          \"attacker_team_id\": 0,
          \"phase\": \"attack\"
        },
        \"player_counters\": {}
      }
    }" > /dev/null
}

# ---------------------------------------------------------------------------
# Seed: 4 turns with different outcomes
# Turn 1: capture (attack wins, defense_success=false, no clutch)
# Turn 2: time_limit (defense wins, no clutch - all 3 alive)
# Turn 3: time_limit (defense wins, CLUTCH by def3 - 2 died)
# Turn 4: attacker_eliminated (defense wins, CLUTCH by def3 - 2 died)
# ---------------------------------------------------------------------------
echo ""
echo "--- Seeding: POST 4 elite_turn_summary events ---"

NOW_S=$(date +%s)
T1=$(( (NOW_S - 400) * 1000 ))
T2=$(( (NOW_S - 300) * 1000 ))
T3=$(( (NOW_S - 200) * 1000 ))
T4=$(( (NOW_S - 100) * 1000 ))

# Turn 1: capture (defense fails, no clutch)
send_elite_turn_summary 1 "capture" "false" "false" "null" 1 3 "$T1"
# Turn 2: time_limit (defense wins, no clutch - alive_at_end=3, total=3)
send_elite_turn_summary 2 "time_limit" "true" "false" "null" 3 3 "$T2"
# Turn 3: time_limit (defense wins, CLUTCH by def3 - alive_at_end=1, total=3)
send_elite_turn_summary 3 "time_limit" "true" "true" "def3" 1 3 "$T3"
# Turn 4: attacker_eliminated (defense wins, CLUTCH by def3 - alive_at_end=1, total=3)
send_elite_turn_summary 4 "attacker_eliminated" "true" "true" "def3" 1 3 "$T4"
echo "  4 turn summary events posted"

# Post a regular combat event with elite_context
send_combat_with_elite_context 3 "$(( (NOW_S - 250) * 1000 ))"
echo "  1 regular combat event with elite_context posted"

# ---------------------------------------------------------------------------
# Phase 1: GET /stats/combat/turns — List all turns
# ---------------------------------------------------------------------------
echo ""
echo "--- Phase 1: GET /stats/combat/turns ---"

TURNS=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/turns")
TURNS_COUNT=$(echo "$TURNS" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('turns',[])))" 2>/dev/null || echo "0")
assert "turns list returns 4 turn summaries" "$TURNS_COUNT" "4"

FIRST_TURN_NUM=$(echo "$TURNS" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['turns'][0]['turn_number'])" 2>/dev/null || echo "0")
assert "turns list ordered most-recent first (turn_number=4 first)" "$FIRST_TURN_NUM" "4"

PAGINATION_TOTAL=$(echo "$TURNS" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['pagination']['total'])" 2>/dev/null || echo "0")
assert "turns pagination.total=4" "$PAGINATION_TOTAL" "4"

SERVER_LOGIN_IN_RESP=$(echo "$TURNS" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('server_login',''))" 2>/dev/null || echo "")
assert "turns response has correct server_login" "$SERVER_LOGIN_IN_RESP" "$SERVER_LOGIN"

# ---------------------------------------------------------------------------
# Phase 2: GET /stats/combat/turns — pagination (limit=2)
# ---------------------------------------------------------------------------
echo ""
echo "--- Phase 2: GET /stats/combat/turns?limit=2 ---"

TURNS_P1=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/turns?limit=2&offset=0")
TURNS_P1_COUNT=$(echo "$TURNS_P1" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('turns',[])))" 2>/dev/null || echo "0")
assert "limit=2 returns 2 turns" "$TURNS_P1_COUNT" "2"

TURNS_P2=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/turns?limit=2&offset=2")
TURNS_P2_COUNT=$(echo "$TURNS_P2" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('turns',[])))" 2>/dev/null || echo "0")
assert "limit=2 offset=2 returns 2 more turns" "$TURNS_P2_COUNT" "2"

LAST_TURN_NUM=$(echo "$TURNS_P2" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['turns'][1]['turn_number'])" 2>/dev/null || echo "0")
assert "last page second turn is turn_number=1" "$LAST_TURN_NUM" "1"

# ---------------------------------------------------------------------------
# Phase 3: GET /stats/combat/turns/:turnNumber — use single-turn endpoint for field validation
# ---------------------------------------------------------------------------
echo ""
echo "--- Phase 3: Validate turn 4 payload fields (via GET /turns/4) ---"

TURN4=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/turns/4")
TURN4_OUTCOME=$(echo "$TURN4" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('outcome',''))" 2>/dev/null || echo "")
assert "turn 4 outcome=attacker_eliminated" "$TURN4_OUTCOME" "attacker_eliminated"

TURN4_DEFENSE=$(echo "$TURN4" | python3 -c "import sys,json; d=json.load(sys.stdin); print(str(d.get('defense_success','')).lower())" 2>/dev/null || echo "")
assert "turn 4 defense_success=true" "$TURN4_DEFENSE" "true"

TURN4_CLUTCH=$(echo "$TURN4" | python3 -c "import sys,json; d=json.load(sys.stdin); print(str(d['clutch']['is_clutch']).lower())" 2>/dev/null || echo "")
assert "turn 4 clutch.is_clutch=true" "$TURN4_CLUTCH" "true"

TURN4_CLUTCH_LOGIN=$(echo "$TURN4" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['clutch']['clutch_player_login'])" 2>/dev/null || echo "")
assert "turn 4 clutch.clutch_player_login=def3" "$TURN4_CLUTCH_LOGIN" "def3"

TURN4_MAP=$(echo "$TURN4" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('map_uid',''))" 2>/dev/null || echo "")
assert "turn 4 map_uid=uid-alpha" "$TURN4_MAP" "uid-alpha"

TURN4_PER_PLAYER=$(echo "$TURN4" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('per_player_stats',{})))" 2>/dev/null || echo "0")
assert "turn 4 per_player_stats has 4 players" "$TURN4_PER_PLAYER" "4"

# ---------------------------------------------------------------------------
# Phase 4: GET /stats/combat/turns/:turnNumber — Single turn
# ---------------------------------------------------------------------------
echo ""
echo "--- Phase 4: GET /stats/combat/turns/:turnNumber ---"

HTTP_TURN3=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/turns/3")
assert_http "GET turn 3 returns HTTP 200" "$HTTP_TURN3" "200"

TURN3_RESP=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/turns/3")
TURN3_NUM=$(echo "$TURN3_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('turn_number',''))" 2>/dev/null || echo "")
assert "single turn response: turn_number=3" "$TURN3_NUM" "3"

TURN3_OUTCOME=$(echo "$TURN3_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('outcome',''))" 2>/dev/null || echo "")
assert "single turn response: outcome=time_limit" "$TURN3_OUTCOME" "time_limit"

TURN3_EVENT_ID=$(echo "$TURN3_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('event_id',''))" 2>/dev/null || echo "")
assert_not_empty "single turn response has event_id" "$TURN3_EVENT_ID"

TURN3_RECORDED_AT=$(echo "$TURN3_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('recorded_at',''))" 2>/dev/null || echo "")
assert_not_empty "single turn response has recorded_at" "$TURN3_RECORDED_AT"

# 404 for non-existent turn
HTTP_TURN999=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/turns/999")
assert_http "GET turn 999 (non-existent) returns HTTP 404" "$HTTP_TURN999" "404"

# ---------------------------------------------------------------------------
# Phase 5: GET /stats/combat/players/:login/clutches — def3 has 2 clutches
# ---------------------------------------------------------------------------
echo ""
echo "--- Phase 5: GET /stats/combat/players/def3/clutches ---"

CLUTCH_RESP=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/players/def3/clutches")
CLUTCH_COUNT=$(echo "$CLUTCH_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('clutch_count',''))" 2>/dev/null || echo "")
assert "def3 clutch_count=2" "$CLUTCH_COUNT" "2"

TOTAL_DEF_ROUNDS=$(echo "$CLUTCH_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('total_defense_rounds',''))" 2>/dev/null || echo "")
assert "def3 total_defense_rounds=4 (all 4 turns)" "$TOTAL_DEF_ROUNDS" "4"

CLUTCH_RATE=$(echo "$CLUTCH_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('clutch_rate',''))" 2>/dev/null || echo "")
assert "def3 clutch_rate=0.5 (2/4)" "$CLUTCH_RATE" "0.5"

CLUTCH_TURNS_COUNT=$(echo "$CLUTCH_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('clutch_turns',[])))" 2>/dev/null || echo "0")
assert "def3 clutch_turns list has 2 entries" "$CLUTCH_TURNS_COUNT" "2"

CLUTCH_TURNS_LOGIN=$(echo "$CLUTCH_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('player_login',''))" 2>/dev/null || echo "")
assert "clutch response player_login=def3" "$CLUTCH_TURNS_LOGIN" "def3"

# ---------------------------------------------------------------------------
# Phase 6: GET /stats/combat/players/:login/clutches — player with no clutches
# ---------------------------------------------------------------------------
echo ""
echo "--- Phase 6: Clutch stats for def1 (no clutches) ---"

DEF1_CLUTCH=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/players/def1/clutches")
DEF1_CLUTCH_COUNT=$(echo "$DEF1_CLUTCH" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('clutch_count',''))" 2>/dev/null || echo "")
assert "def1 clutch_count=0" "$DEF1_CLUTCH_COUNT" "0"

DEF1_TOTAL_DEF=$(echo "$DEF1_CLUTCH" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('total_defense_rounds',''))" 2>/dev/null || echo "")
assert "def1 total_defense_rounds=4" "$DEF1_TOTAL_DEF" "4"

DEF1_RATE=$(echo "$DEF1_CLUTCH" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('clutch_rate',''))" 2>/dev/null || echo "")
assert "def1 clutch_rate=0" "$DEF1_RATE" "0"

DEF1_TURNS_COUNT=$(echo "$DEF1_CLUTCH" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('clutch_turns',[])))" 2>/dev/null || echo "0")
assert "def1 clutch_turns=[] (empty)" "$DEF1_TURNS_COUNT" "0"

# ---------------------------------------------------------------------------
# Phase 7: GET /stats/combat/players/:login/clutches — player not in any turn
# ---------------------------------------------------------------------------
echo ""
echo "--- Phase 7: Clutch stats for unknown player ---"

UNKNOWN_CLUTCH=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/players/noone/clutches")
UNKNOWN_CLUTCH_COUNT=$(echo "$UNKNOWN_CLUTCH" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('clutch_count',''))" 2>/dev/null || echo "")
assert "unknown player clutch_count=0" "$UNKNOWN_CLUTCH_COUNT" "0"

UNKNOWN_DEF_ROUNDS=$(echo "$UNKNOWN_CLUTCH" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('total_defense_rounds',''))" 2>/dev/null || echo "")
assert "unknown player total_defense_rounds=0" "$UNKNOWN_DEF_ROUNDS" "0"

# ---------------------------------------------------------------------------
# Phase 8: GET /stats/combat/players/:login/turns — attacker1 (attacker in all 4 turns)
# ---------------------------------------------------------------------------
echo ""
echo "--- Phase 8: GET /stats/combat/players/attacker1/turns ---"

ATK_TURNS=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/players/attacker1/turns")
ATK_TURNS_COUNT=$(echo "$ATK_TURNS" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['pagination']['total'])" 2>/dev/null || echo "0")
assert "attacker1 turn history: 4 turns total" "$ATK_TURNS_COUNT" "4"

ATK_FIRST_ROLE=$(echo "$ATK_TURNS" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['data'][0]['role'])" 2>/dev/null || echo "")
assert "attacker1 role=attacker" "$ATK_FIRST_ROLE" "attacker"

ATK_PLAYER_LOGIN=$(echo "$ATK_TURNS" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('player_login',''))" 2>/dev/null || echo "")
assert "player turn history player_login=attacker1" "$ATK_PLAYER_LOGIN" "attacker1"

ATK_STATS_KILLS=$(echo "$ATK_TURNS" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['data'][0]['stats']['kills'])" 2>/dev/null || echo "")
assert "attacker1 first turn stats.kills=2" "$ATK_STATS_KILLS" "2"

# ---------------------------------------------------------------------------
# Phase 9: GET /stats/combat/players/:login/turns — def3 (defender in all 4 turns)
# ---------------------------------------------------------------------------
echo ""
echo "--- Phase 9: GET /stats/combat/players/def3/turns ---"

DEF3_TURNS=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/players/def3/turns")
DEF3_TOTAL=$(echo "$DEF3_TURNS" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['pagination']['total'])" 2>/dev/null || echo "0")
assert "def3 turn history: 4 turns total" "$DEF3_TOTAL" "4"

DEF3_FIRST_ROLE=$(echo "$DEF3_TURNS" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['data'][0]['role'])" 2>/dev/null || echo "")
assert "def3 role=defender" "$DEF3_FIRST_ROLE" "defender"

# Verify clutch field is present in turn history entry
DEF3_FIRST_CLUTCH=$(echo "$DEF3_TURNS" | python3 -c "import sys,json; d=json.load(sys.stdin); print(str(d['data'][0]['clutch']['is_clutch']).lower())" 2>/dev/null || echo "")
assert "def3 first turn (turn 4): is_clutch=true" "$DEF3_FIRST_CLUTCH" "true"

# ---------------------------------------------------------------------------
# Phase 10: Pagination on turn history
# ---------------------------------------------------------------------------
echo ""
echo "--- Phase 10: Pagination on /players/attacker1/turns ---"

ATK_PAGE1=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/players/attacker1/turns?limit=2&offset=0")
ATK_P1_COUNT=$(echo "$ATK_PAGE1" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('data',[])))" 2>/dev/null || echo "0")
assert "limit=2 offset=0 returns 2 turn entries" "$ATK_P1_COUNT" "2"

ATK_PAGE2=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/players/attacker1/turns?limit=2&offset=2")
ATK_P2_COUNT=$(echo "$ATK_PAGE2" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('data',[])))" 2>/dev/null || echo "0")
assert "limit=2 offset=2 returns 2 more entries" "$ATK_P2_COUNT" "2"

ATK_P2_LAST_TN=$(echo "$ATK_PAGE2" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['data'][1]['turn_number'])" 2>/dev/null || echo "0")
assert "page 2 last entry turn_number=1" "$ATK_P2_LAST_TN" "1"

# ---------------------------------------------------------------------------
# Phase 11: Player with no turns returns empty
# ---------------------------------------------------------------------------
echo ""
echo "--- Phase 11: Player not in any turn ---"

NOONE_TURNS=$(curl -s "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/players/noone/turns")
NOONE_TOTAL=$(echo "$NOONE_TURNS" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['pagination']['total'])" 2>/dev/null || echo "0")
assert "unknown player turn history: total=0" "$NOONE_TOTAL" "0"

NOONE_DATA=$(echo "$NOONE_TURNS" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('data',[])))" 2>/dev/null || echo "0")
assert "unknown player turn history: data=[]" "$NOONE_DATA" "0"

# ---------------------------------------------------------------------------
# Phase 12: 404 for unknown server on all new endpoints
# ---------------------------------------------------------------------------
echo ""
echo "--- Phase 12: 404 for unknown server ---"

HTTP_TURNS_404=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/servers/does-not-exist/stats/combat/turns")
assert_http "GET turns on unknown server returns 404" "$HTTP_TURNS_404" "404"

HTTP_TURN_NUM_404=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/servers/does-not-exist/stats/combat/turns/1")
assert_http "GET turn/:turnNumber on unknown server returns 404" "$HTTP_TURN_NUM_404" "404"

HTTP_CLUTCHES_404=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/servers/does-not-exist/stats/combat/players/def1/clutches")
assert_http "GET clutches on unknown server returns 404" "$HTTP_CLUTCHES_404" "404"

HTTP_PLAYER_TURNS_404=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/servers/does-not-exist/stats/combat/players/def1/turns")
assert_http "GET player turns on unknown server returns 404" "$HTTP_PLAYER_TURNS_404" "404"

# ---------------------------------------------------------------------------
# Phase 13: Server with no turn summaries returns empty (not 404)
# ---------------------------------------------------------------------------
echo ""
echo "--- Phase 13: Empty server (no turn summaries) ---"

EMPTY_SERVER="qa-elite-empty-$(date +%s)"
REG_EMPTY=$(curl -s -X PUT "$BASE_URL/servers/$EMPTY_SERVER/link/registration" \
  -H 'Content-Type: application/json' \
  -d '{"server_name":"Empty Server","plugin_version":"1.0.0","game_mode":"Elite","title_id":"SMStormElite@nadeolabs"}')
EMPTY_TOKEN=$(echo "$REG_EMPTY" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('link_token',''))" 2>/dev/null || echo "")

EMPTY_TURNS=$(curl -s "$BASE_URL/servers/$EMPTY_SERVER/stats/combat/turns")
HTTP_EMPTY_TURNS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/servers/$EMPTY_SERVER/stats/combat/turns")
assert_http "empty server: GET turns returns HTTP 200 (not 404)" "$HTTP_EMPTY_TURNS" "200"

EMPTY_TURNS_COUNT=$(echo "$EMPTY_TURNS" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('turns',[])))" 2>/dev/null || echo "0")
assert "empty server: turns=[] (empty array, not error)" "$EMPTY_TURNS_COUNT" "0"

EMPTY_CLUTCH=$(curl -s "$BASE_URL/servers/$EMPTY_SERVER/stats/combat/players/def1/clutches")
EMPTY_CLUTCH_COUNT=$(echo "$EMPTY_CLUTCH" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('clutch_count',''))" 2>/dev/null || echo "")
assert "empty server: clutch_count=0 (not error)" "$EMPTY_CLUTCH_COUNT" "0"

# Cleanup empty server
curl -s -X DELETE "$BASE_URL/servers/$EMPTY_SERVER" > /dev/null

# ---------------------------------------------------------------------------
# Phase 14: Backward compat — regular /stats/combat still works
# ---------------------------------------------------------------------------
echo ""
echo "--- Phase 14: Existing endpoints not broken ---"

HTTP_COMBAT=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/servers/$SERVER_LOGIN/stats/combat")
assert_http "GET /stats/combat still returns HTTP 200" "$HTTP_COMBAT" "200"

HTTP_PLAYERS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/servers/$SERVER_LOGIN/stats/combat/players")
assert_http "GET /stats/combat/players still returns HTTP 200" "$HTTP_PLAYERS" "200"

HTTP_SCORES=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/servers/$SERVER_LOGIN/stats/scores")
assert_http "GET /stats/scores still returns HTTP 200" "$HTTP_SCORES" "200"

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
if [ "$FAIL" -eq 0 ]; then
  echo -e "${green}All Elite enrichment smoke tests passed.${nc}"
  exit 0
else
  echo -e "${red}$FAIL test(s) FAILED.${nc}"
  exit 1
fi
