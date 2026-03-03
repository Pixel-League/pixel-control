# Pixel Control — Makefile
# Usage: make <target>

.PHONY: help \
	api-up api-down api-build api-test api-logs \
	ui-up ui-down ui-build \
	sm-up sm-down sm-logs sm-build sm-sync sm-hot-sync \
	up down status

# ─── Defaults ────────────────────────────────────────────────────────────────

SHELL := /bin/bash

API_DIR  := pixel-control-server
UI_DIR   := pixel-control-ui
SM_DIR   := pixel-sm-server

# ─── Help ────────────────────────────────────────────────────────────────────

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

# ─── Pixel Control Server (API — NestJS + PostgreSQL) ────────────────────────

api-up: ## Start API server (postgres + NestJS on port 3000)
	@cd $(API_DIR) && docker compose up -d postgres
	@echo "⏳ Waiting for PostgreSQL..."
	@cd $(API_DIR) && until docker compose exec -T postgres pg_isready -U pixel > /dev/null 2>&1; do sleep 1; done
	@cd $(API_DIR) && npx prisma migrate deploy --schema prisma/schema.prisma 2>/dev/null || true
	@cd $(API_DIR) && npm run build
	@cd $(API_DIR) && node --enable-source-maps dist/main.js &
	@echo "✅ API server started on http://localhost:3000/v1"

api-down: ## Stop API server + PostgreSQL
	@-lsof -ti:3000 2>/dev/null | xargs kill 2>/dev/null
	@cd $(API_DIR) && docker compose down
	@echo "✅ API server stopped"

api-build: ## Build API server (compile TypeScript)
	@cd $(API_DIR) && npm run build

api-test: ## Run API unit tests (vitest)
	@cd $(API_DIR) && npm run test

api-logs: ## Tail API server logs (attach to running process)
	@cd $(API_DIR) && docker compose logs -f postgres

# ─── Pixel Control UI (Vite + React) ────────────────────────────────────────

ui-up: ## Start UI dev server (Vite on port 5173)
	@cd $(UI_DIR) && npm run dev &
	@echo "✅ UI dev server started on http://localhost:5173"

ui-down: ## Stop UI dev server
	@-lsof -ti:5173 2>/dev/null | xargs kill 2>/dev/null
	@echo "✅ UI dev server stopped"

ui-build: ## Build UI for production
	@cd $(UI_DIR) && npm run build

# ─── Pixel SM Server (Docker — ShootMania + ManiaControl + MySQL) ────────────

sm-up: ## Start SM dev server Docker stack
	@cd $(SM_DIR) && docker compose up -d --build
	@echo "✅ SM server started (ManiaControl may take 30-60s to boot)"

sm-down: ## Stop SM dev server Docker stack
	@cd $(SM_DIR) && docker compose down
	@echo "✅ SM server stopped"

sm-build: ## Rebuild SM server Docker image
	@cd $(SM_DIR) && docker compose build

sm-logs: ## Tail SM server logs
	@cd $(SM_DIR) && docker compose logs -f shootmania

sm-sync: ## Sync plugin code + restart SM service (full restart)
	@cd $(SM_DIR) && bash scripts/dev-plugin-sync.sh

sm-hot-sync: ## Hot-sync plugin code (restart ManiaControl only)
	@cd $(SM_DIR) && bash scripts/dev-plugin-hot-sync.sh

# ─── All-in-one ──────────────────────────────────────────────────────────────

up: sm-up api-up ui-up ## Start everything (SM + API + UI)

down: ui-down api-down sm-down ## Stop everything

status: ## Show status of all services
	@echo "── API Server (port 3000) ──"
	@curl -s http://localhost:3000/v1 2>/dev/null && echo "" || echo "  ❌ Not running"
	@echo ""
	@echo "── UI Dev Server (port 5173) ──"
	@curl -s -o /dev/null -w "  ✅ Running (HTTP %{http_code})\n" http://localhost:5173 2>/dev/null || echo "  ❌ Not running"
	@echo ""
	@echo "── SM Server (Docker) ──"
	@cd $(SM_DIR) && docker compose ps --format '  {{.Name}}: {{.Status}}' 2>/dev/null || echo "  ❌ Not running"
	@echo ""
	@echo "── PostgreSQL (Docker) ──"
	@cd $(API_DIR) && docker compose ps --format '  {{.Name}}: {{.Status}}' 2>/dev/null || echo "  ❌ Not running"
