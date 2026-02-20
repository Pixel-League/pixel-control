# Pixel Control Native Admin Capability Delegation

This document defines the delegated admin-control routing boundary for `pixel-control-plugin`.

## Ownership boundary

- Pixel plugin owns:
  - telemetry normalization and envelope identity,
  - queue/retry/outage transport behavior,
  - contract/version signaling and capability exposure.
- ManiaControl native services own:
  - permission enforcement and auth hierarchy,
  - map/match-flow command execution,
  - player force and auth grant/revoke execution,
  - vote cancellation/ratio mutation and optional custom-vote execution.

## Control entry points

- Admin chat command: `//pcadmin` (plus configured alias from `PIXEL_CONTROL_ADMIN_COMMAND`).
- Communication methods:
  - `PixelControl.Admin.ExecuteAction`
  - `PixelControl.Admin.ListActions`

Feature toggle and runtime controls:

- `Pixel Control Native Admin Control Enabled` / `PIXEL_CONTROL_ADMIN_CONTROL_ENABLED`
- `Pixel Control Native Admin Command` / `PIXEL_CONTROL_ADMIN_COMMAND`
- `Pixel Control Pause State Max Age Seconds` / `PIXEL_CONTROL_ADMIN_PAUSE_STATE_MAX_AGE_SECONDS`

## Permission model

- Plugin rights are declared through `AuthenticationManager::definePluginPermissionLevel(...)`.
- Requests are gated with `AuthenticationManager::checkPluginPermission(...)`.
- Effective minimum rights:
  - Moderator: map/warmup/pause/vote-cancel/player-force/custom-vote-start
  - Admin: vote-ratio/auth-grant/auth-revoke

## Action routing matrix

| Action | Native entrypoint | Notes |
| --- | --- | --- |
| `map.skip` | `MapActions::skipMap` | delegate |
| `map.restart` | `MapActions::restartMap` | delegate |
| `map.jump` | `MapActions::skipToMapByUid` or `MapActions::skipToMapByMxId` | `map_uid` or `mx_id` |
| `map.queue` | `MapQueue::serverAddMapToMapQueue` | resolves `mx_id` to map uid |
| `warmup.extend` | `ModeScriptEventManager::extendManiaPlanetWarmup` | script-mode guard |
| `warmup.end` | `ModeScriptEventManager::stopManiaPlanetWarmup` | script-mode guard |
| `pause.start` | `ModeScriptEventManager::startPause` | requires `modeUsesPause()` |
| `pause.end` | `ModeScriptEventManager::endPause` | requires `modeUsesPause()` |
| `pause.toggle` | `ModeScriptEventManager::startPause` or `ModeScriptEventManager::endPause` | requires known `pause_active` |
| `vote.cancel` | `Client::cancelVote` | native failure when no vote running |
| `vote.set_ratio` | `Client::setCallVoteRatios` | validates command + ratio range |
| `player.force_team` | `PlayerActions::forcePlayerToTeam` | team-mode guard |
| `player.force_play` | `PlayerActions::forcePlayerToPlay` | delegate |
| `player.force_spec` | `PlayerActions::forcePlayerToSpectator` | delegate |
| `auth.grant` | `PlayerActions::grantAuthLevel` | verifies applied auth level |
| `auth.revoke` | `PlayerActions::revokeAuthLevel` | verifies fallback to player level |
| `vote.custom_start` | `MCTeam\CustomVotesPlugin::startVote` | capability unavailable when plugin inactive |

## Capability and fallback semantics

- Mode-sensitive guards are explicit:
  - script-only actions return `unsupported_mode` when script mode is unavailable,
  - pause actions return `capability_unavailable` when pause is not supported in current mode,
  - team-force action returns `capability_unavailable` when mode is not team-based.
- Native rejections and exceptions are normalized to deterministic result codes/messages.

## Rollout and rollback

Rollout defaults and safety:

- Feature is disabled by default unless explicitly enabled (`PIXEL_CONTROL_ADMIN_CONTROL_ENABLED=1` or setting `Pixel Control Native Admin Control Enabled=1`).
- When disabled, plugin remains telemetry/transport-only and does not register admin command/communication entry points.
- Startup marker confirms effective state: `[PixelControl][admin][bootstrap] enabled=yes|no, ...`.

Rollback procedure (telemetry-only fallback):

1. Set `Pixel Control Native Admin Control Enabled=0` (or unset/zero `PIXEL_CONTROL_ADMIN_CONTROL_ENABLED`).
2. Reload plugin/runtime (for dev stack: `bash pixel-sm-server/scripts/dev-plugin-sync.sh`).
3. Verify bootstrap log shows `enabled=no`.
4. Verify communication/list action calls return no listener response (or command path reports control surface disabled), while connectivity/telemetry envelopes continue.

Restore delegated controls:

1. Set `Pixel Control Native Admin Control Enabled=1`.
2. Reload plugin/runtime.
3. Verify bootstrap log shows `enabled=yes` and communication action list is available again.

## Observability

- Delegated control logs:
  - `[PixelControl][admin][action_requested]`
  - `[PixelControl][admin][action_success]`
  - `[PixelControl][admin][action_failed]`
- Connectivity registration capability payload exposes delegated control surface under `payload.capabilities.admin_control`.

## Validation evidence (2026-02-20)

- Delegated communication matrix: `pixel-sm-server/logs/qa/admin-delegation-20260220-1926/communication-action-matrix.json`
- Permission deny/allow matrix: `pixel-sm-server/logs/qa/admin-delegation-20260220-1926/permission-matrix.json`
- Non-Elite capability fallback matrix (Joust): `pixel-sm-server/logs/qa/admin-delegation-20260220-1926/joust-capability-matrix.json`
- Admin action log markers: `pixel-sm-server/runtime/server/ManiaControl/ManiaControl.log`
