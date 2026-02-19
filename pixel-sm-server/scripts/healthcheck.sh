#!/usr/bin/env bash

set -eu

if [ -z "${PIXEL_SM_SERVER_ROOT:-}" ] || [ -z "${PIXEL_SM_XMLRPC_PORT:-}" ]; then
  exit 1
fi

if [ -z "${PIXEL_SM_DB_HOST:-}" ] || [ -z "${PIXEL_SM_DB_PORT:-}" ] || [ -z "${PIXEL_SM_DB_USER:-}" ] || [ -z "${PIXEL_SM_DB_PASSWORD:-}" ]; then
  exit 1
fi

if ! mysqladmin ping \
  -h"${PIXEL_SM_DB_HOST}" \
  -P"${PIXEL_SM_DB_PORT}" \
  -u"${PIXEL_SM_DB_USER}" \
  "-p${PIXEL_SM_DB_PASSWORD}" \
  --silent >/dev/null 2>&1; then
  exit 1
fi

maniacontrol_log_file="${PIXEL_SM_SERVER_ROOT}/ManiaControl/ManiaControl.log"
if [ ! -f "$maniacontrol_log_file" ]; then
  exit 1
fi

if ! grep -Fq "[PixelControl] Plugin loaded." "$maniacontrol_log_file"; then
  exit 1
fi

if ! bash -c "exec 3<>/dev/tcp/127.0.0.1/${PIXEL_SM_XMLRPC_PORT}" >/dev/null 2>&1; then
  exit 1
fi

exit 0
