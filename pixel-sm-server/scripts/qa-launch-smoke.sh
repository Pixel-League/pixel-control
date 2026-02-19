#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${PROJECT_DIR}/.env"
QA_COMPOSE_FILES="${PIXEL_SM_QA_COMPOSE_FILES:-docker-compose.yml}"
QA_WAIT_SECONDS="${PIXEL_SM_QA_WAIT_SECONDS:-30}"
QA_PLUGIN_WAIT_SECONDS="${PIXEL_SM_QA_PLUGIN_WAIT_SECONDS:-45}"
QA_HEALTH_TIMEOUT_SECONDS="${PIXEL_SM_QA_HEALTH_TIMEOUT_SECONDS:-90}"
QA_XMLRPC_WAIT_SECONDS="${PIXEL_SM_QA_XMLRPC_WAIT_SECONDS:-45}"
QA_BUILD_IMAGES="${PIXEL_SM_QA_BUILD_IMAGES:-1}"
QA_ARTIFACT_DIR="${PIXEL_SM_QA_ARTIFACT_DIR:-${PROJECT_DIR}/logs/qa}"
QA_TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
QA_BUILD_LOG_FILE="${QA_ARTIFACT_DIR}/qa-build-${QA_TIMESTAMP}.log"
QA_STARTUP_LOG_FILE="${QA_ARTIFACT_DIR}/qa-startup-${QA_TIMESTAMP}.log"
QA_XMLRPC_PORT="${PIXEL_SM_QA_XMLRPC_PORT:-55000}"
QA_GAME_PORT="${PIXEL_SM_QA_GAME_PORT:-55100}"
QA_P2P_PORT="${PIXEL_SM_QA_P2P_PORT:-55200}"
QA_ENV_FILE="${QA_ARTIFACT_DIR}/qa-env-${QA_TIMESTAMP}.env"
QA_BUILD_RESULT_LABEL="$QA_BUILD_LOG_FILE"
compose_file_args=()

mkdir -p "$QA_ARTIFACT_DIR"

log() {
  printf '[pixel-sm-qa] %s\n' "$1"
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

require_command() {
  command_name="$1"
  if ! command -v "$command_name" >/dev/null 2>&1; then
    log "Missing command: ${command_name}"
    exit 1
  fi
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
  docker compose --ansi never "${compose_file_args[@]}" --env-file "$QA_ENV_FILE" "$@"
}

cleanup() {
  log "Stopping smoke test stack"
  compose down >/dev/null 2>&1 || true
}

require_log_line() {
  log_file="$1"
  pattern="$2"
  description="$3"
  if ! grep -Fq "$pattern" "$log_file"; then
    log "Missing expected log: ${description}"
    log "Search pattern: ${pattern}"
    log "Captured container logs: ${log_file}"
    exit 1
  fi
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

assert_not_reference_path() {
  candidate_path="$1"
  resolved_candidate_path="$(resolve_path "$candidate_path")"

  case "$resolved_candidate_path" in
    */ressources/*)
      log "Refusing runtime path inside ressources/: ${resolved_candidate_path}"
      log "Copy runtime into pixel-sm-server/runtime/server (or another gitignored local sandbox) first."
      exit 1
      ;;
  esac
}

wait_for_service_health() {
  service_name="$1"
  timeout_seconds="$2"
  elapsed_seconds=0

  log "Waiting for ${service_name} healthcheck (${timeout_seconds}s timeout)"
  while [ "$elapsed_seconds" -lt "$timeout_seconds" ]; do
    container_id="$(compose ps -q "$service_name" || true)"
    if [ -n "$container_id" ]; then
      health_status="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$container_id" 2>/dev/null || true)"
      case "$health_status" in
        healthy)
          return 0
          ;;
        unhealthy)
          log "Service ${service_name} reported unhealthy"
          return 1
          ;;
      esac
    fi

    sleep 2
    elapsed_seconds=$((elapsed_seconds + 2))
  done

  log "Timed out waiting for ${service_name} healthcheck"
  return 1
}

wait_for_local_tcp_port() {
  port="$1"
  timeout_seconds="$2"
  elapsed_seconds=0

  log "Waiting for local TCP port ${port} (${timeout_seconds}s timeout)"
  while [ "$elapsed_seconds" -lt "$timeout_seconds" ]; do
    if bash -c "exec 3<>/dev/tcp/127.0.0.1/${port}" >/dev/null 2>&1; then
      return 0
    fi

    sleep 2
    elapsed_seconds=$((elapsed_seconds + 2))
  done

  log "Timed out waiting for local TCP port ${port}"
  return 1
}

require_command docker

if [ ! -f "$ENV_FILE" ]; then
  log "Missing .env file at ${ENV_FILE}. Create it from .env.example before QA launch."
  exit 1
fi

build_compose_file_args

trap cleanup EXIT

log "Using compose files: ${QA_COMPOSE_FILES}"

cp "$ENV_FILE" "$QA_ENV_FILE"
cat >> "$QA_ENV_FILE" <<EOF
PIXEL_SM_XMLRPC_PORT=${QA_XMLRPC_PORT}
PIXEL_SM_GAME_PORT=${QA_GAME_PORT}
PIXEL_SM_P2P_PORT=${QA_P2P_PORT}
EOF

runtime_source="$(read_env_value "$QA_ENV_FILE" "PIXEL_SM_RUNTIME_SOURCE")"
if [ -z "$runtime_source" ]; then
  runtime_source="./runtime/server"
fi

assert_not_reference_path "$runtime_source"
runtime_log_file="$(resolve_path "$runtime_source")/ManiaControl/ManiaControl.log"

if [ "$QA_BUILD_IMAGES" = "1" ]; then
  log "Building images for smoke validation"
  if ! BUILDKIT_PROGRESS=plain compose build > "$QA_BUILD_LOG_FILE" 2>&1; then
    log "Image build failed. Inspect: ${QA_BUILD_LOG_FILE}"
    exit 1
  fi
else
  QA_BUILD_RESULT_LABEL="skipped (PIXEL_SM_QA_BUILD_IMAGES=0)"
fi

log "Launching stack for smoke validation"
if ! compose up -d > "$QA_STARTUP_LOG_FILE" 2>&1; then
  log "Compose startup failed. Inspect: ${QA_STARTUP_LOG_FILE}"
  exit 1
fi

log "Waiting ${QA_WAIT_SECONDS}s for bootstrap"
sleep "$QA_WAIT_SECONDS"

container_log_file="${QA_ARTIFACT_DIR}/qa-shootmania-${QA_TIMESTAMP}.log"

if ! wait_for_service_health "shootmania" "$QA_HEALTH_TIMEOUT_SECONDS"; then
  compose logs --no-color shootmania > "$container_log_file" || true
  log "Captured container logs: ${container_log_file}"
  exit 1
fi

if ! wait_for_local_tcp_port "$QA_XMLRPC_PORT" "$QA_XMLRPC_WAIT_SECONDS"; then
  compose logs --no-color shootmania > "$container_log_file" || true
  log "Captured container logs: ${container_log_file}"
  exit 1
fi

compose logs --no-color shootmania > "$container_log_file" || true

require_log_line "$container_log_file" "Step 3/5: syncing Pixel Control plugin" "plugin sync step"
require_log_line "$container_log_file" "Pixel Control plugin synchronized" "plugin sync confirmation"
require_log_line "$container_log_file" "Step 4/5: starting ManiaControl" "ManiaControl startup step"
require_log_line "$container_log_file" "Mode script confirmed in runtime" "mode script availability confirmation"
require_log_line "$container_log_file" "...Match settings loaded" "dedicated server matchsettings load"
require_log_line "$container_log_file" "Title pack asset confirmed" "title pack availability confirmation"
log "Checking Pixel Control load marker in ${runtime_log_file}"

deadline_epoch=$(( $(date +%s) + QA_PLUGIN_WAIT_SECONDS ))
while [ "$(date +%s)" -le "$deadline_epoch" ]; do
  if [ -f "$runtime_log_file" ] && grep -Fq "[PixelControl] Plugin loaded." "$runtime_log_file"; then
    log "Pixel Control plugin load marker found"
    log "Build log: ${QA_BUILD_RESULT_LABEL}"
    log "Startup log: ${QA_STARTUP_LOG_FILE}"
    log "Shootmania log: ${container_log_file}"
    log "QA env file: ${QA_ENV_FILE}"
    log "Smoke launch validation passed"
    exit 0
  fi
  sleep 2
done

log "Pixel Control load marker not found within ${QA_PLUGIN_WAIT_SECONDS}s"
log "This usually means ManiaControl did not fully initialize yet or runtime credentials are invalid."
log "Captured container logs: ${container_log_file}"
if [ -f "$runtime_log_file" ]; then
  log "Inspect ManiaControl logs at: ${runtime_log_file}"
else
  log "ManiaControl log file was not created at expected path"
fi
exit 1
