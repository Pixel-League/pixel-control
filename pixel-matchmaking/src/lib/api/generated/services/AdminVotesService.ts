/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { VoteCustomStartDto } from '../models/VoteCustomStartDto';
import type { VotePolicySetDto } from '../models/VotePolicySetDto';
import type { VoteSetRatioDto } from '../models/VoteSetRatioDto';
import type { CancelablePromise } from '../core/CancelablePromise';
import type { BaseHttpRequest } from '../core/BaseHttpRequest';
export class AdminVotesService {
    constructor(public readonly httpRequest: BaseHttpRequest) {}
    /**
     * Cancel the current vote
     * Cancels any currently running vote on the server.
     * @param serverLogin Server login (unique identifier)
     * @returns any Vote cancelled successfully.
     * @throws ApiError
     */
    public adminVotesControllerCancelVote(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/votes/cancel',
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
     * Set required vote ratio for a command
     * Sets the minimum ratio of votes (0.0–1.0) required to pass the specified vote command.
     * @param serverLogin Server login (unique identifier)
     * @param requestBody
     * @returns any Vote ratio updated successfully.
     * @throws ApiError
     */
    public adminVotesControllerSetVoteRatio(
        serverLogin: string,
        requestBody: VoteSetRatioDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'PUT',
            url: '/v1/servers/{serverLogin}/votes/ratio',
            path: {
                'serverLogin': serverLogin,
            },
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                400: `Invalid or missing command/ratio.`,
                403: `Link auth rejected by plugin.`,
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Start a custom vote
     * Starts the custom vote at the specified index defined in ManiaControl's vote configuration.
     * @param serverLogin Server login (unique identifier)
     * @param requestBody
     * @returns any Custom vote started successfully.
     * @throws ApiError
     */
    public adminVotesControllerStartCustomVote(
        serverLogin: string,
        requestBody: VoteCustomStartDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'POST',
            url: '/v1/servers/{serverLogin}/votes/custom',
            path: {
                'serverLogin': serverLogin,
            },
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                400: `Invalid or missing vote_index.`,
                403: `Link auth rejected by plugin.`,
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Get current vote policy
     * Returns the current vote policy mode configured on the server.
     * @param serverLogin Server login (unique identifier)
     * @returns any Vote policy returned.
     * @throws ApiError
     */
    public adminVotesControllerGetVotePolicy(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/votes/policy',
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
     * Set vote policy
     * Sets the vote policy mode (e.g. "strict", "lenient", "off") for the server.
     * @param serverLogin Server login (unique identifier)
     * @param requestBody
     * @returns any Vote policy updated successfully.
     * @throws ApiError
     */
    public adminVotesControllerSetVotePolicy(
        serverLogin: string,
        requestBody: VotePolicySetDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'PUT',
            url: '/v1/servers/{serverLogin}/votes/policy',
            path: {
                'serverLogin': serverLogin,
            },
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                400: `Invalid or missing mode.`,
                403: `Link auth rejected by plugin.`,
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
}
