#!/usr/bin/env bash

if [[ "${MATRIX_LINK_AUTH_CASE:-}" != "valid" ]]; then
  return 0
fi

matrix_step_expect_not_codes 'match.bo.get' 'link_auth_missing,link_auth_invalid,link_server_mismatch,admin_command_unauthorized'
