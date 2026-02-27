# Pixel Control Plugin

`pixel-control-plugin` is the first-party ManiaControl plugin in this monorepo.

It is responsible for:

- normalizing ManiaPlanet/ManiaControl callbacks into a stable event contract,
- generating deterministic envelope identity (`event_name`, `event_id`, `idempotency_key`, `source_sequence`),
- buffering/retrying transport when backend delivery is unavailable,
- exposing delegated admin and veto control surfaces over chat and communication sockets,
- maintaining plugin-owned runtime policy state (whitelist, vote policy, team roster, series targets).

## Canonical boundaries

- Plugin owns telemetry normalization, identity, queue/retry/outage behavior, and plugin-owned policy state.
- ManiaControl native services own core game execution primitives (map/match flow, player force actions, auth hierarchy).
- Backend ingestion/runtime is out of scope in this package; backend behavior stays contract-driven through schemas and docs.

## Quick usage

### 1) Local quality gate for this package

Run lint + local deterministic tests:

```bash
bash pixel-control-plugin/scripts/check-quality.sh
```

### 2) Sync plugin into local ShootMania runtime

From repo root:

```bash
bash pixel-sm-server/scripts/dev-plugin-sync.sh
```

For faster iteration without dedicated-server restart:

```bash
bash pixel-sm-server/scripts/dev-plugin-hot-sync.sh
```

## Runtime capabilities

### Event and transport

- Active envelope baseline: `schema_version=2026-02-20.1`.
- Event naming pattern: `pixel_control.<category>.<normalized_source_callback>`.
- Local deterministic identity validation before enqueue/dispatch.
- Bounded in-memory queue with retry policy and outage/recovery markers.
- Connectivity envelopes include capability and queue/outage snapshots.
- Connectivity registration + heartbeat both carry `capabilities.admin_control.*` snapshots.
- Successful delegated policy mutations queue an immediate capability-refresh connectivity update.

### Lifecycle/player/combat telemetry

- Lifecycle variants normalized (`warmup`, `pause`, `match`, `map`, `round` start/end/status).
- Player transition payloads include state delta, reconnect continuity, side/team changes, and constraint signals.
- Combat payloads include per-player counters and score snapshots.
- Lifecycle map payloads include map rotation + veto result projections.

### Plugin-owned policy modules

- Whitelist state: enabled flag + normalized login registry + guest-list sync + immediate connected-player reconciliation with bounded periodic sweep.
- Vote policy state: callback-cancel mode or strict callvote-disable mode.
- Team roster state: login->team assignments + policy flags.
- Series state: `best_of`, `maps_score`, `current_map_score` with persistence + rollback on write failures.

## Admin control surface

### Chat command

- `//pcadmin ...` (alias configurable by setting/env).

Examples:

```bash
//pcadmin help
//pcadmin map.skip
//pcadmin match.bo.set best_of=5
//pcadmin match.maps.set target_team=blue maps_score=2
//pcadmin whitelist.add target_login=SomePlayer
```

### Communication methods

- `PixelControl.Admin.ListActions`
- `PixelControl.Admin.ExecuteAction`

### Action families (delegated)

- map: `map.skip`, `map.restart`, `map.jump`, `map.queue`, `map.add`, `map.remove`
- warmup/pause: `warmup.extend`, `warmup.end`, `pause.start`, `pause.end`
- votes: `vote.cancel`, `vote.set_ratio`, `vote.custom_start`
- player/auth: `player.force_team`, `player.force_play`, `player.force_spec`, `auth.grant`, `auth.revoke`
- whitelist: `whitelist.enable|disable|add|remove|list|clean|sync`
- vote policy: `vote.policy.get|set`
- team control: `team.policy.get|set`, `team.roster.assign|unassign|list`
- series targets: `match.bo.get|set`, `match.maps.get|set`, `match.score.get|set`

### Security notes

- Chat path is actor-bound and permission-gated by native plugin rights.
- Link configuration is restricted to super/master admin via `//pcadmin server.link.set base_url=<url> link_token=<token>` and `//pcadmin server.link.status`.
- Communication payload path requires linked auth fields (`server_login`, `auth.mode=link_bearer`, `auth.token`) for `PixelControl.Admin.ListActions` and `PixelControl.Admin.ExecuteAction`.
- Link settings resolve env-first with setting fallback (`PIXEL_CONTROL_LINK_SERVER_URL`, `PIXEL_CONTROL_LINK_TOKEN`); token output is always fingerprint-masked in operator responses.

## Veto control surface

### Chat command

- `/pcveto ...` for player-facing commands.
- `//pcveto ...` for admin/control commands.

Core operations:

- status/maps/help
- matchmaking votes (`vote`)
- tournament actions (`action`)
- admin controls (`start`, `cancel`, `mode`, `duration`, `min_players`, `ready`, `config`)

### Communication methods

- `PixelControl.VetoDraft.Status`
- `PixelControl.VetoDraft.Start`
- `PixelControl.VetoDraft.Action`
- `PixelControl.VetoDraft.Cancel`
- `PixelControl.VetoDraft.Ready`

### Matchmaking ready gate

- Matchmaking start requires explicit arming (`ready`).
- Ready token is consumed on successful start.
- No automatic re-arm after cycle completion.

## Script reference

All commands below are launched from repository root unless noted.

### Test and validation scripts

- `bash pixel-control-plugin/scripts/check-quality.sh`
  - Lint plugin `src` + `tests`, then run deterministic local test suite.
- `bash pixel-sm-server/scripts/validate-dev-stack-launch.sh`
  - Validate stack launch readiness (health, plugin load marker, XML-RPC reachability).
- `bash pixel-sm-server/scripts/validate-mode-launch-matrix.sh`
  - Validate mode launch matrix (Elite/Siege/Battle/Joust/Custom).
- `bash pixel-sm-server/scripts/replay-core-telemetry-wave3.sh`
  - Deterministic wave-3 telemetry replay with marker validation.
- `bash pixel-sm-server/scripts/replay-extended-telemetry-wave4.sh`
  - Deterministic wave-4 telemetry replay with marker validation.
- `bash pixel-sm-server/scripts/simulate-admin-control-payloads.sh matrix`
  - Replay delegated admin communication action matrix.
- `bash pixel-sm-server/scripts/simulate-veto-control-payloads.sh matrix`
  - Replay veto communication matrix with strict assertion artifact output.
- `bash pixel-sm-server/scripts/test-automated-suite.sh`
  - Orchestrated automatable validation run with summary artifacts.

### Other development scripts

- `bash pixel-sm-server/scripts/dev-plugin-sync.sh`
  - Sync plugin source into runtime and restart shootmania service.
- `bash pixel-sm-server/scripts/dev-plugin-hot-sync.sh`
  - Sync plugin source and restart ManiaControl process only.
- `bash pixel-sm-server/scripts/dev-mode-compose.sh <mode> [relaunch]`
  - Apply mode profile from `.env.<mode>` into `.env` and relaunch stack.
- `bash pixel-sm-server/scripts/import-reference-runtime.sh`
  - Import local reference runtime into first-party mutable runtime directory.
- `bash pixel-sm-server/scripts/fetch-titlepack.sh <TitlePackId>`
  - Download title pack assets into local mutable title-pack source.
- `bash pixel-sm-server/scripts/manual-wave5-session-bootstrap.sh ...`
  - Create manual evidence skeleton for real-client validation sessions.
- `bash pixel-sm-server/scripts/manual-wave5-ack-stub.sh ...`
  - Start local ACK stub for payload capture during manual sessions.
- `bash pixel-sm-server/scripts/manual-wave5-log-export.sh ...`
  - Export runtime logs for a manual evidence session.
- `bash pixel-sm-server/scripts/manual-wave5-evidence-check.sh ...`
  - Validate manual evidence folder completeness.

## Key configuration surface

Plugin runtime resolves settings with env-first precedence, then setting fallback.

Common env groups:

- transport/auth:
  - `PIXEL_CONTROL_API_BASE_URL`, `PIXEL_CONTROL_API_EVENT_PATH`, `PIXEL_CONTROL_AUTH_MODE`, `PIXEL_CONTROL_AUTH_VALUE`
- queue/dispatch/heartbeat:
  - `PIXEL_CONTROL_QUEUE_MAX_SIZE`, `PIXEL_CONTROL_DISPATCH_BATCH_SIZE`, `PIXEL_CONTROL_HEARTBEAT_INTERVAL_SECONDS`
- admin control:
  - `PIXEL_CONTROL_ADMIN_CONTROL_ENABLED`, `PIXEL_CONTROL_ADMIN_COMMAND`, `PIXEL_CONTROL_ADMIN_PAUSE_STATE_MAX_AGE_SECONDS`
- veto control:
  - `PIXEL_CONTROL_VETO_DRAFT_ENABLED`, `PIXEL_CONTROL_VETO_DRAFT_COMMAND`, `PIXEL_CONTROL_VETO_DRAFT_DEFAULT_MODE`, `PIXEL_CONTROL_VETO_DRAFT_MATCHMAKING_DURATION_SECONDS`, `PIXEL_CONTROL_VETO_DRAFT_MATCHMAKING_AUTOSTART_MIN_PLAYERS`, `PIXEL_CONTROL_VETO_DRAFT_TOURNAMENT_ACTION_TIMEOUT_SECONDS`, `PIXEL_CONTROL_VETO_DRAFT_DEFAULT_BEST_OF`, `PIXEL_CONTROL_VETO_DRAFT_LAUNCH_IMMEDIATELY`
- policy modules:
  - whitelist: `PIXEL_CONTROL_WHITELIST_ENABLED`, `PIXEL_CONTROL_WHITELIST_LOGINS`
  - vote policy: `PIXEL_CONTROL_VOTE_POLICY_MODE`
  - team policy: `PIXEL_CONTROL_TEAM_POLICY_ENABLED`, `PIXEL_CONTROL_TEAM_SWITCH_LOCK_ENABLED`, `PIXEL_CONTROL_TEAM_ROSTER_ASSIGNMENTS`

## Retained docs and why they remain

- `pixel-control-plugin/docs/event-contract.md`
  - Human-readable compatibility contract and additive-field policy for schema baseline.
- `pixel-control-plugin/docs/schema/*.json`
  - Machine-consumed schema/catalog artifacts used for validation, compatibility checks, and ingestion guardrails.

These files remain intentionally versioned and are not replaced by README prose.

## Removed/merged docs policy

This README is the canonical operational guide for plugin usage.

Historical audit/checklist/feature inventory docs were merged here to reduce duplicate guidance and stale branching narratives.

## Compatibility baseline

- Keep control-surface names stable (`PixelControl.Admin.*`, `PixelControl.VetoDraft.*`, `pcadmin`, `pcveto`) unless explicitly versioned.
- Keep envelope schema baseline stable (`2026-02-20.1`) unless explicit schema migration is approved.
