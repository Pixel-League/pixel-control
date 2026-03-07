/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { WhitelistAddDto } from '../models/WhitelistAddDto';
import type { CancelablePromise } from '../core/CancelablePromise';
import type { BaseHttpRequest } from '../core/BaseHttpRequest';
export class AdminWhitelistService {
    constructor(public readonly httpRequest: BaseHttpRequest) {}
    /**
     * Enable server whitelist
     * Enables the server whitelist, restricting connections to whitelisted players only.
     * @param serverLogin Server login (unique identifier)
     * @returns any Whitelist enabled successfully.
     * @throws ApiError
     */
    public adminWhitelistControllerEnableWhitelist(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/whitelist/enable',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                403: `Link auth rejected by plugin.`,
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Disable server whitelist
     * Disables the server whitelist, allowing all players to connect.
     * @param serverLogin Server login (unique identifier)
     * @returns any Whitelist disabled successfully.
     * @throws ApiError
     */
    public adminWhitelistControllerDisableWhitelist(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/whitelist/disable',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                403: `Link auth rejected by plugin.`,
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Add a player to the whitelist
     * Adds the specified player login to the server whitelist.
     * @param serverLogin Server login (unique identifier)
     * @param requestBody
     * @returns any Player added to whitelist successfully.
     * @throws ApiError
     */
    public adminWhitelistControllerAddToWhitelist(
        serverLogin: string,
        requestBody: WhitelistAddDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/whitelist',
            path: {
                'serverLogin': serverLogin,
            },
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                400: `Invalid or missing target_login.`,
                403: `Link auth rejected by plugin.`,
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * List all whitelisted players
     * Returns the current server whitelist of allowed player logins.
     * @param serverLogin Server login (unique identifier)
     * @returns any Whitelist returned.
     * @throws ApiError
     */
    public adminWhitelistControllerListWhitelist(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/whitelist',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                403: `Link auth rejected by plugin.`,
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Clear the entire whitelist
     * Removes all players from the server whitelist in one operation.
     * @param serverLogin Server login (unique identifier)
     * @returns any Whitelist cleared successfully.
     * @throws ApiError
     */
    public adminWhitelistControllerCleanWhitelist(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'DELETE',
            url: '/v1/servers/{serverLogin}/whitelist',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                403: `Link auth rejected by plugin.`,
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Remove a player from the whitelist
     * Removes the specified player login from the server whitelist.
     * @param serverLogin Server login (unique identifier)
     * @param login Player login to remove from the whitelist
     * @returns any Player removed from whitelist successfully.
     * @throws ApiError
     */
    public adminWhitelistControllerRemoveFromWhitelist(
        serverLogin: string,
        login: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'DELETE',
            url: '/v1/servers/{serverLogin}/whitelist/{login}',
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
     * Sync whitelist from file
     * Triggers ManiaControl to reload the whitelist from its persistent storage file.
     * @param serverLogin Server login (unique identifier)
     * @returns any Whitelist synced successfully.
     * @throws ApiError
     */
    public adminWhitelistControllerSyncWhitelist(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/whitelist/sync',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                403: `Link auth rejected by plugin.`,
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
}
