#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

printf '[deprecated] scripts/qa-veto-payload-sim.sh -> scripts/simulate-veto-control-payloads.sh\n' >&2
exec bash "${SCRIPT_DIR}/simulate-veto-control-payloads.sh" "$@"
