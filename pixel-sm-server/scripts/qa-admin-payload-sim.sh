#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

ENV_FILE="${PIXEL_SM_ADMIN_SIM_ENV_FILE:-${PROJECT_DIR}/.env}"
COMPOSE_FILES_CSV="${PIXEL_SM_ADMIN_SIM_COMPOSE_FILES:-${PIXEL_SM_QA_COMPOSE_FILES:-docker-compose.yml}}"
SERVICE_NAME="${PIXEL_SM_ADMIN_SIM_SERVICE_NAME:-shootmania}"

COMM_HOST="${PIXEL_SM_ADMIN_SIM_COMM_HOST:-127.0.0.1}"
COMM_PORT="${PIXEL_SM_ADMIN_SIM_COMM_PORT:-}"
COMM_PASSWORD="${PIXEL_SM_ADMIN_SIM_COMM_PASSWORD:-}"
COMM_SOCKET_ENABLED=""

METHOD_EXECUTE="PixelControl.Admin.ExecuteAction"
METHOD_LIST="PixelControl.Admin.ListActions"

OUTPUT_ROOT="${PROJECT_DIR}/logs/qa"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
RUN_DIR="${OUTPUT_ROOT}/admin-payload-sim-${TIMESTAMP}"

MATRIX_ACTOR_LOGIN="${PIXEL_SM_ADMIN_SIM_ACTOR_LOGIN:-}"
MATRIX_TARGET_LOGIN="${PIXEL_SM_ADMIN_SIM_TARGET_LOGIN:-__pixel_target_login__}"
MATRIX_MAP_UID="${PIXEL_SM_ADMIN_SIM_MAP_UID:-__pixel_map_uid__}"
MATRIX_MX_ID="${PIXEL_SM_ADMIN_SIM_MX_ID:-__pixel_mx_id__}"
MATRIX_TEAM="${PIXEL_SM_ADMIN_SIM_TEAM:-blue}"
MATRIX_AUTH_LEVEL="${PIXEL_SM_ADMIN_SIM_AUTH_LEVEL:-admin}"
MATRIX_VOTE_COMMAND="${PIXEL_SM_ADMIN_SIM_VOTE_COMMAND:-nextmap}"
MATRIX_VOTE_RATIO="${PIXEL_SM_ADMIN_SIM_VOTE_RATIO:-0.60}"
MATRIX_VOTE_INDEX="${PIXEL_SM_ADMIN_SIM_VOTE_INDEX:-0}"

COMPOSE_FILE_ARGS=()

log() {
  printf '[qa-admin-payload-sim] %s\n' "$*"
}

warn() {
  printf '[qa-admin-payload-sim][warn] %s\n' "$*" >&2
}

fail() {
  printf '[qa-admin-payload-sim][error] %s\n' "$*" >&2
  exit 1
}

usage() {
  cat <<'USAGE'
Usage:
  bash scripts/qa-admin-payload-sim.sh list-actions
  bash scripts/qa-admin-payload-sim.sh execute <action> [key=value ...]
  bash scripts/qa-admin-payload-sim.sh matrix [key=value ...]

Commands:
  list-actions
      Calls PixelControl.Admin.ListActions through ManiaControl communication socket.

  execute <action> [key=value ...]
      Sends one PixelControl.Admin.ExecuteAction payload.
      Special key:
        actor_login=<login>   (optional, top-level payload field)
      Other key=value entries are sent under payload.parameters.

  matrix [key=value ...]
      Replays all canonical delegated admin actions with sample payloads.
      Optional overrides:
        actor_login=<login>
        target_login=<login>
        map_uid=<uid>
        mx_id=<maniaexchange_map_id>
        team=<red|blue|0|1>
        auth_level=<player|moderator|admin|superadmin>
        vote_command=<command>
        vote_ratio=<0..1>
        vote_index=<int>

Env overrides:
  PIXEL_SM_ADMIN_SIM_ENV_FILE
  PIXEL_SM_ADMIN_SIM_COMPOSE_FILES (CSV, default falls back to PIXEL_SM_QA_COMPOSE_FILES or docker-compose.yml)
  PIXEL_SM_ADMIN_SIM_SERVICE_NAME (default: shootmania)
  PIXEL_SM_ADMIN_SIM_COMM_HOST (default: 127.0.0.1)
  PIXEL_SM_ADMIN_SIM_COMM_PORT (default: auto from mc_settings, else 31501)
  PIXEL_SM_ADMIN_SIM_COMM_PASSWORD (default: auto from mc_settings, else empty string)
  PIXEL_SM_ADMIN_SIM_MX_ID (default placeholder for matrix map.add simulation)

Notes:
  - This tool simulates server-originated payloads to Pixel Control admin communication methods.
  - Current plugin security mode for payload path is temporary/untrusted by design.
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

  local running_services
  running_services="$(compose ps --status running --services 2>/dev/null || true)"
  if ! printf '%s\n' "$running_services" | grep -Fxq "$SERVICE_NAME"; then
    fail "Service '$SERVICE_NAME' is not running. Start stack first (e.g. docker compose up -d --build)."
  fi
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

build_execute_payload_json() {
  local action_name="$1"
  local actor_login="$2"
  shift 2
  local pair
  local key
  local value
  local parameters_json="{"
  local first=1
  local payload_json

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
      parameters_json+=","
    fi
    first=0

    parameters_json+="\"$(json_escape "$key")\":"
    parameters_json+="$(json_value "$value")"
  done

  parameters_json+="}"

  payload_json="{\"action\":\"$(json_escape "$action_name")\",\"parameters\":${parameters_json}"
  if [[ -n "$actor_login" ]]; then
    payload_json+=",\"actor_login\":\"$(json_escape "$actor_login")\""
  fi
  payload_json+="}"

  printf '%s' "$payload_json"
}

invoke_communication_method() {
  local method_name="$1"
  local payload_json="$2"
  local output_file="$3"
  local payload_b64

  payload_b64="$(printf '%s' "$payload_json" | base64 | tr -d '\n')"

  compose exec -T \
    -e PIXEL_SM_ADMIN_SIM_COMM_HOST="$COMM_HOST" \
    -e PIXEL_SM_ADMIN_SIM_COMM_PORT="$COMM_PORT" \
    -e PIXEL_SM_ADMIN_SIM_COMM_PASSWORD="$COMM_PASSWORD" \
    -e PIXEL_SM_ADMIN_SIM_METHOD="$method_name" \
    -e PIXEL_SM_ADMIN_SIM_DATA_B64="$payload_b64" \
    "$SERVICE_NAME" php <<'PHP' > "$output_file"
<?php
$host = getenv('PIXEL_SM_ADMIN_SIM_COMM_HOST') ?: '127.0.0.1';
$port = (int) (getenv('PIXEL_SM_ADMIN_SIM_COMM_PORT') ?: '31501');
$password = (string) getenv('PIXEL_SM_ADMIN_SIM_COMM_PASSWORD');
$method = (string) getenv('PIXEL_SM_ADMIN_SIM_METHOD');
$dataB64 = (string) getenv('PIXEL_SM_ADMIN_SIM_DATA_B64');

if ($method === '') {
    fwrite(STDERR, "Missing method name.\n");
    exit(2);
}

$decodedJson = base64_decode($dataB64, true);
if ($decodedJson === false) {
    fwrite(STDERR, "Invalid base64 payload data.\n");
    exit(2);
}

$data = json_decode($decodedJson, true);
if ($decodedJson !== '' && $data === null && json_last_error() !== JSON_ERROR_NONE) {
    fwrite(STDERR, "Invalid JSON payload: " . json_last_error_msg() . "\n");
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
    fwrite(STDERR, "Failed to encode request JSON.\n");
    exit(2);
}

$encrypted = openssl_encrypt($request, 'aes-192-cbc', $password, OPENSSL_RAW_DATA, 'kZ2Kt0CzKUjN2MJX');
if ($encrypted === false) {
    fwrite(STDERR, "OpenSSL encryption failed.\n");
    exit(3);
}

$errno = 0;
$errstr = '';
$socket = @fsockopen($host, $port, $errno, $errstr, 5.0);
if (!$socket) {
    fwrite(STDERR, "Socket connect failed to {$host}:{$port} ({$errno} {$errstr})\n");
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
        fwrite(STDERR, "Socket write failed.\n");
        exit(5);
    }
    $written += $bytes;
}

$lengthLine = fgets($socket);
if ($lengthLine === false) {
    fclose($socket);
    fwrite(STDERR, "Socket read failed (length prefix missing).\n");
    exit(6);
}

$expectedLength = (int) trim($lengthLine);
if ($expectedLength <= 0) {
    fclose($socket);
    fwrite(STDERR, "Invalid response length prefix: {$lengthLine}\n");
    exit(6);
}

$responseEncrypted = '';
while (strlen($responseEncrypted) < $expectedLength && !feof($socket)) {
    $chunk = fread($socket, $expectedLength - strlen($responseEncrypted));
    if ($chunk === false) {
        fclose($socket);
        fwrite(STDERR, "Socket read failed while receiving encrypted response.\n");
        exit(7);
    }
    if ($chunk === '') {
        $meta = stream_get_meta_data($socket);
        if (!empty($meta['timed_out'])) {
            fclose($socket);
            fwrite(STDERR, "Socket read timeout while receiving encrypted response.\n");
            exit(7);
        }
        continue;
    }
    $responseEncrypted .= $chunk;
}

fclose($socket);

if (strlen($responseEncrypted) !== $expectedLength) {
    fwrite(STDERR, "Incomplete encrypted response payload.\n");
    exit(7);
}

$responseJson = openssl_decrypt($responseEncrypted, 'aes-192-cbc', $password, OPENSSL_RAW_DATA, 'kZ2Kt0CzKUjN2MJX');
if ($responseJson === false) {
    fwrite(STDERR, "OpenSSL decrypt failed (check socket password).\n");
    exit(8);
}

$response = json_decode($responseJson, true);
if (!is_array($response)) {
    fwrite(STDERR, "Invalid response JSON from communication socket.\n");
    exit(9);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
PHP
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

sanitize_action_name() {
  local action_name="$1"
  action_name="${action_name//./-}"
  action_name="${action_name//\//-}"
  action_name="${action_name//:/-}"
  action_name="${action_name// /-}"
  printf '%s' "$action_name"
}

run_list_actions() {
  local output_file="$1"
  invoke_communication_method "$METHOD_LIST" '{}' "$output_file"
}

run_execute_action() {
  local action_name="$1"
  local actor_login="$2"
  local output_file="$3"
  shift 3
  local payload_json
  payload_json="$(build_execute_payload_json "$action_name" "$actor_login" "$@")"
  invoke_communication_method "$METHOD_EXECUTE" "$payload_json" "$output_file"
}

run_matrix() {
  local summary_file="${RUN_DIR}/summary.md"
  local list_file="${RUN_DIR}/list-actions.json"
  local index=0

  run_list_actions "$list_file"

  {
    printf '# Admin Payload Simulation Matrix\n\n'
    printf -- '- Timestamp: `%s`\n' "$TIMESTAMP"
    printf -- '- Service: `%s`\n' "$SERVICE_NAME"
    printf -- '- Compose files: `%s`\n' "$COMPOSE_FILES_CSV"
    printf -- '- Comm host/port: `%s:%s`\n' "$COMM_HOST" "$COMM_PORT"
    printf -- '- Comm password source: `%s`\n' "$( [[ -n "${PIXEL_SM_ADMIN_SIM_COMM_PASSWORD:-}" ]] && printf '%s' 'env_override' || printf '%s' 'mc_settings_or_empty' )"
    printf -- '- Socket enabled setting: `%s`\n' "${COMM_SOCKET_ENABLED:-unknown}"

    printf -- '- Matrix actor login: `%s`\n' "${MATRIX_ACTOR_LOGIN:-<actorless>}"
    printf -- '- Matrix target login: `%s`\n' "$MATRIX_TARGET_LOGIN"
    printf -- '- Matrix map uid: `%s`\n' "$MATRIX_MAP_UID"
    printf -- '- Matrix mx id: `%s`\n\n' "$MATRIX_MX_ID"
    printf '| # | Action | Communication Error | Action Success | Action Code | Artifact |\n'
    printf '| - | ------ | ------------------- | -------------- | ----------- | -------- |\n'
  } > "$summary_file"

  matrix_step() {
    local action_name="$1"
    shift
    local safe_action
    local response_file
    local summary_triplet
    local communication_error
    local action_success
    local action_code

    index=$((index + 1))
    safe_action="$(sanitize_action_name "$action_name")"
    response_file="${RUN_DIR}/execute-$(printf '%02d' "$index")-${safe_action}.json"

    run_execute_action "$action_name" "$MATRIX_ACTOR_LOGIN" "$response_file" "$@"

    summary_triplet="$(extract_response_triplet "$response_file")"
    communication_error="${summary_triplet%%$'\t'*}"
    summary_triplet="${summary_triplet#*$'\t'}"
    action_success="${summary_triplet%%$'\t'*}"
    action_code="${summary_triplet#*$'\t'}"

    printf '| %s | `%s` | `%s` | `%s` | `%s` | `%s` |\n' \
      "$index" \
      "$action_name" \
      "$communication_error" \
      "$action_success" \
      "$action_code" \
      "$(basename "$response_file")" \
      >> "$summary_file"
  }

  if [[ "$MATRIX_TARGET_LOGIN" == "__pixel_target_login__" ]]; then
    warn "target_login is using placeholder value. Player/auth force actions may return target errors."
  fi
  if [[ "$MATRIX_MAP_UID" == "__pixel_map_uid__" ]]; then
    warn "map_uid is using placeholder value. map.jump/map.queue/map.remove may return native_rejected."
  fi
  if [[ "$MATRIX_MX_ID" == "__pixel_mx_id__" ]]; then
    warn "mx_id is using placeholder value. map.add may return missing/invalid parameter errors."
  fi

  matrix_step 'map.skip'
  matrix_step 'map.restart'
  matrix_step 'map.jump' "map_uid=${MATRIX_MAP_UID}"
  matrix_step 'map.queue' "map_uid=${MATRIX_MAP_UID}"
  matrix_step 'map.add' "mx_id=${MATRIX_MX_ID}"
  matrix_step 'map.remove' "map_uid=${MATRIX_MAP_UID}"
  matrix_step 'warmup.extend' 'seconds=30'
  matrix_step 'warmup.end'
  matrix_step 'pause.start'
  matrix_step 'pause.end'
  matrix_step 'vote.cancel'
  matrix_step 'vote.set_ratio' "command=${MATRIX_VOTE_COMMAND}" "ratio=${MATRIX_VOTE_RATIO}"
  matrix_step 'vote.custom_start' "vote_index=${MATRIX_VOTE_INDEX}"
  matrix_step 'player.force_team' "target_login=${MATRIX_TARGET_LOGIN}" "team=${MATRIX_TEAM}"
  matrix_step 'player.force_play' "target_login=${MATRIX_TARGET_LOGIN}"
  matrix_step 'player.force_spec' "target_login=${MATRIX_TARGET_LOGIN}"
  matrix_step 'auth.grant' "target_login=${MATRIX_TARGET_LOGIN}" "auth_level=${MATRIX_AUTH_LEVEL}"
  matrix_step 'auth.revoke' "target_login=${MATRIX_TARGET_LOGIN}"

  log "Matrix simulation complete."
  log "Summary: ${summary_file}"
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
      actor_login)
        MATRIX_ACTOR_LOGIN="$value"
        ;;
      target_login)
        MATRIX_TARGET_LOGIN="$value"
        ;;
      map_uid)
        MATRIX_MAP_UID="$value"
        ;;
      mx_id)
        MATRIX_MX_ID="$value"
        ;;
      team)
        MATRIX_TEAM="$value"
        ;;
      auth_level)
        MATRIX_AUTH_LEVEL="$value"
        ;;
      vote_command)
        MATRIX_VOTE_COMMAND="$value"
        ;;
      vote_ratio)
        MATRIX_VOTE_RATIO="$value"
        ;;
      vote_index)
        MATRIX_VOTE_INDEX="$value"
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
  local list_file=""
  local action_name=""
  local response_file=""
  local actor_login=""
  local pair
  local -a exec_param_pairs=()

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
    list-actions)
      list_file="${RUN_DIR}/list-actions.json"
      run_list_actions "$list_file"
      log "List actions response: ${list_file}"
      cat "$list_file"
      ;;
    execute)
      action_name="${1:-}"
      if [[ -z "$action_name" ]]; then
        fail "Missing action name. Usage: execute <action> [key=value ...]"
      fi
      shift || true

      actor_login="$MATRIX_ACTOR_LOGIN"
      for pair in "$@"; do
        if [[ "$pair" != *=* ]]; then
          fail "Invalid execute argument (expected key=value): $pair"
        fi
        if [[ "${pair%%=*}" == "actor_login" ]]; then
          actor_login="${pair#*=}"
          continue
        fi
        exec_param_pairs+=("$pair")
      done

      response_file="${RUN_DIR}/execute-$(sanitize_action_name "$action_name").json"
      if [[ -n "${exec_param_pairs[*]:-}" ]]; then
        run_execute_action "$action_name" "$actor_login" "$response_file" "${exec_param_pairs[@]}"
      else
        run_execute_action "$action_name" "$actor_login" "$response_file"
      fi
      log "Execute response: ${response_file}"
      cat "$response_file"

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
