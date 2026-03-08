/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { CancelablePromise } from '../core/CancelablePromise';
import type { BaseHttpRequest } from '../core/BaseHttpRequest';
export class StatsEliteService {
    constructor(public readonly httpRequest: BaseHttpRequest) {}
    /**
     * List Elite turn summaries
     * Returns paginated Elite turn summaries for the server. Each entry contains the full turn context: attacker/defender logins, outcome, duration, per-player combat stats (kills, deaths, hits, shots, misses, rocket_hits), map context, and clutch detection result. Turns are ordered most-recent first. Supports optional time-range filtering via since/until (ISO8601).
     * @param serverLogin The dedicated server login (unique identifier)
     * @param limit Max turns to return (1–200, default 50)
     * @param offset Turns to skip (default 0)
     * @param since Filter turns recorded after this ISO8601 timestamp
     * @param until Filter turns recorded before this ISO8601 timestamp
     * @returns any Paginated list of elite turn summaries. Fields per turn: event_kind, turn_number, attacker_login, defender_logins, attacker_team_id, outcome, duration_seconds, defense_success, per_player_stats, map_uid, map_name, clutch { is_clutch, clutch_player_login, alive_defenders_at_end, total_defenders }.
     * @throws ApiError
     */
    public eliteStatsReadControllerGetEliteTurns(
        serverLogin: string,
        limit: number = 50,
        offset?: number,
        since?: string,
        until?: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/stats/combat/turns',
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
     * Get a single Elite turn by turn number
     * Returns the full turn summary for the given turn number on this server. Turn numbers are monotonically incremented per-server-session by the plugin. If the server restarts the counter resets; the most recent event with the requested turn number is returned. Returns 404 if the turn number has no recorded event.
     * @param serverLogin The dedicated server login (unique identifier)
     * @param turnNumber The Elite turn number (integer, 1-based)
     * @returns any Full turn summary with additional fields: server_login, event_id, recorded_at (ISO8601).
     * @throws ApiError
     */
    public eliteStatsReadControllerGetEliteTurnByNumber(
        serverLogin: string,
        turnNumber: number,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/stats/combat/turns/{turnNumber}',
            path: {
                'serverLogin': serverLogin,
                'turnNumber': turnNumber,
            },
            errors: {
                404: `Server not found or turn number not recorded.`,
            },
        });
    }
    /**
     * Get clutch statistics for a player
     * Returns clutch statistics for the given player across all recorded Elite turn summaries on this server. A clutch is defined as: a single remaining defender wins the round (defense_success=true, aliveCount=1, totalDefenders>1). Returns clutch_count, total_defense_rounds the player participated in, clutch_rate (clutch_count / total_defense_rounds), and the list of clutch turns.
     * @param serverLogin The dedicated server login (unique identifier)
     * @param login The player login to query clutch stats for
     * @returns any Clutch stats: server_login, player_login, clutch_count, total_defense_rounds, clutch_rate, clutch_turns [ { turn_number, map_uid, map_name, recorded_at, defender_logins, alive_defenders_at_end, total_defenders, outcome } ].
     * @throws ApiError
     */
    public eliteStatsReadControllerGetPlayerClutchStats(
        serverLogin: string,
        login: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/stats/combat/players/{login}/clutches',
            path: {
                'serverLogin': serverLogin,
                'login': login,
            },
            errors: {
                404: `Server not found.`,
            },
        });
    }
    /**
     * Get per-turn Elite history for a player
     * Returns a paginated list of Elite turn summaries in which the given player participated (either as attacker or defender). Each entry includes the player's per-turn stats (kills, deaths, hits, shots, misses, rocket_hits), their role (attacker/defender), the round outcome, and clutch info. Ordered most-recent first.
     * @param serverLogin The dedicated server login (unique identifier)
     * @param login The player login to query turn history for
     * @param limit Max turns to return (1–200, default 50)
     * @param offset Turns to skip (default 0)
     * @returns any Paginated list of per-player turn entries. Fields: turn_number, map_uid, map_name, recorded_at, role (attacker|defender), stats { kills, deaths, hits, shots, misses, rocket_hits }, outcome, defense_success, clutch { is_clutch, clutch_player_login, alive_defenders_at_end, total_defenders }.
     * @throws ApiError
     */
    public eliteStatsReadControllerGetElitePlayerTurnHistory(
        serverLogin: string,
        login: string,
        limit: number = 50,
        offset?: number,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/stats/combat/players/{login}/turns',
            path: {
                'serverLogin': serverLogin,
                'login': login,
            },
            query: {
                'limit': limit,
                'offset': offset,
            },
            errors: {
                404: `Server not found.`,
            },
        });
    }
}
