#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

log() {
  printf '[pixel-sm-titlepack] %s\n' "$1"
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
      log "Refusing title pack target inside ressources/: ${resolved_candidate_path}"
      log "Use pixel-sm-server/TitlePacks (or another gitignored local sandbox) as target."
      exit 1
      ;;
  esac
}

if [ "$#" -lt 1 ] || [ "$#" -gt 2 ]; then
  log "Usage: bash scripts/fetch-titlepack.sh <title-pack-id-or-filename> [target-dir]"
  exit 1
fi

title_pack_input="$1"
target_source_dir="${2:-${PIXEL_SM_TITLEPACKS_SOURCE:-./TitlePacks}}"
download_base_url="${PIXEL_SM_TITLEPACK_DOWNLOAD_BASE_URL:-https://maniaplanet.com/ingame/public/titles/download}"
force_fetch="${PIXEL_SM_TITLEPACK_FORCE_FETCH:-0}"

normalized_input="$(printf '%s' "$title_pack_input" | tr '[:upper:]' '[:lower:]')"
title_pack_filename="$title_pack_input"
case "$normalized_input" in
  *.title.pack.gbx)
    ;;
  *)
    title_pack_filename="${title_pack_input}.Title.Pack.gbx"
    ;;
esac

target_dir="$(resolve_path "$target_source_dir")"
assert_not_reference_path "$target_source_dir"
mkdir -p "$target_dir"

target_file_path="${target_dir}/${title_pack_filename}"
if [ -f "$target_file_path" ] && [ "$force_fetch" != "1" ]; then
  log "Title pack already present: ${target_file_path}"
  exit 0
fi

download_url="${download_base_url%/}/${title_pack_filename}"
log "Downloading ${title_pack_filename}"
if ! curl -fL -A "pixel-sm-server/1.0" "$download_url" -o "$target_file_path"; then
  log "Failed to download title pack from ${download_url}"
  exit 1
fi

if [ ! -s "$target_file_path" ]; then
  log "Downloaded file is empty: ${target_file_path}"
  exit 1
fi

log "Title pack available at: ${target_file_path}"
