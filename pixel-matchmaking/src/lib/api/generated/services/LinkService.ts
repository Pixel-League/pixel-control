/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { LinkRegistrationDto } from '../models/LinkRegistrationDto';
import type { LinkTokenDto } from '../models/LinkTokenDto';
import type { CancelablePromise } from '../core/CancelablePromise';
import type { BaseHttpRequest } from '../core/BaseHttpRequest';
export class LinkService {
    constructor(public readonly httpRequest: BaseHttpRequest) {}
    /**
     * Register or update a server identity
     * Creates the server record in the API database on first call. Subsequent calls update server_name, game_mode, and title_id. A link token is generated automatically on first registration.
     * @param serverLogin Dedicated server login (unique identifier of the game server)
     * @param requestBody Optional server metadata to register or update
     * @returns any Server registered or updated successfully. Returns server_login, registered flag, and link_token (only on first registration).
     * @throws ApiError
     */
    public linkControllerRegisterServer(
        serverLogin: string,
        requestBody: LinkRegistrationDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'PUT',
            url: '/v1/servers/{serverLogin}/link/registration',
            path: {
                'serverLogin': serverLogin,
            },
            body: requestBody,
            mediaType: 'application/json',
        });
    }
    /**
     * Generate or rotate the link token
     * Returns the existing link token, or generates a new one if rotate=true or no token exists. The link token is the shared secret between API and plugin for link_bearer auth.
     * @param serverLogin Dedicated server login
     * @param requestBody Set rotate=true to force token rotation
     * @returns any Returns server_login, link_token, and rotated flag.
     * @throws ApiError
     */
    public linkControllerGenerateToken(
        serverLogin: string,
        requestBody: LinkTokenDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/link/token',
            path: {
                'serverLogin': serverLogin,
            },
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                404: `Server not found.`,
            },
        });
    }
    /**
     * Check if server is linked and auth is valid
     * Returns the current link status, last heartbeat timestamp, plugin version, and computed online status. A server is online if its last heartbeat was received within the configured threshold (default 360s).
     * @param serverLogin Dedicated server login
     * @returns any Returns server_login, linked, last_heartbeat, plugin_version, and online.
     * @throws ApiError
     */
    public linkControllerGetAuthState(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/link/auth-state',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                404: `Server not found.`,
            },
        });
    }
    /**
     * Check server access and permissions
     * Returns whether the server has access granted (currently equivalent to linked status), along with link and online state. Future tiers may add granular permission checks.
     * @param serverLogin Dedicated server login
     * @returns any Returns server_login, access_granted, linked, and online.
     * @throws ApiError
     */
    public linkControllerCheckAccess(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/link/access',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                404: `Server not found.`,
            },
        });
    }
    /**
     * Delete a registered server
     * Permanently removes a server and all its associated connectivity events from the database. This unlinks the server and clears all stored telemetry.
     * @param serverLogin Dedicated server login to delete
     * @returns any Server deleted successfully. Returns server_login and deleted flag.
     * @throws ApiError
     */
    public linkControllerDeleteServer(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'DELETE',
            url: '/v1/servers/{serverLogin}',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                404: `Server not found.`,
            },
        });
    }
    /**
     * List all registered servers
     * Returns an array of all registered servers with their link status, online state, and metadata. Online status is dynamically computed from heartbeat recency. Filter by status query parameter.
     * @param status Filter servers: all (default), linked (only linked), offline (only offline)
     * @returns any Array of server summaries with server_login, server_name, linked, online, last_heartbeat, plugin_version, game_mode, and title_id.
     * @throws ApiError
     */
    public linkControllerListServers(
        status?: 'all' | 'linked' | 'offline',
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers',
            query: {
                'status': status,
            },
        });
    }
}
