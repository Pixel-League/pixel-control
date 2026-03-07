/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { MapAddDto } from '../models/MapAddDto';
import type { MapJumpDto } from '../models/MapJumpDto';
import type { MapQueueDto } from '../models/MapQueueDto';
import type { CancelablePromise } from '../core/CancelablePromise';
import type { BaseHttpRequest } from '../core/BaseHttpRequest';
export class AdminMapsService {
    constructor(public readonly httpRequest: BaseHttpRequest) {}
    /**
     * Add a map from ManiaExchange
     * Downloads the map with the given ManiaExchange ID and adds it to the server map pool.
     * @param serverLogin Server login (unique identifier)
     * @param requestBody
     * @returns any Map added successfully.
     * @throws ApiError
     */
    public adminMapsControllerAddMap(
        serverLogin: string,
        requestBody: MapAddDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/maps',
            path: {
                'serverLogin': serverLogin,
            },
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                400: `Invalid or missing mx_id.`,
                403: `Link auth rejected by plugin.`,
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Skip to the next map
     * Instructs the server to immediately skip to the next map in the rotation.
     * @param serverLogin Server login (unique identifier)
     * @returns any Map skipped successfully.
     * @throws ApiError
     */
    public adminMapsControllerSkipMap(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/maps/skip',
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
     * Restart the current map
     * Instructs the server to restart the currently running map.
     * @param serverLogin Server login (unique identifier)
     * @returns any Map restarted successfully.
     * @throws ApiError
     */
    public adminMapsControllerRestartMap(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/maps/restart',
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
     * Jump to a specific map by UID
     * Instructs the server to immediately jump to the map with the given UID.
     * @param serverLogin Server login (unique identifier)
     * @param requestBody
     * @returns any Map jumped successfully.
     * @throws ApiError
     */
    public adminMapsControllerJumpToMap(
        serverLogin: string,
        requestBody: MapJumpDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/maps/jump',
            path: {
                'serverLogin': serverLogin,
            },
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                400: `Invalid or missing map_uid.`,
                403: `Link auth rejected by plugin.`,
                404: `Server not found or map UID not in pool.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Queue a map as the next map
     * Instructs the server to queue the specified map to play next after the current one.
     * @param serverLogin Server login (unique identifier)
     * @param requestBody
     * @returns any Map queued successfully.
     * @throws ApiError
     */
    public adminMapsControllerQueueMap(
        serverLogin: string,
        requestBody: MapQueueDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/maps/queue',
            path: {
                'serverLogin': serverLogin,
            },
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                400: `Invalid or missing map_uid.`,
                403: `Link auth rejected by plugin.`,
                404: `Server not found or map UID not in pool.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Remove a map from the server map pool
     * Removes the map with the given UID from the server's map pool.
     * @param serverLogin Server login (unique identifier)
     * @param mapUid UID of the map to remove
     * @returns any Map removed successfully.
     * @throws ApiError
     */
    public adminMapsControllerRemoveMap(
        serverLogin: string,
        mapUid: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'DELETE',
            url: '/v1/servers/{serverLogin}/maps/{mapUid}',
            path: {
                'serverLogin': serverLogin,
                'mapUid': mapUid,
            },
            errors: {
                400: `Invalid map UID.`,
                403: `Link auth rejected by plugin.`,
                404: `Server not found or map UID not in pool.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
}
