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
DEV_SHOOTMANIA_LOG_FILE="${DEV_ARTIFACT_DIR}/dev-plugin-hot-sync-shootmania-${DEV_TIMESTAMP}.log"
DEV_MANIACONTROL_LOG_FILE="${DEV_ARTIFACT_DIR}/dev-plugin-hot-sync-maniacontrol-${DEV_TIMESTAMP}.log"
compose_file_args=()

log() {
  printf '[pixel-sm-dev-hot-sync] %s\n' "$1"
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

require_log_line() {
  log_file="$1"
  pattern="$2"
  description="$3"
  if ! grep -Fq "$pattern" "$log_file"; then
    log "Missing expected log: ${description}"
    log "Search pattern: ${pattern}"
    log "Captured ManiaControl logs: ${log_file}"
    exit 1
  fi
}

require_command docker

if [ ! -f "$ENV_FILE" ]; then
  log "Missing .env file at ${ENV_FILE}. Create it from .env.example before plugin hot sync."
  exit 1
fi

mkdir -p "$DEV_ARTIFACT_DIR"
build_compose_file_args

runtime_source="$(read_env_value "$ENV_FILE" "PIXEL_SM_RUNTIME_SOURCE")"
if [ -z "$runtime_source" ]; then
  runtime_source="./runtime/server"
fi

plugin_source="$(read_env_value "$ENV_FILE" "PIXEL_CONTROL_PLUGIN_SOURCE")"
if [ -z "$plugin_source" ]; then
  plugin_source="../pixel-control-plugin/src"
fi

assert_not_reference_path "$runtime_source"
assert_not_reference_path "$plugin_source"

runtime_log_file="$(resolve_path "$runtime_source")/ManiaControl/ManiaControl.log"
plugin_source_file="$(resolve_path "$plugin_source")/PixelControlPlugin.php"

if [ ! -f "$plugin_source_file" ]; then
  log "Missing plugin source file: ${plugin_source_file}"
  exit 1
fi

log "Using compose files: ${DEV_COMPOSE_FILES}"
log "Ensuring mysql + shootmania services are up (no rebuild, no recreate)"
compose up -d --no-recreate mysql shootmania >/dev/null

if ! wait_for_service_health "shootmania" "$DEV_HEALTH_TIMEOUT_SECONDS"; then
  compose logs --no-color shootmania > "$DEV_SHOOTMANIA_LOG_FILE" || true
  log "Captured shootmania logs: ${DEV_SHOOTMANIA_LOG_FILE}"
  exit 1
fi

log "Synchronizing plugin source into running container"
compose exec -T shootmania bash -lc '
set -euo pipefail
plugin_source_root="/opt/pixel-sm/pixel-control-plugin-src"
plugin_target_root="/opt/pixel-sm/runtime/server/ManiaControl/plugins/PixelControl"

if [ ! -f "${plugin_source_root}/PixelControlPlugin.php" ]; then
  echo "Missing plugin source at ${plugin_source_root}/PixelControlPlugin.php" >&2
  exit 1
fi

mkdir -p "$plugin_target_root"
cp -R "${plugin_source_root}/." "$plugin_target_root/"

if [ ! -f "${plugin_target_root}/PixelControlPlugin.php" ]; then
  echo "Plugin sync failed at ${plugin_target_root}" >&2
  exit 1
fi
'

dedicated_pid_before="$(compose exec -T shootmania bash -lc 'pgrep -fo "ManiaPlanetServer /nodaemon" || true')"

log "Restarting ManiaControl process only (dedicated server stays up)"
compose exec -T shootmania bash -lc '
set -euo pipefail
find_maniacontrol_pid() {
  ps -eo pid=,stat=,args= | awk '"'"'$2 !~ /^Z/ && index($0, "ManiaControl.php") > 0 { print $1; exit }'"'"'
}

maniacontrol_pid="$(find_maniacontrol_pid || true)"
if [ -n "$maniacontrol_pid" ]; then
  kill "$maniacontrol_pid" || true
  sleep 1
  if kill -0 "$maniacontrol_pid" >/dev/null 2>&1; then
    kill -9 "$maniacontrol_pid" || true
  fi
fi

cd /opt/pixel-sm/runtime/server/ManiaControl
if [ -f "ManiaControl.php" ]; then
  php ManiaControl.php > ManiaControl.log 2>&1 &
else
  echo "Missing ManiaControl entrypoint" >&2
  exit 1
fi

new_maniacontrol_pid=""
for _ in 1 2 3 4 5 6 7 8 9 10; do
  new_maniacontrol_pid="$(find_maniacontrol_pid || true)"
  if [ -n "$new_maniacontrol_pid" ]; then
    break
  fi
  sleep 1
done

if [ -z "$new_maniacontrol_pid" ]; then
  echo "Warning: ManiaControl PID not detected yet; continuing with load-marker check." >&2
fi
'

dedicated_pid_after="$(compose exec -T shootmania bash -lc 'pgrep -fo "ManiaPlanetServer /nodaemon" || true')"

if [ -n "$dedicated_pid_before" ] && [ -n "$dedicated_pid_after" ] && [ "$dedicated_pid_before" = "$dedicated_pid_after" ]; then
  log "Dedicated server PID unchanged (${dedicated_pid_after})"
else
  log "Dedicated server PID changed (${dedicated_pid_before} -> ${dedicated_pid_after})"
  log "This run may have restarted dedicated unexpectedly; inspect shootmania logs."
fi

log "Checking Pixel Control load marker in ${runtime_log_file}"
deadline_epoch=$(( $(date +%s) + DEV_PLUGIN_WAIT_SECONDS ))
while [ "$(date +%s)" -le "$deadline_epoch" ]; do
  if [ -f "$runtime_log_file" ] && grep -Fq "[PixelControl] Plugin loaded." "$runtime_log_file"; then
    compose logs --no-color shootmania > "$DEV_SHOOTMANIA_LOG_FILE" || true
    if [ -f "$runtime_log_file" ]; then
      tail -n 400 "$runtime_log_file" > "$DEV_MANIACONTROL_LOG_FILE" || true
    fi
    log "Pixel Control plugin load marker found"
    log "Shootmania log: ${DEV_SHOOTMANIA_LOG_FILE}"
    log "ManiaControl tail log: ${DEV_MANIACONTROL_LOG_FILE}"
    log "Plugin hot sync completed (no shootmania container restart)"
    exit 0
  fi

  sleep 2
done

compose logs --no-color shootmania > "$DEV_SHOOTMANIA_LOG_FILE" || true
if [ -f "$runtime_log_file" ]; then
  tail -n 400 "$runtime_log_file" > "$DEV_MANIACONTROL_LOG_FILE" || true
fi
log "Pixel Control load marker not found within ${DEV_PLUGIN_WAIT_SECONDS}s"
log "Captured shootmania logs: ${DEV_SHOOTMANIA_LOG_FILE}"
if [ -f "$runtime_log_file" ]; then
  log "Captured ManiaControl tail log: ${DEV_MANIACONTROL_LOG_FILE}"
fi
exit 1
