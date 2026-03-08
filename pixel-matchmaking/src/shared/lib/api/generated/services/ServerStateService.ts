/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { ServerStateSnapshotDto } from '../models/ServerStateSnapshotDto';
import type { CancelablePromise } from '../core/CancelablePromise';
import type { BaseHttpRequest } from '../core/BaseHttpRequest';
export class ServerStateService {
    constructor(public readonly httpRequest: BaseHttpRequest) {}
    /**
     * Get persisted plugin state snapshot
     * Returns the most recently saved plugin state snapshot for the server. Falls back to linked template config if no saved state exists. Returns { state: null, updated_at: null, source: "default" } if neither state nor template exist. The "source" field indicates whether the state came from a saved snapshot ("saved"), a linked config template ("template"), or is the default null state ("default").
     * @param serverLogin Server login (unique identifier)
     * @returns any State returned (may be null if no prior save and no template).
     * @throws ApiError
     */
    public serverStateControllerGetState(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/state',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                404: `Server not found.`,
            },
        });
    }
    /**
     * Save plugin state snapshot
     * Persists the plugin state snapshot for the server (upsert). Requires a valid link_bearer token in the Authorization header. The snapshot replaces any previously saved state.
     * @param serverLogin Server login (unique identifier)
     * @param authorization Bearer <link_token> — link bearer token for the server
     * @param requestBody
     * @returns any State saved successfully. Returns { saved: true, updated_at: <iso> }.
     * @throws ApiError
     */
    public serverStateControllerSaveState(
        serverLogin: string,
        authorization: string,
        requestBody: ServerStateSnapshotDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/state',
            path: {
                'serverLogin': serverLogin,
            },
            headers: {
                'Authorization': authorization,
            },
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                400: `Invalid or missing snapshot body.`,
                403: `Invalid or missing link bearer token.`,
                404: `Server not found.`,
            },
        });
    }
    /**
     * Apply linked config template as server state
     * Takes the linked template config, wraps it in a full state snapshot envelope, and saves it as the server's persisted state (upsert). Returns 400 if the server has no linked template.
     * @param serverLogin Server login (unique identifier)
     * @returns any Template applied as server state.
     * @throws ApiError
     */
    public serverStateControllerApplyTemplate(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/state/apply-template',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                400: `Server has no linked config template.`,
                404: `Server not found.`,
            },
        });
    }
}
