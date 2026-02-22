#!/usr/bin/env bash

local_invalid_actor=""

matrix_run_step "start.tournament" "$METHOD_START" \
  "mode=tournament_draft" \
  "captain_a=${MATRIX_CAPTAIN_A}" \
  "captain_b=${MATRIX_CAPTAIN_B}" \
  "best_of=${MATRIX_TOURNAMENT_BEST_OF}" \
  "starter=${MATRIX_TOURNAMENT_STARTER}" \
  "action_timeout_seconds=${MATRIX_TOURNAMENT_TIMEOUT}" \
  "launch_immediately=0"

if [[ "$MATRIX_LAST_ACTION_SUCCESS" == "true" && "$MATRIX_LAST_ACTION_CODE" == "tournament_started" ]]; then
  MATRIX_TOURNAMENT_STARTED=1
else
  MATRIX_TOURNAMENT_STARTED=0
fi

if [[ "$MATRIX_LAST_ACTION_SUCCESS" == "false" && "$MATRIX_LAST_ACTION_CODE" == "map_pool_too_small_for_bo" ]]; then
  MATRIX_TOURNAMENT_LIMITED_MODE=1
else
  MATRIX_TOURNAMENT_LIMITED_MODE=0
fi

matrix_run_step "status.tournament.loop_0" "$METHOD_STATUS"
matrix_refresh_status_from_last

if [[ "$MATRIX_TOURNAMENT_STARTED" -eq 1 && "$MATRIX_STATUS_ACTIVE" == "1" && "$MATRIX_STATUS_MODE" == "tournament_draft" && "$MATRIX_STATUS_SESSION_STATUS" == "running" ]]; then
  case "$MATRIX_STATUS_CURRENT_TEAM" in
    team_a)
      local_invalid_actor="$MATRIX_CAPTAIN_B"
      ;;
    team_b)
      local_invalid_actor="$MATRIX_CAPTAIN_A"
      ;;
    system)
      local_invalid_actor=""
      ;;
    *)
      local_invalid_actor=""
      ;;
  esac

  if [[ -n "$local_invalid_actor" ]]; then
    matrix_run_step "action.tournament.invalid_actor" "$METHOD_ACTION" \
      "actor_login=${local_invalid_actor}" \
      "map=1"
    matrix_run_step "status.tournament.after_invalid_actor" "$METHOD_STATUS"
    matrix_refresh_status_from_last
  else
    warn "Skipping action.tournament.invalid_actor; current step team '${MATRIX_STATUS_CURRENT_TEAM}' has no deterministic actor restriction gate."
  fi
else
  warn "Skipping action.tournament.invalid_actor; tournament is not running."
fi
