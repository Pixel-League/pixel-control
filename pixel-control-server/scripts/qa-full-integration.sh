#!/usr/bin/env bash
# qa-full-integration.sh — Comprehensive QA integration test for all P0–P2.6 endpoints
# Simulates a full Elite BO3 match session with 6 players, 3 maps, realistic combat events.
# Validates ALL 25 endpoints with 150+ assertions.
#
# Usage: bash scripts/qa-full-integration.sh [API_BASE]
# Requires: curl, jq, running server on port 3000
# macOS-compatible (no GNU-only commands)
#
# Exit codes: 0 = all pass, 1 = any failure

set -uo pipefail

# ---------------------------------------------------------------------------
# Constants and config
# ---------------------------------------------------------------------------
API="${1:-http://localhost:3000/v1}"
SERVER_LOGIN="qa-integration-server"
PLUGIN_VERSION="2.0.0"

# Run-unique ID for idempotency keys (macOS compatible: no %N)
RUN_TS=$(date +%s)

# Sequence counter stored in a temp file (subshell-safe)
_SEQ_FILE=$(mktemp)
echo "1" > "$_SEQ_FILE"

# Temp files for HTTP response body and status code
_BODY=$(mktemp)
_CODE=$(mktemp)

# Counters
PASS=0
FAIL=0
TOTAL=0

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
_LINK_TOKEN=""
cleanup() {
  echo ""
  echo "--- Cleanup ---"
  curl -s -o /dev/null -X DELETE "${API}/servers/${SERVER_LOGIN}" || true
  rm -f "$_SEQ_FILE" "$_BODY" "$_CODE" 2>/dev/null || true
  echo "Test server deleted."
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
# Logging helpers
# ---------------------------------------------------------------------------
log_section() {
  echo ""
  echo -e "${BOLD}${CYAN}══════════════════════════════════════════════════${NC}"
  echo -e "${BOLD}${CYAN}  $1${NC}"
  echo -e "${BOLD}${CYAN}══════════════════════════════════════════════════${NC}"
}

log_info()  { echo -e "${YELLOW}  [info]${NC} $1"; }
log_pass()  { echo -e "${GREEN}  [PASS]${NC} $1"; }
log_fail()  { echo -e "${RED}  [FAIL]${NC} $1"; }

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
    echo -e "         ${RED}expected:${NC} $expected"
    echo -e "         ${RED}actual:${NC}   $actual"
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
    echo -e "         ${RED}expected to contain:${NC} $substring"
    echo -e "         ${RED}actual:${NC} ${text:0:200}"
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
  fi
}

assert_null() {
  local label="$1" actual="$2"
  TOTAL=$((TOTAL + 1))
  if [ "$actual" = "null" ] || [ -z "$actual" ]; then
    PASS=$((PASS + 1))
    log_pass "$label"
  else
    FAIL=$((FAIL + 1))
    log_fail "$label (expected null, got: $actual)"
  fi
}

assert_gte() {
  local label="$1" expected="$2" actual="$3"
  TOTAL=$((TOTAL + 1))
  if [ -n "$actual" ] && [ "$actual" != "null" ] && [ "$actual" -ge "$expected" ] 2>/dev/null; then
    PASS=$((PASS + 1))
    log_pass "$label ($actual >= $expected)"
  else
    FAIL=$((FAIL + 1))
    log_fail "$label (expected >= $expected, got: $actual)"
  fi
}

assert_gt() {
  local label="$1" expected="$2" actual="$3"
  TOTAL=$((TOTAL + 1))
  if [ -n "$actual" ] && [ "$actual" != "null" ] && [ "$actual" -gt "$expected" ] 2>/dev/null; then
    PASS=$((PASS + 1))
    log_pass "$label ($actual > $expected)"
  else
    FAIL=$((FAIL + 1))
    log_fail "$label (expected > $expected, got: $actual)"
  fi
}

# jq-based field assertion: assert_jq "label" "expected" "$json" ".path"
assert_jq() {
  local label="$1" expected="$2" json="$3" expr="$4"
  local actual
  actual=$(echo "$json" | jq -r "$expr" 2>/dev/null)
  _assert "$label" "$expected" "$actual"
}

assert_jq_gte() {
  local label="$1" expected="$2" json="$3" expr="$4"
  local actual
  actual=$(echo "$json" | jq -r "$expr" 2>/dev/null)
  assert_gte "$label" "$expected" "$actual"
}

assert_jq_gt() {
  local label="$1" expected="$2" json="$3" expr="$4"
  local actual
  actual=$(echo "$json" | jq -r "$expr" 2>/dev/null)
  assert_gt "$label" "$expected" "$actual"
}

assert_jq_not_null() {
  local label="$1" json="$2" expr="$3"
  local actual
  actual=$(echo "$json" | jq -r "$expr" 2>/dev/null)
  assert_not_null "$label" "$actual"
}

# ---------------------------------------------------------------------------
# Sequence counter (macOS-safe file-based)
# ---------------------------------------------------------------------------
next_seq() {
  local seq
  seq=$(cat "$_SEQ_FILE")
  echo $((seq + 1)) > "$_SEQ_FILE"
  echo "$seq"
}

# ---------------------------------------------------------------------------
# HTTP helpers
# NOTE: HTTP_CODE is written to _CODE file so that callers using $(...) can
# read it back via: HTTP_CODE=$(cat "$_CODE")
# All do_get/send_event callers must read HTTP_CODE from the file.
# ---------------------------------------------------------------------------

# GET: writes body to $_BODY, writes HTTP code to $_CODE, returns body on stdout
do_get() {
  local url="$1"
  local code
  code=$(curl -s -o "$_BODY" -w "%{http_code}" "$url")
  echo "$code" > "$_CODE"
  cat "$_BODY"
}

# POST event to /v1/plugin/events
send_event() {
  local payload="$1"
  local code
  code=$(curl -s -o "$_BODY" -w "%{http_code}" \
    -X POST "${API}/plugin/events" \
    -H "Content-Type: application/json" \
    -H "X-Pixel-Server-Login: ${SERVER_LOGIN}" \
    -H "X-Pixel-Plugin-Version: ${PLUGIN_VERSION}" \
    -d "$payload")
  echo "$code" > "$_CODE"
  cat "$_BODY"
}

# Read HTTP code from temp file (must be called after do_get/send_event)
get_http_code() { cat "$_CODE" 2>/dev/null || echo "000"; }

# Build a standard event envelope JSON
# Usage: build_envelope "category" "source_callback" "normalized_cb" <seq> <time_ms> 'payload_json'
build_envelope() {
  local category="$1"
  local source_cb="$2"
  local norm_cb="$3"
  local seq="$4"
  local ts="$5"
  local payload="$6"
  local idem_key="pc-idem-${category}-${norm_cb}-${seq}-${RUN_TS}"
  jq -n \
    --arg event_name "pixel_control.${category}.${norm_cb}" \
    --arg event_id "pc-evt-${category}-${norm_cb}-${seq}-${RUN_TS}" \
    --arg event_category "$category" \
    --arg idempotency_key "$idem_key" \
    --arg source_callback "$source_cb" \
    --argjson source_sequence "$ts" \
    --argjson source_time "$ts" \
    --arg schema_version "2026-02-20.1" \
    --argjson payload "$payload" \
    '{
      event_name: $event_name,
      event_id: $event_id,
      event_category: $event_category,
      idempotency_key: $idempotency_key,
      source_callback: $source_callback,
      source_sequence: $source_sequence,
      source_time: $source_time,
      schema_version: $schema_version,
      payload: $payload
    }'
}

# ---------------------------------------------------------------------------
# Prerequisite check
# ---------------------------------------------------------------------------
check_prerequisites() {
  log_section "Prerequisites"
  if ! command -v curl &>/dev/null; then
    echo "ERROR: curl is required" >&2; exit 1
  fi
  if ! command -v jq &>/dev/null; then
    echo "ERROR: jq is required (brew install jq)" >&2; exit 1
  fi

  log_info "Waiting for API at $API..."
  local ready=0
  for i in $(seq 1 20); do
    if curl -sf "${API}/servers" -o /dev/null 2>/dev/null; then
      ready=1
      break
    fi
    echo "  Attempt $i/20 — not ready, waiting 2s..."
    sleep 2
  done
  if [ "$ready" -eq 0 ]; then
    echo "ERROR: API did not become ready after 40s." >&2; exit 1
  fi
  log_info "API is ready."
}

# ===========================================================================
# FIXTURE DATA — pre-computed for consistency
# ===========================================================================
#
# BO3 Elite match: Team 0 (Red) vs Team 1 (Blue)
# Team 0: alpha (login: qa-player-alpha), bravo (qa-player-bravo), charlie (qa-player-charlie)
# Team 1: delta (qa-player-delta), echo (qa-player-echo), foxtrot (qa-player-foxtrot)
#
# Maps:
#   Map 1 - Oasis Elite (uid: qa-map-oasis)    — Team 0 wins 3-2
#   Map 2 - Zenith Storm (uid: qa-map-zenith)   — Team 1 wins 3-1
#   Map 3 - Colosseum (uid: qa-map-colosseum)   — Team 0 wins 3-2 — BO3 result: 2-1 Team 0
#
# Per-map player_counters_delta (= cumulative since map.begin, reset per map):
#
# MAP 1 (Oasis Elite) — 5 rounds — Team 0 wins:
#   alpha:   kills=5, deaths=2, hits=8,  shots=20, rockets=15, lasers=5,  hits_rocket=5, hits_laser=3, attack_rounds_played=2, attack_rounds_won=2, defense_rounds_played=3, defense_rounds_won=1
#   bravo:   kills=3, deaths=1, hits=6,  shots=15, rockets=10, lasers=5,  hits_rocket=4, hits_laser=2, attack_rounds_played=1, attack_rounds_won=1, defense_rounds_played=4, defense_rounds_won=2
#   charlie: kills=2, deaths=2, hits=5,  shots=12, rockets=8,  lasers=4,  hits_rocket=3, hits_laser=2, attack_rounds_played=2, attack_rounds_won=1, defense_rounds_played=3, defense_rounds_won=1
#   delta:   kills=3, deaths=3, hits=7,  shots=18, rockets=12, lasers=6,  hits_rocket=4, hits_laser=3, attack_rounds_played=3, attack_rounds_won=1, defense_rounds_played=2, defense_rounds_won=0
#   echo:    kills=1, deaths=3, hits=4,  shots=10, rockets=7,  lasers=3,  hits_rocket=2, hits_laser=2, attack_rounds_played=1, attack_rounds_won=0, defense_rounds_played=4, defense_rounds_won=1
#   foxtrot: kills=2, deaths=2, hits=5,  shots=14, rockets=9,  lasers=5,  hits_rocket=3, hits_laser=2, attack_rounds_played=1, attack_rounds_won=0, defense_rounds_played=4, defense_rounds_won=1
#   win_context: winner_team_id=0
#   team_counters_delta: [{team_id:0, player_logins:[alpha,bravo,charlie]}, {team_id:1, player_logins:[delta,echo,foxtrot]}]
#
# MAP 2 (Zenith Storm) — 4 rounds — Team 1 wins:
#   alpha:   kills=3, deaths=3, hits=6,  shots=15, rockets=10, lasers=5,  hits_rocket=4, hits_laser=2, attack_rounds_played=2, attack_rounds_won=0, defense_rounds_played=2, defense_rounds_won=1
#   bravo:   kills=2, deaths=2, hits=4,  shots=10, rockets=6,  lasers=4,  hits_rocket=2, hits_laser=2, attack_rounds_played=1, attack_rounds_won=0, defense_rounds_played=3, defense_rounds_won=1
#   charlie: kills=1, deaths=3, hits=3,  shots=8,  rockets=5,  lasers=3,  hits_rocket=2, hits_laser=1, attack_rounds_played=1, attack_rounds_won=0, defense_rounds_played=3, defense_rounds_won=0
#   delta:   kills=4, deaths=2, hits=8,  shots=20, rockets=14, lasers=6,  hits_rocket=5, hits_laser=3, attack_rounds_played=2, attack_rounds_won=2, defense_rounds_played=2, defense_rounds_won=1
#   echo:    kills=3, deaths=1, hits=6,  shots=14, rockets=9,  lasers=5,  hits_rocket=4, hits_laser=2, attack_rounds_played=1, attack_rounds_won=1, defense_rounds_played=3, defense_rounds_won=2
#   foxtrot: kills=3, deaths=2, hits=7,  shots=16, rockets=11, lasers=5,  hits_rocket=4, hits_laser=3, attack_rounds_played=1, attack_rounds_won=1, defense_rounds_played=3, defense_rounds_won=1
#   win_context: winner_team_id=1
#
# MAP 3 (Colosseum) — 5 rounds — Team 0 wins:
#   alpha:   kills=6, deaths=2, hits=9,  shots=22, rockets=16, lasers=6,  hits_rocket=6, hits_laser=3, attack_rounds_played=2, attack_rounds_won=2, defense_rounds_played=3, defense_rounds_won=2
#   bravo:   kills=4, deaths=2, hits=7,  shots=17, rockets=12, lasers=5,  hits_rocket=5, hits_laser=2, attack_rounds_played=2, attack_rounds_won=2, defense_rounds_played=3, defense_rounds_won=1
#   charlie: kills=3, deaths=1, hits=6,  shots=13, rockets=9,  lasers=4,  hits_rocket=4, hits_laser=2, attack_rounds_played=1, attack_rounds_won=1, defense_rounds_played=4, defense_rounds_won=2
#   delta:   kills=2, deaths=4, hits=5,  shots=12, rockets=8,  lasers=4,  hits_rocket=3, hits_laser=2, attack_rounds_played=2, attack_rounds_won=1, defense_rounds_played=3, defense_rounds_won=0
#   echo:    kills=1, deaths=3, hits=3,  shots=9,  rockets=6,  lasers=3,  hits_rocket=2, hits_laser=1, attack_rounds_played=2, attack_rounds_won=0, defense_rounds_played=3, defense_rounds_won=1
#   foxtrot: kills=2, deaths=2, hits=5,  shots=11, rockets=7,  lasers=4,  hits_rocket=3, hits_laser=2, attack_rounds_played=1, attack_rounds_won=0, defense_rounds_played=4, defense_rounds_won=1
#   win_context: winner_team_id=0
#
# ALPHA TOTALS (across all 3 maps):
#   kills=14, deaths=7, hits=23, shots=57, rockets=41, lasers=16
#   hits_rocket=15, hits_laser=8
#   attack_rounds_played=6, attack_rounds_won=4, defense_rounds_played=8, defense_rounds_won=4
#   kd_ratio = 14/7 = 2.0
#   accuracy = 23/57 = 0.4035 (round to 4dp)
#   rocket_accuracy = 15/41 = 0.3659 (round to 4dp)
#   laser_accuracy = 8/16 = 0.5
#   attack_win_rate = 4/6 = 0.6667 (round to 4dp)
#   defense_win_rate = 4/8 = 0.5
#
# ALPHA win/loss per map: Map1=win(team0,winner0), Map2=lose(team0,winner1), Map3=win(team0,winner0)
#   maps_won=2, maps_played=3, win_rate=2/3=0.6667

# ===========================================================================
# PHASE 1 - Server setup and prerequisite check
# ===========================================================================
check_prerequisites

log_section "Phase 1 — Server Registration"

# Register server
log_info "Registering server $SERVER_LOGIN..."
REG=$(curl -s -X PUT "${API}/servers/${SERVER_LOGIN}/link/registration" \
  -H "Content-Type: application/json" \
  -d "{\"server_name\":\"QA Integration Server\",\"game_mode\":\"Elite\",\"title_id\":\"SMStormElite@nadeolabs\",\"plugin_version\":\"${PLUGIN_VERSION}\"}")

_LINK_TOKEN=$(echo "$REG" | jq -r '.link_token // empty')
assert_not_null "registration: link_token returned" "$_LINK_TOKEN"

# ===========================================================================
# PHASE 2 - Connectivity events
# ===========================================================================
log_section "Phase 2 — Connectivity Events"

SEQ=$(next_seq)
TS=$(( RUN_TS * 1000 + SEQ ))

# plugin_registration
REG_PAYLOAD=$(jq -n \
  --arg sv "2026-02-20.1" \
  --argjson ts "$TS" \
  '{
    event_kind: "plugin_registration",
    transition_kind: "registration",
    capabilities: {
      event_envelope: true,
      schema_version: "2026-02-20.1",
      admin_control: true,
      callback_groups: ["connectivity","lifecycle","combat","player","mode"],
      transport: {method: "http_post", format: "json"},
      queue: {enabled: true, max_size: 2000}
    },
    context: {
      players: {active: 6, total: 6, spectators: 0},
      server: {login: "qa-integration-server", name: "QA Integration Server"}
    },
    queue: {depth: 0, max_size: 2000, high_watermark: 0, dropped_on_capacity: 0, dropped_on_identity_validation: 0, recovery_flush_pending: false},
    retry: {max_retry_attempts: 3, retry_backoff_ms: 250, dispatch_batch_size: 3},
    outage: {active: false, started_at: null, failure_count: 0, last_error_code: null, recovery_flush_pending: false}
  }')

ENV=$(build_envelope "connectivity" "ManiaControl.PluginLoaded" "plugin_registration" "$SEQ" "$TS" "$REG_PAYLOAD")
RESP=$(send_event "$ENV")
assert_contains "connectivity registration accepted" '"accepted"' "$RESP"

SEQ=$(next_seq)
TS=$(( RUN_TS * 1000 + SEQ ))

# plugin_heartbeat
HB_PAYLOAD=$(jq -n '{
  event_kind: "plugin_heartbeat",
  transition_kind: "heartbeat",
  context: {
    players: {active: 6, total: 6, spectators: 0},
    server: {login: "qa-integration-server"}
  },
  queue: {depth: 0, max_size: 2000, high_watermark: 2, dropped_on_capacity: 0, dropped_on_identity_validation: 0, recovery_flush_pending: false},
  retry: {max_retry_attempts: 3, retry_backoff_ms: 250, dispatch_batch_size: 3},
  outage: {active: false, started_at: null, failure_count: 0, last_error_code: null, recovery_flush_pending: false}
}')

ENV=$(build_envelope "connectivity" "ManiaControl.Heartbeat" "plugin_heartbeat" "$SEQ" "$TS" "$HB_PAYLOAD")
RESP=$(send_event "$ENV")
assert_contains "connectivity heartbeat accepted" '"accepted"' "$RESP"

# ===========================================================================
# PHASE 3 — Player connect events (6 players)
# ===========================================================================
log_section "Phase 3 — Player Connect Events"

send_player_connect() {
  local login="$1" nickname="$2" team_id="$3"
  local seq ts env payload resp
  seq=$(next_seq)
  ts=$(( RUN_TS * 1000 + seq ))
  payload=$(jq -n \
    --arg login "$login" \
    --arg nickname "$nickname" \
    --argjson team_id "$team_id" \
    '{
      event_kind: "player.connect",
      transition_kind: "connectivity",
      player: {
        login: $login,
        nickname: $nickname,
        team_id: $team_id,
        is_spectator: false,
        is_connected: true,
        has_joined_game: true,
        auth_level: 0,
        auth_name: "player"
      },
      state_delta: {
        connectivity_state: "connected",
        readiness_state: "ready",
        eligibility_state: "eligible"
      },
      permission_signals: {can_join_round: true},
      roster_snapshot: {}
    }')
  env=$(build_envelope "player" "Shootmania.PlayerConnect" "player_connect" "$seq" "$ts" "$payload")
  resp=$(send_event "$env")
  assert_contains "player.connect accepted: $login" '"accepted"' "$resp"
}

send_player_connect "qa-player-alpha"   "Alpha"   0
send_player_connect "qa-player-bravo"   "Bravo"   0
send_player_connect "qa-player-charlie" "Charlie" 0
send_player_connect "qa-player-delta"   "Delta"   1
send_player_connect "qa-player-echo"    "Echo"    1
send_player_connect "qa-player-foxtrot" "Foxtrot" 1

# ===========================================================================
# PHASE 4 — Match begin
# ===========================================================================
log_section "Phase 4 — Match Begin"

SEQ=$(next_seq)
TS_MATCH_BEGIN=$(( RUN_TS * 1000 + SEQ ))

ENV=$(build_envelope "lifecycle" "Maniaplanet.BeginMatch" "maniaplanet_begin_match" "$SEQ" "$TS_MATCH_BEGIN" \
  '{"variant":"match.begin","phase":"match","state":"begin","source_channel":"maniaplanet","raw_source_callback":"Maniaplanet.BeginMatch"}')
RESP=$(send_event "$ENV")
assert_contains "lifecycle match.begin accepted" '"accepted"' "$RESP"

# ===========================================================================
# PHASE 5 — Map 1: Oasis Elite (Team 0 wins)
# ===========================================================================
log_section "Phase 5 — Map 1: Oasis Elite"

MAP_POOL='[
  {"uid":"qa-map-oasis","name":"Oasis Elite","file":"Oasis.Map.Gbx","environment":"Storm"},
  {"uid":"qa-map-zenith","name":"Zenith Storm","file":"Zenith.Map.Gbx","environment":"Storm"},
  {"uid":"qa-map-colosseum","name":"Colosseum","file":"Colosseum.Map.Gbx","environment":"Storm"}
]'

# map.begin map 1
SEQ=$(next_seq)
TS=$(( RUN_TS * 1000 + SEQ ))
TS_MAP1_BEGIN=$TS

MAP1_BEGIN_PAYLOAD=$(jq -n \
  --argjson map_pool "$MAP_POOL" \
  '{
    variant: "map.begin",
    phase: "map",
    state: "begin",
    map_rotation: {
      current_map: {uid: "qa-map-oasis", name: "Oasis Elite", file: "Oasis.Map.Gbx", environment: "Storm"},
      map_pool: $map_pool,
      map_pool_size: 3,
      current_map_index: 0,
      next_maps: [],
      played_map_order: [{order: 1, uid: "qa-map-oasis", name: "Oasis Elite"}],
      played_map_count: 0,
      series_targets: {best_of: 3, maps_score: {team_a: 0, team_b: 0}, current_map_score: {team_a: 0, team_b: 0}}
    }
  }')

ENV=$(build_envelope "lifecycle" "Maniaplanet.BeginMap" "maniaplanet_begin_map" "$SEQ" "$TS" "$MAP1_BEGIN_PAYLOAD")
RESP=$(send_event "$ENV")
assert_contains "lifecycle map.begin (Map1) accepted" '"accepted"' "$RESP"

# Mode event: Elite start
SEQ=$(next_seq)
TS=$(( RUN_TS * 1000 + SEQ ))
ENV=$(build_envelope "mode" "Shootmania.ModeScriptCallback.Shootmania_Elite_StartTurn" "shootmania_elite_startturn" "$SEQ" "$TS" \
  '{"raw_callback_summary":{"attacker":"qa-player-alpha","defenders":["qa-player-delta","qa-player-echo","qa-player-foxtrot"],"turn":1}}')
RESP=$(send_event "$ENV")
assert_contains "mode elite_startturn accepted" '"accepted"' "$RESP"

# round.begin
SEQ=$(next_seq)
TS=$(( RUN_TS * 1000 + SEQ ))
ENV=$(build_envelope "lifecycle" "Maniaplanet.BeginRound" "maniaplanet_begin_round" "$SEQ" "$TS" \
  '{"variant":"round.begin","phase":"round","state":"begin"}')
RESP=$(send_event "$ENV")
assert_contains "lifecycle round.begin accepted" '"accepted"' "$RESP"

# Combat events for 5 rounds on Map 1
# We send a few combat events per round and include cumulative player_counters
# The last map.end will carry the final cumulative values as player_counters_delta

# Round 1 combat — alpha attacks, delta/echo/foxtrot defend
# Counters accumulate across the entire map (reset at map.begin)

# After round 1 (partial cumulative):
# alpha: k=2,d=1,h=3,s=6,rkt=4,las=2,h_r=2,h_l=1,atk_p=1,atk_w=1,def_p=0,def_w=0
# delta: k=1,d=2,h=2,s=5,rkt=3,las=2,h_r=1,h_l=1,atk_p=0,atk_w=0,def_p=1,def_w=0

SEQ=$(next_seq)
TS=$(( RUN_TS * 1000 + SEQ ))
# onshoot — alpha fires rocket
SHOOT_PAYLOAD=$(jq -n '{
  event_kind: "shootmania_event_onshoot",
  dimensions: {weapon_id: 2, shooter: {login: "qa-player-alpha", nickname: "Alpha", team_id: 0}},
  player_counters: {
    "qa-player-alpha": {kills: 0, deaths: 0, hits: 0, shots: 1, misses: 0, rockets: 1, lasers: 0, hits_rocket: 0, hits_laser: 0, attack_rounds_played: 0, attack_rounds_won: 0, defense_rounds_played: 0, defense_rounds_won: 0}
  }
}')
ENV=$(build_envelope "combat" "Shootmania.Event.OnShoot" "shootmania_event_onshoot" "$SEQ" "$TS" "$SHOOT_PAYLOAD")
RESP=$(send_event "$ENV")
assert_contains "combat onshoot round1 accepted" '"accepted"' "$RESP"

# onarmorempty — alpha kills delta
SEQ=$(next_seq)
TS=$(( RUN_TS * 1000 + SEQ ))
KILL_PAYLOAD=$(jq -n '{
  event_kind: "shootmania_event_onarmorempty",
  dimensions: {
    weapon_id: 2,
    shooter: {login: "qa-player-alpha", nickname: "Alpha", team_id: 0},
    victim: {login: "qa-player-delta", nickname: "Delta", team_id: 1}
  },
  player_counters: {
    "qa-player-alpha": {kills: 1, deaths: 0, hits: 1, shots: 2, misses: 1, rockets: 2, lasers: 0, hits_rocket: 1, hits_laser: 0, attack_rounds_played: 0, attack_rounds_won: 0, defense_rounds_played: 0, defense_rounds_won: 0},
    "qa-player-delta": {kills: 0, deaths: 1, hits: 0, shots: 1, misses: 1, rockets: 1, lasers: 0, hits_rocket: 0, hits_laser: 0, attack_rounds_played: 0, attack_rounds_won: 0, defense_rounds_played: 0, defense_rounds_won: 0}
  }
}')
ENV=$(build_envelope "combat" "Shootmania.Event.OnArmorEmpty" "shootmania_event_onarmorempty" "$SEQ" "$TS" "$KILL_PAYLOAD")
RESP=$(send_event "$ENV")
assert_contains "combat onarmorempty round1 accepted" '"accepted"' "$RESP"

# Save idempotency key for dedup test
IDEM_KEY_FOR_DEDUP="pc-idem-combat-shootmania_event_onarmorempty-${SEQ}-${RUN_TS}"
IDEM_ENV_FOR_DEDUP="$KILL_PAYLOAD"
# We'll re-send the exact same envelope to test deduplication

# round.end
SEQ=$(next_seq)
TS=$(( RUN_TS * 1000 + SEQ ))
ENV=$(build_envelope "lifecycle" "Maniaplanet.EndRound" "maniaplanet_end_round" "$SEQ" "$TS" \
  '{"variant":"round.end","phase":"round","state":"end","aggregate_stats":{"scope":"round","totals":{},"win_context":{}}}')
RESP=$(send_event "$ENV")
assert_contains "lifecycle round.end accepted" '"accepted"' "$RESP"

# mode endturn
SEQ=$(next_seq)
TS=$(( RUN_TS * 1000 + SEQ ))
ENV=$(build_envelope "mode" "Shootmania.ModeScriptCallback.Shootmania_Elite_EndTurn" "shootmania_elite_endturn" "$SEQ" "$TS" \
  '{"raw_callback_summary":{"victoryType":2,"attacker":"qa-player-alpha","turn":1}}')
RESP=$(send_event "$ENV")
assert_contains "mode elite_endturn round1 accepted" '"accepted"' "$RESP"

# ---- Rounds 2-5: send fewer events, just update counters toward final values ----
# We send one combat event per round to track progress, ending with the final cumulative values

# Round 2 — delta attacks
SEQ=$(next_seq); TS=$(( RUN_TS * 1000 + SEQ ))
ENV=$(build_envelope "lifecycle" "Maniaplanet.BeginRound" "maniaplanet_begin_round" "$SEQ" "$TS" '{"variant":"round.begin"}')
send_event "$ENV" > /dev/null

SEQ=$(next_seq); TS=$(( RUN_TS * 1000 + SEQ ))
R2_PAYLOAD=$(jq -n '{
  event_kind: "shootmania_event_onarmorempty",
  dimensions: {
    weapon_id: 2,
    shooter: {login: "qa-player-delta", nickname: "Delta", team_id: 1},
    victim: {login: "qa-player-alpha", nickname: "Alpha", team_id: 0}
  },
  player_counters: {
    "qa-player-alpha": {kills: 1, deaths: 1, hits: 1, shots: 4, misses: 2, rockets: 3, lasers: 1, hits_rocket: 1, hits_laser: 0, attack_rounds_played: 1, attack_rounds_won: 1, defense_rounds_played: 0, defense_rounds_won: 0},
    "qa-player-bravo":  {kills: 1, deaths: 0, hits: 1, shots: 3, misses: 2, rockets: 2, lasers: 1, hits_rocket: 0, hits_laser: 1, attack_rounds_played: 0, attack_rounds_won: 0, defense_rounds_played: 1, defense_rounds_won: 1},
    "qa-player-delta":  {kills: 1, deaths: 1, hits: 2, shots: 6, misses: 4, rockets: 4, lasers: 2, hits_rocket: 1, hits_laser: 1, attack_rounds_played: 1, attack_rounds_won: 0, defense_rounds_played: 0, defense_rounds_won: 0}
  }
}')
ENV=$(build_envelope "combat" "Shootmania.Event.OnArmorEmpty" "shootmania_event_onarmorempty" "$SEQ" "$TS" "$R2_PAYLOAD")
send_event "$ENV" > /dev/null

SEQ=$(next_seq); TS=$(( RUN_TS * 1000 + SEQ ))
ENV=$(build_envelope "lifecycle" "Maniaplanet.EndRound" "maniaplanet_end_round" "$SEQ" "$TS" '{"variant":"round.end","aggregate_stats":{"scope":"round","totals":{},"win_context":{}}}')
send_event "$ENV" > /dev/null

# Round 3 — bravo attacks
SEQ=$(next_seq); TS=$(( RUN_TS * 1000 + SEQ ))
ENV=$(build_envelope "lifecycle" "Maniaplanet.BeginRound" "maniaplanet_begin_round" "$SEQ" "$TS" '{"variant":"round.begin"}')
send_event "$ENV" > /dev/null

SEQ=$(next_seq); TS=$(( RUN_TS * 1000 + SEQ ))
R3_PAYLOAD=$(jq -n '{
  event_kind: "shootmania_event_onarmorempty",
  dimensions: {
    weapon_id: 1,
    shooter: {login: "qa-player-bravo", nickname: "Bravo", team_id: 0},
    victim: {login: "qa-player-echo", nickname: "Echo", team_id: 1}
  },
  player_counters: {
    "qa-player-alpha":   {kills: 2, deaths: 1, hits: 3, shots: 8,  misses: 5,  rockets: 6,  lasers: 2, hits_rocket: 2, hits_laser: 1, attack_rounds_played: 1, attack_rounds_won: 1, defense_rounds_played: 1, defense_rounds_won: 0},
    "qa-player-bravo":   {kills: 3, deaths: 0, hits: 5, shots: 10, misses: 5,  rockets: 7,  lasers: 3, hits_rocket: 3, hits_laser: 2, attack_rounds_played: 1, attack_rounds_won: 1, defense_rounds_played: 1, defense_rounds_won: 1},
    "qa-player-charlie": {kills: 1, deaths: 1, hits: 2, shots: 5,  misses: 3,  rockets: 3,  lasers: 2, hits_rocket: 1, hits_laser: 1, attack_rounds_played: 0, attack_rounds_won: 0, defense_rounds_played: 1, defense_rounds_won: 0},
    "qa-player-delta":   {kills: 1, deaths: 2, hits: 3, shots: 9,  misses: 6,  rockets: 6,  lasers: 3, hits_rocket: 2, hits_laser: 1, attack_rounds_played: 1, attack_rounds_won: 0, defense_rounds_played: 1, defense_rounds_won: 0},
    "qa-player-echo":    {kills: 1, deaths: 2, hits: 2, shots: 5,  misses: 3,  rockets: 3,  lasers: 2, hits_rocket: 1, hits_laser: 1, attack_rounds_played: 0, attack_rounds_won: 0, defense_rounds_played: 1, defense_rounds_won: 0},
    "qa-player-foxtrot": {kills: 1, deaths: 1, hits: 2, shots: 6,  misses: 4,  rockets: 4,  lasers: 2, hits_rocket: 1, hits_laser: 1, attack_rounds_played: 0, attack_rounds_won: 0, defense_rounds_played: 1, defense_rounds_won: 0}
  }
}')
ENV=$(build_envelope "combat" "Shootmania.Event.OnArmorEmpty" "shootmania_event_onarmorempty" "$SEQ" "$TS" "$R3_PAYLOAD")
send_event "$ENV" > /dev/null

SEQ=$(next_seq); TS=$(( RUN_TS * 1000 + SEQ ))
ENV=$(build_envelope "lifecycle" "Maniaplanet.EndRound" "maniaplanet_end_round" "$SEQ" "$TS" '{"variant":"round.end","aggregate_stats":{"scope":"round","totals":{},"win_context":{}}}')
send_event "$ENV" > /dev/null

# Round 4 — echo attacks
SEQ=$(next_seq); TS=$(( RUN_TS * 1000 + SEQ ))
ENV=$(build_envelope "lifecycle" "Maniaplanet.BeginRound" "maniaplanet_begin_round" "$SEQ" "$TS" '{"variant":"round.begin"}')
send_event "$ENV" > /dev/null

SEQ=$(next_seq); TS=$(( RUN_TS * 1000 + SEQ ))
R4_PAYLOAD=$(jq -n '{
  event_kind: "shootmania_event_onarmorempty",
  dimensions: {
    weapon_id: 2,
    shooter: {login: "qa-player-alpha", nickname: "Alpha", team_id: 0},
    victim: {login: "qa-player-echo", nickname: "Echo", team_id: 1}
  },
  player_counters: {
    "qa-player-alpha":   {kills: 3, deaths: 1, hits: 5, shots: 12, misses: 7, rockets: 9,  lasers: 3, hits_rocket: 3, hits_laser: 2, attack_rounds_played: 1, attack_rounds_won: 1, defense_rounds_played: 2, defense_rounds_won: 0},
    "qa-player-bravo":   {kills: 3, deaths: 0, hits: 5, shots: 11, misses: 6, rockets: 8,  lasers: 3, hits_rocket: 3, hits_laser: 2, attack_rounds_played: 1, attack_rounds_won: 1, defense_rounds_played: 2, defense_rounds_won: 1},
    "qa-player-charlie": {kills: 1, deaths: 1, hits: 3, shots: 7,  misses: 4, rockets: 5,  lasers: 2, hits_rocket: 2, hits_laser: 1, attack_rounds_played: 0, attack_rounds_won: 0, defense_rounds_played: 2, defense_rounds_won: 0},
    "qa-player-delta":   {kills: 2, deaths: 2, hits: 4, shots: 11, misses: 7, rockets: 7,  lasers: 4, hits_rocket: 2, hits_laser: 2, attack_rounds_played: 2, attack_rounds_won: 0, defense_rounds_played: 1, defense_rounds_won: 0},
    "qa-player-echo":    {kills: 1, deaths: 3, hits: 3, shots: 7,  misses: 4, rockets: 5,  lasers: 2, hits_rocket: 1, hits_laser: 2, attack_rounds_played: 1, attack_rounds_won: 0, defense_rounds_played: 2, defense_rounds_won: 1},
    "qa-player-foxtrot": {kills: 1, deaths: 1, hits: 3, shots: 8,  misses: 5, rockets: 5,  lasers: 3, hits_rocket: 1, hits_laser: 2, attack_rounds_played: 0, attack_rounds_won: 0, defense_rounds_played: 2, defense_rounds_won: 0}
  }
}')
ENV=$(build_envelope "combat" "Shootmania.Event.OnArmorEmpty" "shootmania_event_onarmorempty" "$SEQ" "$TS" "$R4_PAYLOAD")
send_event "$ENV" > /dev/null

SEQ=$(next_seq); TS=$(( RUN_TS * 1000 + SEQ ))
ENV=$(build_envelope "lifecycle" "Maniaplanet.EndRound" "maniaplanet_end_round" "$SEQ" "$TS" '{"variant":"round.end","aggregate_stats":{"scope":"round","totals":{},"win_context":{}}}')
send_event "$ENV" > /dev/null

# Round 5 — charlie attacks, Map 1 ends 3-2 Team 0
SEQ=$(next_seq); TS=$(( RUN_TS * 1000 + SEQ ))
ENV=$(build_envelope "lifecycle" "Maniaplanet.BeginRound" "maniaplanet_begin_round" "$SEQ" "$TS" '{"variant":"round.begin"}')
send_event "$ENV" > /dev/null

# Final cumulative counters for Map 1 (these become player_counters_delta on map.end)
SEQ=$(next_seq); TS=$(( RUN_TS * 1000 + SEQ ))
MAP1_FINAL_COUNTERS=$(jq -n '{
  "qa-player-alpha":   {kills: 5, deaths: 2, hits: 8,  shots: 20, misses: 12, rockets: 15, lasers: 5,  hits_rocket: 5, hits_laser: 3, attack_rounds_played: 2, attack_rounds_won: 2, defense_rounds_played: 3, defense_rounds_won: 1},
  "qa-player-bravo":   {kills: 3, deaths: 1, hits: 6,  shots: 15, misses: 9,  rockets: 10, lasers: 5,  hits_rocket: 4, hits_laser: 2, attack_rounds_played: 1, attack_rounds_won: 1, defense_rounds_played: 4, defense_rounds_won: 2},
  "qa-player-charlie": {kills: 2, deaths: 2, hits: 5,  shots: 12, misses: 7,  rockets: 8,  lasers: 4,  hits_rocket: 3, hits_laser: 2, attack_rounds_played: 2, attack_rounds_won: 1, defense_rounds_played: 3, defense_rounds_won: 1},
  "qa-player-delta":   {kills: 3, deaths: 3, hits: 7,  shots: 18, misses: 11, rockets: 12, lasers: 6,  hits_rocket: 4, hits_laser: 3, attack_rounds_played: 3, attack_rounds_won: 1, defense_rounds_played: 2, defense_rounds_won: 0},
  "qa-player-echo":    {kills: 1, deaths: 3, hits: 4,  shots: 10, misses: 6,  rockets: 7,  lasers: 3,  hits_rocket: 2, hits_laser: 2, attack_rounds_played: 1, attack_rounds_won: 0, defense_rounds_played: 4, defense_rounds_won: 1},
  "qa-player-foxtrot": {kills: 2, deaths: 2, hits: 5,  shots: 14, misses: 9,  rockets: 9,  lasers: 5,  hits_rocket: 3, hits_laser: 2, attack_rounds_played: 1, attack_rounds_won: 0, defense_rounds_played: 4, defense_rounds_won: 1}
}')
R5_PAYLOAD=$(jq -n --argjson pc "$MAP1_FINAL_COUNTERS" '{
  event_kind: "shootmania_event_onarmorempty",
  dimensions: {
    weapon_id: 2,
    shooter: {login: "qa-player-charlie", nickname: "Charlie", team_id: 0},
    victim: {login: "qa-player-foxtrot", nickname: "Foxtrot", team_id: 1}
  },
  player_counters: $pc
}')
ENV=$(build_envelope "combat" "Shootmania.Event.OnArmorEmpty" "shootmania_event_onarmorempty" "$SEQ" "$TS" "$R5_PAYLOAD")
RESP=$(send_event "$ENV")
assert_contains "combat final round Map1 accepted" '"accepted"' "$RESP"

SEQ=$(next_seq); TS=$(( RUN_TS * 1000 + SEQ ))
ENV=$(build_envelope "lifecycle" "Maniaplanet.EndRound" "maniaplanet_end_round" "$SEQ" "$TS" '{"variant":"round.end","aggregate_stats":{"scope":"round","totals":{},"win_context":{}}}')
send_event "$ENV" > /dev/null

# Scores event EndMap
SEQ=$(next_seq); TS=$(( RUN_TS * 1000 + SEQ ))
SCORES_ENDMAP1=$(jq -n '{
  event_kind: "scores",
  scores_section: "EndMap",
  scores_snapshot: {
    section: "EndMap",
    use_teams: true,
    winner_team_id: 0,
    team_scores: [
      {team_id: 0, round_points: 3, map_points: 3, match_points: 1},
      {team_id: 1, round_points: 2, map_points: 2, match_points: 0}
    ],
    player_scores: [
      {login: "qa-player-alpha",   nickname: "Alpha",   team_id: 0, rank: 1, round_points: 0, map_points: 0, match_points: 0},
      {login: "qa-player-bravo",   nickname: "Bravo",   team_id: 0, rank: 2, round_points: 0, map_points: 0, match_points: 0},
      {login: "qa-player-charlie", nickname: "Charlie", team_id: 0, rank: 3, round_points: 0, map_points: 0, match_points: 0},
      {login: "qa-player-delta",   nickname: "Delta",   team_id: 1, rank: 4, round_points: 0, map_points: 0, match_points: 0},
      {login: "qa-player-echo",    nickname: "Echo",    team_id: 1, rank: 5, round_points: 0, map_points: 0, match_points: 0},
      {login: "qa-player-foxtrot", nickname: "Foxtrot", team_id: 1, rank: 6, round_points: 0, map_points: 0, match_points: 0}
    ]
  },
  scores_result: {result_state: "team_win", winning_team_id: 0}
}')
ENV=$(build_envelope "combat" "Shootmania.Scores" "shootmania_scores" "$SEQ" "$TS" "$SCORES_ENDMAP1")
send_event "$ENV" > /dev/null

# map.end Map 1
SEQ=$(next_seq)
TS=$(( RUN_TS * 1000 + SEQ ))
TS_MAP1_END=$TS

MAP1_END_PAYLOAD=$(jq -n \
  --argjson pc "$MAP1_FINAL_COUNTERS" \
  --argjson map_pool "$MAP_POOL" \
  '{
    variant: "map.end",
    phase: "map",
    state: "end",
    map_rotation: {
      current_map: {uid: "qa-map-oasis", name: "Oasis Elite", file: "Oasis.Map.Gbx", environment: "Storm"},
      map_pool: $map_pool,
      map_pool_size: 3,
      current_map_index: 0,
      next_maps: [{uid: "qa-map-zenith", name: "Zenith Storm"}],
      played_map_order: [{order: 1, uid: "qa-map-oasis", name: "Oasis Elite"}],
      played_map_count: 1,
      series_targets: {best_of: 3, maps_score: {team_a: 1, team_b: 0}, current_map_score: {team_a: 3, team_b: 2}}
    },
    aggregate_stats: {
      scope: "map",
      player_counters_delta: $pc,
      team_counters_delta: [
        {team_id: 0, player_logins: ["qa-player-alpha","qa-player-bravo","qa-player-charlie"]},
        {team_id: 1, player_logins: ["qa-player-delta","qa-player-echo","qa-player-foxtrot"]}
      ],
      totals: {kills: 16, deaths: 13, hits: 35, shots: 89},
      win_context: {winner_team_id: 0, winning_team: "Team 0 Red", rounds_played: 5, rounds_team_a: 3, rounds_team_b: 2},
      window: {started_at: 1740000000000, ended_at: 1740000300000, duration_seconds: 300}
    }
  }')

ENV=$(build_envelope "lifecycle" "Maniaplanet.EndMap" "maniaplanet_end_map" "$SEQ" "$TS_MAP1_END" "$MAP1_END_PAYLOAD")
RESP=$(send_event "$ENV")
assert_contains "lifecycle map.end (Map1) accepted" '"accepted"' "$RESP"

# ===========================================================================
# PHASE 6 — Map 2: Zenith Storm (Team 1 wins)
# ===========================================================================
log_section "Phase 6 — Map 2: Zenith Storm"

SEQ=$(next_seq); TS=$(( RUN_TS * 1000 + SEQ ))
MAP2_BEGIN_PAYLOAD=$(jq -n \
  --argjson map_pool "$MAP_POOL" \
  '{
    variant: "map.begin",
    phase: "map",
    state: "begin",
    map_rotation: {
      current_map: {uid: "qa-map-zenith", name: "Zenith Storm", file: "Zenith.Map.Gbx", environment: "Storm"},
      map_pool: $map_pool,
      map_pool_size: 3,
      current_map_index: 1,
      next_maps: [{uid: "qa-map-colosseum", name: "Colosseum"}],
      played_map_order: [
        {order: 1, uid: "qa-map-oasis", name: "Oasis Elite"},
        {order: 2, uid: "qa-map-zenith", name: "Zenith Storm"}
      ],
      played_map_count: 1,
      series_targets: {best_of: 3, maps_score: {team_a: 1, team_b: 0}, current_map_score: {team_a: 0, team_b: 0}}
    }
  }')
ENV=$(build_envelope "lifecycle" "Maniaplanet.BeginMap" "maniaplanet_begin_map" "$SEQ" "$TS" "$MAP2_BEGIN_PAYLOAD")
RESP=$(send_event "$ENV")
assert_contains "lifecycle map.begin (Map2) accepted" '"accepted"' "$RESP"

# 4 rounds of combat on Map 2 (fresh counters)
# Final cumulative for map 2:
MAP2_FINAL_COUNTERS=$(jq -n '{
  "qa-player-alpha":   {kills: 3, deaths: 3, hits: 6,  shots: 15, misses: 9,  rockets: 10, lasers: 5,  hits_rocket: 4, hits_laser: 2, attack_rounds_played: 2, attack_rounds_won: 0, defense_rounds_played: 2, defense_rounds_won: 1},
  "qa-player-bravo":   {kills: 2, deaths: 2, hits: 4,  shots: 10, misses: 6,  rockets: 6,  lasers: 4,  hits_rocket: 2, hits_laser: 2, attack_rounds_played: 1, attack_rounds_won: 0, defense_rounds_played: 3, defense_rounds_won: 1},
  "qa-player-charlie": {kills: 1, deaths: 3, hits: 3,  shots: 8,  misses: 5,  rockets: 5,  lasers: 3,  hits_rocket: 2, hits_laser: 1, attack_rounds_played: 1, attack_rounds_won: 0, defense_rounds_played: 3, defense_rounds_won: 0},
  "qa-player-delta":   {kills: 4, deaths: 2, hits: 8,  shots: 20, misses: 12, rockets: 14, lasers: 6,  hits_rocket: 5, hits_laser: 3, attack_rounds_played: 2, attack_rounds_won: 2, defense_rounds_played: 2, defense_rounds_won: 1},
  "qa-player-echo":    {kills: 3, deaths: 1, hits: 6,  shots: 14, misses: 8,  rockets: 9,  lasers: 5,  hits_rocket: 4, hits_laser: 2, attack_rounds_played: 1, attack_rounds_won: 1, defense_rounds_played: 3, defense_rounds_won: 2},
  "qa-player-foxtrot": {kills: 3, deaths: 2, hits: 7,  shots: 16, misses: 9,  rockets: 11, lasers: 5,  hits_rocket: 4, hits_laser: 3, attack_rounds_played: 1, attack_rounds_won: 1, defense_rounds_played: 3, defense_rounds_won: 1}
}')

# Send a representative mid-map combat event
SEQ=$(next_seq); TS=$(( RUN_TS * 1000 + SEQ ))
_MID_MAP2_PC=$(jq -n '{
  "qa-player-alpha":   {kills: 1, deaths: 2, hits: 3, shots: 7,  rockets: 5, lasers: 2, hits_rocket: 2, hits_laser: 1, attack_rounds_played: 1, attack_rounds_won: 0, defense_rounds_played: 1, defense_rounds_won: 0},
  "qa-player-delta":   {kills: 2, deaths: 1, hits: 4, shots: 10, rockets: 7, lasers: 3, hits_rocket: 3, hits_laser: 1, attack_rounds_played: 1, attack_rounds_won: 1, defense_rounds_played: 1, defense_rounds_won: 0}
}')
MID_MAP2=$(jq -n --argjson partial "$_MID_MAP2_PC" '{event_kind:"shootmania_event_onarmorempty", dimensions:{weapon_id:2}, player_counters:$partial}')
ENV=$(build_envelope "combat" "Shootmania.Event.OnArmorEmpty" "shootmania_event_onarmorempty" "$SEQ" "$TS" "$MID_MAP2")
RESP=$(send_event "$ENV")
assert_contains "combat onarmorempty Map2 accepted" '"accepted"' "$RESP"

# Final Map 2 combat event with full counters
SEQ=$(next_seq); TS=$(( RUN_TS * 1000 + SEQ ))
FINAL_MAP2_COMBAT=$(jq -n --argjson pc "$MAP2_FINAL_COUNTERS" '{
  event_kind: "shootmania_event_onarmorempty",
  dimensions: {weapon_id: 2, shooter: {login: "qa-player-delta", nickname: "Delta", team_id: 1}},
  player_counters: $pc
}')
ENV=$(build_envelope "combat" "Shootmania.Event.OnArmorEmpty" "shootmania_event_onarmorempty" "$SEQ" "$TS" "$FINAL_MAP2_COMBAT")
RESP=$(send_event "$ENV")
assert_contains "combat final Map2 accepted" '"accepted"' "$RESP"

# map.end Map 2
SEQ=$(next_seq); TS=$(( RUN_TS * 1000 + SEQ ))
TS_MAP2_END=$TS

MAP2_END_PAYLOAD=$(jq -n \
  --argjson pc "$MAP2_FINAL_COUNTERS" \
  --argjson map_pool "$MAP_POOL" \
  '{
    variant: "map.end",
    phase: "map",
    state: "end",
    map_rotation: {
      current_map: {uid: "qa-map-zenith", name: "Zenith Storm", file: "Zenith.Map.Gbx", environment: "Storm"},
      map_pool: $map_pool,
      map_pool_size: 3,
      current_map_index: 1,
      next_maps: [{uid: "qa-map-colosseum", name: "Colosseum"}],
      played_map_order: [
        {order: 1, uid: "qa-map-oasis", name: "Oasis Elite"},
        {order: 2, uid: "qa-map-zenith", name: "Zenith Storm"}
      ],
      played_map_count: 2,
      series_targets: {best_of: 3, maps_score: {team_a: 1, team_b: 1}, current_map_score: {team_a: 1, team_b: 3}}
    },
    aggregate_stats: {
      scope: "map",
      player_counters_delta: $pc,
      team_counters_delta: [
        {team_id: 0, player_logins: ["qa-player-alpha","qa-player-bravo","qa-player-charlie"]},
        {team_id: 1, player_logins: ["qa-player-delta","qa-player-echo","qa-player-foxtrot"]}
      ],
      totals: {kills: 16, deaths: 13, hits: 34, shots: 83},
      win_context: {winner_team_id: 1, winning_team: "Team 1 Blue", rounds_played: 4, rounds_team_a: 1, rounds_team_b: 3},
      window: {started_at: 1740000300000, ended_at: 1740000560000, duration_seconds: 260}
    }
  }')

ENV=$(build_envelope "lifecycle" "Maniaplanet.EndMap" "maniaplanet_end_map" "$SEQ" "$TS_MAP2_END" "$MAP2_END_PAYLOAD")
RESP=$(send_event "$ENV")
assert_contains "lifecycle map.end (Map2) accepted" '"accepted"' "$RESP"

# ===========================================================================
# PHASE 7 — Map 3: Colosseum (Team 0 wins — BO3 ends 2-1)
# ===========================================================================
log_section "Phase 7 — Map 3: Colosseum"

SEQ=$(next_seq); TS=$(( RUN_TS * 1000 + SEQ ))
MAP3_BEGIN_PAYLOAD=$(jq -n \
  --argjson map_pool "$MAP_POOL" \
  '{
    variant: "map.begin",
    phase: "map",
    state: "begin",
    map_rotation: {
      current_map: {uid: "qa-map-colosseum", name: "Colosseum", file: "Colosseum.Map.Gbx", environment: "Storm"},
      map_pool: $map_pool,
      map_pool_size: 3,
      current_map_index: 2,
      next_maps: [],
      played_map_order: [
        {order: 1, uid: "qa-map-oasis", name: "Oasis Elite"},
        {order: 2, uid: "qa-map-zenith", name: "Zenith Storm"},
        {order: 3, uid: "qa-map-colosseum", name: "Colosseum"}
      ],
      played_map_count: 2,
      series_targets: {best_of: 3, maps_score: {team_a: 1, team_b: 1}, current_map_score: {team_a: 0, team_b: 0}}
    }
  }')
ENV=$(build_envelope "lifecycle" "Maniaplanet.BeginMap" "maniaplanet_begin_map" "$SEQ" "$TS" "$MAP3_BEGIN_PAYLOAD")
RESP=$(send_event "$ENV")
assert_contains "lifecycle map.begin (Map3) accepted" '"accepted"' "$RESP"

# Final cumulative for map 3:
MAP3_FINAL_COUNTERS=$(jq -n '{
  "qa-player-alpha":   {kills: 6, deaths: 2, hits: 9,  shots: 22, misses: 13, rockets: 16, lasers: 6,  hits_rocket: 6, hits_laser: 3, attack_rounds_played: 2, attack_rounds_won: 2, defense_rounds_played: 3, defense_rounds_won: 2},
  "qa-player-bravo":   {kills: 4, deaths: 2, hits: 7,  shots: 17, misses: 10, rockets: 12, lasers: 5,  hits_rocket: 5, hits_laser: 2, attack_rounds_played: 2, attack_rounds_won: 2, defense_rounds_played: 3, defense_rounds_won: 1},
  "qa-player-charlie": {kills: 3, deaths: 1, hits: 6,  shots: 13, misses: 7,  rockets: 9,  lasers: 4,  hits_rocket: 4, hits_laser: 2, attack_rounds_played: 1, attack_rounds_won: 1, defense_rounds_played: 4, defense_rounds_won: 2},
  "qa-player-delta":   {kills: 2, deaths: 4, hits: 5,  shots: 12, misses: 7,  rockets: 8,  lasers: 4,  hits_rocket: 3, hits_laser: 2, attack_rounds_played: 2, attack_rounds_won: 1, defense_rounds_played: 3, defense_rounds_won: 0},
  "qa-player-echo":    {kills: 1, deaths: 3, hits: 3,  shots: 9,  misses: 6,  rockets: 6,  lasers: 3,  hits_rocket: 2, hits_laser: 1, attack_rounds_played: 2, attack_rounds_won: 0, defense_rounds_played: 3, defense_rounds_won: 1},
  "qa-player-foxtrot": {kills: 2, deaths: 2, hits: 5,  shots: 11, misses: 6,  rockets: 7,  lasers: 4,  hits_rocket: 3, hits_laser: 2, attack_rounds_played: 1, attack_rounds_won: 0, defense_rounds_played: 4, defense_rounds_won: 1}
}')

# Final Map 3 combat event
SEQ=$(next_seq); TS=$(( RUN_TS * 1000 + SEQ ))
FINAL_MAP3_COMBAT=$(jq -n --argjson pc "$MAP3_FINAL_COUNTERS" '{
  event_kind: "shootmania_event_onarmorempty",
  dimensions: {weapon_id: 1, shooter: {login: "qa-player-alpha", nickname: "Alpha", team_id: 0}},
  player_counters: $pc
}')
ENV=$(build_envelope "combat" "Shootmania.Event.OnArmorEmpty" "shootmania_event_onarmorempty" "$SEQ" "$TS" "$FINAL_MAP3_COMBAT")
RESP=$(send_event "$ENV")
assert_contains "combat final Map3 accepted" '"accepted"' "$RESP"

# map.end Map 3
SEQ=$(next_seq); TS=$(( RUN_TS * 1000 + SEQ ))
TS_MAP3_END=$TS

MAP3_END_PAYLOAD=$(jq -n \
  --argjson pc "$MAP3_FINAL_COUNTERS" \
  --argjson map_pool "$MAP_POOL" \
  '{
    variant: "map.end",
    phase: "map",
    state: "end",
    map_rotation: {
      current_map: {uid: "qa-map-colosseum", name: "Colosseum", file: "Colosseum.Map.Gbx", environment: "Storm"},
      map_pool: $map_pool,
      map_pool_size: 3,
      current_map_index: 2,
      next_maps: [],
      played_map_order: [
        {order: 1, uid: "qa-map-oasis", name: "Oasis Elite"},
        {order: 2, uid: "qa-map-zenith", name: "Zenith Storm"},
        {order: 3, uid: "qa-map-colosseum", name: "Colosseum"}
      ],
      played_map_count: 3,
      series_targets: {best_of: 3, maps_score: {team_a: 2, team_b: 1}, current_map_score: {team_a: 3, team_b: 2}}
    },
    aggregate_stats: {
      scope: "map",
      player_counters_delta: $pc,
      team_counters_delta: [
        {team_id: 0, player_logins: ["qa-player-alpha","qa-player-bravo","qa-player-charlie"]},
        {team_id: 1, player_logins: ["qa-player-delta","qa-player-echo","qa-player-foxtrot"]}
      ],
      totals: {kills: 18, deaths: 14, hits: 35, shots: 84},
      win_context: {winner_team_id: 0, winning_team: "Team 0 Red", rounds_played: 5, rounds_team_a: 3, rounds_team_b: 2},
      window: {started_at: 1740000560000, ended_at: 1740000880000, duration_seconds: 320}
    }
  }')

ENV=$(build_envelope "lifecycle" "Maniaplanet.EndMap" "maniaplanet_end_map" "$SEQ" "$TS_MAP3_END" "$MAP3_END_PAYLOAD")
RESP=$(send_event "$ENV")
assert_contains "lifecycle map.end (Map3) accepted" '"accepted"' "$RESP"

# ===========================================================================
# PHASE 8 — Match end
# ===========================================================================
log_section "Phase 8 — Match End"

SEQ=$(next_seq); TS=$(( RUN_TS * 1000 + SEQ ))
TS_MATCH_END=$TS

ENV=$(build_envelope "lifecycle" "Maniaplanet.EndMatch" "maniaplanet_end_match" "$SEQ" "$TS_MATCH_END" \
  '{"variant":"match.end","phase":"match","state":"end","aggregate_stats":{"scope":"match","win_context":{"winner_team_id":0},"totals":{"kills":50}}}')
RESP=$(send_event "$ENV")
assert_contains "lifecycle match.end accepted" '"accepted"' "$RESP"

# Final scores event EndMatch — includes player_counters (Map3 final values)
# so that getCombatPlayersCounters() can return all 6 players from this latest combat event.
# Map3 alpha: kills=6, deaths=2, hits=9, shots=22, rockets=16, lasers=6, hits_rocket=6, hits_laser=3
#   kd_ratio = 6/2 = 3.0, accuracy = 9/22 = 0.4091, rocket_accuracy = 6/16 = 0.375, laser_accuracy = 3/6 = 0.5
#   attack_rounds_played=2, attack_rounds_won=2, attack_win_rate=1.0
#   defense_rounds_played=3, defense_rounds_won=2, defense_win_rate=0.6667
SEQ=$(next_seq); TS=$(( RUN_TS * 1000 + SEQ ))
SCORES_ENDMATCH=$(jq -n --argjson pc "$MAP3_FINAL_COUNTERS" '{
  event_kind: "scores",
  scores_section: "EndMatch",
  scores_snapshot: {
    section: "EndMatch",
    use_teams: true,
    winner_team_id: 0,
    team_scores: [
      {team_id: 0, round_points: 0, map_points: 0, match_points: 2},
      {team_id: 1, round_points: 0, map_points: 0, match_points: 1}
    ],
    player_scores: [
      {login: "qa-player-alpha",   nickname: "Alpha",   team_id: 0, rank: 1, round_points: 0, map_points: 0, match_points: 0},
      {login: "qa-player-bravo",   nickname: "Bravo",   team_id: 0, rank: 2, round_points: 0, map_points: 0, match_points: 0},
      {login: "qa-player-charlie", nickname: "Charlie", team_id: 0, rank: 3, round_points: 0, map_points: 0, match_points: 0},
      {login: "qa-player-delta",   nickname: "Delta",   team_id: 1, rank: 4, round_points: 0, map_points: 0, match_points: 0},
      {login: "qa-player-echo",    nickname: "Echo",    team_id: 1, rank: 5, round_points: 0, map_points: 0, match_points: 0},
      {login: "qa-player-foxtrot", nickname: "Foxtrot", team_id: 1, rank: 6, round_points: 0, map_points: 0, match_points: 0}
    ]
  },
  scores_result: {result_state: "team_win", winning_team_id: 0},
  player_counters: $pc
}')
ENV=$(build_envelope "combat" "Shootmania.Scores" "shootmania_scores_match" "$SEQ" "$TS" "$SCORES_ENDMATCH")
RESP=$(send_event "$ENV")
assert_contains "scores EndMatch accepted" '"accepted"' "$RESP"

log_info "All events injected. Starting endpoint validation."

# ===========================================================================
# PHASE 9 — P0 Endpoint Validation
# ===========================================================================
log_section "Phase 9 — P0 Endpoints (link)"

BASE="${API}/servers/${SERVER_LOGIN}"

# auth-state
RESP=$(do_get "${BASE}/link/auth-state")
assert_status 200 "200" "$(get_http_code)"
assert_jq "auth-state: server_login" "${SERVER_LOGIN}" "$RESP" ".server_login"
assert_jq "auth-state: linked=true" "true" "$RESP" ".linked"

# access
RESP=$(do_get "${BASE}/link/access")
assert_status 200 "200" "$(get_http_code)"
assert_jq_not_null "link-access: server_login" "$RESP" ".server_login"

# servers list
RESP=$(do_get "${API}/servers")
assert_status 200 "200" "$(get_http_code)"
SERVER_IN_LIST=$(echo "$RESP" | jq -r --arg sl "$SERVER_LOGIN" '[.[] | select(.server_login == $sl)] | length')
assert_eq "GET /servers: qa-integration-server in list" "1" "$SERVER_IN_LIST"

# ===========================================================================
# PHASE 10 — P1 Endpoint Validation (status + health + capabilities)
# ===========================================================================
log_section "Phase 10 — P1 Endpoints (status/health/capabilities)"

# status
RESP=$(do_get "${BASE}/status")
assert_status 200 "200" "$(get_http_code)"
assert_jq "status: server_login" "${SERVER_LOGIN}" "$RESP" ".server_login"
assert_jq "status: linked=true" "true" "$RESP" ".linked"
assert_jq "status: game_mode=Elite" "Elite" "$RESP" ".game_mode"
assert_jq "status: player_counts.active=6" "6" "$RESP" ".player_counts.active"
assert_jq_gt "status: event_counts.total > 0" "0" "$RESP" ".event_counts.total"
assert_jq_gte "status: event_counts.by_category.connectivity >= 2" "2" "$RESP" ".event_counts.by_category.connectivity"
assert_jq_gt "status: event_counts.by_category.lifecycle > 0" "0" "$RESP" ".event_counts.by_category.lifecycle"
assert_jq_gt "status: event_counts.by_category.combat > 0" "0" "$RESP" ".event_counts.by_category.combat"
assert_jq "status: event_counts.by_category.player=6" "6" "$RESP" ".event_counts.by_category.player"
assert_jq_gt "status: event_counts.by_category.mode > 0" "0" "$RESP" ".event_counts.by_category.mode"

# health
RESP=$(do_get "${BASE}/status/health")
assert_status 200 "200" "$(get_http_code)"
assert_jq "health: server_login" "${SERVER_LOGIN}" "$RESP" ".server_login"
assert_jq "health: plugin_health.queue.depth=0" "0" "$RESP" ".plugin_health.queue.depth"
assert_jq "health: plugin_health.outage.active=false" "false" "$RESP" ".plugin_health.outage.active"
assert_jq_gte "health: connectivity_metrics.total_connectivity_events >= 2" "2" "$RESP" ".connectivity_metrics.total_connectivity_events"
assert_jq_gte "health: connectivity_metrics.registration_count >= 1" "1" "$RESP" ".connectivity_metrics.registration_count"
assert_jq_gte "health: connectivity_metrics.heartbeat_count >= 1" "1" "$RESP" ".connectivity_metrics.heartbeat_count"

# capabilities
RESP=$(do_get "${BASE}/status/capabilities")
assert_status 200 "200" "$(get_http_code)"
assert_jq "capabilities: server_login" "${SERVER_LOGIN}" "$RESP" ".server_login"
assert_jq "capabilities: source=plugin_registration" "plugin_registration" "$RESP" ".source"
assert_jq "capabilities: event_envelope=true" "true" "$RESP" ".capabilities.event_envelope"
assert_jq "capabilities: schema_version=2026-02-20.1" "2026-02-20.1" "$RESP" ".capabilities.schema_version"

# ===========================================================================
# PHASE 11 — Players Endpoints (P2.1, P2.2)
# ===========================================================================
log_section "Phase 11 — Players Endpoints"

# GET /players
RESP=$(do_get "${BASE}/players")
assert_status 200 "200" "$(get_http_code)"
PLAYER_COUNT=$(echo "$RESP" | jq '.data | length')
assert_eq "players: 6 players returned" "6" "$PLAYER_COUNT"

# Verify all 6 logins present
for login in qa-player-alpha qa-player-bravo qa-player-charlie qa-player-delta qa-player-echo qa-player-foxtrot; do
  CNT=$(echo "$RESP" | jq --arg l "$login" '[.data[] | select(.login == $l)] | length')
  assert_eq "players: $login in list" "1" "$CNT"
done

# Verify alpha details
ALPHA_IN_LIST=$(echo "$RESP" | jq -r '[.data[] | select(.login == "qa-player-alpha")][0]')
assert_jq "players list: alpha.nickname=Alpha" "Alpha" "$ALPHA_IN_LIST" ".nickname"
assert_jq "players list: alpha.team_id=0" "0" "$ALPHA_IN_LIST" ".team_id"
assert_jq "players list: alpha.is_connected=true" "true" "$ALPHA_IN_LIST" ".is_connected"
assert_jq "players list: alpha.is_spectator=false" "false" "$ALPHA_IN_LIST" ".is_spectator"
assert_jq "players list: alpha.has_joined_game=true" "true" "$ALPHA_IN_LIST" ".has_joined_game"

# pagination limit=3
RESP=$(do_get "${BASE}/players?limit=3&offset=0")
assert_status 200 "200" "$(get_http_code)"
assert_jq "players pagination: limit=3, count=3" "3" "$RESP" ".data | length"
assert_jq "players pagination: total=6" "6" "$RESP" ".pagination.total"
assert_jq "players pagination: limit=3" "3" "$RESP" ".pagination.limit"

# GET /players/qa-player-alpha
RESP=$(do_get "${BASE}/players/qa-player-alpha")
assert_status 200 "200" "$(get_http_code)"
assert_jq "player detail: login=qa-player-alpha" "qa-player-alpha" "$RESP" ".login"
assert_jq "player detail: nickname=Alpha" "Alpha" "$RESP" ".nickname"
assert_jq "player detail: team_id=0" "0" "$RESP" ".team_id"
assert_jq "player detail: is_connected=true" "true" "$RESP" ".is_connected"

# GET /players/nonexistent — 404
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE}/players/nonexistent-player")
assert_status "players 404 for unknown" "404" "$HTTP_CODE"

# ===========================================================================
# PHASE 12 — Combat Stats Endpoints (P2.3, P2.4, P2.5)
# ===========================================================================
log_section "Phase 12 — Combat Stats Endpoints"

# GET /stats/combat
RESP=$(do_get "${BASE}/stats/combat")
assert_status 200 "200" "$(get_http_code)"
assert_jq_gt "stats/combat: total_events > 0" "0" "$RESP" ".combat_summary.total_events"
assert_jq "stats/combat: tracked_player_count=6" "6" "$RESP" ".combat_summary.tracked_player_count"
assert_jq_gt "stats/combat: total_kills > 0" "0" "$RESP" ".combat_summary.total_kills"

# Check event_kinds keys present
KINDS=$(echo "$RESP" | jq -r '.combat_summary.event_kinds | keys[]' 2>/dev/null | tr '\n' ',' )
assert_contains "stats/combat: event_kinds has onarmorempty" "shootmania_event_onarmorempty" "$KINDS"
assert_contains "stats/combat: event_kinds has scores" "scores" "$KINDS"

# GET /stats/combat/players
RESP=$(do_get "${BASE}/stats/combat/players")
assert_status 200 "200" "$(get_http_code)"
PLAYERS_COUNT=$(echo "$RESP" | jq '.data | length')
assert_eq "stats/combat/players: 6 players" "6" "$PLAYERS_COUNT"

# Verify alpha has all fields
ALPHA_STATS=$(echo "$RESP" | jq -r '[.data[] | select(.login == "qa-player-alpha")][0]')
assert_jq_not_null "combat/players: alpha.kills not null" "$ALPHA_STATS" ".kills"
assert_jq_not_null "combat/players: alpha.deaths not null" "$ALPHA_STATS" ".deaths"
assert_jq_not_null "combat/players: alpha.accuracy not null" "$ALPHA_STATS" ".accuracy"
assert_jq_not_null "combat/players: alpha.kd_ratio not null" "$ALPHA_STATS" ".kd_ratio"
assert_jq_not_null "combat/players: alpha.hits_rocket not null" "$ALPHA_STATS" ".hits_rocket"
assert_jq_not_null "combat/players: alpha.hits_laser not null" "$ALPHA_STATS" ".hits_laser"
assert_jq_not_null "combat/players: alpha.rocket_accuracy not null" "$ALPHA_STATS" ".rocket_accuracy"
assert_jq_not_null "combat/players: alpha.laser_accuracy not null" "$ALPHA_STATS" ".laser_accuracy"
assert_jq_not_null "combat/players: alpha.attack_rounds_played not null" "$ALPHA_STATS" ".attack_rounds_played"
assert_jq_not_null "combat/players: alpha.defense_rounds_played not null" "$ALPHA_STATS" ".defense_rounds_played"

# GET /stats/combat/players/qa-player-alpha (detail — uses last map3 event player_counters)
# After map3: alpha has kills=6,deaths=2,hits=9,shots=22,rockets=16,lasers=6,hits_rocket=6,hits_laser=3
# kd_ratio = 6/2 = 3.0
# accuracy = 9/22 = 0.4091
# rocket_accuracy = 6/16 = 0.375
# laser_accuracy = 3/6 = 0.5
# attack_rounds_played=2, attack_rounds_won=2, attack_win_rate=2/2=1.0
# defense_rounds_played=3, defense_rounds_won=2, defense_win_rate=2/3=0.6667
RESP=$(do_get "${BASE}/stats/combat/players/qa-player-alpha")
assert_status 200 "200" "$(get_http_code)"
assert_jq "combat/players/alpha: login" "qa-player-alpha" "$RESP" ".login"
assert_jq "combat/players/alpha: kills=6" "6" "$RESP" ".counters.kills"
assert_jq "combat/players/alpha: deaths=2" "2" "$RESP" ".counters.deaths"
assert_jq "combat/players/alpha: kd_ratio=3 (6/2)" "3" "$RESP" ".counters.kd_ratio"
assert_jq "combat/players/alpha: shots=22" "22" "$RESP" ".counters.shots"
assert_jq "combat/players/alpha: hits=9" "9" "$RESP" ".counters.hits"
assert_jq "combat/players/alpha: rockets=16" "16" "$RESP" ".counters.rockets"
assert_jq "combat/players/alpha: hits_rocket=6" "6" "$RESP" ".counters.hits_rocket"
assert_jq "combat/players/alpha: rocket_accuracy=0.375" "0.375" "$RESP" ".counters.rocket_accuracy"
assert_jq "combat/players/alpha: lasers=6" "6" "$RESP" ".counters.lasers"
assert_jq "combat/players/alpha: hits_laser=3" "3" "$RESP" ".counters.hits_laser"
assert_jq "combat/players/alpha: laser_accuracy=0.5" "0.5" "$RESP" ".counters.laser_accuracy"
assert_jq "combat/players/alpha: attack_rounds_played=2" "2" "$RESP" ".counters.attack_rounds_played"
assert_jq "combat/players/alpha: attack_rounds_won=2" "2" "$RESP" ".counters.attack_rounds_won"
assert_jq "combat/players/alpha: attack_win_rate=1.0" "1" "$RESP" ".counters.attack_win_rate"
assert_jq "combat/players/alpha: defense_rounds_played=3" "3" "$RESP" ".counters.defense_rounds_played"
assert_jq "combat/players/alpha: defense_rounds_won=2" "2" "$RESP" ".counters.defense_rounds_won"
assert_jq "combat/players/alpha: defense_win_rate=0.6667" "0.6667" "$RESP" ".counters.defense_win_rate"

# 404 for unknown player
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE}/stats/combat/players/nonexistent-player")
assert_status "stats/combat/players 404 for unknown" "404" "$HTTP_CODE"

# ===========================================================================
# PHASE 13 — Scores Endpoint (P2.6)
# ===========================================================================
log_section "Phase 13 — Scores Endpoint"

RESP=$(do_get "${BASE}/stats/scores")
assert_status 200 "200" "$(get_http_code)"
assert_jq "scores: scores_section=EndMatch" "EndMatch" "$RESP" ".scores_section"
assert_jq "scores: use_teams=true" "true" "$RESP" ".scores_snapshot.use_teams"
TEAM_SCORES_COUNT=$(echo "$RESP" | jq '.scores_snapshot.team_scores | length')
assert_eq "scores: 2 team_scores" "2" "$TEAM_SCORES_COUNT"
PLAYER_SCORES_COUNT=$(echo "$RESP" | jq '.scores_snapshot.player_scores | length')
assert_eq "scores: 6 player_scores" "6" "$PLAYER_SCORES_COUNT"
assert_jq "scores: result_state=team_win" "team_win" "$RESP" ".scores_result.result_state"
assert_jq "scores: winner_team_id=0" "0" "$RESP" ".scores_snapshot.winner_team_id"

# ===========================================================================
# PHASE 14 — Lifecycle Endpoints (P2.7, P2.8, P2.9)
# ===========================================================================
log_section "Phase 14 — Lifecycle Endpoints"

# GET /lifecycle
RESP=$(do_get "${BASE}/lifecycle")
assert_status 200 "200" "$(get_http_code)"
assert_jq "lifecycle: server_login" "${SERVER_LOGIN}" "$RESP" ".server_login"
assert_jq "lifecycle: current_phase=match" "match" "$RESP" ".current_phase"
assert_jq_not_null "lifecycle: match state not null" "$RESP" ".match"
assert_jq_not_null "lifecycle: map state not null" "$RESP" ".map"
assert_jq_not_null "lifecycle: round state not null" "$RESP" ".round"

# GET /lifecycle/map-rotation
RESP=$(do_get "${BASE}/lifecycle/map-rotation")
assert_status 200 "200" "$(get_http_code)"
assert_jq "map-rotation: map_pool_size=3" "3" "$RESP" ".map_pool_size"
MAP_POOL_LEN=$(echo "$RESP" | jq '.map_pool | length')
assert_eq "map-rotation: map_pool has 3 maps" "3" "$MAP_POOL_LEN"
assert_jq "map-rotation: current_map=qa-map-colosseum" "qa-map-colosseum" "$RESP" ".current_map.uid"
assert_jq "map-rotation: series_targets.best_of=3" "3" "$RESP" ".series_targets.best_of"

# GET /lifecycle/aggregate-stats
RESP=$(do_get "${BASE}/lifecycle/aggregate-stats")
assert_status 200 "200" "$(get_http_code)"
assert_jq_not_null "aggregate-stats: aggregates not null" "$RESP" ".aggregates"
AGG_LEN=$(echo "$RESP" | jq '.aggregates | length')
assert_gte "aggregate-stats: aggregates has >= 1 entry" "1" "$AGG_LEN"
# Should have map scope
HAS_MAP=$(echo "$RESP" | jq '[.aggregates[] | select(.scope == "map")] | length')
assert_gte "aggregate-stats: has map scope entry" "1" "$HAS_MAP"
# Map scope should have 6 players in player_counters_delta
MAP_AGG=$(echo "$RESP" | jq '[.aggregates[] | select(.scope == "map")][0]')
MAP_PLAYER_COUNT=$(echo "$MAP_AGG" | jq '.player_counters_delta | length')
assert_eq "aggregate-stats map: 6 players in delta" "6" "$MAP_PLAYER_COUNT"

# ===========================================================================
# PHASE 15 — Maps Endpoint (P2.11)
# ===========================================================================
log_section "Phase 15 — Maps Endpoint"

RESP=$(do_get "${BASE}/maps")
assert_status 200 "200" "$(get_http_code)"
assert_jq "maps: map_count=3" "3" "$RESP" ".map_count"
MAPS_LEN=$(echo "$RESP" | jq '.maps | length')
assert_eq "maps: maps array has 3 entries" "3" "$MAPS_LEN"
assert_jq_not_null "maps: current_map not null" "$RESP" ".current_map"

# ===========================================================================
# PHASE 16 — Mode Endpoint (P2.12)
# ===========================================================================
log_section "Phase 16 — Mode Endpoint"

RESP=$(do_get "${BASE}/mode")
assert_status 200 "200" "$(get_http_code)"
assert_jq "mode: game_mode=Elite" "Elite" "$RESP" ".game_mode"
assert_jq "mode: title_id=SMStormElite@nadeolabs" "SMStormElite@nadeolabs" "$RESP" ".title_id"
assert_jq_gt "mode: total_mode_events > 0" "0" "$RESP" ".total_mode_events"
MODE_EVENTS_LEN=$(echo "$RESP" | jq '.recent_mode_events | length')
assert_gt "mode: recent_mode_events > 0" "0" "$MODE_EVENTS_LEN"

# ===========================================================================
# PHASE 17 — Per-Map Combat Stats (P2.5.1, P2.5.2, P2.5.3)
# ===========================================================================
log_section "Phase 17 — Per-Map Combat Stats"

# GET /stats/combat/maps
RESP=$(do_get "${BASE}/stats/combat/maps")
assert_status 200 "200" "$(get_http_code)"
assert_jq "stats/combat/maps: pagination.total=3" "3" "$RESP" ".pagination.total"
MAPS_STATS_LEN=$(echo "$RESP" | jq '.maps | length')
assert_eq "stats/combat/maps: 3 map entries" "3" "$MAPS_STATS_LEN"

# Most recent first — colosseum should be [0], then zenith [1], then oasis [2]
assert_jq "stats/combat/maps[0]: colosseum (most recent)" "qa-map-colosseum" "$RESP" ".maps[0].map_uid"
assert_jq "stats/combat/maps[1]: zenith" "qa-map-zenith" "$RESP" ".maps[1].map_uid"
assert_jq "stats/combat/maps[2]: oasis (oldest)" "qa-map-oasis" "$RESP" ".maps[2].map_uid"

# pagination limit=2
RESP=$(do_get "${BASE}/stats/combat/maps?limit=2&offset=0")
assert_status 200 "200" "$(get_http_code)"
assert_jq "stats/combat/maps?limit=2: count=2" "2" "$RESP" ".maps | length"
assert_jq "stats/combat/maps?limit=2: total=3" "3" "$RESP" ".pagination.total"

# pagination limit=1&offset=2 → oldest = oasis
RESP=$(do_get "${BASE}/stats/combat/maps?limit=1&offset=2")
assert_status 200 "200" "$(get_http_code)"
assert_jq "stats/combat/maps?limit=1&offset=2: oasis" "qa-map-oasis" "$RESP" ".maps[0].map_uid"

# GET /stats/combat/maps/qa-map-oasis
# Map 1: alpha: kills=5, deaths=2, kd_ratio=2.5, attack_win_rate=2/2=1.0, defense_win_rate=1/3=0.3333
# accuracy=8/20=0.4, rocket_accuracy=5/15=0.3333, laser_accuracy=3/5=0.6
RESP=$(do_get "${BASE}/stats/combat/maps/qa-map-oasis")
assert_status 200 "200" "$(get_http_code)"
assert_jq "stats/combat/maps/oasis: map_uid" "qa-map-oasis" "$RESP" ".map_uid"
assert_jq "stats/combat/maps/oasis: map_name=Oasis Elite" "Oasis Elite" "$RESP" ".map_name"
assert_jq "stats/combat/maps/oasis: win_context.winner_team_id=0" "0" "$RESP" ".win_context.winner_team_id"
# Verify alpha's per-map stats (from player_counters_delta on map.end)
OASIS_ALPHA=$(echo "$RESP" | jq '.player_stats["qa-player-alpha"]')
assert_jq "oasis alpha: kills=5" "5" "$OASIS_ALPHA" ".kills"
assert_jq "oasis alpha: deaths=2" "2" "$OASIS_ALPHA" ".deaths"
assert_jq "oasis alpha: kd_ratio=2.5" "2.5" "$OASIS_ALPHA" ".kd_ratio"
assert_jq "oasis alpha: shots=20" "20" "$OASIS_ALPHA" ".shots"
assert_jq "oasis alpha: hits=8" "8" "$OASIS_ALPHA" ".hits"
assert_jq "oasis alpha: rockets=15" "15" "$OASIS_ALPHA" ".rockets"
assert_jq "oasis alpha: hits_rocket=5" "5" "$OASIS_ALPHA" ".hits_rocket"
assert_jq "oasis alpha: lasers=5" "5" "$OASIS_ALPHA" ".lasers"
assert_jq "oasis alpha: hits_laser=3" "3" "$OASIS_ALPHA" ".hits_laser"
assert_jq "oasis alpha: attack_rounds_played=2" "2" "$OASIS_ALPHA" ".attack_rounds_played"
assert_jq "oasis alpha: attack_rounds_won=2" "2" "$OASIS_ALPHA" ".attack_rounds_won"
assert_jq "oasis alpha: attack_win_rate=1" "1" "$OASIS_ALPHA" ".attack_win_rate"
assert_jq "oasis alpha: defense_rounds_played=3" "3" "$OASIS_ALPHA" ".defense_rounds_played"
assert_jq "oasis alpha: defense_rounds_won=1" "1" "$OASIS_ALPHA" ".defense_rounds_won"
assert_jq "oasis alpha: defense_win_rate=0.3333" "0.3333" "$OASIS_ALPHA" ".defense_win_rate"

# Also check oasis accuracy fields
assert_jq "oasis alpha: accuracy=0.4" "0.4" "$OASIS_ALPHA" ".accuracy"
assert_jq "oasis alpha: rocket_accuracy=0.3333" "0.3333" "$OASIS_ALPHA" ".rocket_accuracy"
assert_jq "oasis alpha: laser_accuracy=0.6" "0.6" "$OASIS_ALPHA" ".laser_accuracy"

# Verify team_stats present
TEAM_STATS_LEN=$(echo "$RESP" | jq '.team_stats | length')
assert_eq "oasis: team_stats has 2 entries" "2" "$TEAM_STATS_LEN"

# GET /stats/combat/maps/qa-map-zenith — Team 1 wins
RESP=$(do_get "${BASE}/stats/combat/maps/qa-map-zenith")
assert_status 200 "200" "$(get_http_code)"
assert_jq "stats/combat/maps/zenith: winner_team_id=1" "1" "$RESP" ".win_context.winner_team_id"
assert_jq "stats/combat/maps/zenith: map_name=Zenith Storm" "Zenith Storm" "$RESP" ".map_name"

# GET /stats/combat/maps/qa-map-oasis/players/qa-player-alpha
RESP=$(do_get "${BASE}/stats/combat/maps/qa-map-oasis/players/qa-player-alpha")
assert_status 200 "200" "$(get_http_code)"
assert_jq "map-player detail: player_login=alpha" "qa-player-alpha" "$RESP" ".player_login"
assert_jq "map-player detail: map_uid=oasis" "qa-map-oasis" "$RESP" ".map_uid"
assert_jq "map-player detail: counters.kills=5" "5" "$RESP" ".counters.kills"
assert_jq "map-player detail: counters.kd_ratio=2.5" "2.5" "$RESP" ".counters.kd_ratio"
assert_jq_not_null "map-player detail: counters.hits_rocket" "$RESP" ".counters.hits_rocket"
assert_jq_not_null "map-player detail: counters.rocket_accuracy" "$RESP" ".counters.rocket_accuracy"
assert_jq_not_null "map-player detail: counters.attack_rounds_played" "$RESP" ".counters.attack_rounds_played"

# 404 for unknown map
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE}/stats/combat/maps/nonexistent-map")
assert_status "stats/combat/maps 404 for unknown map" "404" "$HTTP_CODE"

# 404 for unknown player on known map
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${BASE}/stats/combat/maps/qa-map-oasis/players/nonexistent")
assert_status "stats/combat/maps/oasis/players 404 for unknown" "404" "$HTTP_CODE"

# ===========================================================================
# PHASE 18 — Series Combat Stats (P2.5.4)
# ===========================================================================
log_section "Phase 18 — Series Combat Stats"

RESP=$(do_get "${BASE}/stats/combat/series")
assert_status 200 "200" "$(get_http_code)"
SERIES_COUNT=$(echo "$RESP" | jq '.series | length')
assert_eq "stats/combat/series: 1 series" "1" "$SERIES_COUNT"
assert_jq "series: total_maps_played=3" "3" "$RESP" ".series[0].total_maps_played"
SERIES_MAPS_LEN=$(echo "$RESP" | jq '.series[0].maps | length')
assert_eq "series: 3 maps in series" "3" "$SERIES_MAPS_LEN"
assert_jq_gt "series: series_totals.kills > 0" "0" "$RESP" ".series[0].series_totals.kills"
assert_jq_not_null "series: match_started_at" "$RESP" ".series[0].match_started_at"
assert_jq_not_null "series: match_ended_at" "$RESP" ".series[0].match_ended_at"
# Pagination
assert_jq "series: pagination.total=1" "1" "$RESP" ".pagination.total"

# ===========================================================================
# PHASE 19 — Player Combat Map History (P2.6 enhanced)
# ===========================================================================
log_section "Phase 19 — Player Combat Map History"

# GET /stats/combat/players/qa-player-alpha/maps
RESP=$(do_get "${BASE}/stats/combat/players/qa-player-alpha/maps")
assert_status 200 "200" "$(get_http_code)"
assert_jq "player history: player_login=alpha" "qa-player-alpha" "$RESP" ".player_login"
assert_jq "player history: maps_played=3" "3" "$RESP" ".maps_played"
assert_jq "player history: maps_won=2 (team0 wins map1+map3)" "2" "$RESP" ".maps_won"
assert_jq "player history: win_rate=0.6667" "0.6667" "$RESP" ".win_rate"
ALPHA_MAPS_LEN=$(echo "$RESP" | jq '.maps | length')
assert_eq "player history: 3 map entries" "3" "$ALPHA_MAPS_LEN"

# Ordered most-recent first: colosseum[0], zenith[1], oasis[2]
assert_jq "player history maps[0]: colosseum (most recent)" "qa-map-colosseum" "$RESP" ".maps[0].map_uid"
assert_jq "player history maps[2]: oasis (oldest)" "qa-map-oasis" "$RESP" ".maps[2].map_uid"

# won field per map: colosseum=true, zenith=false, oasis=true
assert_jq "player history: colosseum won=true" "true" "$RESP" ".maps[0].won"
assert_jq "player history: zenith won=false" "false" "$RESP" ".maps[1].won"
assert_jq "player history: oasis won=true" "true" "$RESP" ".maps[2].won"

# Verify oasis per-map counters in history (same as map.end player_counters_delta)
OASIS_HIST=$(echo "$RESP" | jq '[.maps[] | select(.map_uid == "qa-map-oasis")][0]')
assert_jq "player history oasis: kills=5" "5" "$OASIS_HIST" ".counters.kills"
assert_jq "player history oasis: deaths=2" "2" "$OASIS_HIST" ".counters.deaths"
assert_jq "player history oasis: kd_ratio=2.5" "2.5" "$OASIS_HIST" ".counters.kd_ratio"
assert_jq_not_null "player history oasis: kd_ratio not null" "$OASIS_HIST" ".counters.kd_ratio"
assert_jq_not_null "player history oasis: hits_rocket not null" "$OASIS_HIST" ".counters.hits_rocket"
assert_jq_not_null "player history oasis: hits_laser not null" "$OASIS_HIST" ".counters.hits_laser"
assert_jq_not_null "player history oasis: rocket_accuracy not null" "$OASIS_HIST" ".counters.rocket_accuracy"
assert_jq_not_null "player history oasis: laser_accuracy not null" "$OASIS_HIST" ".counters.laser_accuracy"
assert_jq_not_null "player history oasis: attack_rounds_played not null" "$OASIS_HIST" ".counters.attack_rounds_played"
assert_jq_not_null "player history oasis: attack_rounds_won not null" "$OASIS_HIST" ".counters.attack_rounds_won"
assert_jq_not_null "player history oasis: attack_win_rate not null" "$OASIS_HIST" ".counters.attack_win_rate"
assert_jq_not_null "player history oasis: defense_rounds_played not null" "$OASIS_HIST" ".counters.defense_rounds_played"
assert_jq_not_null "player history oasis: defense_rounds_won not null" "$OASIS_HIST" ".counters.defense_rounds_won"
assert_jq_not_null "player history oasis: defense_win_rate not null" "$OASIS_HIST" ".counters.defense_win_rate"

# pagination: limit=1
RESP=$(do_get "${BASE}/stats/combat/players/qa-player-alpha/maps?limit=1")
assert_status 200 "200" "$(get_http_code)"
assert_jq "player history?limit=1: 1 map returned" "1" "$RESP" ".maps | length"
assert_jq "player history?limit=1: total=3" "3" "$RESP" ".pagination.total"

# nonexistent player — HTTP 200 with empty maps
RESP=$(do_get "${BASE}/stats/combat/players/nonexistent-player/maps")
assert_status 200 "200" "$(get_http_code)"
assert_jq "player history nonexistent: maps_played=0" "0" "$RESP" ".maps_played"
assert_jq "player history nonexistent: maps=[]" "0" "$RESP" ".maps | length"

# ===========================================================================
# PHASE 20 — Edge Cases
# ===========================================================================
log_section "Phase 20 — Edge Cases"

# --- P5.1 Idempotency deduplication ---
log_info "Testing idempotency deduplication..."
# Re-send the same envelope that already has a stored idempotency key
DEDUP_ENV=$(build_envelope "combat" "Shootmania.Event.OnArmorEmpty" "shootmania_event_onarmorempty_dedup" "999999" "$(( RUN_TS * 1000 + 999999 ))" '{"event_kind":"shootmania_event_onarmorempty","dimensions":{}}')
# Send it first time
RESP=$(send_event "$DEDUP_ENV")
assert_contains "dedup: first send accepted" '"accepted"' "$RESP"
# Send exact same again — should be duplicate
RESP=$(send_event "$DEDUP_ENV")
assert_contains "dedup: second send returns duplicate" '"duplicate"' "$RESP"

# --- P5.2 Time-range filtering ---
log_info "Testing time-range filtering (future timestamp)..."
FUTURE_TS="2099-12-31T23:59:59Z"

# stats/combat with future since
RESP=$(do_get "${BASE}/stats/combat?since=${FUTURE_TS}")
assert_status 200 "200" "$(get_http_code)"
assert_jq "time-range: total_events=0 (future since)" "0" "$RESP" ".combat_summary.total_events"

# stats/combat/maps with future since
RESP=$(do_get "${BASE}/stats/combat/maps?since=${FUTURE_TS}")
assert_status 200 "200" "$(get_http_code)"
assert_jq "time-range: maps=[] (future since)" "0" "$RESP" ".maps | length"

# player history with future since
RESP=$(do_get "${BASE}/stats/combat/players/qa-player-alpha/maps?since=${FUTURE_TS}")
assert_status 200 "200" "$(get_http_code)"
assert_jq "time-range: player history maps_played=0 (future since)" "0" "$RESP" ".maps_played"

# --- P5.3 Unknown server 404s ---
log_info "Testing unknown server 404s..."
UNKNOWN="nonexistent-server-xyz"
for ep in status players "stats/combat" lifecycle maps mode; do
  HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${API}/servers/${UNKNOWN}/${ep}")
  assert_status "unknown server 404: $ep" "404" "$HTTP_CODE"
done

# --- P5.4 Malformed input ---
log_info "Testing malformed input rejection..."

# Missing X-Pixel-Server-Login header
HTTP_CODE=$(curl -s -o "$_BODY" -w "%{http_code}" \
  -X POST "${API}/plugin/events" \
  -H "Content-Type: application/json" \
  -H "X-Pixel-Plugin-Version: 2.0.0" \
  -d '{"event_id":"test","event_name":"test","event_category":"combat","idempotency_key":"test-missing-header","source_callback":"test","source_sequence":1,"source_time":1,"schema_version":"2026-02-20.1","payload":{}}')
RESP=$(cat "$_BODY")
# Should be 4xx or contain error/rejected
BODY_TEXT=$(cat "$_BODY")
assert_contains "malformed: missing X-Pixel-Server-Login rejected" "missing_server_login" "$BODY_TEXT"

# ===========================================================================
# PHASE 21 — Final Cleanup Validation
# ===========================================================================
log_section "Phase 21 — Cleanup Validation"

# DELETE server
log_info "Deleting test server..."
HTTP_CODE=$(curl -s -o "$_BODY" -w "%{http_code}" \
  -X DELETE "${API}/servers/${SERVER_LOGIN}")
RESP=$(cat "$_BODY")
assert_status "DELETE server: 200" "200" "$HTTP_CODE"
assert_contains "DELETE server: response contains deleted" '"deleted"' "$RESP"

# After delete, status should 404 (cascade)
# Disable trap temporarily to avoid double-cleanup
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${API}/servers/${SERVER_LOGIN}/status")
assert_status "cascade delete: status returns 404" "404" "$HTTP_CODE"

# Disable cleanup trap so it doesn't try to double-delete
trap - EXIT

# ===========================================================================
# FINAL SUMMARY
# ===========================================================================
log_section "Final Results"

echo ""
echo -e "${BOLD}Total assertions:${NC} $TOTAL"
echo -e "${GREEN}${BOLD}Passed:${NC} ${GREEN}${PASS}${NC}"
if [ "$FAIL" -gt 0 ]; then
  echo -e "${RED}${BOLD}Failed:${NC} ${RED}${FAIL}${NC}"
else
  echo -e "${RED}${BOLD}Failed:${NC} ${GREEN}0${NC}"
fi
echo ""

# Final cleanup
rm -f "$_SEQ_FILE" "$_BODY" "$_CODE" 2>/dev/null || true

if [ "$FAIL" -gt 0 ]; then
  echo -e "${RED}${BOLD}QA INTEGRATION TESTS FAILED ($FAIL failures)${NC}"
  exit 1
else
  echo -e "${GREEN}${BOLD}All $TOTAL assertions passed. QA integration suite complete.${NC}"
  exit 0
fi
