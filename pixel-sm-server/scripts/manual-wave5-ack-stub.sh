#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

OUTPUT_FILE="${PIXEL_SM_MANUAL_ACK_OUTPUT:-${PROJECT_DIR}/logs/manual/wave5-real-client-$(date +%Y%m%d)/SESSION-session-001-payload.ndjson}"
BIND_HOST="${PIXEL_SM_MANUAL_ACK_BIND_HOST:-127.0.0.1}"
ACK_PORT="${PIXEL_SM_MANUAL_ACK_PORT:-18080}"
RECEIPT_ID="${PIXEL_SM_MANUAL_ACK_RECEIPT_ID:-wave5-manual}"

log() {
  printf '[pixel-sm-manual-ack] %s\n' "$1"
}

usage() {
  cat <<'EOF'
Usage:
  bash scripts/manual-wave5-ack-stub.sh [--output FILE] [--bind-host HOST] [--port PORT] [--receipt-id ID]

Options:
  --output      NDJSON capture file path
  --bind-host   Bind host for local ACK server (default: 127.0.0.1)
  --port        Bind port for local ACK server (default: 18080)
  --receipt-id  Receipt id returned in ACK payloads (default: wave5-manual)
EOF
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --output)
      if [ "$#" -lt 2 ]; then
        log "Missing value for --output"
        exit 1
      fi
      OUTPUT_FILE="$2"
      shift 2
      ;;
    --bind-host)
      if [ "$#" -lt 2 ]; then
        log "Missing value for --bind-host"
        exit 1
      fi
      BIND_HOST="$2"
      shift 2
      ;;
    --port)
      if [ "$#" -lt 2 ]; then
        log "Missing value for --port"
        exit 1
      fi
      ACK_PORT="$2"
      shift 2
      ;;
    --receipt-id)
      if [ "$#" -lt 2 ]; then
        log "Missing value for --receipt-id"
        exit 1
      fi
      RECEIPT_ID="$2"
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

mkdir -p "$(dirname "$OUTPUT_FILE")"
touch "$OUTPUT_FILE"

log "Starting local ACK stub at ${BIND_HOST}:${ACK_PORT}"
log "Capturing plugin envelopes in: ${OUTPUT_FILE}"
log "Stop with Ctrl+C"

python3 -u - "$BIND_HOST" "$ACK_PORT" "$OUTPUT_FILE" "$RECEIPT_ID" <<'PY'
import json
import sys
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

bind_host = sys.argv[1]
bind_port = int(sys.argv[2])
capture_file = sys.argv[3]
receipt_id = sys.argv[4]


class Handler(BaseHTTPRequestHandler):
    def _write_json(self, status_code, payload):
        encoded = json.dumps(payload).encode("utf-8")
        self.send_response(status_code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)

    def do_GET(self):
        if self.path == "/healthz":
            self._write_json(200, {"ok": True})
            return

        self._write_json(404, {"error": "not_found"})

    def do_POST(self):
        content_length = int(self.headers.get("Content-Length", "0"))
        raw_body = self.rfile.read(content_length)

        decoded_body = None
        try:
            decoded_body = json.loads(raw_body.decode("utf-8"))
        except Exception:
            decoded_body = {"raw_body": raw_body.decode("utf-8", errors="replace")}

        record = {
            "received_at": int(time.time()),
            "path": self.path,
            "request": decoded_body,
        }

        with open(capture_file, "a", encoding="utf-8") as capture_handle:
            capture_handle.write(json.dumps(record, ensure_ascii=True) + "\n")

        self._write_json(
            200,
            {
                "ack": {
                    "status": "accepted",
                    "disposition": "processed",
                    "receipt_id": receipt_id,
                }
            },
        )

    def log_message(self, fmt, *args):
        return


server = ThreadingHTTPServer((bind_host, bind_port), Handler)
server.serve_forever()
PY
