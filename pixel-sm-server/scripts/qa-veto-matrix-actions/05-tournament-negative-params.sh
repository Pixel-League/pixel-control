#!/usr/bin/env bash

matrix_run_step "start.tournament.captain_missing" "$METHOD_START" \
  "mode=tournament_draft" \
  "captain_a=${MATRIX_CAPTAIN_A}" \
  "captain_b=" \
  "best_of=${MATRIX_TOURNAMENT_BEST_OF}" \
  "starter=${MATRIX_TOURNAMENT_STARTER}" \
  "action_timeout_seconds=${MATRIX_TOURNAMENT_TIMEOUT}" \
  "launch_immediately=0"

matrix_run_step "start.tournament.captain_conflict" "$METHOD_START" \
  "mode=tournament_draft" \
  "captain_a=${MATRIX_CAPTAIN_A}" \
  "captain_b=${MATRIX_CAPTAIN_A}" \
  "best_of=${MATRIX_TOURNAMENT_BEST_OF}" \
  "starter=${MATRIX_TOURNAMENT_STARTER}" \
  "action_timeout_seconds=${MATRIX_TOURNAMENT_TIMEOUT}" \
  "launch_immediately=0"
