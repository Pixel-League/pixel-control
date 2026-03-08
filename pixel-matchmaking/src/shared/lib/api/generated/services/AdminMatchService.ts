/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { MatchBestOfDto } from '../models/MatchBestOfDto';
import type { MatchMapsScoreDto } from '../models/MatchMapsScoreDto';
import type { MatchRoundScoreDto } from '../models/MatchRoundScoreDto';
import type { CancelablePromise } from '../core/CancelablePromise';
import type { BaseHttpRequest } from '../core/BaseHttpRequest';
export class AdminMatchService {
    constructor(public readonly httpRequest: BaseHttpRequest) {}
    /**
     * Set the best-of target
     * Sets the best-of match target (e.g. 3 for best-of-3). Must be a positive odd integer.
     * @param serverLogin Server login (unique identifier)
     * @param requestBody
     * @returns any Best-of target updated successfully.
     * @throws ApiError
     */
    public adminMatchControllerSetBestOf(
        serverLogin: string,
        requestBody: MatchBestOfDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'PUT',
            url: '/v1/servers/{serverLogin}/match/best-of',
            path: {
                'serverLogin': serverLogin,
            },
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                400: `Invalid best_of value.`,
                403: `Link auth rejected by plugin.`,
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Get the current best-of target
     * Retrieves the current best-of configuration from the live ManiaControl socket. Returns the details object containing the best_of value. Returns 503 if the socket is unavailable.
     * @param serverLogin Server login (unique identifier)
     * @returns any Current best-of returned in details.best_of.
     * @throws ApiError
     */
    public adminMatchControllerGetBestOf(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/match/best-of',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                404: `Server not found.`,
                502: `ManiaControl socket error.`,
                503: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Set the maps score for a team
     * Sets the maps score (wins) for the specified team.
     * @param serverLogin Server login (unique identifier)
     * @param requestBody
     * @returns any Maps score updated successfully.
     * @throws ApiError
     */
    public adminMatchControllerSetMapsScore(
        serverLogin: string,
        requestBody: MatchMapsScoreDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'PUT',
            url: '/v1/servers/{serverLogin}/match/maps-score',
            path: {
                'serverLogin': serverLogin,
            },
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                400: `Invalid target_team or maps_score.`,
                403: `Link auth rejected by plugin.`,
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Get the current maps score
     * Retrieves the current maps score state from the live ManiaControl socket. Returns details containing team maps scores. Returns 503 if the socket is unavailable.
     * @param serverLogin Server login (unique identifier)
     * @returns any Current maps score returned in details.
     * @throws ApiError
     */
    public adminMatchControllerGetMapsScore(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/match/maps-score',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                404: `Server not found.`,
                502: `ManiaControl socket error.`,
                503: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Set the round score for a team
     * Sets the current round score for the specified team.
     * @param serverLogin Server login (unique identifier)
     * @param requestBody
     * @returns any Round score updated successfully.
     * @throws ApiError
     */
    public adminMatchControllerSetRoundScore(
        serverLogin: string,
        requestBody: MatchRoundScoreDto,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'PUT',
            url: '/v1/servers/{serverLogin}/match/round-score',
            path: {
                'serverLogin': serverLogin,
            },
            body: requestBody,
            mediaType: 'application/json',
            errors: {
                400: `Invalid target_team or score.`,
                403: `Link auth rejected by plugin.`,
                404: `Server not found.`,
                502: `ManiaControl socket unavailable.`,
            },
        });
    }
    /**
     * Get the current round score
     * Retrieves the current round score state from the live ManiaControl socket. Returns details containing team round scores. Returns 503 if the socket is unavailable.
     * @param serverLogin Server login (unique identifier)
     * @returns any Current round score returned in details.
     * @throws ApiError
     */
    public adminMatchControllerGetRoundScore(
        serverLogin: string,
    ): CancelablePromise<any> {
        return this.httpRequest.request({
            method: 'GET',
            url: '/v1/servers/{serverLogin}/match/round-score',
            path: {
                'serverLogin': serverLogin,
            },
            errors: {
                404: `Server not found.`,
                502: `ManiaControl socket error.`,
                503: `ManiaControl socket unavailable.`,
            },
        });
    }
}
