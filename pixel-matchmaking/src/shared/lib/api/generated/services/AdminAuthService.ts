/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { AuthGrantDto } from '../models/AuthGrantDto';
import type { CancelablePromise } from '../core/CancelablePromise';
import type { BaseHttpRequest } from '../core/BaseHttpRequest';
export class AdminAuthService {
    constructor(public readonly httpRequest: BaseHttpRequest) {}
    /**
     * Grant auth level to a player
     * Grants the specified auth level ("player", "moderator", "admin", "superadmin") to the given player login via ManiaControl's AuthenticationManager.
     * @param serverLogin Server login (unique identifier)
     * @param login Player login to grant auth to
     * @param requestBody
     * @returns any Auth level granted successfully.
     * @throws ApiError
     */
    public adminAuthControllerGrantAuth(
        serverLogin: string,
        login: string,
        requestBody: AuthGrantDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/players/{login}/auth',
            path: {
                'serverLogin': serverLogin,
                'login': login,
            },
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                400: `Invalid or missing auth_level.`,
                403: `Link auth rejected by plugin.`,
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Revoke auth level from a player
     * Revokes any elevated auth level from the specified player login, resetting them to the base "player" level.
     * @param serverLogin Server login (unique identifier)
     * @param login Player login to revoke auth from
     * @returns any Auth revoked successfully.
     * @throws ApiError
     */
    public adminAuthControllerRevokeAuth(
        serverLogin: string,
        login: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'DELETE',
            url: '/v1/servers/{serverLogin}/players/{login}/auth',
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
