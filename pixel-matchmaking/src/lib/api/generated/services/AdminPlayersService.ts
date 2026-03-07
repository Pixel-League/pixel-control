/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { ForceTeamDto } from '../models/ForceTeamDto';
import type { CancelablePromise } from '../core/CancelablePromise';
import type { BaseHttpRequest } from '../core/BaseHttpRequest';
export class AdminPlayersService {
    constructor(public readonly httpRequest: BaseHttpRequest) {}
    /**
     * Force a player to a specific team
     * Forces the specified player into Team A or Team B. Accepted team values: "team_a", "team_b", "0", "1", "red", "blue", "a", "b".
     * @param serverLogin Server login (unique identifier)
     * @param login Player login to force
     * @param requestBody
     * @returns any Player forced to team successfully.
     * @throws ApiError
     */
    public adminPlayersControllerForceTeam(
        serverLogin: string,
        login: string,
        requestBody: ForceTeamDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/players/{login}/force-team',
            path: {
                'serverLogin': serverLogin,
                'login': login,
            },
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                400: `Invalid or missing team parameter.`,
                403: `Link auth rejected by plugin.`,
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Force a player out of spectator mode
     * Moves the specified player from spectator to player slot. Equivalent to forceSpectator($login, 0) in ManiaControl.
     * @param serverLogin Server login (unique identifier)
     * @param login Player login to force out of spectator
     * @returns any Player forced to play successfully.
     * @throws ApiError
     */
    public adminPlayersControllerForcePlay(
        serverLogin: string,
        login: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/players/{login}/force-play',
            path: {
                'serverLogin': serverLogin,
                'login': login,
            },
            errors: {
                403: `Link auth rejected by plugin.`,
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Force a player into spectator mode
     * Moves the specified player to the spectator slot. Equivalent to forceSpectator($login, 1) in ManiaControl.
     * @param serverLogin Server login (unique identifier)
     * @param login Player login to force into spectator
     * @returns any Player forced to spectator successfully.
     * @throws ApiError
     */
    public adminPlayersControllerForceSpec(
        serverLogin: string,
        login: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/players/{login}/force-spec',
            path: {
                'serverLogin': serverLogin,
                'login': login,
            },
            errors: {
                403: `Link auth rejected by plugin.`,
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
}
