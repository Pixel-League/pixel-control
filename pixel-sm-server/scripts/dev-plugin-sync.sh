#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${PROJECT_DIR}/.env"
DEV_COMPOSE_FILES="${PIXEL_SM_DEV_COMPOSE_FILES:-docker-compose.yml}"
DEV_HEALTH_TIMEOUT_SECONDS="${PIXEL_SM_DEV_HEALTH_TIMEOUT_SECONDS:-90}"
DEV_PLUGIN_WAIT_SECONDS="${PIXEL_SM_DEV_PLUGIN_WAIT_SECONDS:-45}"
DEV_ARTIFACT_DIR="${PIXEL_SM_DEV_ARTIFACT_DIR:-${PROJECT_DIR}/logs/dev}"
DEV_TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
DEV_SHOOTMANIA_LOG_FILE="${DEV_ARTIFACT_DIR}/dev-plugin-sync-shootmania-${DEV_TIMESTAMP}.log"
compose_file_args=()

log() {
  printf '[pixel-sm-dev-sync] %s\n' "$1"
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
  IFS=',' read -r -a requested_compose_files <<< "$DEV_COMPOSE_FILES"

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
    log "No compose files resolved from PIXEL_SM_DEV_COMPOSE_FILES='${DEV_COMPOSE_FILES}'"
    exit 1
  fi
}

compose() {
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

require_log_line() {
  log_file="$1"
  pattern="$2"
  description="$3"
  if ! grep -Fq "$pattern" "$log_file"; then
    log "Missing expected log: ${description}"
    log "Search pattern: ${pattern}"
    log "Captured shootmania logs: ${log_file}"
    exit 1
  fi
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

require_command docker

if [ ! -f "$ENV_FILE" ]; then
  log "Missing .env file at ${ENV_FILE}. Create it from .env.example before plugin sync."
  exit 1
fi

mkdir -p "$DEV_ARTIFACT_DIR"
build_compose_file_args

runtime_source="$(read_env_value "$ENV_FILE" "PIXEL_SM_RUNTIME_SOURCE")"
if [ -z "$runtime_source" ]; then
  runtime_source="./runtime/server"
fi

assert_not_reference_path "$runtime_source"
runtime_log_file="$(resolve_path "$runtime_source")/ManiaControl/ManiaControl.log"

log "Using compose files: ${DEV_COMPOSE_FILES}"
log "Ensuring mysql + shootmania services are up (no rebuild)"
compose up -d mysql shootmania >/dev/null

log "Restarting shootmania to apply plugin source changes"
compose restart shootmania >/dev/null

if ! wait_for_service_health "shootmania" "$DEV_HEALTH_TIMEOUT_SECONDS"; then
  compose logs --no-color shootmania > "$DEV_SHOOTMANIA_LOG_FILE" || true
  log "Captured shootmania logs: ${DEV_SHOOTMANIA_LOG_FILE}"
  exit 1
fi

compose logs --no-color shootmania > "$DEV_SHOOTMANIA_LOG_FILE" || true
require_log_line "$DEV_SHOOTMANIA_LOG_FILE" "Step 3/5: syncing Pixel Control plugin" "plugin sync step"
require_log_line "$DEV_SHOOTMANIA_LOG_FILE" "Pixel Control plugin synchronized" "plugin sync confirmation"
log "Checking Pixel Control load marker in ${runtime_log_file}"

deadline_epoch=$(( $(date +%s) + DEV_PLUGIN_WAIT_SECONDS ))
while [ "$(date +%s)" -le "$deadline_epoch" ]; do
  if [ -f "$runtime_log_file" ] && grep -Fq "[PixelControl] Plugin loaded." "$runtime_log_file"; then
    log "Pixel Control plugin load marker found"
    log "Shootmania log: ${DEV_SHOOTMANIA_LOG_FILE}"
    log "Plugin fast sync completed"
    exit 0
  fi
  sleep 2
done

log "Pixel Control load marker not found within ${DEV_PLUGIN_WAIT_SECONDS}s"
log "Captured shootmania logs: ${DEV_SHOOTMANIA_LOG_FILE}"
if [ -f "$runtime_log_file" ]; then
  log "Inspect ManiaControl logs at: ${runtime_log_file}"
fi
exit 1
