#!/usr/bin/env bash

tournament_actor_login=""
tournament_selection="1"

if [[ "$MATRIX_TOURNAMENT_STARTED" -ne 1 ]]; then
  warn "Skipping tournament action loop; tournament did not start."
  return 0
fi

while true; do
  if [[ "$MATRIX_STATUS_ACTIVE" != "1" || "$MATRIX_STATUS_MODE" != "tournament_draft" || "$MATRIX_STATUS_SESSION_STATUS" != "running" ]]; then
    break
  fi

  MATRIX_TOURNAMENT_GUARD=$((MATRIX_TOURNAMENT_GUARD + 1))
  if [[ "$MATRIX_TOURNAMENT_GUARD" -gt "$MATRIX_TOURNAMENT_MAX_STEPS" ]]; then
    warn "Tournament matrix loop exceeded ${MATRIX_TOURNAMENT_MAX_STEPS} action/status cycles; stopping loop."
    break
  fi

  case "$MATRIX_STATUS_CURRENT_TEAM" in
    team_a)
      tournament_actor_login="$MATRIX_CAPTAIN_A"
      ;;
    team_b)
      tournament_actor_login="$MATRIX_CAPTAIN_B"
      ;;
    system)
      tournament_actor_login="$MATRIX_CAPTAIN_A"
      ;;
    *)
      warn "Tournament status returned non-actionable team '${MATRIX_STATUS_CURRENT_TEAM}'; stopping loop."
      break
      ;;
  esac

  if [[ -z "$tournament_actor_login" ]]; then
    warn "Resolved empty actor login for team '${MATRIX_STATUS_CURRENT_TEAM}'; stopping loop."
    break
  fi

  matrix_run_step "action.tournament.step_${MATRIX_TOURNAMENT_GUARD}.${MATRIX_STATUS_CURRENT_TEAM}.map_${tournament_selection}" "$METHOD_ACTION" \
    "actor_login=${tournament_actor_login}" \
    "map=${tournament_selection}"

  matrix_run_step "status.tournament.loop_${MATRIX_TOURNAMENT_GUARD}" "$METHOD_STATUS"
  matrix_refresh_status_from_last
done
