/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { TeamPolicyDto } from '../models/TeamPolicyDto';
import type { TeamRosterAssignDto } from '../models/TeamRosterAssignDto';
import type { CancelablePromise } from '../core/CancelablePromise';
import type { BaseHttpRequest } from '../core/BaseHttpRequest';
export class AdminTeamsService {
    constructor(public readonly httpRequest: BaseHttpRequest) {}
    /**
     * Set team enforcement policy
     * Enables or disables team enforcement. When enabled, players are locked to their assigned roster teams. Optional switch_lock prevents players from manually switching teams.
     * @param serverLogin Server login (unique identifier)
     * @param requestBody
     * @returns any Team policy updated successfully.
     * @throws ApiError
     */
    public adminTeamsControllerSetPolicy(
        serverLogin: string,
        requestBody: TeamPolicyDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'PUT',
            url: '/v1/servers/{serverLogin}/teams/policy',
            path: {
                'serverLogin': serverLogin,
            },
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                400: `Invalid or missing enabled parameter.`,
                403: `Link auth rejected by plugin.`,
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Get current team enforcement policy
     * Returns the current team enforcement policy state: enabled flag and switch_lock setting.
     * @param serverLogin Server login (unique identifier)
     * @returns any Team policy retrieved successfully.
     * @throws ApiError
     */
    public adminTeamsControllerGetPolicy(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/teams/policy',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Assign a player to a team in the roster
     * Adds or updates a player's team assignment in the server roster. Accepted team values: "team_a", "team_b", "a", "b", "0", "1", "red", "blue".
     * @param serverLogin Server login (unique identifier)
     * @param requestBody
     * @returns any Player assigned to team roster successfully.
     * @throws ApiError
     */
    public adminTeamsControllerAssignRoster(
        serverLogin: string,
        requestBody: TeamRosterAssignDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/teams/roster',
            path: {
                'serverLogin': serverLogin,
            },
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                400: `Invalid or missing target_login or team.`,
                403: `Link auth rejected by plugin.`,
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Get the current team roster
     * Returns the full team roster: a map of player logins to their assigned teams.
     * @param serverLogin Server login (unique identifier)
     * @returns any Team roster retrieved successfully.
     * @throws ApiError
     */
    public adminTeamsControllerListRoster(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/teams/roster',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Remove a player from the team roster
     * Removes the specified player from the server team roster. Returns an error if the player is not in the roster.
     * @param serverLogin Server login (unique identifier)
     * @param login Player login to remove from roster
     * @returns any Player removed from roster successfully.
     * @throws ApiError
     */
    public adminTeamsControllerUnassignRoster(
        serverLogin: string,
        login: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'DELETE',
            url: '/v1/servers/{serverLogin}/teams/roster/{login}',
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
