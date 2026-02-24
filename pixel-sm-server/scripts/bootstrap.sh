#!/usr/bin/env bash

set -eu

log() {
  printf '[pixel-sm-bootstrap] %s\n' "$1"
}

require_env() {
  var_name="$1"
  eval "var_value=\${$var_name:-}"
  if [ -z "$var_value" ]; then
    log "Missing required environment variable: ${var_name}"
    exit 1
  fi
}

resolve_matchsettings_file() {
  if [ -n "${PIXEL_SM_MATCHSETTINGS:-}" ]; then
    printf '%s' "$PIXEL_SM_MATCHSETTINGS"
    return
  fi

  preset_name="$(resolve_mode_preset_name "${PIXEL_SM_MODE:-custom}")"
  printf '%s' "${preset_name}.txt"
}

resolve_mode_preset_name() {
  normalized_mode="$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')"

  case "$normalized_mode" in
    elite|siege|battle|joust|custom)
      printf '%s' "$normalized_mode"
      ;;
    *)
      printf '%s' "custom"
      ;;
  esac
}

resolve_expected_title_pack_for_preset() {
  preset_name="$1"

  case "$preset_name" in
    elite)
      printf '%s' "SMStormElite@nadeolabs"
      ;;
    siege|joust)
      printf '%s' "SMStorm@nadeo"
      ;;
    battle)
      printf '%s' "SMStormBattle@nadeolabs"
      ;;
    custom)
      printf '%s' ""
      ;;
    *)
      printf '%s' ""
      ;;
  esac
}

resolve_expected_script_for_preset() {
  preset_name="$1"

  case "$preset_name" in
    elite)
      printf '%s' "ShootMania\\Elite\\ElitePro.Script.txt"
      ;;
    siege)
      printf '%s' "ShootMania\\SiegeV1.Script.txt"
      ;;
    battle)
      printf '%s' "Battle\\BattlePro.Script.txt"
      ;;
    joust)
      printf '%s' "ShootMania\\Joust\\JoustBase.Script.txt"
      ;;
    custom)
      printf '%s' ""
      ;;
    *)
      printf '%s' ""
      ;;
  esac
}

validate_mode_preset_expectations() {
  matchsettings_file="$1"
  preset_name="$(resolve_mode_preset_name "${PIXEL_SM_MODE:-custom}")"

  if [ -n "${PIXEL_SM_MATCHSETTINGS:-}" ]; then
    log "Using explicit matchsettings override (${PIXEL_SM_MATCHSETTINGS}); mode preset defaults are bypassed."
    return
  fi

  expected_title_pack="$(resolve_expected_title_pack_for_preset "$preset_name")"
  expected_script="$(resolve_expected_script_for_preset "$preset_name")"
  resolved_script="$(resolve_matchsettings_script_name "$matchsettings_file")"

  if [ -n "$expected_title_pack" ]; then
    normalized_expected_title_pack="$(printf '%s' "$expected_title_pack" | tr '[:upper:]' '[:lower:]')"
    normalized_configured_title_pack="$(printf '%s' "${PIXEL_SM_TITLE_PACK}" | tr '[:upper:]' '[:lower:]')"
    if [ "$normalized_expected_title_pack" != "$normalized_configured_title_pack" ]; then
      log "Mode preset '${preset_name}' expects title pack '${expected_title_pack}', but PIXEL_SM_TITLE_PACK is '${PIXEL_SM_TITLE_PACK}'."
      log "Set PIXEL_SM_TITLE_PACK=${expected_title_pack} or provide PIXEL_SM_MATCHSETTINGS override with compatible assets."
      exit 1
    fi
  fi

  if [ -n "$expected_script" ]; then
    normalized_expected_script="$(printf '%s' "$expected_script" | tr '[:upper:]' '[:lower:]')"
    normalized_resolved_script="$(printf '%s' "$resolved_script" | tr '[:upper:]' '[:lower:]')"
    if [ "$normalized_expected_script" != "$normalized_resolved_script" ]; then
      log "Mode preset '${preset_name}' expects script '${expected_script}', but matchsettings uses '${resolved_script}'."
      log "Provide a preset-compatible matchsettings template or switch to explicit PIXEL_SM_MATCHSETTINGS override."
      exit 1
    fi
  fi
}

resolve_matchsettings_source() {
  candidate="$1"
  if [ -f "$candidate" ]; then
    printf '%s' "$candidate"
    return
  fi

  template_candidate="/opt/pixel-sm/templates/matchsettings/${candidate}"
  if [ -f "$template_candidate" ]; then
    printf '%s' "$template_candidate"
    return
  fi

  runtime_candidate="${PIXEL_SM_SERVER_ROOT}/UserData/Maps/MatchSettings/${candidate}"
  if [ -f "$runtime_candidate" ]; then
    printf '%s' "$runtime_candidate"
    return
  fi

  log "Unable to resolve matchsettings file: ${candidate}"
  exit 1
}

list_runtime_maps() {
  maps_root="${PIXEL_SM_SERVER_ROOT}/UserData/Maps"

  if [ ! -d "$maps_root" ]; then
    log "Maps directory missing: ${maps_root}"
    exit 1
  fi

  find "$maps_root" \
    -type f \
    \( -name '*.Map.Gbx' -o -name '*.map.gbx' \) \
    ! -path "${maps_root}/MatchSettings/*" \
    -print0 \
    | sort -z \
    | while IFS= read -r -d '' map_path; do
      printf '%s\n' "${map_path#${maps_root}/}"
    done
}

sync_mode_map_pool() {
  source_root="/opt/pixel-sm/mode-maps"
  target_root="${PIXEL_SM_SERVER_ROOT}/UserData/Maps/PixelControl"

  if [ ! -d "$source_root" ]; then
    return
  fi

  rm -rf "$target_root"

  copied_map_count=0
  while IFS= read -r -d '' source_map_path; do
    relative_map_path="${source_map_path#${source_root}/}"
    target_map_path="${target_root}/${relative_map_path}"

    mkdir -p "$(dirname "$target_map_path")"
    cp "$source_map_path" "$target_map_path"
    copied_map_count=$((copied_map_count + 1))
  done < <(
    find "$source_root" \
      -type f \
      \( -name '*.Map.Gbx' -o -name '*.map.gbx' \) \
      -print0 \
      | sort -z
  )

  if [ "$copied_map_count" -gt 0 ]; then
    log "Synced ${copied_map_count} mode map(s) from ${source_root}."
  fi
}

resolve_matchsettings_script_name() {
  matchsettings_file="$1"
  xmlstarlet sel -t -v '/playlist/gameinfos/script_name' "$matchsettings_file" 2>/dev/null || true
}

auto_injection_supported_for_matchsettings() {
  matchsettings_file="$1"
  normalized_mode="$(printf '%s' "${PIXEL_SM_MODE:-}" | tr '[:upper:]' '[:lower:]')"
  normalized_script_name="$(resolve_matchsettings_script_name "$matchsettings_file" | tr '[:upper:]' '[:lower:]')"

  case "$normalized_mode" in
    siege|battle|joust|royal)
      return 1
      ;;
    elite)
      return 0
      ;;
  esac

  case "$normalized_script_name" in
    *siege*|*battle*|*joust*|*royal*)
      return 1
      ;;
  esac

  return 0
}

validate_matchsettings_mode_script() {
  matchsettings_file="$1"
  script_name="$(resolve_matchsettings_script_name "$matchsettings_file")"

  if [ -z "$script_name" ]; then
    log "Matchsettings is missing /playlist/gameinfos/script_name: ${matchsettings_file}"
    exit 1
  fi

  normalized_script_path="$(printf '%s' "$script_name" | tr '\\' '/')"
  normalized_script_path="${normalized_script_path#/}"

  mode_script_path="${PIXEL_SM_SERVER_ROOT}/GameData/Scripts/Modes/${normalized_script_path}"
  if [ -f "$mode_script_path" ]; then
    log "Mode script confirmed in runtime: ${script_name}"
    return
  fi

  user_mode_script_path="${PIXEL_SM_SERVER_ROOT}/UserData/Scripts/Modes/${normalized_script_path}"
  if [ -f "$user_mode_script_path" ]; then
    log "Mode script confirmed in runtime: ${script_name}"
    return
  fi

  normalized_title_pack="$(printf '%s' "${PIXEL_SM_TITLE_PACK}" | tr '[:upper:]' '[:lower:]')"
  normalized_script_name="$(printf '%s' "${script_name}" | tr '[:upper:]' '[:lower:]')"
  case "${normalized_script_name}|${normalized_title_pack}" in
    shootmania\\battle\\mode.script.txt\|smstormbattle*|shootmania\\battle.script.txt\|smstormbattle*|battle\\*.script.txt\|smstormbattle*|shootmania\\battle\\*.script.txt\|smstormbattle*)
      log "Mode script confirmed in runtime: ${script_name} (provided by title pack ${PIXEL_SM_TITLE_PACK})."
      return
      ;;
    shootmania\\siegev1.script.txt\|smstormsiege*|shootmania\\siegev1\\*.script.txt\|smstormsiege*|shootmania\\siege\\*.script.txt\|smstormsiege*|siege\\*.script.txt\|smstormsiege*|siegev1\\*.script.txt\|smstormsiege*)
      log "Mode script confirmed in runtime: ${script_name} (provided by title pack ${PIXEL_SM_TITLE_PACK})."
      return
      ;;
    shootmania\\joust\\joust.script.txt\|smstormjoust*|joust\\*.script.txt\|smstormjoust*|shootmania\\joust\\*.script.txt\|smstormjoust*)
      log "Mode script confirmed in runtime: ${script_name} (provided by title pack ${PIXEL_SM_TITLE_PACK})."
      return
      ;;
  esac

  log "Mode script not available in runtime: ${script_name}"
  log "Expected one of: ${mode_script_path} or ${user_mode_script_path}"
  log "Provide a title pack/runtime that includes this mode script before launch."
  exit 1
}

list_title_pack_assets() {
  packs_root="$1"

  find "$packs_root" \
    -maxdepth 1 \
    -type f \
    -iname '*.title.pack.gbx' \
    -print0 \
    | sort -z \
    | while IFS= read -r -d '' pack_path; do
      basename "$pack_path"
    done
}

validate_title_pack_asset() {
  packs_root="${PIXEL_SM_SERVER_ROOT}/Packs"

  if [ ! -d "$packs_root" ]; then
    log "Title pack directory missing: ${packs_root}"
    exit 1
  fi

  title_pack_assets_file="$(mktemp)"
  list_title_pack_assets "$packs_root" > "$title_pack_assets_file"

  requested_title_pack="$(printf '%s' "$PIXEL_SM_TITLE_PACK" | tr '[:upper:]' '[:lower:]')"
  requested_title_pack_with_suffix="$requested_title_pack"
  case "$requested_title_pack" in
    *.title.pack.gbx)
      ;;
    *)
      requested_title_pack_with_suffix="${requested_title_pack}.title.pack.gbx"
      ;;
  esac

  matched_title_pack_asset=""
  while IFS= read -r available_title_pack_asset; do
    if [ -z "$available_title_pack_asset" ]; then
      continue
    fi

    normalized_available_title_pack_asset="$(printf '%s' "$available_title_pack_asset" | tr '[:upper:]' '[:lower:]')"
    if [ "$normalized_available_title_pack_asset" = "$requested_title_pack" ] || [ "$normalized_available_title_pack_asset" = "$requested_title_pack_with_suffix" ]; then
      matched_title_pack_asset="$available_title_pack_asset"
      break
    fi
  done < "$title_pack_assets_file"

  if [ -n "$matched_title_pack_asset" ]; then
    rm -f "$title_pack_assets_file"
    log "Title pack asset confirmed for ${PIXEL_SM_TITLE_PACK}: ${matched_title_pack_asset}"
    return
  fi

  log "Configured title pack '${PIXEL_SM_TITLE_PACK}' is missing in ${packs_root}."
  if [ -s "$title_pack_assets_file" ]; then
    while IFS= read -r available_title_pack_asset; do
      [ -z "$available_title_pack_asset" ] && continue
      log "Available title pack asset: ${available_title_pack_asset}"
    done < "$title_pack_assets_file"
  else
    log "No *.Title.Pack.gbx assets found in ${packs_root}."
  fi

  rm -f "$title_pack_assets_file"
  exit 1
}

ensure_matchsettings_has_playable_maps() {
  matchsettings_file="$1"
  maps_root="${PIXEL_SM_SERVER_ROOT}/UserData/Maps"
  declared_maps_file="$(mktemp)"

  if ! xmlstarlet val -q "$matchsettings_file" >/dev/null 2>&1; then
    rm -f "$declared_maps_file"
    log "Failed to parse matchsettings XML: ${matchsettings_file}"
    exit 1
  fi

  xmlstarlet sel -t -m '/playlist/map/file' -v . -n "$matchsettings_file" > "$declared_maps_file" 2>/dev/null || true

  declared_map_count=0
  existing_declared_map_count=0
  while IFS= read -r declared_map; do
    if [ -z "$declared_map" ]; then
      continue
    fi

    declared_map_count=$((declared_map_count + 1))
    if [ -f "${maps_root}/${declared_map}" ]; then
      existing_declared_map_count=$((existing_declared_map_count + 1))
    fi
  done < "$declared_maps_file"
  rm -f "$declared_maps_file"

  if [ "$existing_declared_map_count" -gt 0 ]; then
    return
  fi

  if ! auto_injection_supported_for_matchsettings "$matchsettings_file"; then
    script_name="$(resolve_matchsettings_script_name "$matchsettings_file")"
    preset_name="$(resolve_mode_preset_name "${PIXEL_SM_MODE:-custom}")"
    log "Match map auto-injection is disabled for non-Elite mode/script (mode='${PIXEL_SM_MODE:-unset}', script='${script_name}')."
    log "Preset '${preset_name}' requires explicit mode-compatible <map> entries that exist in ${maps_root}."
    log "Use PIXEL_SM_MAPS_SOURCE to mount preset maps (for example pixel-sm-server/maps/) before launch."
    log "Provide explicit mode-compatible <map> entries in ${matchsettings_file} before launch."
    exit 1
  fi

  mapfile -t runtime_maps < <(list_runtime_maps)
  if [ "${#runtime_maps[@]}" -eq 0 ]; then
    log "No playable maps found under ${maps_root}. Add at least one .Map.Gbx file."
    exit 1
  fi

  if ! xmlstarlet ed --inplace -d '/playlist/map' "$matchsettings_file"; then
    log "Failed to reset matchsettings map entries in ${matchsettings_file}"
    exit 1
  fi

  for runtime_map in "${runtime_maps[@]}"; do
    if ! xmlstarlet ed --inplace \
      -s '/playlist' -t elem -n map -v '' \
      -s '/playlist/map[last()]' -t elem -n file -v "$runtime_map" \
      "$matchsettings_file"; then
      log "Failed to inject map '${runtime_map}' into ${matchsettings_file}"
      exit 1
    fi
  done

  if [ "$declared_map_count" -eq 0 ]; then
    log "Matchsettings had no map entries; injected ${#runtime_maps[@]} runtime map(s)."
    return
  fi

  log "Matchsettings map entries were missing on disk; replaced with ${#runtime_maps[@]} runtime map(s)."
}

validate_environment() {
  require_env PIXEL_SM_SERVER_ROOT
  require_env PIXEL_SM_PLUGIN_SOURCE_ROOT
  require_env PIXEL_SM_DB_HOST
  require_env PIXEL_SM_DB_PORT
  require_env PIXEL_SM_DB_NAME
  require_env PIXEL_SM_DB_USER
  require_env PIXEL_SM_DB_PASSWORD
  require_env PIXEL_SM_XMLRPC_PORT
  require_env PIXEL_SM_SERVER_NAME
  require_env PIXEL_SM_DEDICATED_LOGIN
  require_env PIXEL_SM_DEDICATED_PASSWORD
  require_env PIXEL_SM_TITLE_PACK
  require_env PIXEL_SM_GAME_PORT
  require_env PIXEL_SM_P2P_PORT
  require_env PIXEL_SM_MANIACONTROL_SUPERADMIN_PASSWORD
  require_env PIXEL_SM_MANIACONTROL_ADMIN_PASSWORD
  require_env PIXEL_SM_MANIACONTROL_USER_PASSWORD
}

wait_for_database() {
  timeout_seconds="${PIXEL_SM_BOOTSTRAP_TIMEOUT_SECONDS:-120}"
  elapsed_seconds=0

  log "Step 1/5: waiting for MySQL readiness"
  while ! mysqladmin ping \
      -h"${PIXEL_SM_DB_HOST}" \
      -P"${PIXEL_SM_DB_PORT}" \
      -u"${PIXEL_SM_DB_USER}" \
      "-p${PIXEL_SM_DB_PASSWORD}" \
      --silent >/dev/null 2>&1; do
    sleep 2
    elapsed_seconds=$((elapsed_seconds + 2))

    if [ "$elapsed_seconds" -ge "$timeout_seconds" ]; then
      log "Timed out while waiting for MySQL after ${timeout_seconds}s"
      exit 1
    fi
  done
}

render_runtime_files() {
  log "Step 2/5: rendering dedicated + ManiaControl config files"

  mkdir -p "${PIXEL_SM_SERVER_ROOT}/ManiaControl/configs"
  mkdir -p "${PIXEL_SM_SERVER_ROOT}/UserData/Config"
  mkdir -p "${PIXEL_SM_SERVER_ROOT}/UserData/Maps/MatchSettings"
  mkdir -p "${PIXEL_SM_SERVER_ROOT}/Packs"

  for pack_file in /opt/pixel-sm/titlepacks/*.gbx /opt/pixel-sm/titlepacks/*.Gbx; do
    if [ -f "$pack_file" ]; then
      cp -n "$pack_file" "${PIXEL_SM_SERVER_ROOT}/Packs/"
    fi
  done

  sync_mode_map_pool

  validate_title_pack_asset

  envsubst '${PIXEL_SM_XMLRPC_PORT} ${PIXEL_SM_DB_HOST} ${PIXEL_SM_DB_PORT} ${PIXEL_SM_DB_USER} ${PIXEL_SM_DB_PASSWORD} ${PIXEL_SM_DB_NAME} ${PIXEL_SM_MANIACONTROL_SUPERADMIN_PASSWORD} ${PIXEL_SM_MANIACONTROL_MASTERADMIN_LOGIN}' \
    < /opt/pixel-sm/templates/maniacontrol/server.template.xml \
    > "${PIXEL_SM_SERVER_ROOT}/ManiaControl/configs/server.xml"

  envsubst '${PIXEL_SM_MANIACONTROL_SUPERADMIN_PASSWORD} ${PIXEL_SM_MANIACONTROL_ADMIN_PASSWORD} ${PIXEL_SM_MANIACONTROL_USER_PASSWORD} ${PIXEL_SM_SERVER_NAME} ${PIXEL_SM_GAME_PORT} ${PIXEL_SM_P2P_PORT} ${PIXEL_SM_XMLRPC_PORT} ${PIXEL_SM_TITLE_PACK}' \
    < /opt/pixel-sm/templates/dedicated/dedicated_cfg.template.txt \
    > "${PIXEL_SM_SERVER_ROOT}/UserData/Config/dedicated_cfg.txt"

  selected_matchsettings="$(resolve_matchsettings_file)"
  preset_name="$(resolve_mode_preset_name "${PIXEL_SM_MODE:-custom}")"

  if [ -n "${PIXEL_SM_MATCHSETTINGS:-}" ]; then
    log "Matchsettings resolution: override '${PIXEL_SM_MATCHSETTINGS}' (preset='${preset_name}')."
  else
    log "Matchsettings resolution: preset '${preset_name}' -> '${selected_matchsettings}'."
  fi

  source_matchsettings="$(resolve_matchsettings_source "$selected_matchsettings")"

  cp "$source_matchsettings" "${PIXEL_SM_SERVER_ROOT}/UserData/Maps/MatchSettings/active-matchsettings.txt"
  validate_mode_preset_expectations "${PIXEL_SM_SERVER_ROOT}/UserData/Maps/MatchSettings/active-matchsettings.txt"
  validate_matchsettings_mode_script "${PIXEL_SM_SERVER_ROOT}/UserData/Maps/MatchSettings/active-matchsettings.txt"
  ensure_matchsettings_has_playable_maps "${PIXEL_SM_SERVER_ROOT}/UserData/Maps/MatchSettings/active-matchsettings.txt"
  PIXEL_SM_MATCHSETTINGS_ACTIVE="MatchSettings/active-matchsettings.txt"
  export PIXEL_SM_MATCHSETTINGS_ACTIVE
}

install_pixel_control_plugin() {
  log "Step 3/5: syncing Pixel Control plugin"

  plugin_source_root="${PIXEL_SM_PLUGIN_SOURCE_ROOT}"
  plugin_entry_source="${plugin_source_root}/PixelControlPlugin.php"
  plugin_target_root="${PIXEL_SM_SERVER_ROOT}/ManiaControl/plugins/PixelControl"
  plugin_entry_target="${plugin_target_root}/PixelControlPlugin.php"

  if [ ! -f "$plugin_entry_source" ]; then
    log "Pixel Control plugin source missing: ${plugin_entry_source}"
    exit 1
  fi

  mkdir -p "$plugin_target_root"
  cp -R "${plugin_source_root}/." "$plugin_target_root/"

  if [ ! -f "$plugin_entry_target" ]; then
    log "Pixel Control plugin install failed: missing target file ${plugin_entry_target}"
    exit 1
  fi

  log "Pixel Control plugin synchronized: ${plugin_entry_target}"
}

start_maniacontrol() {
  if [ "${PIXEL_SM_START_MANIACONTROL:-1}" != "1" ]; then
    log "Step 4/5: skipping ManiaControl startup"
    return
  fi

  log "Step 4/5: starting ManiaControl"
  if [ -f "${PIXEL_SM_SERVER_ROOT}/ManiaControl/ManiaControl.sh" ]; then
    (
      cd "${PIXEL_SM_SERVER_ROOT}/ManiaControl"
      sh ManiaControl.sh
    ) &
    return
  fi

  if [ -f "${PIXEL_SM_SERVER_ROOT}/ManiaControl/ManiaControl.php" ]; then
    (
      cd "${PIXEL_SM_SERVER_ROOT}/ManiaControl"
      php ManiaControl.php > ManiaControl.log 2>&1
    ) &
    return
  fi

  log "ManiaControl startup script not found in ${PIXEL_SM_SERVER_ROOT}/ManiaControl"
  exit 1
}

start_dedicated_server() {
  log "Step 5/5: starting ManiaPlanet dedicated server"

  if [ ! -f "${PIXEL_SM_SERVER_ROOT}/ManiaPlanetServer" ]; then
    log "Dedicated server binary missing: ${PIXEL_SM_SERVER_ROOT}/ManiaPlanetServer"
    exit 1
  fi

  cd "${PIXEL_SM_SERVER_ROOT}"
  chmod +x ./ManiaPlanetServer

  exec ./ManiaPlanetServer \
    /nodaemon \
    "/title=${PIXEL_SM_TITLE_PACK}" \
    "/servername=${PIXEL_SM_SERVER_NAME}" \
    "/login=${PIXEL_SM_DEDICATED_LOGIN}" \
    "/password=${PIXEL_SM_DEDICATED_PASSWORD}" \
    "/game_settings=${PIXEL_SM_MATCHSETTINGS_ACTIVE}" \
    /dedicated_cfg=dedicated_cfg.txt
}

main() {
  validate_environment
  wait_for_database
  render_runtime_files
  install_pixel_control_plugin
  start_maniacontrol
  start_dedicated_server
}

main "$@"
