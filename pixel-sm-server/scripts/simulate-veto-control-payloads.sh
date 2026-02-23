#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

ENV_FILE="${PIXEL_SM_VETO_SIM_ENV_FILE:-${PROJECT_DIR}/.env}"
COMPOSE_FILES_CSV="${PIXEL_SM_VETO_SIM_COMPOSE_FILES:-${PIXEL_SM_QA_COMPOSE_FILES:-docker-compose.yml}}"
SERVICE_NAME="${PIXEL_SM_VETO_SIM_SERVICE_NAME:-shootmania}"

COMM_HOST="${PIXEL_SM_VETO_SIM_COMM_HOST:-127.0.0.1}"
COMM_PORT="${PIXEL_SM_VETO_SIM_COMM_PORT:-}"
COMM_PASSWORD="${PIXEL_SM_VETO_SIM_COMM_PASSWORD:-}"
COMM_SOCKET_ENABLED=""

METHOD_START="PixelControl.VetoDraft.Start"
METHOD_ACTION="PixelControl.VetoDraft.Action"
METHOD_STATUS="PixelControl.VetoDraft.Status"
METHOD_CANCEL="PixelControl.VetoDraft.Cancel"
METHOD_READY="PixelControl.VetoDraft.Ready"

OUTPUT_ROOT="${PIXEL_SM_VETO_SIM_OUTPUT_ROOT:-${PROJECT_DIR}/logs/qa}"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
RUN_DIR="${OUTPUT_ROOT}/veto-payload-sim-${TIMESTAMP}"
MATRIX_ACTION_SPECS_DIR="${PIXEL_SM_VETO_SIM_ACTION_SPECS_DIR:-${SCRIPT_DIR}/veto-action-matrix-steps}"
MATRIX_ASSERT_STRICT="${PIXEL_SM_VETO_SIM_MATRIX_ASSERT_STRICT:-1}"

MATRIX_CAPTAIN_A="${PIXEL_SM_VETO_SIM_CAPTAIN_A:-__pixel_captain_a__}"
MATRIX_CAPTAIN_B="${PIXEL_SM_VETO_SIM_CAPTAIN_B:-__pixel_captain_b__}"
MATRIX_VOTER_A="${PIXEL_SM_VETO_SIM_VOTER_A:-voter_a}"
MATRIX_VOTER_B="${PIXEL_SM_VETO_SIM_VOTER_B:-voter_b}"
MATRIX_VOTER_C="${PIXEL_SM_VETO_SIM_VOTER_C:-voter_c}"
MATRIX_MATCHMAKING_DURATION="${PIXEL_SM_VETO_SIM_MATCHMAKING_DURATION:-8}"
MATRIX_TOURNAMENT_BEST_OF="${PIXEL_SM_VETO_SIM_TOURNAMENT_BEST_OF:-3}"
MATRIX_TOURNAMENT_STARTER="${PIXEL_SM_VETO_SIM_TOURNAMENT_STARTER:-team_a}"
MATRIX_TOURNAMENT_TIMEOUT="${PIXEL_SM_VETO_SIM_TOURNAMENT_TIMEOUT:-45}"
MATRIX_WAIT_EXTRA_SECONDS="${PIXEL_SM_VETO_SIM_WAIT_EXTRA_SECONDS:-20}"

COMPOSE_FILE_ARGS=()
MATRIX_LAST_RESPONSE_FILE=""
MATRIX_LAST_COMMUNICATION_ERROR=""
MATRIX_LAST_ACTION_SUCCESS=""
MATRIX_LAST_ACTION_CODE=""

MATRIX_SUMMARY_FILE=""
MATRIX_MANIFEST_FILE=""
MATRIX_VALIDATION_FILE=""
MATRIX_CONTEXT_FILE=""
MATRIX_STEP_INDEX=0

MATRIX_STATUS_ACTIVE="0"
MATRIX_STATUS_MODE=""
MATRIX_STATUS_SESSION_STATUS=""
MATRIX_STATUS_CURRENT_TEAM=""

MATRIX_MATCHMAKING_STARTED=0
MATRIX_MATCHMAKING_LIMITED_MODE=0
MATRIX_MATCHMAKING_MAP_END_TRIGGERED=0
MATRIX_TOURNAMENT_STARTED=0
MATRIX_TOURNAMENT_LIMITED_MODE=0
MATRIX_TOURNAMENT_GUARD=0
MATRIX_TOURNAMENT_MAX_STEPS=32

log() {
  printf '[simulate-veto-control-payloads] %s\n' "$*"
}

warn() {
  printf '[simulate-veto-control-payloads][warn] %s\n' "$*" >&2
}

fail() {
  printf '[simulate-veto-control-payloads][error] %s\n' "$*" >&2
  exit 1
}

usage() {
  cat <<'USAGE'
Usage:
  bash scripts/simulate-veto-control-payloads.sh status
  bash scripts/simulate-veto-control-payloads.sh start [key=value ...]
  bash scripts/simulate-veto-control-payloads.sh action [key=value ...]
  bash scripts/simulate-veto-control-payloads.sh cancel [key=value ...]
  bash scripts/simulate-veto-control-payloads.sh ready
  bash scripts/simulate-veto-control-payloads.sh matrix [key=value ...]

Commands:
  status
      Calls PixelControl.VetoDraft.Status.

  start [key=value ...]
      Calls PixelControl.VetoDraft.Start.
      Common keys:
        mode=matchmaking_vote|tournament_draft
        duration_seconds=<int>
        launch_immediately=0|1
        captain_a=<login>
        captain_b=<login>
        best_of=<odd int>
        starter=team_a|team_b|random
        action_timeout_seconds=<int>

  action [key=value ...]
      Calls PixelControl.VetoDraft.Action.
      Common keys:
        actor_login=<login>
        operation=vote|action
        map=<uid|index>
        selection=<uid|index>
        allow_override=0|1
        force=0|1

  cancel [key=value ...]
      Calls PixelControl.VetoDraft.Cancel.
      Common keys:
        reason=<text>

  ready
      Calls PixelControl.VetoDraft.Ready.

  matrix [key=value ...]
      Runs deterministic matchmaking + tournament veto simulation matrix
      through modular scenario scripts + strict response assertions.
      Optional overrides:
        captain_a=<login>
        captain_b=<login>
        voter_a=<login>
        voter_b=<login>
        voter_c=<login>
        matchmaking_duration=<int>
        tournament_best_of=<odd int>
        tournament_starter=team_a|team_b|random
        tournament_timeout=<int>
        wait_extra_seconds=<int>
        action_specs_dir=<path>
        matrix_assert_strict=0|1

Env overrides:
  PIXEL_SM_VETO_SIM_ENV_FILE
  PIXEL_SM_VETO_SIM_COMPOSE_FILES (CSV, fallback PIXEL_SM_QA_COMPOSE_FILES or docker-compose.yml)
  PIXEL_SM_VETO_SIM_SERVICE_NAME (default: shootmania)
  PIXEL_SM_VETO_SIM_COMM_HOST (default: 127.0.0.1)
  PIXEL_SM_VETO_SIM_COMM_PORT (default: auto from mc_settings, else 31501)
  PIXEL_SM_VETO_SIM_COMM_PASSWORD (default: auto from mc_settings, else empty string)
  PIXEL_SM_VETO_SIM_OUTPUT_ROOT (default: pixel-sm-server/logs/qa)
  PIXEL_SM_VETO_SIM_ACTION_SPECS_DIR (default: scripts/veto-action-matrix-steps)
  PIXEL_SM_VETO_SIM_MATRIX_ASSERT_STRICT (default: 1)

Notes:
  - This tool simulates future Pixel Control Server orchestration over ManiaControl communication socket.
  - It targets PixelControl.VetoDraft.Start|Action|Status|Cancel methods.
  - Ready gate arming is available through PixelControl.VetoDraft.Ready.
  - Matrix command emits strict validation artifacts (matrix-context.json + matrix-validation.json).
USAGE
}

trim_whitespace() {
  local input="$*"
  input="${input#${input%%[![:space:]]*}}"
  input="${input%${input##*[![:space:]]}}"
  printf '%s' "$input"
}

resolve_path() {
  local path="$1"
  if [[ "$path" = /* ]]; then
    printf '%s' "$path"
    return
  fi

  printf '%s/%s' "$PROJECT_DIR" "$path"
}

build_compose_file_args() {
  local compose_files_csv="$1"
  local normalized_csv
  local parts=()
  local entry
  local trimmed
  local resolved
  local has_file=0

  normalized_csv="$(trim_whitespace "$compose_files_csv")"
  if [[ -z "$normalized_csv" ]]; then
    fail "Compose file list cannot be empty."
  fi

  IFS=',' read -r -a parts <<< "$normalized_csv"

  for entry in "${parts[@]}"; do
    trimmed="$(trim_whitespace "$entry")"
    if [[ -z "$trimmed" ]]; then
      continue
    fi

    resolved="$(resolve_path "$trimmed")"
    if [[ ! -f "$resolved" ]]; then
      fail "Compose file not found: $resolved"
    fi

    has_file=1
    printf '%s\n' "-f"
    printf '%s\n' "$resolved"
  done

  if [[ "$has_file" -ne 1 ]]; then
    fail "No valid compose files resolved from: $compose_files_csv"
  fi
}

compose() {
  docker compose --ansi never "${COMPOSE_FILE_ARGS[@]}" --env-file "$ENV_FILE" "$@"
}

ensure_prerequisites() {
  command -v docker >/dev/null 2>&1 || fail "docker command not found."
  [[ -f "$ENV_FILE" ]] || fail "Env file not found: $ENV_FILE"

  local running_services=""
  local attempt=1
  local max_attempts=30

  while [ "$attempt" -le "$max_attempts" ]; do
    running_services="$(compose ps --status running --services 2>/dev/null || true)"
    if printf '%s\n' "$running_services" | grep -Fxq "$SERVICE_NAME"; then
      return 0
    fi

    attempt=$((attempt + 1))
    sleep 1
  done

  fail "Service '$SERVICE_NAME' is not running. Start stack first (for example: docker compose up -d --build)."
}

resolve_socket_settings_from_db() {
  compose exec -T "$SERVICE_NAME" php <<'PHP'
<?php
mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('PIXEL_SM_DB_HOST') ?: 'mysql';
$port = (int) (getenv('PIXEL_SM_DB_PORT') ?: '3306');
$user = getenv('PIXEL_SM_DB_USER') ?: '';
$pass = getenv('PIXEL_SM_DB_PASSWORD') ?: '';
$name = getenv('PIXEL_SM_DB_NAME') ?: '';

if ($user === '' || $name === '') {
    exit(0);
}

$mysqli = @new mysqli($host, $user, $pass, $name, $port);
if ($mysqli->connect_errno) {
    exit(0);
}

$className = 'ManiaControl\\Communication\\CommunicationManager';
$query = "SELECT `setting`, `value` FROM `mc_settings` WHERE `class` = '" . $mysqli->real_escape_string($className) . "';";
$result = $mysqli->query($query);
if (!$result) {
    $mysqli->close();
    exit(0);
}

$enabled = '';
$portValue = '';
$password = '';

while ($row = $result->fetch_assoc()) {
    $settingName = isset($row['setting']) ? (string) $row['setting'] : '';
    $settingValue = isset($row['value']) ? (string) $row['value'] : '';

    if ($settingName === 'Activate Socket') {
        $enabled = $settingValue;
        continue;
    }

    if ($settingName === 'Password for the Socket Connection') {
        $password = $settingValue;
        continue;
    }

    if (strpos($settingName, 'Socket Port for Server ') === 0 && $portValue === '') {
        $portValue = $settingValue;
    }
}

$result->free();
$mysqli->close();

echo $enabled . "\t" . $portValue . "\t" . $password;
PHP
}

resolve_socket_settings() {
  local settings_line=""
  local auto_enabled=""
  local auto_port=""
  local auto_password=""

  settings_line="$(resolve_socket_settings_from_db 2>/dev/null || true)"
  if [[ -n "$settings_line" ]]; then
    IFS=$'\t' read -r auto_enabled auto_port auto_password <<< "$settings_line"
  fi

  COMM_SOCKET_ENABLED="$auto_enabled"

  if [[ -z "$COMM_PORT" && -n "$auto_port" ]]; then
    COMM_PORT="$auto_port"
  fi
  if [[ -z "$COMM_PORT" ]]; then
    COMM_PORT="31501"
  fi

  if [[ -z "$COMM_PASSWORD" && -n "$auto_password" ]]; then
    COMM_PASSWORD="$auto_password"
  fi

  if [[ -n "$COMM_SOCKET_ENABLED" && "$COMM_SOCKET_ENABLED" != "1" && "$COMM_SOCKET_ENABLED" != "true" && "$COMM_SOCKET_ENABLED" != "yes" ]]; then
    warn "Communication socket setting is not enabled in mc_settings (Activate Socket=$COMM_SOCKET_ENABLED)."
  fi
}

json_escape() {
  local value="$1"
  value="${value//\\/\\\\}"
  value="${value//\"/\\\"}"
  value="${value//$'\n'/\\n}"
  value="${value//$'\r'/\\r}"
  value="${value//$'\t'/\\t}"
  printf '%s' "$value"
}

json_value() {
  local value="$1"
  if [[ "$value" =~ ^-?[0-9]+$ ]]; then
    printf '%s' "$value"
    return
  fi

  if [[ "$value" =~ ^-?[0-9]+\.[0-9]+$ ]]; then
    printf '%s' "$value"
    return
  fi

  if [[ "$value" == "true" || "$value" == "false" || "$value" == "null" ]]; then
    printf '%s' "$value"
    return
  fi

  printf '"%s"' "$(json_escape "$value")"
}

build_payload_json() {
  local pair
  local key
  local value
  local payload_json="{"
  local first=1

  for pair in "$@"; do
    if [[ "$pair" != *=* ]]; then
      fail "Invalid key=value pair: $pair"
    fi
    key="${pair%%=*}"
    value="${pair#*=}"

    if [[ -z "$key" ]]; then
      fail "Invalid key=value pair (empty key): $pair"
    fi

    if [[ "$first" -eq 0 ]]; then
      payload_json+=","
    fi
    first=0

    payload_json+="\"$(json_escape "$key")\":"
    payload_json+="$(json_value "$value")"
  done

  payload_json+="}"
  printf '%s' "$payload_json"
}

invoke_communication_method() {
  local method_name="$1"
  local payload_json="$2"
  local output_file="$3"
  local payload_b64
  local attempt=1
  local max_attempts=12
  local exit_code=0

  payload_b64="$(printf '%s' "$payload_json" | base64 | tr -d '\n')"

  while true; do
    set +e
    compose exec -T \
      -e PIXEL_SM_VETO_SIM_COMM_HOST="$COMM_HOST" \
      -e PIXEL_SM_VETO_SIM_COMM_PORT="$COMM_PORT" \
      -e PIXEL_SM_VETO_SIM_COMM_PASSWORD="$COMM_PASSWORD" \
      -e PIXEL_SM_VETO_SIM_METHOD="$method_name" \
      -e PIXEL_SM_VETO_SIM_DATA_B64="$payload_b64" \
      "$SERVICE_NAME" php <<'PHP' > "$output_file"
<?php
$host = getenv('PIXEL_SM_VETO_SIM_COMM_HOST') ?: '127.0.0.1';
$port = (int) (getenv('PIXEL_SM_VETO_SIM_COMM_PORT') ?: '31501');
$password = (string) getenv('PIXEL_SM_VETO_SIM_COMM_PASSWORD');
$method = (string) getenv('PIXEL_SM_VETO_SIM_METHOD');
$dataB64 = (string) getenv('PIXEL_SM_VETO_SIM_DATA_B64');
$stderr = @fopen('php://stderr', 'w');
$writeStderr = static function ($message) use ($stderr) {
    if (is_resource($stderr)) {
        fwrite($stderr, (string) $message);
    }
};

if ($method === '') {
    $writeStderr("Missing method name.\n");
    exit(2);
}

$decodedJson = base64_decode($dataB64, true);
if ($decodedJson === false) {
    $writeStderr("Invalid base64 payload data.\n");
    exit(2);
}

$data = json_decode($decodedJson, true);
if ($decodedJson !== '' && $data === null && json_last_error() !== JSON_ERROR_NONE) {
    $writeStderr("Invalid JSON payload: " . json_last_error_msg() . "\n");
    exit(2);
}
if (!is_array($data)) {
    $data = array();
}

$request = json_encode(
    array(
        'method' => $method,
        'data' => $data,
    ),
    JSON_UNESCAPED_SLASHES
);

if ($request === false) {
    $writeStderr("Failed to encode request JSON.\n");
    exit(2);
}

$encrypted = openssl_encrypt($request, 'aes-192-cbc', $password, OPENSSL_RAW_DATA, 'kZ2Kt0CzKUjN2MJX');
if ($encrypted === false) {
    $writeStderr("OpenSSL encryption failed.\n");
    exit(3);
}

$errno = 0;
$errstr = '';
$socket = @fsockopen($host, $port, $errno, $errstr, 5.0);
if (!$socket) {
    $writeStderr("Socket connect failed to {$host}:{$port} ({$errno} {$errstr})\n");
    exit(4);
}

stream_set_timeout($socket, 5);

$frame = strlen($encrypted) . "\n" . $encrypted;
$frameLength = strlen($frame);
$written = 0;

while ($written < $frameLength) {
    $bytes = fwrite($socket, substr($frame, $written));
    if ($bytes === false || $bytes === 0) {
        fclose($socket);
        $writeStderr("Socket write failed.\n");
        exit(5);
    }
    $written += $bytes;
}

$lengthLine = fgets($socket);
if ($lengthLine === false) {
    fclose($socket);
    $writeStderr("Socket read failed (length prefix missing).\n");
    exit(6);
}

$expectedLength = (int) trim($lengthLine);
if ($expectedLength <= 0) {
    fclose($socket);
    $writeStderr("Invalid response length prefix: {$lengthLine}\n");
    exit(6);
}

$responseEncrypted = '';
while (strlen($responseEncrypted) < $expectedLength && !feof($socket)) {
    $chunk = fread($socket, $expectedLength - strlen($responseEncrypted));
    if ($chunk === false) {
        fclose($socket);
        $writeStderr("Socket read failed while receiving encrypted response.\n");
        exit(7);
    }
    if ($chunk === '') {
        $meta = stream_get_meta_data($socket);
        if (!empty($meta['timed_out'])) {
            fclose($socket);
            $writeStderr("Socket read timeout while receiving encrypted response.\n");
            exit(7);
        }
        continue;
    }
    $responseEncrypted .= $chunk;
}

fclose($socket);

if (strlen($responseEncrypted) !== $expectedLength) {
    $writeStderr("Incomplete encrypted response payload.\n");
    exit(7);
}

$responseJson = openssl_decrypt($responseEncrypted, 'aes-192-cbc', $password, OPENSSL_RAW_DATA, 'kZ2Kt0CzKUjN2MJX');
if ($responseJson === false) {
    $writeStderr("OpenSSL decrypt failed (check socket password).\n");
    exit(8);
}

$response = json_decode($responseJson, true);
if (!is_array($response)) {
    $writeStderr("Invalid response JSON from communication socket.\n");
    exit(9);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
PHP

    exit_code=$?
    set -e

    if [ "$exit_code" -eq 0 ]; then
      return 0
    fi

    if [ "$attempt" -ge "$max_attempts" ]; then
      return "$exit_code"
    fi

    attempt=$((attempt + 1))
    sleep 1
  done
}

extract_response_triplet() {
  local response_file="$1"
  local communication_error="n/a"
  local action_success="n/a"
  local action_code="n/a"
  local line

  line="$(grep -Eo '"error"[[:space:]]*:[[:space:]]*(true|false)' "$response_file" | head -n1 || true)"
  if [[ -n "$line" ]]; then
    communication_error="${line##*:}"
    communication_error="$(trim_whitespace "$communication_error")"
  fi

  line="$(grep -Eo '"success"[[:space:]]*:[[:space:]]*(true|false)' "$response_file" | head -n1 || true)"
  if [[ -n "$line" ]]; then
    action_success="${line##*:}"
    action_success="$(trim_whitespace "$action_success")"
  fi

  line="$(grep -Eo '"code"[[:space:]]*:[[:space:]]*"[^"]+"' "$response_file" | head -n1 || true)"
  if [[ -n "$line" ]]; then
    action_code="${line#*:}"
    action_code="$(trim_whitespace "$action_code")"
    action_code="${action_code#\"}"
    action_code="${action_code%\"}"
  fi

  printf '%s\t%s\t%s' "$communication_error" "$action_success" "$action_code"
}

read_status_snapshot() {
  local response_file="$1"

  php -r '
  $file = isset($argv[1]) ? (string) $argv[1] : "";
  $raw = @file_get_contents($file);
  if (!is_string($raw)) {
      echo "0\t\t\t";
      exit(0);
  }

  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
      echo "0\t\t\t";
      exit(0);
  }

  $data = isset($decoded["data"]) && is_array($decoded["data"]) ? $decoded["data"] : array();
  $status = isset($data["status"]) && is_array($data["status"]) ? $data["status"] : array();
  $session = isset($status["session"]) && is_array($status["session"]) ? $status["session"] : array();
  $currentStep = isset($session["current_step"]) && is_array($session["current_step"]) ? $session["current_step"] : array();

  $active = !empty($status["active"]) ? "1" : "0";
  $mode = isset($status["mode"]) ? (string) $status["mode"] : "";
  $sessionStatus = isset($session["status"]) ? (string) $session["status"] : "";
  $currentTeam = isset($currentStep["team"]) ? (string) $currentStep["team"] : "";

  echo $active . "\t" . $mode . "\t" . $sessionStatus . "\t" . $currentTeam;
  ' "$response_file"
}

read_matchmaking_lifecycle_snapshot() {
  local response_file="$1"

  php -r '
  $file = isset($argv[1]) ? (string) $argv[1] : "";
  $raw = @file_get_contents($file);
  if (!is_string($raw)) {
      echo "\t\t0\t0";
      exit(0);
  }

  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
      echo "\t\t0\t0";
      exit(0);
  }

  $data = isset($decoded["data"]) && is_array($decoded["data"]) ? $decoded["data"] : array();
  $lifecycle = isset($data["matchmaking_lifecycle"]) && is_array($data["matchmaking_lifecycle"])
      ? $data["matchmaking_lifecycle"]
      : array();

  $status = isset($lifecycle["status"]) ? (string) $lifecycle["status"] : "";
  $stage = isset($lifecycle["stage"]) ? (string) $lifecycle["stage"] : "";
  $ready = !empty($lifecycle["ready_for_next_players"]) ? "1" : "0";
  $historyCount = isset($lifecycle["history"]) && is_array($lifecycle["history"]) ? (string) count($lifecycle["history"]) : "0";

  echo $status . "\t" . $stage . "\t" . $ready . "\t" . $historyCount;
  ' "$response_file"
}

read_matchmaking_selected_map_uid() {
  local response_file="$1"

  php -r '
  $file = isset($argv[1]) ? (string) $argv[1] : "";
  $raw = @file_get_contents($file);
  if (!is_string($raw)) {
      echo "";
      exit(0);
  }

  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
      echo "";
      exit(0);
  }

  $data = isset($decoded["data"]) && is_array($decoded["data"]) ? $decoded["data"] : array();
  $lifecycle = isset($data["matchmaking_lifecycle"]) && is_array($data["matchmaking_lifecycle"])
      ? $data["matchmaking_lifecycle"]
      : array();

  $selectedMap = isset($lifecycle["selected_map"]) && is_array($lifecycle["selected_map"]) ? $lifecycle["selected_map"] : array();
  $selectedMapUid = isset($selectedMap["uid"]) ? trim((string) $selectedMap["uid"]) : "";

  echo $selectedMapUid;
  ' "$response_file"
}

invoke_with_pairs() {
  local method_name="$1"
  local output_file="$2"
  shift 2
  local payload_json

  if [[ "$#" -gt 0 ]]; then
    payload_json="$(build_payload_json "$@")"
  else
    payload_json='{}'
  fi

  invoke_communication_method "$method_name" "$payload_json" "$output_file"
}

run_status() {
  local output_file="$1"
  invoke_with_pairs "$METHOD_STATUS" "$output_file"
}

run_start() {
  local output_file="$1"
  shift
  invoke_with_pairs "$METHOD_START" "$output_file" "$@"
}

run_action() {
  local output_file="$1"
  shift
  invoke_with_pairs "$METHOD_ACTION" "$output_file" "$@"
}

run_cancel() {
  local output_file="$1"
  shift
  invoke_with_pairs "$METHOD_CANCEL" "$output_file" "$@"
}

run_ready() {
  local output_file="$1"
  invoke_with_pairs "$METHOD_READY" "$output_file"
}

sanitize_name() {
  local name="$1"
  name="${name//./-}"
  name="${name//\//-}"
  name="${name//:/-}"
  name="${name// /-}"
  printf '%s' "$name"
}

matrix_step() {
  local summary_file="$1"
  local index="$2"
  local label="$3"
  local method_name="$4"
  shift 4

  local safe_label
  local response_file
  local summary_triplet
  local communication_error
  local action_success
  local action_code

  safe_label="$(sanitize_name "$label")"
  response_file="${RUN_DIR}/step-$(printf '%02d' "$index")-${safe_label}.json"

  invoke_with_pairs "$method_name" "$response_file" "$@"
  MATRIX_LAST_RESPONSE_FILE="$response_file"

  summary_triplet="$(extract_response_triplet "$response_file")"
  communication_error="${summary_triplet%%$'\t'*}"
  summary_triplet="${summary_triplet#*$'\t'}"
  action_success="${summary_triplet%%$'\t'*}"
  action_code="${summary_triplet#*$'\t'}"

   MATRIX_LAST_COMMUNICATION_ERROR="$communication_error"
   MATRIX_LAST_ACTION_SUCCESS="$action_success"
   MATRIX_LAST_ACTION_CODE="$action_code"

  printf '| %s | `%s` | `%s` | `%s` | `%s` | `%s` |\n' \
    "$index" \
    "$label" \
    "$communication_error" \
    "$action_success" \
    "$action_code" \
    "$(basename "$response_file")" \
    >> "$summary_file"
}

append_matrix_manifest_line() {
  local index="$1"
  local label="$2"
  local method_name="$3"
  local response_file="$4"
  local artifact_name

  artifact_name="$(basename "$response_file")"

  printf '{"index":%s,"label":"%s","method":"%s","artifact":"%s","communication_error":"%s","action_success":"%s","action_code":"%s"}\n' \
    "$index" \
    "$(json_escape "$label")" \
    "$(json_escape "$method_name")" \
    "$(json_escape "$artifact_name")" \
    "$(json_escape "$MATRIX_LAST_COMMUNICATION_ERROR")" \
    "$(json_escape "$MATRIX_LAST_ACTION_SUCCESS")" \
    "$(json_escape "$MATRIX_LAST_ACTION_CODE")" \
    >> "$MATRIX_MANIFEST_FILE"
}

matrix_run_step() {
  local label="$1"
  local method_name="$2"
  shift 2

  MATRIX_STEP_INDEX=$((MATRIX_STEP_INDEX + 1))
  matrix_step "$MATRIX_SUMMARY_FILE" "$MATRIX_STEP_INDEX" "$label" "$method_name" "$@"
  append_matrix_manifest_line "$MATRIX_STEP_INDEX" "$label" "$method_name" "$MATRIX_LAST_RESPONSE_FILE"
}

matrix_refresh_status_from_last() {
  local status_snapshot
  status_snapshot="$(read_status_snapshot "$MATRIX_LAST_RESPONSE_FILE")"
  IFS=$'\t' read -r MATRIX_STATUS_ACTIVE MATRIX_STATUS_MODE MATRIX_STATUS_SESSION_STATUS MATRIX_STATUS_CURRENT_TEAM <<< "$status_snapshot"
}

matrix_wait_for_matchmaking_closure() {
  local wait_seconds
  wait_seconds=$((MATRIX_MATCHMAKING_DURATION + MATRIX_WAIT_EXTRA_SECONDS))
  if [[ "$wait_seconds" -lt 2 ]]; then
    wait_seconds=2
  fi
  log "Waiting ${wait_seconds}s for matchmaking timer closure..."
  sleep "$wait_seconds"
}

matrix_trigger_matchmaking_map_end() {
  local trigger_file="${RUN_DIR}/matchmaking-lifecycle-map-end-trigger.log"
  local selected_map_uid=""
  local trigger_code

  selected_map_uid="$(read_matchmaking_selected_map_uid "$MATRIX_LAST_RESPONSE_FILE")"
  if [[ -z "$selected_map_uid" ]]; then
    MATRIX_MATCHMAKING_MAP_END_TRIGGERED=0
    warn "Failed to resolve selected map uid from status artifact: ${MATRIX_LAST_RESPONSE_FILE}"
    return 1
  fi

  trigger_code=$(cat <<'PHP'
define('MANIACONTROL_PATH', '/opt/pixel-sm/runtime/server/ManiaControl/');
require_once '/opt/pixel-sm/runtime/server/ManiaControl/core/AutoLoader.php';
\ManiaControl\AutoLoader::register();

$xmlrpcPort = (int) (getenv('PIXEL_SM_XMLRPC_PORT') ?: '5000');
$password = (string) getenv('PIXEL_SM_MANIACONTROL_SUPERADMIN_PASSWORD');
if ($password === '') {
    fwrite(STDERR, "Missing PIXEL_SM_MANIACONTROL_SUPERADMIN_PASSWORD env value\n");
    exit(2);
}

$selectedMapUid = trim((string) getenv('PIXEL_SM_QA_SELECTED_MAP_UID'));
if ($selectedMapUid === '') {
    fwrite(STDERR, "Missing PIXEL_SM_QA_SELECTED_MAP_UID env value\n");
    exit(2);
}

$client = Maniaplanet\DedicatedServer\Connection::factory('127.0.0.1', $xmlrpcPort, 5, 'SuperAdmin', $password);
$maxHops = 8;

for ($hop = 0; $hop < $maxHops; $hop++) {
    $currentMapInfo = $client->getCurrentMapInfo();
    $currentMapUid = '';

    if (is_object($currentMapInfo)) {
        if (isset($currentMapInfo->uid)) {
            $currentMapUid = trim((string) $currentMapInfo->uid);
        } elseif (isset($currentMapInfo->uId)) {
            $currentMapUid = trim((string) $currentMapInfo->uId);
        } elseif (isset($currentMapInfo->UId)) {
            $currentMapUid = trim((string) $currentMapInfo->UId);
        }
    }

    if ($currentMapUid !== '' && strcasecmp($currentMapUid, $selectedMapUid) === 0) {
        $client->nextMap();
        echo "matchmaking_lifecycle_map_end_triggered\n";
        echo "selected_map_uid={$selectedMapUid}\n";
        echo "hop={$hop}\n";
        exit(0);
    }

    $client->nextMap();
    usleep(700000);
}

fwrite(STDERR, "Could not reach selected map uid before timeout hops\n");
exit(3);
PHP
)

  if compose exec -T -e PIXEL_SM_QA_SELECTED_MAP_UID="$selected_map_uid" "$SERVICE_NAME" php -r "$trigger_code" > "$trigger_file" 2>&1; then
    MATRIX_MATCHMAKING_MAP_END_TRIGGERED=1
    log "Triggered selected-map end via dedicated nextMap (selected=${selected_map_uid}, artifact: ${trigger_file})"
    return 0
  fi

  MATRIX_MATCHMAKING_MAP_END_TRIGGERED=0
  warn "Failed to trigger selected-map end (artifact: ${trigger_file})"
  return 1
}

matrix_wait_for_matchmaking_lifecycle_completion() {
  local max_attempts="${1:-20}"
  local status_file="${RUN_DIR}/status.matchmaking.lifecycle.poll.json"
  local lifecycle_snapshot=""
  local lifecycle_status=""
  local lifecycle_stage=""
  local lifecycle_ready="0"
  local lifecycle_history_count="0"
  local attempt=1

  while [[ "$attempt" -le "$max_attempts" ]]; do
    run_status "$status_file"
    lifecycle_snapshot="$(read_matchmaking_lifecycle_snapshot "$status_file")"
    IFS=$'\t' read -r lifecycle_status lifecycle_stage lifecycle_ready lifecycle_history_count <<< "$lifecycle_snapshot"

    if [[ "$lifecycle_status" == "completed" && "$lifecycle_stage" == "ready_for_next_players" && "$lifecycle_ready" == "1" ]]; then
      return 0
    fi

    attempt=$((attempt + 1))
    sleep 1
  done

  return 1
}

run_matrix_action_specs() {
  local action_spec_file=""
  local spec_count=0

  if [[ ! -d "$MATRIX_ACTION_SPECS_DIR" ]]; then
    fail "Missing matrix action specs directory: ${MATRIX_ACTION_SPECS_DIR}"
  fi

  shopt -s nullglob
  for action_spec_file in "$MATRIX_ACTION_SPECS_DIR"/*.sh; do
    [[ -f "$action_spec_file" ]] || continue
    spec_count=$((spec_count + 1))
    # shellcheck source=/dev/null
    source "$action_spec_file"
  done
  shopt -u nullglob

  if [[ "$spec_count" -eq 0 ]]; then
    fail "No matrix action spec scripts found in ${MATRIX_ACTION_SPECS_DIR}"
  fi
}

write_matrix_context_file() {
  command -v python3 >/dev/null 2>&1 || fail "python3 command not found (required for matrix context artifact)."

  python3 - "$MATRIX_CONTEXT_FILE" "$TIMESTAMP" "$SERVICE_NAME" "$COMPOSE_FILES_CSV" "$COMM_HOST" "$COMM_PORT" "$COMM_SOCKET_ENABLED" "$MATRIX_CAPTAIN_A" "$MATRIX_CAPTAIN_B" "$MATRIX_MATCHMAKING_DURATION" "$MATRIX_TOURNAMENT_BEST_OF" "$MATRIX_TOURNAMENT_STARTER" "$MATRIX_TOURNAMENT_TIMEOUT" "$MATRIX_WAIT_EXTRA_SECONDS" "$MATRIX_MATCHMAKING_STARTED" "$MATRIX_MATCHMAKING_LIMITED_MODE" "$MATRIX_MATCHMAKING_MAP_END_TRIGGERED" "$MATRIX_TOURNAMENT_STARTED" "$MATRIX_TOURNAMENT_LIMITED_MODE" "$MATRIX_TOURNAMENT_GUARD" "$MATRIX_TOURNAMENT_MAX_STEPS" "$MATRIX_ASSERT_STRICT" "$MATRIX_ACTION_SPECS_DIR" <<'PY'
import json
import sys

output_file = sys.argv[1]

payload = {
    "schema": "pixel-sm-veto-matrix-context.v1",
    "timestamp": sys.argv[2],
    "service": sys.argv[3],
    "compose_files": sys.argv[4],
    "communication": {
        "host": sys.argv[5],
        "port": sys.argv[6],
        "socket_enabled": sys.argv[7],
    },
    "matrix_inputs": {
        "captain_a": sys.argv[8],
        "captain_b": sys.argv[9],
        "matchmaking_duration": int(sys.argv[10]),
        "tournament_best_of": int(sys.argv[11]),
        "tournament_starter": sys.argv[12],
        "tournament_timeout": int(sys.argv[13]),
        "wait_extra_seconds": int(sys.argv[14]),
        "matrix_assert_strict": sys.argv[22] == "1",
        "matrix_action_specs_dir": sys.argv[23],
    },
    "execution_flags": {
        "matchmaking_started": sys.argv[15] == "1",
        "matchmaking_limited_mode": sys.argv[16] == "1",
        "matchmaking_map_end_triggered": sys.argv[17] == "1",
        "tournament_started": sys.argv[18] == "1",
        "tournament_limited_mode": sys.argv[19] == "1",
        "tournament_actions_executed": int(sys.argv[20]),
        "tournament_guard_limit": int(sys.argv[21]),
    },
}

with open(output_file, "w", encoding="utf-8") as handle:
    json.dump(payload, handle, indent=2, ensure_ascii=True)
    handle.write("\n")
PY
}

validate_matrix_artifacts() {
  local manifest_file="$1"
  local run_dir="$2"
  local output_file="$3"

  command -v python3 >/dev/null 2>&1 || fail "python3 command not found (required for matrix validation)."

  python3 - "$manifest_file" "$run_dir" "$output_file" <<'PY'
import json
import os
import sys

manifest_file = sys.argv[1]
run_dir = sys.argv[2]
output_file = sys.argv[3]

METHOD_STATUS = "PixelControl.VetoDraft.Status"
METHOD_START = "PixelControl.VetoDraft.Start"
METHOD_ACTION = "PixelControl.VetoDraft.Action"
METHOD_CANCEL = "PixelControl.VetoDraft.Cancel"
METHOD_READY = "PixelControl.VetoDraft.Ready"

checks = []
compatibility_notes = []
errors = []


def add_check(check_id: str, passed: bool, detail: str, status: str = "passed", required: bool = True):
    checks.append(
        {
            "id": check_id,
            "required": required,
            "passed": bool(passed),
            "status": status,
            "detail": detail,
        }
    )


def parse_manifest(path: str):
    rows = []
    if not os.path.isfile(path):
        return rows

    with open(path, "r", encoding="utf-8") as handle:
        for raw_line in handle:
            line = raw_line.strip()
            if not line:
                continue
            try:
                payload = json.loads(line)
            except Exception:
                continue
            if isinstance(payload, dict):
                rows.append(payload)

    rows.sort(key=lambda row: int(row.get("index", 0)))
    return rows


manifest_rows = parse_manifest(manifest_file)
if not manifest_rows:
    errors.append("matrix manifest is empty or missing")

step_records = []
step_by_label = {}
method_stats = {
    "status": {"invoked": 0, "shape_failures": []},
    "start": {"invoked": 0, "shape_failures": []},
    "action": {"invoked": 0, "shape_failures": []},
    "cancel": {"invoked": 0, "shape_failures": []},
    "ready": {"invoked": 0, "shape_failures": []},
}
error_true_steps = []
transport_error_inconsistencies = []


def method_kind(method_name: str) -> str:
    if method_name == METHOD_STATUS:
        return "status"
    if method_name == METHOD_START:
        return "start"
    if method_name == METHOD_ACTION:
        return "action"
    if method_name == METHOD_CANCEL:
        return "cancel"
    if method_name == METHOD_READY:
        return "ready"
    return "unknown"


for row in manifest_rows:
    label = str(row.get("label") or "").strip()
    method_name = str(row.get("method") or "").strip()
    artifact = str(row.get("artifact") or "").strip()
    index = int(row.get("index") or 0)
    kind = method_kind(method_name)

    if kind == "unknown":
        errors.append(f"unknown method in manifest label={label} method={method_name}")
        continue

    method_stats[kind]["invoked"] += 1
    artifact_path = os.path.join(run_dir, artifact)

    step_info = {
        "index": index,
        "label": label,
        "method": method_name,
        "kind": kind,
        "artifact": artifact,
        "artifact_path": artifact_path,
        "payload": None,
        "shape_ok": True,
        "shape_errors": [],
        "data_success": None,
        "data_code": "",
    }

    if not os.path.isfile(artifact_path):
        step_info["shape_ok"] = False
        step_info["shape_errors"].append("artifact_missing")
        method_stats[kind]["shape_failures"].append({"label": label, "artifact": artifact, "reason": "artifact_missing"})
        step_records.append(step_info)
        step_by_label[label] = step_info
        continue

    try:
        with open(artifact_path, "r", encoding="utf-8") as handle:
            payload = json.load(handle)
    except Exception:
        payload = None

    if not isinstance(payload, dict):
        step_info["shape_ok"] = False
        step_info["shape_errors"].append("payload_not_object")
        method_stats[kind]["shape_failures"].append({"label": label, "artifact": artifact, "reason": "payload_not_object"})
        step_records.append(step_info)
        step_by_label[label] = step_info
        continue

    step_info["payload"] = payload

    if not isinstance(payload.get("error"), bool):
        step_info["shape_ok"] = False
        step_info["shape_errors"].append("missing_error_flag")
        method_stats[kind]["shape_failures"].append({"label": label, "artifact": artifact, "reason": "missing_error_flag"})
    elif payload.get("error"):
        error_true_steps.append({"label": label, "artifact": artifact})

    data = payload.get("data")
    if not isinstance(data, dict):
        step_info["shape_ok"] = False
        step_info["shape_errors"].append("missing_data_object")
        method_stats[kind]["shape_failures"].append({"label": label, "artifact": artifact, "reason": "missing_data_object"})
        step_records.append(step_info)
        step_by_label[label] = step_info
        continue

    if kind == "status":
        communication = data.get("communication")
        status_payload = data.get("status")
        matchmaking_lifecycle = data.get("matchmaking_lifecycle")
        session = status_payload.get("session") if isinstance(status_payload, dict) else None
        error_flag = payload.get("error")

        if error_flag is not False:
            transport_error_inconsistencies.append(
                {
                    "label": label,
                    "artifact": artifact,
                    "reason": "status_error_flag_not_false",
                    "error": error_flag,
                }
            )

        if not isinstance(communication, dict):
            step_info["shape_ok"] = False
            step_info["shape_errors"].append("status_missing_communication")
            method_stats[kind]["shape_failures"].append({"label": label, "artifact": artifact, "reason": "status_missing_communication"})
        else:
            required_comm_keys = ("start", "action", "status", "cancel", "ready")
            missing_comm_keys = [key for key in required_comm_keys if not str(communication.get(key) or "").strip()]
            if missing_comm_keys:
                step_info["shape_ok"] = False
                step_info["shape_errors"].append("status_missing_comm_keys")
                method_stats[kind]["shape_failures"].append(
                    {
                        "label": label,
                        "artifact": artifact,
                        "reason": "status_missing_comm_keys",
                        "missing_comm_keys": missing_comm_keys,
                    }
                )

        if not isinstance(status_payload, dict):
            step_info["shape_ok"] = False
            step_info["shape_errors"].append("status_missing_status_object")
            method_stats[kind]["shape_failures"].append({"label": label, "artifact": artifact, "reason": "status_missing_status_object"})
        else:
            if not isinstance(status_payload.get("active"), bool):
                step_info["shape_ok"] = False
                step_info["shape_errors"].append("status_active_not_bool")
                method_stats[kind]["shape_failures"].append({"label": label, "artifact": artifact, "reason": "status_active_not_bool"})
            if not isinstance(session, dict):
                step_info["shape_ok"] = False
                step_info["shape_errors"].append("status_missing_session")
                method_stats[kind]["shape_failures"].append({"label": label, "artifact": artifact, "reason": "status_missing_session"})
            else:
                if not str(session.get("status") or "").strip():
                    step_info["shape_ok"] = False
                    step_info["shape_errors"].append("status_session_status_missing")
                    method_stats[kind]["shape_failures"].append({"label": label, "artifact": artifact, "reason": "status_session_status_missing"})

        if not isinstance(matchmaking_lifecycle, dict):
            step_info["shape_ok"] = False
            step_info["shape_errors"].append("status_missing_matchmaking_lifecycle")
            method_stats[kind]["shape_failures"].append({"label": label, "artifact": artifact, "reason": "status_missing_matchmaking_lifecycle"})
        else:
            lifecycle_status = str(matchmaking_lifecycle.get("status") or "").strip()
            lifecycle_stage = str(matchmaking_lifecycle.get("stage") or "").strip()
            lifecycle_ready = matchmaking_lifecycle.get("ready_for_next_players")
            lifecycle_history = matchmaking_lifecycle.get("history")

            if lifecycle_status == "":
                step_info["shape_ok"] = False
                step_info["shape_errors"].append("status_matchmaking_lifecycle_status_missing")
                method_stats[kind]["shape_failures"].append({"label": label, "artifact": artifact, "reason": "status_matchmaking_lifecycle_status_missing"})

            if lifecycle_stage == "":
                step_info["shape_ok"] = False
                step_info["shape_errors"].append("status_matchmaking_lifecycle_stage_missing")
                method_stats[kind]["shape_failures"].append({"label": label, "artifact": artifact, "reason": "status_matchmaking_lifecycle_stage_missing"})

            if not isinstance(lifecycle_ready, bool):
                step_info["shape_ok"] = False
                step_info["shape_errors"].append("status_matchmaking_lifecycle_ready_not_bool")
                method_stats[kind]["shape_failures"].append({"label": label, "artifact": artifact, "reason": "status_matchmaking_lifecycle_ready_not_bool"})

            if not isinstance(lifecycle_history, list):
                step_info["shape_ok"] = False
                step_info["shape_errors"].append("status_matchmaking_lifecycle_history_not_list")
                method_stats[kind]["shape_failures"].append({"label": label, "artifact": artifact, "reason": "status_matchmaking_lifecycle_history_not_list"})

        if not isinstance(data.get("matchmaking_ready_armed"), bool):
            step_info["shape_ok"] = False
            step_info["shape_errors"].append("status_matchmaking_ready_armed_not_bool")
            method_stats[kind]["shape_failures"].append(
                {"label": label, "artifact": artifact, "reason": "status_matchmaking_ready_armed_not_bool"}
            )
    else:
        success = data.get("success")
        code = str(data.get("code") or "").strip()
        message = str(data.get("message") or "").strip()
        error_flag = payload.get("error")

        if not isinstance(success, bool):
            step_info["shape_ok"] = False
            step_info["shape_errors"].append("action_success_not_bool")
            method_stats[kind]["shape_failures"].append({"label": label, "artifact": artifact, "reason": "action_success_not_bool"})
        if not code:
            step_info["shape_ok"] = False
            step_info["shape_errors"].append("action_code_missing")
            method_stats[kind]["shape_failures"].append({"label": label, "artifact": artifact, "reason": "action_code_missing"})
        if not message:
            step_info["shape_ok"] = False
            step_info["shape_errors"].append("action_message_missing")
            method_stats[kind]["shape_failures"].append({"label": label, "artifact": artifact, "reason": "action_message_missing"})

        if isinstance(success, bool):
            step_info["data_success"] = success
            if isinstance(error_flag, bool) and error_flag != (not success):
                transport_error_inconsistencies.append(
                    {
                        "label": label,
                        "artifact": artifact,
                        "reason": "error_flag_mismatch_success",
                        "error": error_flag,
                        "success": success,
                        "code": code,
                    }
                )
        step_info["data_code"] = code

    step_records.append(step_info)
    step_by_label[label] = step_info


def get_step(label: str):
    return step_by_label.get(label)


def expect_action_code(label: str, expected_success, expected_code):
    row = get_step(label)
    if row is None:
        return False, f"missing step {label}"
    if not row.get("shape_ok", False):
        return False, f"step {label} has invalid shape"
    success = row.get("data_success")
    code = str(row.get("data_code") or "")
    if success is not expected_success or code != expected_code:
        return False, f"step {label} expected success={expected_success} code={expected_code} got success={success} code={code}"
    return True, "ok"


def expect_action_code_any(label: str, expected_success, expected_codes):
    row = get_step(label)
    if row is None:
        return False, f"missing step {label}"
    if not row.get("shape_ok", False):
        return False, f"step {label} has invalid shape"
    success = row.get("data_success")
    code = str(row.get("data_code") or "")
    if success is not expected_success or code not in expected_codes:
        return False, f"step {label} expected success={expected_success} code in {sorted(expected_codes)} got success={success} code={code}"
    return True, "ok"


def find_labels(prefix: str):
    return sorted(label for label in step_by_label.keys() if label.startswith(prefix))


def status_step_current_team(label: str):
    row = get_step(label)
    if row is None:
        return ""
    payload = row.get("payload") if isinstance(row, dict) else None
    if not isinstance(payload, dict):
        return ""
    data = payload.get("data")
    if not isinstance(data, dict):
        return ""
    status_payload = data.get("status")
    if not isinstance(status_payload, dict):
        return ""
    session = status_payload.get("session")
    if not isinstance(session, dict):
        return ""
    current_step = session.get("current_step")
    if not isinstance(current_step, dict):
        return ""
    return str(current_step.get("team") or "").strip()


def status_step_matchmaking_lifecycle(label: str):
    row = get_step(label)
    if row is None:
        return None
    payload = row.get("payload") if isinstance(row, dict) else None
    if not isinstance(payload, dict):
        return None
    data = payload.get("data")
    if not isinstance(data, dict):
        return None
    lifecycle_payload = data.get("matchmaking_lifecycle")
    if not isinstance(lifecycle_payload, dict):
        return None
    return lifecycle_payload


def status_step_matchmaking_ready_armed(label: str):
    row = get_step(label)
    if row is None:
        return None
    payload = row.get("payload") if isinstance(row, dict) else None
    if not isinstance(payload, dict):
        return None
    data = payload.get("data")
    if not isinstance(data, dict):
        return None
    return data.get("matchmaking_ready_armed")


def has_stage_sequence(history_rows, expected_stages):
    if not isinstance(history_rows, list) or not history_rows:
        return False

    cursor = 0
    for entry in history_rows:
        if not isinstance(entry, dict):
            continue
        stage_name = str(entry.get("stage") or "").strip()
        if stage_name == "":
            continue
        if cursor < len(expected_stages) and stage_name == expected_stages[cursor]:
            cursor += 1
            if cursor == len(expected_stages):
                return True

    return cursor == len(expected_stages)


for kind in ("status", "start", "action", "cancel", "ready"):
    invoked = method_stats[kind]["invoked"]
    shape_failures = method_stats[kind]["shape_failures"]
    passed = invoked > 0 and not shape_failures
    detail = f"invoked={invoked}, shape_failures={len(shape_failures)}"
    add_check(f"method.{kind}.response_shape", passed, detail)

communication_ok = len(transport_error_inconsistencies) == 0
add_check(
    "transport.communication_error_flag",
    communication_ok,
    "error flag is consistent with method semantics" if communication_ok else "error flag mismatches detected",
)

matchmaking_started = False
matchmaking_limited = False
tournament_started = False
tournament_limited = False

ok, detail = expect_action_code("start.matchmaking.not_ready", False, "matchmaking_ready_required")
add_check("negative.matchmaking.ready_required_initial", ok, detail, status="passed" if ok else "failed")

ready_arm_ok, ready_arm_detail = expect_action_code_any(
    "ready.matchmaking.arm",
    True,
    {"matchmaking_ready_armed", "matchmaking_ready_already_armed"},
)
add_check("flow.matchmaking.ready_arm", ready_arm_ok, ready_arm_detail, status="passed" if ready_arm_ok else "failed")

ready_status_value = status_step_matchmaking_ready_armed("status.matchmaking.after_ready")
ready_status_ok = ready_status_value is True
add_check(
    "flow.matchmaking.ready_status",
    ready_status_ok,
    f"status.matchmaking.after_ready.matchmaking_ready_armed={ready_status_value}",
    status="passed" if ready_status_ok else "failed",
)

start_matchmaking = get_step("start.matchmaking")
if start_matchmaking is None:
    add_check("flow.matchmaking.start", False, "missing start.matchmaking step", status="failed")
else:
    success = start_matchmaking.get("data_success")
    code = start_matchmaking.get("data_code")
    if success is True and code == "matchmaking_started":
        matchmaking_started = True
        add_check("flow.matchmaking.start", True, "start.matchmaking returned matchmaking_started")
    elif success is False and code == "map_pool_too_small":
        matchmaking_limited = True
        compatibility_notes.append("matchmaking limited by map pool size")
        add_check("flow.matchmaking.start", True, "non-elite compatibility map_pool_too_small", status="compatibility")
    else:
        add_check("flow.matchmaking.start", False, f"unexpected start.matchmaking result success={success} code={code}", status="failed")

ok, detail = expect_action_code_any("action.no_session.initial", False, {"tournament_not_running", "session_not_running"})
add_check("negative.action.session_not_running", ok, detail, status="passed" if ok else "failed")

cancel_initial_ok, cancel_initial_detail = expect_action_code("cancel.no_session.initial", False, "session_not_running")
cancel_post_ok, cancel_post_detail = expect_action_code("cancel.no_session.post_matchmaking", False, "session_not_running")
cancel_missing_ok = cancel_initial_ok and cancel_post_ok
cancel_missing_detail = f"initial={cancel_initial_detail}; post_matchmaking={cancel_post_detail}"
add_check("negative.cancel.session_not_running", cancel_missing_ok, cancel_missing_detail, status="passed" if cancel_missing_ok else "failed")

if matchmaking_started:
    ok, detail = expect_action_code_any(
        "start.matchmaking.conflict",
        False,
        {"session_active", "matchmaking_ready_required"},
    )
    add_check("negative.start.session_active", ok, detail, status="passed" if ok else "failed")

    vote_labels = [
        "action.matchmaking.vote.voter_a",
        "action.matchmaking.vote.voter_b",
        "action.matchmaking.vote.voter_c",
    ]
    failures = []
    for vote_label in vote_labels:
        vote_ok, vote_detail = expect_action_code(vote_label, True, "vote_recorded")
        if not vote_ok:
            failures.append(vote_detail)
    add_check(
        "flow.matchmaking.votes",
        len(failures) == 0,
        "all vote actions returned vote_recorded" if not failures else "; ".join(failures),
        status="passed" if not failures else "failed",
    )
else:
    add_check("negative.start.session_active", True, "not applicable (matchmaking did not start)", status="compatibility")
    add_check("flow.matchmaking.votes", True, "not applicable (matchmaking did not start)", status="compatibility")

if matchmaking_started:
    lifecycle_after_map_end = status_step_matchmaking_lifecycle("status.matchmaking.lifecycle.after_map_end")
    if lifecycle_after_map_end is None:
        add_check(
            "flow.matchmaking.lifecycle.sequence",
            False,
            "missing status.matchmaking.lifecycle.after_map_end step",
            status="failed",
        )
        add_check(
            "flow.matchmaking.lifecycle.ready_state",
            False,
            "missing lifecycle snapshot after map-end trigger",
            status="failed",
        )
    else:
        lifecycle_status = str(lifecycle_after_map_end.get("status") or "")
        lifecycle_stage = str(lifecycle_after_map_end.get("stage") or "")
        lifecycle_ready = lifecycle_after_map_end.get("ready_for_next_players")
        lifecycle_history = lifecycle_after_map_end.get("history")

        expected_lifecycle_stages = [
            "veto_completed",
            "selected_map_loaded",
            "match_started",
            "selected_map_finished",
            "players_removed",
            "map_changed",
            "match_ended",
            "ready_for_next_players",
        ]
        sequence_ok = has_stage_sequence(lifecycle_history, expected_lifecycle_stages)
        add_check(
            "flow.matchmaking.lifecycle.sequence",
            sequence_ok,
            "observed full lifecycle stage sequence"
            if sequence_ok
            else f"incomplete lifecycle sequence (status={lifecycle_status}, stage={lifecycle_stage})",
            status="passed" if sequence_ok else "failed",
        )

        ready_ok = lifecycle_status == "completed" and lifecycle_stage == "ready_for_next_players" and lifecycle_ready is True
        add_check(
            "flow.matchmaking.lifecycle.ready_state",
            ready_ok,
            f"status={lifecycle_status} stage={lifecycle_stage} ready={lifecycle_ready}",
            status="passed" if ready_ok else "failed",
        )
else:
    add_check("flow.matchmaking.lifecycle.sequence", True, "not applicable (matchmaking did not start)", status="compatibility")
    add_check("flow.matchmaking.lifecycle.ready_state", True, "not applicable (matchmaking did not start)", status="compatibility")

if matchmaking_started:
    ok, detail = expect_action_code("start.matchmaking.post_cycle_without_ready", False, "matchmaking_ready_required")
    add_check("negative.matchmaking.ready_required_post_cycle", ok, detail, status="passed" if ok else "failed")

    ready_rearm_ok, ready_rearm_detail = expect_action_code_any(
        "ready.matchmaking.rearm",
        True,
        {"matchmaking_ready_armed", "matchmaking_ready_already_armed"},
    )
    add_check("flow.matchmaking.ready_rearm", ready_rearm_ok, ready_rearm_detail, status="passed" if ready_rearm_ok else "failed")

    rearmed_vote_ok, rearmed_vote_detail = expect_action_code("action.matchmaking.vote.rearmed_bootstrap", True, "vote_recorded")
    add_check(
        "flow.matchmaking.rearmed_vote_bootstrap",
        rearmed_vote_ok,
        rearmed_vote_detail,
        status="passed" if rearmed_vote_ok else "failed",
    )
else:
    add_check("negative.matchmaking.ready_required_post_cycle", True, "not applicable (matchmaking did not start)", status="compatibility")
    add_check("flow.matchmaking.ready_rearm", True, "not applicable (matchmaking did not start)", status="compatibility")
    add_check("flow.matchmaking.rearmed_vote_bootstrap", True, "not applicable (matchmaking did not start)", status="compatibility")

ok, detail = expect_action_code("start.tournament.captain_missing", False, "captain_missing")
add_check("negative.tournament.captain_missing", ok, detail, status="passed" if ok else "failed")

ok, detail = expect_action_code("start.tournament.captain_conflict", False, "captain_conflict")
add_check("negative.tournament.captain_conflict", ok, detail, status="passed" if ok else "failed")

start_tournament = get_step("start.tournament")
if start_tournament is None:
    add_check("flow.tournament.start", False, "missing start.tournament step", status="failed")
else:
    success = start_tournament.get("data_success")
    code = start_tournament.get("data_code")
    if success is True and code == "tournament_started":
        tournament_started = True
        add_check("flow.tournament.start", True, "start.tournament returned tournament_started")
    elif success is False and code == "map_pool_too_small_for_bo":
        tournament_limited = True
        compatibility_notes.append("tournament limited by map pool size for best-of")
        add_check("flow.tournament.start", True, "non-elite compatibility map_pool_too_small_for_bo", status="compatibility")
    else:
        add_check("flow.tournament.start", False, f"unexpected start.tournament result success={success} code={code}", status="failed")

if tournament_started:
    tournament_initial_team = status_step_current_team("status.tournament.loop_0")
    invalid_actor_required = tournament_initial_team in {"team_a", "team_b"}

    if invalid_actor_required:
        ok, detail = expect_action_code("action.tournament.invalid_actor", False, "actor_not_allowed")
        add_check("negative.tournament.actor_not_allowed", ok, detail, status="passed" if ok else "failed")
    else:
        if tournament_initial_team == "system":
            compatibility_notes.append("tournament initial step team=system; actor restriction check not applicable")
        add_check(
            "negative.tournament.actor_not_allowed",
            True,
            f"not applicable (initial_team={tournament_initial_team or 'unknown'})",
            status="compatibility",
        )

    tournament_action_labels = find_labels("action.tournament.step_")
    action_failures = []

    if not tournament_action_labels:
        if invalid_actor_required:
            action_failures.append("no action.tournament.step_* artifacts")
        else:
            legacy_step = get_step("action.tournament.invalid_actor")
            legacy_success = legacy_step.get("data_success") if isinstance(legacy_step, dict) else None
            legacy_code = str(legacy_step.get("data_code") or "") if isinstance(legacy_step, dict) else ""
            if legacy_success is True and legacy_code == "tournament_action_applied":
                compatibility_notes.append("legacy invalid_actor step consumed tournament action under system turn")
            else:
                action_failures.append("no action.tournament.step_* artifacts")

    for action_label in tournament_action_labels:
        action_ok, action_detail = expect_action_code(action_label, True, "tournament_action_applied")
        if not action_ok:
            action_failures.append(action_detail)

    tournament_actions_status = "failed"
    tournament_actions_detail = "; ".join(action_failures)
    if not action_failures:
        if tournament_action_labels:
            tournament_actions_status = "passed"
            tournament_actions_detail = f"actions={len(tournament_action_labels)}"
        else:
            tournament_actions_status = "compatibility"
            tournament_actions_detail = "not applicable (system-driven tournament step without action.tournament.step_* artifacts)"

    add_check(
        "flow.tournament.actions",
        len(action_failures) == 0,
        tournament_actions_detail,
        status=tournament_actions_status,
    )
else:
    add_check("negative.tournament.actor_not_allowed", True, "not applicable (tournament did not start)", status="compatibility")
    add_check("flow.tournament.actions", True, "not applicable (tournament did not start)", status="compatibility")

status_final = get_step("status.final")
if status_final is None:
    add_check("flow.tournament.final_status", False, "missing status.final step", status="failed")
else:
    payload = status_final.get("payload") if isinstance(status_final, dict) else None
    data = payload.get("data") if isinstance(payload, dict) else None
    status_payload = data.get("status") if isinstance(data, dict) else None
    session = status_payload.get("session") if isinstance(status_payload, dict) else None

    if not isinstance(status_payload, dict) or not isinstance(session, dict):
        add_check("flow.tournament.final_status", False, "status.final payload missing status/session", status="failed")
    elif tournament_started:
        active = status_payload.get("active")
        mode = str(status_payload.get("mode") or "")
        session_status = str(session.get("status") or "")

        passed = active is False and mode == "tournament_draft" and session_status in {"completed", "cancelled"}
        detail = f"active={active} mode={mode} session_status={session_status}"
        add_check("flow.tournament.final_status", passed, detail, status="passed" if passed else "failed")
    else:
        add_check("flow.tournament.final_status", True, "status.final captured without active tournament flow", status="compatibility")

required_failed = [check["id"] for check in checks if check.get("required") and not check.get("passed")]
overall_passed = len(required_failed) == 0 and len(errors) == 0

result = {
    "schema": "pixel-sm-veto-matrix-validation.v1",
    "manifest_file": manifest_file,
    "run_dir": run_dir,
    "step_count": len(step_records),
    "checks": checks,
    "required_failed_checks": required_failed,
    "compatibility_notes": compatibility_notes,
    "errors": errors,
    "method_shape": method_stats,
    "error_true_steps": error_true_steps,
    "transport_error_inconsistencies": transport_error_inconsistencies,
    "overall_passed": overall_passed,
}

with open(output_file, "w", encoding="utf-8") as handle:
    json.dump(result, handle, indent=2, ensure_ascii=True)
    handle.write("\n")

if not overall_passed:
    raise SystemExit(1)
PY
}

run_matrix() {
  local validation_passed=0

  MATRIX_SUMMARY_FILE="${RUN_DIR}/summary.md"
  MATRIX_MANIFEST_FILE="${RUN_DIR}/matrix-step-manifest.ndjson"
  MATRIX_VALIDATION_FILE="${RUN_DIR}/matrix-validation.json"
  MATRIX_CONTEXT_FILE="${RUN_DIR}/matrix-context.json"
  MATRIX_STEP_INDEX=0
  MATRIX_LAST_RESPONSE_FILE=""
  MATRIX_LAST_COMMUNICATION_ERROR=""
  MATRIX_LAST_ACTION_SUCCESS=""
  MATRIX_LAST_ACTION_CODE=""

  MATRIX_STATUS_ACTIVE="0"
  MATRIX_STATUS_MODE=""
  MATRIX_STATUS_SESSION_STATUS=""
  MATRIX_STATUS_CURRENT_TEAM=""

  MATRIX_MATCHMAKING_STARTED=0
  MATRIX_MATCHMAKING_LIMITED_MODE=0
  MATRIX_MATCHMAKING_MAP_END_TRIGGERED=0
  MATRIX_TOURNAMENT_STARTED=0
  MATRIX_TOURNAMENT_LIMITED_MODE=0
  MATRIX_TOURNAMENT_GUARD=0

  : > "$MATRIX_MANIFEST_FILE"

  {
    printf '# Veto Payload Simulation Matrix\n\n'
    printf -- '- Timestamp: `%s`\n' "$TIMESTAMP"
    printf -- '- Service: `%s`\n' "$SERVICE_NAME"
    printf -- '- Compose files: `%s`\n' "$COMPOSE_FILES_CSV"
    printf -- '- Communication target: `%s:%s`\n' "$COMM_HOST" "$COMM_PORT"
    printf -- '- Socket enabled setting: `%s`\n' "${COMM_SOCKET_ENABLED:-unknown}"
    printf -- '- Matrix action specs dir: `%s`\n' "$MATRIX_ACTION_SPECS_DIR"
    printf -- '- Matrix strict assertions: `%s`\n' "$MATRIX_ASSERT_STRICT"
    printf -- '- Captain A: `%s`\n' "$MATRIX_CAPTAIN_A"
    printf -- '- Captain B: `%s`\n' "$MATRIX_CAPTAIN_B"
    printf -- '- Matchmaking duration: `%s`\n' "$MATRIX_MATCHMAKING_DURATION"
    printf -- '- Tournament BO: `%s`\n' "$MATRIX_TOURNAMENT_BEST_OF"
    printf -- '- Tournament starter: `%s`\n' "$MATRIX_TOURNAMENT_STARTER"
    printf -- '- Tournament action timeout: `%s`\n\n' "$MATRIX_TOURNAMENT_TIMEOUT"
    printf '| # | Step | Communication Error | Action Success | Action Code | Artifact |\n'
    printf '| - | ---- | ------------------- | -------------- | ----------- | -------- |\n'
  } > "$MATRIX_SUMMARY_FILE"

  if [[ "$MATRIX_CAPTAIN_A" == "__pixel_captain_a__" || "$MATRIX_CAPTAIN_B" == "__pixel_captain_b__" ]]; then
    warn "Captain placeholders are active; tournament start may fail until valid logins are provided."
  fi

  run_matrix_action_specs
  write_matrix_context_file

  if validate_matrix_artifacts "$MATRIX_MANIFEST_FILE" "$RUN_DIR" "$MATRIX_VALIDATION_FILE"; then
    validation_passed=1
  else
    validation_passed=0
  fi

  {
    printf '\n## Validation artifacts\n\n'
    printf -- '- Step manifest: `%s`\n' "$(basename "$MATRIX_MANIFEST_FILE")"
    printf -- '- Matrix context: `%s`\n' "$(basename "$MATRIX_CONTEXT_FILE")"
    printf -- '- Matrix validation: `%s`\n' "$(basename "$MATRIX_VALIDATION_FILE")"
    printf -- '- Validation passed: `%s`\n' "$( [ "$validation_passed" -eq 1 ] && printf 'true' || printf 'false' )"
  } >> "$MATRIX_SUMMARY_FILE"

  if [[ "$validation_passed" -ne 1 && "$MATRIX_ASSERT_STRICT" == "1" ]]; then
    fail "Matrix strict validation failed. Inspect ${MATRIX_VALIDATION_FILE}."
  fi

  if [[ "$validation_passed" -ne 1 ]]; then
    warn "Matrix validation reported failures but strict mode is disabled (PIXEL_SM_VETO_SIM_MATRIX_ASSERT_STRICT=${MATRIX_ASSERT_STRICT})."
  fi

  log "Matrix simulation complete."
  log "Summary: ${MATRIX_SUMMARY_FILE}"
  log "Matrix validation: ${MATRIX_VALIDATION_FILE}"
}

parse_kv_assignments() {
  local token
  local key
  local value

  for token in "$@"; do
    if [[ "$token" != *=* ]]; then
      fail "Invalid argument (expected key=value): $token"
    fi

    key="${token%%=*}"
    value="${token#*=}"

    if [[ -z "$key" ]]; then
      fail "Invalid assignment (empty key): $token"
    fi

    case "$key" in
      captain_a)
        MATRIX_CAPTAIN_A="$value"
        ;;
      captain_b)
        MATRIX_CAPTAIN_B="$value"
        ;;
      voter_a)
        MATRIX_VOTER_A="$value"
        ;;
      voter_b)
        MATRIX_VOTER_B="$value"
        ;;
      voter_c)
        MATRIX_VOTER_C="$value"
        ;;
      matchmaking_duration)
        MATRIX_MATCHMAKING_DURATION="$value"
        ;;
      tournament_best_of)
        MATRIX_TOURNAMENT_BEST_OF="$value"
        ;;
      tournament_starter)
        MATRIX_TOURNAMENT_STARTER="$value"
        ;;
      tournament_timeout)
        MATRIX_TOURNAMENT_TIMEOUT="$value"
        ;;
      wait_extra_seconds)
        MATRIX_WAIT_EXTRA_SECONDS="$value"
        ;;
      action_specs_dir)
        MATRIX_ACTION_SPECS_DIR="$value"
        ;;
      matrix_assert_strict)
        MATRIX_ASSERT_STRICT="$value"
        ;;
      comm_host)
        COMM_HOST="$value"
        ;;
      comm_port)
        COMM_PORT="$value"
        ;;
      comm_password)
        COMM_PASSWORD="$value"
        ;;
      service_name)
        SERVICE_NAME="$value"
        ;;
      compose_files)
        COMPOSE_FILES_CSV="$value"
        ;;
      env_file)
        ENV_FILE="$value"
        ;;
      *)
        fail "Unknown assignment key: $key"
        ;;
    esac
  done
}

main() {
  local command="${1:-}"
  local output_file=""
  local -a command_pairs=()

  case "$command" in
    ''|-h|--help|help)
      usage
      exit 0
      ;;
  esac

  shift || true

  if [[ "$command" == "matrix" && "$#" -gt 0 ]]; then
    parse_kv_assignments "$@"
    set --
  fi

  while IFS= read -r compose_arg; do
    COMPOSE_FILE_ARGS+=("$compose_arg")
  done < <(build_compose_file_args "$COMPOSE_FILES_CSV")

  ensure_prerequisites
  resolve_socket_settings

  mkdir -p "$RUN_DIR"

  log "Artifacts directory: $RUN_DIR"
  log "Communication target: ${COMM_HOST}:${COMM_PORT}"

  case "$command" in
    status)
      output_file="${RUN_DIR}/status.json"
      run_status "$output_file"
      log "Status response: ${output_file}"
      cat "$output_file"
      ;;
    start)
      if [[ "$#" -gt 0 ]]; then
        command_pairs=("$@")
      fi
      output_file="${RUN_DIR}/start.json"
      run_start "$output_file" "${command_pairs[@]}"
      log "Start response: ${output_file}"
      cat "$output_file"
      ;;
    action)
      if [[ "$#" -gt 0 ]]; then
        command_pairs=("$@")
      fi
      output_file="${RUN_DIR}/action.json"
      run_action "$output_file" "${command_pairs[@]}"
      log "Action response: ${output_file}"
      cat "$output_file"
      ;;
    cancel)
      if [[ "$#" -gt 0 ]]; then
        command_pairs=("$@")
      fi
      output_file="${RUN_DIR}/cancel.json"
      run_cancel "$output_file" "${command_pairs[@]}"
      log "Cancel response: ${output_file}"
      cat "$output_file"
      ;;
    ready)
      output_file="${RUN_DIR}/ready.json"
      run_ready "$output_file"
      log "Ready response: ${output_file}"
      cat "$output_file"
      ;;
    matrix)
      run_matrix
      ;;
    *)
      fail "Unknown command: $command"
      ;;
  esac
}

main "$@"
