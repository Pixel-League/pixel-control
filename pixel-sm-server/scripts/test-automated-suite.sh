#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
AUTOMATED_SUITE_DIR="${PROJECT_DIR}/scripts/automated-suite"
ADMIN_ACTION_SPECS_DIR="${AUTOMATED_SUITE_DIR}/admin-actions"
ADMIN_LINK_AUTH_CASE_SPECS_DIR="${AUTOMATED_SUITE_DIR}/admin-link-auth-cases"
VETO_CHECK_SPECS_DIR="${AUTOMATED_SUITE_DIR}/veto-checks"

DEV_MODE_SCRIPT="${PROJECT_DIR}/scripts/dev-mode-compose.sh"
LAUNCH_VALIDATION_SCRIPT="${PROJECT_DIR}/scripts/validate-dev-stack-launch.sh"
MODE_MATRIX_VALIDATION_SCRIPT="${PROJECT_DIR}/scripts/validate-mode-launch-matrix.sh"
WAVE3_REPLAY_SCRIPT="${PROJECT_DIR}/scripts/replay-core-telemetry-wave3.sh"
WAVE4_REPLAY_SCRIPT="${PROJECT_DIR}/scripts/replay-extended-telemetry-wave4.sh"
ADMIN_SIMULATION_SCRIPT="${PROJECT_DIR}/scripts/simulate-admin-control-payloads.sh"
VETO_SIMULATION_SCRIPT="${PROJECT_DIR}/scripts/simulate-veto-control-payloads.sh"
ACK_STUB_SCRIPT="${PROJECT_DIR}/scripts/manual-wave5-ack-stub.sh"
DEV_PLUGIN_SYNC_SCRIPT="${PROJECT_DIR}/scripts/dev-plugin-sync.sh"

AUTOMATED_COMPOSE_FILES="${PIXEL_SM_AUTOMATED_SUITE_COMPOSE_FILES:-${PIXEL_SM_QA_COMPOSE_FILES:-docker-compose.yml}}"
AUTOMATED_BUILD_FIRST_SMOKE="${PIXEL_SM_AUTOMATED_SUITE_BUILD_FIRST_SMOKE:-1}"
ACK_STUB_PORT_BASE="${PIXEL_SM_AUTOMATED_SUITE_ACK_STUB_PORT_BASE:-18180}"
ACK_STUB_BIND_HOST="${PIXEL_SM_AUTOMATED_SUITE_ACK_STUB_BIND_HOST:-127.0.0.1}"
ACK_STUB_RECEIPT_ID="${PIXEL_SM_AUTOMATED_SUITE_ACK_STUB_RECEIPT_ID:-automated-suite}"
COMM_SOCKET_HOST="${PIXEL_SM_AUTOMATED_SUITE_COMM_HOST:-127.0.0.1}"
COMM_SOCKET_PORT="${PIXEL_SM_AUTOMATED_SUITE_COMM_PORT:-31501}"
COMM_SOCKET_WAIT_ATTEMPTS="${PIXEL_SM_AUTOMATED_SUITE_COMM_WAIT_ATTEMPTS:-45}"
AUTOMATED_LINK_SERVER_URL="${PIXEL_SM_AUTOMATED_SUITE_LINK_SERVER_URL:-http://127.0.0.1:8080}"
AUTOMATED_LINK_TOKEN="${PIXEL_SM_AUTOMATED_SUITE_LINK_TOKEN:-automated-suite-link-token}"
AUTOMATED_LINK_SERVER_LOGIN="${PIXEL_SM_AUTOMATED_SUITE_LINK_SERVER_LOGIN:-}"

DEFAULT_MODES_CSV="elite,joust"
REQUESTED_MODES_CSV="$DEFAULT_MODES_CSV"
WITH_MODE_MATRIX_VALIDATION=0

RUN_TIMESTAMP=""
RUN_DIR=""
RUN_MANIFEST_FILE=""
COVERAGE_INVENTORY_FILE=""
CHECK_RESULTS_FILE=""
SUITE_SUMMARY_JSON_FILE=""
SUITE_SUMMARY_MD_FILE=""
MANUAL_HANDOFF_FILE=""

declare -a REQUESTED_MODES=()
declare -a CLEANUP_PIDS=()

REQUIRED_CHECK_FAILURES=0
TOTAL_CHECKS=0
PASSED_CHECKS=0
FAILED_CHECKS=0
SUITE_EXIT_CODE=0
SMOKE_BUILD_PENDING=1
ACTIVE_ACK_STUB_PID=""
ACTIVE_ACK_STUB_PORT=""

DOCKER_VERSION=""
BASH_VERSION_LINE=""
PYTHON3_VERSION=""
PHP_VERSION_LINE=""
CURL_VERSION_LINE=""

usage() {
  cat <<'USAGE'
Usage:
  bash scripts/test-automated-suite.sh [--modes elite,joust] [--with-mode-matrix-validation]

Options:
  --modes <csv>                       Comma-separated mode list (default: elite,joust)
  --with-mode-matrix-validation      Run validate-mode-launch-matrix.sh as optional deep validation coverage
  --with-mode-smoke                  Deprecated alias of --with-mode-matrix-validation
  -h, --help                         Show this help message

Notes:
  - Required checks fail the suite with non-zero exit.
  - Manual-only combat validation remains outside automated pass criteria:
    OnShoot, OnHit, OnNearMiss, OnArmorEmpty, OnCapture.
USAGE
}

trim_whitespace() {
  local input="$*"
  input="${input#${input%%[![:space:]]*}}"
  input="${input%${input##*[![:space:]]}}"
  printf '%s' "$input"
}

log() {
  printf '[test-automated-suite] %s\n' "$*"
}

fail() {
  printf '[test-automated-suite][error] %s\n' "$*" >&2
  exit 1
}

require_command() {
  local command_name="$1"
  command -v "$command_name" >/dev/null 2>&1 || fail "Missing command: ${command_name}"
}

register_cleanup_pid() {
  local pid="$1"
  if [ -n "$pid" ]; then
    CLEANUP_PIDS+=("$pid")
  fi
}

cleanup() {
  local pid=""
  if [ "${#CLEANUP_PIDS[@]}" -eq 0 ]; then
    return
  fi

  for pid in "${CLEANUP_PIDS[@]}"; do
    if [ -z "$pid" ]; then
      continue
    fi

    if kill -0 "$pid" >/dev/null 2>&1; then
      kill "$pid" >/dev/null 2>&1 || true
      wait "$pid" >/dev/null 2>&1 || true
    fi
  done
}

append_check_result() {
  local check_id="$1"
  local required="$2"
  local status="$3"
  local exit_code="$4"
  local duration_seconds="$5"
  local description="$6"
  local artifact_path="$7"

  python3 - "$CHECK_RESULTS_FILE" "$check_id" "$required" "$status" "$exit_code" "$duration_seconds" "$description" "$artifact_path" <<'PY'
import json
import sys
import time

check_results_file = sys.argv[1]
check_id = sys.argv[2]
required = sys.argv[3] == "1"
status = sys.argv[4]
exit_code = int(sys.argv[5])
duration_seconds = int(sys.argv[6])
description = sys.argv[7]
artifact_path = sys.argv[8]

payload = {
    "check_id": check_id,
    "required": required,
    "status": status,
    "exit_code": exit_code,
    "duration_seconds": duration_seconds,
    "description": description,
    "artifact_path": artifact_path if artifact_path else None,
    "recorded_at_epoch": int(time.time()),
}

with open(check_results_file, "a", encoding="utf-8") as handle:
    handle.write(json.dumps(payload, ensure_ascii=True) + "\n")
PY
}

run_check_command() {
  local check_id="$1"
  local required="$2"
  local description="$3"
  local artifact_path="$4"
  shift 4

  local started_epoch=""
  local ended_epoch=""
  local duration_seconds=""
  local exit_code=0
  local status="passed"

  TOTAL_CHECKS=$((TOTAL_CHECKS + 1))
  started_epoch="$(date +%s)"
  log "check=${check_id} status=running required=${required} ${description}"

  set +e
  "$@"
  exit_code=$?
  set -e

  ended_epoch="$(date +%s)"
  duration_seconds=$((ended_epoch - started_epoch))

  if [ "$exit_code" -eq 0 ]; then
    status="passed"
    PASSED_CHECKS=$((PASSED_CHECKS + 1))
  else
    status="failed"
    FAILED_CHECKS=$((FAILED_CHECKS + 1))
    if [ "$required" = "1" ]; then
      REQUIRED_CHECK_FAILURES=$((REQUIRED_CHECK_FAILURES + 1))
    fi
  fi

  append_check_result "$check_id" "$required" "$status" "$exit_code" "$duration_seconds" "$description" "$artifact_path"
  log "check=${check_id} status=${status} required=${required} duration=${duration_seconds}s exit_code=${exit_code}"
}

run_check_command_logged() {
  local check_id="$1"
  local required="$2"
  local description="$3"
  local artifact_path="$4"
  local log_file="$5"
  shift 5

  local started_epoch=""
  local ended_epoch=""
  local duration_seconds=""
  local exit_code=0
  local status="passed"

  mkdir -p "$(dirname "$log_file")"

  TOTAL_CHECKS=$((TOTAL_CHECKS + 1))
  started_epoch="$(date +%s)"
  log "check=${check_id} status=running required=${required} ${description}"

  set +e
  "$@" >"$log_file" 2>&1
  exit_code=$?
  set -e

  ended_epoch="$(date +%s)"
  duration_seconds=$((ended_epoch - started_epoch))

  if [ "$exit_code" -eq 0 ]; then
    status="passed"
    PASSED_CHECKS=$((PASSED_CHECKS + 1))
  else
    status="failed"
    FAILED_CHECKS=$((FAILED_CHECKS + 1))
    if [ "$required" = "1" ]; then
      REQUIRED_CHECK_FAILURES=$((REQUIRED_CHECK_FAILURES + 1))
    fi
  fi

  append_check_result "$check_id" "$required" "$status" "$exit_code" "$duration_seconds" "$description" "$artifact_path"
  log "check=${check_id} status=${status} required=${required} duration=${duration_seconds}s exit_code=${exit_code} log=${log_file}"
}

wait_for_comm_socket_after_recovery() {
  local host="$1"
  local port="$2"
  local max_attempts="$3"
  local log_file="$4"
  local attempt=1

  while [ "$attempt" -le "$max_attempts" ]; do
    if python3 - "$host" "$port" <<'PY' >/dev/null 2>&1
import socket
import sys

host = sys.argv[1]
port = int(sys.argv[2])

sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
sock.settimeout(0.75)

try:
    sock.connect((host, port))
except OSError:
    sys.exit(1)
finally:
    sock.close()

sys.exit(0)
PY
    then
      printf '[test-automated-suite][recovery] communication socket ready host=%s port=%s attempts=%s\n' \
        "$host" "$port" "$attempt" >>"$log_file"
      return 0
    fi

    attempt=$((attempt + 1))
    sleep 1
  done

  printf '[test-automated-suite][recovery] communication socket wait timed out host=%s port=%s attempts=%s\n' \
    "$host" "$port" "$max_attempts" >>"$log_file"
  return 1
}

run_logged_command_with_mode_recovery() {
  local mode="$1"
  local log_file="$2"
  shift 2

  local attempt=1
  local max_attempts=2
  local exit_code=0
  local stale_container=""
  local container_names=""
  local errexit_was_set=0

  if [[ $- == *e* ]]; then
    errexit_was_set=1
  fi

  mkdir -p "$(dirname "$log_file")"
  : > "$log_file"

  while [ "$attempt" -le "$max_attempts" ]; do
    if [ "$errexit_was_set" -eq 1 ]; then
      set +e
    fi

    "$@" >>"$log_file" 2>&1
    exit_code=$?

    if [ "$errexit_was_set" -eq 1 ]; then
      set -e
    fi

    if [ "$exit_code" -eq 0 ]; then
      return 0
    fi

    if [ "$attempt" -ge "$max_attempts" ]; then
      return "$exit_code"
    fi

    printf '[test-automated-suite][recovery] command failed for mode=%s attempt=%s exit_code=%s; recovering stack and retrying\n' \
      "$mode" "$attempt" "$exit_code" >>"$log_file"

    container_names="$(docker ps -a --format '{{.Names}}' 2>/dev/null || true)"
    for stale_container in $container_names; do
      case "$stale_container" in
        *pixel-sm-server-shootmania-1)
          docker rm -f "$stale_container" >>"$log_file" 2>&1 || true
          ;;
      esac
    done

    env PIXEL_SM_DEV_COMPOSE_FILES="$AUTOMATED_COMPOSE_FILES" bash "$DEV_MODE_SCRIPT" "$mode" relaunch >>"$log_file" 2>&1 || true
    wait_for_comm_socket_after_recovery "$COMM_SOCKET_HOST" "$COMM_SOCKET_PORT" "$COMM_SOCKET_WAIT_ATTEMPTS" "$log_file" || true

    attempt=$((attempt + 1))
  done

  return "$exit_code"
}

ensure_required_scripts() {
  [ -f "$DEV_MODE_SCRIPT" ] || fail "Missing script: ${DEV_MODE_SCRIPT}"
  [ -f "$LAUNCH_VALIDATION_SCRIPT" ] || fail "Missing script: ${LAUNCH_VALIDATION_SCRIPT}"
  [ -f "$MODE_MATRIX_VALIDATION_SCRIPT" ] || fail "Missing script: ${MODE_MATRIX_VALIDATION_SCRIPT}"
  [ -f "$WAVE3_REPLAY_SCRIPT" ] || fail "Missing script: ${WAVE3_REPLAY_SCRIPT}"
  [ -f "$WAVE4_REPLAY_SCRIPT" ] || fail "Missing script: ${WAVE4_REPLAY_SCRIPT}"
  [ -f "$ADMIN_SIMULATION_SCRIPT" ] || fail "Missing script: ${ADMIN_SIMULATION_SCRIPT}"
  [ -f "$VETO_SIMULATION_SCRIPT" ] || fail "Missing script: ${VETO_SIMULATION_SCRIPT}"
  [ -f "$ACK_STUB_SCRIPT" ] || fail "Missing script: ${ACK_STUB_SCRIPT}"
  [ -f "$DEV_PLUGIN_SYNC_SCRIPT" ] || fail "Missing script: ${DEV_PLUGIN_SYNC_SCRIPT}"
  [ -d "$ADMIN_ACTION_SPECS_DIR" ] || fail "Missing directory: ${ADMIN_ACTION_SPECS_DIR}"
  [ -d "$ADMIN_LINK_AUTH_CASE_SPECS_DIR" ] || fail "Missing directory: ${ADMIN_LINK_AUTH_CASE_SPECS_DIR}"
  [ -d "$VETO_CHECK_SPECS_DIR" ] || fail "Missing directory: ${VETO_CHECK_SPECS_DIR}"

  local spec_file=""
  local admin_spec_count=0
  local admin_link_auth_case_spec_count=0
  local veto_spec_count=0

  shopt -s nullglob
  for spec_file in "$ADMIN_ACTION_SPECS_DIR"/*.sh; do
    [ -f "$spec_file" ] || continue
    admin_spec_count=$((admin_spec_count + 1))
  done

  for spec_file in "$ADMIN_LINK_AUTH_CASE_SPECS_DIR"/*.sh; do
    [ -f "$spec_file" ] || continue
    admin_link_auth_case_spec_count=$((admin_link_auth_case_spec_count + 1))
  done

  for spec_file in "$VETO_CHECK_SPECS_DIR"/*.sh; do
    [ -f "$spec_file" ] || continue
    veto_spec_count=$((veto_spec_count + 1))
  done
  shopt -u nullglob

  [ "$admin_spec_count" -gt 0 ] || fail "No admin action spec scripts found in ${ADMIN_ACTION_SPECS_DIR}"
  [ "$admin_link_auth_case_spec_count" -gt 0 ] || fail "No admin link-auth case spec scripts found in ${ADMIN_LINK_AUTH_CASE_SPECS_DIR}"
  [ "$veto_spec_count" -gt 0 ] || fail "No veto check spec scripts found in ${VETO_CHECK_SPECS_DIR}"
}

run_mode_profile_apply() {
  local mode="$1"
  local mode_dir="${RUN_DIR}/modes/${mode}"
  local log_file="${mode_dir}/mode-apply.log"

  mkdir -p "$mode_dir"

  run_check_command \
    "mode.${mode}.profile_apply" \
    "1" \
    "Apply mode profile via dev-mode-compose.sh" \
    "$log_file" \
    run_logged_command_with_mode_recovery "$mode" "$log_file" \
      env PIXEL_SM_DEV_COMPOSE_FILES="$AUTOMATED_COMPOSE_FILES" bash "$DEV_MODE_SCRIPT" "$mode" relaunch
}

run_mode_launch_validation() {
  local mode="$1"
  local mode_dir="${RUN_DIR}/modes/${mode}"
  local validation_dir="${mode_dir}/launch-validation"
  local log_file="${mode_dir}/launch-validation-run.log"
  local build_images="0"

  mkdir -p "$validation_dir"

  if [ "$SMOKE_BUILD_PENDING" = "1" ]; then
    build_images="$AUTOMATED_BUILD_FIRST_SMOKE"
    SMOKE_BUILD_PENDING=0
  fi

  run_check_command \
    "mode.${mode}.launch_validation" \
    "1" \
    "Run validate-dev-stack-launch.sh for mode" \
    "$validation_dir" \
    run_logged_command_with_mode_recovery "$mode" "$log_file" \
      env \
        PIXEL_SM_QA_COMPOSE_FILES="$AUTOMATED_COMPOSE_FILES" \
        PIXEL_SM_QA_ARTIFACT_DIR="$validation_dir" \
        PIXEL_SM_QA_BUILD_IMAGES="$build_images" \
        PIXEL_SM_QA_HEALTH_TIMEOUT_SECONDS="120" \
        PIXEL_SM_QA_XMLRPC_WAIT_SECONDS="90" \
        bash "$LAUNCH_VALIDATION_SCRIPT"
}

resolve_latest_artifact() {
  local glob_pattern="$1"
  local resolved=""
  local candidate=""

  shopt -s nullglob
  for candidate in $glob_pattern; do
    resolved="$candidate"
  done
  shopt -u nullglob

  if [ -z "$resolved" ]; then
    return 1
  fi

  printf '%s' "$resolved"
}

resolve_latest_admin_sim_dir() {
  local output_root="$1"
  resolve_latest_artifact "${output_root}/admin-payload-sim-*"
}

resolve_latest_veto_sim_dir() {
  local output_root="$1"
  resolve_latest_artifact "${output_root}/veto-payload-sim-*"
}

record_missing_marker_check() {
  local check_id="$1"
  local description="$2"
  local artifact_path="$3"

  TOTAL_CHECKS=$((TOTAL_CHECKS + 1))
  FAILED_CHECKS=$((FAILED_CHECKS + 1))
  REQUIRED_CHECK_FAILURES=$((REQUIRED_CHECK_FAILURES + 1))

  append_check_result \
    "$check_id" \
    "1" \
    "failed" \
    "1" \
    "0" \
    "$description" \
    "$artifact_path"
  log "check=${check_id} status=failed required=1 reason=marker_file_missing"
}

validate_wave4_plugin_only_markers_file() {
  local markers_file="$1"

  python3 - "$markers_file" <<'PY'
import json
import sys

markers_file = sys.argv[1]
with open(markers_file, "r", encoding="utf-8") as handle:
    payload = json.load(handle)

if payload.get("validation_profile") != "plugin_only":
    raise SystemExit("validation_profile is not plugin_only")

if not payload.get("profile_passed"):
    raise SystemExit("profile_passed=false")

checks = payload.get("plugin_only_checks")
if not isinstance(checks, dict):
    raise SystemExit("plugin_only_checks missing")

if checks.get("identity_fields_valid") is not True:
    raise SystemExit("identity_fields_valid=false")
PY
}

run_mode_wave4_plugin_only() {
  local mode="$1"
  local mode_dir="${RUN_DIR}/modes/${mode}"
  local wave4_dir="${mode_dir}/wave4-plugin-only"
  local log_file="${mode_dir}/wave4-plugin-only-run.log"
  local markers_file=""

  mkdir -p "$wave4_dir"

  run_check_command \
    "mode.${mode}.wave4_plugin_only.replay" \
    "1" \
    "Run replay-extended-telemetry-wave4.sh with plugin_only profile" \
    "$wave4_dir" \
    run_logged_command_with_mode_recovery "$mode" "$log_file" \
      env \
        PIXEL_SM_QA_COMPOSE_FILES="$AUTOMATED_COMPOSE_FILES" \
        PIXEL_SM_QA_ARTIFACT_DIR="$wave4_dir" \
        PIXEL_SM_QA_TELEMETRY_INJECT_FIXTURES="0" \
        PIXEL_SM_QA_TELEMETRY_MARKER_PROFILE="plugin_only" \
        bash "$WAVE4_REPLAY_SCRIPT"

  markers_file="$(resolve_latest_artifact "${wave4_dir}/wave4-telemetry-*-markers.json" || true)"
  if [ -z "$markers_file" ]; then
    record_missing_marker_check "mode.${mode}.wave4_plugin_only.markers" "Validate plugin-only marker report fields" "$wave4_dir"
    return
  fi

  run_check_command \
    "mode.${mode}.wave4_plugin_only.markers" \
    "1" \
    "Validate plugin-only marker report fields" \
    "$markers_file" \
    validate_wave4_plugin_only_markers_file "$markers_file"
}

validate_wave3_markers_file() {
  local markers_file="$1"

  python3 - "$markers_file" <<'PY'
import json
import sys

markers_file = sys.argv[1]
with open(markers_file, "r", encoding="utf-8") as handle:
    payload = json.load(handle)

if payload.get("all_markers_passed") is not True:
    raise SystemExit("wave3 all_markers_passed=false")
PY
}

validate_wave4_strict_markers_file() {
  local markers_file="$1"

  python3 - "$markers_file" <<'PY'
import json
import sys

markers_file = sys.argv[1]
with open(markers_file, "r", encoding="utf-8") as handle:
    payload = json.load(handle)

if payload.get("validation_profile") != "strict":
    raise SystemExit("validation_profile is not strict")

if payload.get("profile_passed") is not True:
    raise SystemExit("profile_passed=false")

if payload.get("strict_markers_passed") is not True:
    raise SystemExit("strict_markers_passed=false")
PY
}

run_elite_strict_gate() {
  local strict_dir="${RUN_DIR}/strict/elite"
  local wave3_dir="${strict_dir}/wave3"
  local wave4_dir="${strict_dir}/wave4"
  local wave3_log_file="${strict_dir}/wave3-run.log"
  local wave4_log_file="${strict_dir}/wave4-run.log"
  local markers_file=""

  mkdir -p "$wave3_dir" "$wave4_dir"

  run_check_command \
    "strict.elite.profile_apply" \
    "1" \
    "Apply elite mode profile before strict gate" \
    "$strict_dir" \
    run_logged_command_with_mode_recovery elite "${strict_dir}/mode-apply.log" \
      env PIXEL_SM_DEV_COMPOSE_FILES="$AUTOMATED_COMPOSE_FILES" bash "$DEV_MODE_SCRIPT" elite relaunch

  run_check_command \
    "strict.elite.wave3.replay" \
    "1" \
    "Run strict wave-3 telemetry replay in elite" \
    "$wave3_dir" \
    run_logged_command_with_mode_recovery elite "$wave3_log_file" \
      env \
        PIXEL_SM_QA_COMPOSE_FILES="$AUTOMATED_COMPOSE_FILES" \
        PIXEL_SM_QA_ARTIFACT_DIR="$wave3_dir" \
        PIXEL_SM_QA_TELEMETRY_INJECT_FIXTURES="1" \
        bash "$WAVE3_REPLAY_SCRIPT"

  markers_file="$(resolve_latest_artifact "${wave3_dir}/wave3-telemetry-*-markers.json" || true)"
  if [ -z "$markers_file" ]; then
    record_missing_marker_check "strict.elite.wave3.markers" "Validate wave-3 strict marker report" "$wave3_dir"
  else
    run_check_command \
      "strict.elite.wave3.markers" \
      "1" \
      "Validate wave-3 strict marker report" \
      "$markers_file" \
      validate_wave3_markers_file "$markers_file"
  fi

  run_check_command \
    "strict.elite.wave4.replay" \
    "1" \
    "Run strict wave-4 telemetry replay in elite" \
    "$wave4_dir" \
    run_logged_command_with_mode_recovery elite "$wave4_log_file" \
      env \
        PIXEL_SM_QA_COMPOSE_FILES="$AUTOMATED_COMPOSE_FILES" \
        PIXEL_SM_QA_ARTIFACT_DIR="$wave4_dir" \
        PIXEL_SM_QA_TELEMETRY_INJECT_FIXTURES="1" \
        PIXEL_SM_QA_TELEMETRY_MARKER_PROFILE="strict" \
        bash "$WAVE4_REPLAY_SCRIPT"

  markers_file="$(resolve_latest_artifact "${wave4_dir}/wave4-telemetry-*-markers.json" || true)"
  if [ -z "$markers_file" ]; then
    record_missing_marker_check "strict.elite.wave4.markers" "Validate wave-4 strict marker report" "$wave4_dir"
  else
    run_check_command \
      "strict.elite.wave4.markers" \
      "1" \
      "Validate wave-4 strict marker report" \
      "$markers_file" \
      validate_wave4_strict_markers_file "$markers_file"
  fi
}

run_optional_mode_matrix_validation() {
  local mode_validation_dir="${RUN_DIR}/optional/mode-validation"
  local log_file="${mode_validation_dir}/mode-validation.log"

  mkdir -p "$mode_validation_dir"

  run_check_command_logged \
    "optional.mode_validation.matrix" \
    "0" \
    "Run optional validate-mode-launch-matrix.sh" \
    "$mode_validation_dir" \
    "$log_file" \
    env \
      PIXEL_SM_QA_COMPOSE_FILES="$AUTOMATED_COMPOSE_FILES" \
      PIXEL_SM_QA_ARTIFACT_DIR="$mode_validation_dir" \
      PIXEL_SM_QA_MODE_BUILD_FIRST="0" \
      bash "$MODE_MATRIX_VALIDATION_SCRIPT"
}

wait_for_http_health() {
  local url="$1"
  local max_attempts="$2"
  local attempt=1

  while [ "$attempt" -le "$max_attempts" ]; do
    if curl -sS "$url" >/dev/null 2>&1; then
      return 0
    fi

    attempt=$((attempt + 1))
    sleep 1
  done

  return 1
}

wait_for_capture_lines() {
  local capture_file="$1"
  local minimum_lines="$2"
  local max_attempts="$3"
  local attempt=1
  local line_count=0

  while [ "$attempt" -le "$max_attempts" ]; do
    line_count=0
    if [ -f "$capture_file" ]; then
      line_count="$(python3 - "$capture_file" <<'PY'
import sys

capture_file = sys.argv[1]
line_count = 0
with open(capture_file, "r", encoding="utf-8") as handle:
    for raw_line in handle:
        if raw_line.strip():
            line_count += 1

print(line_count)
PY
)"
    fi

    if [ "$line_count" -ge "$minimum_lines" ]; then
      return 0
    fi

    attempt=$((attempt + 1))
    sleep 1
  done

  return 1
}

start_ack_stub_process() {
  local output_file="$1"
  local port="$2"
  local stub_log_file="$3"
  local stub_pid=""

  mkdir -p "$(dirname "$output_file")"
  : > "$output_file"
  mkdir -p "$(dirname "$stub_log_file")"

  bash "$ACK_STUB_SCRIPT" \
    --output "$output_file" \
    --bind-host "$ACK_STUB_BIND_HOST" \
    --port "$port" \
    --receipt-id "$ACK_STUB_RECEIPT_ID" \
    >"$stub_log_file" 2>&1 &

  stub_pid="$!"
  register_cleanup_pid "$stub_pid"

  if ! wait_for_http_health "http://${ACK_STUB_BIND_HOST}:${port}/healthz" 20; then
    if kill -0 "$stub_pid" >/dev/null 2>&1; then
      kill "$stub_pid" >/dev/null 2>&1 || true
      wait "$stub_pid" >/dev/null 2>&1 || true
    fi
    return 1
  fi

  if ! kill -0 "$stub_pid" >/dev/null 2>&1; then
    wait "$stub_pid" >/dev/null 2>&1 || true
    return 1
  fi

  ACTIVE_ACK_STUB_PID="$stub_pid"
  return 0
}

start_ack_stub_process_with_retry() {
  local output_file="$1"
  local base_port="$2"
  local stub_log_file="$3"
  local max_attempts="$4"
  local attempt=0
  local candidate_port=0

  ACTIVE_ACK_STUB_PID=""
  ACTIVE_ACK_STUB_PORT=""

  while [ "$attempt" -lt "$max_attempts" ]; do
    candidate_port=$((base_port + attempt))
    if start_ack_stub_process "$output_file" "$candidate_port" "$stub_log_file"; then
      ACTIVE_ACK_STUB_PORT="$candidate_port"
      return 0
    fi

    attempt=$((attempt + 1))
  done

  return 1
}

stop_ack_stub_process() {
  local stub_pid="$1"

  if [ -z "$stub_pid" ]; then
    return
  fi

  if kill -0 "$stub_pid" >/dev/null 2>&1; then
    kill "$stub_pid" >/dev/null 2>&1 || true
    wait "$stub_pid" >/dev/null 2>&1 || true
  fi
}

validate_admin_payload_capture() {
  local capture_file="$1"
  local output_file="$2"
  local mode="$3"

  python3 - "$capture_file" "$output_file" "$mode" <<'PY'
import json
import sys

capture_file = sys.argv[1]
output_file = sys.argv[2]
mode = sys.argv[3]

required_admin_fields = [
    "action_name",
    "action_domain",
    "action_type",
    "action_phase",
    "target_scope",
    "initiator_kind",
]

errors = []
line_count = 0
admin_action_envelopes = 0
observed_action_names = []
identity_failures = []
admin_field_failures = []

with open(capture_file, "r", encoding="utf-8") as handle:
    for raw_line in handle:
        line = raw_line.strip()
        if not line:
            continue
        line_count += 1

        try:
            row = json.loads(line)
        except Exception:
            continue

        request = row.get("request")
        if not isinstance(request, dict):
            continue

        envelope = request.get("envelope")
        if not isinstance(envelope, dict):
            continue

        payload = envelope.get("payload")
        if not isinstance(payload, dict):
            continue

        admin_action = payload.get("admin_action")
        if not isinstance(admin_action, dict):
            continue

        admin_action_envelopes += 1

        event_name = str(envelope.get("event_name") or "").strip()
        event_id = str(envelope.get("event_id") or "").strip()
        idempotency_key = str(envelope.get("idempotency_key") or "").strip()
        source_sequence = envelope.get("source_sequence")
        source_sequence_ok = isinstance(source_sequence, int) and source_sequence > 0

        if not event_name or not event_id or not idempotency_key or not source_sequence_ok:
            identity_failures.append(
                {
                    "event_name": event_name,
                    "event_id": event_id,
                    "idempotency_key": idempotency_key,
                    "source_sequence": source_sequence,
                }
            )

        missing_admin_fields = []
        for field in required_admin_fields:
            value = admin_action.get(field)
            if value is None:
                missing_admin_fields.append(field)
                continue
            if isinstance(value, str) and not value.strip():
                missing_admin_fields.append(field)

        if missing_admin_fields:
            admin_field_failures.append(
                {
                    "event_name": event_name,
                    "missing_fields": missing_admin_fields,
                }
            )

        action_name = str(admin_action.get("action_name") or "").strip()
        if action_name:
            observed_action_names.append(action_name)

if line_count == 0:
    errors.append("capture file has zero lines")

if admin_action_envelopes == 0:
    errors.append("no admin_action envelopes observed in capture")

if identity_failures:
    errors.append("admin_action envelopes missing required identity fields")

if admin_field_failures:
    errors.append("admin_action payload missing required admin fields")

result = {
    "schema": "pixel-sm-automated-suite-admin-payload.v1",
    "mode": mode,
    "capture_file": capture_file,
    "line_count": line_count,
    "admin_action_envelope_count": admin_action_envelopes,
    "observed_action_names": sorted(set(observed_action_names)),
    "required_admin_fields": required_admin_fields,
    "identity_failures": identity_failures,
    "admin_field_failures": admin_field_failures,
    "errors": errors,
    "passed": len(errors) == 0,
}

with open(output_file, "w", encoding="utf-8") as handle:
    json.dump(result, handle, indent=2, ensure_ascii=True)
    handle.write("\n")

if errors:
    raise SystemExit(1)
PY
}

run_mode_admin_payload_assertions() {
  local mode="$1"
  local mode_index="$2"
  local mode_dir="${RUN_DIR}/modes/${mode}"
  local admin_dir="${mode_dir}/admin-sim"
  local payload_root="${admin_dir}/payload"
  local payload_output_root="${payload_root}/matrix"
  local capture_file="${mode_dir}/admin-capture.ndjson"
  local stub_log_file="${payload_root}/ack-stub.log"
  local payload_validation_file="${payload_root}/admin-payload-validation.json"
  local payload_run_dir=""
  local stub_port_base=""

  mkdir -p "$payload_root"
  stub_port_base=$((ACK_STUB_PORT_BASE + mode_index * 20))
  ACTIVE_ACK_STUB_PID=""
  ACTIVE_ACK_STUB_PORT=""

  run_check_command \
    "mode.${mode}.admin.payload.stub_ready" \
    "1" \
    "Start local ACK stub for admin payload capture" \
    "$stub_log_file" \
    start_ack_stub_process_with_retry "$capture_file" "$stub_port_base" "$stub_log_file" 20

  if [ -z "$ACTIVE_ACK_STUB_PID" ] || [ -z "$ACTIVE_ACK_STUB_PORT" ]; then
    return
  fi

  run_check_command \
    "mode.${mode}.admin.payload.dev_sync" \
    "1" \
    "Restart plugin transport toward local ACK stub" \
    "$payload_root" \
    run_logged_command_with_mode_recovery "$mode" "${payload_root}/dev-sync.log" \
      env \
        PIXEL_SM_DEV_COMPOSE_FILES="$AUTOMATED_COMPOSE_FILES" \
        PIXEL_SM_DEV_ARTIFACT_DIR="$payload_root" \
        PIXEL_CONTROL_LINK_SERVER_URL="$AUTOMATED_LINK_SERVER_URL" \
        PIXEL_CONTROL_LINK_TOKEN="$AUTOMATED_LINK_TOKEN" \
        PIXEL_CONTROL_API_BASE_URL="http://host.docker.internal:${ACTIVE_ACK_STUB_PORT}" \
        bash "$DEV_PLUGIN_SYNC_SCRIPT"

  run_check_command \
    "mode.${mode}.admin.payload.matrix" \
    "1" \
    "Run admin matrix while ACK stub capture is active" \
    "$payload_output_root" \
    run_logged_command_with_mode_recovery "$mode" "${payload_root}/matrix-command.log" \
      env \
        PIXEL_SM_ADMIN_SIM_COMPOSE_FILES="$AUTOMATED_COMPOSE_FILES" \
        PIXEL_SM_ADMIN_SIM_OUTPUT_ROOT="$payload_output_root" \
        bash "$ADMIN_SIMULATION_SCRIPT" matrix

  run_check_command \
    "mode.${mode}.admin.payload.capture_wait" \
    "1" \
    "Wait for captured admin payload lines" \
    "$capture_file" \
    wait_for_capture_lines "$capture_file" 1 20

  stop_ack_stub_process "$ACTIVE_ACK_STUB_PID"
  ACTIVE_ACK_STUB_PID=""
  ACTIVE_ACK_STUB_PORT=""

  payload_run_dir="$(resolve_latest_admin_sim_dir "$payload_output_root" || true)"
  if [ -z "$payload_run_dir" ]; then
    record_missing_marker_check "mode.${mode}.admin.payload.assertions" "Validate admin payload capture fields" "$payload_output_root"
    return
  fi

  run_check_command \
    "mode.${mode}.admin.payload.assertions" \
    "1" \
    "Validate captured admin payload fields and envelope identity" \
    "$payload_validation_file" \
    validate_admin_payload_capture "$capture_file" "$payload_validation_file" "$mode"
}

validate_admin_response_payload_correlation() {
  local response_validation_file="$1"
  local payload_validation_file="$2"
  local output_file="$3"
  local mode="$4"

  python3 - "$response_validation_file" "$payload_validation_file" "$output_file" "$mode" <<'PY'
import json
import sys

response_validation_file = sys.argv[1]
payload_validation_file = sys.argv[2]
output_file = sys.argv[3]
mode = sys.argv[4]

correlation_aliases = {
    "warmup.extend": {"warmup.extend", "warmup.start", "warmup.status", "warmup.end"},
    "warmup.end": {"warmup.end", "warmup.status"},
    "pause.start": {"pause.start", "pause.status", "pause.end"},
    "pause.end": {"pause.end", "pause.status"},
}

with open(response_validation_file, "r", encoding="utf-8") as handle:
    response_payload = json.load(handle)

with open(payload_validation_file, "r", encoding="utf-8") as handle:
    payload_payload = json.load(handle)

response_success_actions = response_payload.get("successful_action_names") or []
response_required_actions = response_payload.get("correlation_required_action_names") or []
payload_observed_actions = payload_payload.get("observed_action_names") or []

response_success_actions = sorted({str(name).strip() for name in response_success_actions if str(name).strip()})
response_required_actions = sorted({str(name).strip() for name in response_required_actions if str(name).strip()})
payload_observed_actions = sorted({str(name).strip() for name in payload_observed_actions if str(name).strip()})

if not response_required_actions:
    response_required_actions = response_success_actions

payload_observed_set = set(payload_observed_actions)
missing_correlations = []
matched_correlations = []

for required_action_name in response_required_actions:
    accepted_aliases = correlation_aliases.get(required_action_name, {required_action_name})
    matched_aliases = sorted(alias for alias in accepted_aliases if alias in payload_observed_set)
    if matched_aliases:
        matched_correlations.append(
            {
                "required_action_name": required_action_name,
                "matched_aliases": matched_aliases,
            }
        )
        continue

    missing_correlations.append(
        {
            "required_action_name": required_action_name,
            "accepted_aliases": sorted(accepted_aliases),
        }
    )

errors = []
if missing_correlations:
    errors.append("required successful admin actions missing payload correlation")

result = {
    "schema": "pixel-sm-automated-suite-admin-correlation.v1",
    "mode": mode,
    "response_validation_file": response_validation_file,
    "payload_validation_file": payload_validation_file,
    "successful_action_names": response_success_actions,
    "correlation_required_action_names": response_required_actions,
    "payload_observed_action_names": payload_observed_actions,
    "matched_correlations": matched_correlations,
    "missing_correlations": missing_correlations,
    "errors": errors,
    "passed": len(errors) == 0,
}

with open(output_file, "w", encoding="utf-8") as handle:
    json.dump(result, handle, indent=2, ensure_ascii=True)
    handle.write("\n")

if errors:
    raise SystemExit(1)
PY
}

run_mode_admin_correlation_assertions() {
  local mode="$1"
  local mode_dir="${RUN_DIR}/modes/${mode}"
  local response_validation_file="${mode_dir}/admin-sim/response/admin-response-validation.json"
  local payload_validation_file="${mode_dir}/admin-sim/payload/admin-payload-validation.json"
  local correlation_file="${mode_dir}/admin-sim/admin-correlation-validation.json"

  if [ ! -f "$response_validation_file" ]; then
    record_missing_marker_check "mode.${mode}.admin.correlation" "Correlate successful admin responses with payload evidence" "$response_validation_file"
    return
  fi

  if [ ! -f "$payload_validation_file" ]; then
    record_missing_marker_check "mode.${mode}.admin.correlation" "Correlate successful admin responses with payload evidence" "$payload_validation_file"
    return
  fi

  run_check_command \
    "mode.${mode}.admin.correlation" \
    "1" \
    "Correlate successful admin responses with payload evidence" \
    "$correlation_file" \
    validate_admin_response_payload_correlation "$response_validation_file" "$payload_validation_file" "$correlation_file" "$mode"
}

validate_veto_response_artifacts() {
  local matrix_run_dir="$1"
  local output_file="$2"
  local mode="$3"
  local veto_check_specs_dir="$4"

  python3 - "$matrix_run_dir" "$output_file" "$mode" "$veto_check_specs_dir" <<'PY'
import json
import os
import subprocess
import sys

matrix_run_dir = sys.argv[1]
output_file = sys.argv[2]
mode = sys.argv[3]
veto_check_specs_dir = sys.argv[4]

errors = []
required_check_ids = []

if not os.path.isdir(veto_check_specs_dir):
    errors.append(f"missing veto check specs directory: {veto_check_specs_dir}")
else:
    try:
        spec_paths = sorted(
            os.path.join(veto_check_specs_dir, name)
            for name in os.listdir(veto_check_specs_dir)
            if name.endswith(".sh")
        )
    except Exception as exc:
        errors.append(f"failed to list veto check specs: {exc}")
        spec_paths = []

    for spec_path in spec_paths:
        if not os.path.isfile(spec_path):
            continue
        try:
            output = subprocess.check_output(["bash", spec_path], stderr=subprocess.STDOUT, text=True)
        except subprocess.CalledProcessError as exc:
            errors.append(f"failed to read veto check spec {spec_path}: {(exc.output or '').strip()}")
            continue

        check_id = (output or "").strip()
        if check_id:
            required_check_ids.append(check_id)

required_check_ids = sorted(set(required_check_ids))
if not required_check_ids:
    errors.append("no required veto check ids loaded from descriptor scripts")

matrix_validation_file = os.path.join(matrix_run_dir, "matrix-validation.json")
matrix_payload = {}
if not os.path.isfile(matrix_validation_file):
    errors.append(f"missing matrix validation artifact: {matrix_validation_file}")
else:
    try:
        with open(matrix_validation_file, "r", encoding="utf-8") as handle:
            matrix_payload = json.load(handle)
    except Exception as exc:
        errors.append(f"invalid matrix validation json: {exc}")
        matrix_payload = {}

checks = matrix_payload.get("checks") if isinstance(matrix_payload, dict) else None
if not isinstance(checks, list):
    checks = []

check_index = {}
for row in checks:
    if not isinstance(row, dict):
        continue
    check_id = str(row.get("id") or "").strip()
    if not check_id:
        continue
    check_index[check_id] = row

missing_required_check_ids = [check_id for check_id in required_check_ids if check_id not in check_index]
failed_required_check_ids = [
    check_id
    for check_id in required_check_ids
    if check_id in check_index and not bool(check_index[check_id].get("passed"))
]

if missing_required_check_ids:
    errors.append("matrix-validation is missing required veto checks")

if failed_required_check_ids:
    errors.append("matrix-validation reported failed required veto checks")

matrix_overall_passed = bool(matrix_payload.get("overall_passed")) if isinstance(matrix_payload, dict) else False
if not matrix_overall_passed:
    errors.append("matrix-validation overall_passed=false")

result = {
    "schema": "pixel-sm-automated-suite-veto-response.v1",
    "mode": mode,
    "matrix_run_dir": matrix_run_dir,
    "matrix_validation_file": matrix_validation_file,
    "required_check_ids": required_check_ids,
    "missing_required_check_ids": missing_required_check_ids,
    "failed_required_check_ids": failed_required_check_ids,
    "matrix_required_failed_checks": matrix_payload.get("required_failed_checks") if isinstance(matrix_payload, dict) else [],
    "matrix_overall_passed": matrix_overall_passed,
    "errors": errors,
    "passed": len(errors) == 0,
}

with open(output_file, "w", encoding="utf-8") as handle:
    json.dump(result, handle, indent=2, ensure_ascii=True)
    handle.write("\n")

if errors:
    raise SystemExit(1)
PY
}

run_mode_veto_response_assertions() {
  local mode="$1"
  local mode_dir="${RUN_DIR}/modes/${mode}"
  local veto_dir="${mode_dir}/veto-sim"
  local matrix_output_root="${veto_dir}/matrix"
  local matrix_run_dir=""
  local validation_file="${veto_dir}/veto-response-validation.json"
  local tournament_best_of="1"

  mkdir -p "$veto_dir"

  if [ "$mode" = "elite" ]; then
    tournament_best_of="3"
  fi

  run_check_command \
    "mode.${mode}.veto.profile_apply" \
    "1" \
    "Re-apply mode profile before veto matrix" \
    "$veto_dir" \
    run_logged_command_with_mode_recovery "$mode" "${veto_dir}/mode-apply.log" \
      env PIXEL_SM_DEV_COMPOSE_FILES="$AUTOMATED_COMPOSE_FILES" bash "$DEV_MODE_SCRIPT" "$mode" relaunch

  run_check_command \
    "mode.${mode}.veto.matrix" \
    "1" \
    "Run simulate-veto-control-payloads.sh matrix" \
    "$matrix_output_root" \
    run_logged_command_with_mode_recovery "$mode" "${veto_dir}/matrix-command.log" \
      env \
        PIXEL_SM_VETO_SIM_COMPOSE_FILES="$AUTOMATED_COMPOSE_FILES" \
        PIXEL_SM_VETO_SIM_OUTPUT_ROOT="$matrix_output_root" \
        PIXEL_SM_VETO_SIM_CAPTAIN_A="automated_${mode}_captain_a" \
        PIXEL_SM_VETO_SIM_CAPTAIN_B="automated_${mode}_captain_b" \
        PIXEL_SM_VETO_SIM_MATCHMAKING_DURATION="6" \
        PIXEL_SM_VETO_SIM_WAIT_EXTRA_SECONDS="1" \
        PIXEL_SM_VETO_SIM_TOURNAMENT_BEST_OF="$tournament_best_of" \
        bash "$VETO_SIMULATION_SCRIPT" matrix

  matrix_run_dir="$(resolve_latest_veto_sim_dir "$matrix_output_root" || true)"
  if [ -z "$matrix_run_dir" ]; then
    record_missing_marker_check "mode.${mode}.veto.response_assertions" "Validate veto matrix response payloads" "$matrix_output_root"
    return
  fi

  run_check_command \
    "mode.${mode}.veto.response_assertions" \
    "1" \
    "Validate veto matrix response payloads" \
    "$validation_file" \
    validate_veto_response_artifacts "$matrix_run_dir" "$validation_file" "$mode" "$VETO_CHECK_SPECS_DIR"
}

validate_admin_response_artifacts() {
  local list_actions_file="$1"
  local matrix_run_dir="$2"
  local output_file="$3"
  local mode="$4"
  local action_specs_dir="$5"

  python3 - "$list_actions_file" "$matrix_run_dir" "$output_file" "$mode" "$action_specs_dir" <<'PY'
import glob
import json
import os
import subprocess
import sys

list_actions_file = sys.argv[1]
matrix_run_dir = sys.argv[2]
output_file = sys.argv[3]
mode = sys.argv[4]
action_specs_dir = sys.argv[5]

allowed_codes = {
    "ok",
    "capability_unavailable",
    "unsupported_mode",
    "native_rejected",
    "native_exception",
    "missing_parameters",
    "invalid_parameters",
    "target_not_found",
    "actor_not_found",
}

def load_required_action_keys(spec_dir: str):
    keys = []
    for script_path in sorted(glob.glob(os.path.join(spec_dir, "*.sh"))):
        if not os.path.isfile(script_path):
            continue
        try:
            output = subprocess.check_output(["bash", script_path], stderr=subprocess.STDOUT, text=True)
        except subprocess.CalledProcessError as exc:
            raise SystemExit(f"failed to read admin action spec {script_path}: {exc.output}")

        key = (output or "").strip()
        if key:
            keys.append(key)

    keys = sorted(set(keys))
    if not keys:
        raise SystemExit(f"no admin action keys loaded from {spec_dir}")
    return keys


required_action_keys = load_required_action_keys(action_specs_dir)

correlation_required_action_allowlist = {
    "warmup.extend",
    "warmup.end",
    "pause.end",
}

errors = []

with open(list_actions_file, "r", encoding="utf-8") as handle:
    list_payload = json.load(handle)

if list_payload.get("error") is not False:
    errors.append("list-actions error flag is not false")

list_data = list_payload.get("data")
if not isinstance(list_data, dict):
    errors.append("list-actions data payload is missing")
    list_data = {}

if list_data.get("enabled") is not True:
    errors.append("list-actions data.enabled is not true")

communication = list_data.get("communication")
if not isinstance(communication, dict):
    errors.append("list-actions communication payload missing")
else:
    if communication.get("exec") != "PixelControl.Admin.ExecuteAction":
        errors.append("communication.exec mismatch")
    if communication.get("list") != "PixelControl.Admin.ListActions":
        errors.append("communication.list mismatch")

actions = list_data.get("actions")
if not isinstance(actions, dict):
    errors.append("list-actions actions payload missing")
    actions = {}

missing_action_keys = [name for name in required_action_keys if name not in actions]
if missing_action_keys:
    errors.append("list-actions missing required action definitions")

execute_files = sorted(glob.glob(os.path.join(matrix_run_dir, "execute-*.json")))
if not execute_files:
    errors.append("matrix run did not produce execute-*.json artifacts")

executed_action_names = []
successful_action_names = []
observed_codes = []
shape_failures = []
unexpected_codes = []

for execute_file in execute_files:
    with open(execute_file, "r", encoding="utf-8") as handle:
        payload = json.load(handle)

    if "error" not in payload or not isinstance(payload["error"], bool):
        shape_failures.append({"file": execute_file, "reason": "missing_or_invalid_error_flag"})
        continue

    data = payload.get("data")
    if not isinstance(data, dict):
        shape_failures.append({"file": execute_file, "reason": "missing_data_object"})
        continue

    required_data_fields = ("action_name", "success", "code", "message")
    missing_data_fields = [field for field in required_data_fields if field not in data]
    if missing_data_fields:
        shape_failures.append({"file": execute_file, "reason": "missing_data_fields", "fields": missing_data_fields})
        continue

    action_name = str(data.get("action_name") or "")
    action_success = data.get("success")
    action_code = str(data.get("code") or "")

    if action_name:
        executed_action_names.append(action_name)

    if not isinstance(action_success, bool):
        shape_failures.append({"file": execute_file, "reason": "data.success_not_bool"})
        continue

    observed_codes.append(action_code)
    if action_code not in allowed_codes:
        unexpected_codes.append({"file": execute_file, "code": action_code, "action_name": action_name})

    if action_success:
        successful_action_names.append(action_name)

if shape_failures:
    errors.append("execute response shape validation failed")

if unexpected_codes:
    errors.append("execute responses contain unexpected action codes")

successful_action_names = sorted(set(successful_action_names))
correlation_required_action_names = [
    action_name
    for action_name in successful_action_names
    if action_name in correlation_required_action_allowlist
]

result = {
    "schema": "pixel-sm-automated-suite-admin-response.v1",
    "mode": mode,
    "list_actions_file": list_actions_file,
    "matrix_run_dir": matrix_run_dir,
    "required_action_keys": required_action_keys,
    "missing_action_keys": missing_action_keys,
    "execute_file_count": len(execute_files),
    "executed_action_names": sorted(set(executed_action_names)),
    "successful_action_names": successful_action_names,
    "correlation_required_action_names": correlation_required_action_names,
    "correlation_required_action_allowlist": sorted(correlation_required_action_allowlist),
    "observed_codes": sorted(set(observed_codes)),
    "allowed_codes": sorted(allowed_codes),
    "shape_failures": shape_failures,
    "unexpected_codes": unexpected_codes,
    "errors": errors,
    "passed": len(errors) == 0,
}

with open(output_file, "w", encoding="utf-8") as handle:
    json.dump(result, handle, indent=2, ensure_ascii=True)
    handle.write("\n")

if errors:
    raise SystemExit(1)
PY
}

load_admin_link_auth_case_ids() {
  local spec_dir="$1"
  local spec_file=""
  local case_id=""
  local duplicate_case_id=""
  local -a case_ids=()

  if [ ! -d "$spec_dir" ]; then
    fail "Missing admin link-auth case specs directory: ${spec_dir}"
  fi

  shopt -s nullglob
  for spec_file in "$spec_dir"/*.sh; do
    [ -f "$spec_file" ] || continue
    case_id="$(bash "$spec_file" 2>/dev/null || true)"
    case_id="$(trim_whitespace "$case_id")"
    if [ -z "$case_id" ]; then
      continue
    fi

    duplicate_case_id=""
    for duplicate_case_id in "${case_ids[@]}"; do
      if [ "$duplicate_case_id" = "$case_id" ]; then
        duplicate_case_id="$case_id"
        break
      fi
      duplicate_case_id=""
    done

    if [ -n "$duplicate_case_id" ]; then
      continue
    fi

    case_ids+=("$case_id")
  done
  shopt -u nullglob

  if [ "${#case_ids[@]}" -eq 0 ]; then
    fail "No admin link-auth case ids loaded from ${spec_dir}"
  fi

  printf '%s\n' "${case_ids[@]}"
}

run_mode_admin_link_auth_assertions() {
  local mode="$1"
  local mode_dir="${RUN_DIR}/modes/${mode}"
  local admin_dir="${mode_dir}/admin-sim"
  local response_root="${admin_dir}/response"
  local link_auth_root="${response_root}/link-auth"
  local -a link_auth_cases=()
  local -a command_args=()
  local case_id=""
  local output_root=""

  mkdir -p "$link_auth_root"

  mapfile -t link_auth_cases < <(load_admin_link_auth_case_ids "$ADMIN_LINK_AUTH_CASE_SPECS_DIR")

  for case_id in "${link_auth_cases[@]}"; do
    output_root="${link_auth_root}/${case_id}"
    command_args=("matrix" "link_auth_case=${case_id}")

    case "$case_id" in
      valid)
        command_args+=("link_token=${AUTOMATED_LINK_TOKEN}")
        if [ -n "$AUTOMATED_LINK_SERVER_LOGIN" ]; then
          command_args+=("link_server_login=${AUTOMATED_LINK_SERVER_LOGIN}")
        fi
        ;;
      invalid)
        command_args+=("link_token=invalid-token")
        if [ -n "$AUTOMATED_LINK_SERVER_LOGIN" ]; then
          command_args+=("link_server_login=${AUTOMATED_LINK_SERVER_LOGIN}")
        fi
        ;;
      mismatch)
        command_args+=("link_token=${AUTOMATED_LINK_TOKEN}")
        if [ -n "$AUTOMATED_LINK_SERVER_LOGIN" ]; then
          command_args+=("link_server_login=${AUTOMATED_LINK_SERVER_LOGIN}")
        fi
        ;;
      missing)
        ;;
      *)
        fail "Unsupported admin link-auth case id from descriptors: ${case_id}"
        ;;
    esac

    run_check_command \
      "mode.${mode}.admin.link_auth.${case_id}" \
      "1" \
      "Run admin matrix link-auth case (${case_id})" \
      "$output_root" \
      run_logged_command_with_mode_recovery "$mode" "${admin_dir}/link-auth-${case_id}-command.log" \
        env \
          PIXEL_SM_ADMIN_SIM_COMPOSE_FILES="$AUTOMATED_COMPOSE_FILES" \
          PIXEL_SM_ADMIN_SIM_OUTPUT_ROOT="$output_root" \
          bash "$ADMIN_SIMULATION_SCRIPT" "${command_args[@]}"
  done
}

run_mode_admin_response_assertions() {
  local mode="$1"
  local mode_dir="${RUN_DIR}/modes/${mode}"
  local admin_dir="${mode_dir}/admin-sim"
  local response_root="${admin_dir}/response"
  local list_output_root="${response_root}/list-actions"
  local matrix_output_root="${response_root}/matrix"
  local list_run_dir=""
  local matrix_run_dir=""
  local list_actions_file=""
  local validation_file="${response_root}/admin-response-validation.json"

  mkdir -p "$response_root"

  run_check_command \
    "mode.${mode}.admin.dev_sync" \
    "1" \
    "Prepare stack for admin payload simulation via dev-plugin-sync.sh" \
    "$admin_dir" \
    run_logged_command_with_mode_recovery "$mode" "${admin_dir}/dev-sync.log" \
      env \
        PIXEL_SM_DEV_COMPOSE_FILES="$AUTOMATED_COMPOSE_FILES" \
        PIXEL_SM_DEV_ARTIFACT_DIR="$admin_dir" \
        PIXEL_CONTROL_LINK_SERVER_URL="$AUTOMATED_LINK_SERVER_URL" \
        PIXEL_CONTROL_LINK_TOKEN="$AUTOMATED_LINK_TOKEN" \
        bash "$DEV_PLUGIN_SYNC_SCRIPT"

  run_check_command \
    "mode.${mode}.admin.list_actions" \
    "1" \
    "Run simulate-admin-control-payloads.sh list-actions" \
    "$list_output_root" \
    run_logged_command_with_mode_recovery "$mode" "${admin_dir}/list-actions-command.log" \
      env \
        PIXEL_SM_ADMIN_SIM_COMPOSE_FILES="$AUTOMATED_COMPOSE_FILES" \
        PIXEL_SM_ADMIN_SIM_OUTPUT_ROOT="$list_output_root" \
        bash "$ADMIN_SIMULATION_SCRIPT" list-actions

  run_check_command \
    "mode.${mode}.admin.matrix" \
    "1" \
    "Run simulate-admin-control-payloads.sh matrix" \
    "$matrix_output_root" \
    run_logged_command_with_mode_recovery "$mode" "${admin_dir}/matrix-command.log" \
      env \
        PIXEL_SM_ADMIN_SIM_COMPOSE_FILES="$AUTOMATED_COMPOSE_FILES" \
        PIXEL_SM_ADMIN_SIM_OUTPUT_ROOT="$matrix_output_root" \
        bash "$ADMIN_SIMULATION_SCRIPT" matrix

  list_run_dir="$(resolve_latest_admin_sim_dir "$list_output_root" || true)"
  if [ -z "$list_run_dir" ]; then
    record_missing_marker_check "mode.${mode}.admin.response_assertions" "Validate admin response payloads" "$list_output_root"
    return
  fi

  matrix_run_dir="$(resolve_latest_admin_sim_dir "$matrix_output_root" || true)"
  if [ -z "$matrix_run_dir" ]; then
    record_missing_marker_check "mode.${mode}.admin.response_assertions" "Validate admin response payloads" "$matrix_output_root"
    return
  fi

  list_actions_file="${list_run_dir}/list-actions.json"
  if [ ! -f "$list_actions_file" ]; then
    record_missing_marker_check "mode.${mode}.admin.response_assertions" "Validate admin response payloads" "$list_run_dir"
    return
  fi

  run_check_command \
    "mode.${mode}.admin.response_assertions" \
    "1" \
    "Validate admin list-actions and execute response payloads" \
    "$validation_file" \
    validate_admin_response_artifacts "$list_actions_file" "$matrix_run_dir" "$validation_file" "$mode" "$ADMIN_ACTION_SPECS_DIR"
}

parse_args() {
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --modes)
        if [ "$#" -lt 2 ]; then
          printf '[test-automated-suite][error] Missing value for --modes\n' >&2
          exit 1
        fi
        REQUESTED_MODES_CSV="$2"
        shift 2
        ;;
      --with-mode-matrix-validation)
        WITH_MODE_MATRIX_VALIDATION=1
        shift
        ;;
      --with-mode-smoke)
        log "Deprecated option --with-mode-smoke detected; using --with-mode-matrix-validation behavior."
        WITH_MODE_MATRIX_VALIDATION=1
        shift
        ;;
      -h|--help)
        usage
        exit 0
        ;;
      *)
        printf '[test-automated-suite][error] Unknown option: %s\n' "$1" >&2
        usage
        exit 1
        ;;
    esac
  done

  REQUESTED_MODES_CSV="$(trim_whitespace "$REQUESTED_MODES_CSV")"
  if [ -z "$REQUESTED_MODES_CSV" ]; then
    fail "Mode list cannot be empty."
  fi
}

parse_requested_modes() {
  local raw_entry=""
  local mode=""

  IFS=',' read -r -a raw_modes <<< "$REQUESTED_MODES_CSV"

  for raw_entry in "${raw_modes[@]}"; do
    mode="$(trim_whitespace "$raw_entry")"
    if [ -z "$mode" ]; then
      continue
    fi
    REQUESTED_MODES+=("$mode")
  done

  if [ "${#REQUESTED_MODES[@]}" -eq 0 ]; then
    fail "No valid modes parsed from --modes='${REQUESTED_MODES_CSV}'."
  fi
}

command_version_line() {
  local command_name="$1"
  local version_output=""

  version_output="$($command_name --version 2>/dev/null || true)"
  if [ -z "$version_output" ]; then
    printf 'unknown'
    return
  fi

  printf '%s' "${version_output%%$'\n'*}"
}

capture_command_versions() {
  DOCKER_VERSION="$(command_version_line docker)"
  BASH_VERSION_LINE="$(command_version_line bash)"
  PYTHON3_VERSION="$(command_version_line python3)"
  PHP_VERSION_LINE="$(command_version_line php)"
  CURL_VERSION_LINE="$(command_version_line curl)"
}

emit_run_manifest() {
  local manifest_file="$1"
  shift

  python3 - "$manifest_file" "$RUN_TIMESTAMP" "$RUN_DIR" "$PROJECT_DIR" "$DEFAULT_MODES_CSV" "$REQUESTED_MODES_CSV" "$WITH_MODE_MATRIX_VALIDATION" "$DOCKER_VERSION" "$BASH_VERSION_LINE" "$PYTHON3_VERSION" "$PHP_VERSION_LINE" "$CURL_VERSION_LINE" "$@" <<'PY'
import json
import sys
import time

manifest_path = sys.argv[1]
run_timestamp = sys.argv[2]
run_dir = sys.argv[3]
project_dir = sys.argv[4]
default_modes_csv = sys.argv[5]
requested_modes_csv = sys.argv[6]
with_mode_matrix_validation = sys.argv[7] == "1"
docker_version = sys.argv[8]
bash_version_line = sys.argv[9]
python3_version = sys.argv[10]
php_version_line = sys.argv[11]
curl_version_line = sys.argv[12]
requested_modes = sys.argv[13:]

payload = {
    "schema": "pixel-sm-automated-suite-run-manifest.v1",
    "run_timestamp": run_timestamp,
    "run_directory": run_dir,
    "created_at_epoch": int(time.time()),
    "requested": {
        "default_modes_csv": default_modes_csv,
        "requested_modes_csv": requested_modes_csv,
        "requested_modes": requested_modes,
        "with_mode_matrix_validation": with_mode_matrix_validation,
    },
    "environment": {
        "project_dir": project_dir,
        "command_versions": {
            "docker": docker_version,
            "bash": bash_version_line,
            "python3": python3_version,
            "php": php_version_line,
            "curl": curl_version_line,
        },
    },
}

with open(manifest_path, "w", encoding="utf-8") as handle:
    json.dump(payload, handle, indent=2, ensure_ascii=True)
    handle.write("\n")
PY
}

init_run_context() {
  RUN_TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
  RUN_DIR="${PROJECT_DIR}/logs/qa/automated-suite-${RUN_TIMESTAMP}"
  RUN_MANIFEST_FILE="${RUN_DIR}/run-manifest.json"
  COVERAGE_INVENTORY_FILE="${RUN_DIR}/coverage-inventory.json"
  CHECK_RESULTS_FILE="${RUN_DIR}/check-results.ndjson"
  SUITE_SUMMARY_JSON_FILE="${RUN_DIR}/suite-summary.json"
  SUITE_SUMMARY_MD_FILE="${RUN_DIR}/suite-summary.md"
  MANUAL_HANDOFF_FILE="${RUN_DIR}/manual-handoff.md"

  mkdir -p "$RUN_DIR"
  : > "$CHECK_RESULTS_FILE"

  emit_run_manifest "$RUN_MANIFEST_FILE" "${REQUESTED_MODES[@]}"
}

emit_coverage_inventory() {
  python3 - "$COVERAGE_INVENTORY_FILE" <<'PY'
import json
import sys

output_file = sys.argv[1]

payload = {
    "schema": "pixel-sm-automated-suite-coverage.v1",
    "automatable": [
        "stack.launch_validation",
        "telemetry.wave4_plugin_only",
        "telemetry.wave3_strict",
        "telemetry.wave4_strict",
        "admin.list_actions.response_shape",
        "admin.execute.response_shape",
        "admin.execute.allowed_codes",
        "admin.payload.identity_fields",
        "admin.payload.required_fields",
        "admin.response_payload.correlation",
        "veto.start.response_shape",
        "veto.matchmaking.vote_flow",
        "veto.tournament.action_flow",
        "veto.status.transition_validation",
    ],
    "partial": [
        "admin.execute.mode_dependent_fallback_codes",
        "admin.execute.placeholder_target_fallbacks",
        "strict_replay_fixture_assisted_marker_closure",
    ],
    "manual_only": [
        "combat.OnShoot",
        "combat.OnHit",
        "combat.OnNearMiss",
        "combat.OnArmorEmpty",
        "combat.OnCapture",
        "real_client_combat_correctness",
        "real_session_veto_draft_actor_behavior",
    ],
}

with open(output_file, "w", encoding="utf-8") as handle:
    json.dump(payload, handle, indent=2, ensure_ascii=True)
    handle.write("\n")
PY
}

emit_suite_reports() {
  local modes_csv=""
  modes_csv="$(IFS=,; printf '%s' "${REQUESTED_MODES[*]}")"

  python3 - "$CHECK_RESULTS_FILE" "$SUITE_SUMMARY_JSON_FILE" "$SUITE_SUMMARY_MD_FILE" "$MANUAL_HANDOFF_FILE" "$RUN_DIR" "$modes_csv" "$WITH_MODE_MATRIX_VALIDATION" <<'PY'
import json
import sys
from pathlib import Path

check_results_file = Path(sys.argv[1])
suite_summary_json_file = Path(sys.argv[2])
suite_summary_md_file = Path(sys.argv[3])
manual_handoff_file = Path(sys.argv[4])
run_dir = sys.argv[5]
modes_csv = sys.argv[6]
with_mode_matrix_validation = sys.argv[7] == "1"

checks = []
if check_results_file.exists():
    with check_results_file.open("r", encoding="utf-8") as handle:
        for raw_line in handle:
            line = raw_line.strip()
            if not line:
                continue
            checks.append(json.loads(line))

total_checks = len(checks)
passed_checks = sum(1 for check in checks if check.get("status") == "passed")
failed_checks = [check for check in checks if check.get("status") != "passed"]
required_failed_checks = [check for check in failed_checks if bool(check.get("required"))]
optional_failed_checks = [check for check in failed_checks if not bool(check.get("required"))]

overall_status = "passed" if not required_failed_checks else "failed"
primary_failure = required_failed_checks[0] if required_failed_checks else (failed_checks[0] if failed_checks else None)

summary = {
    "schema": "pixel-sm-automated-suite-summary.v1",
    "run_directory": run_dir,
    "requested_modes_csv": modes_csv,
    "requested_modes": [mode for mode in modes_csv.split(",") if mode],
    "with_mode_matrix_validation": with_mode_matrix_validation,
    "overall_status": overall_status,
    "counts": {
        "total_checks": total_checks,
        "passed_checks": passed_checks,
        "failed_checks": len(failed_checks),
        "required_failed_checks": len(required_failed_checks),
        "optional_failed_checks": len(optional_failed_checks),
    },
    "primary_failure": {
        "check_id": primary_failure.get("check_id") if primary_failure else None,
        "description": primary_failure.get("description") if primary_failure else None,
        "artifact_path": primary_failure.get("artifact_path") if primary_failure else None,
        "exit_code": primary_failure.get("exit_code") if primary_failure else None,
    },
    "failed_required_checks": required_failed_checks,
    "failed_optional_checks": optional_failed_checks,
    "artifact_files": {
        "run_manifest": str(Path(run_dir) / "run-manifest.json"),
        "coverage_inventory": str(Path(run_dir) / "coverage-inventory.json"),
        "check_results": str(Path(run_dir) / "check-results.ndjson"),
        "suite_summary_json": str(suite_summary_json_file),
        "suite_summary_md": str(suite_summary_md_file),
        "manual_handoff": str(manual_handoff_file),
    },
}

with suite_summary_json_file.open("w", encoding="utf-8") as handle:
    json.dump(summary, handle, indent=2, ensure_ascii=True)
    handle.write("\n")

lines = []
lines.append(f"# Automated suite summary ({overall_status})")
lines.append("")
lines.append(f"- Run directory: `{run_dir}`")
lines.append(f"- Requested modes: `{modes_csv}`")
lines.append(f"- Optional mode matrix validation: `{with_mode_matrix_validation}`")
lines.append(f"- Total checks: `{total_checks}`")
lines.append(f"- Passed checks: `{passed_checks}`")
lines.append(f"- Failed checks: `{len(failed_checks)}`")
lines.append(f"- Required failed checks: `{len(required_failed_checks)}`")
lines.append(f"- Optional failed checks: `{len(optional_failed_checks)}`")
lines.append("")

if primary_failure:
    lines.append("## Primary failure")
    lines.append("")
    lines.append(f"- Failing check id: `{primary_failure.get('check_id')}`")
    lines.append(f"- Failure reason: `{primary_failure.get('description')}`")
    lines.append(f"- Primary artifact: `{primary_failure.get('artifact_path')}`")
    lines.append(f"- Exit code: `{primary_failure.get('exit_code')}`")
    lines.append("")

if required_failed_checks:
    lines.append("## Required check failures")
    lines.append("")
    for check in required_failed_checks:
        lines.append(
            "- "
            + f"`{check.get('check_id')}` reason=`{check.get('description')}` artifact=`{check.get('artifact_path')}` exit_code=`{check.get('exit_code')}`"
        )
    lines.append("")

if optional_failed_checks:
    lines.append("## Optional check failures")
    lines.append("")
    for check in optional_failed_checks:
        lines.append(
            "- "
            + f"`{check.get('check_id')}` reason=`{check.get('description')}` artifact=`{check.get('artifact_path')}` exit_code=`{check.get('exit_code')}`"
        )
    lines.append("")

if not failed_checks:
    lines.append("## Result")
    lines.append("")
    lines.append("- All required checks passed.")
    lines.append("")

suite_summary_md_file.write_text("\n".join(lines).rstrip() + "\n", encoding="utf-8")

manual_lines = [
    "# Manual-only handoff",
    "",
    "Automated suite intentionally leaves live combat telemetry verification to manual real-client sessions.",
    "",
    "## Explicit manual-only combat callbacks",
    "",
    "- `OnShoot`",
    "- `OnHit`",
    "- `OnNearMiss`",
    "- `OnArmorEmpty`",
    "- `OnCapture`",
    "",
    "## Canonical manual references",
    "",
    "- `PLAN-real-client-live-combat-stats-qa.md`",
    "- `pixel-sm-server/logs/manual/wave5-real-client-20260220/MANUAL-TEST-MATRIX.md`",
    "",
    "## Automated run context",
    "",
    f"- Suite status: `{overall_status}`",
    f"- Run directory: `{run_dir}`",
]

manual_handoff_file.write_text("\n".join(manual_lines).rstrip() + "\n", encoding="utf-8")
PY
}

finalize_suite_exit() {
  if [ "$REQUIRED_CHECK_FAILURES" -gt 0 ]; then
    SUITE_EXIT_CODE=1
  else
    SUITE_EXIT_CODE=0
  fi

  emit_coverage_inventory
  emit_suite_reports

  log "Checks total=${TOTAL_CHECKS} passed=${PASSED_CHECKS} failed=${FAILED_CHECKS} required_failures=${REQUIRED_CHECK_FAILURES}"
  if [ "$SUITE_EXIT_CODE" -eq 0 ]; then
    log "Suite status: passed"
  else
    log "Suite status: failed"
  fi

  log "Coverage inventory: ${COVERAGE_INVENTORY_FILE}"
  log "Suite summary JSON: ${SUITE_SUMMARY_JSON_FILE}"
  log "Suite summary MD: ${SUITE_SUMMARY_MD_FILE}"
  log "Manual handoff: ${MANUAL_HANDOFF_FILE}"

  return "$SUITE_EXIT_CODE"
}

trap cleanup EXIT INT TERM

main() {
  parse_args "$@"
  parse_requested_modes
  require_command bash
  require_command docker
  require_command python3
  require_command php
  require_command curl
  ensure_required_scripts
  capture_command_versions
  init_run_context

  log "Requested modes CSV: ${REQUESTED_MODES_CSV}"
  log "Optional mode matrix validation: ${WITH_MODE_MATRIX_VALIDATION}"
  log "Compose files: ${AUTOMATED_COMPOSE_FILES}"
  log "Admin link server url: ${AUTOMATED_LINK_SERVER_URL}"
  log "Admin link token status: $( [ -n "$AUTOMATED_LINK_TOKEN" ] && printf '%s' "set(length=${#AUTOMATED_LINK_TOKEN})" || printf '%s' 'unset' )"
  log "Run directory: ${RUN_DIR}"
  log "Run manifest: ${RUN_MANIFEST_FILE}"
  log "Check ledger: ${CHECK_RESULTS_FILE}"

  mode_index=0
  for mode in "${REQUESTED_MODES[@]}"; do
    mode_index=$((mode_index + 1))
    run_mode_profile_apply "$mode"
    run_mode_launch_validation "$mode"
    run_mode_wave4_plugin_only "$mode"
    run_mode_admin_response_assertions "$mode"
    run_mode_admin_link_auth_assertions "$mode"
    run_mode_admin_payload_assertions "$mode" "$mode_index"
    run_mode_admin_correlation_assertions "$mode"
    run_mode_veto_response_assertions "$mode"
  done

  run_elite_strict_gate

  if [ "$WITH_MODE_MATRIX_VALIDATION" = "1" ]; then
    run_optional_mode_matrix_validation
  fi

  finalize_suite_exit
}

main "$@"
