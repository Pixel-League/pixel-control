/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { WarmupExtendDto } from '../models/WarmupExtendDto';
import type { CancelablePromise } from '../core/CancelablePromise';
import type { BaseHttpRequest } from '../core/BaseHttpRequest';
export class AdminWarmupPauseService {
    constructor(public readonly httpRequest: BaseHttpRequest) {}
    /**
     * Extend the warmup duration
     * Extends the current warmup phase by the specified number of seconds.
     * @param serverLogin Server login (unique identifier)
     * @param requestBody
     * @returns any Warmup extended successfully.
     * @throws ApiError
     */
    public adminWarmupPauseControllerExtendWarmup(
        serverLogin: string,
        requestBody: WarmupExtendDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/warmup/extend',
            path: {
                'serverLogin': serverLogin,
            },
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                400: `Invalid seconds value.`,
                403: `Link auth rejected by plugin.`,
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * End the warmup phase immediately
     * Instructs the server to end the warmup phase immediately and start the match.
     * @param serverLogin Server login (unique identifier)
     * @returns any Warmup ended successfully.
     * @throws ApiError
     */
    public adminWarmupPauseControllerEndWarmup(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/warmup/end',
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
     * Pause the match
     * Instructs the server to pause the current match.
     * @param serverLogin Server login (unique identifier)
     * @returns any Match paused successfully.
     * @throws ApiError
     */
    public adminWarmupPauseControllerStartPause(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/pause/start',
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
     * Resume the match from pause
     * Instructs the server to end the pause and resume the match.
     * @param serverLogin Server login (unique identifier)
     * @returns any Match resumed successfully.
     * @throws ApiError
     */
    public adminWarmupPauseControllerEndPause(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/pause/end',
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
