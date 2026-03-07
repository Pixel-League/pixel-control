/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { CancelablePromise } from '../core/CancelablePromise';
import type { BaseHttpRequest } from '../core/BaseHttpRequest';
export class StatsService {
    constructor(public readonly httpRequest: BaseHttpRequest) {}
    /**
     * Get aggregated combat stats
     * Returns aggregated combat statistics for the server. Counter values come from the latest combat event (plugin counters are cumulative session totals, not deltas). Includes total kills, deaths, hits, shots, accuracy, tracked player count, and event kind breakdown. Supports optional time-range filtering via since/until (ISO8601).
     * @param serverLogin The dedicated server login (unique identifier)
     * @param since Filter events after this ISO8601 timestamp
     * @param until Filter events before this ISO8601 timestamp
     * @returns any Combat summary returned. Fields: server_login, combat_summary { total_events, total_kills, total_deaths, total_hits, total_shots, total_accuracy, tracked_player_count, event_kinds {} }, time_range { since, until, event_count }.
     * @throws ApiError
     */
    public statsReadControllerGetCombatStats(
        serverLogin: string,
        since?: string,
        until?: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/stats/combat',
            path: {
                'serverLogin': serverLogin,
            },
            query: {
                'since': since,
                'until': until,
            },
            errors: {
                404: `Server not found.`,
            },
        });
    }
    /**
     * Get per-player combat counters
     * Returns per-player combat counters from the latest combat event for this server. The counters reflect cumulative runtime session totals (kills, deaths, hits, shots, misses, rockets, lasers, accuracy, kd_ratio). Also includes weapon-specific hit fields: hits_rocket and hits_laser (null for events predating plugin v2), and derived accuracies: rocket_accuracy and laser_accuracy (null when hits_rocket/hits_laser are null). Elite mode fields: attack_rounds_played, attack_rounds_won, attack_win_rate, defense_rounds_played, defense_rounds_won, defense_win_rate (null for non-Elite or old events). Supports pagination and optional time-range filtering.
     * @param serverLogin The dedicated server login (unique identifier)
     * @param limit Max items to return (1–200, default 50)
     * @param offset Items to skip (default 0)
     * @param since Filter events after this ISO8601 timestamp
     * @param until Filter events before this ISO8601 timestamp
     * @returns any Per-player counters returned. Fields: data (array of { login, kills, deaths, hits, shots, misses, rockets, lasers, accuracy, kd_ratio, hits_rocket, hits_laser, rocket_accuracy, laser_accuracy, attack_rounds_played, attack_rounds_won, attack_win_rate, defense_rounds_played, defense_rounds_won, defense_win_rate }), pagination { total, limit, offset }.
     * @throws ApiError
     */
    public statsReadControllerGetCombatPlayers(
        serverLogin: string,
        limit: number = 50,
        offset?: number,
        since?: string,
        until?: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/stats/combat/players',
            path: {
                'serverLogin': serverLogin,
            },
            query: {
                'limit': limit,
                'offset': offset,
                'since': since,
                'until': until,
            },
            errors: {
                404: `Server not found.`,
            },
        });
    }
    /**
     * Get player combat stats across recent maps
     * Returns a per-map combat history for a single player, ordered most-recent first. Each map entry is extracted from a lifecycle map.end event that carries aggregate_stats with scope="map". Includes per-map counters (kills, deaths, hits, shots, accuracy, kd_ratio, hits_rocket, hits_laser, rocket_accuracy, laser_accuracy, attack_rounds_played, attack_rounds_won, attack_win_rate, defense_rounds_played, defense_rounds_won, defense_win_rate), map metadata (uid, name, played_at, duration_seconds), win_context, and a won boolean (null when team assignment data is unavailable). Top-level response includes maps_played, maps_won, and win_rate computed across all maps (before pagination). Elite mode fields are null for non-Elite or pre-plugin-update events. Returns empty maps: [] (not 404) when the player has no map history. Supports pagination (default limit 10) and ISO8601 time-range filtering.
     * @param serverLogin The dedicated server login (unique identifier)
     * @param login The player login (unique ManiaPlanet account identifier)
     * @param limit Max maps to return (1–200, default 10)
     * @param offset Maps to skip (default 0)
     * @param since Return maps played after this ISO8601 timestamp
     * @param until Return maps played before this ISO8601 timestamp
     * @returns any Player map history returned. Fields: server_login, player_login, maps_played, maps_won, win_rate, maps (array of { map_uid, map_name, played_at, duration_seconds, counters: PlayerCountersDelta, win_context, won }), pagination { total, limit, offset }. PlayerCountersDelta includes kd_ratio, hits_rocket, hits_laser, rocket_accuracy, laser_accuracy (rocket/laser fields are null for events predating plugin v2), attack_rounds_played, attack_rounds_won, attack_win_rate, defense_rounds_played, defense_rounds_won, defense_win_rate (Elite fields are null for non-Elite or pre-plugin-update events).
     * @throws ApiError
     */
    public statsReadControllerGetPlayerCombatMapHistory(
        serverLogin: string,
        login: string,
        limit: number = 50,
        offset?: number,
        since?: string,
        until?: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/stats/combat/players/{login}/maps',
            path: {
                'serverLogin': serverLogin,
                'login': login,
            },
            query: {
                'limit': limit,
                'offset': offset,
                'since': since,
                'until': until,
            },
            errors: {
                404: `Server not found.`,
            },
        });
    }
    /**
     * Get single player combat counters
     * Returns combat counters for a specific player login, from the most recent combat event containing that player's data. Also includes the total count of combat events for context and the timestamp of the last update. Counters include kd_ratio, hits_rocket, hits_laser, rocket_accuracy, and laser_accuracy (null for events predating plugin v2). Elite mode fields: attack_rounds_played, attack_rounds_won, attack_win_rate, defense_rounds_played, defense_rounds_won, defense_win_rate (null for non-Elite or old events).
     * @param serverLogin The dedicated server login (unique identifier)
     * @param login The player login (unique ManiaPlanet account identifier)
     * @returns any Player combat counters returned. Fields: login, counters { kills, deaths, hits, shots, misses, rockets, lasers, accuracy, kd_ratio, hits_rocket, hits_laser, rocket_accuracy, laser_accuracy, attack_rounds_played, attack_rounds_won, attack_win_rate, defense_rounds_played, defense_rounds_won, defense_win_rate }, recent_events_count, last_updated (ISO8601).
     * @throws ApiError
     */
    public statsReadControllerGetPlayerCombatCounters(
        serverLogin: string,
        login: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/stats/combat/players/{login}',
            path: {
                'serverLogin': serverLogin,
                'login': login,
            },
            errors: {
                404: `Server not found or no combat data for this player.`,
            },
        });
    }
    /**
     * List per-map combat stats
     * Returns combat statistics broken down by completed map, ordered most-recent first. Each entry is extracted from a lifecycle map.end event that carries aggregate_stats with scope="map". Includes per-player counters (kills, deaths, hits, shots, accuracy, kd_ratio, hits_rocket, hits_laser, rocket_accuracy, laser_accuracy, attack_rounds_played, attack_rounds_won, attack_win_rate, defense_rounds_played, defense_rounds_won, defense_win_rate), team stats, totals, win context, and map metadata (uid, name). hits_rocket/hits_laser/rocket_accuracy/laser_accuracy are null for events predating plugin v2. Elite mode fields are null for non-Elite or pre-plugin-update events. Supports pagination and ISO8601 time-range filtering.
     * @param serverLogin The dedicated server login (unique identifier)
     * @param limit Max maps to return (1–200, default 50)
     * @param offset Maps to skip (default 0)
     * @param since Return maps played after this ISO8601 timestamp
     * @param until Return maps played before this ISO8601 timestamp
     * @returns any Map list returned. Fields: server_login, maps (array of MapCombatStatsEntry), pagination { total, limit, offset }.
     * @throws ApiError
     */
    public statsReadControllerGetCombatMaps(
        serverLogin: string,
        limit: number = 50,
        offset?: number,
        since?: string,
        until?: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/stats/combat/maps',
            path: {
                'serverLogin': serverLogin,
            },
            query: {
                'limit': limit,
                'offset': offset,
                'since': since,
                'until': until,
            },
            errors: {
                404: `Server not found.`,
            },
        });
    }
    /**
     * Get combat stats for a specific map
     * Returns the latest combat statistics entry for the given map UID. Data is sourced from the most recent lifecycle map.end event whose map_rotation.current_map.uid matches the requested UID. Returns 404 if no map.end event has been stored for this UID. Per-player entries include kd_ratio, hits_rocket, hits_laser, rocket_accuracy, laser_accuracy (null for events predating plugin v2), and Elite mode fields: attack_rounds_played, attack_rounds_won, attack_win_rate, defense_rounds_played, defense_rounds_won, defense_win_rate (null for non-Elite or pre-plugin-update events).
     * @param serverLogin The dedicated server login (unique identifier)
     * @param mapUid The map UID (from map_rotation.current_map.uid)
     * @returns any Map combat stats returned. Fields: map_uid, map_name, played_at, duration_seconds, player_stats (Record<login, PlayerCountersDelta>), team_stats, totals, win_context, event_id.
     * @throws ApiError
     */
    public statsReadControllerGetCombatMapByUid(
        serverLogin: string,
        mapUid: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/stats/combat/maps/{mapUid}',
            path: {
                'serverLogin': serverLogin,
                'mapUid': mapUid,
            },
            errors: {
                404: `Server not found or no data for this map UID.`,
            },
        });
    }
    /**
     * Get player combat stats on a specific map
     * Returns the combat counters for a single player on a specific map UID. Data is extracted from the player_counters_delta of the most recent lifecycle map.end event for that map. Returns 404 if the map or player is not found. Counters include kd_ratio, hits_rocket, hits_laser, rocket_accuracy, laser_accuracy (null for events predating plugin v2), and Elite mode fields: attack_rounds_played, attack_rounds_won, attack_win_rate, defense_rounds_played, defense_rounds_won, defense_win_rate (null for non-Elite or pre-plugin-update events).
     * @param serverLogin The dedicated server login (unique identifier)
     * @param mapUid The map UID (from map_rotation.current_map.uid)
     * @param login The player login (unique ManiaPlanet account identifier)
     * @returns any Player map stats returned. Fields: server_login, map_uid, map_name, player_login, counters { kills, deaths, hits, shots, misses, rockets, lasers, accuracy, kd_ratio, hits_rocket, hits_laser, rocket_accuracy, laser_accuracy, attack_rounds_played, attack_rounds_won, attack_win_rate, defense_rounds_played, defense_rounds_won, defense_win_rate }, played_at.
     * @throws ApiError
     */
    public statsReadControllerGetCombatMapPlayer(
        serverLogin: string,
        mapUid: string,
        login: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/stats/combat/maps/{mapUid}/players/{login}',
            path: {
                'serverLogin': serverLogin,
                'mapUid': mapUid,
                'login': login,
            },
            errors: {
                404: `Server, map, or player not found.`,
            },
        });
    }
    /**
     * List per-series (Best-Of) combat stats
     * Returns combat statistics grouped by completed series/match. A series is defined by a match.begin / match.end lifecycle event pair. Each series entry includes the maps played within that series window (extracted from map.end events), per-map player/team stats, aggregated series_totals (sum of all map totals), and the series win context. Open series (match.begin without a matching match.end) are excluded. Per-player entries within each map include kd_ratio, hits_rocket, hits_laser, rocket_accuracy, laser_accuracy (null for events predating plugin v2), and Elite mode fields: attack_rounds_played, attack_rounds_won, attack_win_rate, defense_rounds_played, defense_rounds_won, defense_win_rate (null for non-Elite or pre-plugin-update events). Results are ordered most-recent first. Supports pagination and ISO8601 time-range filtering.
     * @param serverLogin The dedicated server login (unique identifier)
     * @param limit Max series to return (1–200, default 50)
     * @param offset Series to skip (default 0)
     * @param since Return series that started after this ISO8601 timestamp
     * @param until Return series that started before this ISO8601 timestamp
     * @returns any Series list returned. Fields: server_login, series (array of SeriesCombatEntry), pagination { total, limit, offset }. SeriesCombatEntry fields: match_started_at, match_ended_at, total_maps_played, maps (MapCombatStatsEntry[]), series_totals, series_win_context.
     * @throws ApiError
     */
    public statsReadControllerGetCombatSeries(
        serverLogin: string,
        limit: number = 50,
        offset?: number,
        since?: string,
        until?: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/stats/combat/series',
            path: {
                'serverLogin': serverLogin,
            },
            query: {
                'limit': limit,
                'offset': offset,
                'since': since,
                'until': until,
            },
            errors: {
                404: `Server not found.`,
            },
        });
    }
    /**
     * Get latest scores snapshot
     * Returns the latest scores snapshot from the most recent SM_SCORES callback event. Includes scores_section (EndRound/EndMap/EndMatch), scores_snapshot (teams, players, ranks, points), and scores_result (result_state, winning_side, winning_reason). If no scores event has been received, returns 200 with no_scores_available: true.
     * @param serverLogin The dedicated server login (unique identifier)
     * @returns any Scores snapshot returned. Fields: server_login, scores_section, scores_snapshot, scores_result, source_time (ISO8601), event_id. If no scores: no_scores_available: true with null fields.
     * @throws ApiError
     */
    public statsReadControllerGetLatestScores(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/stats/scores',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                404: `Server not found.`,
            },
        });
    }
}
