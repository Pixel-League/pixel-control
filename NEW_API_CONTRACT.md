# Pixel Control — API Contract

This document is the canonical reference for all communication between the Pixel Control Plugin and external systems, and the implementation roadmap for the Pixel Control Server API.

- **Inbound commands**: sent to the plugin via ManiaControl's CommunicationManager (AES-encrypted TCP socket). The API server proxies these commands through REST endpoints.
- **Outbound payloads**: sent by the plugin to the Pixel Control Server via async HTTP POST. The API server ingests, stores, and re-exposes them through GET endpoints.

Schema version: `2026-02-20.1` (additive evolution only).
API base path: `/v1`

### Priority Legend

| Priority | Tier | Scope |
| -------- | ---- | ----- |
| **P0🔥** | Foundation | Server link/auth + connectivity ingestion — nothing works without this |
| **P1**   | Core ingestion | Receive plugin events (lifecycle, combat, players, batch, mode, admin) + basic status reads |
| **P2**   | Read API | Expose ingested telemetry data (players, combat stats, scores, lifecycle, maps, mode) |
| **P3**   | Essential admin | Map management, warmup/pause, match/series configuration (proxy to plugin socket) |
| **P4**   | Extended control | Veto/draft flow, player force-team/play/spec, team policy & roster |
| **P5**   | Low priority | Whitelist, vote policy, auth grant/revoke, player history |

Sub-priorities (e.g. P0.1, P3.7, P5.15) define implementation order within each tier.

---

## Table of Contents

- [1. Inbound Commands (Server → Plugin)](#1-inbound-commands-server--plugin)
  - [1.1 Transport: ManiaControl Communication Socket](#11-transport-maniacontrol-communication-socket)
  - [1.2 Link Auth (required for admin commands)](#12-link-auth-required-for-admin-commands)
  - [1.3 Admin Control — `PixelControl.Admin.*`](#13-admin-control--pixelcontroladmin)
    - [1.3.1 `PixelControl.Admin.ListActions`](#131-pixelcontroladminlistactions)
    - [1.3.2 `PixelControl.Admin.ExecuteAction`](#132-pixelcontroladminexecuteaction)
    - [1.3.3 Admin Actions Catalog](#133-admin-actions-catalog)
  - [1.4 Veto/Draft Control — `PixelControl.VetoDraft.*`](#14-vetodraft-control--pixelcontrolvetodraft)
    - [1.4.1 `PixelControl.VetoDraft.Status`](#141-pixelcontrolvetodraftstatus)
    - [1.4.2 `PixelControl.VetoDraft.Ready`](#142-pixelcontrolvetodraftready)
    - [1.4.3 `PixelControl.VetoDraft.Start`](#143-pixelcontrolvetodraftstart)
    - [1.4.4 `PixelControl.VetoDraft.Action`](#144-pixelcontrolvetodraftaction)
    - [1.4.5 `PixelControl.VetoDraft.Cancel`](#145-pixelcontrolvetodraftcancel)
- [2. Outbound Payloads (Plugin → Server)](#2-outbound-payloads-plugin--server)
  - [2.1 Transport: Async HTTP](#21-transport-async-http)
  - [2.2 Event Envelope](#22-event-envelope)
  - [2.3 Connectivity](#23-connectivity)
  - [2.4 Combat / Stats](#24-combat--stats)
  - [2.5 Lifecycle](#25-lifecycle)
  - [2.6 Player](#26-player)
  - [2.7 Mode-Specific](#27-mode-specific)
- [3. Server Link Management](#3-server-link-management)
  - [3.1 Link Flow Overview](#31-link-flow-overview)
  - [3.2 Link API Endpoints](#32-link-api-endpoints)
- [4. API Endpoints Summary](#4-api-endpoints-summary)

---

# 1. Inbound Commands (Server → Plugin)

## 1.1 Transport: ManiaControl Communication Socket

All inbound commands are delivered over ManiaControl's built-in `CommunicationManager` — an AES-192-CBC encrypted TCP socket. Each request is a JSON frame:

```
{ "method": "<method_name>", "data": { ... } }
```

The response is a JSON object with:

| Field   | Type   | Description                         |
| ------- | ------ | ----------------------------------- |
| `error` | bool   | `true` if communication-level error |
| `data`  | object | Method-specific response payload    |

Socket configuration is stored in ManiaControl's `mc_settings` table (`CommunicationManager` class): port, password, and activation status.

The Pixel Control Server acts as a **proxy**: REST API endpoints receive client requests, translate them into communication socket calls to the plugin, and return the plugin's response.

## 1.2 Link Auth (required for admin commands)

All `PixelControl.Admin.*` methods require link-auth evidence in the request payload:

| Field                | Type   | Description                                        |
| -------------------- | ------ | -------------------------------------------------- |
| `server_login`       | string | Dedicated server login (must match the local server) |
| `auth.mode`          | string | Must be `link_bearer`                               |
| `auth.token`         | string | Link token (shared secret between server and plugin) |

**Auth rejection codes:**

| Code                      | Cause                                   |
| ------------------------- | --------------------------------------- |
| `link_auth_missing`       | `server_login` or `auth` block absent    |
| `link_auth_invalid`       | Token does not match expected value      |
| `link_server_mismatch`    | `server_login` does not match local server |
| `admin_command_unauthorized` | Actor does not have required auth level |

The API server injects link-auth fields automatically when proxying to the plugin — API clients do not need to provide socket-level auth.

---

## 1.3 Admin Control — `PixelControl.Admin.*`

Chat command equivalent: `//pcadmin <action> [key=value ...]`

### 1.3.1 `PixelControl.Admin.ListActions`

Returns the admin control surface configuration and all available actions.

**Request data:** (optional link-auth fields only)

**Response `data`:**

| Field             | Type   | Description                                |
| ----------------- | ------ | ------------------------------------------ |
| `enabled`         | bool   | Whether admin control is active            |
| `command`         | string | Chat command prefix (e.g. `pcadmin`)       |
| `communication`   | object | `{ exec, list }` method names              |
| `security`        | object | Auth level requirements, pause TTL         |
| `link`            | object | Link status (`linked`, `base_url`, etc.)   |
| `whitelist`       | object | Current whitelist state                    |
| `vote_policy`     | object | Current vote policy                        |
| `team_control`    | object | Team policy & roster state                 |
| `series_targets`  | object | Best-of, maps score, current map score     |
| `actions`         | object | Map of action_name → action definition     |

> Note: this data is exposed by the Swagger/OpenAPI schema of the API. No dedicated API endpoint needed — the action catalog is documented in the API spec itself.

### 1.3.2 `PixelControl.Admin.ExecuteAction`

Executes a single admin action.

**Request `data`:**

| Field         | Type   | Required | Description                              |
| ------------- | ------ | -------- | ---------------------------------------- |
| `action`      | string | yes      | Action name (see catalog below)          |
| `parameters`  | object | no       | Action-specific parameters               |
| `actor_login` | string | no       | Login of the player triggering the action |
| `server_login`| string | yes      | Link-auth server login                   |
| `auth`        | object | yes      | `{ mode: "link_bearer", token: "..." }`  |

**Response `data`:**

| Field         | Type   | Description                                            |
| ------------- | ------ | ------------------------------------------------------ |
| `action_name` | string | Echoed action name                                     |
| `success`     | bool   | Whether the action succeeded                           |
| `code`        | string | Result code (see per-action codes below)                |
| `message`     | string | Human-readable result message                          |
| `details`     | object | Action-specific result details (optional)               |

### 1.3.3 Admin Actions Catalog

#### Map Management

**Plugin actions (socket):**

| Action         | Min Auth  | Parameters                | Description         |
| -------------- | --------- | ------------------------- | ------------------- |
| `map.skip`     | Moderator | —                         | Skip to next map    |
| `map.restart`  | Moderator | —                         | Restart current map |
| `map.jump`     | Moderator | `map_uid`: string         | Jump to specific map |
| `map.queue`    | Moderator | `map_uid`: string         | Queue map for next   |
| `map.add`      | Admin     | `mx_id`: string           | Add map from ManiaExchange |
| `map.remove`   | Admin     | `map_uid`: string         | Remove map from rotation |

Aliases: `map.next`, `map.skip_current` → `map.skip`; `map.res` → `map.restart`; `map.add_queue` → `map.queue`; `map.add_mx` → `map.add`; `map.delete`, `map.rm` → `map.remove`

**API endpoints:**

| Method   | Endpoint                                      | Body                  | Description         | Dev Status | Priority |
| -------- | --------------------------------------------- | --------------------- | ------------------- | ---------- | -------- |
| `POST`   | `/v1/servers/:serverLogin/maps/skip`          | —                     | Skip to next map    | Todo 🛑    | P3.1     |
| `POST`   | `/v1/servers/:serverLogin/maps/restart`       | —                     | Restart current map | Todo 🛑    | P3.2     |
| `POST`   | `/v1/servers/:serverLogin/maps/jump`          | `{ map_uid }`         | Jump to specific map | Todo 🛑    | P3.3     |
| `POST`   | `/v1/servers/:serverLogin/maps/queue`         | `{ map_uid }`         | Queue map for next   | Todo 🛑    | P3.4     |
| `POST`   | `/v1/servers/:serverLogin/maps`               | `{ mx_id }`           | Add map from MX     | Todo 🛑    | P3.5     |
| `DELETE`  | `/v1/servers/:serverLogin/maps/:mapUid`      | —                     | Remove map          | Todo 🛑    | P3.6     |
| `GET`    | `/v1/servers/:serverLogin/maps`               | —                     | List map pool (from plugin telemetry) | Done ✅    | P2.11    |

#### Warmup & Pause

**Plugin actions (socket):**

| Action          | Min Auth  | Parameters           | Description               |
| --------------- | --------- | -------------------- | ------------------------- |
| `warmup.extend` | Moderator | `seconds`: int       | Extend warmup duration    |
| `warmup.end`    | Moderator | —                    | End warmup phase          |
| `pause.start`   | Moderator | —                    | Pause the match           |
| `pause.end`     | Moderator | —                    | Resume from pause         |

Aliases: `warmup.stop` → `warmup.end`; `pause.resume` → `pause.end`

**API endpoints:**

| Method | Endpoint                                          | Body              | Description            | Dev Status | Priority |
| ------ | ------------------------------------------------- | ----------------- | ---------------------- | ---------- | -------- |
| `POST` | `/v1/servers/:serverLogin/warmup/extend`          | `{ seconds }`     | Extend warmup          | Todo 🛑    | P3.7     |
| `POST` | `/v1/servers/:serverLogin/warmup/end`             | —                 | End warmup             | Todo 🛑    | P3.8     |
| `POST` | `/v1/servers/:serverLogin/pause/start`            | —                 | Pause match            | Todo 🛑    | P3.9     |
| `POST` | `/v1/servers/:serverLogin/pause/end`              | —                 | Resume from pause      | Todo 🛑    | P3.10    |

#### Vote Management

**Plugin actions (socket):**

| Action              | Min Auth  | Parameters                             | Description                 |
| ------------------- | --------- | -------------------------------------- | --------------------------- |
| `vote.cancel`       | Moderator | —                                      | Cancel active vote          |
| `vote.set_ratio`    | Admin     | `command`: string, `ratio`: float      | Set vote ratio for a command |
| `vote.custom_start` | Moderator | `vote_index`: int                      | Start a custom vote         |
| `vote.policy.get`   | Moderator | —                                      | Get current vote policy     |
| `vote.policy.set`   | Admin     | `mode`: string                         | Set vote policy mode        |

Aliases: `vote.cancel_current` → `vote.cancel`; `custom_vote.start` → `vote.custom_start`; `vote.policy`, `vote.policy.status` → `vote.policy.get`

Vote policy modes: `cancel_non_admin_vote_on_callback`, and others.

**API endpoints:**

| Method | Endpoint                                          | Body                       | Description            | Dev Status | Priority |
| ------ | ------------------------------------------------- | -------------------------- | ---------------------- | ---------- | -------- |
| `POST` | `/v1/servers/:serverLogin/votes/cancel`           | —                          | Cancel active vote     | Todo 🛑    | P5.10    |
| `PUT`  | `/v1/servers/:serverLogin/votes/ratio`            | `{ command, ratio }`       | Set vote ratio         | Todo 🛑    | P5.11    |
| `POST` | `/v1/servers/:serverLogin/votes/custom`           | `{ vote_index }`           | Start custom vote      | Todo 🛑    | P5.12    |
| `GET`  | `/v1/servers/:serverLogin/votes/policy`           | —                          | Get vote policy        | Todo 🛑    | P5.13    |
| `PUT`  | `/v1/servers/:serverLogin/votes/policy`           | `{ mode }`                 | Set vote policy        | Todo 🛑    | P5.14    |

#### Player Management

**Plugin actions (socket):**

| Action              | Min Auth  | Parameters                                   | Description               |
| ------------------- | --------- | -------------------------------------------- | ------------------------- |
| `player.force_team` | Moderator | `target_login`: string, `team`: string       | Force player to a team    |
| `player.force_play` | Moderator | `target_login`: string                       | Force player to play      |
| `player.force_spec` | Moderator | `target_login`: string                       | Force player to spectator |

Aliases: `player.force_to_team` → `player.force_team`; `player.force_to_play` → `player.force_play`; `player.force_spectator` → `player.force_spec`

Team values: `0`, `1`, `red`, `blue`, `team_a`, `team_b`, `a`, `b` (normalized to `team_a`/`team_b`)

**API endpoints:**

| Method | Endpoint                                                     | Body           | Description         | Dev Status | Priority |
| ------ | ------------------------------------------------------------ | -------------- | ------------------- | ---------- | -------- |
| `POST` | `/v1/servers/:serverLogin/players/:login/force-team`         | `{ team }`     | Force to team       | Todo 🛑    | P4.6     |
| `POST` | `/v1/servers/:serverLogin/players/:login/force-play`         | —              | Force to play       | Todo 🛑    | P4.7     |
| `POST` | `/v1/servers/:serverLogin/players/:login/force-spec`         | —              | Force to spectator  | Todo 🛑    | P4.8     |
| `GET`  | `/v1/servers/:serverLogin/players`                           | —              | List players (from plugin telemetry) | Done ✅    | P2.1     |

#### Auth Management

**Plugin actions (socket):**

| Action        | Min Auth | Parameters                                    | Description              |
| ------------- | -------- | --------------------------------------------- | ------------------------ |
| `auth.grant`  | Admin    | `target_login`: string, `auth_level`: string  | Grant auth level         |
| `auth.revoke` | Admin    | `target_login`: string                        | Revoke auth level        |

Aliases: `auth.grant_level` → `auth.grant`; `auth.revoke_level` → `auth.revoke`

Auth levels: `player`, `moderator`, `admin`, `superadmin`

**API endpoints:**

| Method   | Endpoint                                                  | Body              | Description     | Dev Status | Priority |
| -------- | --------------------------------------------------------- | ----------------- | --------------- | ---------- | -------- |
| `POST`   | `/v1/servers/:serverLogin/players/:login/auth`            | `{ auth_level }`  | Grant auth      | Todo 🛑    | P5.1     |
| `DELETE`  | `/v1/servers/:serverLogin/players/:login/auth`           | —                 | Revoke auth     | Todo 🛑    | P5.2     |

#### Whitelist Management

**Plugin actions (socket):**

| Action             | Min Auth  | Parameters              | Description                   |
| ------------------ | --------- | ----------------------- | ----------------------------- |
| `whitelist.enable` | Moderator | —                       | Enable whitelist enforcement  |
| `whitelist.disable`| Moderator | —                       | Disable whitelist enforcement |
| `whitelist.add`    | Moderator | `target_login`: string  | Add player to whitelist       |
| `whitelist.remove` | Moderator | `target_login`: string  | Remove player from whitelist  |
| `whitelist.list`   | Moderator | —                       | List whitelisted players      |
| `whitelist.clean`  | Admin     | —                       | Clear entire whitelist        |
| `whitelist.sync`   | Admin     | —                       | Sync whitelist to runtime     |

Aliases: `whitelist.on` → `whitelist.enable`; `whitelist.off` → `whitelist.disable`; `whitelist.rm` → `whitelist.remove`; `whitelist.status` → `whitelist.list`; `whitelist.clear` → `whitelist.clean`

**API endpoints:**

| Method   | Endpoint                                                 | Body                | Description          | Dev Status | Priority |
| -------- | -------------------------------------------------------- | ------------------- | -------------------- | ---------- | -------- |
| `POST`   | `/v1/servers/:serverLogin/whitelist/enable`              | —                   | Enable whitelist     | Todo 🛑    | P5.3     |
| `POST`   | `/v1/servers/:serverLogin/whitelist/disable`             | —                   | Disable whitelist    | Todo 🛑    | P5.4     |
| `POST`   | `/v1/servers/:serverLogin/whitelist`                     | `{ target_login }`  | Add to whitelist     | Todo 🛑    | P5.5     |
| `DELETE`  | `/v1/servers/:serverLogin/whitelist/:login`             | —                   | Remove from whitelist | Todo 🛑    | P5.6     |
| `GET`    | `/v1/servers/:serverLogin/whitelist`                     | —                   | List whitelist       | Todo 🛑    | P5.7     |
| `DELETE`  | `/v1/servers/:serverLogin/whitelist`                    | —                   | Clear whitelist      | Todo 🛑    | P5.8     |
| `POST`   | `/v1/servers/:serverLogin/whitelist/sync`                | —                   | Sync to runtime      | Todo 🛑    | P5.9     |

#### Team Control

**Plugin actions (socket):**

| Action                | Min Auth  | Parameters                                   | Description                  |
| --------------------- | --------- | -------------------------------------------- | ---------------------------- |
| `team.policy.get`     | Moderator | —                                            | Get team policy state        |
| `team.policy.set`     | Admin     | `enabled`: bool, `switch_lock`: bool (opt.)  | Set team policy              |
| `team.roster.assign`  | Moderator | `target_login`: string, `team`: string       | Assign player to team roster |
| `team.roster.unassign`| Moderator | `target_login`: string                       | Remove player from roster    |
| `team.roster.list`    | Moderator | —                                            | List current roster          |

Aliases: `team.policy.status` → `team.policy.get`; `team.assign` → `team.roster.assign`; `team.unassign`, `team.roster.rm` → `team.roster.unassign`; `team.list` → `team.roster.list`

**API endpoints:**

| Method | Endpoint                                                    | Body                            | Description           | Dev Status | Priority |
| ------ | ----------------------------------------------------------- | ------------------------------- | --------------------- | ---------- | -------- |
| `GET`  | `/v1/servers/:serverLogin/teams/policy`                     | —                               | Get team policy       | Todo 🛑    | P4.10    |
| `PUT`  | `/v1/servers/:serverLogin/teams/policy`                     | `{ enabled, switch_lock? }`     | Set team policy       | Todo 🛑    | P4.9     |
| `POST` | `/v1/servers/:serverLogin/teams/roster`                     | `{ target_login, team }`        | Assign to roster      | Todo 🛑    | P4.11    |
| `DELETE`| `/v1/servers/:serverLogin/teams/roster/:login`             | —                               | Unassign from roster  | Todo 🛑    | P4.12    |
| `GET`  | `/v1/servers/:serverLogin/teams/roster`                     | —                               | List roster           | Todo 🛑    | P4.13    |

#### Match / Series Configuration

**Plugin actions (socket):**

| Action           | Min Auth  | Parameters                                          | Description                |
| ---------------- | --------- | --------------------------------------------------- | -------------------------- |
| `match.bo.get`   | Moderator | —                                                   | Get best-of configuration  |
| `match.bo.set`   | Moderator | `best_of`: int (odd)                                | Set best-of value          |
| `match.maps.get` | Moderator | —                                                   | Get maps score state       |
| `match.maps.set` | Moderator | `target_team`: string, `maps_score`: int            | Set maps score for a team  |
| `match.score.get`| Moderator | —                                                   | Get round score state      |
| `match.score.set`| Moderator | `target_team`: string, `score`: int                 | Set round score for a team |

Aliases: `bo.set` → `match.bo.set`; `bo.get`, `match.bo` → `match.bo.get`; `maps.set` → `match.maps.set`; `maps.get`, `match.maps` → `match.maps.get`; `score.set` → `match.score.set`; `score.get`, `match.score` → `match.score.get`

**API endpoints:**

| Method | Endpoint                                                | Body                            | Description           | Dev Status | Priority |
| ------ | ------------------------------------------------------- | ------------------------------- | --------------------- | ---------- | -------- |
| `GET`  | `/v1/servers/:serverLogin/match/best-of`                | —                               | Get best-of           | Todo 🛑    | P3.12    |
| `PUT`  | `/v1/servers/:serverLogin/match/best-of`                | `{ best_of }`                   | Set best-of           | Todo 🛑    | P3.11    |
| `GET`  | `/v1/servers/:serverLogin/match/maps-score`             | —                               | Get maps score        | Todo 🛑    | P3.14    |
| `PUT`  | `/v1/servers/:serverLogin/match/maps-score`             | `{ target_team, maps_score }`   | Set maps score        | Todo 🛑    | P3.13    |
| `GET`  | `/v1/servers/:serverLogin/match/round-score`            | —                               | Get round score       | Todo 🛑    | P3.16    |
| `PUT`  | `/v1/servers/:serverLogin/match/round-score`            | `{ target_team, score }`        | Set round score       | Todo 🛑    | P3.15    |

#### Chat-Only Commands (Super/Master Admin)

These are not available via `ExecuteAction` or the API — only via `//pcadmin` chat in-game:

| Command                                  | Description                        |
| ---------------------------------------- | ---------------------------------- |
| `server.link.set base_url=<url> link_token=<token>` | Configure API server link |
| `server.link.status`                     | Show current link configuration    |

---

## 1.4 Veto/Draft Control — `PixelControl.VetoDraft.*`

Chat command equivalent: `/pcveto <operation>` (player) or `//pcveto <operation>` (admin)

Two modes:
- **`matchmaking_vote`** — players vote on maps, majority wins. Timer-based.
- **`tournament_draft`** — captains alternate ban/pick. Turn-based with timeout.

### 1.4.1 `PixelControl.VetoDraft.Status`

Returns the current veto/draft state. Always callable (no active session required).

**Request `data`:** none

**Response `data`:**

| Field                               | Type   | Description                                        |
| ----------------------------------- | ------ | -------------------------------------------------- |
| `communication`                     | object | Method names (`start`, `action`, `status`, `cancel`, `ready`) |
| `status.active`                     | bool   | Whether a veto session is running                   |
| `status.mode`                       | string | `matchmaking_vote` or `tournament_draft`            |
| `status.session`                    | object | Session details (status, steps, current_step, etc.) |
| `status.session.status`             | string | `idle`, `running`, `completed`, `cancelled`         |
| `status.session.current_step`       | object | Current step info (`team`, `action`, `timeout`)      |
| `status.session.current_step.team`  | string | `team_a`, `team_b`, or `system`                     |
| `matchmaking_autostart_min_players` | int    | Min players for matchmaking autostart                |
| `matchmaking_ready_armed`           | bool   | Whether the ready gate is armed                      |
| `series_targets`                    | object | `best_of`, `maps_score`, `current_map_score`         |
| `matchmaking_lifecycle`             | object | Lifecycle snapshot (see below)                       |

**`matchmaking_lifecycle` object:**

| Field                    | Type   | Description                                           |
| ------------------------ | ------ | ----------------------------------------------------- |
| `status`                 | string | `idle`, `veto_started`, `veto_completed`, `selected_map_loaded`, `match_started`, `selected_map_finished`, `players_removed`, `map_changed`, `match_ended`, `ready_for_next_players` |
| `stage`                  | string | Current stage name (same values as `status`)           |
| `ready_for_next_players` | bool   | Whether lifecycle has completed and is ready to restart |
| `selected_map`           | object | Selected map info (`uid`, `name`, etc.)                |
| `history`                | array  | Bounded list of stage transition entries               |

### 1.4.2 `PixelControl.VetoDraft.Ready`

Arms the matchmaking ready gate. Must be called before `Start` in matchmaking mode. Readiness is consumed on successful start and not auto-rearmed after lifecycle completion.

**Request `data`:** none

**Response `data`:**

| Field     | Type   | Description        |
| --------- | ------ | ------------------ |
| `success` | bool   | Whether arming succeeded |
| `code`    | string | Result code        |
| `message` | string | Human-readable message |

**Response codes:**

| Code                             | Success | Description                       |
| -------------------------------- | ------- | --------------------------------- |
| `matchmaking_ready_armed`        | true    | Ready gate armed                  |
| `matchmaking_ready_already_armed`| true    | Was already armed                 |

### 1.4.3 `PixelControl.VetoDraft.Start`

Starts a new veto/draft session. Fails if a session is already active.

**Request `data` (matchmaking):**

| Field                | Type   | Required | Description                        |
| -------------------- | ------ | -------- | ---------------------------------- |
| `mode`               | string | yes      | `matchmaking_vote`                 |
| `duration_seconds`   | int    | no       | Vote window duration (default: configurable) |
| `launch_immediately` | int    | no       | `0` or `1` — skip countdown       |

**Request `data` (tournament):**

| Field                    | Type   | Required | Description                                  |
| ------------------------ | ------ | -------- | -------------------------------------------- |
| `mode`                   | string | yes      | `tournament_draft`                           |
| `captain_a`              | string | yes      | Login of team A captain                      |
| `captain_b`              | string | yes      | Login of team B captain (must differ from A) |
| `best_of`                | int    | no       | Odd integer (falls back to runtime series default) |
| `starter`                | string | no       | `team_a`, `team_b`, or `random`              |
| `action_timeout_seconds` | int    | no       | Per-turn timeout in seconds                  |
| `launch_immediately`     | int    | no       | `0` or `1` — skip countdown                 |

**Response `data`:**

| Field     | Type   | Description           |
| --------- | ------ | --------------------- |
| `success` | bool   | Whether start succeeded |
| `code`    | string | Result code           |
| `message` | string | Human-readable message |

**Response codes:**

| Code                          | Success | Description                                    |
| ----------------------------- | ------- | ---------------------------------------------- |
| `matchmaking_started`         | true    | Matchmaking session created                    |
| `tournament_started`          | true    | Tournament draft session created               |
| `matchmaking_ready_required`  | false   | Ready gate not armed (call Ready first)        |
| `session_active`              | false   | A veto session is already running              |
| `captain_missing`             | false   | `captain_a` or `captain_b` is empty           |
| `captain_conflict`            | false   | `captain_a` equals `captain_b`                |
| `map_pool_too_small`          | false   | Not enough maps for matchmaking vote           |
| `map_pool_too_small_for_bo`   | false   | Not enough maps for the requested best-of      |

### 1.4.4 `PixelControl.VetoDraft.Action`

Submits a vote (matchmaking) or draft action (tournament) within an active session.

**Request `data` (matchmaking vote):**

| Field            | Type   | Required | Description                            |
| ---------------- | ------ | -------- | -------------------------------------- |
| `actor_login`    | string | yes      | Login of the voting player             |
| `operation`      | string | yes      | `vote`                                 |
| `map`            | string | yes      | Map UID or index to vote for           |
| `allow_override` | int    | no       | `0` or `1` — allow changing existing vote |

**Request `data` (tournament draft):**

| Field         | Type   | Required | Description                        |
| ------------- | ------ | -------- | ---------------------------------- |
| `actor_login` | string | yes      | Login of the acting captain        |
| `operation`   | string | no       | `action` (default)                 |
| `map`         | string | yes      | Map UID or index to ban/pick       |
| `selection`   | string | no       | Map UID or index (alternative to `map`) |
| `force`       | int    | no       | `0` or `1` — force action          |

**Response `data`:**

| Field     | Type   | Description            |
| --------- | ------ | ---------------------- |
| `success` | bool   | Whether action succeeded |
| `code`    | string | Result code            |
| `message` | string | Human-readable message |

**Response codes:**

| Code                        | Success | Description                                     |
| --------------------------- | ------- | ----------------------------------------------- |
| `vote_recorded`             | true    | Vote accepted (matchmaking)                     |
| `tournament_action_applied` | true    | Draft action accepted (tournament)              |
| `session_not_running`       | false   | No active veto session                          |
| `tournament_not_running`    | false   | No active tournament session                    |
| `actor_not_allowed`         | false   | Actor is not the current team's captain          |

### 1.4.5 `PixelControl.VetoDraft.Cancel`

Cancels the active veto/draft session.

**Request `data`:**

| Field    | Type   | Required | Description           |
| -------- | ------ | -------- | --------------------- |
| `reason` | string | no       | Cancellation reason   |

**Response `data`:**

| Field     | Type   | Description              |
| --------- | ------ | ------------------------ |
| `success` | bool   | Whether cancel succeeded |
| `code`    | string | Result code              |
| `message` | string | Human-readable message   |

**Response codes:**

| Code                  | Success | Description                     |
| --------------------- | ------- | ------------------------------- |
| `session_cancelled`   | true    | Session successfully cancelled  |
| `session_not_running` | false   | No active session to cancel     |

**Veto/Draft API endpoints:**

| Method | Endpoint                                        | Body                                                                 | Description        | Dev Status | Priority |
| ------ | ----------------------------------------------- | -------------------------------------------------------------------- | ------------------ | ---------- | -------- |
| `GET`  | `/v1/servers/:serverLogin/veto/status`          | —                                                                    | Get veto state     | Todo 🛑    | P4.1     |
| `POST` | `/v1/servers/:serverLogin/veto/ready`           | —                                                                    | Arm ready gate     | Todo 🛑    | P4.2     |
| `POST` | `/v1/servers/:serverLogin/veto/start`           | `{ mode, duration_seconds?, captain_a?, captain_b?, best_of?, ... }` | Start session      | Todo 🛑    | P4.3     |
| `POST` | `/v1/servers/:serverLogin/veto/action`          | `{ actor_login, operation?, map, ... }`                              | Submit vote/action | Todo 🛑    | P4.4     |
| `POST` | `/v1/servers/:serverLogin/veto/cancel`          | `{ reason? }`                                                        | Cancel session     | Todo 🛑    | P4.5     |

---

# 2. Outbound Payloads (Plugin → Server)

## 2.1 Transport: Async HTTP

The plugin sends events via async HTTP POST to the configured API server. Events are queued locally and dispatched in batches (default: 3 per tick, tick interval: 1000ms).

**Request headers:**

| Header                     | Description                          |
| -------------------------- | ------------------------------------ |
| `Content-Type`             | `application/json`                   |
| `X-Pixel-Server-Login`    | Dedicated server login               |
| `X-Pixel-Plugin-Version`  | Plugin version string                |
| `Authorization`            | `Bearer <token>` (when auth_mode=bearer) |
| `X-Pixel-Control-Api-Key` | API key (when auth_mode=api_key)     |

**Queue configuration:**

| Setting              | Default |
| -------------------- | ------- |
| Max queue size       | 2000    |
| Dispatch batch size  | 3       |
| Max retry attempts   | 3       |
| Retry backoff        | 250ms   |
| Growth log step      | 200     |

**Expected server response:**

| Status       | Shape                                                        |
| ------------ | ------------------------------------------------------------ |
| Accepted     | `{ "ack": { "status": "accepted" } }`                        |
| Duplicate    | `{ "ack": { "status": "accepted", "disposition": "duplicate" } }` |
| Rejected     | `{ "ack": { "status": "rejected", "code": "...", "retryable": false } }` |
| Temp. error  | `{ "error": { "code": "...", "retryable": true, "retry_after_seconds": N } }` |

**Ingestion API endpoints:**

> **Note (P1):** The plugin sends ALL event categories to the single `POST /v1/plugin/events` endpoint. Category routing is performed server-side based on the `event_category` field. The per-category URL variants listed in the original contract do not match the plugin's actual behavior and have been replaced by the unified endpoint.

| Method | Endpoint                               | Description                                                                                   | Dev Status | Priority  |
| ------ | -------------------------------------- | --------------------------------------------------------------------------------------------- | ---------- | --------- |
| `POST` | `/v1/plugin/events`                    | Unified ingestion: all categories (connectivity, lifecycle, combat, player, mode, batch)      | Done ✅    | P0.5+P1   |

## 2.2 Event Envelope

Every outbound event is wrapped in a standard envelope:

| Field              | Type   | Description                                                             |
| ------------------ | ------ | ----------------------------------------------------------------------- |
| `event_name`       | string | `pixel_control.<category>.<normalized_source_callback>`                 |
| `schema_version`   | string | `2026-02-20.1`                                                          |
| `event_id`         | string | `pc-evt-<event_category>-<normalized_callback>-<source_sequence>`       |
| `event_category`   | string | `connectivity`, `lifecycle`, `player`, `combat`, `mode`                 |
| `source_callback`  | string | Raw ManiaControl callback name                                          |
| `source_sequence`  | int    | Monotonically increasing sequence number                                |
| `source_time`      | int    | Unix timestamp                                                          |
| `idempotency_key`  | string | `pc-idem-<sha1(event_id)>`                                              |
| `payload`          | object | Event-specific payload (see sections below)                             |
| `metadata`         | object | Transport metadata (`signal_kind`, `queue`, `retry`, `outage` snapshots) |

Identity validation: the plugin validates event_id / idempotency_key consistency before enqueue. Malformed envelopes are dropped with counter `dropped_on_identity_validation`.

---

## 2.3 Connectivity

Category: `connectivity`. Trigger: plugin startup + periodic timer.

### 2.3.1 Registration (`plugin_registration`)

**Event name:** `pixel_control.connectivity.plugin_registration`
**Trigger:** Plugin `load()` — sent once at startup. Also re-sent immediately after any admin control policy mutation (whitelist, vote policy, team policy, roster, match config).

| Payload Field                      | Type   | Description                                              |
| ---------------------------------- | ------ | -------------------------------------------------------- |
| `type`                             | string | `plugin_registration`                                    |
| `plugin.id`                        | int    | Plugin ID                                                |
| `plugin.name`                      | string | Plugin name                                              |
| `plugin.version`                   | string | Plugin version                                           |
| `capabilities`                     | object | Full capability snapshot (admin_control, queue, transport, callbacks) |
| `context.server`                   | object | Server info (`login`, `title_id`, `game_mode`, `current_map`, etc.) |
| `context.players`                  | object | Player counts (`active`, `total`, `spectators`)          |
| `timestamp`                        | int    | Unix timestamp                                           |

### 2.3.2 Heartbeat (`plugin_heartbeat`)

**Event name:** `pixel_control.connectivity.plugin_heartbeat`
**Trigger:** Timer, every `heartbeat_interval_seconds` (default: 120s)

| Payload Field     | Type   | Description                                    |
| ----------------- | ------ | ---------------------------------------------- |
| `type`            | string | `plugin_heartbeat`                             |
| `capabilities`    | object | Same as registration capabilities              |
| `queue_depth`     | int    | Current queue depth                            |
| `queue`           | object | Full queue state (depth, max, watermark, drops) |
| `retry`           | object | Retry config (max attempts, backoff, batch)    |
| `outage`          | object | Outage state (active, failure count, recovery)  |
| `context.server`  | object | Server info snapshot                           |
| `context.players` | object | Player counts                                  |
| `timestamp`       | int    | Unix timestamp                                 |

**API read endpoints:**

| Method | Endpoint                                          | Description                                     | Dev Status | Priority |
| ------ | ------------------------------------------------- | ----------------------------------------------- | ---------- | -------- |
| `GET`  | `/v1/servers/:serverLogin/status`                 | Latest server status (from heartbeat + registration) | Done ✅    | P1.7     |
| `GET`  | `/v1/servers/:serverLogin/status/health`          | Plugin health (queue, outage, connectivity)      | Done ✅    | P1.8     |
| `GET`  | `/v1/servers/:serverLogin/status/capabilities`    | Plugin capabilities snapshot                     | Done ✅    | P2.10    |

---

## 2.4 Combat / Stats

Category: `combat`. Trigger: ShootMania script callbacks (automatic, real-time during gameplay).

All 6 combat events share a common payload structure:

| Payload Field            | Type   | Description                                              |
| ------------------------ | ------ | -------------------------------------------------------- |
| `event_kind`             | string | See table below                                          |
| `counter_scope`          | string | `runtime_session`                                        |
| `player_counters`        | object | Map of `login` → counter object                         |
| `tracked_player_count`   | int    | Number of tracked players                                |
| `dimensions`             | object | Event-specific dimension data (see below)                |
| `field_availability`     | object | Boolean map of available dimension fields                |
| `missing_dimensions`     | array  | List of dimension field names not available for this event |
| `raw_callback_summary`   | object | Raw callback arguments                                   |

**Player counter fields:** `kills`: int, `deaths`: int, `hits`: int, `shots`: int, `misses`: int, `rockets`: int, `lasers`: int, `accuracy`: float

**Dimension fields:**

| Dimension          | Type   | Available in                       |
| ------------------ | ------ | ---------------------------------- |
| `weapon_id`        | int    | All combat events                  |
| `damage`           | int    | `onhit` only                       |
| `distance`         | float  | `onnearmiss` only                  |
| `event_time`       | int    | All                                |
| `shooter`          | object | All (login, nickname, team_id, is_spectator) |
| `victim`           | object | `onhit`, `onarmorempty`            |
| `shooter_position` | object | All (`x`, `y`, `z`)               |
| `victim_position`  | object | `onhit`, `onarmorempty`            |

Weapon IDs: `1`=laser, `2`=rocket, `3`=nucleus, `4`=grenade, `5`=arrow, `6`=missile

**Combat events:**

| Event Name                                             | Source Callback    | Trigger                 |
| ------------------------------------------------------ | ------------------ | ----------------------- |
| `pixel_control.combat.shootmania_event_onshoot`        | `SM_ONSHOOT`       | Player fires            |
| `pixel_control.combat.shootmania_event_onhit`          | `SM_ONHIT`         | Player hits another     |
| `pixel_control.combat.shootmania_event_onnearmiss`     | `SM_ONNEARMISS`    | Near miss detected      |
| `pixel_control.combat.shootmania_event_onarmorempty`   | `SM_ONARMOREMPTY`  | Player eliminated       |
| `pixel_control.combat.shootmania_event_oncapture`      | `SM_ONCAPTURE`     | Point captured          |
| `pixel_control.combat.shootmania_event_scores`         | `SM_SCORES`        | Score update (round/map/match boundary) |

**Additional fields on `oncapture`:** `capture_players`: string[] (logins of capturing players)

**Additional fields on `scores`:**

| Field              | Type   | Description                                           |
| ------------------ | ------ | ----------------------------------------------------- |
| `scores_section`   | string | `EndRound`, `EndMap`, `EndMatch`, etc.                |
| `scores_snapshot`  | object | Full scores (teams, players, ranks, points)           |
| `scores_result`    | object | `result_state`, `winning_side`, `winning_reason`      |

`scores_result.result_state`: `team_win`, `player_win`, `tie`, `draw`, `unavailable`

**API read endpoints:**

| Method | Endpoint                                                  | Description                                  | Dev Status | Priority |
| ------ | --------------------------------------------------------- | -------------------------------------------- | ---------- | -------- |
| `GET`  | `/v1/servers/:serverLogin/stats/combat`                              | Aggregated combat stats (current session)    | Done ✅    | P2.3     |
| `GET`  | `/v1/servers/:serverLogin/stats/combat/players`                      | Per-player combat counters                   | Done ✅    | P2.4     |
| `GET`  | `/v1/servers/:serverLogin/stats/combat/players/:login`               | Single player combat counters                | Done ✅    | P2.5     |
| `GET`  | `/v1/servers/:serverLogin/stats/scores`                              | Latest scores snapshot                       | Done ✅    | P2.6     |
| `GET`  | `/v1/servers/:serverLogin/stats/combat/maps`                         | Per-map combat stats list (paginated)        | Done ✅    | P2.5.1   |
| `GET`  | `/v1/servers/:serverLogin/stats/combat/maps/:mapUid`                 | Combat stats for a specific map UID          | Done ✅    | P2.5.2   |
| `GET`  | `/v1/servers/:serverLogin/stats/combat/maps/:mapUid/players/:login`  | Player combat stats on a specific map        | Done ✅    | P2.5.3   |
| `GET`  | `/v1/servers/:serverLogin/stats/combat/series`                       | Per-series (Best-Of) combat stats (paginated)| Done ✅    | P2.5.4   |

### Per-Map / Per-Series Combat Stats (P2.5 additions)

Data source: lifecycle events (category `lifecycle`) stored in the `Event` table. Specifically:
- **Map entries** come from `map.end` variant events that carry `payload.aggregate_stats` with `scope: "map"`.
- **Series entries** are formed by pairing `match.begin` and `match.end` variant lifecycle events; all `map.end` events whose `sourceTime` falls between the pair's begin/end times are included as that series's maps.

**`GET /v1/servers/:serverLogin/stats/combat/maps`** (P2.5.1)
- Query params: `limit` (default 50, max 200), `offset` (default 0), `since` (ISO8601), `until` (ISO8601).
- Response: `{ server_login, maps: MapCombatStatsEntry[], pagination: { total, limit, offset } }`.
- Maps ordered most-recent first (by `sourceTime` of the `map.end` event).

**`GET /v1/servers/:serverLogin/stats/combat/maps/:mapUid`** (P2.5.2)
- Returns the latest `MapCombatStatsEntry` for the given `mapUid`. 404 if not found.

**`GET /v1/servers/:serverLogin/stats/combat/maps/:mapUid/players/:login`** (P2.5.3)
- Returns `{ server_login, map_uid, map_name, player_login, counters: PlayerCountersDelta, played_at }`.
- 404 if map or player not found.

**`GET /v1/servers/:serverLogin/stats/combat/series`** (P2.5.4)
- Query params: `limit`, `offset`, `since`, `until` (same as maps endpoint).
- Response: `{ server_login, series: SeriesCombatEntry[], pagination: { total, limit, offset } }`.
- Only complete series (both `match.begin` and `match.end`) are returned. Series ordered most-recent first.

**`MapCombatStatsEntry` shape:**
```json
{
  "map_uid": "uid-alpha",
  "map_name": "Alpha Arena",
  "played_at": "2026-02-28T10:00:00.000Z",
  "duration_seconds": 120,
  "player_stats": {
    "player1": { "kills": 5, "deaths": 2, "hits": 20, "shots": 40, "misses": 20, "rockets": 10, "lasers": 10, "accuracy": 0.5 }
  },
  "team_stats": [...],
  "totals": { "kills": 5, "deaths": 2 },
  "win_context": { "winner_team_id": 0 },
  "event_id": "pc-evt-lifecycle-..."
}
```

**`SeriesCombatEntry` shape:**
```json
{
  "match_started_at": "2026-02-28T09:00:00.000Z",
  "match_ended_at": "2026-02-28T10:30:00.000Z",
  "total_maps_played": 2,
  "maps": [...],
  "series_totals": { "kills": 18, "deaths": 9 },
  "series_win_context": { "winner_team_id": 0 }
}
```

---

## 2.5 Lifecycle

Category: `lifecycle`. Trigger: ManiaControl callbacks and mode script callbacks.

All lifecycle events share a base payload:

| Payload Field            | Type   | Description                                                     |
| ------------------------ | ------ | --------------------------------------------------------------- |
| `variant`                | string | `match.begin`, `match.end`, `map.begin`, `map.end`, `round.begin`, `round.end`, `warmup.start`, `warmup.end`, `warmup.status`, `pause.start`, `pause.end`, `pause.status` |
| `phase`                  | string | `match`, `map`, `round`, `warmup`, `pause`                     |
| `state`                  | string | `begin`, `end`, `start`, `status`                               |
| `source_channel`         | string | `maniaplanet` or `script`                                       |
| `raw_source_callback`    | string | Original callback name                                          |
| `raw_callback_summary`   | object | Raw callback arguments                                          |

**Lifecycle events (22 canonical):**

| Event Name | Source Callback | Variant | Channel |
| --- | --- | --- | --- |
| `pixel_control.lifecycle.maniaplanet_beginmatch` | `CB_MP_BEGINMATCH` | `match.begin` | maniaplanet |
| `pixel_control.lifecycle.maniaplanet_endmatch` | `CB_MP_ENDMATCH` | `match.end` | maniaplanet |
| `pixel_control.lifecycle.maniaplanet_beginmap` | `CB_MP_BEGINMAP` | `map.begin` | maniaplanet |
| `pixel_control.lifecycle.maniaplanet_endmap` | `CB_MP_ENDMAP` | `map.end` | maniaplanet |
| `pixel_control.lifecycle.maniaplanet_beginround` | `CB_MP_BEGINROUND` | `round.begin` | maniaplanet |
| `pixel_control.lifecycle.maniaplanet_endround` | `CB_MP_ENDROUND` | `round.end` | maniaplanet |
| `pixel_control.lifecycle.maniaplanet_warmup_start` | `MP_WARMUP_START` | `warmup.start` | script |
| `pixel_control.lifecycle.maniaplanet_warmup_end` | `MP_WARMUP_END` | `warmup.end` | script |
| `pixel_control.lifecycle.maniaplanet_warmup_status` | `MP_WARMUP_STATUS` | `warmup.status` | script |
| `pixel_control.lifecycle.maniaplanet_pause_status` | — | `pause.start`/`pause.end`/`pause.status` | script |
| `pixel_control.lifecycle.maniaplanet_startmatch_start` | `MP_STARTMATCHSTART` | `match.begin` | script |
| `pixel_control.lifecycle.maniaplanet_startmatch_end` | `MP_STARTMATCHEND` | `match.begin` | script |
| `pixel_control.lifecycle.maniaplanet_endmatch_start` | `MP_ENDMATCHSTART` | `match.end` | script |
| `pixel_control.lifecycle.maniaplanet_endmatch_end` | `MP_ENDMATCHEND` | `match.end` | script |
| `pixel_control.lifecycle.maniaplanet_loadingmap_start` | `MP_LOADINGMAPSTART` | `map.begin` | script |
| `pixel_control.lifecycle.maniaplanet_loadingmap_end` | `MP_LOADINGMAPEND` | `map.begin` | script |
| `pixel_control.lifecycle.maniaplanet_unloadingmap_start` | `MP_UNLOADINGMAPSTART` | `map.end` | script |
| `pixel_control.lifecycle.maniaplanet_unloadingmap_end` | `MP_UNLOADINGMAPEND` | `map.end` | script |
| `pixel_control.lifecycle.maniaplanet_startround_start` | `MP_STARTROUNDSTART` | `round.begin` | script |
| `pixel_control.lifecycle.maniaplanet_startround_end` | `MP_STARTROUNDEND` | `round.begin` | script |
| `pixel_control.lifecycle.maniaplanet_endround_start` | `MP_ENDROUNDSTART` | `round.end` | script |
| `pixel_control.lifecycle.maniaplanet_endround_end` | `MP_ENDROUNDEND` | `round.end` | script |

**Elite mode additionally emits 2 lifecycle projections:**

| Event Name | Source Callback | Variant |
| --- | --- | --- |
| `pixel_control.lifecycle.maniacontrol_callbacks_structures_shootmania_onelitestartturnstructure` | `SM_ELITE_STARTTURN` | `round.begin` |
| `pixel_control.lifecycle.maniacontrol_callbacks_structures_shootmania_oneliteendturnstructure` | `SM_ELITE_ENDTURN` | `round.end` |

**Additive payload on `round.end` / `map.end` — `aggregate_stats`:**

| Field                    | Type   | Description                                                |
| ------------------------ | ------ | ---------------------------------------------------------- |
| `scope`                  | string | `round` or `map`                                           |
| `counter_scope`          | string | `combat_delta`                                             |
| `player_counters_delta`  | object | Per-player counter deltas for this scope                   |
| `totals`                 | object | Aggregated totals across all players                       |
| `team_counters_delta`    | array  | Per-team aggregated counters                               |
| `team_summary`           | object | Team count, assignment source, unresolved logins           |
| `tracked_player_count`   | int    | Players tracked in this window                             |
| `window`                 | object | Start/end timestamps, duration, boundary callbacks          |
| `source_coverage`        | object | Which callbacks contributed data                           |
| `win_context`            | object | `result_state`, `winning_side`, `winning_reason`, tie/draw markers |

`win_context.result_state`: `team_win`, `player_win`, `tie`, `draw`, `unavailable`

**Additive payload on `map.begin` / `map.end` — `map_rotation`:**

| Field                       | Type   | Description                                          |
| --------------------------- | ------ | ---------------------------------------------------- |
| `map_pool`                  | array  | Full map pool                                        |
| `map_pool_size`             | int    | Number of maps in pool                               |
| `current_map`               | object | Current map info (`uid`, `name`, `file`, etc.)       |
| `current_map_index`         | int    | Index in pool                                        |
| `next_maps`                 | array  | Upcoming maps in rotation                            |
| `played_map_order`          | array  | Maps played so far (order, uid, repeat flag)         |
| `played_map_count`          | int    | Number of maps played                                |
| `series_targets`            | object | `best_of`, `maps_score`, `current_map_score`         |
| `veto_draft_mode`           | string | Active veto mode (or empty)                          |
| `veto_draft_session_status` | string | `idle`, `running`, `completed`, `cancelled`          |
| `matchmaking_ready_armed`   | bool   | Ready gate state                                     |
| `veto_draft_actions`        | object | Draft action stream (`ban`, `pick`, `pass`, `lock`)  |
| `veto_result`               | object | Veto result projection (`status`, `final_map`)       |
| `matchmaking_lifecycle`     | object | Lifecycle stage snapshot                             |

**Admin action projection** (on script lifecycle events):

| Field             | Type   | Description                                  |
| ----------------- | ------ | -------------------------------------------- |
| `admin_action`    | object | Linked admin action metadata (when applicable) |
| `.action_name`    | string | e.g. `warmup.start`, `pause.end`             |
| `.action_domain`  | string | `match_flow`                                 |
| `.action_type`    | string | `warmup`, `pause`, `match_start`, etc.       |
| `.actor`          | object | Who triggered (`login`, `nickname`, `type`)  |

**API read endpoints:**

| Method | Endpoint                                              | Description                                  | Dev Status | Priority |
| ------ | ----------------------------------------------------- | -------------------------------------------- | ---------- | -------- |
| `GET`  | `/v1/servers/:serverLogin/lifecycle`                  | Current lifecycle state (phase, warmup, pause) | Done ✅    | P2.7     |
| `GET`  | `/v1/servers/:serverLogin/lifecycle/map-rotation`     | Current map rotation + veto state            | Done ✅    | P2.8     |
| `GET`  | `/v1/servers/:serverLogin/lifecycle/aggregate-stats`  | Latest aggregate stats (round/map scope)     | Done ✅    | P2.9     |

---

## 2.6 Player

Category: `player`. Trigger: ManiaControl player callbacks (automatic on connect/disconnect/info change).

**Player events:**

| Event Name | Source Callback | Transition Kind |
| --- | --- | --- |
| `pixel_control.player.playermanagercallback_playerconnect` | `CB_PLAYERCONNECT` | `connectivity` |
| `pixel_control.player.playermanagercallback_playerdisconnect` | `CB_PLAYERDISCONNECT` | `connectivity` |
| `pixel_control.player.playermanagercallback_playerinfochanged` | `CB_PLAYERINFOCHANGED` | `state_change` |
| `pixel_control.player.playermanagercallback_playerinfoschanged` | `CB_PLAYERINFOSCHANGED` | `batch_refresh` |

**Payload fields:**

| Field                    | Type   | Description                                              |
| ------------------------ | ------ | -------------------------------------------------------- |
| `event_kind`             | string | `player.connect`, `player.disconnect`, `player.info_changed`, `player.infos_changed` |
| `transition_kind`        | string | `connectivity`, `state_change`, `batch_refresh`          |
| `player`                 | object | Current player state (see below)                         |
| `previous_player`        | object | Previous player state (same shape)                       |
| `state_delta`            | object | Per-field before/after/changed deltas                    |
| `permission_signals`     | object | Auth level, role, slot, readiness, eligibility changes   |
| `roster_state`           | object | Current/previous/delta/aggregate roster                  |
| `admin_correlation`      | object | Correlation with recent admin actions                    |
| `reconnect_continuity`   | object | Session chain, identity, ordinal                         |
| `side_change`            | object | Team-side transition detection                           |
| `constraint_signals`     | object | Forced team/slot policy context                          |
| `roster_snapshot`        | object | Server-wide player counts                                |
| `field_availability`     | object | Boolean map of available fields                          |
| `missing_fields`         | array  | Fields not available for this event                      |

**`player` object (key fields):**

| Field                | Type   | Description                                   |
| -------------------- | ------ | --------------------------------------------- |
| `login`              | string | Player login                                  |
| `nickname`           | string | Player display name                           |
| `team_id`            | int    | Team assignment (0, 1, or -1)                 |
| `is_spectator`       | bool   | Whether in spectator mode                     |
| `is_connected`       | bool   | Connection state                              |
| `has_joined_game`    | bool   | Whether joined the game                       |
| `auth_level`         | int    | ManiaControl auth level                       |
| `auth_name`          | string | `player`, `moderator`, `admin`, `superadmin`  |
| `connectivity_state` | string | `connected`, `disconnected`                   |
| `readiness_state`    | string | `ready`, `not_ready`, etc.                    |
| `eligibility_state`  | string | `eligible`, `ineligible`, etc.                |
| `can_join_round`     | bool   | Whether player can join next round            |

**`constraint_signals` (additive):**

| Field                  | Type   | Description                                       |
| ---------------------- | ------ | ------------------------------------------------- |
| `policy_context`       | object | Server policy snapshot (forced_teams, keep_slots, limits) |
| `forced_team_policy`   | object | `policy_state`, team assignment semantics          |
| `slot_policy`          | object | Slot state, forced-spectator pressure hints        |

**API read endpoints:**

| Method | Endpoint                                                | Description                               | Dev Status | Priority |
| ------ | ------------------------------------------------------- | ----------------------------------------- | ---------- | -------- |
| `GET`  | `/v1/servers/:serverLogin/players`                      | Current player list with state            | Done ✅    | P2.1     |
| `GET`  | `/v1/servers/:serverLogin/players/:login`               | Single player state                       | Done ✅    | P2.2     |
| `GET`  | `/v1/servers/:serverLogin/players/:login/history`       | Player connection/state history            | Todo 🛑    | P5.15    |

---

## 2.7 Mode-Specific

Category: `mode`. Trigger: Mode-specific ShootMania script callbacks.

These events carry raw callback data with minimal normalization. Elite turn events also produce lifecycle `round.begin`/`round.end` projections (see section 2.5).

| Event Name | Source Callback | Game Mode |
| --- | --- | --- |
| `pixel_control.mode.shootmania_elite_startturn` | `SM_ELITE_STARTTURN` | Elite |
| `pixel_control.mode.shootmania_elite_endturn` | `SM_ELITE_ENDTURN` | Elite |
| `pixel_control.mode.shootmania_joust_onreload` | `SM_JOUST_ONRELOAD` | Joust |
| `pixel_control.mode.shootmania_joust_selectedplayers` | `SM_JOUST_SELECTEDPLAYERS` | Joust |
| `pixel_control.mode.shootmania_joust_roundresult` | `SM_JOUST_ROUNDRESULT` | Joust |
| `pixel_control.mode.shootmania_royal_points` | `SM_ROYAL_POINTS` | Royal |
| `pixel_control.mode.shootmania_royal_playerspawn` | `SM_ROYAL_PLAYERSPAWN` | Royal |
| `pixel_control.mode.shootmania_royal_roundwinner` | `SM_ROYAL_ROUNDWINNER` | Royal |

Payload: `raw_callback_summary` with argument list from the script callback.

**API read endpoints:**

| Method | Endpoint                                               | Description                                | Dev Status | Priority |
| ------ | ------------------------------------------------------ | ------------------------------------------ | ---------- | -------- |
| `GET`  | `/v1/servers/:serverLogin/mode`                        | Current game mode + active mode events     | Done ✅    | P2.12    |

---

# 3. Server Link Management

## 3.1 Link Flow Overview

Before the API server can send commands to a game server plugin, the two must be **linked**. The link establishes mutual trust via a shared bearer token (`link_bearer`).

**Flow:**

1. **Registration** — The game server (or an admin) registers the server login with the API. This creates the server identity in the API database.
2. **Token generation** — The API generates a link token for the server. This token is the shared secret.
3. **Plugin configuration** — The link token and API base URL are configured on the plugin side, either via:
   - Environment variables: `PIXEL_CONTROL_LINK_SERVER_URL`, `PIXEL_CONTROL_LINK_TOKEN`
   - ManiaControl settings: `Pixel Control Link Server URL`, `Pixel Control Link Token`
   - Chat command (super admin): `//pcadmin server.link.set base_url=<url> link_token=<token>`
4. **Auth verification** — When the API sends a command to the plugin socket, it includes `server_login`, `auth.mode=link_bearer`, and `auth.token`. The plugin validates these fields against its local configuration.
5. **Auth state check** — The API or external clients can verify whether a server is properly linked and operational.

**Plugin-side chat commands (super admin only):**

| Command                                             | Description                        |
| --------------------------------------------------- | ---------------------------------- |
| `//pcadmin server.link.set base_url=<url> link_token=<token>` | Configure link on plugin |
| `//pcadmin server.link.status`                      | Show current link configuration    |

## 3.2 Link API Endpoints

| Method | Endpoint                                            | Body / Params                                  | Description                                         | Dev Status | Priority  |
| ------ | --------------------------------------------------- | ---------------------------------------------- | --------------------------------------------------- | ---------- | --------- |
| `PUT`  | `/v1/servers/:serverLogin/link/registration`        | `{ server_name?, game_mode?, title_id? }`      | Register or update a server identity                | Done ✅    | P0.1🔥    |
| `GET`  | `/v1/servers/:serverLogin/link/access`              | —                                              | Check server access/permissions                     | Done ✅    | P0.4🔥    |
| `POST` | `/v1/servers/:serverLogin/link/token`               | `{ rotate?: bool }`                            | Generate or rotate the link token                   | Done ✅    | P0.2🔥    |
| `GET`  | `/v1/servers/:serverLogin/link/auth-state`          | —                                              | Check if the server is linked and auth is valid     | Done ✅    | P0.3🔥    |
| `GET`  | `/v1/servers`                                       | `?status=linked\|all\|offline` (default: `all`) | List all registered servers with link & online state | Done ✅    | P0.6🔥    |
| `DELETE`| `/v1/servers/:serverLogin`                         | —                                              | Delete a server and all its associated events       | Done ✅    | P0.7🔥    |

**Delete server response:**

| Field              | Type   | Description                                   |
| ------------------ | ------ | --------------------------------------------- |
| `server_login`     | string | Deleted server login                          |
| `deleted`          | bool   | Whether deletion succeeded                    |

**List servers response:**

Returns an array of server summaries:

| Field              | Type   | Description                                   |
| ------------------ | ------ | --------------------------------------------- |
| `server_login`     | string | Dedicated server login                        |
| `server_name`      | string | Server display name (or null)                 |
| `linked`           | bool   | Whether the server has a valid link token     |
| `online`           | bool   | Whether the server is currently online        |
| `last_heartbeat`   | string | ISO timestamp of last heartbeat (or null)     |
| `plugin_version`   | string | Last known plugin version (or null)           |
| `game_mode`        | string | Last known game mode (or null)                |
| `title_id`         | string | Last known title ID (or null)                 |

Query parameter `status` filters: `linked` (only linked servers), `offline` (only offline), `all` (default — no filter).

**Registration flow response:**

| Field              | Type   | Description                                   |
| ------------------ | ------ | --------------------------------------------- |
| `server_login`     | string | Registered server login                       |
| `registered`       | bool   | Whether registration succeeded                |
| `link_token`       | string | Generated token (only on first registration)  |

**Auth state response:**

| Field              | Type   | Description                                   |
| ------------------ | ------ | --------------------------------------------- |
| `server_login`     | string | Server login                                  |
| `linked`           | bool   | Whether the server has a valid link token     |
| `last_heartbeat`   | string | ISO timestamp of last heartbeat (or null)     |
| `plugin_version`   | string | Last known plugin version (or null)           |
| `online`           | bool   | Whether the server is currently online        |

---

# 4. API Endpoints Summary

All endpoints are scoped under `/v1/servers/:serverLogin/` where `:serverLogin` identifies the target game server.

## 4.1 Command Endpoints (write — proxy to plugin socket)

| Method   | Endpoint                                    | Plugin Action       | Domain       | Dev Status | Priority  |
| -------- | ------------------------------------------- | ------------------- | ------------ | ---------- | --------- |
| `POST`   | `.../maps/skip`                             | `map.skip`          | Maps         | Todo 🛑    | P3.1      |
| `POST`   | `.../maps/restart`                          | `map.restart`       | Maps         | Todo 🛑    | P3.2      |
| `POST`   | `.../maps/jump`                             | `map.jump`          | Maps         | Todo 🛑    | P3.3      |
| `POST`   | `.../maps/queue`                            | `map.queue`         | Maps         | Todo 🛑    | P3.4      |
| `POST`   | `.../maps`                                  | `map.add`           | Maps         | Todo 🛑    | P3.5      |
| `DELETE`  | `.../maps/:mapUid`                         | `map.remove`        | Maps         | Todo 🛑    | P3.6      |
| `POST`   | `.../warmup/extend`                         | `warmup.extend`     | Warmup       | Todo 🛑    | P3.7      |
| `POST`   | `.../warmup/end`                            | `warmup.end`        | Warmup       | Todo 🛑    | P3.8      |
| `POST`   | `.../pause/start`                           | `pause.start`       | Pause        | Todo 🛑    | P3.9      |
| `POST`   | `.../pause/end`                             | `pause.end`         | Pause        | Todo 🛑    | P3.10     |
| `POST`   | `.../votes/cancel`                          | `vote.cancel`       | Votes        | Todo 🛑    | P5.10     |
| `PUT`    | `.../votes/ratio`                           | `vote.set_ratio`    | Votes        | Todo 🛑    | P5.11     |
| `POST`   | `.../votes/custom`                          | `vote.custom_start` | Votes        | Todo 🛑    | P5.12     |
| `PUT`    | `.../votes/policy`                          | `vote.policy.set`   | Votes        | Todo 🛑    | P5.14     |
| `POST`   | `.../players/:login/force-team`             | `player.force_team` | Players      | Todo 🛑    | P4.6      |
| `POST`   | `.../players/:login/force-play`             | `player.force_play` | Players      | Todo 🛑    | P4.7      |
| `POST`   | `.../players/:login/force-spec`             | `player.force_spec` | Players      | Todo 🛑    | P4.8      |
| `POST`   | `.../players/:login/auth`                   | `auth.grant`        | Auth         | Todo 🛑    | P5.1      |
| `DELETE`  | `.../players/:login/auth`                  | `auth.revoke`       | Auth         | Todo 🛑    | P5.2      |
| `POST`   | `.../whitelist/enable`                      | `whitelist.enable`  | Whitelist    | Todo 🛑    | P5.3      |
| `POST`   | `.../whitelist/disable`                     | `whitelist.disable` | Whitelist    | Todo 🛑    | P5.4      |
| `POST`   | `.../whitelist`                             | `whitelist.add`     | Whitelist    | Todo 🛑    | P5.5      |
| `DELETE`  | `.../whitelist/:login`                     | `whitelist.remove`  | Whitelist    | Todo 🛑    | P5.6      |
| `DELETE`  | `.../whitelist`                            | `whitelist.clean`   | Whitelist    | Todo 🛑    | P5.8      |
| `POST`   | `.../whitelist/sync`                        | `whitelist.sync`    | Whitelist    | Todo 🛑    | P5.9      |
| `PUT`    | `.../teams/policy`                          | `team.policy.set`   | Teams        | Todo 🛑    | P4.9      |
| `POST`   | `.../teams/roster`                          | `team.roster.assign`| Teams        | Todo 🛑    | P4.11     |
| `DELETE`  | `.../teams/roster/:login`                  | `team.roster.unassign` | Teams     | Todo 🛑    | P4.12     |
| `PUT`    | `.../match/best-of`                         | `match.bo.set`      | Match        | Todo 🛑    | P3.11     |
| `PUT`    | `.../match/maps-score`                      | `match.maps.set`    | Match        | Todo 🛑    | P3.13     |
| `PUT`    | `.../match/round-score`                     | `match.score.set`   | Match        | Todo 🛑    | P3.15     |
| `POST`   | `.../veto/ready`                            | VetoDraft.Ready     | Veto         | Todo 🛑    | P4.2      |
| `POST`   | `.../veto/start`                            | VetoDraft.Start     | Veto         | Todo 🛑    | P4.3      |
| `POST`   | `.../veto/action`                           | VetoDraft.Action    | Veto         | Todo 🛑    | P4.4      |
| `POST`   | `.../veto/cancel`                           | VetoDraft.Cancel    | Veto         | Todo 🛑    | P4.5      |

## 4.2 Link Management Endpoints

| Method | Endpoint                                    | Description                          | Dev Status | Priority  |
| ------ | ------------------------------------------- | ------------------------------------ | ---------- | --------- |
| `PUT`  | `.../link/registration`                     | Register/update server identity      | Done ✅    | P0.1🔥    |
| `GET`  | `.../link/access`                           | Check server access                  | Done ✅    | P0.4🔥    |
| `POST` | `.../link/token`                            | Generate/rotate link token           | Done ✅    | P0.2🔥    |
| `GET`  | `.../link/auth-state`                       | Check link auth state                | Done ✅    | P0.3🔥    |
| `GET`  | `/v1/servers`                               | List all registered servers          | Done ✅    | P0.6🔥    |
| `DELETE`| `/v1/servers/:serverLogin`                 | Delete server and associated events  | Done ✅    | P0.7🔥    |

## 4.3 Read Endpoints (from ingested plugin telemetry)

| Method | Endpoint                                    | Source Category  | Description                           | Dev Status | Priority |
| ------ | ------------------------------------------- | ---------------- | ------------------------------------- | ---------- | -------- |
| `GET`  | `.../status`                                | connectivity     | Server status (heartbeat data)        | Done ✅    | P1.7     |
| `GET`  | `.../status/health`                         | connectivity     | Plugin health (queue, outage)         | Done ✅    | P1.8     |
| `GET`  | `.../status/capabilities`                   | connectivity     | Plugin capabilities                   | Done ✅    | P2.10    |
| `GET`  | `.../maps`                                  | lifecycle        | Map pool (from telemetry)             | Done ✅    | P2.11    |
| `GET`  | `.../players`                               | player           | Current player list                   | Done ✅    | P2.1     |
| `GET`  | `.../players/:login`                        | player           | Single player state                   | Done ✅    | P2.2     |
| `GET`  | `.../players/:login/history`                | player           | Player connection history             | Todo 🛑    | P5.15    |
| `GET`  | `.../stats/combat`                              | combat           | Aggregated combat stats               | Done ✅    | P2.3     |
| `GET`  | `.../stats/combat/players`                      | combat           | Per-player combat counters            | Done ✅    | P2.4     |
| `GET`  | `.../stats/combat/players/:login`               | combat           | Single player combat stats            | Done ✅    | P2.5     |
| `GET`  | `.../stats/scores`                              | combat           | Latest scores snapshot                | Done ✅    | P2.6     |
| `GET`  | `.../stats/combat/maps`                         | lifecycle        | Per-map combat stats list             | Done ✅    | P2.5.1   |
| `GET`  | `.../stats/combat/maps/:mapUid`                 | lifecycle        | Combat stats for specific map         | Done ✅    | P2.5.2   |
| `GET`  | `.../stats/combat/maps/:mapUid/players/:login`  | lifecycle        | Player combat stats on specific map   | Done ✅    | P2.5.3   |
| `GET`  | `.../stats/combat/series`                       | lifecycle        | Per-series (BO) combat stats          | Done ✅    | P2.5.4   |
| `GET`  | `.../lifecycle`                             | lifecycle        | Current lifecycle state               | Done ✅    | P2.7     |
| `GET`  | `.../lifecycle/map-rotation`                | lifecycle        | Map rotation + veto state             | Done ✅    | P2.8     |
| `GET`  | `.../lifecycle/aggregate-stats`             | lifecycle        | Latest aggregate stats                | Done ✅    | P2.9     |
| `GET`  | `.../votes/policy`                          | connectivity     | Current vote policy                   | Todo 🛑    | P5.13    |
| `GET`  | `.../teams/policy`                          | connectivity     | Current team policy                   | Todo 🛑    | P4.10    |
| `GET`  | `.../teams/roster`                          | connectivity     | Current roster                        | Todo 🛑    | P4.13    |
| `GET`  | `.../whitelist`                             | connectivity     | Current whitelist                     | Todo 🛑    | P5.7     |
| `GET`  | `.../match/best-of`                         | connectivity     | Best-of configuration                 | Todo 🛑    | P3.12    |
| `GET`  | `.../match/maps-score`                      | connectivity     | Maps score state                      | Todo 🛑    | P3.14    |
| `GET`  | `.../match/round-score`                     | connectivity     | Round score state                     | Todo 🛑    | P3.16    |
| `GET`  | `.../veto/status`                           | veto             | Veto/draft session state              | Todo 🛑    | P4.1     |
| `GET`  | `.../mode`                                  | mode             | Current game mode + events            | Done ✅    | P2.12    |

## 4.4 Ingestion Endpoints (plugin → server, internal)

> **Note (P1):** The plugin sends ALL event categories to the single `POST /v1/plugin/events` endpoint. Category routing is performed server-side based on `event_category`. Per-category URLs do not exist in the implementation.

| Method | Endpoint                               | Description                                                                                   | Dev Status | Priority  |
| ------ | -------------------------------------- | --------------------------------------------------------------------------------------------- | ---------- | --------- |
| `POST` | `/v1/plugin/events`                    | Unified ingestion: all categories (connectivity, lifecycle, combat, player, mode, batch)      | Done ✅    | P0.5+P1   |
