#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

printf '[deprecated] scripts/qa-wave3-telemetry-replay.sh -> scripts/replay-core-telemetry-wave3.sh\n' >&2
exec bash "${SCRIPT_DIR}/replay-core-telemetry-wave3.sh" "$@"
