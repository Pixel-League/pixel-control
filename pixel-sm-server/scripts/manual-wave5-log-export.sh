#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${PROJECT_DIR}/.env"

MANUAL_DIR="${PIXEL_SM_MANUAL_LOG_DIR:-${PROJECT_DIR}/logs/manual/wave5-real-client-$(date +%Y%m%d)}"
SESSION_ID="${PIXEL_SM_MANUAL_SESSION_ID:-session-001}"
COMPOSE_FILES="${PIXEL_SM_QA_COMPOSE_FILES:-docker-compose.yml}"

compose_file_args=()

log() {
  printf '[pixel-sm-manual-logs] %s\n' "$1"
}

usage() {
  cat <<'EOF'
Usage:
  bash scripts/manual-wave5-log-export.sh [--manual-dir DIR] [--session-id ID] [--compose-files CSV]

Options:
  --manual-dir    Manual evidence directory (default: logs/manual/wave5-real-client-<date>)
  --session-id    Session id used for output filenames (default: session-001)
  --compose-files Comma-separated compose files (default: docker-compose.yml)
EOF
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
  IFS=',' read -r -a requested_compose_files <<< "$COMPOSE_FILES"

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
    log "No compose files resolved from: ${COMPOSE_FILES}"
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

while [ "$#" -gt 0 ]; do
  case "$1" in
    --manual-dir)
      if [ "$#" -lt 2 ]; then
        log "Missing value for --manual-dir"
        exit 1
      fi
      MANUAL_DIR="$2"
      shift 2
      ;;
    --session-id)
      if [ "$#" -lt 2 ]; then
        log "Missing value for --session-id"
        exit 1
      fi
      SESSION_ID="$2"
      shift 2
      ;;
    --compose-files)
      if [ "$#" -lt 2 ]; then
        log "Missing value for --compose-files"
        exit 1
      fi
      COMPOSE_FILES="$2"
      shift 2
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      log "Unknown option: $1"
      usage
      exit 1
      ;;
  esac
done

if ! command -v docker >/dev/null 2>&1; then
  log "Missing command: docker"
  exit 1
fi

if [ ! -f "$ENV_FILE" ]; then
  log "Missing .env file at ${ENV_FILE}"
  exit 1
fi

mkdir -p "$MANUAL_DIR"
build_compose_file_args

runtime_source="$(read_env_value "$ENV_FILE" "PIXEL_SM_RUNTIME_SOURCE")"
if [ -z "$runtime_source" ]; then
  runtime_source="./runtime/server"
fi

runtime_log_file="$(resolve_path "$runtime_source")/ManiaControl/ManiaControl.log"
shootmania_export_file="${MANUAL_DIR}/SESSION-${SESSION_ID}-shootmania.log"
maniacontrol_export_file="${MANUAL_DIR}/SESSION-${SESSION_ID}-maniacontrol.log"

if [ -f "$runtime_log_file" ]; then
  cp "$runtime_log_file" "$maniacontrol_export_file"
  log "Exported ManiaControl log: ${maniacontrol_export_file}"
else
  log "ManiaControl log not found at: ${runtime_log_file}"
fi

if compose ps -q shootmania >/dev/null 2>&1; then
  compose logs --no-color shootmania > "$shootmania_export_file" || true
  log "Exported shootmania logs: ${shootmania_export_file}"
else
  log "Unable to resolve shootmania service via compose files: ${COMPOSE_FILES}"
fi

log "Manual log export completed for session: ${SESSION_ID}"
