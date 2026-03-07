/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { CancelablePromise } from '../core/CancelablePromise';
import type { BaseHttpRequest } from '../core/BaseHttpRequest';
export class ModeService {
    constructor(public readonly httpRequest: BaseHttpRequest) {}
    /**
     * Get current game mode and recent mode events
     * Returns the current game mode from the Server record (set by connectivity events), plus a list of the most recent mode events for this server. Mode events correspond to game-mode-specific callbacks (e.g. SM_ELITE_STARTTURN, SM_JOUST_NEWTURN, etc.). Use the limit parameter to control how many recent events are returned (1–50, default 10).
     * @param serverLogin The dedicated server login (unique identifier)
     * @param limit Number of recent mode events to return (1–50, default 10)
     * @returns any Mode data returned. Fields: server_login, game_mode, title_id, recent_mode_events (array of { event_name, event_id, source_callback, source_time, raw_callback_summary }), total_mode_events, last_updated (ISO8601 or null).
     * @throws ApiError
     */
    public modeReadControllerGetModeData(
        serverLogin: string,
        limit?: any,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/mode',
            path: {
                'serverLogin': serverLogin,
            },
            query: {
                'limit': limit,
            },
            errors: {
                404: `Server not found.`,
            },
        });
    }
}
