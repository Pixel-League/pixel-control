#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${PROJECT_DIR}/.env"
DEV_COMPOSE_FILES="${PIXEL_SM_DEV_COMPOSE_FILES:-docker-compose.yml}"
DEV_BUILD_IMAGES="${PIXEL_SM_DEV_MODE_BUILD_IMAGES:-0}"
MODE_NAME="${1:-}"
ACTION_NAME="${2:-relaunch}"
compose_file_args=()

log() {
  printf '[pixel-sm-dev-mode] %s\n' "$1"
}

usage() {
  cat <<'EOF'
Usage:
  bash scripts/dev-mode-compose.sh <mode> [launch|relaunch]

Examples:
  bash scripts/dev-mode-compose.sh elite
  bash scripts/dev-mode-compose.sh joust relaunch
  PIXEL_SM_DEV_COMPOSE_FILES=docker-compose.yml,docker-compose.host.yml bash scripts/dev-mode-compose.sh elite launch

Notes:
  - Expects a matching mode profile file: .env.<mode> (for example .env.elite)
  - Copies .env.<mode> to .env before docker compose
  - Action defaults to 'relaunch'
  - Set PIXEL_SM_DEV_MODE_BUILD_IMAGES=1 to force --build
EOF
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

print_available_modes() {
  printed_any=0
  for profile_file in "${PROJECT_DIR}"/.env.*; do
    if [ ! -f "$profile_file" ]; then
      continue
    fi

    profile_name="$(basename "$profile_file")"
    case "$profile_name" in
      .env.example)
        continue
        ;;
    esac

    printf '  - %s\n' "${profile_name#.env.}"
    printed_any=1
  done

  if [ "$printed_any" -eq 0 ]; then
    printf '  (none)\n'
  fi
}

if [ -z "$MODE_NAME" ]; then
  usage
  exit 1
fi

case "$ACTION_NAME" in
  launch|relaunch)
    ;;
  *)
    log "Unsupported action: ${ACTION_NAME}"
    usage
    exit 1
    ;;
esac

MODE_ENV_FILE="${PROJECT_DIR}/.env.${MODE_NAME}"
if [ ! -f "$MODE_ENV_FILE" ]; then
  log "Missing mode profile: ${MODE_ENV_FILE}"
  log "Available mode profiles:"
  print_available_modes
  exit 1
fi

require_command docker

build_compose_file_args

cp "$MODE_ENV_FILE" "$ENV_FILE"

resolved_mode="$(read_env_value "$ENV_FILE" "PIXEL_SM_MODE")"
resolved_title_pack="$(read_env_value "$ENV_FILE" "PIXEL_SM_TITLE_PACK")"
log "Applied profile: ${MODE_ENV_FILE}"
log "Resolved mode=${resolved_mode:-unknown} title_pack=${resolved_title_pack:-unknown}"
log "Using compose files: ${DEV_COMPOSE_FILES}"

compose_args=(up -d)
if [ "$DEV_BUILD_IMAGES" = "1" ]; then
  compose_args+=(--build)
fi

case "$ACTION_NAME" in
  launch)
    compose_args+=(mysql shootmania)
    log "Launching mysql + shootmania"
    ;;
  relaunch)
    compose_args+=(--force-recreate shootmania)
    log "Relaunching shootmania"
    ;;
esac

compose "${compose_args[@]}"

log "Done. Current .env now points to mode profile '${MODE_NAME}'."
