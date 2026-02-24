#!/usr/bin/env bash

if [[ "${MATRIX_LINK_AUTH_CASE:-}" != "mismatch" ]]; then
  return 0
fi

matrix_step_expect_code 'match.bo.get' 'link_server_mismatch'
