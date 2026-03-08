/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { CancelablePromise } from '../core/CancelablePromise';
import type { BaseHttpRequest } from '../core/BaseHttpRequest';
export class MapsService {
    constructor(public readonly httpRequest: BaseHttpRequest) {}
    /**
     * Get server map pool
     * Returns the map pool for a server, extracted from the latest lifecycle event containing map_rotation data (map.begin or map.end events). Includes each map's uid, name, and file path. Also returns the current active map and its index in the pool. If no map rotation data exists, returns an empty list.
     * @param serverLogin The dedicated server login (unique identifier)
     * @returns any Map pool returned. Fields: server_login, maps (array of { uid, name, file }), map_count, current_map, current_map_index, last_updated (ISO8601 or null).
     * @throws ApiError
     */
    public mapsReadControllerGetMaps(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/maps',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                404: `Server not found.`,
            },
        });
    }
}
