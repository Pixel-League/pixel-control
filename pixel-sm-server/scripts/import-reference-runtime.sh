#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

log() {
  printf '[pixel-sm-import-runtime] %s\n' "$1"
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

assert_not_reference_path() {
  candidate_path="$1"
  resolved_candidate_path="$(resolve_path "$candidate_path")"

  case "$resolved_candidate_path" in
    */ressources/*)
      log "Refusing to write under reference tree: ${resolved_candidate_path}"
      log "Use pixel-sm-server/runtime/server (or another gitignored local sandbox) as target."
      exit 1
      ;;
  esac
}

source_runtime_input="${1:-../ressources/maniaplanet-server-with-docker/server}"
target_runtime_input="${2:-./runtime/server}"
force_import="${PIXEL_SM_IMPORT_FORCE:-0}"

source_runtime="$(resolve_path "$source_runtime_input")"
target_runtime="$(resolve_path "$target_runtime_input")"

if [ ! -d "$source_runtime" ]; then
  log "Reference runtime directory not found: ${source_runtime}"
  log "Usage: bash scripts/import-reference-runtime.sh [source-runtime-dir] [target-runtime-dir]"
  exit 1
fi

assert_not_reference_path "$target_runtime_input"

if [ "$force_import" != "1" ] && [ -d "$target_runtime" ] && [ -n "$(ls -A "$target_runtime" 2>/dev/null)" ]; then
  log "Target runtime already has files: ${target_runtime}"
  log "Set PIXEL_SM_IMPORT_FORCE=1 to replace existing files."
  exit 1
fi

mkdir -p "$target_runtime"

if [ "$force_import" = "1" ]; then
  find "$target_runtime" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
fi

cp -R "${source_runtime}/." "$target_runtime/"

rm -f "${target_runtime}/ManiaControl/ManiaControl.log" "${target_runtime}/ManiaControl/ManiaControl.pid"
rm -rf "${target_runtime}/Logs" "${target_runtime}/ManiaControl/logs" "${target_runtime}/ManiaControl/backup"

log "Runtime copied into: ${target_runtime}"
log "You can now use PIXEL_SM_RUNTIME_SOURCE=${target_runtime_input} safely without mutating ressources/."
