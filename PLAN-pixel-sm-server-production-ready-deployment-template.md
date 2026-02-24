# PLAN - Harden `pixel-sm-server` for production-ready deployment (2026-02-24)

## Context

- Purpose: make `pixel-sm-server/` easier to deploy in production-like environments while preserving current local-first developer workflows.
- Scope:
  - In scope:
    - Practical, non-breaking hardening checks for `pixel-sm-server/Dockerfile` and Compose runtime wiring.
    - A production deployment template strategy with prefilled safe defaults and clearly marked secret placeholders.
    - A deployment-first README refresh focused on startup, operations, updates, rollback, logs/health, and security notes.
  - Out of scope:
    - Any mutable edits under `ressources/`.
    - Plugin feature expansion in `pixel-control-plugin/`.
    - Re-architecting runtime stack components (MySQL/ManiaControl/ShootMania) beyond compatibility-safe hardening.
- Background / findings:
  - Reviewed files: `pixel-sm-server/Dockerfile`, `pixel-sm-server/docker-compose.yml`, `pixel-sm-server/.env.example`, `pixel-sm-server/README.md`.
  - Dockerfile currently uses `ubuntu:20.04` with PHP/runtime dependencies and bootstrap entrypoint.
  - `.env.example` is development-oriented and includes many local/QA defaults.
  - README is comprehensive but heavily dev/QA-centric; production deployment path is not the first-class reading flow.
- Goals:
  - Keep Dockerfile viability explicit and testable.
  - Keep Compose hardening practical and backward-compatible.
  - Provide a copy/paste friendly production template (`.env` and Compose override) with secure placeholder strategy.
  - Make README deployment-first without removing developer workflows.
- Non-goals:
  - No secret values committed.
  - No change to existing contract that requires updates in `API_CONTRACT.md`.
- Constraints / assumptions:
  - Keep `ubuntu:20.04` + PHP 7.4 compatibility baseline unless a safe, tested alternative is explicitly requested.
  - Maintain local-first conventions (runtime assets remain under `pixel-sm-server/` paths by default).
  - Keep existing dev flows (`.env.example`, existing Compose workflow, validation scripts) operational.
  - All production hardening changes should be additive or compatibility-safe by default.

## Planned file touch map

- Required:
  - `pixel-sm-server/Dockerfile`
  - `pixel-sm-server/docker-compose.yml`
  - `pixel-sm-server/README.md`
  - `pixel-sm-server/.env.example` (only if needed for cross-reference notes)
  - `pixel-sm-server/.env.production.example` (new)
  - `pixel-sm-server/docker-compose.production.yml` (new)
- Optional (only if needed to keep docs/ops clean):
  - `pixel-sm-server/scripts/validate-dev-stack-launch.sh` (or another existing script) for tiny non-breaking checks tied to production template validation.

## Steps

- [Done] Phase 0 - Freeze production-readiness contract and compatibility boundaries
- [Done] Phase 1 - Dockerfile and Compose hardening pass (non-breaking)
- [Done] Phase 2 - Add production deployment templates (env + compose override)
- [Done] Phase 3 - Rewrite README to prioritize fast production deployment and operations
- [Done] Phase 4 - Validate end-to-end deployability, risks, and rollback path

### Phase 0 - Freeze production-readiness contract and compatibility boundaries

- [Done] P0.1 - Define what "production-ready" means for this repo.
  - Capture explicit minimum baseline: reproducible build, deterministic config rendering, health visibility, and rollback-friendly deploy flow.
- [Done] P0.2 - Freeze compatibility guardrails.
  - Preserve current default local developer workflow as baseline.
  - Keep all mutable/runtime sources outside `ressources/`.
- [Done] P0.3 - Define additive file strategy before implementation.
  - Introduce dedicated production template files instead of replacing current dev defaults.

#### Phase 0 acceptance criteria

- Production-readiness scope is explicit and bounded.
- Backward-compatibility guardrails are documented before making file changes.
- The implementation strategy is additive-first (dev defaults remain available).

#### Phase 0 verification commands

```bash
git status
```

### Phase 1 - Dockerfile and Compose hardening pass (non-breaking)

- [Done] P1.1 - Audit Dockerfile viability and hardening opportunities.
  - Verify required runtime tools exist (`php`, `xmlstarlet`, `envsubst`, mysql client, shell scripts).
  - Keep package install minimal and compatibility-safe.
- [Done] P1.2 - Apply practical Dockerfile improvements without changing behavior unexpectedly.
  - Target: image hygiene/readability/reliability changes only (for example deterministic install flow and bootstrap script execution guarantees).
- [Done] P1.3 - Apply Compose hardening checks in a compatibility-safe way.
  - Confirm service health/restart behavior, volume conventions, and environment wiring are production-usable.
  - Keep baseline `docker-compose.yml` usable for existing local workflow.
- [Done] P1.4 - Validate Dockerfile + Compose viability after hardening changes.

#### Phase 1 acceptance criteria

- Docker image builds successfully from current repo state.
- Container image confirms required runtime binaries/dependencies are present.
- Compose renders valid merged configuration with and without production override.
- Existing local workflow compatibility is preserved.

#### Phase 1 verification commands

```bash
docker build -f pixel-sm-server/Dockerfile -t pixel-sm-server:prod-readiness pixel-sm-server
docker run --rm pixel-sm-server:prod-readiness php -v
docker run --rm pixel-sm-server:prod-readiness bash -lc "command -v envsubst && command -v xmlstarlet && command -v mysql"
docker compose -f pixel-sm-server/docker-compose.yml config
```

### Phase 2 - Add production deployment templates (env + compose override)

- [Done] P2.1 - Introduce `pixel-sm-server/.env.production.example` with prefilled safe defaults.
  - Prefill non-secret operational defaults (ports, service toggles, log level, restart-compatible values).
  - Mark all secrets with explicit `CHANGE_ME_*` placeholders and inline warnings.
  - Keep secrets/env keys aligned with Compose variables.
- [Done] P2.2 - Add `pixel-sm-server/docker-compose.production.yml` override.
  - Keep override additive (do not break existing `docker-compose.yml` path).
  - Include production-oriented settings that do not violate local-first constraints.
- [Done] P2.3 - Define one-command deploy path using base + production override + production env template.
  - Ensure this path is explicit, reproducible, and rollback-friendly.
- [Done] P2.4 - Validate config interpolation and structural consistency for production templates.

#### Phase 2 acceptance criteria

- Production template files exist and are self-explanatory.
- All required variables for production path are present and clearly labeled as secret vs safe default.
- Merged compose config resolves cleanly with production template.
- No requirement is introduced to mutate `ressources/` paths.

#### Phase 2 verification commands

```bash
cp pixel-sm-server/.env.production.example pixel-sm-server/.env.production.local
docker compose --env-file pixel-sm-server/.env.production.local -f pixel-sm-server/docker-compose.yml -f pixel-sm-server/docker-compose.production.yml config
```

### Phase 3 - Rewrite README to prioritize fast production deployment and operations

- [Done] P3.1 - Add a deployment-first README top path.
  - Include shortest successful path: prerequisites, env creation, startup, health verification, shutdown.
- [Done] P3.2 - Add operations section for day-2 tasks.
  - Include update/redeploy flow, rollback basics, log inspection, health checks, and failure triage commands.
- [Done] P3.3 - Add security guidance section with explicit secret handling.
  - Explain secret placeholders, no-commit policy for populated env files, and network exposure cautions.
- [Done] P3.4 - Keep dev/QA workflows documented but demoted below production deployment sections.
  - Preserve existing script references while improving readability for deployment operators.

#### Phase 3 acceptance criteria

- README starts with fast production deployment guidance before dev/QA depth.
- README includes concrete startup, update, rollback, logs, and health commands.
- Security notes are explicit and actionable.
- Developer workflows remain documented and discoverable.

#### Phase 3 verification commands

```bash
git diff -- pixel-sm-server/README.md
```

### Phase 4 - Validate end-to-end deployability, risks, and rollback path

- [Done] P4.1 - Run production-template startup smoke test (with local runtime assets available).
  - Use production env template copy + compose override for launch.
  - Confirm service health and key readiness logs.
- [Done] P4.1.a - Re-run production-template smoke with temporary high ports when default host ports are occupied.
  - Use one-shot env overrides for `PIXEL_SM_XMLRPC_PORT` / `PIXEL_SM_GAME_PORT` / `PIXEL_SM_P2P_PORT` to validate template path without changing committed defaults.
- [Done] P4.1.b - Add host-port preflight check before alternate-port smoke rerun.
  - Verify selected alternate TCP/UDP ports are currently free on host before launching Compose stack.
- [Done] P4.2 - Validate update and rollback command paths from README.
  - Confirm commands are executable and consistent with file names/flags.
- [Done] P4.3 - Add explicit risk and rollback notes to plan outcomes and README.
  - Document what can fail, blast radius, and exact fallback path to previous stable deploy method.
- [Done] P4.4 - Capture final artifacts and handoff notes.

#### Phase 4 acceptance criteria

- Production-template deploy path can reach healthy containers in local validation conditions.
- README update/rollback instructions match actual Compose files and env template names.
- Risks and rollback procedure are clearly documented.

#### Phase 4 verification commands

```bash
docker compose --env-file pixel-sm-server/.env.production.local -f pixel-sm-server/docker-compose.yml -f pixel-sm-server/docker-compose.production.yml up -d --build
docker compose --env-file pixel-sm-server/.env.production.local -f pixel-sm-server/docker-compose.yml -f pixel-sm-server/docker-compose.production.yml ps
docker compose --env-file pixel-sm-server/.env.production.local -f pixel-sm-server/docker-compose.yml -f pixel-sm-server/docker-compose.production.yml logs --tail=150 shootmania
docker compose --env-file pixel-sm-server/.env.production.local -f pixel-sm-server/docker-compose.yml -f pixel-sm-server/docker-compose.production.yml down
```

## Risk / rollback notes (execution guardrails)

- Main risks:
  - Production hardening changes accidentally alter existing developer startup behavior.
  - New env template may be misused with placeholder secrets left unchanged.
  - Compose override drift may desynchronize with base compose variables.
- Rollback strategy:
  - Keep production files additive so fallback to baseline is immediate.
  - Baseline rollback command path remains:
    - `docker compose --env-file pixel-sm-server/.env -f pixel-sm-server/docker-compose.yml up -d --build`
  - If regression is detected, remove production override from launch path and revert modified files in one commit.

## Evidence / artifacts

- Planned validation artifacts root: `pixel-sm-server/logs/qa/` (reuse existing conventions for deterministic capture where applicable).
- Optional deployment-focused notes artifact (if needed): `pixel-sm-server/logs/qa/production-readiness-<timestamp>/`.

## Success criteria

- Dockerfile viability is explicitly validated and documented with reproducible commands.
- Compose production hardening remains non-breaking for local developer workflows.
- A production deployment template strategy exists (`.env.production.example` and/or production compose override) with safe defaults and clear secret placeholders.
- README is deployment-first and covers startup, update, rollback, logs/health checks, and security notes.
- No mutable workflow is introduced under `ressources/`; local-first conventions remain intact.

## Notes / outcomes

- Executed validation evidence highlights:
  - Docker image hardening build passed (`pixel-sm-server:prod-readiness`) and runtime tool checks passed (`php`, `envsubst`, `xmlstarlet`, `mysql`).
  - Base compose and production override configs both rendered successfully.
  - Production-template launch with placeholder-only `.env.production.local` reproduced expected failure mode (`shootmania` unhealthy with master-auth/login errors) while cleanup (`down`) succeeded.
  - Production override launch with local valid runtime credentials (`--env-file pixel-sm-server/.env`) reached healthy state (`mysql` + `shootmania`) and shutdown path succeeded.
- Gap handling completed during execution:
  - Added P4.1.a/P4.1.b to cover occupied-host-port rerun flow and explicit preflight checks for alternate ports.
