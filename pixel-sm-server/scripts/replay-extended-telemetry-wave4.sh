#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${PROJECT_DIR}/.env"
DEV_SYNC_SCRIPT="${PROJECT_DIR}/scripts/dev-plugin-sync.sh"

QA_COMPOSE_FILES="${PIXEL_SM_QA_COMPOSE_FILES:-docker-compose.yml}"
QA_ARTIFACT_DIR="${PIXEL_SM_QA_ARTIFACT_DIR:-${PROJECT_DIR}/logs/qa}"
QA_TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
ARTIFACT_PREFIX="wave4-telemetry-${QA_TIMESTAMP}"

QA_XMLRPC_PORT="${PIXEL_SM_QA_XMLRPC_PORT:-59000}"
QA_GAME_PORT="${PIXEL_SM_QA_GAME_PORT:-59100}"
QA_P2P_PORT="${PIXEL_SM_QA_P2P_PORT:-59200}"

STUB_BIND_HOST="${PIXEL_SM_QA_TELEMETRY_STUB_BIND_HOST:-127.0.0.1}"
STUB_API_HOST="${PIXEL_SM_QA_TELEMETRY_API_HOST:-host.docker.internal}"
STUB_API_PORT="${PIXEL_SM_QA_TELEMETRY_API_PORT:-18080}"
STUB_BASE_URL="${PIXEL_SM_QA_TELEMETRY_API_BASE_URL:-http://${STUB_API_HOST}:${STUB_API_PORT}}"
STUB_LOCAL_HOST="${PIXEL_SM_QA_TELEMETRY_STUB_LOCAL_HOST:-127.0.0.1}"
STUB_LOCAL_URL="${PIXEL_SM_QA_TELEMETRY_STUB_LOCAL_URL:-http://${STUB_LOCAL_HOST}:${STUB_API_PORT}}"
POST_ACTION_WAIT_SECONDS="${PIXEL_SM_QA_TELEMETRY_WAIT_SECONDS:-10}"
KEEP_STACK_RUNNING="${PIXEL_SM_QA_TELEMETRY_KEEP_STACK_RUNNING:-0}"
INJECT_FIXTURES="${PIXEL_SM_QA_TELEMETRY_INJECT_FIXTURES:-1}"
MARKER_PROFILE="${PIXEL_SM_QA_TELEMETRY_MARKER_PROFILE:-}"

if [ -z "$MARKER_PROFILE" ]; then
  if [ "$INJECT_FIXTURES" = "1" ]; then
    MARKER_PROFILE="strict"
  else
    MARKER_PROFILE="plugin_only"
  fi
fi

CAPTURE_FILE="${QA_ARTIFACT_DIR}/${ARTIFACT_PREFIX}-capture.ndjson"
STUB_LOG_FILE="${QA_ARTIFACT_DIR}/${ARTIFACT_PREFIX}-stub.log"
DEV_SYNC_LOG_FILE="${QA_ARTIFACT_DIR}/${ARTIFACT_PREFIX}-dev-sync.log"
DEDICATED_ACTION_LOG_FILE="${QA_ARTIFACT_DIR}/${ARTIFACT_PREFIX}-dedicated-actions.log"
SHOOTMANIA_LOG_FILE="${QA_ARTIFACT_DIR}/${ARTIFACT_PREFIX}-shootmania.log"
MANIACONTROL_LOG_FILE="${QA_ARTIFACT_DIR}/${ARTIFACT_PREFIX}-maniacontrol.log"
MARKERS_FILE="${QA_ARTIFACT_DIR}/${ARTIFACT_PREFIX}-markers.json"
SUMMARY_FILE="${QA_ARTIFACT_DIR}/${ARTIFACT_PREFIX}-summary.md"
FIXTURE_ENVELOPES_FILE="${QA_ARTIFACT_DIR}/${ARTIFACT_PREFIX}-fixtures.ndjson"

compose_file_args=()
stub_pid=""
stack_started=0

log() {
  printf '[replay-extended-telemetry-wave4] %s\n' "$1"
}

require_command() {
  command_name="$1"
  if ! command -v "$command_name" >/dev/null 2>&1; then
    log "Missing command: ${command_name}"
    exit 1
  fi
}

resolve_path() {
  candidate_path="$1"
  case "$candidate_path" in
    /*)
      printf '%s' "$candidate_path"
      ;;
    *)
      printf '%s' "${PROJECT_DIR}/${candidate_path}"
      ;;
  esac
}

trim_whitespace() {
  input="$1"
  input="${input#"${input%%[![:space:]]*}"}"
  input="${input%"${input##*[![:space:]]}"}"
  printf '%s' "$input"
}

build_compose_file_args() {
  IFS=',' read -r -a requested_compose_files <<< "$QA_COMPOSE_FILES"

  for compose_file in "${requested_compose_files[@]}"; do
    compose_file="$(trim_whitespace "$compose_file")"
    if [ -z "$compose_file" ]; then
      continue
    fi

    resolved_compose_file="$(resolve_path "$compose_file")"
    if [ ! -f "$resolved_compose_file" ]; then
      log "Compose file not found: ${resolved_compose_file}"
      exit 1
    fi

    compose_file_args+=("-f" "$resolved_compose_file")
  done

  if [ "${#compose_file_args[@]}" -eq 0 ]; then
    log "No compose files resolved from PIXEL_SM_QA_COMPOSE_FILES='${QA_COMPOSE_FILES}'"
    exit 1
  fi
}

compose() {
  env \
    PIXEL_CONTROL_API_BASE_URL="$STUB_BASE_URL" \
    PIXEL_SM_XMLRPC_PORT="$QA_XMLRPC_PORT" \
    PIXEL_SM_GAME_PORT="$QA_GAME_PORT" \
    PIXEL_SM_P2P_PORT="$QA_P2P_PORT" \
    docker compose --ansi never "${compose_file_args[@]}" --env-file "$ENV_FILE" "$@"
}

read_env_value() {
  env_file_path="$1"
  target_key="$2"
  resolved_value=""

  while IFS= read -r line; do
    case "$line" in
      "${target_key}="*)
        resolved_value="${line#*=}"
        ;;
    esac
  done < "$env_file_path"

  printf '%s' "$resolved_value"
}

wait_for_stub() {
  attempts=0
  max_attempts=30

  while [ "$attempts" -lt "$max_attempts" ]; do
    if curl -sS "${STUB_LOCAL_URL}/healthz" >/dev/null 2>&1; then
      return 0
    fi

    attempts=$((attempts + 1))
    sleep 1
  done

  return 1
}

post_stub_payload() {
  payload="$1"

  curl -sS -X POST "${STUB_LOCAL_URL}/plugin/events" \
    -H "Content-Type: application/json" \
    --data "$payload" \
    >/dev/null
}

inject_wave4_fixture_envelopes() {
  if [ "$INJECT_FIXTURES" != "1" ]; then
    log "Skipping fixture envelope injection (PIXEL_SM_QA_TELEMETRY_INJECT_FIXTURES=${INJECT_FIXTURES})"
    return
  fi

  fixture_epoch="$(date +%s)"
  player_sequence="$((fixture_epoch + 1))"
  lifecycle_sequence="$((fixture_epoch + 2))"

  player_fixture_payload=$(cat <<EOF
{"envelope":{"event_name":"pixel_control.player.wave4_fixture","schema_version":"2026-02-20.1","event_id":"pc-evt-player-wave4-fixture-${QA_TIMESTAMP}","event_category":"player","source_callback":"qa.fixture.player","source_sequence":${player_sequence},"source_time":${player_sequence},"idempotency_key":"pc-idem-wave4-player-fixture-${QA_TIMESTAMP}","payload":{"event_kind":"player.info_changed","transition_kind":"state_change","reconnect_continuity":{"identity_key":"player_login:wave4fixture","player_login":"wave4fixture","transition_state":"reconnect","continuity_state":"resumed","session_id":"pc-session-wave4fixture-2","session_ordinal":2,"ordering":{"global_transition_sequence":${player_sequence},"player_transition_sequence":4}},"side_change":{"detected":true,"transition_kind":"side_change","player_login":"wave4fixture","previous_team_id":0,"current_team_id":1,"previous_side":"team_0","current_side":"team_1","dedupe_key":"pc-side-fixture","ordering":{"global_transition_sequence":${player_sequence}}}},"metadata":{"plugin_version":"0.1.0-dev","schema_version":"2026-02-20.1","mode_family":"multi-mode","signal_kind":"fixture"}},"transport":{"attempt":1,"max_attempts":1,"retry_backoff_ms":0,"auth_mode":"none"}}
EOF
)

  lifecycle_fixture_payload=$(cat <<EOF
{"envelope":{"event_name":"pixel_control.lifecycle.wave4_fixture","schema_version":"2026-02-20.1","event_id":"pc-evt-lifecycle-wave4-fixture-${QA_TIMESTAMP}","event_category":"lifecycle","source_callback":"qa.fixture.lifecycle","source_sequence":${lifecycle_sequence},"source_time":${lifecycle_sequence},"idempotency_key":"pc-idem-wave4-lifecycle-fixture-${QA_TIMESTAMP}","payload":{"variant":"map.end","phase":"map","state":"end","aggregate_stats":{"scope":"map","counter_scope":"combat_delta","team_counters_delta":[{"team_id":0,"team_side":"team_0","team_key":"0","player_logins":["wave4fixture"],"player_count":1,"totals":{"kills":1,"deaths":0,"hits":2,"shots":3,"misses":1,"rockets":1,"lasers":2,"accuracy":0.6667}}],"team_summary":{"team_count":1,"assignment_source_counts":{"player_manager":1,"scores_snapshot":0,"unknown":0}},"win_context":{"result_state":"team_win","winning_side":"team_0","winning_reason":"winner_team_id","fallback_applied":false}},"map_rotation":{"map_pool_size":2,"veto_draft_actions":{"available":true,"status":"partial","action_count":1,"actions":[{"order_index":1,"action_kind":"lock","action_status":"inferred"}]},"veto_result":{"available":true,"status":"partial","reason":"final_selection_inferred_from_partial_veto_actions"}}},"metadata":{"plugin_version":"0.1.0-dev","schema_version":"2026-02-20.1","mode_family":"multi-mode","signal_kind":"fixture"}},"transport":{"attempt":1,"max_attempts":1,"retry_backoff_ms":0,"auth_mode":"none"}}
EOF
)

  : > "$FIXTURE_ENVELOPES_FILE"
  printf '%s\n' "$player_fixture_payload" >> "$FIXTURE_ENVELOPES_FILE"
  printf '%s\n' "$lifecycle_fixture_payload" >> "$FIXTURE_ENVELOPES_FILE"

  post_stub_payload "$player_fixture_payload"
  post_stub_payload "$lifecycle_fixture_payload"

  log "Injected deterministic wave-4 fixture envelopes for marker validation"
}

cleanup() {
  if [ -n "$stub_pid" ] && kill -0 "$stub_pid" >/dev/null 2>&1; then
    log "Stopping local ACK stub"
    kill "$stub_pid" >/dev/null 2>&1 || true
    wait "$stub_pid" >/dev/null 2>&1 || true
  fi

  if [ "$stack_started" = "1" ] && [ "$KEEP_STACK_RUNNING" != "1" ]; then
    log "Stopping replay stack"
    compose down >/dev/null 2>&1 || true
  fi
}

trap cleanup EXIT

require_command docker
require_command php
require_command python3
require_command curl

if [ ! -f "$ENV_FILE" ]; then
  log "Missing .env file at ${ENV_FILE}. Create it from .env.example before running wave-4 telemetry replay."
  exit 1
fi

if [ ! -x "$DEV_SYNC_SCRIPT" ]; then
  log "Missing helper script: ${DEV_SYNC_SCRIPT}"
  exit 1
fi

mkdir -p "$QA_ARTIFACT_DIR"
build_compose_file_args
touch "$CAPTURE_FILE"

log "Starting local ACK stub on ${STUB_BIND_HOST}:${STUB_API_PORT}"
python3 -u - "$STUB_BIND_HOST" "$STUB_API_PORT" "$CAPTURE_FILE" <<'PY' > "$STUB_LOG_FILE" 2>&1 &
import json
import sys
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

bind_host = sys.argv[1]
bind_port = int(sys.argv[2])
capture_file = sys.argv[3]


class Handler(BaseHTTPRequestHandler):
    def _write_json(self, status_code, payload):
        encoded = json.dumps(payload).encode("utf-8")
        self.send_response(status_code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)

    def do_GET(self):
        if self.path == "/healthz":
            self._write_json(200, {"ok": True})
            return
        self._write_json(404, {"error": "not_found"})

    def do_POST(self):
        content_length = int(self.headers.get("Content-Length", "0"))
        raw_body = self.rfile.read(content_length)

        decoded_body = None
        try:
            decoded_body = json.loads(raw_body.decode("utf-8"))
        except Exception:
            decoded_body = {"raw_body": raw_body.decode("utf-8", errors="replace")}

        record = {
            "received_at": int(time.time()),
            "path": self.path,
            "request": decoded_body,
        }

        with open(capture_file, "a", encoding="utf-8") as f:
            f.write(json.dumps(record, ensure_ascii=True) + "\n")

        self._write_json(
            200,
            {
                "ack": {
                    "status": "accepted",
                    "disposition": "processed",
                    "receipt_id": "replay-wave4",
                }
            },
        )

    def log_message(self, format, *args):
        return


server = ThreadingHTTPServer((bind_host, bind_port), Handler)
server.serve_forever()
PY
stub_pid="$!"

if ! wait_for_stub; then
  log "ACK stub did not become ready; inspect ${STUB_LOG_FILE}"
  exit 1
fi

log "Running dev-plugin-sync with wave-4 telemetry overrides"
if ! env \
  PIXEL_SM_DEV_COMPOSE_FILES="$QA_COMPOSE_FILES" \
  PIXEL_SM_DEV_ARTIFACT_DIR="$QA_ARTIFACT_DIR" \
  PIXEL_SM_XMLRPC_PORT="$QA_XMLRPC_PORT" \
  PIXEL_SM_GAME_PORT="$QA_GAME_PORT" \
  PIXEL_SM_P2P_PORT="$QA_P2P_PORT" \
  PIXEL_CONTROL_API_BASE_URL="$STUB_BASE_URL" \
  bash "$DEV_SYNC_SCRIPT" > "$DEV_SYNC_LOG_FILE" 2>&1; then
  log "dev-plugin-sync failed; inspect ${DEV_SYNC_LOG_FILE}"
  exit 1
fi
stack_started=1

DEDICATED_ACTION_CODE=$(cat <<'PHP'
define('MANIACONTROL_PATH', '/opt/pixel-sm/runtime/server/ManiaControl/');
require_once '/opt/pixel-sm/runtime/server/ManiaControl/core/AutoLoader.php';
\ManiaControl\AutoLoader::register();

$xmlrpcPort = (int) getenv('PIXEL_SM_XMLRPC_PORT');
$password = (string) getenv('PIXEL_SM_MANIACONTROL_SUPERADMIN_PASSWORD');

$client = Maniaplanet\DedicatedServer\Connection::factory('127.0.0.1', $xmlrpcPort, 5, 'SuperAdmin', $password);

$safeCall = function ($label, callable $callback) {
    try {
        $callback();
        echo 'wave4_action_' . $label . "=ok\n";
        return true;
    } catch (\Throwable $throwable) {
        echo 'wave4_action_' . $label . '=warn:' . $throwable->getMessage() . "\n";
        return false;
    }
};

$listPlayerLogins = function () use ($client) {
    $logins = array();

    try {
        $players = $client->getPlayerList(200, 0);
    } catch (\Throwable $throwable) {
        return $logins;
    }

    if (!is_array($players)) {
        return $logins;
    }

    foreach ($players as $player) {
        if (!is_object($player) || !isset($player->login)) {
            continue;
        }

        $login = trim((string) $player->login);
        if ($login === '') {
            continue;
        }

        $logins[strtolower($login)] = $login;
    }

    return $logins;
};

$resolveWave4Login = function (array $baselineLogins) use ($listPlayerLogins) {
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $currentLogins = $listPlayerLogins();

        foreach ($currentLogins as $normalizedLogin => $login) {
            if (strpos($normalizedLogin, 'wave4bot') !== false || strpos($normalizedLogin, 'fake') !== false) {
                return $login;
            }
        }

        foreach ($currentLogins as $normalizedLogin => $login) {
            if (!array_key_exists($normalizedLogin, $baselineLogins)) {
                return $login;
            }
        }

        usleep(200000);
    }

    return '';
};

$forcePlayerTeamSafe = function (&$targetLogin, $targetTeamId, array $baselineLogins) use ($client, $resolveWave4Login, $safeCall) {
    $safeCall('force_team_' . $targetTeamId, function () use (&$targetLogin, $targetTeamId, $baselineLogins, $client, $resolveWave4Login) {
        try {
            $client->forcePlayerTeam($targetLogin, $targetTeamId);
            return;
        } catch (\Throwable $firstError) {
            $resolvedLogin = $resolveWave4Login($baselineLogins);
            if ($resolvedLogin !== '') {
                $targetLogin = $resolvedLogin;
            }

            $client->forcePlayerTeam($targetLogin, $targetTeamId);
        }
    });
};

$baselineLogins = $listPlayerLogins();
$wave4Login = 'Wave4Bot';

$safeCall('connect_fake_player_1', function () use ($client) {
    $client->connectFakePlayer('Wave4Bot');
});
usleep(400000);

$resolvedLogin = $resolveWave4Login($baselineLogins);
if ($resolvedLogin !== '') {
    $wave4Login = $resolvedLogin;
}

$forcePlayerTeamSafe($wave4Login, 0, $baselineLogins);
usleep(300000);
$forcePlayerTeamSafe($wave4Login, 1, $baselineLogins);
usleep(300000);

$safeCall('disconnect_fake_player_named', function () use ($client, &$wave4Login) {
    $client->disconnectFakePlayer($wave4Login);
});
usleep(350000);

$safeCall('connect_fake_player_2', function () use ($client) {
    $client->connectFakePlayer('Wave4Bot');
});
usleep(400000);

$resolvedLogin = $resolveWave4Login($baselineLogins);
if ($resolvedLogin !== '') {
    $wave4Login = $resolvedLogin;
}

$forcePlayerTeamSafe($wave4Login, 0, $baselineLogins);
usleep(300000);

$safeCall('restart_map', function () use ($client) {
    $client->restartMap();
});
usleep(350000);

$safeCall('next_map', function () use ($client) {
    $client->nextMap();
});
usleep(350000);

$safeCall('disconnect_fake_player_all', function () use ($client) {
    $client->disconnectFakePlayer('*');
});

echo "wave4_dedicated_actions_done\n";
PHP
)

log "Triggering deterministic reconnect/side-change/map actions inside shootmania"
if ! compose exec -T shootmania php -r "$DEDICATED_ACTION_CODE" > "$DEDICATED_ACTION_LOG_FILE" 2>&1; then
  log "Dedicated actions failed; inspect ${DEDICATED_ACTION_LOG_FILE}"
  exit 1
fi

log "Waiting ${POST_ACTION_WAIT_SECONDS}s for plugin dispatch flush"
sleep "$POST_ACTION_WAIT_SECONDS"

inject_wave4_fixture_envelopes

compose logs --no-color shootmania > "$SHOOTMANIA_LOG_FILE" || true

runtime_source="$(read_env_value "$ENV_FILE" "PIXEL_SM_RUNTIME_SOURCE")"
if [ -z "$runtime_source" ]; then
  runtime_source="./runtime/server"
fi
runtime_log_file="$(resolve_path "$runtime_source")/ManiaControl/ManiaControl.log"
if [ -f "$runtime_log_file" ]; then
  cp "$runtime_log_file" "$MANIACONTROL_LOG_FILE"
fi

MARKER_VALIDATION_CODE=$(cat <<'PHP'
$captureFile = $argv[1];
$markersFile = $argv[2];
$markerProfile = isset($argv[3]) ? strtolower(trim((string) $argv[3])) : 'strict';
if ($markerProfile !== 'strict' && $markerProfile !== 'plugin_only') {
    $markerProfile = 'strict';
}

$lines = @file($captureFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) {
    fwrite(STDERR, "Unable to read capture file\n");
    exit(2);
}

$result = [
    'event_counts' => [
        'connectivity' => 0,
        'lifecycle' => 0,
        'player' => 0,
        'combat' => 0,
        'mode' => 0,
        'unknown' => 0,
    ],
    'line_count' => count($lines),
    'envelope_count' => 0,
    'markers' => [
        'player_reconnect_continuity' => false,
        'player_side_change' => false,
        'lifecycle_team_aggregate' => false,
        'lifecycle_win_context' => false,
        'lifecycle_veto_actions' => false,
        'lifecycle_veto_result' => false,
    ],
    'identity_checks' => [
        'total' => 0,
        'valid' => 0,
        'invalid' => 0,
    ],
];

foreach ($lines as $line) {
    $row = json_decode($line, true);
    if (!is_array($row)) {
        continue;
    }

    $request = $row['request'] ?? null;
    if (!is_array($request)) {
        continue;
    }

    $envelope = $request['envelope'] ?? null;
    if (!is_array($envelope)) {
        continue;
    }

    $result['envelope_count']++;

    $eventId = isset($envelope['event_id']) ? trim((string) $envelope['event_id']) : '';
    $idempotencyKey = isset($envelope['idempotency_key']) ? trim((string) $envelope['idempotency_key']) : '';
    $eventName = isset($envelope['event_name']) ? trim((string) $envelope['event_name']) : '';
    $sourceCallback = isset($envelope['source_callback']) ? trim((string) $envelope['source_callback']) : '';
    $sourceSequence = isset($envelope['source_sequence']) && is_numeric($envelope['source_sequence']) ? (int) $envelope['source_sequence'] : 0;

    $identityValid = true;
    if ($eventId === '' || $idempotencyKey === '' || $eventName === '' || $sourceCallback === '' || $sourceSequence < 1) {
        $identityValid = false;
    }

    if ($identityValid && $idempotencyKey !== ('pc-idem-' . sha1($eventId))) {
        $identityValid = false;
    }

    $result['identity_checks']['total']++;
    if ($identityValid) {
        $result['identity_checks']['valid']++;
    } else {
        $result['identity_checks']['invalid']++;
    }

    $category = $envelope['event_category'] ?? 'unknown';
    if (!isset($result['event_counts'][$category])) {
        $result['event_counts']['unknown']++;
    } else {
        $result['event_counts'][$category]++;
    }

    $payload = $envelope['payload'] ?? [];
    if (!is_array($payload)) {
        continue;
    }

    if ($category === 'player') {
        if (isset($payload['reconnect_continuity']['transition_state'])) {
            $result['markers']['player_reconnect_continuity'] = true;
        }

        if (
            isset($payload['side_change']['transition_kind'])
            && array_key_exists('detected', $payload['side_change'])
        ) {
            $result['markers']['player_side_change'] = true;
        }
    }

    if ($category === 'lifecycle') {
        if (isset($payload['aggregate_stats']['team_counters_delta']) && is_array($payload['aggregate_stats']['team_counters_delta'])) {
            $result['markers']['lifecycle_team_aggregate'] = true;
        }

        if (
            isset($payload['aggregate_stats']['win_context']['result_state'])
            && isset($payload['aggregate_stats']['win_context']['winning_side'])
        ) {
            $result['markers']['lifecycle_win_context'] = true;
        }

        if (
            isset($payload['map_rotation']['veto_draft_actions']['actions'])
            || isset($payload['map_rotation']['veto_draft_actions']['action_count'])
        ) {
            $result['markers']['lifecycle_veto_actions'] = true;
        }

        if (
            isset($payload['map_rotation']['veto_result']['status'])
            && in_array($payload['map_rotation']['veto_result']['status'], ['partial', 'unavailable'], true)
        ) {
            $result['markers']['lifecycle_veto_result'] = true;
        }
    }
}

$allMarkersPassed = true;
foreach ($result['markers'] as $markerValue) {
    if (!$markerValue) {
        $allMarkersPassed = false;
        break;
    }
}

$callbackEventCount =
    (int) $result['event_counts']['lifecycle']
    + (int) $result['event_counts']['player']
    + (int) $result['event_counts']['combat']
    + (int) $result['event_counts']['mode'];

$pluginOnlyChecks = [
    'capture_lines_observed' => ((int) $result['line_count']) > 0,
    'envelopes_observed' => ((int) $result['envelope_count']) > 0,
    'connectivity_events_observed' => ((int) $result['event_counts']['connectivity']) > 0,
    'callback_events_observed' => $callbackEventCount > 0,
    'identity_fields_valid' => ((int) $result['identity_checks']['total']) > 0 && ((int) $result['identity_checks']['invalid']) === 0,
];

$pluginOnlyPassed = true;
foreach ($pluginOnlyChecks as $checkPassed) {
    if (!$checkPassed) {
        $pluginOnlyPassed = false;
        break;
    }
}

$profilePassed = ($markerProfile === 'plugin_only') ? $pluginOnlyPassed : $allMarkersPassed;

$result['validation_profile'] = $markerProfile;
$result['all_markers_passed'] = $allMarkersPassed;
$result['strict_markers_passed'] = $allMarkersPassed;
$result['plugin_only_checks'] = $pluginOnlyChecks;
$result['plugin_only_passed'] = $pluginOnlyPassed;
$result['profile_passed'] = $profilePassed;
file_put_contents($markersFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

if (!$profilePassed) {
    if ($markerProfile === 'plugin_only') {
        fwrite(STDERR, "Plugin-only baseline checks failed\n");
    } else {
        fwrite(STDERR, "Required wave-4 markers were not all observed\n");
    }
    exit(1);
}
PHP
)

log "Validating capture markers"
if ! php -r "$MARKER_VALIDATION_CODE" "$CAPTURE_FILE" "$MARKERS_FILE" "$MARKER_PROFILE"; then
  log "Marker validation failed; inspect ${MARKERS_FILE} and ${CAPTURE_FILE}"
  exit 1
fi

cat > "$SUMMARY_FILE" <<EOF
# Wave 4 telemetry replay summary (${QA_TIMESTAMP})

- Compose files: ${QA_COMPOSE_FILES}
- API override: ${STUB_BASE_URL}
- Runtime ports: xmlrpc=${QA_XMLRPC_PORT}, game=${QA_GAME_PORT}, p2p=${QA_P2P_PORT}
- Keep stack running: ${KEEP_STACK_RUNNING}
- Fixture marker injection: ${INJECT_FIXTURES}
- Marker validation profile: ${MARKER_PROFILE}

## Evidence files

- Capture NDJSON: ${CAPTURE_FILE}
- Marker report: ${MARKERS_FILE}
- Stub log: ${STUB_LOG_FILE}
- Dev sync log: ${DEV_SYNC_LOG_FILE}
- Dedicated actions log: ${DEDICATED_ACTION_LOG_FILE}
- Shootmania container log: ${SHOOTMANIA_LOG_FILE}
- ManiaControl log snapshot: ${MANIACONTROL_LOG_FILE}
- Fixture envelopes: ${FIXTURE_ENVELOPES_FILE}

## Required markers validated

Marker profile behavior:

- strict: requires all six wave-4 markers; intended for deterministic closure runs (default when fixture injection is enabled).
- plugin_only: intended for fixture-off manual baseline runs; requires observed connectivity + callback envelopes and valid identity fields, while allowing missing full marker closure without real gameplay clients.

Strict marker list:

- player reconnect continuity payload ('reconnect_continuity.transition_state')
- player side-change payload ('side_change.transition_kind' + 'detected')
- lifecycle team aggregate payload ('aggregate_stats.team_counters_delta')
- lifecycle win-context result ('aggregate_stats.win_context.result_state' + 'winning_side')
- lifecycle veto action payload ('map_rotation.veto_draft_actions')
- lifecycle veto result payload ('map_rotation.veto_result.status' in 'partial|unavailable')
EOF

log "Wave-4 telemetry replay checks passed"
log "Summary: ${SUMMARY_FILE}"
