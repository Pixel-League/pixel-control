#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

MANUAL_DIR="${PIXEL_SM_MANUAL_CHECK_DIR:-${PROJECT_DIR}/logs/manual/wave5-real-client-$(date +%Y%m%d)}"

log() {
  printf '[pixel-sm-manual-check] %s\n' "$1"
}

usage() {
  cat <<'EOF'
Usage:
  bash scripts/manual-wave5-evidence-check.sh [--manual-dir DIR]

Options:
  --manual-dir  Manual evidence directory containing README.md and INDEX.md
EOF
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --manual-dir)
      if [ "$#" -lt 2 ]; then
        log "Missing value for --manual-dir"
        exit 1
      fi
      MANUAL_DIR="$2"
      shift 2
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      log "Unknown option: $1"
      usage
      exit 1
      ;;
  esac
done

if [ ! -d "$MANUAL_DIR" ]; then
  log "Manual directory not found: ${MANUAL_DIR}"
  exit 1
fi

if [ ! -f "${MANUAL_DIR}/README.md" ]; then
  log "Missing README.md in ${MANUAL_DIR}"
  exit 1
fi

if [ ! -f "${MANUAL_DIR}/INDEX.md" ]; then
  log "Missing INDEX.md in ${MANUAL_DIR}"
  exit 1
fi

if [ ! -f "${MANUAL_DIR}/MANUAL-TEST-MATRIX.md" ]; then
  log "Missing MANUAL-TEST-MATRIX.md in ${MANUAL_DIR}"
  exit 1
fi

python3 - "$MANUAL_DIR" <<'PY'
import pathlib
import re
import sys

manual_dir = pathlib.Path(sys.argv[1])
index_file = manual_dir / "INDEX.md"

lines = index_file.read_text(encoding="utf-8").splitlines()
session_rows = []

for line in lines:
    stripped = line.strip()
    if not stripped.startswith("|"):
        continue

    cells = [cell.strip() for cell in stripped.strip("|").split("|")]
    if len(cells) < 6:
        continue

    session_id = cells[0]
    if not session_id or session_id.lower() == "session id" or set(session_id) == {"-"}:
        continue

    if session_id.lower().startswith("pending") or session_id.lower() in {"tbd", "todo"}:
        continue

    session_rows.append(
        {
            "session_id": session_id,
            "scenario_focus": cells[1],
            "payload_file_col": cells[2],
            "notes_file_col": cells[3],
            "evidence_file_col": cells[4],
            "status": cells[5].lower(),
        }
    )

if not session_rows:
    print("No actionable session rows found in INDEX.md", file=sys.stderr)
    sys.exit(1)

missing_items = []
invalid_sessions = []

for row in session_rows:
    session_id = row["session_id"]
    if not re.match(r"^[a-z0-9][a-z0-9_-]*$", session_id):
        invalid_sessions.append(session_id)

    expected_payload = manual_dir / f"SESSION-{session_id}-payload.ndjson"
    expected_notes = manual_dir / f"SESSION-{session_id}-notes.md"
    expected_evidence = manual_dir / f"SESSION-{session_id}-evidence.md"

    for label, expected_path in (
        ("payload", expected_payload),
        ("notes", expected_notes),
        ("evidence", expected_evidence),
    ):
        if not expected_path.exists():
            missing_items.append(f"{session_id}: missing {label} file ({expected_path.name})")

    if row["status"] in {"passed", "failed"} and expected_payload.exists() and expected_payload.stat().st_size == 0:
        missing_items.append(
            f"{session_id}: payload file is empty but status is '{row['status']}'"
        )

if invalid_sessions:
    print("Invalid session id format detected (expected lowercase [a-z0-9_-]):")
    for session_id in invalid_sessions:
        print(f"- {session_id}")

if missing_items:
    print("Manual evidence completeness check failed:")
    for item in missing_items:
        print(f"- {item}")
    sys.exit(1)

print("Manual evidence completeness check passed")
print(f"Validated sessions: {len(session_rows)}")
for row in session_rows:
    print(f"- {row['session_id']} ({row['status']})")
PY
