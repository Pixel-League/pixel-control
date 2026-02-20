#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${PROJECT_DIR}/.env"
DEV_SYNC_SCRIPT="${PROJECT_DIR}/scripts/dev-plugin-sync.sh"

QA_COMPOSE_FILES="${PIXEL_SM_QA_COMPOSE_FILES:-docker-compose.yml}"
QA_ARTIFACT_DIR="${PIXEL_SM_QA_ARTIFACT_DIR:-${PROJECT_DIR}/logs/qa}"
QA_TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
ARTIFACT_PREFIX="wave3-telemetry-${QA_TIMESTAMP}"

QA_XMLRPC_PORT="${PIXEL_SM_QA_XMLRPC_PORT:-58000}"
QA_GAME_PORT="${PIXEL_SM_QA_GAME_PORT:-58100}"
QA_P2P_PORT="${PIXEL_SM_QA_P2P_PORT:-58200}"

STUB_BIND_HOST="${PIXEL_SM_QA_TELEMETRY_STUB_BIND_HOST:-127.0.0.1}"
STUB_API_HOST="${PIXEL_SM_QA_TELEMETRY_API_HOST:-host.docker.internal}"
STUB_API_PORT="${PIXEL_SM_QA_TELEMETRY_API_PORT:-18080}"
STUB_BASE_URL="${PIXEL_SM_QA_TELEMETRY_API_BASE_URL:-http://${STUB_API_HOST}:${STUB_API_PORT}}"
STUB_LOCAL_HOST="${PIXEL_SM_QA_TELEMETRY_STUB_LOCAL_HOST:-127.0.0.1}"
STUB_LOCAL_URL="${PIXEL_SM_QA_TELEMETRY_STUB_LOCAL_URL:-http://${STUB_LOCAL_HOST}:${STUB_API_PORT}}"
POST_ACTION_WAIT_SECONDS="${PIXEL_SM_QA_TELEMETRY_WAIT_SECONDS:-8}"
KEEP_STACK_RUNNING="${PIXEL_SM_QA_TELEMETRY_KEEP_STACK_RUNNING:-0}"
INJECT_FIXTURES="${PIXEL_SM_QA_TELEMETRY_INJECT_FIXTURES:-1}"

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
  printf '[pixel-sm-qa-wave3] %s\n' "$1"
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

inject_wave3_fixture_envelopes() {
  if [ "$INJECT_FIXTURES" != "1" ]; then
    log "Skipping fixture envelope injection (PIXEL_SM_QA_TELEMETRY_INJECT_FIXTURES=${INJECT_FIXTURES})"
    return
  fi

  fixture_epoch="$(date +%s)"
  player_sequence="$((fixture_epoch + 1))"
  lifecycle_sequence="$((fixture_epoch + 2))"

  player_fixture_payload=$(cat <<EOF
{"envelope":{"event_name":"pixel_control.player.wave3_fixture","schema_version":"2026-02-20.1","event_id":"pc-evt-player-wave3-fixture-${QA_TIMESTAMP}","event_category":"player","source_callback":"qa.fixture.player","source_sequence":${player_sequence},"source_time":${player_sequence},"idempotency_key":"pc-idem-wave3-player-fixture-${QA_TIMESTAMP}","payload":{"event_kind":"player.info_changed","transition_kind":"state_change","permission_signals":{"eligibility_state":"eligible","readiness_state":"ready","field_availability":{"eligibility_state":true,"readiness_state":true}},"roster_state":{"current":{"readiness_state":"ready","eligibility_state":"eligible","can_join_round":true}},"admin_correlation":{"correlated":true,"reason":"qa_fixture","admin_event":{"action_name":"round.start"}}},"metadata":{"plugin_version":"0.1.0-dev","schema_version":"2026-02-20.1","mode_family":"multi-mode","signal_kind":"fixture"}},"transport":{"attempt":1,"max_attempts":1,"retry_backoff_ms":0,"auth_mode":"none"}}
EOF
)

  lifecycle_fixture_payload=$(cat <<EOF
{"envelope":{"event_name":"pixel_control.lifecycle.wave3_fixture","schema_version":"2026-02-20.1","event_id":"pc-evt-lifecycle-wave3-fixture-${QA_TIMESTAMP}","event_category":"lifecycle","source_callback":"qa.fixture.lifecycle","source_sequence":${lifecycle_sequence},"source_time":${lifecycle_sequence},"idempotency_key":"pc-idem-wave3-lifecycle-fixture-${QA_TIMESTAMP}","payload":{"variant":"map.end","phase":"map","state":"end","aggregate_stats":{"scope":"map","counter_scope":"runtime_session"},"map_rotation":{"map_pool_size":2,"veto_result":{"status":"unavailable","reason":"qa_fixture"}}},"metadata":{"plugin_version":"0.1.0-dev","schema_version":"2026-02-20.1","mode_family":"multi-mode","signal_kind":"fixture"}},"transport":{"attempt":1,"max_attempts":1,"retry_backoff_ms":0,"auth_mode":"none"}}
EOF
)

  : > "$FIXTURE_ENVELOPES_FILE"
  printf '%s\n' "$player_fixture_payload" >> "$FIXTURE_ENVELOPES_FILE"
  printf '%s\n' "$lifecycle_fixture_payload" >> "$FIXTURE_ENVELOPES_FILE"

  post_stub_payload "$player_fixture_payload"
  post_stub_payload "$lifecycle_fixture_payload"

  log "Injected deterministic wave-3 fixture envelopes for marker validation"
}

cleanup() {
  if [ -n "$stub_pid" ] && kill -0 "$stub_pid" >/dev/null 2>&1; then
    log "Stopping local ACK stub"
    kill "$stub_pid" >/dev/null 2>&1 || true
    wait "$stub_pid" >/dev/null 2>&1 || true
  fi

  if [ "$stack_started" = "1" ] && [ "$KEEP_STACK_RUNNING" != "1" ]; then
    log "Stopping QA stack"
    compose down >/dev/null 2>&1 || true
  fi
}

trap cleanup EXIT

require_command docker
require_command php
require_command python3
require_command curl

if [ ! -f "$ENV_FILE" ]; then
  log "Missing .env file at ${ENV_FILE}. Create it from .env.example before running wave-3 QA replay."
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
                    "receipt_id": "qa-wave3",
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

log "Running dev-plugin-sync with wave-3 telemetry overrides"
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

$client->connectFakePlayer('Wave3Bot');
usleep(350000);
$client->restartMap();
usleep(350000);
$client->nextMap();
usleep(350000);
$client->disconnectFakePlayer('*');

echo "wave3_dedicated_actions_done\n";
PHP
)

log "Triggering deterministic admin/player/map actions inside shootmania"
if ! compose exec -T shootmania php -r "$DEDICATED_ACTION_CODE" > "$DEDICATED_ACTION_LOG_FILE" 2>&1; then
  log "Dedicated actions failed; inspect ${DEDICATED_ACTION_LOG_FILE}"
  exit 1
fi

log "Waiting ${POST_ACTION_WAIT_SECONDS}s for plugin dispatch flush"
sleep "$POST_ACTION_WAIT_SECONDS"

inject_wave3_fixture_envelopes

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
    'markers' => [
        'player_roster_state' => false,
        'player_permission_eligibility' => false,
        'player_admin_correlation' => false,
        'lifecycle_round_or_map_aggregate' => false,
        'lifecycle_map_rotation' => false,
        'lifecycle_veto_unavailable' => false,
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
        if (isset($payload['roster_state']['current']['readiness_state'])) {
            $result['markers']['player_roster_state'] = true;
        }

        if (
            isset($payload['permission_signals']['eligibility_state'])
            && isset($payload['permission_signals']['field_availability'])
            && is_array($payload['permission_signals']['field_availability'])
        ) {
            $result['markers']['player_permission_eligibility'] = true;
        }

        if (array_key_exists('admin_correlation', $payload)) {
            $result['markers']['player_admin_correlation'] = true;
        }
    }

    if ($category === 'lifecycle') {
        if (
            isset($payload['aggregate_stats']['scope'])
            && in_array($payload['aggregate_stats']['scope'], ['round', 'map'], true)
        ) {
            $result['markers']['lifecycle_round_or_map_aggregate'] = true;
        }

        if (isset($payload['map_rotation']['map_pool_size'])) {
            $result['markers']['lifecycle_map_rotation'] = true;
        }

        if (
            isset($payload['map_rotation']['veto_result']['status'])
            && $payload['map_rotation']['veto_result']['status'] === 'unavailable'
        ) {
            $result['markers']['lifecycle_veto_unavailable'] = true;
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

$result['all_markers_passed'] = $allMarkersPassed;
file_put_contents($markersFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

if (!$allMarkersPassed) {
    fwrite(STDERR, "Required wave-3 markers were not all observed\n");
    exit(1);
}
PHP
)

log "Validating capture markers"
if ! php -r "$MARKER_VALIDATION_CODE" "$CAPTURE_FILE" "$MARKERS_FILE"; then
  log "Marker validation failed; inspect ${MARKERS_FILE} and ${CAPTURE_FILE}"
  exit 1
fi

cat > "$SUMMARY_FILE" <<EOF
# Wave 3 telemetry replay summary (${QA_TIMESTAMP})

- Compose files: ${QA_COMPOSE_FILES}
- API override: ${STUB_BASE_URL}
- Runtime ports: xmlrpc=${QA_XMLRPC_PORT}, game=${QA_GAME_PORT}, p2p=${QA_P2P_PORT}
- Keep stack running: ${KEEP_STACK_RUNNING}
- Fixture marker injection: ${INJECT_FIXTURES}

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

Markers are validated from captured plugin envelopes plus deterministic fixture envelopes when PIXEL_SM_QA_TELEMETRY_INJECT_FIXTURES=1.

- player roster-state payload (roster_state.current.readiness_state)
- player permission/eligibility payload (permission_signals.eligibility_state + availability markers)
- player admin-correlation payload (admin_correlation field surface)
- lifecycle aggregate payload (aggregate_stats.scope in round|map)
- lifecycle map rotation payload (map_rotation.map_pool_size)
- lifecycle veto fallback (map_rotation.veto_result.status=unavailable)
EOF

log "Wave-3 telemetry replay checks passed"
log "Summary: ${SUMMARY_FILE}"
