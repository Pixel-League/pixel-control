#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
SMOKE_SCRIPT="${PROJECT_DIR}/scripts/qa-launch-smoke.sh"
TITLEPACK_FETCH_SCRIPT="${PROJECT_DIR}/scripts/fetch-titlepack.sh"

if [ ! -f "$SMOKE_SCRIPT" ]; then
  printf '[pixel-sm-qa-modes] Missing smoke script: %s\n' "$SMOKE_SCRIPT"
  exit 1
fi

if [ ! -f "$TITLEPACK_FETCH_SCRIPT" ]; then
  printf '[pixel-sm-qa-modes] Missing titlepack helper: %s\n' "$TITLEPACK_FETCH_SCRIPT"
  exit 1
fi

log() {
  printf '[pixel-sm-qa-modes] %s\n' "$1"
}

require_file() {
  candidate_file="$1"
  if [ ! -f "$candidate_file" ]; then
    log "Missing required file: ${candidate_file}"
    exit 1
  fi
}

run_smoke_case() {
  case_name="$1"
  shift

  log "Running ${case_name} smoke"
  env "$@" bash "$SMOKE_SCRIPT"
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
  usage_label="$2"
  resolved_candidate_path="$(resolve_path "$candidate_path")"

  case "$resolved_candidate_path" in
    */ressources/*)
      log "Refusing ${usage_label} inside ressources/: ${resolved_candidate_path}"
      log "Copy assets into pixel-sm-server/ first (or another gitignored local sandbox)."
      exit 1
      ;;
  esac
}

ensure_battle_titlepack() {
  expected_titlepack_id="${PIXEL_SM_QA_BATTLE_TITLEPACK_ID:-SMStormBattle@nadeolabs}"
  expected_filename="${expected_titlepack_id}.Title.Pack.gbx"
  resolved_source_dir="$(resolve_path "$battle_titlepacks_source")"
  expected_file_path="${resolved_source_dir}/${expected_filename}"

  if [ -f "$expected_file_path" ]; then
    log "Battle title pack already present: ${expected_file_path}"
    return
  fi

  auto_fetch_battle_titlepack="${PIXEL_SM_QA_AUTO_FETCH_BATTLE_TITLEPACK:-1}"
  if [ "$auto_fetch_battle_titlepack" != "1" ]; then
    log "Missing battle title pack: ${expected_file_path}"
    log "Enable auto-fetch with PIXEL_SM_QA_AUTO_FETCH_BATTLE_TITLEPACK=1 or run: bash scripts/fetch-titlepack.sh ${expected_titlepack_id} ${battle_titlepacks_source}"
    exit 1
  fi

  log "Fetching battle title pack: ${expected_titlepack_id}"
  env PIXEL_SM_TITLEPACKS_SOURCE="$battle_titlepacks_source" bash "$TITLEPACK_FETCH_SCRIPT" "$expected_titlepack_id"

  if [ ! -f "$expected_file_path" ]; then
    log "Battle title pack provisioning failed: ${expected_file_path}"
    exit 1
  fi
}

ensure_matchsettings_templates() {
  require_file "${PROJECT_DIR}/templates/matchsettings/elite.txt"
  require_file "${PROJECT_DIR}/templates/matchsettings/siege.txt"
  require_file "${PROJECT_DIR}/templates/matchsettings/battle.txt"
  require_file "${PROJECT_DIR}/templates/matchsettings/joust.txt"
  require_file "${PROJECT_DIR}/templates/matchsettings/custom.txt"
}

build_first_run="${PIXEL_SM_QA_MODE_BUILD_FIRST:-1}"
battle_titlepacks_source="${PIXEL_SM_QA_BATTLE_TITLEPACKS_SOURCE:-./TitlePacks}"

assert_not_reference_path "$battle_titlepacks_source" "battle title pack source"

first_build_flag=0
if [ "$build_first_run" = "1" ]; then
  first_build_flag=1
fi

ensure_battle_titlepack
ensure_matchsettings_templates

run_smoke_case "elite" \
  PIXEL_SM_QA_BUILD_IMAGES="${first_build_flag}" \
  PIXEL_SM_MODE=elite \
  PIXEL_SM_MATCHSETTINGS= \
  PIXEL_SM_TITLE_PACK=SMStormElite@nadeolabs

run_smoke_case "siege" \
  PIXEL_SM_QA_BUILD_IMAGES=0 \
  PIXEL_SM_MODE=siege \
  PIXEL_SM_MATCHSETTINGS= \
  PIXEL_SM_TITLE_PACK=SMStorm@nadeo

run_smoke_case "battle" \
  PIXEL_SM_QA_BUILD_IMAGES=0 \
  PIXEL_SM_TITLEPACKS_SOURCE="${battle_titlepacks_source}" \
  PIXEL_SM_MODE=battle \
  PIXEL_SM_MATCHSETTINGS= \
  PIXEL_SM_TITLE_PACK=SMStormBattle@nadeolabs

run_smoke_case "joust" \
  PIXEL_SM_QA_BUILD_IMAGES=0 \
  PIXEL_SM_MODE=joust \
  PIXEL_SM_MATCHSETTINGS= \
  PIXEL_SM_TITLE_PACK=SMStorm@nadeo

run_smoke_case "custom" \
  PIXEL_SM_QA_BUILD_IMAGES=0 \
  PIXEL_SM_MODE=custom \
  PIXEL_SM_MATCHSETTINGS= \
  PIXEL_SM_TITLE_PACK=SMStormElite@nadeolabs

log "Elite/Siege/Battle/Joust/Custom smoke checks passed"
