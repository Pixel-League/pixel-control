#!/usr/bin/env bash
# Generate TypeScript API client from pixel-control-server Swagger JSON.
# Requires pixel-control-server to be running on port 3000.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
SWAGGER_URL="http://localhost:3000/api/docs-json"
OUTPUT_DIR="$PROJECT_DIR/src/lib/api/generated"

mkdir -p "$OUTPUT_DIR"

echo "[sdk:generate] Fetching Swagger JSON from $SWAGGER_URL ..."

# Fetch swagger.json with a 5-second timeout
set +e
HTTP_CODE=$(curl -s -o "$OUTPUT_DIR/swagger.json" -w "%{http_code}" --connect-timeout 5 --max-time 10 "$SWAGGER_URL" 2>/dev/null)
CURL_EXIT=$?
set -e

if [ "$CURL_EXIT" -ne 0 ]; then
  echo "[sdk:generate] ERROR: Could not reach pixel-control-server at $SWAGGER_URL"
  echo "[sdk:generate] Make sure the server is running: cd pixel-control-server && npm run start:dev"
  rm -f "$OUTPUT_DIR/swagger.json"
  exit 1
fi

if [ "$HTTP_CODE" != "200" ]; then
  echo "[sdk:generate] ERROR: Server returned HTTP $HTTP_CODE"
  rm -f "$OUTPUT_DIR/swagger.json"
  exit 1
fi

echo "[sdk:generate] Swagger JSON saved. Generating TypeScript client..."

cd "$PROJECT_DIR"
npx openapi-typescript-codegen \
  --input "$OUTPUT_DIR/swagger.json" \
  --output "$OUTPUT_DIR" \
  --client fetch \
  --name PixelControlApi

echo "[sdk:generate] SDK generated successfully in $OUTPUT_DIR"
