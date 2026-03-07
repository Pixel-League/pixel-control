/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { CancelablePromise } from '../core/CancelablePromise';
import type { BaseHttpRequest } from '../core/BaseHttpRequest';
export class PlayersService {
    constructor(public readonly httpRequest: BaseHttpRequest) {}
    /**
     * Get current player list
     * Returns the current de-duplicated player list for a server, reconstructed from the latest player events per unique login. Players with a disconnect event as their latest event are included with is_connected=false. Ordered by last activity (newest first). Supports pagination via limit/offset.
     * @param serverLogin The dedicated server login (unique identifier)
     * @param limit Max items to return (1–200, default 50)
     * @param offset Items to skip (default 0)
     * @returns any Player list returned. Fields: data (array of player state objects), pagination { total, limit, offset }. Each player: login, nickname, team_id, is_spectator, is_connected, has_joined_game, auth_level, auth_name, connectivity_state, readiness_state, eligibility_state, last_updated (ISO8601).
     * @throws ApiError
     */
    public playersReadControllerGetPlayers(
        serverLogin: string,
        limit: number = 50,
        offset?: number,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/players',
            path: {
                'serverLogin': serverLogin,
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
    /**
     * Get single player state
     * Returns the full state of a specific player on a server, derived from the most recent player event for that player login. Includes extended fields: permission_signals, roster_state, reconnect_continuity, side_change, constraint_signals, and the source event_id.
     * @param serverLogin The dedicated server login (unique identifier)
     * @param login The player login (unique ManiaPlanet account identifier)
     * @returns any Player state returned. Includes all base fields plus: permission_signals, roster_state, reconnect_continuity, side_change, constraint_signals, last_event_id, last_updated.
     * @throws ApiError
     */
    public playersReadControllerGetPlayer(
        serverLogin: string,
        login: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/players/{login}',
            path: {
                'serverLogin': serverLogin,
                'login': login,
            },
            errors: {
                404: `Server not found or player has no events on this server.`,
            },
        });
    }
}
