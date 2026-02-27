#!/bin/sh
set -e

echo "[pixel-control-api] Running Prisma migrations..."
npx prisma migrate deploy

echo "[pixel-control-api] Starting application..."
exec node dist/main.js
